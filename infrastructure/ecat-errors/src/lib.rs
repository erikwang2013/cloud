// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
mod codes;

use codes::ErrorCodeExt;
use ecat_protos::errors::ErrorCode;
use std::collections::HashMap;

#[derive(Debug, thiserror::Error)]
pub struct Error {
    pub code: ErrorCode,
    pub reason: String,
    pub message: String,
    #[source]
    pub cause: Option<Box<dyn std::error::Error + Send + Sync>>,
    pub metadata: HashMap<String, String>,
}

impl std::fmt::Display for Error {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "[{:?}] {}: {}", self.code, self.reason, self.message)
    }
}

impl Error {
    pub fn new(code: ErrorCode, reason: impl Into<String>, message: impl Into<String>) -> Self {
        Self {
            code,
            reason: reason.into(),
            message: message.into(),
            cause: None,
            metadata: HashMap::new(),
        }
    }

    pub fn message(&self) -> &str {
        &self.message
    }

    pub fn with_metadata(mut self, key: impl Into<String>, value: impl Into<String>) -> Self {
        self.metadata.insert(key.into(), value.into());
        self
    }

    pub fn from_status(status: tonic::Status) -> Self {
        let code = match status.code() {
            tonic::Code::Ok => ErrorCode::Ok,
            tonic::Code::NotFound => ErrorCode::NotFound,
            tonic::Code::InvalidArgument => ErrorCode::InvalidArgument,
            tonic::Code::PermissionDenied => ErrorCode::PermissionDenied,
            tonic::Code::Unauthenticated => ErrorCode::Unauthenticated,
            tonic::Code::ResourceExhausted => ErrorCode::ResourceExhausted,
            tonic::Code::AlreadyExists => ErrorCode::AlreadyExists,
            tonic::Code::Unavailable => ErrorCode::Unavailable,
            tonic::Code::DeadlineExceeded => ErrorCode::DeadlineExceeded,
            tonic::Code::Unknown => ErrorCode::Unknown,
            _ => ErrorCode::Internal,
        };
        Self::new(code, "grpc_error", status.message().to_string())
    }

    pub fn to_http_status(&self) -> http::StatusCode {
        self.code.http_status()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use http::StatusCode;

    #[test]
    fn test_error_code_http_mapping() {
        assert_eq!(ErrorCode::Ok.http_status(), StatusCode::OK);
        assert_eq!(ErrorCode::NotFound.http_status(), StatusCode::NOT_FOUND);
        assert_eq!(
            ErrorCode::InvalidArgument.http_status(),
            StatusCode::BAD_REQUEST
        );
        assert_eq!(
            ErrorCode::PermissionDenied.http_status(),
            StatusCode::FORBIDDEN
        );
        assert_eq!(
            ErrorCode::Unauthenticated.http_status(),
            StatusCode::UNAUTHORIZED
        );
        assert_eq!(
            ErrorCode::Internal.http_status(),
            StatusCode::INTERNAL_SERVER_ERROR
        );
        assert_eq!(
            ErrorCode::Unavailable.http_status(),
            StatusCode::SERVICE_UNAVAILABLE
        );
        assert_eq!(
            ErrorCode::DeadlineExceeded.http_status(),
            StatusCode::GATEWAY_TIMEOUT
        );
    }

    #[test]
    fn test_from_status_maps_codes() {
        let err = Error::from_status(tonic::Status::not_found("missing"));
        assert_eq!(err.code, ErrorCode::NotFound);

        let err = Error::from_status(tonic::Status::permission_denied("nope"));
        assert_eq!(err.code, ErrorCode::PermissionDenied);

        let err = Error::from_status(tonic::Status::unauthenticated("bad token"));
        assert_eq!(err.code, ErrorCode::Unauthenticated);
    }

    #[test]
    fn test_with_metadata_accumulates() {
        let err = Error::new(ErrorCode::Ok, "test", "msg")
            .with_metadata("key1", "val1")
            .with_metadata("key2", "val2");
        assert_eq!(err.metadata.get("key1").unwrap(), "val1");
        assert_eq!(err.metadata.get("key2").unwrap(), "val2");
    }

    #[test]
    fn test_display_format() {
        let err = Error::new(ErrorCode::NotFound, "user_not_found", "user 42 not found");
        let s = err.to_string();
        assert!(s.contains("NotFound"));
        assert!(s.contains("user_not_found"));
        assert!(s.contains("user 42 not found"));
    }

    #[test]
    fn test_from_status_maps_all_codes() {
        let cases = [
            (tonic::Status::ok(""), ErrorCode::Ok),
            (tonic::Status::invalid_argument("bad"), ErrorCode::InvalidArgument),
            (
                tonic::Status::resource_exhausted("full"),
                ErrorCode::ResourceExhausted,
            ),
            (tonic::Status::already_exists("dup"), ErrorCode::AlreadyExists),
            (tonic::Status::unavailable("down"), ErrorCode::Unavailable),
            (
                tonic::Status::deadline_exceeded("slow"),
                ErrorCode::DeadlineExceeded,
            ),
            (tonic::Status::unknown("?"), ErrorCode::Unknown),
        ];
        for (status, expected) in cases {
            assert_eq!(Error::from_status(status).code, expected);
        }
    }

    #[test]
    fn test_from_status_unmapped_code_falls_back_to_internal() {
        // OutOfRange 等未显式映射的 code 必须回退 Internal 而非误判
        let status = tonic::Status::new(tonic::Code::OutOfRange, "out of range");
        assert_eq!(Error::from_status(status).code, ErrorCode::Internal);
    }

    #[test]
    fn test_message_accessor() {
        let err = Error::new(ErrorCode::Ok, "r", "the message");
        assert_eq!(err.message(), "the message");
        assert_eq!(err.message(), err.message.as_str());
    }

    #[test]
    fn test_to_http_status_matches_code() {
        let err = Error::new(ErrorCode::NotFound, "r", "m");
        assert_eq!(err.to_http_status(), StatusCode::NOT_FOUND);
        assert_eq!(
            err.to_http_status(),
            ErrorCode::NotFound.http_status()
        );
    }

    #[test]
    fn test_cause_is_none_by_default() {
        let err = Error::new(ErrorCode::Ok, "r", "m");
        assert!(err.cause.is_none());
    }
}
