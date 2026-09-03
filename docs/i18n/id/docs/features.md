# Dokumen Desain Fungsi CloudPlatform

## 1. Autentikasi dan Otorisasi Pengguna

### 1.1 Registrasi

```
POST /api/v1/auth/register
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

**Alur data:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/v1/auth/register   │                        │                     │
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

### 1.2 Login

```
POST /api/v1/auth/login
  → WAF 扫描
  → 限流 5 req/min
  → Captcha 验证（点击验证码，3 次尝试限制）
  → Hash::check(password, user->password_hash)
  → 失败 5 次 → login_lock:{userId} Redis TTL 900s
  → TOTP 验证（用户已启用时强制，totp_code 必填；
      错误累计 5 次 → totp_fail:{userId} → login_lock TTL 900s）
  → 新 IP 检测 → 邮件告警
  → deviceFingerprint = sha256(UA + IP段，IPv6 取前缀)
  → clientPlatform = X-Client-Platform 头
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/v1/auth/google → Google OAuth → callback?code=xxx
  1. 验证 Google/Apple ID Token
  2. 查找或创建用户（email 匹配）
  3. 签发 token（含 client_platform）
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 Verifikasi Dua Langkah TOTP

```
1. POST /api/v1/user/totp/setup
     → 生成 secret + QR URL（Redis 暂存 10 分钟，未持久化）
     ← {secret, qr_url, manual}
2. POST /api/v1/user/totp/verify
     → 验证 TOTP code（首次为启用 setup，之后为校验）
     ← {verified: true}
3. GET /api/v1/user/totp/recovery-codes
     → 生成 8 个一次性恢复码（需密码确认）
     ← {recovery_codes: [8 个]}
4. 登录时：输入 TOTP code 或使用恢复码
     → POST /api/v1/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Manajemen Sesi

```
GET /api/v1/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/v1/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/v1/user/account (GDPR 注销)
  → 密码二次确认
  → 软删除 User
  → 全部 RefreshToken revoked
```

---

## 2. Manajemen Produk

### 2.1 Model Produk

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

### 2.2 Daftar Produk (dengan cache)

```
GET /api/v1/products?category_id=1&region_id=2&keyword=vps&page=1

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

### 2.3 Pencarian Produk (Elasticsearch)

```
GET /api/v1/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer 中文分词)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Ulasan Produk

```
GET /api/v1/products/{id}/reviews
  → 已审核评价 + 平均评分 + 评分分布
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/v1/products/{id}/reviews (需登录)
  → rating (1-5) + content
  → status = pending (管理员审核后显示)
```

### 2.5 Impor/Ekspor Massal

```
GET /admin/api/v1/products/export
  → CSV 下载 (产品 + SKU + 区域定价)

POST /admin/api/v1/products/import
  → CSV 上传 upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Sistem Pesanan

### 3.1 Keranjang Belanja

```
POST /api/v1/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/v1/cart           → 购物车列表 (含 SKU 详情 + 实时价格)
DELETE /api/v1/cart/{id}   → removeFromCart
PUT /api/v1/cart/{id}      → updateCartQuantity
```

### 3.2 Alur Pembuatan Pesanan

```
1. POST /api/v1/orders                           创建订单
     → 校验库存、计算价格、应用优惠券
     ← {order_id, order_no, items, total}

2. POST /api/v1/coupons/validate                 应用优惠券
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/v1/orders/{id}/payment-methods       获取可用支付通道
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/v1/orders/{id}/pay                  发起支付
     → 密码二次确认 (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Siklus Hidup Pesanan

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

退款条件: 服务器 72h 内 | 域名 5 天内 | IP 不可退款 | 促销商品不可退款（其他类型如 disk 无窗口限制；未知分类类型默认放行）
退款流程: 用户申请 → Ticket 生成 → 客服审核 → admin 确认 → Provider.destroy() → Payment.refund()
```

---

## 4. Sistem Pembayaran

### 4.1 Perutean Multi-Kanal

```
PaymentRouter::route(Order $order)
  → 筛选可用通道（is_visible + visible_regions + min/max_amount）
  → 按 currency 匹配
  → 计算各通道实付金额（含手续费）
  → 按 fee 升序排列
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Pembayaran Stripe

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

### 4.3 Rekonsiliasi

```
Cron: PaymentReconcile (每日 02:37)
  → 拉取各通道结算报表
  → 与系统 transaction 逐笔对账
  → 差异 > $0.01 → 告警
```

---

## 5. Mesin Pengaktifan Sumber Daya

### 5.1 Arsitektur Plugin Provider

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

### 5.2 Rantai Pengaktifan Lengkap

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

> **Evolusi kanal penyediaan**: Rust kvm-server (`infrastructure/kvm-server`, e-cat workspace) sudah masuk repo —
> gRPC `ping/create_vm/vm_status` (:50051) + discovery registrasi etcd, sisi PHP KvmClient /
> RegistryProcess (`service/app/grpc/`) sudah terhubung. Lapisan driver saat ini adalah **simulated driver** (driver nyata
> libvirt adalah Phase 2), rantai pengaktifan untuk sementara masih langsung melalui ProxmoxProvider; setelah kvm-server
> mengambil alih pembuatan VM, alur bagian ini tidak berubah, hanya berpindah kanal.

### 5.3 Ringkasan Operasi Proxmox

| Operasi | API | Operasi panas |
|------|-----|--------|
| Buat VM | POST /nodes/{node}/qemu | — |
| Upgrade CPU | PUT /qemu/{vmid}/config cores | Online |
| Upgrade memori | PUT /qemu/{vmid}/config memory | Online |
| Perluas disk sistem | PUT /qemu/{vmid}/resize disk | Online |
| Buat disk data | POST /qemu/{vmid}/config scsi{n} | Online |
| Buat IP independen | POST /qemu/{vmid}/config net{n} | Online |
| Hancurkan VM | POST stop → DELETE qemu | — |
| Kueri status | GET /qemu/{vmid}/status/current | — |

---

## 6. Sistem Pemasok

### 6.1 Alur Pendaftaran

```
POST /api/v1/supplier/apply (需用户登录)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → 管理员审核

管理员审批:
  POST /admin/api/v1/suppliers/{id}/approve (密码确认)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → 用户获得供应商权限

商品上架:
  POST /api/v1/supplier/products
    → {product_id, commission_rate}
    → 关联供应商商品

结算:
  Cron: SupplierSettlement (每周一 04:17)
    → 统计周期内已完成订单
    → total_sales - commission = payable
    → 创建 SupplierSettlement

提现:
  POST /api/v1/supplier/withdraw (密码确认)
    → 检查可提现余额
    → 创建 SupplierWithdraw (status=pending)
    → 管理员审批打款
```

### 6.2 API Eksternal

```
POST /admin/api/v1/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → hash('sha256', rawKey) 存储
  ← {api_key: "sk_xxx..."} (仅显示一次)

供应商使用:
  GET /api/v1/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware 验签
    → 按 supplierId 筛选数据
```

---

## 7. Domain dan DNS

```
GET /api/v1/domain/check/{domain}/{tld}    # 域名可用性
GET /api/v1/domain/tlds                     # 可注册 TLD 列表 (缓存 1h)
GET /api/v1/dns/{domain}                    # DNS 记录列表
POST /api/v1/dns/{domain}/records           # 添加 DNS 记录
DELETE /api/v1/dns/{domain}/records/{id}    # 删除 DNS 记录 (密码确认)
```

---

## 8. Sistem Tiket

```
POST /api/v1/tickets                    # 创建工单
GET /api/v1/tickets                     # 我的工单
GET /api/v1/tickets/{id}                # 工单详情
POST /api/v1/tickets/{id}/reply         # 回复工单

管理员:
  GET /admin/api/v1/tickets              # 工单队列
  POST /admin/api/v1/tickets/{id}/assign # 分配客服
  POST /admin/api/v1/tickets/{id}/close  # 关闭工单

事件驱动:
  TicketCreated 事件
    → AutoAssignListener: 分配给负载最少的客服
    → WebSocket 推送 'ticket.created'
```

---

## 9. Sistem Notifikasi

### 9.1 Distribusi Empat Kanal

```
事件触发 → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → 直接写入 notifications 表
```

### 9.2 Jenis Notifikasi

| Event | Kanal | Waktu pemicu |
|------|------|---------|
| Verifikasi registrasi | email | Setelah registrasi email |
| Alarm login abnormal | email | Login IP baru |
| Pembayaran pesanan sukses | email/push | Pembayaran selesai |
| Pengaktifan sumber daya selesai | email/push/in_app | Provisioning selesai |
| Pengingat kedaluwarsa sumber daya | email/push | 7d/3d/1d sebelumnya |
| Balasan tiket | email/push/in_app | Pesan baru Ticket |
| Refund selesai | email/push | Refund selesai diproses |
| Sertifikat SSL kedaluwarsa | email | 30d sebelumnya |
| Domain kedaluwarsa | email | 30d sebelumnya |

---

## 10. Pemantauan dan Alarm

### 10.1 Pemantauan Sumber Daya

```
Cron: CollectMetrics (每 5 分钟)
  → 轮询活动资源
  → ProxmoxApi::status() / Provider API
  → 指标存储到 Redis hash (TTL 1h)

管理员:
  GET /admin/api/v1/monitor/dashboard
    → 概览统计 + 最近告警
  GET /admin/api/v1/monitor/resources/{id}
    → 实时指标 (从 Redis 读取)
```

### 10.2 Aturan Alarm

| Aturan | Tingkat keparahan | Kondisi pemicu |
|------|--------|---------|
| server_down | Kritis | Ping tidak terjangkau 3 kali berturut-turut |
| cpu_high | Peringatan | CPU > 90% selama 10 menit |
| disk_high | Peringatan | Disk > 90% selama 5 menit |
| ssl_expiring | Peringatan | Sertifikat SSL < 30 hari kedaluwarsa |
| domain_expiring | Peringatan | Domain < 30 hari kedaluwarsa |
| provision_failed | Kritis | Tugas pengaktifan gagal terus-menerus |

---

## 11. Tugas Terjadwal

| Ekspresi Cron | Tugas | Kegunaan |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | Sinkronisasi nilai tukar setiap 4 jam |
| `37 2 * * *` | PaymentReconcile | Rekonsiliasi harian |
| `17 4 * * 1` | SupplierSettlement | Settlement pemasok setiap Senin |
| `23 6 * * *` | ExpirationCheck | Pemeriksaan kedaluwarsa + notifikasi |
| `43 7 * * *` | SslCertificateCheck | Pemeriksaan sertifikat SSL |
| `*/5 * * * *` | CollectMetrics | Pengumpulan metrik sumber daya |
| `*/30 * * * *` | CheckExpirations | Pemeriksaan kedaluwarsa sumber daya |

---

## 12. Internasionalisasi (i18n)

### 12.1 Alur Permintaan

```
客户端 → Accept-Language: zh-CN
  → LocaleMiddleware（全局中间件）
    → I18n::setLocale('zh-CN')
    → 加载 i18n/zh-CN/messages.php
```

### 12.2 Cara Penerjemahan

**Teks statis:** `I18n::trans('auth.login_success')` → `登录成功`
**Kolom JSON:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Penggantian parameter:** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 Cakupan

120 entri, mencakup semua modul seperti autentikasi/produk/pesanan/pembayaran/sumber daya/KYC/tiket/notifikasi/pemasok/Webhook/sistem. Mendukung fallback bahasa (bahasa tidak didukung → en-US).

---

## 13. Sakelar Fitur (Feature Flags)

```
config/features.php (默认值)
  ↓ 可被覆盖
.env FEATURE_* 环境变量
  ↓ 可被运行时覆盖
Redis feature:{name} (TTL 1h, 通过管理 API 动态调整)

管理 API:
  GET /admin/api/v1/features → 列出所有 Flag 及状态/来源
  PUT /admin/api/v1/features/{name} → enable/disable/toggle/reset

当前 Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. Sertifikat SSL

Produk sertifikat SSL mendukung tiga jenis DV/OV/EV, diterbitkan dan diperpanjang otomatis melalui protokol ACME (Let's Encrypt) atau API CA eksternal (ZeroSSL/GoGetSSL).

**Alur utama:**

    用户选购 SSL 套餐 → 下单支付 → ProvisionTask 创建
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 验证 → 证书签发
      → 每天检查 expires_at → 到期前 14 天自动续期
      → 到期 → status=expired → 通知用户

**Model data:** `ssl_plans` (paket), `resource_ssl_certs` (instans sertifikat)

## 15. Penyimpanan Objek (S3)

Penyimpanan objek yang kompatibel dengan API S3, mendukung AWS S3 dan MinIO mandiri. Pengguna mengunggah/mengunduh file melalui URL presigned.

**Model data:** `resource_storage_buckets`

## 16. Akselerasi CDN

Produk CDN mendukung empat penyedia (Cloudflare / AWS CloudFront / Aliyun CDN / Tencent CDN), server atau bucket penyimpanan dapat dijadikan asal untuk CDN, mendukung pembersihan cache dan konfigurasi sertifikat HTTPS opsional.

**Arsitektur adaptor:** setiap penyedia memiliki satu adaptor di `service/app/cdn/provider/`, semuanya mengimplementasikan `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration), didistribusikan oleh `CdnAdapterFactory` berdasarkan `provider_type`:

| provider_type | Adaptor | Protokol integrasi | Perlu ICP |
|---------------|---------|--------------------|-----------|
| `cloudflare` | CloudflareAdapter | REST v4 API (termasuk sertifikat otomatis SSL SaaS) | Tidak |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | Tidak |
| `aliyun` | AliyunCdnAdapter | Tanda tangan RPC | Ya |
| `tencent` | TencentCdnAdapter | Tanda tangan TC3 | Ya |

**Konfigurasi akun penyedia:** akun `provider_apis` dikelola di sisi admin melalui CRUD `/admin/providers` (kredensial dienkripsi Encryptable saat disimpan, konvensi `code`: `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`). Prioritas resolusi kredensial di sisi pengguna: akun terikat (provider_account_id) → akun aktif yang cocok dengan `code` → fallback konfigurasi env.

**Ikatan snapshot ketat:** `provider_account_id` ditentukan saat pembuatan domain, penghapusan/pembersihan cache berikutnya hanya menggunakan akun terikat tersebut; akun hilang atau dinonaktifkan mengembalikan 4003, tanpa peralihan akun diam-diam. Domain Aliyun/Tencent harus menyelesaikan ICP pendaftaran, yang belum terdaftar mengembalikan 4002 (termasuk petunjuk `requires_icp_registration`).

**Pembersihan cache:** `POST /api/v1/cdn/domains/{id}/purge`, URL otomatis dideduplikasi dan dihilangkan spasi (maksimal 100), hanya mengizinkan domain ini atau subdomain, menolak wildcard dan URL eksternal, idempoten.

**Antarmuka:** CdnAdapterInterface + CdnProvider (menggunakan saluran upgrade ProvisionProvider, mendukung upgrade plan)

**Model data:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; kunci privat dihapus dari cert_config sebelum disimpan, hanya menyimpan informasi sertifikat non-sensitif)

## 17. Penagihan Pemakaian

Pipeline lengkap pengumpulan pemakaian sumber daya → agregasi → penagihan → pemotongan:

    ResourceMonitor 每 5 分钟采集指标 → resource_metrics
      → UsageAggregator 每小时聚合 → usage_events
      → BillingEngine 每日扣减余额 → 余额不足 → 挂起资源
      → SuspendCheck 每 30 分钟检查 → 余额恢复 → 解挂

**Model data:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Rating Pemasok

Pengguna yang sudah membeli dapat memberi rating empat dimensi kepada pemasok (kualitas/dukungan/kecepatan pengiriman/nilai), satu kali per pesanan. Sisi admin dapat meninjau (approve/hide).

**Model data:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Distribusi Rekomendasi

Pengguna membuat tautan rekomendasi (?ref=CODE), pengguna baru terikat affiliate_code saat registrasi, komisi otomatis diatribusikan setelah pembayaran pesanan.

**Event-driven:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Model data:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. API GraphQL

Menyediakan dua endpoint: POST /graphql (kueri publik) dan POST /api/v1/graphql (kueri terautentikasi). Berbasis webonyx/graphql-php, batas kedalaman kueri 5 lapis, batas kompleksitas 100.

**Operasi sensitif tetap REST-only:** pembayaran, penarikan, refund, tinjauan KYC.

## 21. Observabilitas

Endpoint metrik Prometheus adalah proses independen 127.0.0.1:9100, tidak terpengaruh WAF/rate limit. MetricsMiddleware mencatat jumlah permintaan dan latensi HTTP. Docker Compose menyediakan Prometheus + Grafana + aturan alarm + dashboard.

**Pemeriksaan kesehatan:** /health (publik), /health/live, /health/ready (5 pemeriksaan dependensi), /health/deps (detail keterlambatan)
