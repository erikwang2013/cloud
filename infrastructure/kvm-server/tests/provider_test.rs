// Provider 测试：mock DB + 内存锁 + 模拟驱动，覆盖 create 全链路与各动作的错误路径。
// 快乐路径已由 tests/integration.rs（真实 MySQL）覆盖，这里补错误/边界路径。
mod common;

use common::{MemoryLock, MockDb, route_create_flow, route_release_flow, row};
use ecat_lock::DistributedLock;
use kvm_server::driver::{KvmDriver, SimulatedKvmDriver};
use kvm_server::model::TaskParams;
use kvm_server::provider::KvmProvider;
use serde_json::{Value, json};
use std::sync::Arc;

fn setup() -> (Arc<MockDb>, Arc<MemoryLock>, Arc<SimulatedKvmDriver>) {
    (MockDb::new(), MemoryLock::new(), Arc::new(SimulatedKvmDriver::new()))
}

fn provider(db: &Arc<MockDb>, lock: &Arc<MemoryLock>, driver: Arc<SimulatedKvmDriver>) -> KvmProvider {
    KvmProvider::new(db.clone(), lock.clone(), driver)
}

fn create_task(resource_id: i64, region_id: i64) -> TaskParams {
    TaskParams {
        resource_id: Some(resource_id),
        region_id: Some(region_id),
        params: Some(json!({"specs": {"cpu": 2, "ram": 4, "system_disk": 50}})),
    }
}

// ── create ──

#[tokio::test]
async fn create_success_releases_lock() {
    let (db, lock, driver) = setup();
    route_create_flow(&db, 42, 1);
    let p = provider(&db, &lock, driver);

    let out = p.create(&create_task(42, 1)).await.unwrap();
    assert_eq!(out["vm_id"], "kvm-42");
    assert_eq!(out["ip_address"], "10.0.0.1");
    assert_eq!(out["bridge"], "br-vm42");
    assert!(
        !lock.is_held("lock:provision:region:1:kvm").await,
        "lock must be released after create"
    );
}

#[tokio::test]
async fn create_missing_region_or_resource_errors_before_lock() {
    let (db, lock, driver) = setup();
    let p = provider(&db, &lock, driver);

    let err = p
        .create(&TaskParams { resource_id: Some(1), region_id: None, params: None })
        .await
        .unwrap_err();
    assert!(err.to_string().contains("task.region_id required"));

    let err = p
        .create(&TaskParams { resource_id: None, region_id: Some(1), params: None })
        .await
        .unwrap_err();
    assert!(err.to_string().contains("task.resource_id required"));
    assert!(!lock.is_held("lock:provision:region:1:kvm").await);
}

#[tokio::test]
async fn create_region_lock_held_is_retryable() {
    let (db, lock, driver) = setup();
    // 预占区域锁，模拟并发同区域供应
    lock.acquire("lock:provision:region:1:kvm", std::time::Duration::from_secs(60)).await.unwrap();
    let p = provider(&db, &lock, driver);

    let err = p.create(&create_task(42, 1)).await.unwrap_err();
    assert!(err.to_string().contains("Provisioning in progress"));
}

#[tokio::test]
async fn create_resource_not_found() {
    let (db, lock, driver) = setup();
    db.rows("SELECT id FROM resources WHERE id = ?", vec![]);
    let p = provider(&db, &lock, driver);

    let err = p.create(&create_task(99, 1)).await.unwrap_err();
    assert!(err.to_string().contains("resource 99 not found"));
    assert!(!lock.is_held("lock:provision:region:1:kvm").await, "lock released on error path");
}

#[tokio::test]
async fn create_driver_failure_rolls_back_and_releases_lock() {
    let (db, lock, driver) = setup();
    route_create_flow(&db, 42, 1);
    route_release_flow(&db);
    driver.fail_on("start_vm");
    let p = provider(&db, &lock, driver.clone());

    let err = p.create(&create_task(42, 1)).await.unwrap_err();
    assert!(err.to_string().contains("simulated failure at start_vm"));
    assert!(db.contains("DELETE FROM network_services"), "cleanup must run");
    assert!(!lock.is_held("lock:provision:region:1:kvm").await);
}

// ── renew ──

#[tokio::test]
async fn renew_updates_expiry() {
    let (db, _lock, driver) = setup();
    db.affected("UPDATE resources SET expired_at", 1);
    let p = provider(&db, &MemoryLock::new(), driver);
    p.renew(42, 3).await.unwrap();
    let params = db.params.lock().unwrap();
    assert_eq!(params[0], vec![json!(3), json!(42)]);
}

#[tokio::test]
async fn renew_missing_resource_errors() {
    let (db, _lock, driver) = setup();
    db.affected("UPDATE resources SET expired_at", 0);
    let p = provider(&db, &MemoryLock::new(), driver);
    let err = p.renew(42, 3).await.unwrap_err();
    assert!(err.to_string().contains("resource 42 not found"));
}

// ── upgrade ──

#[tokio::test]
async fn upgrade_merges_new_specs_into_existing() {
    let (db, _lock, driver) = setup();
    db.rows(
        "SELECT id, CAST(specs AS CHAR(1024)) AS specs FROM resources WHERE id = ?",
        vec![row(&[("id", json!(1)), ("specs", json!(r#"{"cpu":1,"ram":1,"system_disk":10}"#))])],
    );
    db.affected("UPDATE resources SET specs = ?", 1);
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(1)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-1")),
            ("size_gb", json!(10)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.rows(
        "ip_address, storage_pool FROM host_machines WHERE id = ?",
        vec![row(&[
            ("id", json!(1)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.0.0.10")),
            ("storage_pool", json!("p")),
        ])],
    );
    db.rows("LEFT JOIN resources", vec![]);
    db.rows("AS specs FROM host_machines", vec![row(&[("specs", json!("{}"))])]);
    db.affected("UPDATE host_machines SET specs", 1);
    let p = provider(&db, &MemoryLock::new(), driver);

    p.upgrade(1, &json!({"cpu": 8, "ram": 16})).await.unwrap();
    // 找 UPDATE resources 的参数：specs 合并后 {"cpu":8,"ram":16,"system_disk":10}
    let params = db.params.lock().unwrap();
    let merged = params
        .iter()
        .find(|ps| ps.len() == 2 && ps[0].is_string())
        .unwrap();
    let specs: Value = serde_json::from_str(merged[0].as_str().unwrap()).unwrap();
    assert_eq!(specs["cpu"], 8);
    assert_eq!(specs["ram"], 16);
    assert_eq!(specs["system_disk"], 10);
}

#[tokio::test]
async fn upgrade_missing_resource_errors() {
    let (db, _lock, driver) = setup();
    db.rows("SELECT id, CAST(specs AS CHAR(1024)) AS specs FROM resources WHERE id = ?", vec![]);
    let p = provider(&db, &MemoryLock::new(), driver);
    let err = p.upgrade(1, &json!({"cpu": 8})).await.unwrap_err();
    assert!(err.to_string().contains("resource not found"));
}

#[tokio::test]
async fn upgrade_invalid_existing_specs_errors() {
    let (db, _lock, driver) = setup();
    db.rows(
        "SELECT id, CAST(specs AS CHAR(1024)) AS specs FROM resources WHERE id = ?",
        vec![row(&[("id", json!(1)), ("specs", json!("not-json"))])],
    );
    let p = provider(&db, &MemoryLock::new(), driver);
    let err = p.upgrade(1, &json!({"cpu": 8})).await.unwrap_err();
    assert!(err.to_string().contains("invalid specs json"));
}

// ── destroy ──

#[tokio::test]
async fn destroy_releases_and_recalculates() {
    let (db, _lock, driver) = setup();
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-42")),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.rows(
        "ip_address, storage_pool FROM host_machines WHERE id = ?",
        vec![row(&[
            ("id", json!(1)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.0.0.10")),
            ("storage_pool", json!("p")),
        ])],
    );
    db.rows("LEFT JOIN resources", vec![
        row(&[("resource_id", json!(42)), ("size_gb", json!(50)), ("specs", json!(r#"{"cpu":2,"ram":4}"#))]),
    ]);
    db.rows("AS specs FROM host_machines", vec![row(&[("specs", json!("{}"))])]);
    db.affected("UPDATE host_machines SET specs", 1);
    route_release_flow(&db);
    let p = provider(&db, &MemoryLock::new(), driver.clone());

    p.destroy(42).await.unwrap();
    assert!(driver.state().calls.contains(&"destroyVm(kvm-42)".into()));
    assert!(db.contains("DELETE FROM switch_services"));
    // 重算参数：cpu=2, ram=4, disk=50
    let params = db.params.lock().unwrap();
    let update = params
        .iter()
        .find(|ps| ps.len() == 2 && ps[1] == json!(1))
        .unwrap();
    let specs: Value = serde_json::from_str(update[0].as_str().unwrap()).unwrap();
    assert_eq!(specs["cpu_allocated"], 2);
    assert_eq!(specs["ram_allocated_gb"], 4);
    assert_eq!(specs["disk_allocated_gb"], 50);
}

// ── status ──

#[tokio::test]
async fn status_no_disk_returns_error_metrics() {
    let (db, _lock, driver) = setup();
    db.rows("FROM disks WHERE resource_id", vec![]);
    let p = provider(&db, &MemoryLock::new(), driver);
    let out = p.status(42).await;
    assert_eq!(out["status"], "error");
}

#[tokio::test]
async fn status_pending_when_no_vm() {
    let (db, _lock, driver) = setup();
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", serde_json::Value::Null),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("creating")),
        ])],
    );
    let p = provider(&db, &MemoryLock::new(), driver);
    assert_eq!(p.status(42).await["status"], "pending");
}

#[tokio::test]
async fn status_running_from_driver() {
    let (db, _lock, driver) = setup();
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-42")),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.rows(
        "ip_address, storage_pool FROM host_machines WHERE id = ?",
        vec![row(&[
            ("id", json!(1)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.0.0.10")),
            ("storage_pool", json!("p")),
        ])],
    );
    driver.create_vm(kvm_server::driver::VmSpec {
        vm_id: "kvm-42".into(),
        cpu: 2,
        ram: 2048,
        mac: "02:00:00:00:00:2a".into(),
        bridge: "br-vm42".into(),
    }).unwrap();
    driver.start_vm("kvm-42").unwrap();
    let p = provider(&db, &MemoryLock::new(), driver);
    assert_eq!(p.status(42).await["status"], "running");
}

// ── console_url ──

#[tokio::test]
async fn console_url_shape() {
    let (db, _lock, driver) = setup();
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-42")),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.rows(
        "ip_address, storage_pool FROM host_machines WHERE id = ?",
        vec![row(&[
            ("id", json!(1)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.9.9.9")),
            ("storage_pool", json!("p")),
        ])],
    );
    let p = provider(&db, &MemoryLock::new(), driver);
    let out = p.console_url(42).await.unwrap();
    assert_eq!(out["url"], "https://10.9.9.9:6080/vnc.html?vm=kvm-42");
}

#[tokio::test]
async fn console_url_no_vm_uses_resource_id() {
    let (db, _lock, driver) = setup();
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", serde_json::Value::Null),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.rows(
        "ip_address, storage_pool FROM host_machines WHERE id = ?",
        vec![row(&[
            ("id", json!(1)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.9.9.9")),
            ("storage_pool", json!("p")),
        ])],
    );
    let p = provider(&db, &MemoryLock::new(), driver);
    let out = p.console_url(42).await.unwrap();
    assert_eq!(out["url"], "https://10.9.9.9:6080/vnc.html?vm=42");
}

// ── resize_disk / create_disk / create_ip ──

#[tokio::test]
async fn resize_disk_records_and_updates() {
    let (db, _lock, driver) = setup();
    db.rows(
        "AND disk_type = 'system'",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-42")),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.affected("INSERT INTO disk_resizes", 1);
    db.affected("UPDATE disks SET size_gb", 1);
    db.rows(
        "ip_address, storage_pool FROM host_machines WHERE id = ?",
        vec![row(&[
            ("id", json!(1)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.0.0.10")),
            ("storage_pool", json!("p")),
        ])],
    );
    db.rows("LEFT JOIN resources", vec![]);
    db.rows("AS specs FROM host_machines", vec![row(&[("specs", json!("{}"))])]);
    db.affected("UPDATE host_machines SET specs", 1);
    let p = provider(&db, &MemoryLock::new(), driver);

    p.resize_disk(42, 100).await.unwrap();
    // disk_resizes 参数：[id, disk_id, old 50, new 100]
    let params = db.params.lock().unwrap();
    assert!(params.iter().any(|ps| ps.len() == 4 && ps[2] == json!(50) && ps[3] == json!(100)));
    assert!(db.contains("UPDATE disks SET size_gb"));
}

#[tokio::test]
async fn resize_disk_no_system_disk_errors() {
    let (db, _lock, driver) = setup();
    db.rows("AND disk_type = 'system'", vec![]);
    let p = provider(&db, &MemoryLock::new(), driver);
    let err = p.resize_disk(42, 100).await.unwrap_err();
    assert!(err.to_string().contains("no system disk for resource 42"));
}

#[tokio::test]
async fn create_disk_requires_size_gb() {
    let (db, _lock, driver) = setup();
    let p = provider(&db, &MemoryLock::new(), driver);
    let err = p
        .create_disk(&TaskParams { resource_id: Some(42), params: Some(json!({})), ..Default::default() })
        .await
        .unwrap_err();
    assert!(err.to_string().contains("params.size_gb required"));
}

#[tokio::test]
async fn create_disk_inserts_data_disk_vdb() {
    let (db, _lock, driver) = setup();
    db.rows(
        "AND disk_type = 'system'",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-42")),
            ("size_gb", json!(50)),
            ("storage_pool", json!("poolX")),
            ("status", json!("active")),
        ])],
    );
    db.affected("'data',?,'vdb'", 1);
    let p = provider(&db, &MemoryLock::new(), driver);

    let out = p
        .create_disk(&TaskParams { resource_id: Some(42), params: Some(json!({"size_gb": 30})), ..Default::default() })
        .await
        .unwrap();
    assert_eq!(out["device"], "vdb");
    // 数据盘参数含 size 30 与 storage_pool poolX
    let params = db.params.lock().unwrap();
    assert!(params.iter().any(|ps| ps.iter().any(|v| v == &json!(30))));
    assert!(params.iter().any(|ps| ps.iter().any(|v| v == &json!("poolX"))));
}

#[tokio::test]
async fn create_ip_returns_allocated_address() {
    let (db, _lock, driver) = setup();
    db.rows(
        "FROM disks WHERE resource_id",
        vec![row(&[
            ("id", json!(1)),
            ("resource_id", json!(42)),
            ("host_machine_id", json!(1)),
            ("vm_id", json!("kvm-42")),
            ("size_gb", json!(50)),
            ("storage_pool", json!("p")),
            ("status", json!("active")),
        ])],
    );
    db.rows(
        "FROM ip_pools WHERE host_machine_id",
        vec![row(&[
            ("id", json!(5)),
            ("host_machine_id", json!(1)),
            ("ip_start", json!("10.0.0.10")),
            ("ip_end", json!("10.0.0.20")),
            ("gateway", json!("10.0.0.1")),
        ])],
    );
    db.affected("UPDATE ip_pools SET used_count = used_count + 1", 1);
    db.rows("FROM ip_allocations WHERE ip_pool_id", vec![]);
    db.affected("INSERT INTO ip_allocations", 1);
    let p = provider(&db, &MemoryLock::new(), driver);

    let out = p
        .create_ip(&TaskParams { resource_id: Some(42), ..Default::default() })
        .await
        .unwrap();
    assert_eq!(out["ip"], "10.0.0.10");
}

#[tokio::test]
async fn create_ip_missing_resource_errors() {
    let (db, _lock, driver) = setup();
    let p = provider(&db, &MemoryLock::new(), driver);
    let err = p.create_ip(&TaskParams::default()).await.unwrap_err();
    assert!(err.to_string().contains("task.resource_id required"));
}
