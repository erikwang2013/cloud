// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use std::time::Duration;

#[async_trait]
pub trait Cache: Send + Sync {
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>, CacheError>;
    async fn set(&self, key: &str, value: &[u8], ttl: Duration) -> Result<(), CacheError>;
    async fn delete(&self, key: &str) -> Result<(), CacheError>;

    /// Atomically increment the value at `key` by `delta`, returning the new value.
    /// Backends that cannot increment return an error.
    async fn increment(&self, _key: &str, _delta: i64) -> Result<i64, CacheError> {
        Err(CacheError::Other(
            "increment not supported by this backend".into(),
        ))
    }

    /// Returns the remaining time-to-live of `key`, or `None` if the key does not exist.
    async fn ttl(&self, _key: &str) -> Result<Option<Duration>, CacheError> {
        Err(CacheError::Other(
            "ttl not supported by this backend".into(),
        ))
    }

    /// Fetch multiple keys in one round trip. Missing keys yield `None`.
    async fn multi_get(&self, _keys: &[&str]) -> Result<Vec<Option<Vec<u8>>>, CacheError> {
        Err(CacheError::Other(
            "multi_get not supported by this backend".into(),
        ))
    }
}

#[derive(Debug, thiserror::Error)]
pub enum CacheError {
    #[error("cache error: {0}")]
    Other(String),
}

#[cfg(test)]
mod tests {
    use super::*;

    /// 只实现核心三操作的后端：可选操作必须走默认实现的报错路径。
    struct MinimalCache;

    #[async_trait]
    impl Cache for MinimalCache {
        async fn get(&self, _key: &str) -> Result<Option<Vec<u8>>, CacheError> {
            Ok(None)
        }
        async fn set(&self, _key: &str, _value: &[u8], _ttl: Duration) -> Result<(), CacheError> {
            Ok(())
        }
        async fn delete(&self, _key: &str) -> Result<(), CacheError> {
            Ok(())
        }
    }

    #[tokio::test]
    async fn optional_ops_default_to_not_supported_error() {
        let cache = MinimalCache;
        let err = cache.increment("k", 1).await.unwrap_err();
        assert!(err.to_string().contains("increment not supported"), "got: {err}");
        let err = cache.ttl("k").await.unwrap_err();
        assert!(err.to_string().contains("ttl not supported"), "got: {err}");
        let err = cache.multi_get(&["a", "b"]).await.unwrap_err();
        assert!(err.to_string().contains("multi_get not supported"), "got: {err}");
    }
}
