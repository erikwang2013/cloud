use ecat_data::RdbmsClient;
use ecat_lock::DistributedLock;
use serde_json::{Value, json};
use std::sync::Arc;
use std::time::Duration;

use crate::driver::{KvmDriver, KvmError};
use crate::model::{Disk, HostMachine, Specs, TaskParams, next_id};
use crate::orchestrator::ServiceOrchestrator;
use crate::selector::HostSelector;

/// KVM 提供商：与 PHP KvmProvider 对等。create 走区域锁；upgrade/resize_disk/
/// create_disk/create_ip 落 DB 记账（真机 virsh 命令 Phase 2）。
pub struct KvmProvider {
    db: Arc<dyn RdbmsClient>,
    lock: Arc<dyn DistributedLock>,
    selector: HostSelector,
    orchestrator: ServiceOrchestrator,
    driver: Arc<dyn KvmDriver>,
}

impl KvmProvider {
    pub fn new(
        db: Arc<dyn RdbmsClient>,
        lock: Arc<dyn DistributedLock>,
        driver: Arc<dyn KvmDriver>,
    ) -> Self {
        KvmProvider {
            selector: HostSelector::new(db.clone()),
            orchestrator: ServiceOrchestrator::new(db.clone()),
            db,
            lock,
            driver,
        }
    }

    pub async fn create(&self, task: &TaskParams) -> Result<Value, KvmError> {
        let region_id = task
            .region_id
            .ok_or_else(|| KvmError::Retryable("task.region_id required".into()))?;
        let resource_id = task
            .resource_id
            .ok_or_else(|| KvmError::Retryable("task.resource_id required".into()))?;

        let lock_key = format!("lock:provision:region:{region_id}:kvm");
        let token = self
            .lock
            .acquire(&lock_key, Duration::from_secs(300))
            .await
            .map_err(|e| KvmError::Retryable(format!("lock acquire: {e}")))?
            .ok_or_else(|| KvmError::Retryable("Provisioning in progress for this region".into()))?;

        let outcome = self.create_locked(resource_id, region_id, &task.specs()).await;
        // PHP finally：按 token 释放，释放失败不影响结果
        let _ = self.lock.release(&lock_key, &token).await;
        outcome
    }

    async fn create_locked(
        &self,
        resource_id: i64,
        region_id: i64,
        specs: &Specs,
    ) -> Result<Value, KvmError> {
        let exists = self
            .db
            .query_with("SELECT id FROM resources WHERE id = ?", &[json!(resource_id)])
            .await?;
        if exists.is_empty() {
            return Err(KvmError::Retryable(format!(
                "resource {resource_id} not found"
            )));
        }
        let host = self.selector.select(region_id, specs).await?;
        let info = self
            .orchestrator
            .provision(&host, resource_id, specs, &*self.driver)
            .await?;
        self.recalculate_host_allocation(&host).await?;
        Ok(serde_json::to_value(info)?)
    }

    /// 续期：expired_at 计算在 DB 侧完成（sqlx Any 不支持读取 DATETIME 列）。
    pub async fn renew(&self, resource_id: i64, months: i64) -> Result<Value, KvmError> {
        let n = self
            .db
            .execute_with(
                "UPDATE resources SET expired_at = DATE_ADD(COALESCE(expired_at, NOW()), INTERVAL ? MONTH) WHERE id = ?",
                &[json!(months), json!(resource_id)],
            )
            .await?;
        if n == 0 {
            return Err(KvmError::Retryable(format!(
                "resource {resource_id} not found"
            )));
        }
        Ok(json!({}))
    }

    /// 升级：骨架只落 DB 记账（PHP 同款 TODO）。
    pub async fn upgrade(
        &self,
        resource_id: i64,
        new_specs: &Value,
    ) -> Result<Value, KvmError> {
        let row = self
            .db
            .query_with("SELECT id, CAST(specs AS CHAR(1024)) AS specs FROM resources WHERE id = ?", &[json!(resource_id)])
            .await?
            .into_iter()
            .next()
            .ok_or_else(|| KvmError::Retryable("resource not found".into()))?;
        let specs_raw = row.get("specs").and_then(|v| v.as_str()).unwrap_or("{}");
        let mut specs: Value = serde_json::from_str(specs_raw)
            .map_err(|_| KvmError::Retryable(format!("invalid specs json for resource {resource_id}")))?;
        if let Some(cpu) = new_specs.get("cpu").and_then(|v| v.as_i64()) {
            specs["cpu"] = json!(cpu);
        }
        if let Some(ram) = new_specs.get("ram").and_then(|v| v.as_i64()) {
            specs["ram"] = json!(ram);
        }
        self.db
            .execute_with(
                "UPDATE resources SET specs = ? WHERE id = ?",
                &[json!(specs.to_string()), json!(resource_id)],
            )
            .await?;
        let host = self.host_of(resource_id).await?;
        self.recalculate_host_allocation(&host).await?;
        Ok(json!({}))
    }

    pub async fn destroy(&self, resource_id: i64) -> Result<Value, KvmError> {
        let host = self.host_of(resource_id).await?;
        self.orchestrator.release(resource_id, &*self.driver).await?;
        self.recalculate_host_allocation(&host).await?;
        Ok(json!({}))
    }

    /// status 不抛错：与 PHP 一致，失败返回 status=error。
    pub async fn status(&self, resource_id: i64) -> Value {
        let status = match self.status_inner(resource_id).await {
            Ok(s) => s,
            Err(e) => {
                tracing::warn!("KVM status failed for resource {resource_id}: {e}");
                "error".into()
            }
        };
        json!({ "status": status, "metrics": {} })
    }

    async fn status_inner(&self, resource_id: i64) -> Result<String, KvmError> {
        let disk = self.first_disk(resource_id).await?;
        let vm = disk.vm_id.filter(|v| !v.is_empty());
        match vm {
            Some(vm) => {
                let _ = self.host_by_id(disk.host_machine_id).await?;
                self.driver.status(&vm)
            }
            None => Ok("pending".into()),
        }
    }

    /// 真机 noVNC 接入留 Phase 2；URL 形状与 PHP 一致。
    pub async fn console_url(&self, resource_id: i64) -> Result<Value, KvmError> {
        let disk = self.first_disk(resource_id).await?;
        let host = self.host_by_id(disk.host_machine_id).await?;
        let vm = disk.vm_id.unwrap_or_else(|| resource_id.to_string());
        Ok(json!({
            "url": format!("https://{}:6080/vnc.html?vm={}", host.ip_address, vm)
        }))
    }

    /// 扩容：骨架只落记账（disk_resizes + disks.size_gb），真机 qemu-img 留 Phase 2。
    pub async fn resize_disk(
        &self,
        resource_id: i64,
        new_size_gb: i64,
    ) -> Result<Value, KvmError> {
        let disk = self.system_disk(resource_id).await?;
        self.db
            .execute_with(
                "INSERT INTO disk_resizes (id, disk_id, old_size_gb, new_size_gb, status, finished_at) VALUES (?,?,?,?,'completed',NOW())",
                &[json!(next_id()), json!(disk.id), json!(disk.size_gb), json!(new_size_gb)],
            )
            .await?;
        self.db
            .execute_with(
                "UPDATE disks SET size_gb = ? WHERE id = ?",
                &[json!(new_size_gb), json!(disk.id)],
            )
            .await?;
        let host = self.host_by_id(disk.host_machine_id).await?;
        self.recalculate_host_allocation(&host).await?;
        Ok(json!({}))
    }

    /// 加数据盘：骨架只落记录（device_path=vdb），真机 attach 留 Phase 2。
    pub async fn create_disk(&self, task: &TaskParams) -> Result<Value, KvmError> {
        let resource_id = task
            .resource_id
            .ok_or_else(|| KvmError::Retryable("task.resource_id required".into()))?;
        let size_gb = task
            .params
            .as_ref()
            .and_then(|p| p.get("size_gb"))
            .and_then(|v| v.as_i64())
            .ok_or_else(|| KvmError::Retryable("params.size_gb required".into()))?;
        let system = self.system_disk(resource_id).await?;
        self.db
            .execute_with(
                "INSERT INTO disks (id, resource_id, host_machine_id, vm_id, size_gb, disk_type, storage_pool, device_path, status) VALUES (?,?,?,?,?,'data',?,'vdb','active')",
                &[
                    json!(next_id()),
                    json!(resource_id),
                    json!(system.host_machine_id),
                    json!(system.vm_id.unwrap_or_default()),
                    json!(size_gb),
                    json!(system.storage_pool),
                ],
            )
            .await?;
        Ok(json!({ "device": "vdb" }))
    }

    /// 附加第二张网卡：骨架只分配 IP（真机挂网卡留 Phase 2）。
    pub async fn create_ip(&self, task: &TaskParams) -> Result<Value, KvmError> {
        let resource_id = task
            .resource_id
            .ok_or_else(|| KvmError::Retryable("task.resource_id required".into()))?;
        let disk = self.first_disk(resource_id).await?;
        let (ip, _) = self
            .orchestrator
            .allocate_ip(disk.host_machine_id, resource_id)
            .await?;
        Ok(json!({ "ip": ip.ip_address }))
    }

    async fn system_disk(&self, resource_id: i64) -> Result<Disk, KvmError> {
        self.disk_by("disk_type = 'system'", resource_id)
            .await?
            .ok_or_else(|| {
                KvmError::Retryable(format!("no system disk for resource {resource_id}"))
            })
    }

    async fn first_disk(&self, resource_id: i64) -> Result<Disk, KvmError> {
        self.disk_by("1=1", resource_id).await?.ok_or_else(|| {
            KvmError::Retryable(format!("no disk for resource {resource_id}"))
        })
    }

    async fn disk_by(&self, cond: &str, resource_id: i64) -> Result<Option<Disk>, KvmError> {
        let rows = self
            .db
            .query_with(
                &format!(
                    "SELECT id, resource_id, host_machine_id, vm_id, size_gb, storage_pool, status FROM disks WHERE resource_id = ? AND {cond} ORDER BY id LIMIT 1"
                ),
                &[json!(resource_id)],
            )
            .await?;
        Ok(rows.first().map(Disk::from_row).transpose()?)
    }

    async fn host_of(&self, resource_id: i64) -> Result<HostMachine, KvmError> {
        let disk = self.first_disk(resource_id).await?;
        self.host_by_id(disk.host_machine_id).await
    }

    async fn host_by_id(&self, host_id: i64) -> Result<HostMachine, KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT id, region_id, ip_address, storage_pool FROM host_machines WHERE id = ?",
                &[json!(host_id)],
            )
            .await?;
        rows.first()
            .map(HostMachine::from_row)
            .ok_or_else(|| KvmError::Retryable(format!("host {host_id} not found")))?
    }

    /// 与 PHP 相同的聚合重算：active 磁盘所属资源 specs 之和 + 磁盘之和，幂等。
    async fn recalculate_host_allocation(&self, host: &HostMachine) -> Result<(), KvmError> {
        let rows = self
            .db
            .query_with(
                "SELECT d.resource_id, d.size_gb, CAST(r.specs AS CHAR(1024)) AS specs FROM disks d LEFT JOIN resources r ON r.id = d.resource_id WHERE d.host_machine_id = ? AND d.status = 'active'",
                &[json!(host.id)],
            )
            .await?;
        let mut cpu = 0i64;
        let mut ram = 0i64;
        let mut disk_gb = 0i64;
        for row in &rows {
            // PHP 对孤儿磁盘同样计入 diskGb（sum 全部 activeDisks），故先累计再 continue
            disk_gb += row.get("size_gb").and_then(|v| v.as_i64()).unwrap_or(0);
            let Some(_) = row.get("resource_id").and_then(|v| v.as_i64()) else {
                continue; // LEFT JOIN 无对应 resource：PHP !$resource continue
            };
            if let Some(specs) = row
                .get("specs")
                .and_then(|v| v.as_str())
                .and_then(|s| serde_json::from_str::<Value>(s).ok())
            {
                cpu += specs.get("cpu").and_then(|v| v.as_i64()).unwrap_or(1);
                ram += specs.get("ram").and_then(|v| v.as_i64()).unwrap_or(2);
            }
        }

        let specs_row = self
            .db
            .query_with("SELECT CAST(specs AS CHAR(1024)) AS specs FROM host_machines WHERE id = ?", &[json!(host.id)])
            .await?;
        let mut host_specs: Value = specs_row
            .first()
            .and_then(|r| r.get("specs"))
            .and_then(|v| v.as_str())
            .and_then(|s| serde_json::from_str(s).ok())
            .unwrap_or_else(|| json!({}));
        if let Value::Object(m) = &mut host_specs {
            m.insert("cpu_allocated".into(), json!(cpu));
            m.insert("ram_allocated_gb".into(), json!(ram));
            m.insert("disk_allocated_gb".into(), json!(disk_gb));
        }
        self.db
            .execute_with(
                "UPDATE host_machines SET specs = ? WHERE id = ?",
                &[json!(host_specs.to_string()), json!(host.id)],
            )
            .await?;
        Ok(())
    }
}
