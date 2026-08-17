// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use super::{ConfigError, ConfigSource};
use async_trait::async_trait;
use std::collections::HashMap;

pub struct EnvSource {
    prefix: String,
}

impl EnvSource {
    pub fn new(prefix: impl Into<String>) -> Self {
        Self {
            prefix: prefix.into(),
        }
    }
}

fn parse_env_value(s: &str) -> serde_json::Value {
    if s.eq_ignore_ascii_case("true") {
        return serde_json::Value::Bool(true);
    }
    if s.eq_ignore_ascii_case("false") {
        return serde_json::Value::Bool(false);
    }
    if let Ok(n) = s.parse::<i64>() {
        return serde_json::Value::Number(n.into());
    }
    if let Ok(n) = s.parse::<f64>()
        && let Some(num) = serde_json::Number::from_f64(n)
    {
        return serde_json::Value::Number(num);
    }
    serde_json::Value::String(s.to_string())
}

#[async_trait]
impl ConfigSource for EnvSource {
    async fn load(&self) -> Result<HashMap<String, serde_json::Value>, ConfigError> {
        let mut map = HashMap::new();
        for (key, value) in std::env::vars() {
            if key.starts_with(&self.prefix) {
                let k = key[self.prefix.len()..].to_lowercase();
                let v = parse_env_value(&value);
                map.insert(k, v);
            }
        }
        Ok(map)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse_bool_true() {
        assert_eq!(parse_env_value("true"), serde_json::Value::Bool(true));
        assert_eq!(parse_env_value("TRUE"), serde_json::Value::Bool(true));
    }

    #[test]
    fn parse_bool_false() {
        assert_eq!(parse_env_value("false"), serde_json::Value::Bool(false));
    }

    #[test]
    fn parse_int() {
        assert_eq!(parse_env_value("42"), serde_json::json!(42));
        assert_eq!(parse_env_value("-1"), serde_json::json!(-1));
    }

    #[test]
    fn parse_float() {
        let v = parse_env_value("3.14");
        assert!(v.is_number());
    }

    #[test]
    fn parse_string_fallback() {
        assert_eq!(
            parse_env_value("hello"),
            serde_json::Value::String("hello".into())
        );
    }
}
