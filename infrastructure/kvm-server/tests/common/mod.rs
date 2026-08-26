// 共享测试桩：脚本化内存 DB（按 SQL 子串路由）与内存分布式锁。
#![allow(dead_code)]
use async_trait::async_trait;
use ecat_data::{RdbmsClient, RdbmsError, Row, Transaction};
use ecat_lock::{DistributedLock, LockError};
use serde_json::{Value, json};
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

pub enum Outcome {
    Rows(Vec<Row>),
    Affected(u64),
}

pub fn row(pairs: &[(&str, Value)]) -> Row {
    Row::new(
        pairs.iter().map(|(c, _)| c.to_string()).collect(),
        pairs.iter().map(|(_, v)| v.clone()).collect(),
    )
}

/// 按 SQL 子串路由的内存 DB。未匹配任何路由的语句返回错误，让测试显式声明所有依赖。
pub struct MockDb {
    routes: Mutex<
        Vec<(
            String,
            Box<dyn Fn(&str, &[Value]) -> Result<Outcome, RdbmsError> + Send + Sync>,
        )>,
    >,
    pub executed: Mutex<Vec<String>>,
    pub params: Mutex<Vec<Vec<Value>>>,
}

impl MockDb {
    pub fn new() -> Arc<Self> {
        Arc::new(MockDb {
            routes: Mutex::new(Vec::new()),
            executed: Mutex::new(Vec::new()),
            params: Mutex::new(Vec::new()),
        })
    }

    pub fn route(
        &self,
        sql_sub: &str,
        handler: impl Fn(&str, &[Value]) -> Result<Outcome, RdbmsError> + Send + Sync + 'static,
    ) {
        self.routes.lock().unwrap().push((sql_sub.into(), Box::new(handler)));
    }

    pub fn rows(&self, sql_sub: &str, rows: Vec<Row>) {
        self.route(sql_sub, move |_, _| Ok(Outcome::Rows(rows.clone())));
    }

    pub fn affected(&self, sql_sub: &str, n: u64) {
        self.route(sql_sub, move |_, _| Ok(Outcome::Affected(n)));
    }

    pub fn fail(&self, sql_sub: &str, msg: &str) {
        let msg = msg.to_string();
        self.route(sql_sub, move |_, _| {
            Err(RdbmsError::Database(msg.clone()))
        });
    }

    pub fn count(&self, sub: &str) -> usize {
        self.executed
            .lock()
            .unwrap()
            .iter()
            .filter(|s| s.contains(sub))
            .count()
    }

    pub fn contains(&self, sub: &str) -> bool {
        self.count(sub) > 0
    }

    fn handle(&self, sql: &str, params: &[Value]) -> Result<Outcome, RdbmsError> {
        let routes = self.routes.lock().unwrap();
        routes
            .iter()
            .find(|(sub, _)| sql.contains(sub.as_str()))
            .map(|(_, h)| h(sql, params))
            .unwrap_or_else(|| Err(RdbmsError::Database(format!("no route for sql: {sql}"))))
    }
}

#[async_trait]
impl RdbmsClient for MockDb {
    async fn execute(&self, sql: &str) -> Result<u64, RdbmsError> {
        self.executed.lock().unwrap().push(sql.to_string());
        match self.handle(sql, &[])? {
            Outcome::Affected(n) => Ok(n),
            _ => Err(RdbmsError::Database("expected affected row count".into())),
        }
    }

    async fn query(&self, sql: &str) -> Result<Vec<Row>, RdbmsError> {
        self.executed.lock().unwrap().push(sql.to_string());
        match self.handle(sql, &[])? {
            Outcome::Rows(rows) => Ok(rows),
            _ => Err(RdbmsError::Database("expected rows".into())),
        }
    }

    async fn execute_with(&self, sql: &str, params: &[Value]) -> Result<u64, RdbmsError> {
        self.executed.lock().unwrap().push(sql.to_string());
        self.params.lock().unwrap().push(params.to_vec());
        match self.handle(sql, params)? {
            Outcome::Affected(n) => Ok(n),
            _ => Err(RdbmsError::Database("expected affected row count".into())),
        }
    }

    async fn query_with(&self, sql: &str, params: &[Value]) -> Result<Vec<Row>, RdbmsError> {
        self.executed.lock().unwrap().push(sql.to_string());
        self.params.lock().unwrap().push(params.to_vec());
        match self.handle(sql, params)? {
            Outcome::Rows(rows) => Ok(rows),
            _ => Err(RdbmsError::Database("expected rows".into())),
        }
    }

    async fn transaction(&self) -> Result<Transaction, RdbmsError> {
        Err(RdbmsError::Database("transactions not supported in MockDb".into()))
    }
}

/// 与 ecat-lock 测试同语义的内存锁：token 匹配才能释放，TTL 过期后可重新获取。
pub struct MemoryLock {
    held: tokio::sync::Mutex<HashMap<String, (String, Instant)>>,
}

impl MemoryLock {
    pub fn new() -> Arc<Self> {
        Arc::new(MemoryLock {
            held: tokio::sync::Mutex::new(HashMap::new()),
        })
    }

    pub async fn is_held(&self, key: &str) -> bool {
        self.held.lock().await.contains_key(key)
    }
}

#[async_trait]
impl DistributedLock for MemoryLock {
    async fn acquire(&self, key: &str, ttl: Duration) -> Result<Option<String>, LockError> {
        let mut held = self.held.lock().await;
        if let Some((_, expires)) = held.get(key)
            && *expires > Instant::now()
        {
            return Ok(None);
        }
        let token = format!("tok-{key}");
        held.insert(key.into(), (token.clone(), Instant::now() + ttl));
        Ok(Some(token))
    }

    async fn release(&self, key: &str, token: &str) -> Result<(), LockError> {
        let mut held = self.held.lock().await;
        match held.get(key) {
            Some((t, _)) if t == token => {
                held.remove(key);
                Ok(())
            }
            Some(_) => Err(LockError::Other("token mismatch".into())),
            None => Err(LockError::Other("lock not held".into())),
        }
    }
}

/// 一键铺好 create 全流程路由：资源存在 → 宿主机 → IP 池 → 各服务表 → 分配重算。
/// 返回 (db, 池首 IP)。drives 覆盖（可选）。
pub fn route_create_flow(db: &MockDb, resource_id: i64, host_id: i64) {
    db.rows(
        "SELECT id FROM resources WHERE id = ?",
        vec![row(&[("id", json!(resource_id))])],
    );
    db.rows(
        "WHERE region_id = ? AND hypervisor",
        vec![row(&[
            ("id", json!(host_id)),
            ("region_id", json!(1)),
            ("ip_address", json!("10.0.0.10")),
            ("storage_pool", json!("pool0")),
        ])],
    );
    db.rows(
        "FROM ip_pools WHERE host_machine_id",
        vec![row(&[
            ("id", json!(1)),
            ("host_machine_id", json!(host_id)),
            ("ip_start", json!("10.0.0.1")),
            ("ip_end", json!("10.0.0.10")),
            ("gateway", json!("10.0.0.254")),
        ])],
    );
    db.affected("UPDATE ip_pools SET used_count = used_count + 1", 1);
    db.rows("FROM ip_allocations WHERE ip_pool_id", vec![]);
    db.affected("INSERT INTO ip_allocations", 1);
    db.affected("INSERT INTO network_services", 1);
    db.affected("INSERT INTO firewall_services", 1);
    db.affected("INSERT INTO switch_services", 1);
    db.affected("INSERT INTO disks", 1);
    db.affected("UPDATE network_services SET status", 1);
    db.affected("UPDATE firewall_services SET status", 1);
    db.affected("UPDATE switch_services SET status", 1);
    db.affected("UPDATE disks SET status", 1);
    db.rows("LEFT JOIN resources", vec![]);
    db.rows(
        "AS specs FROM host_machines",
        vec![row(&[("specs", json!("{}"))])],
    );
    db.affected("UPDATE host_machines SET specs", 1);
}

pub fn route_release_flow(db: &MockDb) {
    db.affected("UPDATE ip_allocations SET released_at", 1);
    db.affected("UPDATE disks SET status = 'destroyed'", 1);
    db.affected("DELETE FROM network_services", 1);
    db.affected("DELETE FROM firewall_services", 1);
    db.affected("DELETE FROM switch_services", 1);
}
