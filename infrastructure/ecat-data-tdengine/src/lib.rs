// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use ecat_data::{DataPoint, FieldValue, TsdbClient, TsdbError};
use ecat_tls::TlsClientConfig;
use serde::Deserialize;

#[derive(Debug, Clone, Deserialize)]
pub struct TdengineConfig {
    pub base_url: String,
    pub username: String,
    pub password: String,
    #[serde(default)]
    pub database: Option<String>,
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
}

pub struct TdengineClient {
    client: reqwest::Client,
    base_url: String,
    username: String,
    password: String,
    database: Option<String>,
}

impl TdengineClient {
    pub fn new(
        base_url: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
    ) -> Self {
        Self {
            client: reqwest::Client::new(),
            base_url: base_url.into(),
            username: username.into(),
            password: password.into(),
            database: None,
        }
    }

    pub fn from_config(cfg: TdengineConfig) -> Result<Self, TsdbError> {
        let client = ecat_tls::build_reqwest_client(&cfg.tls)
            .map_err(|e| TsdbError::Other(format!("TLS: {e}")))?;
        Ok(Self {
            client,
            base_url: cfg.base_url,
            username: cfg.username,
            password: cfg.password,
            database: cfg.database,
        })
    }

    fn sql_url(&self) -> String {
        match &self.database {
            Some(db) => format!("{}/rest/sql/{}", self.base_url, percent_encode_segment(db)),
            None => format!("{}/rest/sql", self.base_url),
        }
    }

    async fn exec(&self, sql: &str) -> Result<serde_json::Value, TsdbError> {
        let resp = self
            .client
            .post(self.sql_url())
            .basic_auth(&self.username, Some(&self.password))
            .json(&serde_json::json!({ "sql": sql }))
            .send()
            .await
            .map_err(|e| TsdbError::Other(format!("tdengine exec: {e}")))?;
        if !resp.status().is_success() {
            return Err(TsdbError::Other(resp.text().await.unwrap_or_default()));
        }
        resp.json()
            .await
            .map_err(|e| TsdbError::Other(format!("tdengine parse: {e}")))
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

/// 转义双引号字符串字面量：先转义反斜杠再转义双引号，防止注入逃逸
fn escape_sql_string(s: &str) -> String {
    s.replace('\\', "\\\\").replace('"', "\\\"")
}

/// 转义双引号包裹的标识符（measurement/列名）
fn escape_ident(s: &str) -> String {
    s.replace('\\', "\\\\").replace('"', "\\\"")
}

/// 单条 DataPoint 生成一条 INSERT 语句
fn point_to_insert(p: &DataPoint) -> String {
    // Tags are flattened as columns; measurement is the table name.
    let mut cols = Vec::new();
    let mut vals = Vec::new();
    cols.push("ts".to_string());
    vals.push(
        p.timestamp
            .map(|ts| ts.to_string())
            .unwrap_or_else(|| "now".to_string()),
    );
    for (k, v) in &p.tags {
        cols.push(format!("\"{}\"", escape_ident(k)));
        vals.push(format!("\"{}\"", escape_sql_string(v)));
    }
    for (k, v) in &p.fields {
        cols.push(format!("\"{}\"", escape_ident(k)));
        vals.push(match v {
            FieldValue::Float(f) => f.to_string(),
            FieldValue::Int(i) => i.to_string(),
            FieldValue::String(s) => format!("\"{}\"", escape_sql_string(s)),
            FieldValue::Bool(b) => {
                if *b {
                    "true".to_string()
                } else {
                    "false".to_string()
                }
            }
        });
    }
    format!(
        "INSERT INTO \"{}\" ({}) VALUES ({})",
        escape_ident(&p.measurement),
        cols.join(", "),
        vals.join(", ")
    )
}

/// 每批最多写入的语句数，TDengine REST 支持换行分隔的多语句
const BATCH_SIZE: usize = 100;

#[async_trait]
impl TsdbClient for TdengineClient {
    async fn write(&self, points: &[DataPoint]) -> Result<(), TsdbError> {
        for chunk in points.chunks(BATCH_SIZE) {
            let sql = chunk
                .iter()
                .map(point_to_insert)
                .collect::<Vec<_>>()
                .join("\n");
            self.exec(&sql).await?;
        }
        Ok(())
    }

    async fn query(&self, sql: &str) -> Result<serde_json::Value, TsdbError> {
        self.exec(sql).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn config_deserializes() {
        let cfg: TdengineConfig = serde_json::from_value(serde_json::json!({
            "base_url": "http://localhost:6041",
            "username": "root",
            "password": "taosdata",
            "database": "demo",
        }))
        .unwrap();
        assert_eq!(cfg.database.as_deref(), Some("demo"));
        assert!(cfg.tls.is_none());
    }

    #[test]
    fn client_constructs() {
        let _client = TdengineClient::new("http://localhost:6041", "root", "taosdata");
    }

    #[test]
    fn sql_url_encodes_database_segment() {
        let mut client = TdengineClient::new("http://localhost:6041", "root", "taosdata");
        client.database = Some("my db/1".into());
        assert_eq!(
            client.sql_url(),
            "http://localhost:6041/rest/sql/my%20db%2F1"
        );
    }
}
