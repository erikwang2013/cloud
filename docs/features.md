# CloudPlatform 功能设计文档

## 1. 用户认证与授权

### 1.1 注册

```
POST /api/auth/register
  → WAF 扫描
  → 限流 3 req/min
  → 密码校验 len≥8
  → 邮箱/手机号唯一性检查
  → bcrypt(password, cost=12)
  → Snowflake::id() 生成 user_id
  → Encryptable::set() 加密敏感字段
  → User + UserProfile + UserBalance 创建
  → NotificationDispatcher::send('email_verify') 发送验证邮件
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**数据流:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 登录

```
POST /api/auth/login
  → WAF 扫描
  → 限流 5 req/min
  → Captcha 验证（点击验证码，3 次尝试限制）
  → Hash::check(password, user->password_hash)
  → 失败 5 次 → login_lock:{userId} Redis TTL 900s
  → 新 IP 检测 → 邮件告警
  → deviceFingerprint = sha256(UA + IP段)
  → clientPlatform = X-Client-Platform 头
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth（Google / Apple）

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. 验证 Google/Apple ID Token
  2. 查找或创建用户（email 匹配）
  3. 签发 token（含 client_platform）
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP 两步验证

```
1. POST /api/user/totp/setup
     → 生成 secret + QR code URL
     ← {secret, qr_code_url}
2. POST /api/user/totp/verify
     → 验证 TOTP code
     ← {recovery_codes: [8 个]}
3. 登录时：输入 TOTP code 或使用恢复码
     → POST /api/auth/login/recovery
```

### 1.5 会话管理

```
GET /api/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/user/account (GDPR 注销)
  → 密码二次确认
  → 软删除 User
  → 全部 RefreshToken revoked
```

---

## 2. 商品管理

### 2.1 产品模型

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (多语言JSON)   │                      │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 产品列表（带缓存）

```
GET /api/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → 按 category_id/region_id/keyword/supplier_id 筛选
    → count + skip/take 分页
  ← 分页结果

缓存失效:
  Admin product/SKU/region-price 变更
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 商品搜索 (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer 中文分词)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 商品评价

```
GET /api/products/{id}/reviews
  → 已审核评价 + 平均评分 + 评分分布
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (需登录)
  → rating (1-5) + content
  → status = pending (管理员审核后显示)
```

### 2.5 批量导入导出

```
GET /admin/api/products/export
  → CSV 下载 (产品 + SKU + 区域定价)

POST /admin/api/products/import
  → CSV 上传 upsert
  ← {imported: N, errors: [...]}
```

---

## 3. 订单系统

### 3.1 购物车

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → 购物车列表 (含 SKU 详情 + 实时价格)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 下单流程

```
1. POST /api/orders                           创建订单
     → 校验库存、计算价格、应用优惠券
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                 应用优惠券
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods       获取可用支付通道
     → PaymentRouter::route(order)
     ← [{channel, fee, total, currency}]

4. POST /api/orders/{id}/pay                  发起支付
     → 密码二次确认 (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_no}
```

### 3.3 订单生命周期

```
                    ┌─────────┐
                    │ pending  │ 待支付
                    └────┬─────┘
                         │ 支付成功
                    ┌────┴─────┐
                    │  paid    │ 已支付
                    └────┬─────┘
                         │ OrderPaid 事件
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ 已完成
                    └────┬─────┘
                         │ 用户申请退款
                    ┌────┴─────┐
                    │ refunded │ 已退款
                    └──────────┘

退款条件: 服务器 72h 内 | 域名 5 天内 | IP 不可退款 | 促销商品不可退款
退款流程: 用户申请 → Ticket 生成 → 客服审核 → admin 确认 → Provider.destroy() → Payment.refund()
```

---

## 4. 支付系统

### 4.1 多通道路由

```
PaymentRouter::route(Order $order)
  → 筛选可用通道（is_visible + visible_regions + min/max_amount）
  → 按 currency 匹配
  → 计算各通道实付金额（含手续费）
  → 按 fee 升序排列
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe 支付

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. 选择 Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. 创建 transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. 用户确认支付
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             验签 stripe-signature
                             幂等检查 transaction_no
                          8. transaction=success
                          9. 触发 OrderPaid 事件
                             → ProvisioningService
                             → WebSocket 推送
                             → 邮件/SMS/Push 通知
```

### 4.3 对账

```
Cron: PaymentReconcile (每日 02:37)
  → 拉取各通道结算报表
  → 与系统 transaction 逐笔对账
  → 差异 > $0.01 → 告警
```

---

## 5. 资源开通引擎

### 5.1 Provider 插件架构

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → Provider 实例
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (可扩展)
  'server:aliyun_ecs'  → AliyunProvider (可扩展)
  'domain:namecheap'   → DomainProvider (可扩展)
```

### 5.2 完整开通链路

```
OrderPaid 事件触发
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ 为每个 OrderItem 创建 ProvisionTask
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (Redis Queue 消费)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     按 cpu/ram/disk 余量 + 负载均衡排序
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     创建 VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     挂载系统盘 (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     启动 VM
  │
  ├→ 创建 Resource + Disk + IpAllocation 记录
  │
  ├→ 更新 host_machine 已分配资源量
  │
  └→ Order::status = completed
       → WebSocket 推送 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

重试策略:
  1min → 5min → 15min → 1h → 6h → 24h (6 次后标记失败 + 告警)
```

### 5.3 Proxmox 操作汇总

| 操作 | API | 热操作 |
|------|-----|--------|
| 创建 VM | POST /nodes/{node}/qemu | — |
| 升级 CPU | PUT /qemu/{vmid}/config cores | 在线 |
| 升级内存 | PUT /qemu/{vmid}/config memory | 在线 |
| 扩容系统盘 | PUT /qemu/{vmid}/resize disk | 在线 |
| 创建数据盘 | POST /qemu/{vmid}/config scsi{n} | 在线 |
| 创建独立 IP | POST /qemu/{vmid}/config net{n} | 在线 |
| 销毁 VM | POST stop → DELETE qemu | — |
| 状态查询 | GET /qemu/{vmid}/status/current | — |

---

## 6. 供应商系统

### 6.1 入驻流程

```
POST /api/supplier/apply (需用户登录)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → 管理员审核

管理员审批:
  POST /admin/api/suppliers/{id}/approve (密码确认)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → 用户获得供应商权限

商品上架:
  POST /api/supplier/products
    → {product_id, commission_rate}
    → 关联供应商商品

结算:
  Cron: SupplierSettlement (每周一 04:17)
    → 统计周期内已完成订单
    → total_sales - commission = payable
    → 创建 SupplierSettlement

提现:
  POST /api/supplier/withdraw (密码确认)
    → 检查可提现余额
    → 创建 SupplierWithdraw (status=pending)
    → 管理员审批打款
```

### 6.2 外部 API

```
POST /admin/api/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → hash('sha256', rawKey) 存储
  ← {api_key: "sk_xxx..."} (仅显示一次)

供应商使用:
  GET /api/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware 验签
    → 按 supplierId 筛选数据
```

---

## 7. 域名与 DNS

```
GET /api/domain/check/{domain}/{tld}    # 域名可用性
GET /api/domain/tlds                     # 可注册 TLD 列表 (缓存 1h)
GET /api/dns/{domain}                    # DNS 记录列表
POST /api/dns/{domain}/records           # 添加 DNS 记录
DELETE /api/dns/{domain}/records/{id}    # 删除 DNS 记录 (密码确认)
```

---

## 8. 工单系统

```
POST /api/tickets                    # 创建工单
GET /api/tickets                     # 我的工单
GET /api/tickets/{id}                # 工单详情
POST /api/tickets/{id}/reply         # 回复工单

管理员:
  GET /admin/api/tickets              # 工单队列
  POST /admin/api/tickets/{id}/assign # 分配客服
  POST /admin/api/tickets/{id}/close  # 关闭工单

事件驱动:
  TicketCreated 事件
    → AutoAssignListener: 分配给负载最少的客服
    → WebSocket 推送 'ticket.created'
```

---

## 9. 通知系统

### 9.1 四通道分发

```
事件触发 → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → 直接写入 notifications 表
```

### 9.2 通知类型

| 事件 | 通道 | 触发时机 |
|------|------|---------|
| 注册验证 | email | 邮箱注册后 |
| 登录异常告警 | email | 新 IP 登录 |
| 订单支付成功 | email/push | 支付完成 |
| 资源开通完成 | email/push/in_app | Provisioning 完成 |
| 资源到期提醒 | email/push | 7d/3d/1d 前 |
| 工单回复 | email/push/in_app | Ticket 新消息 |
| 退款完成 | email/push | 退款处理完 |
| SSL 证书到期 | email | 30d 前 |
| 域名到期 | email | 30d 前 |

---

## 10. 监控与告警

### 10.1 资源监控

```
Cron: CollectMetrics (每 5 分钟)
  → 轮询活动资源
  → ProxmoxApi::status() / Provider API
  → 指标存储到 Redis hash (TTL 1h)

管理员:
  GET /admin/api/monitor/dashboard
    → 概览统计 + 最近告警
  GET /admin/api/monitor/resources/{id}
    → 实时指标 (从 Redis 读取)
```

### 10.2 告警规则

| 规则 | 严重度 | 触发条件 |
|------|--------|---------|
| server_down | 严重 | 连续 3 次 Ping 不可达 |
| cpu_high | 警告 | CPU > 90% 持续 10min |
| disk_high | 警告 | 磁盘 > 90% 持续 5min |
| ssl_expiring | 警告 | SSL 证书 < 30 天到期 |
| domain_expiring | 警告 | 域名 < 30 天到期 |
| provision_failed | 严重 | 开通任务连续失败 |

---

## 11. 定时任务

| Cron 表达式 | 任务 | 用途 |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | 每 4 小时同步汇率 |
| `37 2 * * *` | PaymentReconcile | 每日对账 |
| `17 4 * * 1` | SupplierSettlement | 每周一结算供应商 |
| `23 6 * * *` | ExpirationCheck | 到期检查 + 通知 |
| `43 7 * * *` | SslCertificateCheck | SSL 证书检查 |
| `*/5 * * * *` | CollectMetrics | 资源指标采集 |
| `*/30 * * * *` | CheckExpirations | 资源到期检查 |

---

## 12. Feature Flags 功能开关

```
config/features.php (默认值)
  ↓ 可被覆盖
.env FEATURE_* 环境变量
  ↓ 可被运行时覆盖
Redis feature:{name} (TTL 1h, 通过管理 API 动态调整)

管理 API:
  GET /admin/api/features → 列出所有 Flag 及状态/来源
  PUT /admin/api/features/{name} → enable/disable/toggle/reset

当前 Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth
```
