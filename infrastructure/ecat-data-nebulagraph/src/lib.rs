// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use ecat_data::{GraphClient, GraphError};
use ecat_tls::TlsClientConfig;
use serde::Deserialize;

#[derive(Debug, Clone, Deserialize)]
pub struct NebulaGraphConfig {
    pub base_url: String,
    pub space: String,
    #[serde(default)]
    pub username: Option<String>,
    #[serde(default)]
    pub password: Option<String>,
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
}

pub struct NebulaGraphClient {
    client: reqwest::Client,
    base_url: String,
    space: String,
    username: Option<String>,
    password: Option<String>,
}

impl NebulaGraphClient {
    pub fn new(base_url: impl Into<String>, space: impl Into<String>) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.into(),
            space: space.into(),
            username: None,
            password: None,
        }
    }

    pub fn with_auth(
        base_url: impl Into<String>,
        space: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
    ) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.into(),
            space: space.into(),
            username: Some(username.into()),
            password: Some(password.into()),
        }
    }

    pub fn from_config(cfg: NebulaGraphConfig) -> Result<Self, GraphError> {
        let client = ecat_tls::build_reqwest_client(&cfg.tls)
            .map_err(|e| GraphError::Other(format!("TLS: {e}")))?;
        Ok(Self {
            client,
            base_url: cfg.base_url,
            space: cfg.space,
            username: cfg.username,
            password: cfg.password,
        })
    }

    fn apply_auth(&self, req: reqwest::RequestBuilder) -> reqwest::RequestBuilder {
        ecat_tls::apply_basic_auth(req, &self.username, &self.password)
    }
}

#[async_trait]
impl GraphClient for NebulaGraphClient {
    async fn execute(
        &self,
        ngql: &str,
        params: &serde_json::Value,
    ) -> Result<serde_json::Value, GraphError> {
        if !params.is_null() {
            return Err(GraphError::Other(
                "params not supported".to_string(),
            ));
        }
        let req = self
            .client
            .post(format!("{}/api/ngql/execute", self.base_url))
            .json(&serde_json::json!({"gql": ngql, "space": self.space}));
        let resp = self
            .apply_auth(req)
            .send()
            .await
            .map_err(|e| GraphError::Other(format!("nebula: {e}")))?;
        if !resp.status().is_success() {
            return Err(GraphError::Other(resp.text().await.unwrap_or_default()));
        }
        resp.json()
            .await
            .map_err(|e| GraphError::Other(format!("nebula parse: {e}")))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn client_constructs() {
        let _client = NebulaGraphClient::new("http://localhost:19669", "test_space");
    }

    #[test]
    fn config_with_optional_auth() {
        let cfg: NebulaGraphConfig = serde_json::from_str(
            r#"{"base_url":"http://localhost:19669","space":"test","username":"root","password":"nebula"}"#
        ).unwrap();
        let client = NebulaGraphClient::from_config(cfg).unwrap();
        assert!(client.username.is_some());
    }

    #[tokio::test]
    async fn execute_rejects_params() {
        let client = NebulaGraphClient::new("http://localhost:19669", "test_space");
        let err = client
            .execute("SHOW SPACES", &serde_json::json!({"limit": 5}))
            .await
            .unwrap_err();
        assert!(err.to_string().contains("params not supported"));
    }
}
