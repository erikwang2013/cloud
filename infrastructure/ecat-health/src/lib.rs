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

    pub fn with_check(self, check: impl HealthCheck + 'static) -> Self {
        let name = check.name().to_string();
        self.checks.blocking_write().insert(name, Box::new(check));
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

    #[test]
    fn registry_builds_with_checks() {
        let _reg = HealthRegistry::new()
            .with_check(FnCheck::new("a", || async { Ok(()) }))
            .with_check(FnCheck::new("b", || async { Err("err".into()) }));
    }
}
