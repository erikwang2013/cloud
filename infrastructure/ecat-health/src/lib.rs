// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use axum::Router;
use axum::http::StatusCode;
use axum::response::IntoResponse;
use axum::routing::get;
use serde::Serialize;
use std::collections::HashMap;
use std::sync::Arc;
use tokio::sync::RwLock;

#[async_trait]
pub trait HealthCheck: Send + Sync {
    fn name(&self) -> &str;
    async fn check(&self) -> Result<(), String>;
}

#[derive(Clone, Default)]
pub struct HealthRegistry {
    checks: Arc<RwLock<HashMap<String, Box<dyn HealthCheck>>>>,
}

impl HealthRegistry {
    pub fn new() -> Self {
        Self::default()
    }

    // async：tokio RwLock 的 blocking_write 在运行时内调用会 panic
    pub async fn with_check(self, check: impl HealthCheck + 'static) -> Self {
        let name = check.name().to_string();
        self.checks.write().await.insert(name, Box::new(check));
        self
    }

    pub fn into_router(self) -> Router {
        let shared = self.checks;

        async fn liveness() -> impl IntoResponse {
            StatusCode::OK
        }

        async fn readiness(
            state: Arc<RwLock<HashMap<String, Box<dyn HealthCheck>>>>,
        ) -> impl IntoResponse {
            let checks = state.read().await;
            if checks.is_empty() {
                return (StatusCode::OK, "no checks registered").into_response();
            }

            let mut results = Vec::with_capacity(checks.len());
            for check in checks.values() {
                match check.check().await {
                    Ok(()) => results.push(CheckResult {
                        name: check.name().to_string(),
                        status: "ok",
                        error: None,
                    }),
                    Err(e) => results.push(CheckResult {
                        name: check.name().to_string(),
                        status: "fail",
                        error: Some(e),
                    }),
                }
            }

            let healthy = results.iter().all(|r| r.status == "ok");
            let status = if healthy {
                StatusCode::OK
            } else {
                StatusCode::SERVICE_UNAVAILABLE
            };
            (status, axum::Json(ReadinessResponse { results })).into_response()
        }

        Router::new()
            .route("/health", get(liveness))
            .route("/ready", get(move || readiness(Arc::clone(&shared))))
    }
}

#[derive(Debug, Serialize)]
struct ReadinessResponse {
    results: Vec<CheckResult>,
}

#[derive(Debug, Serialize)]
struct CheckResult {
    name: String,
    status: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<String>,
}

// ── Built-in checks ──

pub struct FnCheck<F> {
    name: String,
    f: F,
}

impl<F, Fut> FnCheck<F>
where
    F: Fn() -> Fut + Send + Sync,
    Fut: std::future::Future<Output = Result<(), String>> + Send,
{
    pub fn new(name: impl Into<String>, f: F) -> Self {
        Self {
            name: name.into(),
            f,
        }
    }
}

#[async_trait]
impl<F, Fut> HealthCheck for FnCheck<F>
where
    F: Fn() -> Fut + Send + Sync,
    Fut: std::future::Future<Output = Result<(), String>> + Send,
{
    fn name(&self) -> &str {
        &self.name
    }

    async fn check(&self) -> Result<(), String> {
        (self.f)().await
    }
}

// ── Tests ──

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn empty_registry_router() {
        let reg = HealthRegistry::new();
        let _router = reg.into_router();
    }

    #[tokio::test]
    async fn fn_check_passes() {
        let check = FnCheck::new("test", || async { Ok(()) });
        assert!(check.check().await.is_ok());
        assert_eq!(check.name(), "test");
    }

    #[tokio::test]
    async fn fn_check_fails() {
        let check = FnCheck::new("fail", || async { Err("boom".into()) });
        assert!(check.check().await.is_err());
    }

    #[tokio::test]
    async fn registry_builds_with_checks() {
        let _reg = HealthRegistry::new()
            .with_check(FnCheck::new("a", || async { Ok(()) }))
            .await
            .with_check(FnCheck::new("b", || async { Err("err".into()) }))
            .await;
    }

    async fn get(router: axum::Router, path: &str) -> (axum::http::StatusCode, String) {
        use tower::ServiceExt;
        let resp = router
            .oneshot(
                axum::http::Request::builder()
                    .uri(path)
                    .body(axum::body::Body::empty())
                    .unwrap(),
            )
            .await
            .unwrap();
        let (parts, body) = resp.into_parts();
        let bytes = axum::body::to_bytes(body, 1 << 16).await.unwrap();
        (
            parts.status,
            String::from_utf8_lossy(&bytes).into_owned(),
        )
    }

    #[tokio::test]
    async fn liveness_returns_200() {
        let (status, _) = get(HealthRegistry::new().into_router(), "/health").await;
        assert_eq!(status, axum::http::StatusCode::OK);
    }

    #[tokio::test]
    async fn readiness_empty_registry_returns_200() {
        let (status, body) = get(HealthRegistry::new().into_router(), "/ready").await;
        assert_eq!(status, axum::http::StatusCode::OK);
        assert!(body.contains("no checks registered"));
    }

    #[tokio::test]
    async fn readiness_all_healthy_returns_200_ok_json() {
        let reg = HealthRegistry::new()
            .with_check(FnCheck::new("db", || async { Ok(()) }))
            .await
            .with_check(FnCheck::new("cache", || async { Ok(()) }))
            .await;
        let (status, body) = get(reg.into_router(), "/ready").await;
        assert_eq!(status, axum::http::StatusCode::OK);
        let json: serde_json::Value = serde_json::from_str(&body).unwrap();
        assert_eq!(json["results"].as_array().unwrap().len(), 2);
        assert!(json["results"]
            .as_array()
            .unwrap()
            .iter()
            .all(|r| r["status"] == "ok"));
    }

    #[tokio::test]
    async fn readiness_any_failure_returns_503_with_error() {
        let reg = HealthRegistry::new()
            .with_check(FnCheck::new("db", || async { Ok(()) }))
            .await
            .with_check(FnCheck::new("cache", || async {
                Err("connection refused".into())
            }))
            .await;
        let (status, body) = get(reg.into_router(), "/ready").await;
        assert_eq!(status, axum::http::StatusCode::SERVICE_UNAVAILABLE);
        let json: serde_json::Value = serde_json::from_str(&body).unwrap();
        let results = json["results"].as_array().unwrap();
        assert_eq!(results.len(), 2);
        let failed = results.iter().find(|r| r["name"] == "cache").unwrap();
        assert_eq!(failed["status"], "fail");
        assert_eq!(failed["error"], "connection refused");
    }

    #[tokio::test]
    async fn readiness_unknown_path_returns_404() {
        let (status, _) = get(HealthRegistry::new().into_router(), "/nope").await;
        assert_eq!(status, axum::http::StatusCode::NOT_FOUND);
    }
}
