// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! S3 / MinIO object storage client (reqwest + rustls, AWS SigV4 signing).
//!
//! TLS is handled through the shared [`ecat_tls::TlsClientConfig`] surface
//! (custom CA, mTLS, skip_verify), consistent with the other HTTP data
//! crates. `endpoint` may carry a scheme ("https://..."); otherwise the
//! scheme is derived from whether TLS is enabled in the config.
//!
//! Requests are signed with AWS Signature V4 (path-style addressing) and
//! every response status is checked — non-2xx responses surface the status
//! and body instead of being silently dropped.

use async_trait::async_trait;
use ecat_data::{StorageClient, StorageError};
use ecat_tls::TlsClientConfig;
use hmac::{Hmac, Mac};
use reqwest::header::AUTHORIZATION;
use serde::Deserialize;
use sha2::{Digest, Sha256};
use time::OffsetDateTime;
use time::macros::format_description;

const SERVICE: &str = "s3";
const AMZ_DATE_FMT: &[time::format_description::FormatItem<'_>] =
    format_description!("[year][month][day]T[hour][minute][second]Z");
const DATE_STAMP_FMT: &[time::format_description::FormatItem<'_>] =
    format_description!("[year][month][day]");

type HmacSha256 = Hmac<Sha256>;

#[derive(Debug, Clone, Deserialize)]
pub struct S3Config {
    pub endpoint: String,
    pub region: String,
    pub access_key: String,
    pub secret_key: String,
    #[serde(default)]
    pub tls: Option<TlsClientConfig>,
}

pub struct S3Client {
    client: reqwest::Client,
    endpoint: String,
    host: String,
    region: String,
    access_key: String,
    secret_key: String,
}

impl S3Client {
    pub fn from_config(cfg: S3Config) -> Result<Self, StorageError> {
        let client = ecat_tls::build_reqwest_client(&cfg.tls)
            .map_err(|e| StorageError::Other(format!("s3 tls: {e}")))?;
        let endpoint = if cfg.endpoint.contains("://") {
            cfg.endpoint.clone()
        } else if cfg.tls.as_ref().is_some_and(|t| t.is_enabled()) {
            format!("https://{}", cfg.endpoint)
        } else {
            format!("http://{}", cfg.endpoint)
        };
        let host = endpoint
            .strip_prefix("https://")
            .or_else(|| endpoint.strip_prefix("http://"))
            .unwrap_or(&endpoint)
            .to_string();
        Ok(Self {
            client,
            endpoint,
            host,
            region: cfg.region,
            access_key: cfg.access_key,
            secret_key: cfg.secret_key,
        })
    }

    /// 返回原始（未编码）路径；编码统一在 signed_request 的 URL 构建与
    /// sign 的 canonical URI 处各做一次，避免双重 percent-encoding。
    fn object_path(&self, bucket: &str, key: &str) -> String {
        format!("/{bucket}/{key}")
    }

    fn signed_request(
        &self,
        method: &str,
        path: &str,
        query: &[(&str, &str)],
        payload: &[u8],
    ) -> (String, String, String, String) {
        let now = OffsetDateTime::now_utc();
        let payload_hash = hex(&Sha256::digest(payload));
        let time = SigTime {
            amz_date: now.format(&AMZ_DATE_FMT).expect("amz date format"),
            date_stamp: now.format(&DATE_STAMP_FMT).expect("date stamp format"),
        };
        let auth = sign(
            method,
            &self.host,
            path,
            query,
            &payload_hash,
            &Credentials {
                access_key: &self.access_key,
                secret_key: &self.secret_key,
                region: &self.region,
            },
            &time,
        );
        let q = canonical_query(query);
        let url = if q.is_empty() {
            format!("{}{}", self.endpoint, encode_uri_component(path, true))
        } else {
            format!("{}{}?{q}", self.endpoint, encode_uri_component(path, true))
        };
        (url, auth, time.amz_date, payload_hash)
    }

    async fn check_status(resp: reqwest::Response, op: &str) -> Result<reqwest::Response, StorageError> {
        let status = resp.status();
        if !status.is_success() {
            let body = resp.text().await.unwrap_or_default();
            return Err(StorageError::Other(format!("s3 {op}: HTTP {status}: {body}")));
        }
        Ok(resp)
    }
}

#[async_trait]
impl StorageClient for S3Client {
    async fn put(&self, bucket: &str, key: &str, data: &[u8]) -> Result<(), StorageError> {
        let path = self.object_path(bucket, key);
        let (url, auth, amz_date, payload_hash) = self.signed_request("PUT", &path, &[], data);
        let resp = self
            .client
            .put(url)
            .header(AUTHORIZATION, auth)
            .header("x-amz-date", amz_date)
            .header("x-amz-content-sha256", payload_hash)
            .body(data.to_vec())
            .send()
            .await
            .map_err(|e| StorageError::Other(format!("s3 put: {e}")))?;
        Self::check_status(resp, "put").await?;
        Ok(())
    }

    async fn get(&self, bucket: &str, key: &str) -> Result<Vec<u8>, StorageError> {
        let path = self.object_path(bucket, key);
        let (url, auth, amz_date, payload_hash) = self.signed_request("GET", &path, &[], b"");
        let resp = self
            .client
            .get(url)
            .header(AUTHORIZATION, auth)
            .header("x-amz-date", amz_date)
            .header("x-amz-content-sha256", payload_hash)
            .send()
            .await
            .map_err(|e| StorageError::Other(format!("s3 get: {e}")))?;
        let resp = Self::check_status(resp, "get").await?;
        Ok(resp
            .bytes()
            .await
            .map_err(|e| StorageError::Other(format!("s3 get body: {e}")))?
            .to_vec())
    }

    async fn delete(&self, bucket: &str, key: &str) -> Result<(), StorageError> {
        let path = self.object_path(bucket, key);
        let (url, auth, amz_date, payload_hash) = self.signed_request("DELETE", &path, &[], b"");
        let resp = self
            .client
            .delete(url)
            .header(AUTHORIZATION, auth)
            .header("x-amz-date", amz_date)
            .header("x-amz-content-sha256", payload_hash)
            .send()
            .await
            .map_err(|e| StorageError::Other(format!("s3 delete: {e}")))?;
        Self::check_status(resp, "delete").await?;
        Ok(())
    }

    /// List object keys under `prefix`, following continuation tokens across
    /// pages (same behavior as the previous rust-s3 backend).
    async fn list(&self, bucket: &str, prefix: &str) -> Result<Vec<String>, StorageError> {
        let path = format!("/{bucket}");
        let mut keys = Vec::new();
        let mut token: Option<String> = None;
        loop {
            let mut query: Vec<(&str, &str)> = vec![("list-type", "2"), ("prefix", prefix)];
            let token_owned;
            if let Some(t) = &token {
                token_owned = t.clone();
                query.push(("continuation-token", &token_owned));
            }
            let (url, auth, amz_date, payload_hash) = self.signed_request("GET", &path, &query, b"");
            let resp = self
                .client
                .get(url)
                .header(AUTHORIZATION, auth)
                .header("x-amz-date", amz_date)
                .header("x-amz-content-sha256", payload_hash)
                .send()
                .await
                .map_err(|e| StorageError::Other(format!("s3 list: {e}")))?;
            let resp = Self::check_status(resp, "list").await?;
            let body = resp
                .text()
                .await
                .map_err(|e| StorageError::Other(format!("s3 list body: {e}")))?;
            let (page_keys, next) = parse_list_xml(&body);
            keys.extend(page_keys);
            match next {
                Some(t) => token = Some(t),
                None => break,
            }
        }
        Ok(keys)
    }
}

/// RFC 3986 percent-encoding of a path/query segment. Slashes are kept when
/// `keep_slash` is set (S3 keys use "/" as a path separator), and are encoded
/// to %2F otherwise (query values).
fn encode_uri_component(s: &str, keep_slash: bool) -> String {
    let mut out = String::with_capacity(s.len());
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(char::from(b))
            }
            b'/' if keep_slash => out.push('/'),
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

fn hex(data: &[u8]) -> String {
    data.iter().map(|b| format!("{b:02x}")).collect()
}

fn hmac_sha256(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = <HmacSha256 as Mac>::new_from_slice(key).expect("hmac accepts any key length");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn canonical_query(query: &[(&str, &str)]) -> String {
    let mut q: Vec<(String, String)> = query
        .iter()
        .map(|(k, v)| (encode_uri_component(k, false), encode_uri_component(v, false)))
        .collect();
    q.sort();
    q.iter()
        .map(|(k, v)| format!("{k}={v}"))
        .collect::<Vec<_>>()
        .join("&")
}

struct Credentials<'a> {
    access_key: &'a str,
    secret_key: &'a str,
    region: &'a str,
}

struct SigTime {
    amz_date: String,
    date_stamp: String,
}

/// AWS Signature V4 (SigV4) — returns the `Authorization` header value.
/// `payload_hash` is the hex SHA-256 of the payload; the caller must send the
/// same value in the `x-amz-content-sha256` request header.
fn sign(
    method: &str,
    host: &str,
    path: &str,
    query: &[(&str, &str)],
    payload_hash: &str,
    creds: &Credentials<'_>,
    time: &SigTime,
) -> String {
    let mut headers: Vec<(String, String)> = [
        ("host", host.to_string()),
        ("x-amz-content-sha256", payload_hash.to_string()),
        ("x-amz-date", time.amz_date.clone()),
    ]
    .into_iter()
    .map(|(k, v)| (k.to_string(), v))
    .collect();
    headers.sort_by(|a, b| a.0.cmp(&b.0));
    let signed_headers: Vec<String> = headers.iter().map(|(k, _)| k.clone()).collect();
    let canonical_headers = headers
        .iter()
        .map(|(k, v)| format!("{k}:{v}\n"))
        .collect::<String>();
    let canonical_request = format!(
        "{method}\n{}\n{}\n{canonical_headers}\n{}\n{payload_hash}",
        encode_uri_component(path, true),
        canonical_query(query),
        signed_headers.join(";"),
    );
    let scope = format!("{}/{}/{SERVICE}/aws4_request", time.date_stamp, creds.region);
    let string_to_sign = format!(
        "AWS4-HMAC-SHA256\n{}\n{scope}\n{}",
        time.amz_date,
        hex(&Sha256::digest(canonical_request.as_bytes())),
    );
    let k_date = hmac_sha256(
        format!("AWS4{}", creds.secret_key).as_bytes(),
        time.date_stamp.as_bytes(),
    );
    let k_region = hmac_sha256(&k_date, creds.region.as_bytes());
    let k_service = hmac_sha256(&k_region, SERVICE.as_bytes());
    let k_signing = hmac_sha256(&k_service, b"aws4_request");
    let signature = hex(&hmac_sha256(&k_signing, string_to_sign.as_bytes()));
    format!(
        "AWS4-HMAC-SHA256 Credential={}/{scope}, SignedHeaders={}, Signature={signature}",
        creds.access_key,
        signed_headers.join(";"),
    )
}

fn parse_list_xml(xml: &str) -> (Vec<String>, Option<String>) {
    use quick_xml::events::Event;
    let mut reader = quick_xml::Reader::from_str(xml);
    reader.config_mut().trim_text(true);
    let mut keys = Vec::new();
    let mut token = None;
    let mut in_key = false;
    let mut in_token = false;
    let mut text = String::new();
    let mut buf = Vec::new();
    loop {
        match reader.read_event_into(&mut buf) {
            Ok(Event::Start(e)) if e.name().as_ref() == b"Key" => in_key = true,
            Ok(Event::Start(e)) if e.name().as_ref() == b"NextContinuationToken" => {
                in_token = true
            }
            Ok(Event::Text(t)) if in_key || in_token => {
                // quick-xml >= 0.37 会在实体引用处拆分文本，需追加而非覆盖
                text.push_str(&t.xml10_content().unwrap_or_default());
            }
            Ok(Event::GeneralRef(r)) if in_key || in_token => {
                // 实体引用以独立事件给出（无 &...; 定界符），按 XML 1.0 预定义实体还原
                match r.as_ref() {
                    b"amp" => text.push('&'),
                    b"lt" => text.push('<'),
                    b"gt" => text.push('>'),
                    b"quot" => text.push('"'),
                    b"apos" => text.push('\''),
                    [b'#', rest @ ..] => {
                        let (radix, digits) = match rest {
                            [b'x', hex @ ..] => (16, hex),
                            dec => (10, dec),
                        };
                        if let Ok(n) =
                            u32::from_str_radix(std::str::from_utf8(digits).unwrap_or(""), radix)
                            && let Some(c) = char::from_u32(n)
                        {
                            text.push(c);
                        }
                    }
                    _ => {}
                }
            }
            Ok(Event::End(e)) if e.name().as_ref() == b"Key" => {
                keys.push(std::mem::take(&mut text));
                in_key = false;
            }
            Ok(Event::End(e)) if e.name().as_ref() == b"NextContinuationToken" => {
                token = Some(std::mem::take(&mut text));
                in_token = false;
            }
            Ok(Event::Eof) => break,
            Err(_) => break,
            _ => {}
        }
        buf.clear();
    }
    (keys, token)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::{Read, Write};

    const AK: &str = "AKIAIOSFODNN7EXAMPLE";
    const SK: &str = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY";

    // 期望值由独立的 Python SigV4 参考实现生成，该实现先通过 AWS 官方
    // GetObject 示例（Signature f0e8bdb8...）自检。
    fn auth_for(method: &str, path: &str, query: &[(&str, &str)], payload: &[u8]) -> String {
        sign(
            method,
            "localhost:9000",
            path,
            query,
            &hex(&Sha256::digest(payload)),
            &Credentials {
                access_key: AK,
                secret_key: SK,
                region: "us-east-1",
            },
            &SigTime {
                amz_date: "20130524T000000Z".into(),
                date_stamp: "20130524".into(),
            },
        )
    }

    #[test]
    fn sigv4_put_matches_reference_vector() {
        assert_eq!(
            auth_for("PUT", "/bucket/test.txt", &[], b"Welcome to Amazon S3."),
            "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, \
             SignedHeaders=host;x-amz-content-sha256;x-amz-date, \
             Signature=57c36bcbd9ad566ed192c7a24f438759ba5c9f10563fcdb9011bdcf7de314bd6"
        );
    }

    #[test]
    fn sigv4_get_matches_reference_vector() {
        assert_eq!(
            auth_for("GET", "/bucket/test.txt", &[], b""),
            "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, \
             SignedHeaders=host;x-amz-content-sha256;x-amz-date, \
             Signature=0170e04fbd0f581a7b0073ce899f93dfe5161eaa474c84e004a1ce7d5d7ed4f9"
        );
    }

    #[test]
    fn sigv4_delete_matches_reference_vector() {
        assert_eq!(
            auth_for("DELETE", "/bucket/test.txt", &[], b""),
            "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, \
             SignedHeaders=host;x-amz-content-sha256;x-amz-date, \
             Signature=4335c7bab27a372c2424c4b5792612d54e0b6599e1ef48fa84250001211ab476"
        );
    }

    #[test]
    fn sigv4_list_signs_canonical_query() {
        assert_eq!(
            auth_for("GET", "/bucket", &[("list-type", "2"), ("prefix", "logs/2026")], b""),
            "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, \
             SignedHeaders=host;x-amz-content-sha256;x-amz-date, \
             Signature=a6981ac88c050c98ca9ff5f26b690394c6f71ed9888c34e2d3bcb988862ef6d8"
        );
    }

    #[test]
    fn sigv4_signs_percent_encoded_key_path() {
        assert_eq!(
            auth_for("PUT", "/bucket/a b#c?d%e.txt", &[], b"data"),
            "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, \
             SignedHeaders=host;x-amz-content-sha256;x-amz-date, \
             Signature=a2fd63590a7e9036b1384bcdf0034d8f714b02c92403642bfea60ba21adb64f9"
        );
    }

    #[test]
    fn encode_uri_component_encodes_reserved_chars() {
        assert_eq!(encode_uri_component("logs-2026", false), "logs-2026");
        assert_eq!(
            encode_uri_component("a/b c#d?e%f", true),
            "a/b%20c%23d%3Fe%25f"
        );
        assert_eq!(
            encode_uri_component("a/b c#d?e%f", false),
            "a%2Fb%20c%23d%3Fe%25f"
        );
        assert_eq!(encode_uri_component("你好", false), "%E4%BD%A0%E5%A5%BD");
    }

    #[test]
    fn list_xml_parses_keys_and_continuation_token() {
        let xml = r#"<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
  <IsTruncated>true</IsTruncated>
  <Contents><Key>logs/2026/01.log</Key></Contents>
  <Contents><Key>logs/2026/02&amp;special.log</Key></Contents>
  <NextContinuationToken>tok==</NextContinuationToken>
</ListBucketResult>"#;
        let (keys, token) = parse_list_xml(xml);
        assert_eq!(keys, vec!["logs/2026/01.log", "logs/2026/02&special.log"]);
        assert_eq!(token.as_deref(), Some("tok=="));
    }

    #[test]
    fn list_xml_without_token_ends_paging() {
        let xml = "<ListBucketResult><Contents><Key>a</Key></Contents></ListBucketResult>";
        let (keys, token) = parse_list_xml(xml);
        assert_eq!(keys, vec!["a"]);
        assert!(token.is_none());
    }

    #[test]
    fn config_deserializes_with_tls() {
        let cfg: S3Config = serde_json::from_value(serde_json::json!({
            "endpoint": "localhost:9000",
            "region": "us-east-1",
            "access_key": "minioadmin",
            "secret_key": "minioadmin",
            "tls": {"skip_verify": true},
        }))
        .unwrap();
        assert_eq!(cfg.region, "us-east-1");
        assert!(cfg.tls.unwrap().skip_verify == Some(true));
    }

    #[test]
    fn client_constructs_with_http_endpoint() {
        let client = S3Client::from_config(S3Config {
            endpoint: "localhost:9000".into(),
            region: "us-east-1".into(),
            access_key: "minioadmin".into(),
            secret_key: "minioadmin".into(),
            tls: None,
        })
        .unwrap();
        assert_eq!(client.endpoint, "http://localhost:9000");
        assert_eq!(client.host, "localhost:9000");
    }

    #[test]
    fn client_constructs_https_when_tls_enabled() {
        let client = S3Client::from_config(S3Config {
            endpoint: "localhost:9000".into(),
            region: "us-east-1".into(),
            access_key: "a".into(),
            secret_key: "b".into(),
            tls: Some(TlsClientConfig {
                ca_cert: None,
                client_cert: None,
                client_key: None,
                skip_verify: Some(true),
            }),
        })
        .unwrap();
        assert_eq!(client.endpoint, "https://localhost:9000");
    }

    fn test_client() -> S3Client {
        S3Client::from_config(S3Config {
            endpoint: "localhost:9000".into(),
            region: "us-east-1".into(),
            access_key: "minioadmin".into(),
            secret_key: "minioadmin".into(),
            tls: None,
        })
        .unwrap()
    }

    #[test]
    fn object_path_returns_raw_key() {
        let client = test_client();
        assert_eq!(client.object_path("bucket", "a b#c?d%e.txt"), "/bucket/a b#c?d%e.txt");
    }

    #[test]
    fn signed_request_url_encodes_path_exactly_once() {
        let client = test_client();
        let path = client.object_path("bucket", "a b#c?d%e.txt");
        let (url, _, _, _) = client.signed_request("PUT", &path, &[], b"data");
        assert!(url.contains("/bucket/a%20b%23c%3Fd%25e.txt"), "url: {url}");
        assert!(!url.contains("%2520"), "double encoding: {url}");
    }

    #[test]
    fn signed_request_returns_headers_matching_signature() {
        let client = test_client();
        let path = client.object_path("bucket", "key");
        let (_, auth, amz_date, payload_hash) = client.signed_request("PUT", &path, &[], b"data");
        // 签名使用的时间与 payload 哈希必须与请求装配值一致（同一来源）。
        let expected_hash = hex(&Sha256::digest(b"data"));
        assert_eq!(payload_hash, expected_hash);
        assert!(amz_date.ends_with('Z') && amz_date.len() == 16, "amz_date: {amz_date}");
        // Authorization 的 SignedHeaders 与 credential scope 使用同一 amz_date。
        let scope_date = amz_date[..8].to_string();
        assert!(auth.contains(&format!("{scope_date}/us-east-1/s3/aws4_request")));
        assert!(auth.contains("SignedHeaders=host;x-amz-content-sha256;x-amz-date"));
        // 空 payload（GET/DELETE/list）哈希固定。
        let (_, _, _, empty_hash) = client.signed_request("GET", &path, &[], b"");
        assert_eq!(
            empty_hash,
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        );
    }

    #[test]
    fn client_keeps_explicit_scheme() {
        let client = S3Client::from_config(S3Config {
            endpoint: "https://s3.example.com:8443".into(),
            region: "us-east-1".into(),
            access_key: "a".into(),
            secret_key: "b".into(),
            tls: None,
        })
        .unwrap();
        assert_eq!(client.endpoint, "https://s3.example.com:8443");
        assert_eq!(client.host, "s3.example.com:8443");
    }

    #[tokio::test]
    async fn put_surfaces_http_error_status() {
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let addr = listener.local_addr().unwrap();
        std::thread::spawn(move || {
            if let Ok((mut sock, _)) = listener.accept() {
                let mut buf = [0u8; 8192];
                let _ = sock.read(&mut buf);
                let _ = sock.write_all(
                    b"HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
                );
            }
        });
        let client = S3Client::from_config(S3Config {
            endpoint: addr.to_string(),
            region: "us-east-1".into(),
            access_key: "a".into(),
            secret_key: "b".into(),
            tls: None,
        })
        .unwrap();
        let err = client.put("bucket", "key", b"data").await.unwrap_err();
        assert!(err.to_string().contains("HTTP 500"), "got: {err}");
    }

    #[tokio::test]
    async fn requests_carry_all_signed_headers() {
        // 请求装配层：SignedHeaders 列出的头必须实际出现在请求中，
        // 否则真实 S3 对所有操作返回 403 SignatureDoesNotMatch。
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let addr = listener.local_addr().unwrap();
        let (got_tx, got_rx) = std::sync::mpsc::channel();
        std::thread::spawn(move || {
            if let Ok((mut sock, _)) = listener.accept() {
                let mut buf = [0u8; 8192];
                let n = sock.read(&mut buf).unwrap();
                let _ = got_tx.send(String::from_utf8_lossy(&buf[..n]).to_string());
                let _ = sock.write_all(
                    b"HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
                );
            }
        });
        let client = S3Client::from_config(S3Config {
            endpoint: addr.to_string(),
            region: "us-east-1".into(),
            access_key: "a".into(),
            secret_key: "b".into(),
            tls: None,
        })
        .unwrap();
        client.put("bucket", "key", b"data").await.unwrap();
        let raw = got_rx.recv().unwrap();
        let (head, _) = raw.split_once("\r\n\r\n").unwrap();
        let auth = head
            .lines()
            .find_map(|l| l.strip_prefix("authorization: "))
            .or_else(|| head.lines().find_map(|l| l.strip_prefix("Authorization: ")))
            .unwrap();
        let signed = auth
            .split(", ")
            .find_map(|p| p.strip_prefix("SignedHeaders="))
            .unwrap();
        for name in signed.split(';') {
            assert!(
                head.to_ascii_lowercase().contains(&format!("{name}:")),
                "missing signed header {name} in:\n{head}"
            );
        }
        // payload 哈希头与 body 一致。
        assert!(head.contains(
            "x-amz-content-sha256: 3a6eb0790f39ac87c94f3856b2dd2c5d110e6811602261a9a923d3bb23adc8b7"
        ), "hash mismatch in:\n{head}");
    }
}
