# CloudPlatform 機能設計ドキュメント

## 1. ユーザー認証と認可

### 1.1 登録

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

**データフロー:**

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

### 1.2 ログイン

```
POST /api/auth/login
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

### 1.3 OAuth（Google / Apple）

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. 验证 Google/Apple ID Token
  2. 查找或创建用户（email 匹配）
  3. 签发 token（含 client_platform）
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP 二段階認証

```
1. POST /api/user/totp/setup
     → 生成 secret + QR URL（Redis 暂存 10 分钟，未持久化）
     ← {secret, qr_url, manual}
2. POST /api/user/totp/verify
     → 验证 TOTP code（首次为启用 setup，之后为校验）
     ← {verified: true}
3. GET /api/user/totp/recovery-codes
     → 生成 8 个一次性恢复码（需密码确认）
     ← {recovery_codes: [8 个]}
4. 登录时：输入 TOTP code 或使用恢复码
     → POST /api/auth/login/recovery (login, password, recovery_code)
```

### 1.5 セッション管理

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

### 2.1 製品モデル

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

### 2.2 製品リスト（キャッシュ付き）

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

### 2.3 商品検索 (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer 中文分词)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 商品レビュー

```
GET /api/products/{id}/reviews
  → 已审核评价 + 平均评分 + 评分分布
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (需登录)
  → rating (1-5) + content
  → status = pending (管理员审核后显示)
```

### 2.5 一括インポート/エクスポート

```
GET /admin/api/products/export
  → CSV 下载 (产品 + SKU + 区域定价)

POST /admin/api/products/import
  → CSV 上传 upsert
  ← {imported: N, errors: [...]}
```

---

## 3. 注文システム

### 3.1 ショッピングカート

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → 购物车列表 (含 SKU 详情 + 实时价格)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 注文フロー

```
1. POST /api/orders                           创建订单
     → 校验库存、计算价格、应用优惠券
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                 应用优惠券
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods       获取可用支付通道
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/orders/{id}/pay                  发起支付
     → 密码二次确认 (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 注文ライフサイクル

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

**返金条件:** サーバーは 72 時間以内 | ドメインは 5 日以内 | IP は返金不可 | プロモーション商品は返金不可（disk などの他のタイプは期間制限なし；未知のカテゴリタイプはデフォルトで許可）
**返金フロー:** ユーザー申請 → Ticket 生成 → カスタマーサポート審査 → admin 確認 → Provider.destroy() → Payment.refund()

---

## 4. 決済システム

### 4.1 マルチチャネルルーティング

```
PaymentRouter::route(Order $order)
  → 筛选可用通道（is_visible + visible_regions + min/max_amount）
  → 按 currency 匹配
  → 计算各通道实付金额（含手续费）
  → 按 fee 升序排列
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe 決済

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

### 4.3 照合

```
Cron: PaymentReconcile (每日 02:37)
  → 拉取各通道结算报表
  → 与系统 transaction 逐笔对账
  → 差异 > $0.01 → 告警
```

---

## 5. リソース開通エンジン

### 5.1 Provider プラグインアーキテクチャ

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

### 5.2 完全な開通チェーン

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

> **供給チャネルの進化**：Rust kvm-server（`infrastructure/kvm-server`、e-cat workspace）がリポジトリに入庫済み——
> gRPC `ping/create_vm/vm_status`（:50051）+ etcd 登録検出、PHP 側の KvmClient /
> RegistryProcess（`service/app/grpc/`）も配線済み。ドライバー層は現在**シミュレーションドライバー**（libvirt 実
> ドライバーは Phase 2）、開通チェーンは当面 ProxmoxProvider 直結のまま；kvm-server が VM 作成を引き継いだ後も
> 本節のフローは不変で、チャネルの切り替えのみ。

### 5.3 Proxmox 操作まとめ

| 操作 | API | ホット操作 |
|------|-----|--------|
| VM 作成 | POST /nodes/{node}/qemu | — |
| CPU アップグレード | PUT /qemu/{vmid}/config cores | オンライン |
| メモリアップグレード | PUT /qemu/{vmid}/config memory | オンライン |
| システムディスク拡張 | PUT /qemu/{vmid}/resize disk | オンライン |
| データディスク作成 | POST /qemu/{vmid}/config scsi{n} | オンライン |
| 独立 IP 作成 | POST /qemu/{vmid}/config net{n} | オンライン |
| VM 破棄 | POST stop → DELETE qemu | — |
| 状態照会 | GET /qemu/{vmid}/status/current | — |

---

## 6. サプライヤーシステム

### 6.1 参入フロー

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

## 7. ドメインと DNS

```
GET /api/domain/check/{domain}/{tld}    # 域名可用性
GET /api/domain/tlds                     # 可注册 TLD 列表 (缓存 1h)
GET /api/dns/{domain}                    # DNS 记录列表
POST /api/dns/{domain}/records           # 添加 DNS 记录
DELETE /api/dns/{domain}/records/{id}    # 删除 DNS 记录 (密码确认)
```

---

## 8. チケットシステム

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

## 9. 通知システム

### 9.1 4 チャネル配信

```
事件触发 → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → 直接写入 notifications 表
```

### 9.2 通知タイプ

| イベント | チャネル | トリガー時期 |
|------|------|---------|
| 登録検証 | email | メール登録後 |
| ログイン異常アラート | email | 新 IP ログイン |
| 注文決済成功 | email/push | 決済完了 |
| リソース開通完了 | email/push/in_app | Provisioning 完了 |
| リソース期限切れリマインド | email/push | 7d/3d/1d 前 |
| チケット返信 | email/push/in_app | Ticket 新メッセージ |
| 返金完了 | email/push | 返金処理完了 |
| SSL 証明書期限切れ | email | 30d 前 |
| ドメイン期限切れ | email | 30d 前 |

---

## 10. 監視とアラート

### 10.1 リソース監視

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

### 10.2 アラートルール

| ルール | 深刻度 | トリガー条件 |
|------|--------|---------|
| server_down | 深刻 | 連続 3 回の Ping 到達不可 |
| cpu_high | 警告 | CPU > 90% が 10 分間継続 |
| disk_high | 警告 | ディスク > 90% が 5 分間継続 |
| ssl_expiring | 警告 | SSL 証明書が 30 日以内に期限切れ |
| domain_expiring | 警告 | ドメインが 30 日以内に期限切れ |
| provision_failed | 深刻 | 開通タスクが連続失敗 |

---

## 11. 定期タスク

| Cron 式 | タスク | 用途 |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | 4 時間ごとに為替レート同期 |
| `37 2 * * *` | PaymentReconcile | 日次照合 |
| `17 4 * * 1` | SupplierSettlement | 毎週月曜にサプライヤー決済 |
| `23 6 * * *` | ExpirationCheck | 期限切れチェック + 通知 |
| `43 7 * * *` | SslCertificateCheck | SSL 証明書チェック |
| `*/5 * * * *` | CollectMetrics | リソースメトリクス収集 |
| `*/30 * * * *` | CheckExpirations | リソース期限切れチェック |

---

## 12. 国際化（i18n）

### 12.1 リクエストフロー

```
客户端 → Accept-Language: zh-CN
  → LocaleMiddleware（全局中间件）
    → I18n::setLocale('zh-CN')
    → 加载 i18n/zh-CN/messages.php
```

### 12.2 翻訳方式

**静的テキスト：** `I18n::trans('auth.login_success')` → `登录成功`
**JSON フィールド：** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**パラメータ置換：** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 カバレッジ

120 エントリで、認証/商品/注文/決済/リソース/KYC/チケット/通知/サプライヤー/Webhook/システムなど全モジュールをカバー。言語フォールバックに対応（未対応言語 → en-US）。

---

## 13. Feature Flags 機能スイッチ

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
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL 証明書

SSL 証明書製品は DV/OV/EV の 3 タイプをサポートし、ACME プロトコル（Let's Encrypt）または外部 CA API（ZeroSSL/GoGetSSL）で自動発行と自動更新を行う。

**主要フロー：**

    用户选购 SSL 套餐 → 下单支付 → ProvisionTask 创建
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 验证 → 证书签发
      → 每天检查 expires_at → 到期前 14 天自动续期
      → 到期 → status=expired → 通知用户

**データモデル：** `ssl_plans`（プラン）、`resource_ssl_certs`（証明書インスタンス）

## 15. オブジェクトストレージ（S3）

S3 API 互換のオブジェクトストレージで、AWS S3 と MinIO 自建ストレージに対応。ユーザーはプリサイン URL でファイルをアップロード/ダウンロードする。

**データモデル：** `resource_storage_buckets`

## 16. CDN 高速化

CDN 製品は 4 社のプロバイダー（Cloudflare / AWS CloudFront / Aliyun CDN / Tencent CDN）に対応し、サーバーまたはストレージバケットをオリジンとして CDN に接続でき、キャッシュパージとオプションの HTTPS 証明書設定をサポートする。

**アダプターアーキテクチャ：** `service/app/cdn/provider/` 配下にプロバイダーごとのアダプターがあり、共通で `CdnAdapterInterface`（createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration）を実装し、`CdnAdapterFactory` が `provider_type` に応じてディスパッチする：

| provider_type | アダプター | 接続プロトコル | ICP 登録が必要 |
|---------------|-----------|--------------|--------------|
| `cloudflare` | CloudflareAdapter | REST v4 API（SSL SaaS 自動証明書含む） | いいえ |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php（CloudFront + ACM） | いいえ |
| `aliyun` | AliyunCdnAdapter | RPC 署名 | はい |
| `tencent` | TencentCdnAdapter | TC3 署名 | はい |

**プロバイダーアカウント設定：** 管理側の `/admin/providers` CRUD で `provider_apis` アカウントを管理する（資格情報は Encryptable で暗号化して保存、`code` は `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent` と規定）。ユーザー側の資格情報解決順序：バインドアカウント（provider_account_id）→ code 一致のアクティブアカウント → env 設定のフォールバック。

**厳格なスナップショットバインド：** ドメイン作成時に `provider_account_id` を確定し、以降の削除/キャッシュパージはそのバインドアカウントのみを使用。アカウント欠落・無効時は 4003 を返し、アカウントを静かに切り替えない。Aliyun/Tencent ドメインは ICP 登録が必要で、未登録の場合は 4002 を返す（`requires_icp_registration` ヒントを含む）。

**キャッシュパージ：** `POST /api/cdn/domains/{id}/purge`、URL は自動的に重複・空白を除去（最大 100 個）、自ドメインまたはサブドメインのみ許可し、ワイルドカードと外部 URL は拒否、冪等。

**インターフェース：** CdnAdapterInterface + CdnProvider（ProvisionProvider のアップグレードチャネルを再利用、プランアップグレード対応）

**データモデル：** `resource_cdn`（provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config；cert_config は保存前に秘密鍵を除去し、非機密の証明書情報のみ保存）

## 17. 従量課金

リソース使用量の収集 → 集約 → 課金 → 引き落としの完全なパイプライン：

    ResourceMonitor 每 5 分钟采集指标 → resource_metrics
      → UsageAggregator 每小时聚合 → usage_events
      → BillingEngine 每日扣减余额 → 余额不足 → 挂起资源
      → SuspendCheck 每 30 分钟检查 → 余额恢复 → 解挂

**データモデル：** `resource_metrics`、`usage_events`、`usage_rates`、`usage_invoice_items`

## 18. サプライヤー評価

購入済みユーザーはサプライヤーを 4 次元で評価できる（品質/サポート/納品速度/コストパフォーマンス）、注文ごとに 1 回。管理側は審査できる（approve/hide）。

**データモデル：** `supplier_ratings`、`suppliers.rating_avg/rating_count`

## 19. アフィリエイト

ユーザーが紹介リンクを生成（?ref=CODE）、新規ユーザー登録時に affiliate_code をバインド、注文決済後に自動でコミッションを帰属させる。

**イベント駆動：** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**データモデル：** `affiliate_plans`、`affiliate_links`、`affiliate_earnings`、`affiliate_payouts`

## 20. GraphQL API

POST /graphql（公開クエリ）と POST /api/graphql（認証クエリ）の 2 つのエンドポイントを提供。webonyx/graphql-php ベースで、クエリ深さ制限 5 層、複雑度制限 100。

**機密操作は REST のみ：** 決済、出金、返金、KYC 審査。

## 21. 可観測性

Prometheus メトリクスエンドポイントは独立プロセス 127.0.0.1:9100 で、WAF/レート制限の影響を受けない。MetricsMiddleware が HTTP リクエスト数と遅延を記録する。Docker Compose に Prometheus + Grafana + アラートルール + ダッシュボードをプリセット。

**ヘルスチェック：** /health（公開）、/health/live、/health/ready（5 項目の依存チェック）、/health/deps（遅延詳細）
