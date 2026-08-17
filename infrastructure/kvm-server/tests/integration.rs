//! 集成测试：需要本地 MySQL（install.sql 初始化）+ Redis。
//! 环境变量：KVM_TEST_MYSQL_URL（兼容 KVM_TEST_DB_URL）/ KVM_TEST_REDIS_URL，缺失则跳过；
//! 已设置但连接失败则显式失败（不静默跳过）。
//! 必须 `--test-threads=1` 运行：各用例共享 host id=1 / resource id=42 且各自 cleanup，
//! 并行会互踩（唯一 id 方案见 Phase 2）。
use axum::Json;
use axum::extract::State;
use axum::http::HeaderMap;
use ecat_data::RdbmsClient;
use ecat_data_redis::RedisLock;
use ecat_data_sqlx::SqlxClient;
use kvm_server::api::{ActionRequest, ApiState, handle_action};
use kvm_server::driver::SimulatedKvmDriver;
use kvm_server::model::{Specs, TaskParams};
use kvm_server::provider::KvmProvider;
use kvm_server::selector::HostSelector;
use serde_json::{Value, json};
use std::sync::Arc;

struct TestEnv {
    db: Arc<SqlxClient>,
    lock: Arc<RedisLock>,
}

async fn env() -> Option<TestEnv> {
    let db_url = std::env::var("KVM_TEST_MYSQL_URL")
        .ok()
        .or_else(|| std::env::var("KVM_TEST_DB_URL").ok());
    let redis_url = std::env::var("KVM_TEST_REDIS_URL").ok();
    let (Some(db_url), Some(redis_url)) = (db_url, redis_url) else {
        eprintln!("skipping: KVM_TEST_MYSQL_URL / KVM_TEST_REDIS_URL not set");
        return None;
    };
    // 已配置但连不上：显式失败，防止集成测试假绿
    let db = SqlxClient::connect(&db_url)
        .await
        .expect("KVM_TEST_MYSQL_URL 已设置但连接失败");
    let lock = RedisLock::connect(&redis_url)
        .await
        .expect("KVM_TEST_REDIS_URL 已设置但连接失败");
    Some(TestEnv {
        db: Arc::new(db),
        lock: Arc::new(lock),
    })
}

async fn cleanup(db: &dyn RdbmsClient) {
    for t in [
        "ip_allocations",
        "switch_services",
        "firewall_services",
        "network_services",
        "disk_resizes",
        "disks",
        "resources",
        "ip_pools",
        "host_machines",
    ] {
        let _ = db.execute(&format!("DELETE FROM {t}")).await;
    }
}

async fn seed_host(db: &dyn RdbmsClient, host_id: i64, region_id: i64) {
    db.execute_with(
        "INSERT INTO host_machines (id, region_id, name, ip_address, proxmox_node, storage_pool, api_token_encrypted, hypervisor, specs, status) VALUES (?,?,?,?,?,?,?,'kvm',?,'online')",
        &[
            json!(host_id),
            json!(region_id),
            json!("host-1"),
            json!("10.1.0.10"),
            json!("pve1"),
            json!("/var/lib/libvirt/images"),
            json!("test-token"),
            json!(r#"{"cpu_total":16,"cpu_allocated":0,"ram_total_gb":64,"ram_allocated_gb":0,"disk_total_gb":2000,"disk_allocated_gb":0}"#),
        ],
    )
    .await
    .unwrap();
    db.execute_with(
        "INSERT INTO ip_pools (id, host_machine_id, ip_start, ip_end, gateway, total_count, used_count) VALUES (?,?,?,?,?,?,0)",
        &[
            json!(host_id + 1000),
            json!(host_id),
            json!("10.0.0.10"),
            json!("10.0.0.20"),
            json!("10.0.0.1"),
            json!(11),
        ],
    )
    .await
    .unwrap();
}

async fn seed_resource(db: &dyn RdbmsClient, resource_id: i64, region_id: i64) {
    db.execute_with(
        "INSERT INTO resources (id, order_item_id, user_id, product_id, type, provider, region_id, status, specs) VALUES (?,1,1,1,'kvm','kvm',?,'provisioning',?)",
        &[
            json!(resource_id),
            json!(region_id),
            json!(r#"{"cpu":2,"ram":4,"system_disk":50}"#),
        ],
    )
    .await
    .unwrap();
}

fn provider(env: &TestEnv, driver: Arc<SimulatedKvmDriver>) -> KvmProvider {
    KvmProvider::new(env.db.clone(), env.lock.clone(), driver)
}

fn create_task(resource_id: i64, region_id: i64) -> TaskParams {
    TaskParams {
        resource_id: Some(resource_id),
        region_id: Some(region_id),
        params: Some(json!({"specs": {"cpu": 2, "ram": 4, "system_disk": 50}})),
    }
}

async fn count(db: &dyn RdbmsClient, sql: &str, params: &[Value]) -> i64 {
    db.query_with(sql, params)
        .await
        .unwrap()
        .first()
        .and_then(|r| r.get("n"))
        .and_then(|v| v.as_i64())
        .unwrap_or(-1)
}

#[tokio::test]
async fn create_provisions_with_isolated_naming() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await;
    seed_resource(&*env.db, 42, 10).await;
    let driver = Arc::new(SimulatedKvmDriver::new());
    let p = provider(&env, driver.clone());

    let data = p.create(&create_task(42, 10)).await.unwrap();
    assert_eq!(data["vm_id"], "kvm-42");
    assert_eq!(data["bridge"], "br-vm42");
    assert_eq!(data["ip_address"], "10.0.0.10");

    // 命名隔离断言
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM disks WHERE resource_id = ? AND vm_id = 'kvm-42' AND status = 'active' AND device_path = 'vda'",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 1);
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM network_services WHERE resource_id = ? AND bridge_name = 'br-vm42' AND status = 'active'",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 1);
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM firewall_services WHERE resource_id = ? AND table_name = 'fw-vm42' AND status = 'active'",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 1);
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM switch_services WHERE resource_id = ? AND veth_host = 'veth42a' AND veth_guest = 'veth42b' AND mac_address = '02:00:00:00:00:2a' AND status = 'active'",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 1);

    // 模拟驱动状态
    let st = driver.state();
    assert!(st.bridges.contains("br-vm42"));
    assert_eq!(st.veths["veth42a"].guest, "veth42b");
    assert_eq!(st.vms["kvm-42"].status, "running");
    assert!(st.tables.contains_key("fw-vm42"));

    // 聚合重算后宿主 specs 必须保留 total 键：JSON 列读取若静默丢失，selector 容量过滤会误判
    let host_specs = env
        .db
        .query_with("SELECT CAST(specs AS CHAR(1024)) AS specs FROM host_machines WHERE id = 1", &[])
        .await
        .unwrap()
        .first()
        .and_then(|r| r.get("specs"))
        .and_then(|v| v.as_str())
        .and_then(|s| serde_json::from_str::<serde_json::Value>(s).ok())
        .unwrap_or_else(|| json!({}));
    assert_eq!(host_specs["cpu_total"], 16);
    assert_eq!(host_specs["ram_total_gb"], 64);
    assert_eq!(host_specs["disk_total_gb"], 2000);
    assert_eq!(host_specs["cpu_allocated"], 2);
    assert_eq!(host_specs["ram_allocated_gb"], 4);
    assert_eq!(host_specs["disk_allocated_gb"], 50);
}

#[tokio::test]
async fn create_rolls_back_on_driver_failure() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await;
    seed_resource(&*env.db, 42, 10).await;
    let driver = Arc::new(SimulatedKvmDriver::new());
    driver.fail_on("start_vm");
    let p = provider(&env, driver.clone());

    let err = p.create(&create_task(42, 10)).await.unwrap_err();
    assert!(err.to_string().contains("start_vm"), "got: {err}");

    // 驱动侧尽力清理：bridge/veth/table 全部移除，vm 标记 destroyed
    let st = driver.state();
    assert!(st.bridges.is_empty(), "bridges: {:?}", st.bridges);
    assert!(st.veths.is_empty(), "veths: {:?}", st.veths);
    assert!(st.tables.is_empty(), "tables: {:?}", st.tables);
    assert_eq!(st.vms["kvm-42"].status, "destroyed");

    // DB 侧：服务记录删除、磁盘 destroyed、IP 释放
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM network_services WHERE resource_id = ?",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 0);
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM disks WHERE resource_id = ? AND status = 'destroyed'",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 1);
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM ip_allocations WHERE resource_id = ? AND released_at IS NULL",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 0);
}

#[tokio::test]
async fn destroy_releases_resources() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await;
    seed_resource(&*env.db, 42, 10).await;
    let driver = Arc::new(SimulatedKvmDriver::new());
    let p = provider(&env, driver.clone());

    p.create(&create_task(42, 10)).await.unwrap();
    p.destroy(42).await.unwrap();

    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM ip_allocations WHERE resource_id = ? AND released_at IS NULL",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 0);
    let n = count(
        &*env.db,
        "SELECT COUNT(*) n FROM disks WHERE resource_id = ? AND status = 'destroyed'",
        &[json!(42)],
    )
    .await;
    assert_eq!(n, 1);
    for t in ["network_services", "firewall_services", "switch_services"] {
        let n = count(&*env.db, &format!("SELECT COUNT(*) n FROM {t} WHERE resource_id = ?"), &[json!(42)]).await;
        assert_eq!(n, 0, "{t} not cleaned");
    }
    let st = driver.state();
    assert_eq!(st.vms["kvm-42"].status, "destroyed");
}

#[tokio::test]
async fn concurrent_create_same_region_only_one_wins() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await;
    seed_resource(&*env.db, 42, 10).await;
    let driver = Arc::new(SimulatedKvmDriver::new());
    let p = Arc::new(provider(&env, driver.clone()));
    let task = create_task(42, 10);

    let mut handles = Vec::new();
    for _ in 0..2 {
        let p = p.clone();
        let task = task.clone();
        handles.push(tokio::spawn(async move { p.create(&task).await.is_ok() }));
    }
    let results: Vec<bool> = {
        let mut v = Vec::new();
        for h in handles {
            v.push(h.await.unwrap());
        }
        v
    };
    assert_eq!(results.iter().filter(|ok| **ok).count(), 1, "exactly one create wins: {results:?}");
}

#[tokio::test]
async fn ip_allocation_is_sequential_and_respects_existing() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await;
    seed_resource(&*env.db, 42, 10).await;
    seed_resource(&*env.db, 43, 10).await;
    let driver = Arc::new(SimulatedKvmDriver::new());
    let p = provider(&env, driver.clone());

    let d1 = p.create(&create_task(42, 10)).await.unwrap();
    assert_eq!(d1["ip_address"], "10.0.0.10");
    let d2 = p.create(&create_task(43, 10)).await.unwrap();
    assert_eq!(d2["ip_address"], "10.0.0.11");

    // 预占 .12 后 create_ip 应跳过已分配
    db_execute(
        &*env.db,
        "INSERT INTO ip_allocations (id, ip_pool_id, resource_id, ip_address, type) VALUES (?,1001,999,'10.0.0.12','primary')",
        &[json!(9_999_999_999i64)],
    )
    .await;
    let ip = p.create_ip(&TaskParams { resource_id: Some(42), region_id: None, params: None }).await.unwrap();
    assert_eq!(ip["ip"], "10.0.0.13");
}

#[tokio::test]
async fn create_ip_reuses_first_disk_host() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await;
    seed_resource(&*env.db, 42, 10).await;
    let driver = Arc::new(SimulatedKvmDriver::new());
    let p = provider(&env, driver.clone());

    p.create(&create_task(42, 10)).await.unwrap();
    let ip = p
        .create_ip(&TaskParams { resource_id: Some(42), region_id: None, params: None })
        .await
        .unwrap();
    assert_eq!(ip["ip"], "10.0.0.11");
}

async fn db_execute(db: &dyn RdbmsClient, sql: &str, params: &[Value]) {
    db.execute_with(sql, params).await.unwrap();
}

#[tokio::test]
async fn api_rejects_missing_or_wrong_auth_token() {
    let Some(env) = env().await else { return };
    let driver = Arc::new(SimulatedKvmDriver::new());
    let state = ApiState {
        provider: Arc::new(provider(&env, driver.clone())),
        auth_token: "test-token".into(),
    };

    // 用永不落库的 resource id：destroy 必经业务错误而非残留数据干扰
    let destroy_req = || ActionRequest {
        action: "destroy".into(),
        task: None,
        resource_id: Some(424242),
        params: None,
    };

    let Json(resp) = handle_action(State(state.clone()), HeaderMap::new(), Json(destroy_req())).await;
    assert_eq!(resp.message, "unauthorized");
    assert!(!resp.retryable);

    let mut h = HeaderMap::new();
    h.insert("x-auth-token", "wrong-token".parse().unwrap());
    let Json(resp) = handle_action(State(state.clone()), h, Json(destroy_req())).await;
    assert_eq!(resp.message, "unauthorized");

    // 正确 token 通过鉴权进入业务逻辑：resource 42 无磁盘 → 业务错误而非 unauthorized
    let mut h = HeaderMap::new();
    h.insert("x-auth-token", "test-token".parse().unwrap());
    let Json(resp) = handle_action(State(state.clone()), h, Json(destroy_req())).await;
    assert_ne!(resp.message, "unauthorized");
    assert!(resp.retryable);
    assert!(resp.message.contains("no disk"), "got: {}", resp.message);
}

#[tokio::test]
async fn host_selector_filters_capacity_and_orders_by_cpu_usage() {
    let Some(env) = env().await else { return };
    cleanup(&*env.db).await;
    seed_host(&*env.db, 1, 10).await; // cpu_allocated 0 → 占用率最低，应被选中

    // host 2：cpu 余量不足（15/16，请求 2）→ 必须被过滤
    db_execute(
        &*env.db,
        "INSERT INTO host_machines (id, region_id, name, ip_address, proxmox_node, storage_pool, api_token_encrypted, hypervisor, specs, status) VALUES (?,10,'host-2','10.1.0.11','pve2','/var/lib/libvirt/images','tok','kvm',?,'online')",
        &[json!(2), json!(r#"{"cpu_total":16,"cpu_allocated":15,"ram_total_gb":64,"ram_allocated_gb":0,"disk_total_gb":2000,"disk_allocated_gb":0}"#)],
    )
    .await;
    // host 3：余量足但占用率更高（8/16）
    db_execute(
        &*env.db,
        "INSERT INTO host_machines (id, region_id, name, ip_address, proxmox_node, storage_pool, api_token_encrypted, hypervisor, specs, status) VALUES (?,10,'host-3','10.1.0.12','pve3','/var/lib/libvirt/images','tok','kvm',?,'online')",
        &[json!(3), json!(r#"{"cpu_total":16,"cpu_allocated":8,"ram_total_gb":64,"ram_allocated_gb":0,"disk_total_gb":2000,"disk_allocated_gb":0}"#)],
    )
    .await;

    let sel = HostSelector::new(env.db.clone());
    let specs = Specs { cpu: Some(2), ram: Some(4), system_disk: Some(50) };

    let host = sel.select(10, &specs).await.unwrap();
    assert_eq!(host.id, 1, "cpu 占用率最低的宿主应被选中");

    // 占用率最低者余量不足后，应选中 host 3（剩余候选唯一）
    db_execute(
        &*env.db,
        "UPDATE host_machines SET specs = ? WHERE id = 1",
        &[json!(r#"{"cpu_total":16,"cpu_allocated":15,"ram_total_gb":64,"ram_allocated_gb":0,"disk_total_gb":2000,"disk_allocated_gb":0}"#)],
    )
    .await;
    let host = sel.select(10, &specs).await.unwrap();
    assert_eq!(host.id, 3);

    // 所有宿主余量不足 → 无合适宿主
    db_execute(
        &*env.db,
        "UPDATE host_machines SET specs = ? WHERE id = 3",
        &[json!(r#"{"cpu_total":16,"cpu_allocated":15,"ram_total_gb":64,"ram_allocated_gb":0,"disk_total_gb":2000,"disk_allocated_gb":0}"#)],
    )
    .await;
    let err = sel.select(10, &specs).await.unwrap_err();
    assert!(err.to_string().contains("no suitable KVM host"), "got: {err}");
}
