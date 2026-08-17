use axum::Router;
use axum::routing::post;
use ecat::App;
use ecat_config::{Config, EnvSource};
use ecat_data_redis::RedisLock;
use ecat_data_sqlx::SqlxClient;
use ecat_protos::kvm::kvm_service_server::KvmServiceServer;
use ecat_registry::{Registry, ServiceInfo};
use ecat_registry_etcd::EtcdRegistry;
use ecat_transport_grpc::GrpcServer;
use ecat_transport_http::HttpServer;
use std::collections::HashMap;
use std::sync::{Arc, RwLock};
use tonic::service::Routes;

use kvm_server::api::{ApiState, handle_action};
use kvm_server::driver::{KvmDriver, SimulatedKvmDriver, VirshDriver};
use kvm_server::grpc::KvmGrpcService;
use kvm_server::provider::KvmProvider;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    ecat_logging::init();

    // 环境变量配置：KVM_DB_URL / KVM_REDIS_URL / KVM_ADDR / KVM_AUTH_TOKEN / KVM_DRIVER(simulated|virsh)
    let mut cfg = Config::new();
    cfg.load(&EnvSource::new("KVM_")).await?;
    let db_url = cfg
        .get::<String>("db_url")
        .ok_or("KVM_DB_URL env required")?;
    let redis_url = cfg
        .get::<String>("redis_url")
        .ok_or("KVM_REDIS_URL env required")?;
    let addr = cfg.get::<String>("addr").unwrap_or_else(|| "0.0.0.0:8000".into());
    let auth_token = cfg
        .get::<String>("auth_token")
        .ok_or("KVM_AUTH_TOKEN env required")?;
    let driver_mode = cfg.get::<String>("driver").unwrap_or_else(|| "virsh".into());

    let db = Arc::new(SqlxClient::connect(&db_url).await?);
    let lock = Arc::new(RedisLock::connect(&redis_url).await?);
    let driver: Arc<dyn KvmDriver> = match driver_mode.as_str() {
        "simulated" => Arc::new(SimulatedKvmDriver::new()),
        _ => Arc::new(VirshDriver::new()),
    };

    let state = ApiState {
        provider: Arc::new(KvmProvider::new(db, lock, driver)),
        auth_token: auth_token.clone(),
    };
    let router = Router::new()
        .route("/v1/kvm/actions", post(handle_action))
        .with_state(state.clone());

    let http_srv = HttpServer::new(addr).router(router);

    // gRPC：与 HTTP 双栈共存，同一 KvmProvider
    let grpc_addr = cfg
        .get::<String>("grpc_addr")
        .unwrap_or_else(|| "0.0.0.0:50051".into());
    let routes = Routes::new(KvmServiceServer::with_interceptor(
        KvmGrpcService::new(state),
        kvm_server::grpc::check_auth(auth_token.clone()),
    ));
    let grpc_srv = GrpcServer::new(grpc_addr).routes(routes);

    // etcd 注册 + lease 心跳（keepalive 由 EtcdRegistry 内部后台任务驱动）
    // peers 存活表：watch service/admin 前缀，当前仅日志 + 状态可查询，无业务联动
    let peers: Arc<RwLock<HashMap<String, Vec<ServiceInfo>>>> = Arc::new(RwLock::new(HashMap::new()));
    let mut registration: Option<ecat_registry::Registration> = None;
    if let Some(etcd_url) = cfg.get::<String>("etcd_url") {
        let registry = Arc::new(EtcdRegistry::new(vec![etcd_url], "cloud").lease_ttl(15));
        registration = Some(
            registry
                .register(ServiceInfo::new("kvm-server", env!("CARGO_PKG_VERSION")))
                .await?,
        );
        tracing::info!("registered kvm-server in etcd");
        for name in ["service", "admin"] {
            let peers = peers.clone();
            registry
                .clone()
                .watch(name, Arc::new(move |instances| {
                    tracing::info!(peer = %name, count = instances.len(), "peer liveness updated");
                    peers.write().unwrap().insert(name.into(), instances);
                }))
                .await?;
        }
    }

    let mut app = App::builder()
        .name("kvm-server")
        .version("v0.1.0")
        .server(http_srv)
        .server(grpc_srv)
        .build()?;
    // 退出时 deregister 尽力而为（Drop），进程崩溃则由 lease 过期兜底
    let _ = registration.as_ref();
    app.run().await?;
    Ok(())
}
