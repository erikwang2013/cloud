// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::path::PathBuf;

use async_trait::async_trait;

#[async_trait]
pub trait Server: Send + Sync {
    async fn start(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>>;
    async fn stop(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>>;
}

/// 将空 host 的地址（":8000"）规范化为 IPv4 通配（"0.0.0.0:8000"），
/// 避免解析到 IPv6 [::] 而在无 IPv6 环境绑定失败。供各 transport
/// （http/grpc/ws）在构造时统一调用，保证行为一致。
pub fn normalize_addr(addr: String) -> String {
    if addr.starts_with(':') {
        format!("0.0.0.0{addr}")
    } else {
        addr
    }
}

// ── mTLS Configuration ──

#[derive(Clone)]
pub struct TlsConfig {
    pub cert_path: PathBuf,
    pub key_path: PathBuf,
    pub ca_cert_path: Option<PathBuf>,
    pub require_client_auth: bool,
}

impl TlsConfig {
    pub fn new(cert_path: impl Into<PathBuf>, key_path: impl Into<PathBuf>) -> Self {
        Self {
            cert_path: cert_path.into(),
            key_path: key_path.into(),
            ca_cert_path: None,
            require_client_auth: false,
        }
    }

    pub fn with_client_auth(mut self, ca_cert_path: impl Into<PathBuf>) -> Self {
        self.ca_cert_path = Some(ca_cert_path.into());
        self.require_client_auth = true;
        self
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalize_addr_adds_ipv4_wildcard() {
        assert_eq!(normalize_addr(":8000".into()), "0.0.0.0:8000");
    }

    #[test]
    fn normalize_addr_leaves_hosted_addrs_alone() {
        assert_eq!(normalize_addr("127.0.0.1:8000".into()), "127.0.0.1:8000");
        assert_eq!(normalize_addr("[::1]:8000".into()), "[::1]:8000");
        assert_eq!(normalize_addr("example.com:8000".into()), "example.com:8000");
    }

    struct TestServer;

    #[async_trait]
    impl Server for TestServer {
        async fn start(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
            Ok(())
        }

        async fn stop(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
            Ok(())
        }
    }

    #[tokio::test]
    async fn test_server_trait_start() {
        let server = TestServer;
        assert!(server.start().await.is_ok());
    }

    #[tokio::test]
    async fn test_server_trait_stop() {
        let server = TestServer;
        assert!(server.stop().await.is_ok());
    }
}
