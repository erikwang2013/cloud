// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
pub mod errors {
    tonic::include_proto!("ecat.errors");
}

pub mod metadata {
    tonic::include_proto!("ecat.metadata");
}

#[cfg(test)]
mod tests {
    use super::*;
    use prost::Message;

    #[test]
    fn error_roundtrips_through_prost() {
        let mut metadata = std::collections::HashMap::new();
        metadata.insert("retryable".to_string(), "true".to_string());
        let err = errors::Error {
            code: errors::ErrorCode::PermissionDenied as i32,
            reason: "PERMISSION_DENIED".into(),
            message: "no access".into(),
            metadata,
        };
        let bytes = err.encode_to_vec();
        let decoded = errors::Error::decode(bytes.as_slice()).unwrap();
        assert_eq!(decoded.code, 1004);
        assert_eq!(decoded.reason, "PERMISSION_DENIED");
        assert_eq!(decoded.message, "no access");
        assert_eq!(decoded.metadata.get("retryable").map(String::as_str), Some("true"));
        assert_eq!(decoded.metadata.len(), 1);
    }

    #[test]
    fn error_code_enum_values_match_proto() {
        assert_eq!(errors::ErrorCode::Ok as i32, 0);
        assert_eq!(errors::ErrorCode::InvalidArgument as i32, 1001);
        assert_eq!(errors::ErrorCode::PermissionDenied as i32, 1004);
        assert_eq!(errors::ErrorCode::DeadlineExceeded as i32, 1009);
    }

    #[test]
    fn metadata_roundtrips_through_prost() {
        let mut pairs = std::collections::HashMap::new();
        pairs.insert("env".to_string(), "prod".to_string());
        pairs.insert("region".to_string(), "cn-east".to_string());
        let md = metadata::Metadata { pairs };
        let decoded = metadata::Metadata::decode(md.encode_to_vec().as_slice()).unwrap();
        assert_eq!(decoded, md);
        assert_eq!(decoded.pairs.len(), 2);
    }

    #[test]
    fn empty_message_roundtrips() {
        let err = errors::Error {
            code: 0,
            reason: String::new(),
            message: String::new(),
            metadata: Default::default(),
        };
        let decoded = errors::Error::decode(err.encode_to_vec().as_slice()).unwrap();
        assert_eq!(decoded, err);
        assert!(decoded.metadata.is_empty());
    }
}
