// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// 测试模块：lib.rs 的私有项通过 super::* 引用
use super::*;

#[test]
fn client_constructs() {
    let _client = ClickhouseClient::new("http://localhost:8123", "default");
}

#[test]
fn config_with_optional_auth() {
    let cfg: ClickhouseConfig = serde_json::from_str(
        r#"{"base_url":"http://localhost:8123","username":"default","password":"secret"}"#,
    )
    .unwrap();
    let client = ClickhouseClient::from_config(cfg).unwrap();
    assert!(client.username.is_some());
}

#[test]
fn quote_ident_escapes_backticks() {
    assert_eq!(quote_ident("cpu"), "`cpu`");
    assert_eq!(quote_ident("a`b"), "`a``b`");
}

#[test]
fn field_type_maps_variants() {
    assert_eq!(field_type(&FieldValue::Float(1.0)), "Float64");
    assert_eq!(field_type(&FieldValue::Int(1)), "Int64");
    assert_eq!(field_type(&FieldValue::String("s".into())), "String");
    assert_eq!(field_type(&FieldValue::Bool(true)), "UInt8");
}

#[test]
fn build_create_table_sql() {
    let sql = build_create_table(
        "cpu",
        &["host".to_string()],
        &[("usage".to_string(), "Float64")],
    );
    assert_eq!(
        sql,
        "CREATE TABLE IF NOT EXISTS `cpu` (`host` String, `usage` Float64, `timestamp` Int64 DEFAULT 0) ENGINE = MergeTree ORDER BY timestamp"
    );
}

#[test]
fn build_insert_body_serializes_and_escapes() {
    let points = [
        DataPoint::new("cpu")
            .with_tag("host", "a`b,c")
            .with_field("usage", FieldValue::Float(0.5))
            .with_timestamp(100),
        DataPoint::new("cpu")
            .with_field("usage", FieldValue::Int(7))
            .with_timestamp(200),
    ];
    let refs: Vec<&DataPoint> = points.iter().collect();
    let body = build_insert_body(&refs, &["host".to_string()], &["usage".to_string()]);
    let lines: Vec<&str> = body.lines().collect();
    assert_eq!(lines.len(), 2);
    assert_eq!(lines[0], r#"{"host":"a`b,c","usage":0.5,"timestamp":100}"#);
    assert_eq!(lines[1], r#"{"usage":7,"timestamp":200}"#);
}

#[test]
fn field_to_json_non_finite_floats_fall_back_to_zero() {
    assert_eq!(
        field_to_json(&FieldValue::Float(f64::NAN)),
        serde_json::json!(0)
    );
    assert_eq!(
        field_to_json(&FieldValue::Float(f64::INFINITY)),
        serde_json::json!(0)
    );
    assert_eq!(
        field_to_json(&FieldValue::Float(f64::NEG_INFINITY)),
        serde_json::json!(0)
    );
    assert_eq!(
        field_to_json(&FieldValue::Float(0.5)),
        serde_json::json!(0.5)
    );
}

#[test]
fn build_insert_body_omits_timestamp_key_when_missing() {
    let points = [DataPoint::new("cpu")
        .with_tag("host", "h1")
        .with_field("usage", FieldValue::Float(0.5))];
    let refs: Vec<&DataPoint> = points.iter().collect();
    let body = build_insert_body(&refs, &["host".to_string()], &["usage".to_string()]);
    assert_eq!(body, "{\"host\":\"h1\",\"usage\":0.5}\n");
}

#[test]
fn build_insert_body_empty_points_is_empty_string() {
    let refs: Vec<&DataPoint> = Vec::new();
    let body = build_insert_body(&refs, &["host".to_string()], &["usage".to_string()]);
    assert_eq!(body, "");
}

#[test]
fn build_insert_body_filters_keys_per_point() {
    // 两个不同 measurement 的点只含各自拥有的键（键过滤按点进行）
    let points = [
        DataPoint::new("cpu")
            .with_tag("host", "a")
            .with_timestamp(1),
        DataPoint::new("mem")
            .with_tag("dc", "x")
            .with_field("used", FieldValue::Int(5)),
    ];
    let refs: Vec<&DataPoint> = points.iter().collect();
    let body = build_insert_body(&refs, &["host".to_string()], &["used".to_string()]);
    let lines: Vec<&str> = body.lines().collect();
    assert_eq!(lines.len(), 2);
    assert_eq!(lines[0], r#"{"host":"a","timestamp":1}"#);
    assert_eq!(lines[1], r#"{"used":5}"#);
}
