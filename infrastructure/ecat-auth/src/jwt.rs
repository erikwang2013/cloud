// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use super::claims::AuthClaims;
use super::helpers::extract_bearer;
use http::{Request, Response, StatusCode};
use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;
use std::task::{Context, Poll};
use tower::{Layer, Service};

/// Errors while constructing or operating the JWT auth layer.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum JwtAuthError {
    /// The shared secret is shorter than 32 bytes, which is too weak for HS256.
    WeakKey,
}

impl std::fmt::Display for JwtAuthError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::WeakKey => write!(f, "JWT secret must be at least 32 bytes for HS256"),
        }
    }
}

impl std::error::Error for JwtAuthError {}

enum JwtSecret {
    Shared(Vec<u8>),
    #[allow(dead_code)]
    RsaReserved(Vec<u8>),
}

#[derive(Clone)]
pub struct JwtAuthLayer {
    secret: Arc<JwtSecret>,
    required_claims: Vec<String>,
    header_name: String,
    /// 配置后强制校验 JWT 的 iss（签发者）声明，不匹配即拒绝。
    required_issuer: Option<String>,
    /// 配置后强制校验 JWT 的 aud（受众）声明，不匹配即拒绝。
    required_audience: Option<String>,
}

impl JwtAuthLayer {
    /// Create a layer for HS256-signed tokens.
    ///
    /// The secret must be at least 32 bytes (the minimum key size HS256
    /// accepts per RFC 7518); shorter keys are rejected with
    /// [`JwtAuthError::WeakKey`].
    pub fn new(secret: impl Into<String>) -> Result<Self, JwtAuthError> {
        let secret = secret.into();
        if secret.len() < 32 {
            return Err(JwtAuthError::WeakKey);
        }
        Ok(Self {
            secret: Arc::new(JwtSecret::Shared(secret.into_bytes())),
            required_claims: vec!["sub".into()],
            header_name: "Authorization".into(),
            required_issuer: None,
            required_audience: None,
        })
    }

    pub fn require_claims(mut self, claims: &[&str]) -> Self {
        self.required_claims = claims.iter().map(|c| c.to_string()).collect();
        self
    }

    /// 强制校验 iss（签发者）声明：缺失或不匹配即拒绝。
    /// 默认不校验 iss，保持向后兼容。
    pub fn required_issuer(mut self, issuer: impl Into<String>) -> Self {
        self.required_issuer = Some(issuer.into());
        self
    }

    /// 强制校验 aud（受众）声明：缺失或不匹配即拒绝。
    /// 默认不校验 aud，保持向后兼容。
    pub fn required_audience(mut self, audience: impl Into<String>) -> Self {
        self.required_audience = Some(audience.into());
        self
    }

    pub fn header_name(mut self, name: impl Into<String>) -> Self {
        self.header_name = name.into();
        self
    }
}

impl<S> Layer<S> for JwtAuthLayer {
    type Service = JwtAuthService<S>;

    fn layer(&self, inner: S) -> Self::Service {
        JwtAuthService {
            inner,
            config: Arc::new(self.clone()),
        }
    }
}

#[derive(Clone)]
pub struct JwtAuthService<S> {
    inner: S,
    config: Arc<JwtAuthLayer>,
}

impl<S, B> Service<Request<B>> for JwtAuthService<S>
where
    S: Service<Request<B>, Response = Response<axum::body::Body>> + Clone + Send + 'static,
    S::Future: Send + 'static,
    S::Error: std::error::Error + Send + Sync + 'static,
    B: Send + 'static,
{
    type Response = Response<axum::body::Body>;
    type Error = Box<dyn std::error::Error + Send + Sync>;
    type Future = Pin<Box<dyn Future<Output = Result<Self::Response, Self::Error>> + Send>>;

    fn poll_ready(&mut self, cx: &mut Context<'_>) -> Poll<Result<(), Self::Error>> {
        self.inner.poll_ready(cx).map_err(|e| Box::new(e) as _)
    }

    fn call(&mut self, req: Request<B>) -> Self::Future {
        let token = extract_bearer(req.headers(), &self.config.header_name);
        let config = Arc::clone(&self.config);
        let mut inner = self.inner.clone();

        Box::pin(async move {
            let token = match token {
                Some(t) => t,
                None => {
                    return Ok(Response::builder()
                        .status(StatusCode::UNAUTHORIZED)
                        .body(axum::body::Body::from(
                            r#"{"error":"missing authorization token"}"#,
                        ))
                        .unwrap());
                }
            };

            let secret_bytes = match config.secret.as_ref() {
                JwtSecret::Shared(b) => b,
                JwtSecret::RsaReserved(b) => b,
            };

            let mut validation = jsonwebtoken::Validation::new(jsonwebtoken::Algorithm::HS256);
            if let Some(iss) = &config.required_issuer {
                validation.set_issuer(&[iss.as_str()]);
            }
            if let Some(aud) = &config.required_audience {
                validation.set_audience(&[aud.as_str()]);
            }
            let token_data = match jsonwebtoken::decode::<AuthClaims>(
                &token,
                &jsonwebtoken::DecodingKey::from_secret(secret_bytes),
                &validation,
            ) {
                Ok(data) => data,
                Err(e) => {
                    // Distinguish expiry in the logs without leaking
                    // jsonwebtoken internals to clients.
                    let expired =
                        matches!(e.kind(), jsonwebtoken::errors::ErrorKind::ExpiredSignature);
                    tracing::warn!(
                        error = %e,
                        expired,
                        "jwt validation failed"
                    );
                    return Ok(Response::builder()
                        .status(StatusCode::UNAUTHORIZED)
                        .body(axum::body::Body::from(r#"{"error":"invalid token"}"#))
                        .unwrap());
                }
            };

            // jsonwebtoken 的 set_issuer/set_audience 只在声明存在时校验匹配，
            // 声明缺失会被静默跳过；required_issuer/audience 语义是"必须存在且匹配"，
            // 因此这里补存在性检查。
            if (config.required_issuer.is_some() && !token_data.claims.extra.contains_key("iss"))
                || (config.required_audience.is_some()
                    && !token_data.claims.extra.contains_key("aud"))
            {
                return Ok(Response::builder()
                    .status(StatusCode::UNAUTHORIZED)
                    .body(axum::body::Body::from(r#"{"error":"invalid token"}"#))
                    .unwrap());
            }

            for claim in &config.required_claims {
                let satisfied = match claim.as_str() {
                    "sub" => !token_data.claims.sub.is_empty(),
                    "role" => token_data.claims.role.is_some(),
                    _ => token_data.claims.extra.contains_key(claim),
                };
                if !satisfied {
                    return Ok(Response::builder()
                        .status(StatusCode::FORBIDDEN)
                        .body(axum::body::Body::from(format!(
                            r#"{{"error":"missing required claim: {claim}"}}"#
                        )))
                        .unwrap());
                }
            }

            let claims = token_data.claims;
            let mut req = req;
            req.extensions_mut().insert(claims);
            inner.call(req).await.map_err(|e| Box::new(e) as _)
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tower::ServiceExt;

    const SECRET: &str = "0123456789abcdef0123456789abcdef";
    const ISSUER: &str = "https://issuer.example";
    const AUDIENCE: &str = "api.example";

    fn make_token(sub: &str, iss: Option<&str>, aud: Option<&str>) -> String {
        let mut claims = serde_json::json!({ "sub": sub, "exp": 4_102_444_800u64 });
        if let Some(iss) = iss {
            claims["iss"] = serde_json::Value::String(iss.into());
        }
        if let Some(aud) = aud {
            claims["aud"] = serde_json::Value::String(aud.into());
        }
        jsonwebtoken::encode(
            &jsonwebtoken::Header::new(jsonwebtoken::Algorithm::HS256),
            &claims,
            &jsonwebtoken::EncodingKey::from_secret(SECRET.as_bytes()),
        )
        .unwrap()
    }

    async fn call_layer(layer: JwtAuthLayer, token: &str) -> axum::http::StatusCode {
        // JwtAuthService 的错误类型是 Box<dyn Error>，axum Router（Infallible）
        // 无法直接 layer，此处用 Layer::layer 包一个 MethodRouter 做端到端测试。
        let svc = layer.layer(axum::routing::get(|| async { "ok" }));
        svc.oneshot(
            axum::http::Request::builder()
                .header("Authorization", format!("Bearer {token}"))
                .body(axum::body::Body::empty())
                .unwrap(),
        )
        .await
        .unwrap()
        .status()
    }

    /// S4 向后兼容：未配置 required_issuer/audience 时，
    /// 不带 iss/aud 的 token 必须照常通过。
    #[tokio::test]
    async fn default_layer_accepts_token_without_iss_aud() {
        let layer = JwtAuthLayer::new(SECRET).unwrap();
        let token = make_token("user-1", None, None);
        assert_eq!(call_layer(layer, &token).await, StatusCode::OK);
    }

    /// S4：配置 required_issuer 后，iss 缺失或不匹配即拒绝。
    #[tokio::test]
    async fn required_issuer_rejects_missing_and_wrong() {
        let layer = JwtAuthLayer::new(SECRET).unwrap().required_issuer(ISSUER);

        let ok = make_token("user-1", Some(ISSUER), None);
        assert_eq!(call_layer(layer.clone(), &ok).await, StatusCode::OK);

        let wrong = make_token("user-1", Some("https://other.example"), None);
        assert_eq!(call_layer(layer.clone(), &wrong).await, StatusCode::UNAUTHORIZED);

        let missing = make_token("user-1", None, None);
        assert_eq!(call_layer(layer, &missing).await, StatusCode::UNAUTHORIZED);
    }

    /// S4：配置 required_audience 后，aud 缺失或不匹配即拒绝。
    #[tokio::test]
    async fn required_audience_rejects_missing_and_wrong() {
        let layer = JwtAuthLayer::new(SECRET).unwrap().required_audience(AUDIENCE);

        let ok = make_token("user-1", None, Some(AUDIENCE));
        assert_eq!(call_layer(layer.clone(), &ok).await, StatusCode::OK);

        let wrong = make_token("user-1", None, Some("other.example"));
        assert_eq!(call_layer(layer.clone(), &wrong).await, StatusCode::UNAUTHORIZED);

        let missing = make_token("user-1", None, None);
        assert_eq!(call_layer(layer, &missing).await, StatusCode::UNAUTHORIZED);
    }
}
