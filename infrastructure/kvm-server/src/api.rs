use axum::Json;
use axum::extract::State;
use axum::http::HeaderMap;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::sync::Arc;

use crate::driver::KvmError;
use crate::model::TaskParams;
use crate::provider::KvmProvider;

/// 单一 action 入口：9 个动作一个路由（见设计第 3 节）。
#[derive(Debug, Deserialize)]
pub struct ActionRequest {
    pub action: String,
    #[serde(default)]
    pub task: Option<TaskParams>,
    #[serde(default)]
    pub resource_id: Option<i64>,
    #[serde(default)]
    pub params: Option<Value>,
}

#[derive(Debug, Serialize)]
pub struct ActionResponse {
    pub ok: bool,
    pub retryable: bool,
    pub message: String,
    pub data: Option<Value>,
}

/// 常数时间 token 比较：长度差异与逐字节差异都累积进 diff。
fn eq_const_time(a: &str, b: &str) -> bool {
    let (a, b) = (a.as_bytes(), b.as_bytes());
    let mut diff = (a.len() ^ b.len()) as u8;
    for i in 0..a.len().max(b.len()) {
        diff |= a.get(i).copied().unwrap_or(0) ^ b.get(i).copied().unwrap_or(0);
    }
    diff == 0
}

impl ActionResponse {
    fn ok(data: Value) -> Self {
        ActionResponse {
            ok: true,
            retryable: false,
            message: "ok".into(),
            data: Some(data),
        }
    }

    fn err(message: &str, retryable: bool) -> Self {
        ActionResponse {
            ok: false,
            retryable,
            message: message.into(),
            data: None,
        }
    }
}

#[derive(Clone)]
pub struct ApiState {
    pub provider: Arc<KvmProvider>,
    pub auth_token: String,
}

pub async fn handle_action(
    State(state): State<ApiState>,
    headers: HeaderMap,
    Json(req): Json<ActionRequest>,
) -> Json<ActionResponse> {
    let auth = headers
        .get("x-auth-token")
        .and_then(|v| v.to_str().ok())
        .filter(|t| eq_const_time(t, &state.auth_token));
    if auth.is_none() {
        return Json(ActionResponse::err("unauthorized", false));
    }

    // PHP 侧所有 Provider 动作异常都转 retryable，故统一映射
    let resp = run_action(&state, req).await;
    Json(match resp {
        Ok(v) => ActionResponse::ok(v),
        Err(e) => ActionResponse::err(&e.to_string(), true),
    })
}

async fn run_action(
    state: &ApiState,
    req: ActionRequest,
) -> Result<Value, KvmError> {
    let provider = &state.provider;
    match req.action.as_str() {
        "create" => {
            let task = req
                .task
                .ok_or_else(|| KvmError::Retryable("task required for create".into()))?;
            provider.create(&task).await
        }
        "create_disk" => {
            let task = req
                .task
                .ok_or_else(|| KvmError::Retryable("task required for create_disk".into()))?;
            provider.create_disk(&task).await
        }
        "create_ip" => {
            let task = req
                .task
                .ok_or_else(|| KvmError::Retryable("task required for create_ip".into()))?;
            provider.create_ip(&task).await
        }
        "destroy" => {
            let id = req
                .resource_id
                .ok_or_else(|| KvmError::Retryable("resource_id required".into()))?;
            provider.destroy(id).await
        }
        "renew" => {
            let id = req
                .resource_id
                .ok_or_else(|| KvmError::Retryable("resource_id required".into()))?;
            let months = req
                .params
                .as_ref()
                .and_then(|p| p.get("months"))
                .and_then(|v| v.as_i64())
                .ok_or_else(|| KvmError::Retryable("params.months required".into()))?;
            provider.renew(id, months).await
        }
        "upgrade" => {
            let id = req
                .resource_id
                .ok_or_else(|| KvmError::Retryable("resource_id required".into()))?;
            let new_specs = req
                .params
                .as_ref()
                .and_then(|p| p.get("new_specs"))
                .ok_or_else(|| KvmError::Retryable("params.new_specs required".into()))?;
            provider.upgrade(id, new_specs).await
        }
        "resize_disk" => {
            let id = req
                .resource_id
                .ok_or_else(|| KvmError::Retryable("resource_id required".into()))?;
            let size = req
                .params
                .as_ref()
                .and_then(|p| p.get("new_size_gb"))
                .and_then(|v| v.as_i64())
                .ok_or_else(|| KvmError::Retryable("params.new_size_gb required".into()))?;
            provider.resize_disk(id, size).await
        }
        "status" => {
            let id = req
                .resource_id
                .ok_or_else(|| KvmError::Retryable("resource_id required".into()))?;
            Ok(provider.status(id).await)
        }
        "console_url" => {
            let id = req
                .resource_id
                .ok_or_else(|| KvmError::Retryable("resource_id required".into()))?;
            provider.console_url(id).await
        }
        other => Err(KvmError::Retryable(format!("unknown action: {other}"))),
    }
}
