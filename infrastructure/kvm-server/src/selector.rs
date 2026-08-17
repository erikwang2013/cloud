use ecat_data::RdbmsClient;

use crate::driver::KvmError;
use crate::model::{HostMachine, Specs};

/// 按区域选 KVM 宿主机：SQL 与 PHP HostSelector 逐句一致（hypervisor='kvm' +
/// online + JSON 余量比较 + cpu 占用率升序）。
pub struct HostSelector {
    db: std::sync::Arc<dyn RdbmsClient>,
}

impl HostSelector {
    pub fn new(db: std::sync::Arc<dyn RdbmsClient>) -> Self {
        HostSelector { db }
    }

    pub async fn select(&self, region_id: i64, specs: &Specs) -> Result<HostMachine, KvmError> {
        let sql = r#"
            SELECT id, region_id, ip_address, storage_pool
            FROM host_machines
            WHERE region_id = ? AND hypervisor = 'kvm' AND status = 'online'
              AND JSON_EXTRACT(specs, '$.cpu_total') - JSON_EXTRACT(specs, '$.cpu_allocated') >= ?
              AND JSON_EXTRACT(specs, '$.ram_total_gb') - JSON_EXTRACT(specs, '$.ram_allocated_gb') >= ?
              AND JSON_EXTRACT(specs, '$.disk_total_gb') - JSON_EXTRACT(specs, '$.disk_allocated_gb') >= ?
            ORDER BY JSON_EXTRACT(specs, '$.cpu_allocated') / NULLIF(JSON_EXTRACT(specs, '$.cpu_total'), 0) ASC
            LIMIT 1
        "#;
        // PHP 默认：cpu ?? 1, ram ?? 1, system_disk ?? 10
        let rows = self
            .db
            .query_with(
                sql,
                &[
                    serde_json::json!(region_id),
                    serde_json::json!(specs.cpu.unwrap_or(1)),
                    serde_json::json!(specs.ram.unwrap_or(1)),
                    serde_json::json!(specs.system_disk.unwrap_or(10)),
                ],
            )
            .await?;
        rows.first()
            .map(HostMachine::from_row)
            .ok_or_else(|| KvmError::Retryable("no suitable KVM host in region".into()))?
    }
}
