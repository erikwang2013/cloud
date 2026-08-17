// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use http::{Request, StatusCode};
use security_rust::Scanner;
use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;
use std::task::{Context as TaskCtx, Poll};

pub use security_rust::{AttackCategory, DetectionResult, ScannerBuilder, Severity};

#[derive(Debug, thiserror::Error)]
pub enum SecurityError {
    #[error("attack blocked: {0}")]
    AttackBlocked(String),
    #[error("inner error: {0}")]
    Inner(#[from] Box<dyn std::error::Error + Send + Sync>),
}

impl SecurityError {
    pub fn to_http_status(&self) -> StatusCode {
        match self {
            Self::AttackBlocked(_) => StatusCode::FORBIDDEN,
            Self::Inner(_) => StatusCode::INTERNAL_SERVER_ERROR,
        }
    }
}

/// 拦截结果映射为 HTTP 响应：攻击拦截为 403，内部错误为 500。
impl axum::response::IntoResponse for SecurityError {
    fn into_response(self) -> axum::response::Response {
        let status = self.to_http_status();
        let body = match &self {
            Self::AttackBlocked(types) => format!(r#"{{"error":"attack blocked","types":"{types}"}}"#),
            Self::Inner(e) => format!(r#"{{"error":"{e}"}}"#),
        };
        (status, body).into_response()
    }
}

/// Wraps `security_rust::Scanner` with convenient constructors.
pub struct SecurityScanner {
    scanner: Scanner,
}

impl SecurityScanner {
    /// Create scanner with default detector configuration.
    pub fn new() -> Self {
        Self {
            scanner: Scanner::default(),
        }
    }

    /// Scan a single string through all detectors.
    pub fn scan(&self, input: &str) -> Vec<DetectionResult> {
        self.scanner.scan(input)
    }

    /// Scan multiple request parts (path, headers, body, etc.).
    pub fn scan_parts(&self, parts: &[&str]) -> Vec<DetectionResult> {
        let mut results = Vec::with_capacity(parts.len() * 2);
        for part in parts {
            results.extend(self.scanner.scan(part));
        }
        results
    }

    /// Scan request body bytes. Converts to string for analysis.
    pub fn scan_body(&self, body: &[u8]) -> Vec<DetectionResult> {
        if let Ok(s) = std::str::from_utf8(body) {
            self.scanner.scan(s)
        } else {
            Vec::new()
        }
    }
}

impl Default for SecurityScanner {
    fn default() -> Self {
        Self::new()
    }
}

/// Logs detections and returns a blocking error when a High/Critical attack
/// was found. Shared by the header-scanning and body-scanning middlewares.
fn evaluate(results: &[DetectionResult]) -> Option<SecurityError> {
    for r in results {
        tracing::warn!(
            attack_type = %r.attack_type,
            category = ?r.category,
            severity = ?r.severity,
            matched = %r.matched_pattern,
            "attack detected"
        );
    }
    if results
        .iter()
        .any(|r| matches!(r.severity, Severity::High | Severity::Critical))
    {
        let attack_types: Vec<String> = results.iter().map(|r| r.attack_type.to_string()).collect();
        return Some(SecurityError::AttackBlocked(attack_types.join(", ")));
    }
    None
}

/// Builds the scan list from URI and headers (shared by both middlewares).
fn request_parts<B>(req: &Request<B>) -> Vec<String> {
    let mut parts: Vec<String> = Vec::new();
    parts.push(req.uri().to_string());
    for value in req.headers().values() {
        if let Ok(v) = value.to_str() {
            parts.push(v.to_string());
        }
    }
    parts
}

// ── Tower Layer ──

#[derive(Clone)]
pub struct SecurityLayer {
    scanner: Arc<SecurityScanner>,
}

impl SecurityLayer {
    pub fn new() -> Self {
        Self {
            scanner: Arc::new(SecurityScanner::new()),
        }
    }
}

impl Default for SecurityLayer {
    fn default() -> Self {
        Self::new()
    }
}

impl<S> tower::Layer<S> for SecurityLayer {
    type Service = SecurityService<S>;

    fn layer(&self, inner: S) -> Self::Service {
        SecurityService {
            inner,
            scanner: Arc::clone(&self.scanner),
        }
    }
}

#[derive(Clone)]
pub struct SecurityService<S> {
    inner: S,
    scanner: Arc<SecurityScanner>,
}

impl<S, B> tower::Service<Request<B>> for SecurityService<S>
where
    S: tower::Service<Request<B>> + Send + 'static,
    S::Future: Send + 'static,
    S::Error: Into<Box<dyn std::error::Error + Send + Sync>>,
    B: Send + 'static,
{
    type Response = S::Response;
    type Error = SecurityError;
    type Future = Pin<Box<dyn Future<Output = Result<Self::Response, Self::Error>> + Send>>;

    fn poll_ready(&mut self, cx: &mut TaskCtx<'_>) -> Poll<Result<(), Self::Error>> {
        self.inner
            .poll_ready(cx)
            .map_err(|e| SecurityError::Inner(e.into()))
    }

    fn call(&mut self, req: Request<B>) -> Self::Future {
        let scanner = Arc::clone(&self.scanner);
        let parts = request_parts(&req);
        let strings: Vec<&str> = parts.iter().map(|s| s.as_str()).collect();
        let results = scanner.scan_parts(&strings);

        if let Some(err) = evaluate(&results) {
            return Box::pin(async move { Err(err) });
        }

        let fut = self.inner.call(req);
        Box::pin(async move { fut.await.map_err(|e| SecurityError::Inner(e.into())) })
    }
}

// ── Body-scanning variant ──

/// Tower layer that scans URI, headers, **and** the request body.
///
/// The body is read exactly once (up to [`body_limit`](Self::body_limit)
/// bytes) and passed through to the inner service, so handlers still receive
/// the full payload. Use this instead of [`SecurityLayer`] when request
/// bodies must also be checked for SQLi/XSS payloads.
#[derive(Clone)]
pub struct SecurityBodyLayer {
    scanner: Arc<SecurityScanner>,
    body_limit: usize,
}

impl SecurityBodyLayer {
    pub fn new() -> Self {
        Self {
            scanner: Arc::new(SecurityScanner::new()),
            body_limit: 10 * 1024 * 1024,
        }
    }

    /// Maximum body size (in bytes) that will be buffered and scanned.
    /// Larger bodies are rejected with a 500 rather than buffered.
    pub fn body_limit(mut self, limit: usize) -> Self {
        self.body_limit = limit;
        self
    }
}

impl Default for SecurityBodyLayer {
    fn default() -> Self {
        Self::new()
    }
}

impl<S> tower::Layer<S> for SecurityBodyLayer {
    type Service = SecurityBodyService<S>;

    fn layer(&self, inner: S) -> Self::Service {
        SecurityBodyService {
            inner,
            scanner: Arc::clone(&self.scanner),
            body_limit: self.body_limit,
        }
    }
}

#[derive(Clone)]
pub struct SecurityBodyService<S> {
    inner: S,
    scanner: Arc<SecurityScanner>,
    body_limit: usize,
}

impl<S> tower::Service<Request<axum::body::Body>> for SecurityBodyService<S>
where
    S: tower::Service<Request<axum::body::Body>> + Clone + Send + 'static,
    S::Future: Send + 'static,
    S::Error: Into<Box<dyn std::error::Error + Send + Sync>>,
{
    type Response = S::Response;
    type Error = SecurityError;
    type Future = Pin<Box<dyn Future<Output = Result<Self::Response, Self::Error>> + Send>>;

    fn poll_ready(&mut self, cx: &mut TaskCtx<'_>) -> Poll<Result<(), Self::Error>> {
        self.inner
            .poll_ready(cx)
            .map_err(|e| SecurityError::Inner(e.into()))
    }

    fn call(&mut self, req: Request<axum::body::Body>) -> Self::Future {
        let scanner = Arc::clone(&self.scanner);
        let body_limit = self.body_limit;
        let mut inner = self.inner.clone();
        let parts = request_parts(&req);
        let strings: Vec<&str> = parts.iter().map(|s| s.as_str()).collect();
        let header_results = scanner.scan_parts(&strings);

        Box::pin(async move {
            let (parts, body) = req.into_parts();
            // Read the body exactly once; the collected bytes become the new
            // body so downstream handlers can still access the payload.
            let bytes = axum::body::to_bytes(body, body_limit)
                .await
                .map_err(|e| SecurityError::Inner(Box::new(e)))?;

            let mut results = header_results;
            results.extend(scanner.scan_body(&bytes));

            if let Some(err) = evaluate(&results) {
                return Err(err);
            }

            let req = Request::from_parts(parts, axum::body::Body::from(bytes));
            inner
                .call(req)
                .await
                .map_err(|e| SecurityError::Inner(e.into()))
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn scanner_detects_sql_injection() {
        let s = SecurityScanner::new();
        let results = s.scan("SELECT * FROM users; DROP TABLE users;");
        assert!(!results.is_empty());
    }

    #[test]
    fn scanner_detects_xss() {
        let s = SecurityScanner::new();
        let results = s.scan("<script>alert('xss')</script>");
        assert!(!results.is_empty());
    }

    #[test]
    fn scanner_clean_input_no_detection() {
        let s = SecurityScanner::new();
        let results = s.scan("hello world");
        assert!(results.is_empty());
    }

    #[test]
    fn scanner_scan_parts_aggregates() {
        let s = SecurityScanner::new();
        let results = s.scan_parts(&["clean", "<script>x</script>"]);
        assert!(!results.is_empty());
    }

    #[test]
    fn attack_blocked_maps_to_403() {
        use axum::response::IntoResponse;
        let resp = SecurityError::AttackBlocked("sqli".into()).into_response();
        assert_eq!(resp.status(), StatusCode::FORBIDDEN);
    }

    #[test]
    fn inner_error_maps_to_500() {
        use axum::response::IntoResponse;
        let resp =
            SecurityError::Inner(Box::new(std::io::Error::other("boom"))).into_response();
        assert_eq!(resp.status(), StatusCode::INTERNAL_SERVER_ERROR);
    }

    #[test]
    fn layer_constructs() {
        let _layer = SecurityLayer::new();
    }

    #[test]
    fn layer_default_constructs() {
        let _layer: SecurityLayer = Default::default();
    }

    #[test]
    fn body_layer_constructs() {
        let _layer = SecurityBodyLayer::new().body_limit(1024);
    }

    #[test]
    fn body_layer_default_constructs() {
        let _layer: SecurityBodyLayer = Default::default();
    }

    #[tokio::test]
    async fn body_layer_blocks_attack_in_body() {
        use tower::Layer as _;
        use tower::ServiceExt;

        let layer = SecurityBodyLayer::new();
        let svc = layer.layer(tower::service_fn(|_: Request<axum::body::Body>| async {
            Ok::<_, std::convert::Infallible>(http::Response::new(axum::body::Body::empty()))
        }));

        let req = http::Request::builder()
            .method("POST")
            .uri("/submit")
            .body(axum::body::Body::from("<script>alert('xss')</script>"))
            .unwrap();
        let result = svc.oneshot(req).await;
        assert!(matches!(result, Err(SecurityError::AttackBlocked(_))));
    }

    #[tokio::test]
    async fn body_layer_passes_clean_body_through() {
        use tower::Layer as _;
        use tower::ServiceExt;

        let layer = SecurityBodyLayer::new();
        let svc = layer.layer(tower::service_fn(
            |req: Request<axum::body::Body>| async move {
                let (_, body) = req.into_parts();
                let bytes = axum::body::to_bytes(body, 1024).await.unwrap();
                Ok::<_, std::convert::Infallible>(http::Response::new(axum::body::Body::from(
                    bytes,
                )))
            },
        ));

        let req = http::Request::builder()
            .method("POST")
            .uri("/submit")
            .body(axum::body::Body::from("hello world"))
            .unwrap();
        let resp = svc.oneshot(req).await.expect("clean body passes through");
        let (_, body) = resp.into_parts();
        let bytes = axum::body::to_bytes(body, 1024).await.unwrap();
        assert_eq!(&bytes[..], b"hello world");
    }
}
