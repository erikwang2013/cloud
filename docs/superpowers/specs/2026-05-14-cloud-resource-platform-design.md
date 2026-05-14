# 全球云资源交易平台 — 系统设计

## 项目概述

面向全球用户的云资源交易平台，支持自营 + 第三方供应商混合模式。用户可购买服务器、IP、云盘、域名等云产品。全自动资源开通，多支付通道，多币种，多语言。

### 技术栈

| 层级 | 技术 |
|------|------|
| 用户端 App | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| 管理后台 | webman-admin |
| 服务端 | PHP webman (模块化单体) |
| 数据库 | MySQL 8.0 (主从) |
| 缓存/队列 | Redis (缓存 + Session + 队列) |
| 存储 | S3/OSS + CDN |
| 监控 | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 一、模块划分 (12 个核心模块)

| 模块 | 职责 |
|------|------|
| **User** | 注册/登录(OAuth+邮箱+手机)、KYC 实名认证、会员等级、余额账户 |
| **Product** | 商品定义(SKU)、多区域定价、库存管理、分类、搜索、评价 |
| **Order** | 购物车、下单、订单生命周期(待付→已付→开通中→已完成→退款)、续费/升级 |
| **Payment** | 支付通道路由、多币种报价、汇率、退款、对账 |
| **Provisioning** | 对接各云厂商 API，自动创建/续费/销毁资源 |
| **Domain** | 域名查询、注册、转移、续费、DNS 管理 |
| **Supplier** | 供应商入驻、审批、商品上架、结算、分成 |
| **Monitor** | 资源状态探活、用量采集、告警规则 |
| **Ticket** | 工单提交、分配、SLA 追踪 |
| **Notification** | 邮件/短信/App Push/站内信，多模板多语言 |
| **Report** | 营收报表、供应商结算报表、销售趋势 |
| **I18n** | 多语言词条、多币种汇率、多时区 |

---

## 二、核心数据模型

### 用户中心 (User)

- **users** — 用户主表 (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — 用户档案 (user_id, avatar, nickname, country)
- **user_kyc** — 实名认证 (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — 余额账户 (user_id, currency, balance, frozen_balance)
- **user_balance_log** — 余额变动记录 (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — 用户地址 (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### 商品中心 (Product)

- **product_categories** — 商品分类 (id, parent_id, name, icon, sort)
- **products** — 商品主表 (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — 区域定价 (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — 商品图片 (product_id, url, sort)
- **product_attributes** — 自定义属性 (product_id, key, value)
- **product_reviews** — 商品评价 (user_id, product_id, order_id, rating, content)
- **regions** — 区域表 (id, name, continent, country, city, data_center, status)

### 订单中心 (Order)

- **carts** — 购物车 (user_id, sku_id, region_id, quantity, cycle)
- **orders** — 订单主表 (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — 订单明细 (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — 订单时间线 (order_id, status, operator, remark, created_at)
- **order_invoices** — 发票 (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — 退款单 (order_id, user_id, amount, reason, status, handled_by)

### 支付中心 (Payment)

- **payment_channels** — 支付通道配置 (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — 交易记录 (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — 对账表 (date, channel_id, channel_total, system_total, diff, status)

### 资源开通 (Provisioning)

- **resources** — 资源主表 (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — 服务器详情 (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP 详情 (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — 云盘详情 (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — 域名详情 (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — 开通任务 (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — 云厂商 API 配置 (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### 物理机资源管理 (Host & IP Pool)

自营物理服务器使用 Proxmox VE (社区版，免费) 管理虚拟机，通过 REST API 创建/管理 VM、分配 IP、挂载磁盘。

- **host_machines** — 宿主机 (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP 池 (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP 分配记录 (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM 磁盘明细 (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — 磁盘扩容记录 (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### 供应商 (Supplier)

- **suppliers** — 供应商主表 (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — 供应商商品关联 (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — 结算单 (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — 提现记录 (supplier_id, amount, method, account_info, status)

### 域名服务 (Domain)

- **domain_tlds** — 支持的 TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — 域名转移 (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS 区域 (domain_name, user_id, zone_id)
- **dns_records** — DNS 记录 (zone_id, type, name, value, ttl, priority)

### 工单与通知 (Ticket & Notification)

- **tickets** — 工单 (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — 工单消息 (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — 通知记录 (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — 通知模板 (code, name, channels, title_template, body_template, variables)

---

## 三、API 设计规范

### RESTful 路由

```
统一前缀: /api/v1
管理后台: /admin/api/v1
```

### 统一响应格式

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### 鉴权方案

| 端 | 方式 |
|----|------|
| 用户端 | JWT (access_token 2h + refresh_token 30d) |
| 管理端 | JWT (access_token 2h + refresh_token 7d) |
| 供应商 API | API Key + IP 白名单 |
| 云厂商回调 | 签名验证 (HMAC-SHA256) |

### 安全措施

- 全站 HTTPS + HSTS
- JWT + Redis 黑名单
- RBAC (角色 → 权限 → 资源)
- IP/用户级令牌桶限流，支付接口严格限速
- 参数化查询 + XSS 过滤
- 密钥加密存储，日志脱敏
- admin 操作全量审计日志

### 多语言方案

- 请求头: Accept-Language: zh-CN / en-US / ja-JP
- JSON 列存储多语言文案: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n 文件管理静态文本，前端和后端各一套

---

## 四、资源开通引擎

### Provider 插件架构

每种云产品类型 × 云厂商的组合，实现统一接口:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory 根据 (product_type, provider) 路由到具体实现:
- ProxmoxProvider (自营物理机: 服务器/数据盘/IP)
- AwsServerProvider / AliyunServerProvider (第三方云服务器)
- GcpIpProvider (第三方 IP)
- AzureDiskProvider (第三方云盘)
- NamecheapDomainProvider / GoDaddyDomainProvider (域名)

### 异步任务保障

- Provisioning Worker 轮询 provision_tasks 表
- 按 provider 分组控制并发 (每个 provider 最多 5 并发)
- 重试策略: 1min → 5min → 15min → 1h → 6h → 24h (最多 6 次)
- 不可重试失败 → 告警 + 自动生成工单

### 自营物理机方案：Proxmox VE (社区版)

自营服务器采用 Proxmox VE (开源免费，AGPL v3)，PHP 通过 HTTP 调用 Proxmox REST API 管理 KVM 虚拟机生命周期和资源分配。

架构:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### ProxmoxApi 客户端封装

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### 资源操作

**创建 VM (服务器):**
1. HostSelector 选择一台资源够用的宿主机 (按 cpu/ram/disk 余量 + 负载均衡排序)
2. 从该宿主机的 ip_pool 分配一个 IP
3. ProxmoxApi.post("/nodes/{node}/qemu") 创建 VM (设定 vmid、name、cores、memory、net0、ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") 挂载系统盘 (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") 启动 VM
6. 更新 host_machine.specs 已分配量 (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**升级 CPU (在线):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**升级内存 (在线):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**扩容系统盘:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**单独创建数据盘:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**单独创建 IP:**
从 IP 池分配 → 通过 Proxmox API 添加虚拟网卡 + 配置 IP，或保留为独立资源分配给已有 VM 的额外网卡。

**销毁 VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### 宿主机选择策略

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### 资源拆分操作汇总

| 操作 | 实现方式 | 热操作 |
|------|----------|--------|
| 创建 VM (CPU+内存+系统盘+IP) | Proxmox create qemu | — |
| 单独升级 CPU | PUT config cores | 在线 |
| 单独升级内存 | PUT config memory | 在线 |
| 扩容系统盘 | PUT resize disk | 在线 (需 VM 支持) |
| 单独创建数据盘 | POST config 添加磁盘 | 在线 |
| 单独创建 IP | 从 IP 池分配 + VM 添加网卡 | 在线 |

### 资源生命周期

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

续费: active → (renew) → active (延长 expired_at)
升级: active → (upgrade) → upgrading → active

### 资源来源

| 来源 | 虚拟化/API | 产品类型 | 说明 |
|------|-----------|----------|------|
| 自营物理机 | Proxmox VE (社区版) | 服务器、数据盘、IP | 自有数据中心托管，PHP 调 Proxmox API |
| 第三方云厂商 | AWS/GCP/阿里云/华为云/Azure SDK | 服务器、IP、云盘 | 转售第三方云资源 |
| 域名注册商 | Namecheap/GoDaddy/阿里云万网 API | 域名注册/转移 | 域名服务 |

### 首期对接

| 区域 | 服务器 | IP | 云盘 | 域名 |
|------|--------|----|------|------|
| 亚太 | 阿里云、华为云、AWS | 阿里云、GCP | 阿里云、华为云 | 阿里云万网、Namecheap |
| 欧洲 | AWS、GCP、Hetzner | GCP、OVH | AWS、GCP | Namecheap、Gandi |
| 北美 | AWS、GCP、Azure | AWS、GCP | AWS、Azure | GoDaddy、Namecheap |

---

## 五、支付系统

### 多通道路由

PaymentRouter 根据用户币种偏好查询可用通道，计算各通道实付金额 (含通道手续费)，返回支付选项列表。

### 支付流程 (Stripe)

1. 用户选择 Stripe → 前端收集卡信息
2. Stripe 返回 PaymentIntent ID
3. 后端创建 payment_transaction (status=pending)
4. 前端 confirm Payment
5. Stripe webhook 回调 → 验签 → 更新 transaction
6. transaction=success → 触发 OrderPaid 事件
7. OrderPaid → Provisioning 自动开通

### 加密货币支付

1. 用户选择币种 (如 USDT-TRC20)
2. 后端通过 Coinbase Commerce / BitPay API 生成收款地址
3. Worker 每 30s 查区块链确认 (或 webhook)
4. 确认到账 → 触发 OrderPaid 事件

### 汇率与多币种

- 汇率源定时从 exchangerate-api 拉取存入 Redis
- 商品定价以 USD 为基准，其他币种实时换算
- 下单时锁定汇率，退款时按原汇率退回

### 支付通道可见性控制

payment_channels 表字段:
- is_visible: 是否对用户端展示
- visible_regions: 限定可见地区，空表示全部
- min_amount / max_amount: 订单金额区间限制

### 对账

每日凌晨拉取各通道结算报表，与系统 transaction 逐笔对账，差异 > $0.01 告警。

### 退款策略

- 服务器/VPS: 购买后 72h 内全额退款
- 域名: 注册后 5 天内可退款 (ICANN 规范)
- IP: 购买后不可退款
- 云盘: 同服务器策略
- 特殊促销商品: 不可退款

退款流程: 用户申请 → Ticket 生成 → 客服审核 → admin 确认 → provider.destroy() → payment.refund() → 原路退回

---

## 六、客户端页面结构

### Flutter / HarmonyOS 用户端

- **认证**: 登录/注册 (邮箱+密码、Google OAuth、Apple ID、手机号)、忘记密码、两步验证
- **首页**: 区域选择器、产品分类入口、Banner/促销、推荐商品
- **产品**: 列表 (多条件筛选)、详情 (配置/区域/价格计算器)、评价
- **购物&支付**: 购物车、订单确认 (支付方式/账单地址/余额/优惠码)、收银台、支付结果
- **我的资源**: 资源列表 (按状态筛选)、详情操作 (重启/关机/续费/升级/销毁)、控制台 SSO、用量图表
- **订单**: 列表 (待付/已付/已完成/已退款)、详情、发票
- **工单**: 列表、新建、对话
- **个人中心**: 资料/KYC、余额&充值、通知、地址管理、语言/货币/安全设置
- **公共**: 帮助中心、服务条款、关于

### webman-admin 管理后台

- **仪表盘**: 总览 + 趋势图
- **用户管理**: 列表/详情/KYC 审核
- **商品管理**: 分类/列表/定价(SKU×区域)/库存/评价
- **订单管理**: 列表/详情/退款审核/发票
- **支付管理**: 通道配置/交易记录/对账报表
- **资源管理**: 列表/开通任务监控/云厂商 API 配置
- **供应商管理**: 入驻审核/列表/商品分配/结算/提现
- **工单管理**: 队列/我的工单/SLA 监控
- **域名管理**: TLD 定价/注册商 API/转移管理
- **消息通知**: 模板管理/发送记录
- **系统设置**: 管理员&角色/操作日志/多语言/汇率/区域/系统参数
- **报表**: 营收/供应商结算/产品销售分析/区域分析

---

## 七、消息通知系统

### 四通道

Email (SMTP/SendGrid) / SMS (Twilio/阿里短信) / Push (FCM/HMS) / 站内信

### 流程

事件触发 → Notification Dispatcher → 匹配模板 (事件码+语言偏好) → 按用户偏好分发各通道 → Redis Queue 异步发送

### 通知类型

注册验证码、订单支付成功、资源开通完成、资源到期提醒 (7d/3d/1d)、工单回复、退款完成、安全告警、促销活动

### 失败重试

3 次退避，通过 webman redis-queue 管理。

---

## 八、供应商系统

### 入驻流程

注册 → 提交公司信息+联系人+结算方式 → 管理员审核 → 通过后上架商品 → admin 审核商品 → 用户购买 → 自动分账 → 供应商申请提现 → admin 打款

### 权限隔离

供应商只能看自己的商品/订单/结算单/工单/提现记录。不能看平台营收、其他供应商数据、支付通道配置。

### 分账规则

- 自营商品: commission_rate = 100% (全归平台)
- 第三方商品: commission_rate = 5%~20% (平台抽成)
- 结算公式: 订单商品金额 - 平台抽成 - 通道手续费 = 供应商应收
- 结算周期: 周结 / 月结

---

## 九、监控与运维

### 资源监控

- 采集指标: CPU/内存/磁盘/带宽使用率、IP 连通性、云盘 IOPS、DNS 解析、SSL 证书到期
- 采集方式: Agent 上报 / SNMP (自有) + 云厂商监控 API (第三方) + WHOIS/DNS 轮询 (域名)
- 采集周期: 5 分钟，Prometheus + VictoriaMetrics 存储

### 告警规则

| 告警事件 | 严重度 | 触发条件 |
|----------|--------|----------|
| 服务器宕机 | 严重 | 连续 3 次 Ping 不可达 |
| CPU/内存 > 90% | 提示 | 持续 10 分钟 |
| 磁盘 > 90% | 警告 | 持续 5 分钟 |
| 带宽 > 80% | 提示 | 持续 30 分钟 |
| SSL 证书 < 30 天到期 | 警告 | 每日检查 |
| 域名 < 30 天到期 | 警告 | 每日检查 |
| 开通任务失败 | 严重 | 连续失败 2 次 |
| 支付对账差异 | 严重 | 单笔 > $0.01 |

---

## 十、部署架构

### 生产环境

- 应用服务器 × 2: webman (多进程) + Nginx + Supervisor
- 数据库: MySQL 8.0 主从 (1 主 2 从) + Redis Cluster
- 队列: webman redis-queue (支付回调/通知/开通任务)
- 定时任务: Crontab (对账/结算/域名检查/续费提醒)
- 存储: S3/OSS + CDN
- 日志监控: ELK/Loki + Prometheus + Grafana + Sentry

### 目录结构

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 客户端
│   └── harmonyos/         # HarmonyOS 客户端 (ArkTS)
├── service/               # webman 服务端
│   ├── app/
│   │   ├── controller/    # 控制器 (按模块)
│   │   ├── service/       # 业务逻辑 (按模块)
│   │   ├── model/         # 数据模型
│   │   ├── middleware/     # 中间件
│   │   ├── event/         # 事件定义
│   │   ├── listener/      # 事件监听器
│   │   ├── queue/         # 队列任务
│   │   ├── provider/      # 云厂商适配器
│   │   └── cron/          # 定时任务
│   ├── common/            # 公共库 (auth/payment/i18n/notification/helper)
│   ├── config/            # 配置文件
│   ├── database/
│   │   └── migrations/    # 数据库迁移
│   └── storage/           # 日志/缓存/上传
├── admin/                 # webman-admin
├── docs/                  # 文档
└── docker/                # Docker 配置
```

### 关键 Composer 依赖

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog
