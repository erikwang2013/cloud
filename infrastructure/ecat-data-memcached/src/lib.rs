// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! ⚠️ **内存实现，仅用于开发/测试，禁止生产使用** / **IN-MEMORY FAKE
//! IMPLEMENTATION — development/testing only, NOT for production.**
//!
//! This crate does **not** speak the memcached network protocol and never
//! connects to a server. All data lives in a process-local in-memory
//! `HashMap`, is shared only within this process, and is lost on restart.
//! It exists to exercise the `Cache` trait locally. **Do not use it in
//! production** — replace it with a real memcached/redis client before
//! deployment.

use async_trait::async_trait;
use ecat_data::{Cache, CacheError};
use ecat_tls::TlsClientConfig;
use serde::Deserialize;
use std::collections::HashMap;
use std::time::{Duration, Instant};
use tokio::sync::Mutex;

type CacheEntry = (Vec<u8>, Option<Instant>);

/// ⚠️ **内存实现，仅用于开发/测试，禁止生产使用** — this config drives an
/// in-memory cache only; there is no memcached server connection.
#[derive(Debug, Clone, Deserialize, Default)]
pub struct MemcachedConfig {
    #[serde(default)]
    pub username: Option<String>,
    #[serde(default)]
    pub password: Option<String>,
    /// TLS config — reserved for future network-based memcached implementation.
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
    /// Whether this client runs in the built-in in-memory mode (no network).
    /// Always `true` in the current implementation — the memcached protocol
    /// is not implemented. Reserved for future real protocol support.
    #[serde(default)]
    pub in_memory: Option<bool>,
}

/// ⚠️ **内存实现，仅用于开发/测试，禁止生产使用** — `MemcachedClient` is an
/// in-memory `HashMap` cache that is API-compatible with the `Cache` trait.
/// It never talks to a memcached server; data is process-local and lost on
/// restart. For local development/testing only.
pub struct MemcachedClient {
    store: Mutex<HashMap<Vec<u8>, CacheEntry>>,
    _username: Option<String>,
    _password: Option<String>,
}

impl MemcachedClient {
    pub fn new() -> Self {
        Self {
            store: Mutex::new(HashMap::new()),
            _username: None,
            _password: None,
        }
    }

    pub fn with_auth(_username: impl Into<String>, _password: impl Into<String>) -> Self {
        Self {
            store: Mutex::new(HashMap::new()),
            _username: Some(_username.into()),
            _password: Some(_password.into()),
        }
    }

    /// 与 workspace 其它数据后端一致：返回 `Result<Self, CacheError>`。
    /// 内存实现不会失败，恒为 `Ok`。
    pub fn from_config(cfg: MemcachedConfig) -> Result<Self, CacheError> {
        Ok(Self {
            store: Mutex::new(HashMap::new()),
            _username: cfg.username,
            _password: cfg.password,
        })
    }
}

impl Default for MemcachedClient {
    fn default() -> Self {
        Self::new()
    }
}

/// Above this store size, `set()` starts sweeping expired entries.
const SWEEP_THRESHOLD: usize = 1024;
/// Max expired entries removed per `set()` call (sampled sweep).
const SWEEP_SAMPLE: usize = 64;

#[async_trait]
impl Cache for MemcachedClient {
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>, CacheError> {
        let mut store = self.store.lock().await;
        match store.get(key.as_bytes()) {
            Some((_, Some(exp))) if Instant::now() > *exp => {
                store.remove(key.as_bytes());
                Ok(None)
            }
            Some((val, _)) => Ok(Some(val.clone())),
            None => Ok(None),
        }
    }

    async fn set(&self, key: &str, value: &[u8], ttl: Duration) -> Result<(), CacheError> {
        let mut store = self.store.lock().await;
        // Bound memory: once the store grows large, sample-sweep expired
        // entries on every set() (at most SWEEP_SAMPLE removals per call).
        if store.len() >= SWEEP_THRESHOLD {
            let now = Instant::now();
            let mut swept = 0usize;
            store.retain(|_, (_, exp)| {
                if swept >= SWEEP_SAMPLE {
                    return true;
                }
                match exp {
                    Some(e) if *e <= now => {
                        swept += 1;
                        false
                    }
                    _ => true,
                }
            });
        }
        let expires = if ttl.is_zero() {
            None
        } else {
            Some(Instant::now() + ttl)
        };
        store.insert(key.as_bytes().to_vec(), (value.to_vec(), expires));
        Ok(())
    }

    async fn delete(&self, key: &str) -> Result<(), CacheError> {
        let mut store = self.store.lock().await;
        store.remove(key.as_bytes());
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn set_get() {
        let c = MemcachedClient::new();
        c.set("k", b"v", Duration::from_secs(60)).await.unwrap();
        assert_eq!(c.get("k").await.unwrap(), Some(b"v".to_vec()));
    }

    #[tokio::test]
    async fn get_missing() {
        let c = MemcachedClient::new();
        assert_eq!(c.get("nope").await.unwrap(), None);
    }

    #[tokio::test]
    async fn delete_removes() {
        let c = MemcachedClient::new();
        c.set("x", b"y", Duration::from_secs(60)).await.unwrap();
        c.delete("x").await.unwrap();
        assert_eq!(c.get("x").await.unwrap(), None);
    }

    #[test]
    fn from_config_returns_ok_and_works() {
        let c = MemcachedClient::from_config(MemcachedConfig {
            username: Some("u".into()),
            password: Some("p".into()),
            ..Default::default()
        })
        .unwrap();
        let rt = tokio::runtime::Runtime::new().unwrap();
        rt.block_on(async {
            c.set("cfg", b"v", Duration::from_secs(60)).await.unwrap();
            assert_eq!(c.get("cfg").await.unwrap(), Some(b"v".to_vec()));
        });
    }
}
