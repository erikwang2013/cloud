// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use axum::extract::{Request, State};
use axum::http::StatusCode;
use axum::response::Response;
use axum::Router;
use ecat_transport::Server;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use tokio::net::TcpListener;
use tokio::task::JoinHandle;

#[derive(Debug, Clone)]
pub struct MockRequest {
    pub method: String,
    pub path: String,
    pub body: String,
    pub headers: Vec<(String, String)>,
}

#[derive(Debug, Clone)]
pub struct MockResponse {
    pub status: u16,
    pub body: String,
    pub content_type: &'static str,
}

impl Default for MockResponse {
    fn default() -> Self {
        Self {
            status: 200,
            body: String::new(),
            content_type: "text/plain",
        }
    }
}

struct MockState {
    running: AtomicBool,
    response: RwLock<MockResponse>,
    requests: Mutex<Vec<MockRequest>>,
}

pub struct MockServer {
    state: Arc<MockState>,
    handle: Mutex<Option<JoinHandle<()>>>,
    addr: Mutex<Option<SocketAddr>>,
}

impl MockServer {
    pub fn new() -> Self {
        Self {
            state: Arc::new(MockState {
                running: AtomicBool::new(false),
                response: RwLock::new(MockResponse::default()),
                requests: Mutex::new(Vec::new()),
            }),
            handle: Mutex::new(None),
            addr: Mutex::new(None),
        }
    }
    pub fn is_running(&self) -> bool {
        self.state.running.load(Ordering::SeqCst)
    }
    /// Address the mock server is bound to (available after `start`).
    pub fn url(&self) -> Option<SocketAddr> {
        *self.addr.lock().unwrap()
    }
    /// Preset the response body and status code for subsequent requests.
    pub fn set_response(&self, status: u16, body: impl Into<String>) {
        let mut resp = self.state.response.write().unwrap();
        resp.status = status;
        resp.body = body.into();
    }
    /// Requests received since the server started.
    pub fn received_requests(&self) -> Vec<MockRequest> {
        self.state.requests.lock().unwrap().clone()
    }
}

impl Default for MockServer {
    fn default() -> Self {
        Self::new()
    }
}

#[async_trait]
impl Server for MockServer {
    async fn start(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        if self.is_running() {
            return Ok(());
        }
        let listener = TcpListener::bind("127.0.0.1:0").await?;
        let addr = listener.local_addr()?;
        let state = Arc::clone(&self.state);
        let router = Router::new().fallback(handler).with_state(state);
        let handle = tokio::spawn(async move {
            let _ = axum::serve(listener, router).await;
        });
        self.state.running.store(true, Ordering::SeqCst);
        *self.handle.lock().unwrap() = Some(handle);
        *self.addr.lock().unwrap() = Some(addr);
        Ok(())
    }
    async fn stop(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        if let Some(handle) = self.handle.lock().unwrap().take() {
            handle.abort();
        }
        self.state.running.store(false, Ordering::SeqCst);
        Ok(())
    }
}

async fn handler(State(state): State<Arc<MockState>>, req: Request) -> Response {
    let (parts, body) = req.into_parts();
    let body_bytes = axum::body::to_bytes(body, 1 << 20).await.unwrap_or_default();
    let mut headers = Vec::new();
    for (name, value) in &parts.headers {
        if let Ok(value) = value.to_str() {
            headers.push((name.as_str().to_string(), value.to_string()));
        }
    }
    state.requests.lock().unwrap().push(MockRequest {
        method: parts.method.to_string(),
        path: parts.uri.path().to_string(),
        body: String::from_utf8_lossy(&body_bytes).into_owned(),
        headers,
    });
    let resp = state.response.read().unwrap().clone();
    let status = StatusCode::from_u16(resp.status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR);
    let mut builder = Response::builder().status(status);
    if !resp.content_type.is_empty() {
        builder = builder.header("content-type", resp.content_type);
    }
    builder
        .body(axum::body::Body::from(resp.body))
        .unwrap_or_else(|e| {
            Response::builder()
                .status(StatusCode::INTERNAL_SERVER_ERROR)
                .body(axum::body::Body::from(e.to_string()))
                .unwrap()
        })
}

pub struct ChaosConfig {
    pub latency_ms: Option<u64>,
    pub error_rate: f64,
    pub enabled: bool,
}

impl Default for ChaosConfig {
    fn default() -> Self {
        Self {
            latency_ms: None,
            error_rate: 0.0,
            enabled: false,
        }
    }
}

impl ChaosConfig {
    pub fn with_latency(mut self, ms: u64) -> Self {
        self.latency_ms = Some(ms);
        self.enabled = true;
        self
    }
    pub fn with_errors(mut self, rate: f64) -> Self {
        self.error_rate = rate.clamp(0.0, 1.0);
        self.enabled = true;
        self
    }
    pub fn should_fail(&self) -> bool {
        if !self.enabled || self.error_rate == 0.0 {
            return false;
        }
        rand_fraction() < self.error_rate
    }
    pub fn latency(&self) -> Option<std::time::Duration> {
        self.latency_ms.map(std::time::Duration::from_millis)
    }
}

fn rand_fraction() -> f64 {
    use std::hash::{DefaultHasher, Hash, Hasher};
    let mut h = DefaultHasher::new();
    std::time::Instant::now().hash(&mut h);
    (h.finish() % 10000) as f64 / 10000.0
}

pub struct TestFixture {
    pub app_name: String,
    pub mock_server: MockServer,
    pub chaos: ChaosConfig,
}

impl TestFixture {
    pub fn new(name: impl Into<String>) -> Self {
        Self {
            app_name: name.into(),
            mock_server: MockServer::new(),
            chaos: ChaosConfig::default(),
        }
    }
    pub fn with_chaos(mut self, chaos: ChaosConfig) -> Self {
        self.chaos = chaos;
        self
    }
    pub async fn start(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        self.mock_server.start().await
    }
    pub async fn stop(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        self.mock_server.stop().await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mock_server_constructs() {
        let srv = MockServer::new();
        assert!(!srv.is_running());
    }

    #[tokio::test]
    async fn mock_server_start_stop() {
        let srv = MockServer::new();
        srv.start().await.unwrap();
        assert!(srv.is_running());
        assert!(srv.url().is_some());
        srv.stop().await.unwrap();
        assert!(!srv.is_running());
    }

    async fn http_get(addr: SocketAddr, path: &str) -> String {
        use tokio::io::{AsyncReadExt, AsyncWriteExt};
        let mut stream = tokio::net::TcpStream::connect(addr).await.unwrap();
        let req = format!("GET {path} HTTP/1.1\r\nHost: {addr}\r\nConnection: close\r\n\r\n");
        stream.write_all(req.as_bytes()).await.unwrap();
        let mut buf = Vec::new();
        stream.read_to_end(&mut buf).await.unwrap();
        String::from_utf8_lossy(&buf).into_owned()
    }

    #[tokio::test]
    async fn mock_server_serves_requests() {
        let srv = MockServer::new();
        srv.start().await.unwrap();
        srv.set_response(200, "hello mock");
        let raw = http_get(srv.url().unwrap(), "/health").await;
        assert!(raw.starts_with("HTTP/1.1 200"), "raw: {raw}");
        assert!(raw.contains("hello mock"));
        let reqs = srv.received_requests();
        assert_eq!(reqs.len(), 1);
        assert_eq!(reqs[0].method, "GET");
        assert_eq!(reqs[0].path, "/health");
        srv.stop().await.unwrap();
    }

    #[tokio::test]
    async fn mock_server_custom_status() {
        let srv = MockServer::new();
        srv.start().await.unwrap();
        srv.set_response(404, "not found");
        let raw = http_get(srv.url().unwrap(), "/missing").await;
        assert!(raw.starts_with("HTTP/1.1 404"), "raw: {raw}");
        assert!(raw.contains("not found"));
        assert_eq!(srv.received_requests().len(), 1);
        srv.stop().await.unwrap();
    }

    #[test]
    fn chaos_default_disabled() {
        let c = ChaosConfig::default();
        assert!(!c.enabled);
    }

    #[test]
    fn chaos_with_errors() {
        let c = ChaosConfig::default().with_errors(1.0);
        assert!(c.enabled);
    }

    #[test]
    fn test_fixture_defaults() {
        let f = TestFixture::new("test-app");
        assert_eq!(f.app_name, "test-app");
    }
}
