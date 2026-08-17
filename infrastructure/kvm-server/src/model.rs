use ecat_data::Row;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::sync::atomic::{AtomicU64, Ordering};

use crate::driver::KvmError;

/// 任务参数中的规格，各消费方沿用 PHP 各自默认值（selector 1/1/10，orchestrator 2/2/20）。
#[derive(Debug, Clone, Default, Deserialize, Serialize)]
pub struct Specs {
    pub cpu: Option<i64>,
    pub ram: Option<i64>,
    pub system_disk: Option<i64>,
}

impl From<&Value> for Specs {
    fn from(v: &Value) -> Self {
        Specs {
            cpu: v.get("cpu").and_then(|x| x.as_i64()),
            ram: v.get("ram").and_then(|x| x.as_i64()),
            system_disk: v.get("system_disk").and_then(|x| x.as_i64()),
        }
    }
}

/// 防火墙规则：与 PHP defaultRules 的 JSON 形状一致。
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct FirewallRule {
    pub direction: String,
    pub protocol: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub port: Option<i64>,
    pub cidr: String,
    pub action: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub state: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct ProvisionInfo {
    pub vm_id: String,
    pub ip_address: String,
    pub bridge: String,
}

/// provision_tasks 载荷（PHP 传入），create/create_disk/create_ip 用。
#[derive(Debug, Clone, Default, Deserialize, Serialize)]
pub struct TaskParams {
    #[serde(default)]
    pub resource_id: Option<i64>,
    #[serde(default)]
    pub region_id: Option<i64>,
    #[serde(default)]
    pub params: Option<Value>,
}

impl TaskParams {
    pub fn specs(&self) -> Specs {
        self.params
            .as_ref()
            .and_then(|p| p.get("specs"))
            .map(Specs::from)
            .unwrap_or_default()
    }
}

fn get_i64<'a>(row: &'a Row, col: &str) -> Result<i64, KvmError> {
    row.get(col)
        .and_then(|v| v.as_i64())
        .ok_or_else(|| KvmError::Retryable(format!("column {col} missing or not integer")))
}

fn get_str<'a>(row: &'a Row, col: &str) -> Result<String, KvmError> {
    row.get(col)
        .and_then(|v| v.as_str())
        .map(|s| s.to_string())
        .ok_or_else(|| KvmError::Retryable(format!("column {col} missing or not string")))
}

fn get_opt_str<'a>(row: &'a Row, col: &str) -> Result<Option<String>, KvmError> {
    Ok(row
        .get(col)
        .and_then(|v| v.as_str())
        .map(|s| s.to_string()))
}

#[derive(Debug, Clone)]
pub struct HostMachine {
    pub id: i64,
    pub region_id: i64,
    pub ip_address: String,
    pub storage_pool: String,
}

impl HostMachine {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(HostMachine {
            id: get_i64(r, "id")?,
            region_id: get_i64(r, "region_id")?,
            ip_address: get_str(r, "ip_address")?,
            storage_pool: get_str(r, "storage_pool")?,
        })
    }
}

#[derive(Debug, Clone)]
pub struct Resource {
    pub id: i64,
    pub specs: Option<String>,
}

impl Resource {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(Resource {
            id: get_i64(r, "id")?,
            specs: get_opt_str(r, "specs")?,
        })
    }
}

#[derive(Debug, Clone)]
pub struct Disk {
    pub id: i64,
    pub resource_id: i64,
    pub host_machine_id: i64,
    pub vm_id: Option<String>,
    pub size_gb: i64,
    pub storage_pool: String,
    pub status: String,
}

impl Disk {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(Disk {
            id: get_i64(r, "id")?,
            resource_id: get_i64(r, "resource_id")?,
            host_machine_id: get_i64(r, "host_machine_id")?,
            vm_id: get_opt_str(r, "vm_id")?,
            size_gb: get_i64(r, "size_gb")?,
            storage_pool: get_str(r, "storage_pool")?,
            status: get_str(r, "status")?,
        })
    }
}

#[derive(Debug, Clone)]
pub struct IpPool {
    pub id: i64,
    pub host_machine_id: i64,
    pub ip_start: String,
    pub ip_end: String,
    pub gateway: String,
}

impl IpPool {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(IpPool {
            id: get_i64(r, "id")?,
            host_machine_id: get_i64(r, "host_machine_id")?,
            ip_start: get_str(r, "ip_start")?,
            ip_end: get_str(r, "ip_end")?,
            gateway: get_str(r, "gateway")?,
        })
    }
}

#[derive(Debug, Clone)]
pub struct IpAllocation {
    pub ip_pool_id: i64,
    pub resource_id: i64,
    pub ip_address: String,
}

#[derive(Debug, Clone)]
pub struct NetworkService {
    pub id: i64,
    pub bridge_name: String,
}

impl NetworkService {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(NetworkService {
            id: get_i64(r, "id")?,
            bridge_name: get_str(r, "bridge_name")?,
        })
    }
}

#[derive(Debug, Clone)]
pub struct FirewallService {
    pub id: i64,
    pub table_name: String,
}

impl FirewallService {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(FirewallService {
            id: get_i64(r, "id")?,
            table_name: get_str(r, "table_name")?,
        })
    }
}

#[derive(Debug, Clone)]
pub struct SwitchService {
    pub id: i64,
    pub veth_host: String,
}

impl SwitchService {
    pub fn from_row(r: &Row) -> Result<Self, KvmError> {
        Ok(SwitchService {
            id: get_i64(r, "id")?,
            veth_host: get_str(r, "veth_host")?,
        })
    }
}

// ponytail: 伪雪花 id（毫秒<<22 | pid<<16 | 进程内自增），仅服务本 crate 插入的自管行；
// 生产若需与统一 ID 服务协调再替换。
static ID_COUNTER: AtomicU64 = AtomicU64::new(0);

pub fn next_id() -> i64 {
    let now_ms = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis() as u64;
    let seq = ID_COUNTER.fetch_add(1, Ordering::Relaxed) & 0xffff;
    let pid = (std::process::id() as u64) & 0x3f;
    ((now_ms << 22) | (pid << 16) | seq) as i64
}
