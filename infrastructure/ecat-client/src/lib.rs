// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use std::collections::HashMap;
use std::sync::Arc;
use std::time::Duration;
use tokio::sync::RwLock;

// ── Service Resolver ──

#[async_trait]
pub trait ServiceResolver: Send + Sync {
    async fn resolve(&self, name: &str) -> Result<Vec<String>, String>;
}

#[derive(Clone, Default)]
pub struct StaticResolver {
    endpoints: Arc<RwLock<HashMap<String, Vec<String>>>>,
}

impl StaticResolver {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn add_service(self, name: impl Into<String>, endpoints: Vec<String>) -> Self {
        self.endpoints
            .blocking_write()
            .insert(name.into(), endpoints);
        self
    }

    pub fn single(name: impl Into<String>, endpoint: impl Into<String>) -> Self {
        let mut map = HashMap::new();
        map.insert(name.into(), vec![endpoint.into()]);
        Self {
            endpoints: Arc::new(RwLock::new(map)),
        }
    }
}

#[async_trait]
impl ServiceResolver for StaticResolver {
    async fn resolve(&self, name: &str) -> Result<Vec<String>, String> {
        self.endpoints
            .read()
            .await
            .get(name)
            .cloned()
            .ok_or_else(|| format!("no endpoints for service '{name}'"))
    }
}

// ── Load Balancer ──

pub trait LoadBalancer: Send + Sync {
    fn pick(&self, endpoints: &[String]) -> Option<String>;
}

pub struct RoundRobin {
    counter: std::sync::atomic::AtomicUsize,
}

impl RoundRobin {
    pub fn new() -> Self {
        Self {
            counter: std::sync::atomic::AtomicUsize::new(0),
        }
    }
}

impl Default for RoundRobin {
    fn default() -> Self {
        Self::new()
    }
}

impl LoadBalancer for RoundRobin {
    fn pick(&self, endpoints: &[String]) -> Option<String> {
        if endpoints.is_empty() {
            return None;
        }
        let idx = self
            .counter
            .fetch_add(1, std::sync::atomic::Ordering::Relaxed)
            % endpoints.len();
        Some(endpoints[idx].clone())
    }
}

pub struct RandomBalancer;

impl LoadBalancer for RandomBalancer {
    fn pick(&self, endpoints: &[String]) -> Option<String> {
        if endpoints.is_empty() {
            return None;
        }
        use std::collections::hash_map::RandomState;
        use std::hash::{BuildHasher, Hasher};
        let idx = RandomState::new().build_hasher().finish() as usize % endpoints.len();
        endpoints.get(idx).cloned()
    }
}

// ── HTTP Client ──

pub struct HttpClient {
    client: reqwest::Client,
    resolver: Arc<dyn ServiceResolver>,
    balancer: Arc<dyn LoadBalancer>,
    timeout: Duration,
}

impl HttpClient {
    pub fn builder() -> HttpClientBuilder {
        HttpClientBuilder::default()
    }

    pub async fn get(&self, service: &str, path: &str) -> Result<reqwest::Response, String> {
        let endpoints = self.resolver.resolve(service).await?;
        let endpoint = self
            .balancer
            .pick(&endpoints)
            .ok_or_else(|| format!("no available endpoint for '{service}'"))?;
        let url = format!("{endpoint}{path}");
        tracing::debug!(url, "http client: GET");
        self.client
            .get(&url)
            .timeout(self.timeout)
            .send()
            .await
            .map_err(|e| format!("http request failed: {e}"))
    }

    pub async fn post(
        &self,
        service: &str,
        path: &str,
        body: &[u8],
    ) -> Result<reqwest::Response, String> {
        let endpoints = self.resolver.resolve(service).await?;
        let endpoint = self
            .balancer
            .pick(&endpoints)
            .ok_or_else(|| format!("no available endpoint for '{service}'"))?;
        let url = format!("{endpoint}{path}");
        self.client
            .post(&url)
            .body(body.to_vec())
            .timeout(self.timeout)
            .send()
            .await
            .map_err(|e| format!("http request failed: {e}"))
    }

    pub async fn health(&self, service: &str) -> Result<(), String> {
        let resp = self.get(service, "/health").await?;
        if resp.status().is_success() {
            Ok(())
        } else {
            Err(format!("health check returned {}", resp.status()))
        }
    }
}

// ── Builder ──

pub struct HttpClientBuilder {
    resolver: Option<Arc<dyn ServiceResolver>>,
    balancer: Option<Arc<dyn LoadBalancer>>,
    timeout: Duration,
}

impl Default for HttpClientBuilder {
    fn default() -> Self {
        Self {
            resolver: None,
            balancer: None,
            timeout: Duration::from_secs(5),
        }
    }
}

impl HttpClientBuilder {
    pub fn resolver(mut self, r: impl ServiceResolver + 'static) -> Self {
        self.resolver = Some(Arc::new(r));
        self
    }

    pub fn balancer(mut self, b: impl LoadBalancer + 'static) -> Self {
        self.balancer = Some(Arc::new(b));
        self
    }

    pub fn timeout(mut self, d: Duration) -> Self {
        self.timeout = d;
        self
    }

    pub fn build(self) -> Result<HttpClient, String> {
        let resolver = self
            .resolver
            .ok_or_else(|| "resolver is required".to_string())?;
        let balancer = self.balancer.unwrap_or_else(|| Arc::new(RoundRobin::new()));
        Ok(HttpClient {
            client: reqwest::Client::builder()
                .build()
                .map_err(|e| format!("failed to build http client: {e}"))?,
            resolver,
            balancer,
            timeout: self.timeout,
        })
    }
}

// ── gRPC Client ──

pub struct GrpcClient {
    resolver: Arc<dyn ServiceResolver>,
    balancer: Arc<dyn LoadBalancer>,
}

impl GrpcClient {
    pub fn builder() -> GrpcClientBuilder {
        GrpcClientBuilder::default()
    }

    pub async fn connect(&self, service: &str) -> Result<tonic::transport::Channel, String> {
        let endpoints = self.resolver.resolve(service).await?;
        let endpoint = self
            .balancer
            .pick(&endpoints)
            .ok_or_else(|| format!("no available endpoint for '{service}'"))?;
        tonic::transport::Endpoint::from_shared(endpoint)
            .map_err(|e| format!("invalid endpoint: {e}"))?
            .connect()
            .await
            .map_err(|e| format!("grpc connect failed: {e}"))
    }
}

#[derive(Default)]
pub struct GrpcClientBuilder {
    resolver: Option<Arc<dyn ServiceResolver>>,
    balancer: Option<Arc<dyn LoadBalancer>>,
}

impl GrpcClientBuilder {
    pub fn resolver(mut self, r: impl ServiceResolver + 'static) -> Self {
        self.resolver = Some(Arc::new(r));
        self
    }

    pub fn balancer(mut self, b: impl LoadBalancer + 'static) -> Self {
        self.balancer = Some(Arc::new(b));
        self
    }

    pub fn build(self) -> Result<GrpcClient, String> {
        Ok(GrpcClient {
            resolver: self
                .resolver
                .ok_or_else(|| "resolver is required".to_string())?,
            balancer: self.balancer.unwrap_or_else(|| Arc::new(RoundRobin::new())),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn static_resolver_resolves() {
        let resolver = StaticResolver::single("auth", "http://localhost:8080");
        let eps = resolver.resolve("auth").await.unwrap();
        assert_eq!(eps, vec!["http://localhost:8080"]);
    }

    #[tokio::test]
    async fn static_resolver_not_found() {
        let resolver = StaticResolver::new();
        assert!(resolver.resolve("unknown").await.is_err());
    }

    #[test]
    fn round_robin_picks() {
        let balancer = RoundRobin::new();
        let eps = vec!["a".into(), "b".into(), "c".into()];
        let picks: Vec<_> = (0..6).map(|_| balancer.pick(&eps).unwrap()).collect();
        assert_eq!(picks, ["a", "b", "c", "a", "b", "c"]);
    }

    #[test]
    fn round_robin_empty() {
        assert_eq!(RoundRobin::new().pick(&[]), None);
    }

    #[test]
    fn random_empty() {
        assert_eq!(RandomBalancer.pick(&[]), None);
    }

    #[tokio::test]
    async fn client_builder_requires_resolver() {
        let result = HttpClient::builder().build();
        assert!(result.is_err());
    }

    #[tokio::test]
    async fn client_builder_with_resolver() {
        let resolver = StaticResolver::single("svc", "http://localhost:9000");
        let client = HttpClient::builder()
            .resolver(resolver)
            .timeout(Duration::from_secs(3))
            .build()
            .unwrap();
        assert_eq!(client.timeout, Duration::from_secs(3));
    }
}
