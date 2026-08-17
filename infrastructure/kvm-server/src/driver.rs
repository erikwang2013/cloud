use serde::{Deserialize, Serialize};
use std::collections::{HashMap, HashSet};
use std::sync::Mutex;

use crate::model::FirewallRule;

/// 驱动错误：NotImplemented 对应 PHP VirshDriver 的 TODO 抛错，Retryable 对应
/// PHP 骨架中捕获后转 retryable 的异常。
#[derive(Debug, thiserror::Error)]
pub enum KvmError {
    #[error("not implemented (Phase 2): {0}")]
    NotImplemented(String),
    #[error("{0}")]
    Retryable(String),
    #[error("{0}")]
    Failed(String),
}

impl From<ecat_data::RdbmsError> for KvmError {
    fn from(e: ecat_data::RdbmsError) -> Self {
        KvmError::Retryable(format!("db: {e}"))
    }
}

impl From<serde_json::Error> for KvmError {
    fn from(e: serde_json::Error) -> Self {
        KvmError::Retryable(format!("json: {e}"))
    }
}

/// PHP KvmDriverInterface 11 方法的对等移植。
pub trait KvmDriver: Send + Sync {
    fn create_vm(&self, spec: VmSpec) -> Result<String, KvmError>;
    fn create_bridge(&self, name: &str) -> Result<(), KvmError>;
    fn create_veth(&self, host: &str, guest: &str, bridge: &str, mac: &str) -> Result<(), KvmError>;
    fn attach_disk(&self, vm_id: &str, device_path: &str, size_gb: i64) -> Result<(), KvmError>;
    fn start_vm(&self, vm_id: &str) -> Result<(), KvmError>;
    fn apply_firewall(&self, table: &str, policy: &str, rules: &[FirewallRule]) -> Result<(), KvmError>;
    fn destroy_vm(&self, vm_id: &str) -> Result<(), KvmError>;
    fn remove_bridge(&self, name: &str) -> Result<(), KvmError>;
    fn remove_veth(&self, host: &str) -> Result<(), KvmError>;
    fn remove_firewall(&self, table: &str) -> Result<(), KvmError>;
    /// running/stopped/pending/error
    fn status(&self, vm_id: &str) -> Result<String, KvmError>;
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VmSpec {
    pub vm_id: String,
    pub cpu: i64,
    /// MB
    pub ram: i64,
    pub mac: String,
    pub bridge: String,
}

/// 内存态模拟驱动：与 PHP SimulatedKvmDriver 状态形状一致，供测试与本地全流程验证。
pub struct SimulatedKvmDriver {
    state: Mutex<SimState>,
    fail_on: Mutex<HashSet<&'static str>>,
}

#[derive(Debug, Clone, Default)]
pub struct SimState {
    pub calls: Vec<String>,
    pub bridges: HashSet<String>,
    pub veths: HashMap<String, VethState>,
    pub vms: HashMap<String, VmState>,
    pub tables: HashMap<String, TableState>,
}

#[derive(Debug, Clone)]
pub struct VethState {
    pub guest: String,
    pub bridge: String,
    pub mac: String,
}

#[derive(Debug, Clone)]
pub struct VmState {
    pub spec: VmSpec,
    pub status: String,
}

#[derive(Debug, Clone)]
pub struct TableState {
    pub policy: String,
    pub rules: Vec<FirewallRule>,
}

impl SimulatedKvmDriver {
    pub fn new() -> Self {
        SimulatedKvmDriver {
            state: Mutex::new(SimState::default()),
            fail_on: Mutex::new(HashSet::new()),
        }
    }

    /// 指定方法名后该驱动调用即失败（回滚测试用）。
    pub fn fail_on(&self, method: &'static str) {
        self.fail_on.lock().unwrap().insert(method);
    }

    pub fn state(&self) -> SimState {
        self.state.lock().unwrap().clone()
    }

    fn maybe_fail(&self, method: &'static str) -> Result<(), KvmError> {
        if self.fail_on.lock().unwrap().contains(method) {
            return Err(KvmError::Retryable(format!("simulated failure at {method}")));
        }
        Ok(())
    }

    fn record(&self, call: String) {
        self.state.lock().unwrap().calls.push(call);
    }
}

impl Default for SimulatedKvmDriver {
    fn default() -> Self {
        Self::new()
    }
}

impl KvmDriver for SimulatedKvmDriver {
    fn create_vm(&self, spec: VmSpec) -> Result<String, KvmError> {
        self.maybe_fail("create_vm")?;
        self.state.lock().unwrap().vms.insert(
            spec.vm_id.clone(),
            VmState {
                spec: spec.clone(),
                status: "stopped".into(),
            },
        );
        self.record(format!("createVm({})", spec.vm_id));
        Ok(spec.vm_id)
    }

    fn create_bridge(&self, name: &str) -> Result<(), KvmError> {
        self.maybe_fail("create_bridge")?;
        self.state.lock().unwrap().bridges.insert(name.to_string());
        self.record(format!("createBridge({name})"));
        Ok(())
    }

    fn create_veth(&self, host: &str, guest: &str, bridge: &str, mac: &str) -> Result<(), KvmError> {
        self.maybe_fail("create_veth")?;
        self.state.lock().unwrap().veths.insert(
            host.to_string(),
            VethState {
                guest: guest.to_string(),
                bridge: bridge.to_string(),
                mac: mac.to_string(),
            },
        );
        self.record(format!("createVeth({host},{guest},{bridge},{mac})"));
        Ok(())
    }

    fn attach_disk(&self, vm_id: &str, device_path: &str, size_gb: i64) -> Result<(), KvmError> {
        self.maybe_fail("attach_disk")?;
        self.record(format!("attachDisk({vm_id},{device_path},{size_gb})"));
        Ok(())
    }

    fn start_vm(&self, vm_id: &str) -> Result<(), KvmError> {
        self.maybe_fail("start_vm")?;
        if let Some(vm) = self.state.lock().unwrap().vms.get_mut(vm_id) {
            vm.status = "running".into();
        }
        self.record(format!("startVm({vm_id})"));
        Ok(())
    }

    fn apply_firewall(&self, table: &str, policy: &str, rules: &[FirewallRule]) -> Result<(), KvmError> {
        self.maybe_fail("apply_firewall")?;
        self.state.lock().unwrap().tables.insert(
            table.to_string(),
            TableState {
                policy: policy.to_string(),
                rules: rules.to_vec(),
            },
        );
        self.record(format!("applyFirewall({table},{policy})"));
        Ok(())
    }

    fn destroy_vm(&self, vm_id: &str) -> Result<(), KvmError> {
        self.maybe_fail("destroy_vm")?;
        if let Some(vm) = self.state.lock().unwrap().vms.get_mut(vm_id) {
            vm.status = "destroyed".into();
        }
        self.record(format!("destroyVm({vm_id})"));
        Ok(())
    }

    fn remove_bridge(&self, name: &str) -> Result<(), KvmError> {
        self.maybe_fail("remove_bridge")?;
        self.state.lock().unwrap().bridges.remove(name);
        self.record(format!("removeBridge({name})"));
        Ok(())
    }

    fn remove_veth(&self, host: &str) -> Result<(), KvmError> {
        self.maybe_fail("remove_veth")?;
        self.state.lock().unwrap().veths.remove(host);
        self.record(format!("removeVeth({host})"));
        Ok(())
    }

    fn remove_firewall(&self, table: &str) -> Result<(), KvmError> {
        self.maybe_fail("remove_firewall")?;
        self.state.lock().unwrap().tables.remove(table);
        self.record(format!("removeFirewall({table})"));
        Ok(())
    }

    fn status(&self, vm_id: &str) -> Result<String, KvmError> {
        let st = self
            .state
            .lock()
            .unwrap()
            .vms
            .get(vm_id)
            .map(|v| v.status.clone())
            .unwrap_or_else(|| "error".into());
        Ok(st)
    }
}

/// libvirt (virsh) 真机驱动骨架：与 PHP VirshDriver 一致，全部方法留 TODO。
pub struct VirshDriver;

impl VirshDriver {
    pub fn new() -> Self {
        VirshDriver
    }
}

impl Default for VirshDriver {
    fn default() -> Self {
        Self::new()
    }
}

impl KvmDriver for VirshDriver {
    fn create_vm(&self, _spec: VmSpec) -> Result<String, KvmError> {
        // TODO: virsh define <vm.xml>（spec: cpu/ram/disk/vmId/mac/bridge）
        Err(KvmError::NotImplemented("VirshDriver::createVm not implemented (Phase 2)".into()))
    }
    fn create_bridge(&self, _name: &str) -> Result<(), KvmError> {
        // TODO: ip link add {name} type bridge && ip addr add {subnet}/24 dev {name}
        Err(KvmError::NotImplemented("VirshDriver::createBridge not implemented (Phase 2)".into()))
    }
    fn create_veth(&self, _host: &str, _guest: &str, _bridge: &str, _mac: &str) -> Result<(), KvmError> {
        // TODO: ip link add {host} type veth peer name {guest} && ip link set {host} master {bridge}
        Err(KvmError::NotImplemented("VirshDriver::createVeth not implemented (Phase 2)".into()))
    }
    fn attach_disk(&self, _vm_id: &str, _device_path: &str, _size_gb: i64) -> Result<(), KvmError> {
        // TODO: qemu-img create -f qcow2 {path} {sizeGb}G && virsh attach-disk {vmId}
        Err(KvmError::NotImplemented("VirshDriver::attachDisk not implemented (Phase 2)".into()))
    }
    fn start_vm(&self, _vm_id: &str) -> Result<(), KvmError> {
        // TODO: virsh start {vmId}
        Err(KvmError::NotImplemented("VirshDriver::startVm not implemented (Phase 2)".into()))
    }
    fn apply_firewall(&self, _table: &str, _policy: &str, _rules: &[FirewallRule]) -> Result<(), KvmError> {
        // TODO: nft add table inet {table} && nft add chain ... (per-VM 表 = 隔离)
        Err(KvmError::NotImplemented("VirshDriver::applyFirewall not implemented (Phase 2)".into()))
    }
    fn destroy_vm(&self, _vm_id: &str) -> Result<(), KvmError> {
        // TODO: virsh destroy {vmId} && virsh undefine {vmId}
        Err(KvmError::NotImplemented("VirshDriver::destroyVm not implemented (Phase 2)".into()))
    }
    fn remove_bridge(&self, _name: &str) -> Result<(), KvmError> {
        // TODO: ip link del {name}
        Err(KvmError::NotImplemented("VirshDriver::removeBridge not implemented (Phase 2)".into()))
    }
    fn remove_veth(&self, _host: &str) -> Result<(), KvmError> {
        // TODO: ip link del {host}
        Err(KvmError::NotImplemented("VirshDriver::removeVeth not implemented (Phase 2)".into()))
    }
    fn remove_firewall(&self, _table: &str) -> Result<(), KvmError> {
        // TODO: nft delete table inet {table}
        Err(KvmError::NotImplemented("VirshDriver::removeFirewall not implemented (Phase 2)".into()))
    }
    fn status(&self, _vm_id: &str) -> Result<String, KvmError> {
        // TODO: virsh domstate {vmId}
        Err(KvmError::NotImplemented("VirshDriver::status not implemented (Phase 2)".into()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn simulated_driver_tracks_state_and_calls() {
        let d = SimulatedKvmDriver::new();
        let vm = d
            .create_vm(VmSpec {
                vm_id: "kvm-1".into(),
                cpu: 2,
                ram: 2048,
                mac: "02:00:00:00:00:01".into(),
                bridge: "br-vm1".into(),
            })
            .unwrap();
        assert_eq!(vm, "kvm-1");
        d.start_vm("kvm-1").unwrap();
        let st = d.state();
        assert_eq!(st.vms["kvm-1"].status, "running");
        assert_eq!(d.status("kvm-1").unwrap(), "running");
        assert_eq!(d.status("missing").unwrap(), "error");
        assert_eq!(
            st.calls,
            vec!["createVm(kvm-1)", "startVm(kvm-1)"]
        );
    }

    #[test]
    fn simulated_driver_fail_on_method() {
        let d = SimulatedKvmDriver::new();
        d.fail_on("start_vm");
        d.create_bridge("br-vm1").unwrap();
        assert!(d.start_vm("kvm-1").is_err());
    }

    #[test]
    fn virsh_driver_returns_not_implemented() {
        let d = VirshDriver::new();
        assert!(matches!(
            d.status("kvm-1"),
            Err(KvmError::NotImplemented(_))
        ));
    }
}
