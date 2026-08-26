// HTTP action 入口测试：鉴权（常数时间比较）+ action 分发。
mod common;

use common::{MemoryLock, MockDb, route_create_flow};
use axum::Json;
use axum::extract::State;
use axum::http::HeaderMap;
use kvm_server::api::{ActionRequest, ActionResponse, ApiState, handle_action};
use kvm_server::driver::SimulatedKvmDriver;
use kvm_server::provider::KvmProvider;
use serde_json::json;
use std::sync::Arc;

fn state() -> ApiState {
    let db = MockDb::new();
    route_create_flow(&db, 42, 1);
    let lock = MemoryLock::new();
    let driver: Arc<dyn kvm_server::driver::KvmDriver> = Arc::new(SimulatedKvmDriver::new());
    let provider = Arc::new(KvmProvider::new(db, lock, driver));
    ApiState { provider, auth_token: "secret-token".into() }
}

async fn call(state: &ApiState, token: Option<&str>, body: ActionRequest) -> ActionResponse {
    let mut headers = HeaderMap::new();
    if let Some(t) = token {
        headers.insert("x-auth-token", t.parse().unwrap());
    }
    handle_action(State(state.clone()), headers, Json(body)).await.0
}

#[tokio::test]
async fn missing_token_is_unauthorized_not_retryable() {
    let resp = call(&state(), None, ActionRequest { action: "status".into(), task: None, resource_id: Some(1), params: None }).await;
    assert!(!resp.ok);
    assert!(!resp.retryable);
    assert_eq!(resp.message, "unauthorized");
}

#[tokio::test]
async fn wrong_token_length_diff_is_unauthorized() {
    let resp = call(&state(), Some("short"), ActionRequest { action: "status".into(), task: None, resource_id: Some(1), params: None }).await;
    assert!(!resp.ok);
    assert_eq!(resp.message, "unauthorized");
}

#[tokio::test]
async fn wrong_token_same_length_is_unauthorized() {
    let resp = call(&state(), Some("secret-token-x"), ActionRequest { action: "status".into(), task: None, resource_id: Some(1), params: None }).await;
    assert!(!resp.ok);
    assert_eq!(resp.message, "unauthorized");
}

#[tokio::test]
async fn unknown_action_is_retryable() {
    let resp = call(&state(), Some("secret-token"), ActionRequest { action: "explode".into(), task: None, resource_id: None, params: None }).await;
    assert!(!resp.ok);
    assert!(resp.retryable);
    assert!(resp.message.contains("unknown action: explode"));
}

#[tokio::test]
async fn create_without_task_is_retryable() {
    let resp = call(&state(), Some("secret-token"), ActionRequest { action: "create".into(), task: None, resource_id: None, params: None }).await;
    assert!(!resp.ok);
    assert!(resp.retryable);
    assert!(resp.message.contains("task required for create"));
}

#[tokio::test]
async fn status_without_resource_id_is_retryable() {
    let resp = call(&state(), Some("secret-token"), ActionRequest { action: "status".into(), task: None, resource_id: None, params: None }).await;
    assert!(!resp.ok);
    assert!(resp.retryable);
    assert!(resp.message.contains("resource_id required"));
}

#[tokio::test]
async fn renew_without_months_is_retryable() {
    let resp = call(&state(), Some("secret-token"), ActionRequest { action: "renew".into(), task: None, resource_id: Some(1), params: Some(json!({})) }).await;
    assert!(!resp.ok);
    assert!(resp.retryable);
    assert!(resp.message.contains("params.months required"));
}

#[tokio::test]
async fn full_create_through_http_handler() {
    let resp = call(&state(), Some("secret-token"), ActionRequest {
        action: "create".into(),
        task: Some(kvm_server::model::TaskParams {
            resource_id: Some(42),
            region_id: Some(1),
            params: Some(json!({"specs": {"cpu": 2, "ram": 4, "system_disk": 50}})),
        }),
        resource_id: None,
        params: None,
    }).await;
    assert!(resp.ok, "{}", resp.message);
    assert!(!resp.retryable);
    let data = resp.data.expect("data on success");
    assert_eq!(data["vm_id"], "kvm-42");
}

#[tokio::test]
async fn status_action_never_fails_returns_error_status() {
    // status 无磁盘 → 内部 error，但 HTTP 层仍 ok=true（与 PHP 一致不抛错）
    let db = MockDb::new();
    db.rows("FROM disks WHERE resource_id", vec![]);
    let provider = Arc::new(KvmProvider::new(
        db,
        MemoryLock::new(),
        Arc::new(SimulatedKvmDriver::new()),
    ));
    let st = ApiState { provider, auth_token: "secret-token".into() };
    let resp = call(&st, Some("secret-token"), ActionRequest { action: "status".into(), task: None, resource_id: Some(99), params: None }).await;
    assert!(resp.ok);
    assert_eq!(resp.data.unwrap()["status"], "error");
}
