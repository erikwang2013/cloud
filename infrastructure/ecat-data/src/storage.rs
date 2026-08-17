// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;

/// Object-storage client abstraction (e.g. S3, MinIO).
#[async_trait]
pub trait StorageClient: Send + Sync {
    /// Upload `data` under `key` in `bucket`.
    async fn put(&self, bucket: &str, key: &str, data: &[u8]) -> Result<(), StorageError>;

    /// Download the object at `key` in `bucket`.
    async fn get(&self, bucket: &str, key: &str) -> Result<Vec<u8>, StorageError>;

    /// Delete the object at `key` in `bucket`.
    async fn delete(&self, bucket: &str, key: &str) -> Result<(), StorageError>;

    /// List object keys in `bucket` under `prefix`.
    async fn list(&self, bucket: &str, prefix: &str) -> Result<Vec<String>, StorageError>;
}

#[derive(Debug, thiserror::Error)]
pub enum StorageError {
    #[error("storage error: {0}")]
    Other(String),
}
