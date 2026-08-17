use axum::Router;
use axum::routing::post;
use ecat::App;
use ecat_config::{Config, EnvSource};
use ecat_data_redis::RedisLock;
use ecat_data_sqlx::SqlxClient;
use ecat_transport_http::HttpServer;
use std::sync::Arc;

use kvm_server::api::{ApiState, handle_action};
use kvm_server::driver::{KvmDriver, SimulatedKvmDriver, VirshDriver};
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
        auth_token,
    };
    let router = Router::new()
        .route("/v1/kvm/actions", post(handle_action))
        .with_state(state);

    let http_srv = HttpServer::new(addr).router(router);
    let mut app = App::builder()
        .name("kvm-server")
        .version("v0.1.0")
        .server(http_srv)
        .build()?;
    app.run().await?;
    Ok(())
}
