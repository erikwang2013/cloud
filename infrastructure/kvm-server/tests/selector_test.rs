// 宿主机选择器测试：SQL 形状 + 参数默认值 + 无可用主机/DB 错误路径。
mod common;

use common::{MockDb, row};
use kvm_server::driver::KvmError;
use kvm_server::model::Specs;
use kvm_server::selector::HostSelector;
use serde_json::json;

fn selector(db: &std::sync::Arc<MockDb>) -> HostSelector {
    HostSelector::new(db.clone())
}

#[tokio::test]
async fn select_returns_capacity_ordered_host() {
    let db = MockDb::new();
    db.rows(
        "WHERE region_id = ? AND hypervisor",
        vec![row(&[
            ("id", json!(7)),
            ("region_id", json!(3)),
            ("ip_address", json!("10.1.0.10")),
            ("storage_pool", json!("/var/lib/libvirt/images")),
        ])],
    );
    let host = selector(&db).select(3, &Specs::default()).await.unwrap();
    assert_eq!(host.id, 7);
    assert_eq!(host.region_id, 3);
    assert_eq!(host.ip_address, "10.1.0.10");
}

#[tokio::test]
async fn select_applies_php_default_specs() {
    let db = MockDb::new();
    db.rows("WHERE region_id = ? AND hypervisor", vec![]);
    let _ = selector(&db).select(3, &Specs::default()).await;
    // SQL 参数：[region, cpu(默认1), ram(默认1), system_disk(默认10)]
    let params = db.params.lock().unwrap();
    assert_eq!(params[0], vec![json!(3), json!(1), json!(1), json!(10)]);
}

#[tokio::test]
async fn select_uses_explicit_specs() {
    let db = MockDb::new();
    db.rows("WHERE region_id = ? AND hypervisor", vec![]);
    let _ = selector(&db)
        .select(3, &Specs { cpu: Some(4), ram: Some(8), system_disk: Some(100) })
        .await;
    let params = db.params.lock().unwrap();
    assert_eq!(params[0], vec![json!(3), json!(4), json!(8), json!(100)]);
}

#[tokio::test]
async fn select_no_suitable_host_errors_retryable() {
    let db = MockDb::new();
    db.rows("WHERE region_id = ? AND hypervisor", vec![]);
    let err = selector(&db).select(3, &Specs::default()).await.unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("no suitable KVM host")));
}

#[tokio::test]
async fn select_db_error_propagates_as_retryable() {
    let db = MockDb::new();
    db.fail("WHERE region_id = ? AND hypervisor", "server gone away");
    let err = selector(&db).select(3, &Specs::default()).await.unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("server gone away")));
}

#[tokio::test]
async fn select_host_row_missing_column_errors() {
    let db = MockDb::new();
    // 缺 storage_pool 列：from_row 失败应转 Retryable 而非 panic
    db.rows(
        "WHERE region_id = ? AND hypervisor",
        vec![row(&[
            ("id", json!(7)),
            ("region_id", json!(3)),
            ("ip_address", json!("10.1.0.10")),
        ])],
    );
    let err = selector(&db).select(3, &Specs::default()).await.unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("storage_pool")));
}

#[tokio::test]
async fn selector_sql_mentions_capacity_guards() {
    // 防止 SQL 被改坏导致容量过滤缺失（与 PHP 逐句一致）
    let db = MockDb::new();
    db.rows("WHERE region_id = ? AND hypervisor", vec![]);
    let _ = selector(&db).select(1, &Specs::default()).await;
    let sql = db.executed.lock().unwrap()[0].clone();
    for needle in [
        "hypervisor = 'kvm'",
        "status = 'online'",
        "cpu_allocated",
        "ram_allocated_gb",
        "disk_allocated_gb",
        "ORDER BY",
        "LIMIT 1",
    ] {
        assert!(sql.contains(needle), "missing {needle} in: {sql}");
    }
}
