// Thin tonic wrapper over the existing KvmProvider — no business logic here.
use ecat_protos::kvm::kvm_service_server::KvmService;
use ecat_protos::kvm::{ActionReply, CreateVmRequest, PingRequest, PingResponse, VmStatusRequest};
use serde_json::Value;
use tonic::{Request, Response, Status};

use crate::api::ApiState;
use crate::model::TaskParams;

/// Authorization interceptor: requires `authorization: Bearer <token>` metadata,
/// constant-time compared (same token check as the HTTP API).
pub fn check_auth(expected: String) -> impl Fn(tonic::Request<()>) -> Result<tonic::Request<()>, Status> + Clone {
    move |req: tonic::Request<()>| {
        let ok = req
            .metadata()
            .get("authorization")
            .and_then(|v| v.to_str().ok())
            .map(|v| v.strip_prefix("Bearer ").unwrap_or(v))
            .map(|t| t.len() == expected.len() && t.bytes().zip(expected.bytes()).all(|(a, b)| a == b))
            .unwrap_or(false);
        if ok {
            Ok(req)
        } else {
            Err(Status::unauthenticated("unauthorized"))
        }
    }
}

pub struct KvmGrpcService {
    state: ApiState,
}

impl KvmGrpcService {
    pub fn new(state: ApiState) -> Self {
        KvmGrpcService { state }
    }
}

fn reply(ok: bool, retryable: bool, message: String, data: Option<Value>) -> ActionReply {
    ActionReply {
        ok,
        retryable,
        message,
        data_json: data.map(|v| v.to_string()).unwrap_or_default(),
    }
}

#[tonic::async_trait]
impl KvmService for KvmGrpcService {
    async fn ping(
        &self,
        _req: Request<PingRequest>,
    ) -> Result<Response<PingResponse>, Status> {
        Ok(Response::new(PingResponse {
            pong: "pong".into(),
        }))
    }

    async fn create_vm(
        &self,
        req: Request<CreateVmRequest>,
    ) -> Result<Response<ActionReply>, Status> {
        let r = req.into_inner();
        let params = if r.specs_json.is_empty() {
            None
        } else {
            serde_json::from_str::<Value>(&r.specs_json)
                .ok()
                .map(|specs| serde_json::json!({ "specs": specs }))
        };
        let task = TaskParams {
            resource_id: Some(r.resource_id),
            region_id: Some(r.region_id),
            params,
        };
        let resp = match self.state.provider.create(&task).await {
            Ok(v) => reply(true, false, "ok".into(), Some(v)),
            Err(e) => reply(false, true, e.to_string(), None),
        };
        Ok(Response::new(resp))
    }

    async fn vm_status(
        &self,
        req: Request<VmStatusRequest>,
    ) -> Result<Response<ActionReply>, Status> {
        let id = req.into_inner().resource_id;
        let data = self.state.provider.status(id).await;
        Ok(Response::new(reply(true, false, "ok".into(), Some(data))))
    }
}
