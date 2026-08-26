// gRPC 层测试：check_auth 拦截器（纯函数）+ KvmGrpcService 的 ping/create_vm/vm_status。
mod common;

use common::{MemoryLock, MockDb};
use ecat_protos::kvm::kvm_service_server::KvmService;
use ecat_protos::kvm::{CreateVmRequest, PingRequest, VmStatusRequest};
use kvm_server::api::ApiState;
use kvm_server::driver::SimulatedKvmDriver;
use kvm_server::grpc::{KvmGrpcService, check_auth};
use kvm_server::provider::KvmProvider;
use std::sync::Arc;
use tonic::{Code, Request};

fn req_with_auth(token: &str) -> Request<()> {
    let mut req = Request::new(());
    req.metadata_mut()
        .insert("authorization", token.parse().unwrap());
    req
}

#[test]
fn check_auth_accepts_matching_bearer() {
    let f = check_auth("s3cret".into());
    assert!(f(req_with_auth("Bearer s3cret")).is_ok());
}

#[test]
fn check_auth_accepts_raw_token_without_prefix() {
    let f = check_auth("s3cret".into());
    assert!(f(req_with_auth("s3cret")).is_ok());
}

#[test]
fn check_auth_rejects_wrong_token_same_length() {
    let f = check_auth("s3cret".into());
    assert!(matches!(f(req_with_auth("Bearer x3cret")), Err(s) if s.code() == Code::Unauthenticated));
}

#[test]
fn check_auth_rejects_wrong_length_token() {
    let f = check_auth("s3cret".into());
    assert!(matches!(f(req_with_auth("Bearer xyz")), Err(s) if s.code() == Code::Unauthenticated));
}

#[test]
fn check_auth_rejects_missing_header() {
    let f = check_auth("s3cret".into());
    assert!(matches!(f(Request::new(())), Err(s) if s.code() == Code::Unauthenticated));
}

#[test]
fn check_auth_rejects_empty_token() {
    let f = check_auth("s3cret".into());
    assert!(matches!(f(req_with_auth("")), Err(s) if s.code() == Code::Unauthenticated));
}

fn service() -> KvmGrpcService {
    let db = MockDb::new();
    db.rows("SELECT id FROM resources WHERE id = ?", vec![]); // resource 缺失 → 快速失败
    let provider = Arc::new(KvmProvider::new(
        db,
        MemoryLock::new(),
        Arc::new(SimulatedKvmDriver::new()),
    ));
    KvmGrpcService::new(ApiState { provider, auth_token: "unused".into() })
}

#[tokio::test]
async fn ping_returns_pong() {
    let s = service();
    let resp = s.ping(Request::new(PingRequest {})).await.unwrap();
    assert_eq!(resp.into_inner().pong, "pong");
}

#[tokio::test]
async fn create_vm_errors_are_retryable_replies() {
    let s = service();
    let resp = s
        .create_vm(Request::new(CreateVmRequest { resource_id: 42, region_id: 1, specs_json: "{}".into() }))
        .await
        .unwrap()
        .into_inner();
    assert!(!resp.ok);
    assert!(resp.retryable);
    assert!(resp.message.contains("resource 42 not found"));
    assert_eq!(resp.data_json, "");
}

#[tokio::test]
async fn create_vm_accepts_invalid_specs_json_as_no_specs() {
    // 非法 JSON → params=None → 仍走 create（这里因资源缺失报错，而不是 JSON 解析崩溃）
    let s = service();
    let resp = s
        .create_vm(Request::new(CreateVmRequest { resource_id: 42, region_id: 1, specs_json: "not-json".into() }))
        .await
        .unwrap()
        .into_inner();
    assert!(!resp.ok);
    assert!(resp.message.contains("resource 42 not found"));
}

#[tokio::test]
async fn vm_status_returns_data() {
    let s = service();
    let resp = s
        .vm_status(Request::new(VmStatusRequest { resource_id: 42 }))
        .await
        .unwrap()
        .into_inner();
    assert!(resp.ok);
    assert!(resp.data_json.contains("\"status\""));
}
