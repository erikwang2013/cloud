// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use base64::Engine as _;
use ecat_data::{RdbmsClient, RdbmsError, Row, TransactionInner};
use ecat_tls::TlsClientConfig;
use serde::Deserialize;
use sqlx::any::AnyRow;
use sqlx::{AnyPool, Column as SqlxColumn, Row as SqlxRow};

#[derive(Debug, Clone, Deserialize)]
pub struct SqlxConfig {
    pub url: String,
    #[serde(default)]
    pub username: Option<String>,
    #[serde(default)]
    pub password: Option<String>,
    /// TLS — SQLx TLS is configured via URL params (e.g. ?sslmode=require).
    /// This field is reserved for future programmatic TLS support.
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
}

fn percent_encode(s: &str) -> String {
    s.chars()
        .map(|c| match c {
            ':' | '/' | '@' | '#' | '?' | '&' | '=' | '%' | '+' | ' ' => {
                format!("%{:02X}", c as u8)
            }
            _ => c.to_string(),
        })
        .collect()
}

pub struct SqlxClient {
    pool: AnyPool,
}

impl SqlxClient {
    pub async fn connect(url: &str) -> Result<Self, sqlx::Error> {
        // sqlx 0.8 的 AnyPool 运行期要求先注册驱动，否则直接 panic "No drivers installed"
        let _ = sqlx::any::install_default_drivers();
        let pool = AnyPool::connect(url).await?;
        Ok(Self { pool })
    }

    pub async fn connect_with_auth(
        url: &str,
        username: &str,
        password: &str,
    ) -> Result<Self, sqlx::Error> {
        let url = if url.contains('@') {
            url.to_string()
        } else {
            let encoded_user = percent_encode(username);
            let encoded_pass = percent_encode(password);
            url.replacen("://", &format!("://{encoded_user}:{encoded_pass}@"), 1)
        };
        Self::connect(&url).await
    }

    pub async fn from_config(cfg: SqlxConfig) -> Result<Self, sqlx::Error> {
        match (&cfg.username, &cfg.password) {
            (Some(u), Some(p)) if !u.is_empty() || !p.is_empty() => {
                Self::connect_with_auth(&cfg.url, u, p).await
            }
            _ => Self::connect(&cfg.url).await,
        }
    }

    pub fn from_pool(pool: AnyPool) -> Self {
        Self { pool }
    }
}

/// 单格类型转换链：bool → i64 → i32 → f64（NaN/Inf 转字符串）→ String →
/// Blob（base64）。此前 Blob/BYTEA 列会静默落到 Null。
/// 注意：sqlx Any 驱动不支持时间类型，timestamp 列会在 fetch 时直接报错
/// （AnyDriverError），不会静默——调用方可自行 CAST 成文本。
fn cell_to_json(row: &AnyRow, col: &str) -> serde_json::Value {
    row.try_get::<bool, _>(col)
        .map(serde_json::Value::Bool)
        .or_else(|_| {
            row.try_get::<i64, _>(col)
                .map(|n| serde_json::Value::Number(n.into()))
        })
        .or_else(|_| {
            row.try_get::<i32, _>(col)
                .map(|n| serde_json::Value::Number((n as i64).into()))
        })
        .or_else(|_| {
            row.try_get::<f64, _>(col)
                .ok()
                .and_then(|n| {
                    if n.is_finite() {
                        serde_json::Number::from_f64(n).map(serde_json::Value::Number)
                    } else if n.is_nan() {
                        Some(serde_json::Value::String("NaN".into()))
                    } else if n > 0.0 {
                        Some(serde_json::Value::String("Infinity".into()))
                    } else {
                        Some(serde_json::Value::String("-Infinity".into()))
                    }
                })
                .ok_or(())
        })
        .or_else(|_| row.try_get::<String, _>(col).map(serde_json::Value::String))
        .or_else(|_| {
            row.try_get::<Vec<u8>, _>(col).map(|b| {
                serde_json::Value::String(base64::engine::general_purpose::STANDARD.encode(b))
            })
        })
        .unwrap_or(serde_json::Value::Null)
}

fn rows_to_result(rows: Vec<AnyRow>) -> Vec<Row> {
    if rows.is_empty() {
        return Vec::new();
    }
    let columns: Vec<String> = rows[0]
        .columns()
        .iter()
        .map(|c| c.name().to_string())
        .collect();
    rows.iter()
        .map(|row| {
            let values: Vec<serde_json::Value> =
                columns.iter().map(|col| cell_to_json(row, col)).collect();
            Row::new(columns.clone(), values)
        })
        .collect()
}

#[async_trait]
impl RdbmsClient for SqlxClient {
    async fn execute(&self, sql: &str) -> Result<u64, RdbmsError> {
        sqlx::query(sql)
            .execute(&self.pool)
            .await
            .map(|r| r.rows_affected())
            .map_err(|e| RdbmsError::Database(e.to_string()))
    }

    async fn query(&self, sql: &str) -> Result<Vec<Row>, RdbmsError> {
        let rows: Vec<AnyRow> = sqlx::query(sql)
            .fetch_all(&self.pool)
            .await
            .map_err(|e| RdbmsError::Database(e.to_string()))?;
        Ok(rows_to_result(rows))
    }

    async fn execute_with(
        &self,
        sql: &str,
        params: &[serde_json::Value],
    ) -> Result<u64, RdbmsError> {
        let mut q = sqlx::query(sql);
        for p in params {
            q = match p {
                serde_json::Value::String(s) => q.bind(s.as_str()),
                serde_json::Value::Number(n) => {
                    if let Some(i) = n.as_i64() {
                        q.bind(i)
                    } else if let Some(f) = n.as_f64() {
                        q.bind(f)
                    } else {
                        q.bind(n.to_string())
                    }
                }
                serde_json::Value::Bool(b) => q.bind(*b),
                serde_json::Value::Null => q.bind(None::<String>),
                _ => q.bind(p.to_string()),
            };
        }
        q.execute(&self.pool)
            .await
            .map(|r| r.rows_affected())
            .map_err(|e| RdbmsError::Database(e.to_string()))
    }

    async fn query_with(
        &self,
        sql: &str,
        params: &[serde_json::Value],
    ) -> Result<Vec<Row>, RdbmsError> {
        let mut q = sqlx::query(sql);
        for p in params {
            q = match p {
                serde_json::Value::String(s) => q.bind(s.as_str()),
                serde_json::Value::Number(n) => {
                    if let Some(i) = n.as_i64() {
                        q.bind(i)
                    } else if let Some(f) = n.as_f64() {
                        q.bind(f)
                    } else {
                        q.bind(n.to_string())
                    }
                }
                serde_json::Value::Bool(b) => q.bind(*b),
                serde_json::Value::Null => q.bind(None::<String>),
                _ => q.bind(p.to_string()),
            };
        }
        let rows: Vec<sqlx::any::AnyRow> = q
            .fetch_all(&self.pool)
            .await
            .map_err(|e| RdbmsError::Database(e.to_string()))?;
        Ok(rows_to_result(rows))
    }

    async fn transaction(&self) -> Result<ecat_data::Transaction, RdbmsError> {
        let tx = self
            .pool
            .begin()
            .await
            .map_err(|e| RdbmsError::Database(e.to_string()))?;
        Ok(ecat_data::Transaction::with_inner(Box::new(
            SqlxTransactionWrapper { inner: Some(tx) },
        )))
    }
}

struct SqlxTransactionWrapper {
    inner: Option<sqlx::Transaction<'static, sqlx::Any>>,
}

#[async_trait]
impl TransactionInner for SqlxTransactionWrapper {
    async fn commit(&mut self) -> Result<(), RdbmsError> {
        if let Some(tx) = self.inner.take() {
            tx.commit()
                .await
                .map_err(|e| RdbmsError::Database(e.to_string()))?;
        }
        Ok(())
    }

    async fn rollback(&mut self) -> Result<(), RdbmsError> {
        if let Some(tx) = self.inner.take() {
            tx.rollback()
                .await
                .map_err(|e| RdbmsError::Database(e.to_string()))?;
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn percent_encode_special_chars() {
        assert_eq!(percent_encode("user:pass"), "user%3Apass");
        assert_eq!(percent_encode("a/b@c"), "a%2Fb%40c");
        assert_eq!(percent_encode("a#b?c&d=e"), "a%23b%3Fc%26d%3De");
        assert_eq!(percent_encode("100%"), "100%25");
        assert_eq!(percent_encode("a+b"), "a%2Bb");
        assert_eq!(percent_encode("hello world"), "hello%20world");
    }

    #[test]
    fn percent_encode_no_special_chars() {
        assert_eq!(percent_encode("simple"), "simple");
        assert_eq!(percent_encode("user123"), "user123");
        assert_eq!(percent_encode(""), "");
    }

    #[test]
    fn config_deserialize_basic() {
        let cfg: SqlxConfig =
            serde_json::from_str(r#"{"url": "postgres://localhost/db"}"#).unwrap();
        assert_eq!(cfg.url, "postgres://localhost/db");
        assert!(cfg.username.is_none());
        assert!(cfg.password.is_none());
        assert!(cfg.tls.is_none());
    }

    #[test]
    fn config_deserialize_with_auth() {
        let cfg: SqlxConfig = serde_json::from_str(
            r#"{"url": "mysql://localhost/db", "username": "root", "password": "secret"}"#,
        )
        .unwrap();
        assert_eq!(cfg.url, "mysql://localhost/db");
        assert_eq!(cfg.username.as_deref(), Some("root"));
        assert_eq!(cfg.password.as_deref(), Some("secret"));
    }

    #[test]
    fn config_deserialize_with_tls() {
        let cfg: SqlxConfig = serde_json::from_str(
            r#"{"url": "postgres://localhost/db", "tls": {"skip_verify": true}}"#,
        )
        .unwrap();
        assert!(cfg.tls.is_some());
        let tls = cfg.tls.unwrap();
        assert_eq!(tls.skip_verify, Some(true));
    }

    #[test]
    fn config_missing_url_is_error() {
        let result: Result<SqlxConfig, _> = serde_json::from_str(r#"{}"#);
        assert!(result.is_err());
    }

    #[test]
    fn from_pool_is_constructible() {
        // Compile-time check: SqlxClient::from_pool exists with correct signature.
        fn _check_sig(pool: sqlx::AnyPool) -> SqlxClient {
            SqlxClient::from_pool(pool)
        }
    }
}
