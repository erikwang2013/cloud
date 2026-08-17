// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use ecat_transport::{normalize_addr, Server as TransportServer, TlsConfig};
use std::io;
use std::sync::Mutex;
use std::sync::OnceLock;
use tokio::sync::watch;
use tonic::service::Routes;
use tonic::transport::ServerTlsConfig;

pub struct GrpcServer {
    addr: String,
    routes: Option<Routes>,
    shutdown_tx: Mutex<Option<watch::Sender<()>>>,
    tls_config: Option<TlsConfig>,
}

impl GrpcServer {
    pub fn new(addr: impl Into<String>) -> Self {
        Self {
            // 空 host（如 ":50051"）会解析到 IPv6 通配 [::]，在无 IPv6 环境
            // 绑定失败；规范化为 IPv4 通配 "0.0.0.0"
            addr: normalize_addr(addr.into()),
            routes: None,
            shutdown_tx: Mutex::new(None),
            tls_config: None,
        }
    }

    pub fn routes(mut self, routes: Routes) -> Self {
        self.routes = Some(routes);
        self
    }

    pub fn tls(mut self, config: TlsConfig) -> Self {
        self.tls_config = Some(config);
        self
    }
}

/// 统一 workspace 内同时存在 aws-lc-rs（tonic tls 默认）与 ring（reqwest/hyper-rustls）
/// 两个 provider 时 rustls 无法自动选择，需显式安装默认 provider（首装生效，忽略 Err）。
fn ensure_crypto_provider() {
    static INSTALLED: OnceLock<()> = OnceLock::new();
    if INSTALLED.get().is_none() {
        let _ = rustls::crypto::CryptoProvider::install_default(
            rustls::crypto::ring::default_provider(),
        );
        let _ = INSTALLED.set(());
    }
}

fn build_server_tls_config(tls: &TlsConfig) -> Result<ServerTlsConfig, io::Error> {
    let cert = std::fs::read_to_string(&tls.cert_path)
        .map_err(|e| io::Error::new(e.kind(), format!("read cert: {e}")))?;
    let key = std::fs::read_to_string(&tls.key_path)
        .map_err(|e| io::Error::new(e.kind(), format!("read key: {e}")))?;
    let mut cfg =
        ServerTlsConfig::new().identity(tonic::transport::Identity::from_pem(cert, key));
    if tls.require_client_auth {
        let ca = tls
            .ca_cert_path
            .as_ref()
            .ok_or_else(|| io::Error::new(io::ErrorKind::InvalidInput, "mTLS requires ca_cert_path"))?;
        let ca = std::fs::read_to_string(ca)
            .map_err(|e| io::Error::new(e.kind(), format!("read ca: {e}")))?;
        cfg = cfg.client_ca_root(tonic::transport::Certificate::from_pem(ca));
    }
    Ok(cfg)
}

#[async_trait::async_trait]
impl TransportServer for GrpcServer {
    async fn start(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        let addr = self.addr.parse()?;
        let routes = self.routes.clone().unwrap_or_default();
        let (tx, mut rx) = watch::channel(());
        *self.shutdown_tx.lock().unwrap_or_else(|e| e.into_inner()) = Some(tx);
        let shutdown_signal = async move {
            let _ = rx.changed().await;
        };
        let mut builder = tonic::transport::Server::builder();
        if let Some(tls) = &self.tls_config {
            // tonic 在 tls_config() 中立即构建 rustls acceptor，必须先装好 provider
            ensure_crypto_provider();
            builder = builder.tls_config(build_server_tls_config(tls)?)?;
        }
        builder
            .add_routes(routes)
            .serve_with_shutdown(addr, shutdown_signal)
            .await?;
        Ok(())
    }

    async fn stop(&self) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
        if let Some(tx) = self
            .shutdown_tx
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .take()
        {
            let _ = tx.send(());
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use ecat_tls::{generate_client_cert, generate_server_cert};
    use std::path::Path;
    use std::sync::Arc;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tonic::transport::{Certificate, Channel, ClientTlsConfig, Endpoint, Identity};

    fn write_pem_files(dir: &Path, suffix: &str, pair: &ecat_tls::CertPair) -> (std::path::PathBuf, std::path::PathBuf) {
        let cert_path = dir.join(format!("{suffix}.crt"));
        let key_path = dir.join(format!("{suffix}.key"));
        std::fs::write(&cert_path, &pair.cert_pem).unwrap();
        std::fs::write(&key_path, &pair.key_pem).unwrap();
        (cert_path, key_path)
    }

    fn free_port() -> u16 {
        std::net::TcpListener::bind("127.0.0.1:0")
            .unwrap()
            .local_addr()
            .unwrap()
            .port()
    }

    fn start_server(tls: TlsConfig) -> (Arc<GrpcServer>, tokio::task::JoinHandle<()>, u16) {
        let port = free_port();
        let server = Arc::new(GrpcServer::new(format!("127.0.0.1:{port}")).tls(tls));
        let task = tokio::spawn({
            let s = Arc::clone(&server);
            async move {
                let _ = s.start().await;
            }
        });
        (server, task, port)
    }

    async fn connect_with_retry(endpoint: Endpoint) -> Result<Channel, tonic::transport::Error> {
        ensure_crypto_provider();
        for _ in 0..50 {
            match endpoint.clone().connect().await {
                Ok(ch) => return Ok(ch),
                Err(_) => tokio::time::sleep(std::time::Duration::from_millis(100)).await,
            }
        }
        endpoint.connect().await
    }

    fn client_config(
        root_pem: &str,
        pair: Option<&ecat_tls::CertPair>,
    ) -> Result<rustls::ClientConfig, String> {
        let mut roots = rustls::RootCertStore::empty();
        let mut cursor = std::io::Cursor::new(root_pem.as_bytes());
        for cert in rustls_pemfile::certs(&mut cursor)
            .collect::<Result<Vec<_>, _>>()
            .map_err(|e| format!("parse root: {e}"))?
        {
            roots.add(cert).map_err(|e| format!("add root: {e}"))?;
        }
        let builder = rustls::ClientConfig::builder().with_root_certificates(roots);
        match pair {
            None => Ok(builder.with_no_client_auth()),
            Some(p) => {
                let certs = rustls_pemfile::certs(&mut std::io::Cursor::new(p.cert_pem.as_bytes()))
                    .collect::<Result<Vec<_>, _>>()
                    .map_err(|e| format!("parse cert: {e}"))?;
                let key = rustls_pemfile::private_key(&mut std::io::Cursor::new(p.key_pem.as_bytes()))
                    .map_err(|e| format!("parse key: {e}"))?
                    .ok_or_else(|| "no private key".to_string())?;
                builder
                    .with_client_auth_cert(certs, key)
                    .map_err(|e| format!("client auth: {e}"))
            }
        }
    }

    /// TLS 1.3 下客户端握手在收到服务端首条 flight（含 Finished）即视为完成，
    /// 服务端的 mTLS 拒绝告警（CertificateRequired）随后才到达 —— 拒绝必须以
    /// 握手后的后续 I/O 失败来断言。
    async fn probe_rejected(
        port: u16,
        root_pem: &str,
        pair: Option<&ecat_tls::CertPair>,
    ) -> bool {
        ensure_crypto_provider();
        let Ok(cfg) = client_config(root_pem, pair) else {
            return true;
        };
        let connector = tokio_rustls::TlsConnector::from(Arc::new(cfg));
        let tcp = loop {
            match tokio::net::TcpStream::connect(format!("127.0.0.1:{port}")).await {
                Ok(s) => break s,
                Err(_) => tokio::time::sleep(std::time::Duration::from_millis(100)).await,
            }
        };
        let name = rustls::pki_types::ServerName::try_from("localhost").unwrap();
        let tls = match tokio::time::timeout(
            std::time::Duration::from_secs(5),
            connector.connect(name, tcp),
        )
        .await
        {
            Ok(Ok(s)) => s,
            _ => return true,
        };
        let (mut rd, mut wr) = tokio::io::split(tls);
        let _ = wr.write_all(b"PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n").await;
        let mut buf = [0u8; 64];
        for _ in 0..10 {
            match tokio::time::timeout(std::time::Duration::from_millis(200), rd.read(&mut buf)).await {
                Ok(Ok(_)) => return true,
                Ok(Err(_)) => return true,
                Err(_) => continue,
            }
        }
        false
    }

    #[test]
    fn new_sets_addr() {
        let srv = GrpcServer::new("0.0.0.0:50051");
        assert_eq!(srv.addr, "0.0.0.0:50051");
    }

    /// N3：空 host 地址（":50051"）必须规范化为 IPv4 通配，与 HttpServer 一致。
    #[test]
    fn new_normalizes_empty_host_addr() {
        let srv = GrpcServer::new(":50051");
        assert_eq!(srv.addr, "0.0.0.0:50051");
    }

    #[test]
    fn routes_sets_routes() {
        let routes = tonic::service::Routes::default();
        let srv = GrpcServer::new("0.0.0.0:50051").routes(routes);
        assert!(srv.routes.is_some());
    }

    #[test]
    fn new_without_routes_has_none() {
        let srv = GrpcServer::new("0.0.0.0:50051");
        assert!(srv.routes.is_none());
    }

    #[test]
    fn mtls_config_requires_ca_path() {
        let tls = TlsConfig::new("/nope.crt", "/nope.key").with_client_auth("/nope-ca.pem");
        let cfg = build_server_tls_config(&tls);
        assert!(cfg.is_err());
    }

    #[tokio::test]
    async fn tls_server_accepts_client_handshake() {
        // 测试线程与 server task 并发，server 里的 ensure_crypto_provider() 尚未执行时
        // 客户端构造 ClientTlsConfig 会因 rustls 未安装默认 CryptoProvider 而 panic
        ensure_crypto_provider();
        let dir = std::env::temp_dir().join(format!("ecat-grpc-tls-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let server_pair = generate_server_cert("localhost").unwrap();
        let (cert_path, key_path) = write_pem_files(&dir, "server", &server_pair);
        let (server, task, port) = start_server(TlsConfig::new(cert_path, key_path));

        let endpoint = Endpoint::from_shared(format!("https://localhost:{port}"))
            .unwrap()
            .tls_config(
                ClientTlsConfig::new()
                    .ca_certificate(Certificate::from_pem(server_pair.cert_pem.clone())),
            )
            .unwrap();
        let channel = connect_with_retry(endpoint).await;
        assert!(channel.is_ok(), "tls connect failed: {:?}", channel.err());

        server.stop().await.unwrap();
        let _ = task.await;
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[tokio::test]
    async fn mtls_requires_client_cert() {
        ensure_crypto_provider();
        let dir = std::env::temp_dir().join(format!("ecat-grpc-mtls-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let server_pair = generate_server_cert("localhost").unwrap();
        let client_pair = generate_client_cert("test-client").unwrap();
        let (cert_path, key_path) = write_pem_files(&dir, "server", &server_pair);
        let (ca_path, _) = write_pem_files(&dir, "ca", &client_pair);
        let (server, task, port) =
            start_server(TlsConfig::new(cert_path, key_path).with_client_auth(ca_path));

        let base = Endpoint::from_shared(format!("https://localhost:{port}")).unwrap();

        // 带客户端证书 → 握手成功
        let with_cert = base
            .clone()
            .tls_config(
                ClientTlsConfig::new()
                    .ca_certificate(Certificate::from_pem(server_pair.cert_pem.clone()))
                    .identity(Identity::from_pem(
                        client_pair.cert_pem.clone(),
                        client_pair.key_pem.clone(),
                    )),
            )
            .unwrap();
        assert!(
            connect_with_retry(with_cert).await.is_ok(),
            "client with cert must connect"
        );

        // 无客户端证书 → 服务端拒绝：tonic connect() 因 TLS 1.3 握手时序会先行成功，
        // 改用裸 tokio-rustls 客户端以握手后的后续 I/O（读告警/EOF）断言被拒绝
        assert!(
            probe_rejected(port, &server_pair.cert_pem, None).await,
            "client without cert must be rejected"
        );

        server.stop().await.unwrap();
        let _ = task.await;
        let _ = std::fs::remove_dir_all(&dir);
    }
}
