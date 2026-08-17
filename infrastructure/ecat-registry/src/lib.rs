// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
mod memory;

pub use memory::MemoryRegistry;

use async_trait::async_trait;
use serde::{Deserialize, Serialize};
use std::sync::Arc;

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ServiceInfo {
    pub name: String,
    pub version: String,
    pub endpoints: Vec<String>,
    pub metadata: std::collections::HashMap<String, String>,
}

impl ServiceInfo {
    pub fn new(name: impl Into<String>, version: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            version: version.into(),
            endpoints: Vec::new(),
            metadata: std::collections::HashMap::new(),
        }
    }

    pub fn with_endpoint(mut self, endpoint: impl Into<String>) -> Self {
        self.endpoints.push(endpoint.into());
        self
    }
}

pub struct Registration {
    pub id: String,
    pub service: ServiceInfo,
    registry: Option<std::sync::Arc<dyn Registry>>,
}

impl Registration {
    pub fn new(id: String, service: ServiceInfo, registry: Arc<dyn Registry>) -> Self {
        Self {
            id,
            service,
            registry: Some(registry),
        }
    }
}

impl Drop for Registration {
    fn drop(&mut self) {
        if let Some(reg) = self.registry.take() {
            let id = self.id.clone();
            if let Ok(handle) = tokio::runtime::Handle::try_current() {
                handle.spawn(async move {
                    if let Err(e) = reg.deregister(&id).await {
                        tracing::warn!(service_id = %id, error = %e, "auto-deregister on drop failed");
                    }
                });
            } else {
                tracing::warn!(service_id = %id, "runtime dropped; cannot auto-deregister");
            }
        }
    }
}

pub type ChangeHandler = Arc<dyn Fn(Vec<ServiceInfo>) + Send + Sync>;

#[async_trait]
pub trait Registry: Send + Sync {
    async fn register(&self, service: ServiceInfo) -> Result<Registration, RegistryError>;
    async fn deregister(&self, id: &str) -> Result<(), RegistryError>;
    async fn discover(&self, name: &str) -> Result<Vec<ServiceInfo>, RegistryError>;
    async fn list_services(&self) -> Result<Vec<String>, RegistryError>;

    /// 监听某服务的前缀实例集合变更，变化时调用 on_change。
    /// ponytail: etcd HTTP JSON gateway 无 watch 流端点，降级为 5s 轮询快照对比；
    /// 语义等价（lease 过期剔除 ~15s 内反映），待接入 etcd gRPC watch 流再升级。
    async fn watch(self: Arc<Self>, prefix: &str, on_change: ChangeHandler) -> Result<(), RegistryError>
    where
        Self: Sized + 'static,
    {
        let me: Arc<dyn Registry> = self;
        let prefix = prefix.to_string();
        tokio::spawn(async move {
            let mut snapshot: Vec<ServiceInfo> = Vec::new();
            let mut interval = tokio::time::interval(std::time::Duration::from_secs(5));
            interval.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
            interval.tick().await;
            loop {
                interval.tick().await;
                match me.discover(&prefix).await {
                    Ok(instances) => {
                        if instances != snapshot {
                            tracing::info!(name = %prefix, count = instances.len(), "registry watch: peer set changed");
                            snapshot = instances.clone();
                            on_change(instances);
                        }
                    }
                    Err(e) => tracing::warn!(name = %prefix, error = %e, "registry watch poll failed"),
                }
            }
        });
        Ok(())
    }
}

#[derive(Debug, thiserror::Error)]
pub enum RegistryError {
    #[error("service not found: {0}")]
    NotFound(String),
    #[error("registry error: {0}")]
    Other(String),
}
