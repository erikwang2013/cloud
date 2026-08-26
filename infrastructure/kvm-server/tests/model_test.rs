mod common;

use common::row;
use kvm_server::driver::KvmError;
use kvm_server::model::{
    Disk, FirewallRule, FirewallService, HostMachine, IpAllocation, IpPool, NetworkService,
    Resource, Specs, SwitchService, TaskParams, next_id,
};
use serde_json::json;

#[test]
fn specs_from_json_full_and_partial() {
    let full: Specs = Specs::from(&json!({"cpu": 4, "ram": 8, "system_disk": 100}));
    assert_eq!(full.cpu, Some(4));
    assert_eq!(full.ram, Some(8));
    assert_eq!(full.system_disk, Some(100));

    let partial: Specs = Specs::from(&json!({"cpu": 2}));
    assert_eq!(partial.cpu, Some(2));
    assert_eq!(partial.ram, None);
    assert_eq!(partial.system_disk, None);
}

#[test]
fn specs_from_json_empty_and_non_integer() {
    let empty: Specs = Specs::from(&json!({}));
    assert_eq!(empty.cpu, None);
    assert_eq!(empty.ram, None);
    assert_eq!(empty.system_disk, None);

    // 字符串/浮点/数组都不是合法 i64，应回落 None 而不是 panic
    let weird: Specs = Specs::from(&json!({"cpu": "8", "ram": 2.5, "system_disk": [1, 2]}));
    assert_eq!(weird.cpu, None);
    assert_eq!(weird.ram, None);
    assert_eq!(weird.system_disk, None);
}

#[test]
fn task_params_specs_with_and_without_params() {
    let with_specs = TaskParams {
        params: Some(json!({"specs": {"cpu": 3, "ram": 6}})),
        ..Default::default()
    };
    let s = with_specs.specs();
    assert_eq!(s.cpu, Some(3));
    assert_eq!(s.ram, Some(6));

    let no_params = TaskParams::default();
    assert_eq!(no_params.specs().cpu, None);
    assert_eq!(no_params.specs().ram, None);
    assert_eq!(no_params.specs().system_disk, None);
}

#[test]
fn firewall_rule_serde_round_trip_with_state() {
    let rule = FirewallRule {
        direction: "inbound".into(),
        protocol: "tcp".into(),
        port: Some(22),
        cidr: "0.0.0.0/0".into(),
        action: "accept".into(),
        state: Some("established,related".into()),
    };
    let json_str = serde_json::to_string(&rule).unwrap();
    let back: FirewallRule = serde_json::from_str(&json_str).unwrap();
    assert_eq!(back, rule);
}

#[test]
fn firewall_rule_skips_optional_fields_when_none() {
    let rule = FirewallRule {
        direction: "inbound".into(),
        protocol: "udp".into(),
        port: None,
        cidr: "10.0.0.0/8".into(),
        action: "drop".into(),
        state: None,
    };
    let json_str = serde_json::to_string(&rule).unwrap();
    assert!(!json_str.contains("port"), "port must be skipped: {json_str}");
    assert!(!json_str.contains("state"), "state must be skipped: {json_str}");
    let back: FirewallRule = serde_json::from_str(&json_str).unwrap();
    assert_eq!(back.port, None);
    assert_eq!(back.state, None);
}

#[test]
fn from_row_success_for_all_models() {
    let host = HostMachine::from_row(&row(&[
        ("id", json!(1)),
        ("region_id", json!(2)),
        ("ip_address", json!("10.0.0.5")),
        ("storage_pool", json!("pool1")),
    ]))
    .unwrap();
    assert_eq!(host.id, 1);
    assert_eq!(host.region_id, 2);
    assert_eq!(host.ip_address, "10.0.0.5");

    let disk = Disk::from_row(&row(&[
        ("id", json!(1)),
        ("resource_id", json!(2)),
        ("host_machine_id", json!(3)),
        ("vm_id", json!("kvm-2")),
        ("size_gb", json!(50)),
        ("storage_pool", json!("pool1")),
        ("status", json!("active")),
    ]))
    .unwrap();
    assert_eq!(disk.vm_id.as_deref(), Some("kvm-2"));

    // vm_id 可空
    let disk_no_vm = Disk::from_row(&row(&[
        ("id", json!(1)),
        ("resource_id", json!(2)),
        ("host_machine_id", json!(3)),
        ("vm_id", serde_json::Value::Null),
        ("size_gb", json!(50)),
        ("storage_pool", json!("pool1")),
        ("status", json!("active")),
    ]))
    .unwrap();
    assert_eq!(disk_no_vm.vm_id, None);

    let pool = IpPool::from_row(&row(&[
        ("id", json!(1)),
        ("host_machine_id", json!(2)),
        ("ip_start", json!("10.0.0.1")),
        ("ip_end", json!("10.0.0.9")),
        ("gateway", json!("10.0.0.254")),
    ]))
    .unwrap();
    assert_eq!(pool.gateway, "10.0.0.254");

    let net = NetworkService::from_row(&row(&[
        ("id", json!(1)),
        ("bridge_name", json!("br-vm2")),
    ]))
    .unwrap();
    assert_eq!(net.bridge_name, "br-vm2");

    let fw = FirewallService::from_row(&row(&[
        ("id", json!(1)),
        ("table_name", json!("fw-vm2")),
    ]))
    .unwrap();
    assert_eq!(fw.table_name, "fw-vm2");

    let sw = SwitchService::from_row(&row(&[
        ("id", json!(1)),
        ("veth_host", json!("veth2a")),
    ]))
    .unwrap();
    assert_eq!(sw.veth_host, "veth2a");

    let res = Resource::from_row(&row(&[("id", json!(1)), ("specs", json!("{}"))])).unwrap();
    assert_eq!(res.specs.as_deref(), Some("{}"));
}

#[test]
fn from_row_missing_column_is_retryable_with_column_name() {
    let err = HostMachine::from_row(&row(&[("id", json!(1))])).unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("region_id")));
}

#[test]
fn from_row_wrong_type_is_retryable() {
    let err = HostMachine::from_row(&row(&[
        ("id", json!("not-an-int")),
        ("region_id", json!(2)),
        ("ip_address", json!("10.0.0.5")),
        ("storage_pool", json!("p")),
    ]))
    .unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("id")));
}

#[test]
fn from_row_null_ip_address_is_retryable() {
    let err = HostMachine::from_row(&row(&[
        ("id", json!(1)),
        ("region_id", json!(2)),
        ("ip_address", serde_json::Value::Null),
        ("storage_pool", json!("p")),
    ]))
    .unwrap_err();
    assert!(matches!(err, KvmError::Retryable(m) if m.contains("ip_address")));
}

#[test]
fn next_id_is_positive_and_monotonic() {
    let a = next_id();
    let b = next_id();
    let c = next_id();
    assert!(a > 0);
    assert!(b > a, "ids must be strictly increasing: {a} >= {b}");
    assert!(c > b);
}

#[test]
fn next_id_differs_across_instances_via_sequence_bits() {
    // 同一毫秒内自增序列段保证唯一（低位 16bit 递增）
    let ids: Vec<i64> = (0..1000).map(|_| next_id()).collect();
    let unique: std::collections::HashSet<_> = ids.iter().collect();
    assert_eq!(unique.len(), 1000, "1000 rapid ids must all be unique");
}

#[test]
fn ip_allocation_struct_shape() {
    let a = IpAllocation {
        ip_pool_id: 1,
        resource_id: 2,
        ip_address: "10.0.0.3".into(),
    };
    assert_eq!(a.ip_address, "10.0.0.3");
}
