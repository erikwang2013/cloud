// 编排器测试：纯函数（pick_ip/default_rules）+ 依赖 mock DB 的分配/清理路径。
mod common;

use common::{MockDb, row, route_create_flow, route_release_flow};
use ecat_data::RdbmsError;
use kvm_server::driver::{KvmDriver, KvmError, SimulatedKvmDriver, VmSpec};
use kvm_server::model::{HostMachine, Specs};
use kvm_server::orchestrator::{ServiceOrchestrator, default_rules, pick_ip};
use serde_json::json;

fn host() -> HostMachine {
    HostMachine {
        id: 1,
        region_id: 1,
        ip_address: "10.0.0.10".into(),
        storage_pool: "/var/lib/libvirt/images".into(),
    }
}

fn orch(db: &std::sync::Arc<MockDb>) -> ServiceOrchestrator {
    ServiceOrchestrator::new(db.clone())
}

// ── pick_ip 纯函数 ──

#[test]
fn pick_ip_normal_and_skip_allocated() {
    assert_eq!(pick_ip("10.0.0.1", "10.0.0.3", &[]), Some("10.0.0.1".into()));
    assert_eq!(
        pick_ip("10.0.0.1", "10.0.0.3", &["10.0.0.1".into(), "10.0.0.2".into()]),
        Some("10.0.0.3".into())
    );
}

#[test]
fn pick_ip_exhausted_returns_none() {
    let allocated: Vec<String> = (1..=10).map(|i| format!("10.0.0.{i}")).collect();
    assert_eq!(pick_ip("10.0.0.1", "10.0.0.10", &allocated), None);
}

#[test]
fn pick_ip_invalid_input_returns_none() {
    assert_eq!(pick_ip("not-an-ip", "10.0.0.10", &[]), None);
    assert_eq!(pick_ip("10.0.0.1", "10.0.0.10.5", &[]), None);
    assert_eq!(pick_ip("", "10.0.0.1", &[]), None);
    assert_eq!(pick_ip("10.0.0.1", "1.2.3", &[]), None);
    // octet>255（如 .300）按 PHP ip2long 语义视为非法输入
    assert_eq!(pick_ip("10.0.0.1", "10.0.0.300", &[]), None);
}

#[test]
fn pick_ip_start_after_end_returns_none() {
    assert_eq!(pick_ip("10.0.0.10", "10.0.0.1", &[]), None);
}

#[test]
fn pick_ip_allocated_beyond_end_is_ignored() {
    // 已分配列表里出现池外的地址不影响结果
    assert_eq!(
        pick_ip("10.0.0.1", "10.0.0.2", &["192.168.1.1".into()]),
        Some("10.0.0.1".into())
    );
}

// ── allocate_ip ──

#[tokio::test]
async fn allocate_ip_returns_first_free_and_gateway() {
    let db = MockDb::new();
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

    let (alloc, gateway) = orch(&db).allocate_ip(1, 42).await.unwrap();
    assert_eq!(alloc.ip_address, "10.0.0.10");
    assert_eq!(alloc.resource_id, 42);
    assert_eq!(alloc.ip_pool_id, 5);
    assert_eq!(gateway, "10.0.0.1");
    assert!(db.contains("INSERT INTO ip_allocations"));
}

#[tokio::test]
async fn allocate_ip_no_pool_errors() {
    let db = MockDb::new();
    db.rows("FROM ip_pools WHERE host_machine_id", vec![]);
    let err = orch(&db).allocate_ip(1, 42).await.unwrap_err();
    assert!(matches!(&err, KvmError::Retryable(m) if m.contains("no IP pool available")));
}

#[tokio::test]
async fn allocate_ip_duplicate_conflict_retries_then_gives_up() {
    let db = MockDb::new();
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
    // 每次 INSERT 都 1062 冲突（并发竞争者）
    db.route("INSERT INTO ip_allocations", |_, _| {
        Err(RdbmsError::Database(
            "1062 (23000): Duplicate entry '10.0.0.10'".into(),
        ))
    });

    let err = orch(&db).allocate_ip(1, 42).await.unwrap_err();
    assert!(matches!(&err, KvmError::Retryable(m) if m.contains("conflict after 3 attempts")));
    // 每次失败都回滚 used_count
    assert_eq!(db.count("used_count = used_count - 1"), 3);
}

#[tokio::test]
async fn allocate_ip_db_error_propagates() {
    let db = MockDb::new();
    db.fail("FROM ip_pools WHERE host_machine_id", "connection lost");
    let err = orch(&db).allocate_ip(1, 42).await.unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("connection lost")));
}

// ── provision / release ──

#[tokio::test]
async fn provision_success_returns_info_and_active_status() {
    let db = MockDb::new();
    route_create_flow(&db, 42, 1);
    let driver = SimulatedKvmDriver::new();

    let info = orch(&db)
        .provision(&host(), 42, &Specs { cpu: Some(2), ram: Some(2), system_disk: Some(50) }, &driver)
        .await
        .unwrap();
    assert_eq!(info.vm_id, "kvm-42");
    assert_eq!(info.ip_address, "10.0.0.1");
    assert_eq!(info.bridge, "br-vm42");

    // 驱动按序执行了全部 6 个调用
    let calls = driver.state().calls;
    assert_eq!(
        calls,
        [
            "createBridge(br-vm42)",
            "createVeth(veth42a,veth42b,br-vm42,02:00:00:00:00:2a)",
            "createVm(kvm-42)",
            "attachDisk(kvm-42,/var/lib/libvirt/images/kvm-42.qcow2,50)",
            "applyFirewall(fw-vm42,drop)",
            "startVm(kvm-42)",
        ]
    );
    assert_eq!(driver.status("kvm-42").unwrap(), "running");
    assert!(db.contains("UPDATE disks SET status"), "mark_active must run");
}

#[tokio::test]
async fn provision_applies_spec_defaults() {
    let db = MockDb::new();
    route_create_flow(&db, 42, 1);
    let driver = SimulatedKvmDriver::new();
    // 空 specs：cpu→2, ram→2(GB)*1024, system_disk→20（PHP 默认）
    orch(&db).provision(&host(), 42, &Specs::default(), &driver).await.unwrap();
    let calls = driver.state().calls;
    assert!(calls.iter().any(|c| c.contains("createVm(kvm-42)")), "{calls:?}");
    assert!(calls.iter().any(|c| c.contains("attachDisk(kvm-42,") && c.ends_with(",20)")), "{calls:?}");
}

#[tokio::test]
async fn provision_driver_failure_triggers_cleanup() {
    let db = MockDb::new();
    route_create_flow(&db, 42, 1);
    route_release_flow(&db);
    let driver = SimulatedKvmDriver::new();
    driver.fail_on("start_vm");

    let err = orch(&db).provision(&host(), 42, &Specs::default(), &driver).await.unwrap_err();
    assert!(matches!(err, KvmError::Retryable(_)));
    // cleanup → release_db 已执行，且释放了 IP 分配
    assert!(db.contains("UPDATE ip_allocations SET released_at"));
    assert!(db.contains("DELETE FROM network_services"));
    assert!(db.contains("DELETE FROM firewall_services"));
    assert!(db.contains("DELETE FROM switch_services"));
}

#[tokio::test]
async fn provision_insert_failure_triggers_cleanup() {
    let db = MockDb::new();
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
    db.affected("INSERT INTO network_services", 1);
    db.affected("INSERT INTO firewall_services", 1);
    db.fail("INSERT INTO switch_services", "deadlock");
    route_release_flow(&db);
    let driver = SimulatedKvmDriver::new();

    let err = orch(&db).provision(&host(), 42, &Specs::default(), &driver).await.unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("deadlock")));
    assert!(db.contains("UPDATE ip_allocations SET released_at"), "IP must be released on cleanup");
    // 驱动在 insert 失败后不应执行任何创建调用
    assert!(driver.state().calls.is_empty());
}

#[tokio::test]
async fn release_removes_all_services_and_destroys_vm() {
    let db = MockDb::new();
    db.rows(
        "SELECT id, bridge_name FROM network_services",
        vec![row(&[("id", json!(1)), ("bridge_name", json!("br-vm42"))])],
    );
    db.rows(
        "SELECT id, table_name FROM firewall_services",
        vec![row(&[("id", json!(1)), ("table_name", json!("fw-vm42"))])],
    );
    db.rows(
        "SELECT id, veth_host FROM switch_services",
        vec![row(&[("id", json!(1)), ("veth_host", json!("veth42a"))])],
    );
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
    route_release_flow(&db);
    let driver = SimulatedKvmDriver::new();
    driver.create_vm(VmSpec {
        vm_id: "kvm-42".into(),
        cpu: 2,
        ram: 2048,
        mac: "02:00:00:00:00:2a".into(),
        bridge: "br-vm42".into(),
    }).unwrap();

    orch(&db).release(42, &driver).await.unwrap();
    let calls = driver.state().calls;
    assert!(calls.contains(&"removeVeth(veth42a)".into()), "{calls:?}");
    assert!(calls.contains(&"removeBridge(br-vm42)".into()), "{calls:?}");
    assert!(calls.contains(&"removeFirewall(fw-vm42)".into()), "{calls:?}");
    assert!(calls.contains(&"destroyVm(kvm-42)".into()), "{calls:?}");
    assert!(db.contains("DELETE FROM network_services"));
}

#[tokio::test]
async fn release_without_records_skips_driver_cleanup() {
    let db = MockDb::new();
    route_release_flow(&db);
    let driver = SimulatedKvmDriver::new();
    orch(&db).release(42, &driver).await.unwrap();
    assert!(driver.state().calls.is_empty(), "no services -> no driver calls");
}

#[test]
fn default_rules_shape() {
    let rules = default_rules();
    assert_eq!(rules.len(), 2);
    assert_eq!(rules[0].port, Some(22));
    assert!(rules[0].state.is_none());
    assert_eq!(rules[1].state.as_deref(), Some("established,related"));
}
