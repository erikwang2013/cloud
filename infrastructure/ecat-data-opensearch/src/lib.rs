// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! OpenSearch client.
//!
//! All writes/reads/errors are validated against the HTTP status code; index
//! names and document ids are percent-encoded before being placed in the URL
//! path so that reserved characters cannot break the request.

use async_trait::async_trait;
use ecat_data::{SearchClient, SearchError};
use ecat_tls::TlsClientConfig;
use serde::Deserialize;

#[derive(Debug, Clone, Deserialize)]
pub struct OpenSearchConfig {
    pub base_url: String,
    #[serde(default)]
    pub username: Option<String>,
    #[serde(default)]
    pub password: Option<String>,
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
}

pub struct OpenSearchClient {
    client: reqwest::Client,
    base_url: String,
    username: Option<String>,
    password: Option<String>,
}

impl OpenSearchClient {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.into(),
            username: None,
            password: None,
        }
    }

    pub fn with_auth(
        base_url: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
    ) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.into(),
            username: Some(username.into()),
            password: Some(password.into()),
        }
    }

    pub fn from_config(cfg: OpenSearchConfig) -> Result<Self, SearchError> {
        let client = ecat_tls::build_reqwest_client(&cfg.tls)
            .map_err(|e| SearchError::Other(format!("TLS: {e}")))?;
        Ok(Self {
            client,
            base_url: cfg.base_url,
            username: cfg.username,
            password: cfg.password,
        })
    }

    fn apply_auth(&self, req: reqwest::RequestBuilder) -> reqwest::RequestBuilder {
        ecat_tls::apply_basic_auth(req, &self.username, &self.password)
    }
}

/// Percent-encode a single URL path segment (RFC 3986): every byte except
/// unreserved characters (`A-Z a-z 0-9 - _ . ~`) becomes `%XX`.
fn percent_encode_segment(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for &b in s.as_bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(b as char);
            }
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// Build the non-2xx error message, including the HTTP status code.
async fn status_error(prefix: &str, resp: reqwest::Response) -> SearchError {
    let status = resp.status().as_u16();
    SearchError::Other(format!(
        "{prefix} failed: status {status}, body: {}",
        resp.text().await.unwrap_or_default()
    ))
}

#[async_trait]
impl SearchClient for OpenSearchClient {
    async fn index(
        &self,
        index: &str,
        id: &str,
        doc: &serde_json::Value,
    ) -> Result<(), SearchError> {
        let req = self
            .client
            .put(format!(
                "{}/{}/_doc/{}",
                self.base_url,
                percent_encode_segment(index),
                percent_encode_segment(id)
            ))
            .json(doc);
        let resp = self
            .apply_auth(req)
            .send()
            .await
            .map_err(|e| SearchError::Other(format!("index: {e}")))?;
        if !resp.status().is_success() {
            return Err(status_error("index", resp).await);
        }
        Ok(())
    }

    async fn search(
        &self,
        index: &str,
        query: &serde_json::Value,
    ) -> Result<serde_json::Value, SearchError> {
        let req = self
            .client
            .post(format!(
                "{}/{}/_search",
                self.base_url,
                percent_encode_segment(index)
            ))
            .json(query);
        let resp = self
            .apply_auth(req)
            .send()
            .await
            .map_err(|e| SearchError::Other(format!("search: {e}")))?;
        if !resp.status().is_success() {
            return Err(status_error("search", resp).await);
        }
        resp.json()
            .await
            .map_err(|e| SearchError::Other(format!("parse: {e}")))
    }

    async fn delete(&self, index: &str, id: &str) -> Result<(), SearchError> {
        let req = self.client.delete(format!(
            "{}/{}/_doc/{}",
            self.base_url,
            percent_encode_segment(index),
            percent_encode_segment(id)
        ));
        let resp = self
            .apply_auth(req)
            .send()
            .await
            .map_err(|e| SearchError::Other(format!("delete: {e}")))?;
        if !resp.status().is_success() {
            return Err(status_error("delete", resp).await);
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn client_constructs() {
        let _client = OpenSearchClient::new("http://localhost:9200");
    }

    #[test]
    fn client_with_auth() {
        let _client = OpenSearchClient::with_auth("http://localhost:9200", "admin", "secret");
    }

    #[test]
    fn config_with_optional_auth() {
        let cfg: OpenSearchConfig = serde_json::from_str(
            r#"{"base_url":"http://localhost:9200","username":"admin","password":"secret"}"#,
        )
        .unwrap();
        let client = OpenSearchClient::from_config(cfg).unwrap();
        assert!(client.username.is_some());
    }

    #[test]
    fn percent_encode_segment_encodes_reserved_chars() {
        assert_eq!(percent_encode_segment("logs-2026"), "logs-2026");
        assert_eq!(
            percent_encode_segment("a/b c#d?e%f"),
            "a%2Fb%20c%23d%3Fe%25f"
        );
    }
}
