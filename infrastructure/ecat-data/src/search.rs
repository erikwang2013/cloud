// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;

#[async_trait]
pub trait SearchClient: Send + Sync {
    async fn index(
        &self,
        index: &str,
        id: &str,
        doc: &serde_json::Value,
    ) -> Result<(), SearchError>;
    async fn search(
        &self,
        index: &str,
        query: &serde_json::Value,
    ) -> Result<serde_json::Value, SearchError>;
    async fn delete(&self, index: &str, id: &str) -> Result<(), SearchError>;

    /// Bulk index documents as `(id, doc)` pairs in one round trip.
    /// Backends that cannot bulk-index return an error.
    async fn bulk_index(
        &self,
        _index: &str,
        _docs: &[(String, serde_json::Value)],
    ) -> Result<(), SearchError> {
        Err(SearchError::Other(
            "bulk_index not supported by this backend".into(),
        ))
    }

    /// Update an existing document, replacing it with `doc`.
    async fn update(
        &self,
        _index: &str,
        _id: &str,
        _doc: &serde_json::Value,
    ) -> Result<(), SearchError> {
        Err(SearchError::Other(
            "update not supported by this backend".into(),
        ))
    }
}

#[derive(Debug, thiserror::Error)]
pub enum SearchError {
    #[error("search error: {0}")]
    Other(String),
}
