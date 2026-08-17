// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use ecat_registry::{Registration, Registry, RegistryError, ServiceInfo};
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use tokio::task::JoinHandle;

#[derive(Clone)]
pub struct EtcdRegistry {
    client: reqwest::Client,
    endpoints: Vec<String>,
    prefix: String,
    lease_ttl: u64,
    keepalives: Arc<Mutex<HashMap<String, JoinHandle<()>>>>,
}

impl EtcdRegistry {
    pub fn new(endpoints: Vec<String>, prefix: impl Into<String>) -> Self {
        Self {
            client: reqwest::Client::new(),
            endpoints,
            prefix: prefix.into(),
            lease_ttl: 30,
            keepalives: Arc::new(Mutex::new(HashMap::new())),
        }
    }

    pub fn lease_ttl(mut self, ttl: u64) -> Self {
        self.lease_ttl = ttl;
        self
    }

    fn base_url(&self) -> &str {
        self.endpoints
            .first()
            .map(|s| s.as_str())
            .unwrap_or("http://127.0.0.1:2379")
    }

    /// 后台任务周期调用 lease keepalive，防止注册的租约过期被 etcd 回收。
    /// 任务句柄按注册 id 保存，deregister 时 abort。
    fn spawn_keepalive(&self, id: &str, lease_id: i64) {
        let keepalive_url = format!("{}/v3/lease/keepalive", self.base_url());
        let client = self.client.clone();
        let interval_secs = (self.lease_ttl / 3).max(1);
        let handle = tokio::spawn(async move {
            let mut interval = tokio::time::interval(std::time::Duration::from_secs(interval_secs));
            interval.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
            // interval 首个 tick 立即触发，先消耗掉，避免注册后立刻续约
            interval.tick().await;
            loop {
                interval.tick().await;
                if let Err(e) = client
                    .post(&keepalive_url)
                    .json(&serde_json::json!({"ID": lease_id.to_string()}))
                    .send()
                    .await
                {
                    eprintln!("etcd lease keepalive failed: {e}");
                }
            }
        });
        self.keepalives.lock().unwrap().insert(id.to_string(), handle);
    }
}

#[async_trait]
impl Registry for EtcdRegistry {
    async fn register(&self, service: ServiceInfo) -> Result<Registration, RegistryError> {
        // id 含实例 uuid：多副本同名前缀互不干扰，deregister 精确删本实例
        let instance_id = uuid::Uuid::new_v4();
        let id = format!("{}/{}/{}", self.prefix, service.name, instance_id);
        let lease_id = create_lease(&self.client, self.base_url(), self.lease_ttl)
            .await
            .map_err(RegistryError::Other)?;
        let key = format!(
            "/ecat/services/{}/{}/{}",
            self.prefix, service.name, instance_id
        );
        let value = serde_json::to_string(&service)
            .map_err(|e| RegistryError::Other(format!("serialize: {e}")))?;
        let body = serde_json::json!({
            "key": b64(&key),
            "value": b64(&value),
            "lease": lease_id.to_string(),
        });
        self.client
            .post(format!("{}/v3/kv/put", self.base_url()))
            .json(&body)
            .send()
            .await
            .map_err(|e| RegistryError::Other(format!("etcd put: {e}")))?;
        self.spawn_keepalive(&id, lease_id);
        Ok(Registration::new(id, service, Arc::new(self.clone())))
    }

    async fn deregister(&self, id: &str) -> Result<(), RegistryError> {
        if let Some(handle) = self.keepalives.lock().unwrap().remove(id) {
            handle.abort();
        }
        // 注册键为 /ecat/services/{id}（无尾斜杠），范围删除精确匹配本实例
        let prefix = format!("/ecat/services/{id}");
        self.client
            .post(format!("{}/v3/kv/deleterange", self.base_url()))
            .json(&serde_json::json!({
                "key": b64(&prefix),
                "range_end": b64(&prefix_end(&prefix)),
            }))
            .send()
            .await
            .map_err(|e| RegistryError::Other(format!("etcd del: {e}")))?;
        Ok(())
    }

    async fn discover(&self, name: &str) -> Result<Vec<ServiceInfo>, RegistryError> {
        let prefix = format!("/ecat/services/{}/{}/", self.prefix, name);
        let resp = self
            .client
            .post(format!("{}/v3/kv/range", self.base_url()))
            .json(&serde_json::json!({"key": b64(&prefix), "range_end": b64(&prefix_end(&prefix))}))
            .send()
            .await
            .map_err(|e| RegistryError::Other(format!("etcd range: {e}")))?;
        let result: serde_json::Value = resp
            .json()
            .await
            .map_err(|e| RegistryError::Other(format!("etcd parse: {e}")))?;
        let mut services = Vec::new();
        if let Some(kvs) = result.get("kvs").and_then(|v| v.as_array()) {
            for kv in kvs {
                if let Some(v) = kv.get("value").and_then(|v| v.as_str())
                    && let Ok(svc) = decode_b64_str(v).and_then(|s| {
                        serde_json::from_str::<ServiceInfo>(&s).map_err(|e| e.to_string())
                    })
                {
                    services.push(svc);
                }
            }
        }
        Ok(services)
    }

    async fn list_services(&self) -> Result<Vec<String>, RegistryError> {
        let svcs = self.discover("").await?;
        let mut names: Vec<String> = svcs.into_iter().map(|s| s.name).collect();
        names.sort();
        names.dedup();
        Ok(names)
    }
}

async fn create_lease(client: &reqwest::Client, base_url: &str, ttl: u64) -> Result<i64, String> {
    let resp = client
        .post(format!("{base_url}/v3/lease/grant"))
        .json(&serde_json::json!({"TTL": ttl.to_string()}))
        .send()
        .await
        .map_err(|e| format!("etcd lease: {e}"))?;
    let body: serde_json::Value = resp.json().await.map_err(|e| format!("etcd parse: {e}"))?;
    body.get("ID")
        .and_then(|v| v.as_str())
        .and_then(|s| s.parse().ok())
        .ok_or_else(|| "no lease ID".into())
}

fn prefix_end(prefix: &str) -> String {
    let mut bytes = prefix.as_bytes().to_vec();
    for i in (0..bytes.len()).rev() {
        if bytes[i] < 0xff {
            bytes[i] += 1;
            bytes.truncate(i + 1);
            return String::from_utf8_lossy(&bytes).into_owned();
        }
    }
    String::from_utf8_lossy(&bytes).into_owned()
}

use base64::Engine;

fn b64(s: &str) -> String {
    base64::engine::general_purpose::STANDARD.encode(s.as_bytes())
}

fn decode_b64_str(s: &str) -> Result<String, String> {
    let bytes = base64::engine::general_purpose::STANDARD
        .decode(s)
        .map_err(|e| format!("base64: {e}"))?;
    String::from_utf8(bytes).map_err(|e| format!("utf8: {e}"))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::Ordering;

    #[test]
    fn etcd_registry_constructs() {
        let _reg = EtcdRegistry::new(vec!["http://localhost:2379".into()], "ecat").lease_ttl(60);
    }

    #[test]
    fn b64_roundtrip() {
        let input = "hello-world";
        let encoded = b64(input);
        let decoded = decode_b64_str(&encoded).unwrap();
        assert_eq!(decoded, input);
    }

    /// mock etcd 的 lease/kv 端点：记录 keepalive 调用次数。
    async fn spawn_mock_etcd() -> (String, Arc<std::sync::atomic::AtomicUsize>) {
        let keepalives = Arc::new(std::sync::atomic::AtomicUsize::new(0));
        let ka = keepalives.clone();
        let app = axum::Router::new()
            .route(
                "/v3/lease/grant",
                axum::routing::post(|| async {
                    axum::Json(serde_json::json!({"ID": "123"}))
                }),
            )
            .route(
                "/v3/kv/put",
                axum::routing::post(|| async { axum::Json(serde_json::json!({"header": {}})) }),
            )
            .route(
                "/v3/lease/keepalive",
                axum::routing::post(move || async move {
                    ka.fetch_add(1, Ordering::SeqCst);
                    axum::Json(serde_json::json!({"result": {"ID": "123"}}))
                }),
            );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });
        (format!("http://{addr}"), keepalives)
    }

    #[tokio::test]
    async fn register_keeps_lease_alive_until_deregister() {
        let (base_url, keepalives) = spawn_mock_etcd().await;
        let reg = EtcdRegistry::new(vec![base_url], "ecat").lease_ttl(3);
        let registration = reg
            .register(ServiceInfo::new("svc", "1.0"))
            .await
            .unwrap();

        // lease_ttl=3 → 每 1 秒续约一次；等待首个 keepalive 到达
        tokio::time::timeout(std::time::Duration::from_secs(5), async {
            while keepalives.load(Ordering::SeqCst) == 0 {
                tokio::time::sleep(std::time::Duration::from_millis(50)).await;
            }
        })
        .await
        .expect("keepalive never arrived");
        assert!(keepalives.load(Ordering::SeqCst) >= 1);
        assert!(!reg.keepalives.lock().unwrap().is_empty());

        reg.deregister(&registration.id).await.unwrap();
        assert!(reg.keepalives.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn registration_ids_unique_per_instance() {
        let (base_url, _) = spawn_mock_etcd().await;
        let reg = EtcdRegistry::new(vec![base_url], "ecat").lease_ttl(3);
        let r1 = reg.register(ServiceInfo::new("svc", "1.0")).await.unwrap();
        let r2 = reg.register(ServiceInfo::new("svc", "1.0")).await.unwrap();
        assert_ne!(r1.id, r2.id);

        reg.deregister(&r1.id).await.unwrap();
        let keepalives = reg.keepalives.lock().unwrap();
        assert!(!keepalives.contains_key(&r1.id));
        assert!(keepalives.contains_key(&r2.id));
    }

    #[tokio::test]
    async fn deregister_deletes_exact_instance_prefix() {
        let deletes = Arc::new(std::sync::Mutex::new(Vec::<String>::new()));
        let d = deletes.clone();
        let app = axum::Router::new()
            .route(
                "/v3/lease/grant",
                axum::routing::post(|| async { axum::Json(serde_json::json!({"ID": "123"})) }),
            )
            .route(
                "/v3/kv/put",
                axum::routing::post(|| async { axum::Json(serde_json::json!({"header": {}})) }),
            )
            .route(
                "/v3/kv/deleterange",
                axum::routing::post(move |body: axum::Json<serde_json::Value>| async move {
                    let key = body
                        .get("key")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string();
                    d.lock().unwrap().push(key);
                    axum::Json(serde_json::json!({"header": {}}))
                }),
            );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });

        let reg = EtcdRegistry::new(vec![format!("http://{addr}")], "ecat").lease_ttl(3);
        let r = reg.register(ServiceInfo::new("svc", "1.0")).await.unwrap();
        reg.deregister(&r.id).await.unwrap();

        let deleted = deletes.lock().unwrap();
        assert_eq!(deleted.len(), 1);
        let key = base64::engine::general_purpose::STANDARD
            .decode(&deleted[0])
            .unwrap();
        // 删除区间起点必须是注册 key 本身（无尾斜杠），否则范围删除无法命中
        assert_eq!(String::from_utf8(key).unwrap(), format!("/ecat/services/{}", r.id));
    }
}
