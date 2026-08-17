// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! InfluxDB 2.x client (line protocol writer + Flux query).
//!
//! Measurements, tag keys/values, field keys and string field values are
//! escaped per the InfluxDB line protocol so that `,`, ` `, `=` and `"` in
//! user data cannot break or inject lines.

use async_trait::async_trait;
use ecat_data::{DataPoint, FieldValue, TsdbClient, TsdbError};
use ecat_tls::TlsClientConfig;
use serde::Deserialize;

#[derive(Debug, Clone, Deserialize)]
pub struct InfluxConfig {
    pub base_url: String,
    pub org: String,
    pub bucket: String,
    pub token: String,
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
}

pub struct InfluxClient {
    client: reqwest::Client,
    write_url: String,
    query_url: String,
    org: String,
    bucket: String,
    token: String,
}

impl InfluxClient {
    pub fn new(
        base_url: impl Into<String>,
        org: impl Into<String>,
        bucket: impl Into<String>,
        token: impl Into<String>,
    ) -> Self {
        let base = base_url.into();
        Self {
            write_url: format!("{base}/api/v2/write"),
            query_url: format!("{base}/api/v2/query"),
            org: org.into(),
            bucket: bucket.into(),
            token: token.into(),
            client: reqwest::Client::new(),
        }
    }

    pub fn from_config(cfg: InfluxConfig) -> Result<Self, TsdbError> {
        let base = cfg.base_url.clone();
        let client = ecat_tls::build_reqwest_client(&cfg.tls)
            .map_err(|e| TsdbError::Other(format!("TLS: {e}")))?;
        Ok(Self {
            write_url: format!("{base}/api/v2/write"),
            query_url: format!("{base}/api/v2/query"),
            org: cfg.org,
            bucket: cfg.bucket,
            token: cfg.token,
            client,
        })
    }
}

/// Escape a measurement, tag key/value or field key per InfluxDB line
/// protocol: backslash, comma, space and `=` must be escaped in these
/// unquoted parts of a line.
fn escape_line_part(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '\\' | ',' | ' ' | '=' => {
                out.push('\\');
                out.push(c);
            }
            _ => out.push(c),
        }
    }
    out
}

/// Escape a string field value per InfluxDB line protocol: backslash and
/// double quote are required by the spec; comma and space are escaped too so
/// that user data cannot break or inject lines (the parser consumes the
/// escape, so values are preserved verbatim).
fn escape_field_string(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '\\' | '"' | ',' | ' ' => {
                out.push('\\');
                out.push(c);
            }
            _ => out.push(c),
        }
    }
    out
}

#[async_trait]
impl TsdbClient for InfluxClient {
    async fn write(&self, points: &[DataPoint]) -> Result<(), TsdbError> {
        let mut lines = String::new();
        for p in points {
            let tags: String = if p.tags.is_empty() {
                String::new()
            } else {
                p.tags
                    .iter()
                    .map(|(k, v)| format!(",{}={}", escape_line_part(k), escape_line_part(v)))
                    .collect::<Vec<_>>()
                    .join("")
            };
            let fields: String = p
                .fields
                .iter()
                .map(|(k, v)| {
                    let k = escape_line_part(k);
                    match v {
                        FieldValue::Float(f) => format!("{k}={f}"),
                        FieldValue::Int(i) => format!("{k}={i}i"),
                        FieldValue::String(s) => format!("{k}=\"{}\"", escape_field_string(s)),
                        FieldValue::Bool(b) => format!("{k}={b}"),
                    }
                })
                .collect::<Vec<_>>()
                .join(",");
            lines.push_str(&format!(
                "{}{tags} {fields}",
                escape_line_part(&p.measurement)
            ));
            if let Some(ts) = p.timestamp {
                lines.push_str(&format!(" {ts}"));
            }
            lines.push('\n');
        }

        let resp = self
            .client
            .post(&self.write_url)
            .header("Authorization", format!("Token {}", self.token))
            .header("Content-Type", "text/plain; charset=utf-8")
            .query(&[
                ("org", &self.org),
                ("bucket", &self.bucket),
                ("precision", &"ns".to_string()),
            ])
            .body(lines)
            .send()
            .await
            .map_err(|e| TsdbError::Other(format!("write: {e}")))?;

        if !resp.status().is_success() {
            return Err(TsdbError::Other(format!(
                "write failed: {}",
                resp.text().await.unwrap_or_default()
            )));
        }
        Ok(())
    }

    async fn query(&self, query: &str) -> Result<serde_json::Value, TsdbError> {
        let resp = self
            .client
            .post(&self.query_url)
            .header("Authorization", format!("Token {}", self.token))
            .header("Content-Type", "application/vnd.flux")
            .query(&[("org", &self.org)])
            .body(query.to_string())
            .send()
            .await
            .map_err(|e| TsdbError::Other(format!("query: {e}")))?;
        if !resp.status().is_success() {
            return Err(TsdbError::Other(format!(
                "query failed: {}",
                resp.text().await.unwrap_or_default()
            )));
        }
        resp.json()
            .await
            .map_err(|e| TsdbError::Other(format!("parse: {e}")))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn client_constructs() {
        let _client = InfluxClient::new("http://localhost:8086", "myorg", "mybucket", "mytoken");
    }

    #[test]
    fn data_point_builder() {
        let p = DataPoint::new("cpu")
            .with_tag("host", "server01")
            .with_field("usage", FieldValue::Float(0.85))
            .with_timestamp(1625097600000000000);
        assert_eq!(p.measurement, "cpu");
        assert_eq!(p.tags.get("host").unwrap(), "server01");
    }

    #[test]
    fn escapes_line_parts() {
        assert_eq!(escape_line_part("a,b c=d\\e"), "a\\,b\\ c\\=d\\\\e");
        assert_eq!(escape_line_part("plain"), "plain");
    }

    #[test]
    fn escapes_field_strings() {
        assert_eq!(escape_field_string("say \"hi\""), "say\\ \\\"hi\\\"");
        assert_eq!(escape_field_string("a\\b"), "a\\\\b");
        assert_eq!(escape_field_string("x y,z"), "x\\ y\\,z");
    }

    /// mock InfluxDB 的 /api/v2/query 端点，返回给定状态码与错误体。
    async fn spawn_mock_query(status: u16, body: &'static str) -> String {
        let app = axum::Router::new().route(
            "/api/v2/query",
            axum::routing::post(move || async move {
                (
                    axum::http::StatusCode::from_u16(status).unwrap(),
                    axum::response::Response::new(axum::body::Body::from(body)),
                )
            }),
        );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });
        format!("http://{addr}")
    }

    #[tokio::test]
    async fn query_returns_err_on_http_400() {
        let base_url = spawn_mock_query(400, r#"{"error":"invalid flux"}"#).await;
        let client = InfluxClient::new(base_url, "org", "bucket", "token");
        let err = client.query("from(bucket: \"x\")").await.unwrap_err();
        assert!(err.to_string().contains("invalid flux"));
    }
}
