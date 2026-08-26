use ecat_data::RdbmsClient;
use serde_json::json;
use std::sync::Arc;

use crate::driver::{KvmDriver, KvmError};
use crate::model::{
    Disk, FirewallService, FirewallRule, HostMachine, IpAllocation, IpPool, NetworkService,
    ProvisionInfo, Specs, SwitchService, next_id,
};
use crate::naming;

/// KVM 服务编排：与 PHP ServiceOrchestrator 对等。
/// 事务适配：ecat-data 的 Transaction 无法承载查询，故用"单条条件 UPDATE 原子性 +
/// UNIQUE 冲突重试 + 失败补偿"替代行锁事务（见 allocate_ip），服务记录插入失败与
/// 驱动失败均走 cleanup 释放，终态与 PHP 事务回滚一致。
pub struct ServiceOrchestrator {
    db: Arc<dyn RdbmsClient>,
}

impl ServiceOrchestrator {
    pub fn new(db: Arc<dyn RdbmsClient>) -> Self {
        ServiceOrchestrator { db }
    }

    pub async fn provision(
        &self,
        host: &HostMachine,
        resource_id: i64,
        specs: &Specs,
        driver: &dyn KvmDriver,
    ) -> Result<ProvisionInfo, KvmError> {
        let vm_id = naming::vm_id(resource_id);
        let bridge = naming::bridge(resource_id);
        let fw_table = naming::fw_table(resource_id);
        let veth_host = naming::veth_host(resource_id);
        let veth_guest = naming::veth_guest(resource_id);
        let rules = default_rules();
        let disk_size = specs.system_disk.unwrap_or(20);
        let mac = naming::mac_from_id(resource_id);

        let (ip, gateway) = self.allocate_ip(host.id, resource_id).await?;

        if let Err(e) = self
            .insert_creating(
                host,
                resource_id,
                &vm_id,
                &bridge,
                &fw_table,
                &veth_host,
                &veth_guest,
                &mac,
                &gateway,
                &rules,
                disk_size,
            )
            .await
        {
            self.cleanup(resource_id, driver).await;
            return Err(e);
        }

        let driver_result = (|| {
            driver.create_bridge(&bridge)?;
            driver.create_veth(&veth_host, &veth_guest, &bridge, &mac)?;
            let created = driver.create_vm(crate::driver::VmSpec {
                vm_id: vm_id.clone(),
                cpu: specs.cpu.unwrap_or(2),
                ram: specs.ram.unwrap_or(2) * 1024,
                mac: mac.clone(),
                bridge: bridge.clone(),
            })?;
            driver.attach_disk(&created, &format!("/var/lib/libvirt/images/{vm_id}.qcow2"), disk_size)?;
            driver.apply_firewall(&fw_table, "drop", &rules)?;
            driver.start_vm(&created)?;
            Ok::<String, KvmError>(created)
        })();
        if let Err(e) = driver_result {
            self.cleanup(resource_id, driver).await;
            return Err(e);
        }

        self.mark_active(resource_id).await?;

        Ok(ProvisionInfo {
            vm_id,
            ip_address: ip.ip_address,
            bridge,
        })
    }

    /// 释放 VM 全部服务：驱动清理（失败向上传播，DB 留待重试）+ 记录删除 + IP/磁盘释放。
    pub async fn release(&self, resource_id: i64, driver: &dyn KvmDriver) -> Result<(), KvmError> {
        let net = self.network_service(resource_id).await.ok();
        let fw = self.firewall_service(resource_id).await.ok();
        let sw = self.switch_service(resource_id).await.ok();
        let disk = self.disk(resource_id).await.ok();

        if let Some(sw) = &sw {
            driver.remove_veth(&sw.veth_host)?;
        }
        if let Some(net) = &net {
            driver.remove_bridge(&net.bridge_name)?;
        }
        if let Some(fw) = &fw {
            driver.remove_firewall(&fw.table_name)?;
        }
        if let Some(disk) = &disk {
            if let Some(vm) = &disk.vm_id {
                driver.destroy_vm(vm)?;
            }
        }

        self.release_db(resource_id).await
    }

    /// IP 分配：条件自增（InnoDB 原子）→ 线性挑可用地址 → 插入；UNIQUE 冲突回滚
    /// 自增并重试，≤3 次。语义与 PHP FOR UPDATE 事务等价。
    pub async fn allocate_ip(
        &self,
        host_id: i64,
        resource_id: i64,
    ) -> Result<(IpAllocation, String), KvmError> {
        for _ in 0..3 {
            let pool = self.pick_pool(host_id).await?;
            let updated = self
                .db
                .execute_with(
                    "UPDATE ip_pools SET used_count = used_count + 1 WHERE id = ? AND used_count < total_count",
                    &[json!(pool.id)],
                )
                .await?;
            if updated == 0 {
                continue; // 并发竞争者抢先耗尽，换下一轮
            }
            let allocated = self.allocated_ips(pool.id).await?;
            let Some(ip) = pick_ip(&pool.ip_start, &pool.ip_end, &allocated) else {
                self.decrement_pool(pool.id).await;
                return Err(KvmError::Retryable("no available IP in pool".into()));
            };
            let inserted = self
                .db
                .execute_with(
                    "INSERT INTO ip_allocations (id, ip_pool_id, resource_id, ip_address, type) VALUES (?,?,?,?,'primary')",
                    &[json!(next_id()), json!(pool.id), json!(resource_id), json!(ip)],
                )
                .await
                .map_err(KvmError::from);
            match inserted {
                Ok(_) => {
                    return Ok((
                        IpAllocation {
                            ip_pool_id: pool.id,
                            resource_id,
                            ip_address: ip,
                        },
                        pool.gateway,
                    ))
                }
                Err(e) if is_duplicate(&e) => {
                    self.decrement_pool(pool.id).await;
                    continue;
                }
                Err(e) => {
                    self.decrement_pool(pool.id).await;
                    return Err(e);
                }
            }
        }
        Err(KvmError::Retryable(
            "ip allocation conflict after 3 attempts".into(),
        ))
    }

    async fn insert_creating(
        &self,
        host: &HostMachine,
        resource_id: i64,
        vm_id: &str,
        bridge: &str,
        fw_table: &str,
        veth_host: &str,
        veth_guest: &str,
        mac: &str,
        gateway: &str,
        rules: &[FirewallRule],
        disk_size: i64,
    ) -> Result<(), KvmError> {
        let net_id = next_id();
        self.db
            .execute_with(
                "INSERT INTO network_services (id, host_machine_id, resource_id, vm_id, bridge_name, subnet, gateway_ip, status) VALUES (?,?,?,?,?,NULL,?,'creating')",
                &[json!(net_id), json!(host.id), json!(resource_id), json!(vm_id), json!(bridge), json!(gateway)],
            )
            .await?;
        self.db
            .execute_with(
                "INSERT INTO firewall_services (id, host_machine_id, resource_id, vm_id, table_name, default_policy, rules, status) VALUES (?,?,?,?,?,'drop',?,'creating')",
                &[json!(next_id()), json!(host.id), json!(resource_id), json!(vm_id), json!(fw_table), json!(serde_json::to_string(rules)?)],
            )
            .await?;
        self.db
            .execute_with(
                "INSERT INTO switch_services (id, host_machine_id, resource_id, vm_id, network_service_id, veth_host, veth_guest, mac_address, status) VALUES (?,?,?,?,?,?,?,?,'creating')",
                &[json!(next_id()), json!(host.id), json!(resource_id), json!(vm_id), json!(net_id), json!(veth_host), json!(veth_guest), json!(mac)],
            )
            .await?;
        self.db
            .execute_with(
                "INSERT INTO disks (id, resource_id, host_machine_id, vm_id, size_gb, disk_type, storage_pool, device_path, status) VALUES (?,?,?,?,?,'system',?,'vda','creating')",
                &[json!(next_id()), json!(resource_id), json!(host.id), json!(vm_id), json!(disk_size), json!(host.storage_pool)],
            )
            .await?;
        Ok(())
    }

    async fn mark_active(&self, resource_id: i64) -> Result<(), KvmError> {
        self.db
            .execute_with("UPDATE network_services SET status = 'active' WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        self.db
            .execute_with("UPDATE firewall_services SET status = 'active' WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        self.db
            .execute_with("UPDATE switch_services SET status = 'active' WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        self.db
            .execute_with("UPDATE disks SET status = 'active' WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        Ok(())
    }

    async fn release_db(&self, resource_id: i64) -> Result<(), KvmError> {
        self.db
            .execute_with(
                "UPDATE ip_allocations SET released_at = NOW() WHERE resource_id = ? AND released_at IS NULL",
                &[json!(resource_id)],
            )
            .await?;
        self.db
            .execute_with("UPDATE disks SET status = 'destroyed' WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        self.db
            .execute_with("DELETE FROM network_services WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        self.db
            .execute_with("DELETE FROM firewall_services WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        self.db
            .execute_with("DELETE FROM switch_services WHERE resource_id = ?", &[json!(resource_id)])
            .await?;
        Ok(())
    }

    async fn cleanup(&self, resource_id: i64, driver: &dyn KvmDriver) {
        // 尽力而为：外层已捕获原异常转 retryable
        if let Err(e) = self.release(resource_id, driver).await {
            tracing::warn!("KVM cleanup failed for resource {resource_id}: {e}");
        }
    }

    async fn pick_pool(&self, host_id: i64) -> Result<IpPool, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT id, host_machine_id, ip_start, ip_end, gateway FROM ip_pools WHERE host_machine_id = ? AND used_count < total_count ORDER BY id LIMIT 1",
                &[json!(host_id)],
            )
            .await?;
        rows.first()
            .map(IpPool::from_row)
            .transpose()?
            .ok_or_else(|| KvmError::Retryable("no IP pool available".into()))
    }

    async fn allocated_ips(&self, pool_id: i64) -> Result<Vec<String>, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT ip_address FROM ip_allocations WHERE ip_pool_id = ? AND released_at IS NULL",
                &[json!(pool_id)],
            )
            .await?;
        Ok(rows
            .iter()
            .filter_map(|r| r.get("ip_address").and_then(|v| v.as_str()))
            .map(|s| s.to_string())
            .collect())
    }

    async fn decrement_pool(&self, pool_id: i64) {
        let _ = self
            .db
            .execute_with(
                "UPDATE ip_pools SET used_count = used_count - 1 WHERE id = ?",
                &[json!(pool_id)],
            )
            .await;
    }

    pub(crate) async fn network_service(&self, resource_id: i64) -> Result<NetworkService, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT id, bridge_name FROM network_services WHERE resource_id = ? ORDER BY id LIMIT 1",
                &[json!(resource_id)],
            )
            .await?;
        rows.first()
            .map(NetworkService::from_row)
            .transpose()?
            .ok_or_else(|| KvmError::Retryable("network service not found".into()))
    }

    pub(crate) async fn firewall_service(&self, resource_id: i64) -> Result<FirewallService, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT id, table_name FROM firewall_services WHERE resource_id = ? ORDER BY id LIMIT 1",
                &[json!(resource_id)],
            )
            .await?;
        rows.first()
            .map(FirewallService::from_row)
            .transpose()?
            .ok_or_else(|| KvmError::Retryable("firewall service not found".into()))
    }

    pub(crate) async fn switch_service(&self, resource_id: i64) -> Result<SwitchService, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT id, veth_host FROM switch_services WHERE resource_id = ? ORDER BY id LIMIT 1",
                &[json!(resource_id)],
            )
            .await?;
        rows.first()
            .map(SwitchService::from_row)
            .transpose()?
            .ok_or_else(|| KvmError::Retryable("switch service not found".into()))
    }

    pub(crate) async fn disk(&self, resource_id: i64) -> Result<Disk, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT id, resource_id, host_machine_id, vm_id, size_gb, storage_pool, status FROM disks WHERE resource_id = ? ORDER BY id LIMIT 1",
                &[json!(resource_id)],
            )
            .await?;
        rows.first()
            .map(Disk::from_row)
            .transpose()?
            .ok_or_else(|| KvmError::Retryable("disk not found".into()))
    }
}

/// 线性挑 IP：起始→结束，跳过已分配。纯函数便于单元测试。
pub fn pick_ip(start: &str, end: &str, allocated: &[String]) -> Option<String> {
    let start_u = ip_to_u32(start)?;
    let end_u = ip_to_u32(end)?;
    for ip in start_u..=end_u {
        let s = u32_to_ip(ip);
        if !allocated.contains(&s) {
            return Some(s);
        }
    }
    None
}

pub fn default_rules() -> Vec<FirewallRule> {
    vec![
        FirewallRule {
            direction: "inbound".into(),
            protocol: "tcp".into(),
            port: Some(22),
            cidr: "0.0.0.0/0".into(),
            action: "accept".into(),
            state: None,
        },
        FirewallRule {
            direction: "inbound".into(),
            protocol: "tcp".into(),
            port: None,
            cidr: "0.0.0.0/0".into(),
            action: "accept".into(),
            state: Some("established,related".into()),
        },
    ]
}

fn ip_to_u32(s: &str) -> Option<u32> {
    let parts: Vec<&str> = s.split('.').collect();
    if parts.len() != 4 {
        return None;
    }
    let mut v: u32 = 0;
    for p in parts {
        let octet = p.parse::<u32>().ok()?;
        if octet > 255 {
            return None;
        }
        v = (v << 8) | octet;
    }
    Some(v)
}

fn u32_to_ip(v: u32) -> String {
    format!(
        "{}.{}.{}.{}",
        (v >> 24) & 0xff,
        (v >> 16) & 0xff,
        (v >> 8) & 0xff,
        v & 0xff
    )
}

fn is_duplicate(e: &KvmError) -> bool {
    matches!(e, KvmError::Retryable(m) if m.contains("1062"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn pick_ip_allocates_sequentially_from_start() {
        let alloc = vec![];
        assert_eq!(pick_ip("10.0.0.10", "10.0.0.20", &alloc), Some("10.0.0.10".into()));
        assert_eq!(pick_ip("10.0.0.10", "10.0.0.20", &["10.0.0.10".into(), "10.0.0.11".into()]), Some("10.0.0.12".into()));
    }

    #[test]
    fn pick_ip_returns_none_when_pool_exhausted() {
        let alloc = (10..=20).map(|i| format!("10.0.0.{i}")).collect::<Vec<_>>();
        assert_eq!(pick_ip("10.0.0.10", "10.0.0.20", &alloc), None);
    }

    #[test]
    fn ip_to_u32_rejects_out_of_range_octets() {
        assert_eq!(ip_to_u32("10.0.0.256"), None);
        assert_eq!(ip_to_u32("10.0.300.1"), None);
        assert_eq!(ip_to_u32("10.0.0.-1"), None);
        assert_eq!(ip_to_u32("10.0.0.abc"), None);
        assert_eq!(ip_to_u32("10.0.0"), None);
    }

    #[test]
    fn ip_to_u32_accepts_boundary_octets() {
        assert_eq!(ip_to_u32("0.0.0.0"), Some(0));
        assert_eq!(ip_to_u32("255.255.255.255"), Some(0xffff_ffff));
        assert_eq!(ip_to_u32("10.0.0.255"), Some(0x0a00_00ff));
    }

    #[test]
    fn default_rules_match_php() {
        let rules = default_rules();
        assert_eq!(rules.len(), 2);
        assert_eq!(rules[0].port, Some(22));
        assert_eq!(rules[0].state, None);
        assert_eq!(rules[1].port, None);
        assert_eq!(rules[1].state.as_deref(), Some("established,related"));
        assert!(rules
            .iter()
            .all(|r| r.direction == "inbound" && r.protocol == "tcp" && r.action == "accept"));
    }

    #[test]
    fn duplicate_detection() {
        assert!(is_duplicate(&KvmError::Retryable(
            "db: error returned from database: 1062 (23000): Duplicate entry '10.0.0.10' for key 'ip_allocations.uk_ip'".into()
        )));
        assert!(!is_duplicate(&KvmError::Retryable("db: something else".into())));
    }
}
