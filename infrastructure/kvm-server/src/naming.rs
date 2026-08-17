//! 命名隔离：每 VM 独立的 bridge/veth/fw 表命名，与 PHP 骨架逐字一致。

pub fn vm_id(resource_id: i64) -> String {
    format!("kvm-{resource_id}")
}

pub fn bridge(resource_id: i64) -> String {
    format!("br-vm{resource_id}")
}

pub fn fw_table(resource_id: i64) -> String {
    format!("fw-vm{resource_id}")
}

pub fn veth_host(resource_id: i64) -> String {
    format!("veth{resource_id}a")
}

pub fn veth_guest(resource_id: i64) -> String {
    format!("veth{resource_id}b")
}

/// 与 PHP `sprintf('02:00:00:%02x:%02x:%02x', ...)` 一致。
pub fn mac_from_id(resource_id: i64) -> String {
    format!(
        "02:00:00:{:02x}:{:02x}:{:02x}",
        ((resource_id >> 16) & 0xff) as u8,
        ((resource_id >> 8) & 0xff) as u8,
        (resource_id & 0xff) as u8
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_are_isolated_per_resource() {
        assert_eq!(vm_id(42), "kvm-42");
        assert_eq!(bridge(42), "br-vm42");
        assert_eq!(fw_table(42), "fw-vm42");
        assert_eq!(veth_host(42), "veth42a");
        assert_eq!(veth_guest(42), "veth42b");
        assert_ne!(bridge(41), bridge(42));
        assert_ne!(veth_host(41), veth_host(42));
    }

    #[test]
    fn mac_matches_php_sprintf() {
        assert_eq!(mac_from_id(0x123456), "02:00:00:12:34:56");
        assert_eq!(mac_from_id(1), "02:00:00:00:00:01");
        assert_eq!(mac_from_id(0xabcdef), "02:00:00:ab:cd:ef");
    }
}
