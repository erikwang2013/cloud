# CloudPlatform Feature Design Document

## 1. User Authentication & Authorization

### 1.1 Registration

```
POST /api/auth/register
  → WAF scan
  → Rate limit 3 req/min
  → Password validation len≥8
  → Email/phone uniqueness check
  → bcrypt(password, cost=12)
  → Snowflake::id() generates user_id
  → Encryptable::set() encrypts sensitive fields
  → Create User + UserProfile + UserBalance
  → NotificationDispatcher::send('email_verify') sends verification email
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**Data flow:**

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

### 1.2 Login

```
POST /api/auth/login
  → WAF scan
  → Rate limit 5 req/min
  → Captcha verification (click CAPTCHA, 3-attempt limit)
  → Hash::check(password, user->password_hash)
  → 5 failures → login_lock:{userId} Redis TTL 900s
  → TOTP verification (mandatory when enabled by the user, totp_code required;
      5 accumulated errors → totp_fail:{userId} → login_lock TTL 900s)
  → New-IP detection → email alert
  → deviceFingerprint = sha256(UA + IP subnet, IPv6 takes prefix)
  → clientPlatform = X-Client-Platform header
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. Verify Google/Apple ID Token
  2. Find or create user (email match)
  3. Issue tokens (with client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP Two-Factor Authentication

```
1. POST /api/user/totp/setup
     → Generate secret + QR URL (staged in Redis for 10 minutes, not persisted)
     ← {secret, qr_url, manual}
2. POST /api/user/totp/verify
     → Verify TOTP code (first call enables setup, afterwards it validates)
     ← {verified: true}
3. GET /api/user/totp/recovery-codes
     → Generate 8 one-time recovery codes (requires password confirmation)
     ← {recovery_codes: [8 codes]}
4. At login: enter the TOTP code or use a recovery code
     → POST /api/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Session Management

```
GET /api/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/user/account (GDPR deletion)
  → Secondary password confirmation
  → Soft-delete User
  → All RefreshTokens revoked
```

---

## 2. Product Management

### 2.1 Product Model

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (multi-language JSON)│               │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 Product List (with Cache)

```
GET /api/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → Filter by category_id/region_id/keyword/supplier_id
    → count + skip/take pagination
  ← Paginated result

Cache invalidation:
  Admin product/SKU/region-price changes
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 Product Search (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer Chinese tokenization)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Product Reviews

```
GET /api/products/{id}/reviews
  → Approved reviews + average rating + rating distribution
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (login required)
  → rating (1-5) + content
  → status = pending (displayed after admin review)
```

### 2.5 Bulk Import/Export

```
GET /admin/api/products/export
  → CSV download (products + SKUs + regional pricing)

POST /admin/api/products/import
  → CSV upload upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Order System

### 3.1 Cart

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → Cart list (with SKU details + live prices)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 Checkout Flow

```
1. POST /api/orders                            Create order
     → Validate stock, calculate price, apply coupon
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                  Apply coupon
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods        Get available payment channels
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/orders/{id}/pay                   Initiate payment
     → Secondary password confirmation (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Order Lifecycle

```
                    ┌─────────┐
                    │ pending  │ awaiting payment
                    └────┬─────┘
                         │ payment successful
                    ┌────┴─────┐
                    │  paid    │ paid
                    └────┬─────┘
                         │ OrderPaid event
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ completed
                    └────┬─────┘
                         │ user requests refund
                    ┌────┴─────┐
                    │ refunded │ refunded
                    └──────────┘

Refund conditions: servers within 72h | domains within 5 days | IP non-refundable | promotional products non-refundable (other types such as disk have no window limit; unknown category types pass by default)
Refund flow: user request → Ticket created → support review → admin confirmation → Provider.destroy() → Payment.refund()
```

---

## 4. Payment System

### 4.1 Multi-Channel Routing

```
PaymentRouter::route(Order $order)
  → Filter available channels (is_visible + visible_regions + min/max_amount)
  → Match by currency
  → Calculate the actual amount payable per channel (including fees)
  → Sort by fee ascending
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe Payments

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Select Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. Create transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. User confirms payment
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             verify signature stripe-signature
                             idempotency check transaction_no
                          8. transaction=success
                          9. Trigger OrderPaid event
                             → ProvisioningService
                             → WebSocket push
                             → email/SMS/Push notifications
```

### 4.3 Reconciliation

```
Cron: PaymentReconcile (daily 02:37)
  → Pull each channel's settlement reports
  → Reconcile against system transactions one by one
  → Difference > $0.01 → alert
```

---

## 5. Resource Provisioning Engine

### 5.1 Provider Plugin Architecture

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
  (productType, provider) → Provider instance
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (extensible)
  'server:aliyun_ecs'  → AliyunProvider (extensible)
  'domain:namecheap'   → DomainProvider (extensible)
```

### 5.2 Full Provisioning Pipeline

```
OrderPaid event triggered
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ Create a ProvisionTask for each OrderItem
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (Redis Queue consumer)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     Sorted by cpu/ram/disk headroom + load balancing
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     Create VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     Attach system disk (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     Start VM
  │
  ├→ Create Resource + Disk + IpAllocation records
  │
  ├→ Update allocated resource amounts on host_machine
  │
  └→ Order::status = completed
       → WebSocket push 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

Retry strategy:
  1min → 5min → 15min → 1h → 6h → 24h (marked failed + alert after 6 attempts)
```

> **Provisioning channel evolution**: the Rust kvm-server (`infrastructure/kvm-server`, e-cat workspace) is now in the repo —
> gRPC `ping/create_vm/vm_status` (:50051) + etcd registration and discovery, with the PHP-side KvmClient /
> RegistryProcess (`service/app/grpc/`) wired up. The driver layer is currently the **simulated driver** (the real
> libvirt driver is Phase 2); the provisioning pipeline still goes through direct ProxmoxProvider connections for now;
> once kvm-server takes over VM creation, the flow in this section stays unchanged — only the channel switches.

### 5.3 Proxmox Operation Summary

| Operation | API | Hot operation |
|------|-----|--------|
| Create VM | POST /nodes/{node}/qemu | — |
| Upgrade CPU | PUT /qemu/{vmid}/config cores | online |
| Upgrade memory | PUT /qemu/{vmid}/config memory | online |
| Resize system disk | PUT /qemu/{vmid}/resize disk | online |
| Create data disk | POST /qemu/{vmid}/config scsi{n} | online |
| Create standalone IP | POST /qemu/{vmid}/config net{n} | online |
| Destroy VM | POST stop → DELETE qemu | — |
| Status query | GET /qemu/{vmid}/status/current | — |

---

## 6. Supplier System

### 6.1 Onboarding Flow

```
POST /api/supplier/apply (user login required)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → Admin review

Admin approval:
  POST /admin/api/suppliers/{id}/approve (password confirmation)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → User gains supplier permissions

Product listing:
  POST /api/supplier/products
    → {product_id, commission_rate}
    → Link supplier product

Settlement:
  Cron: SupplierSettlement (every Monday 04:17)
    → Count completed orders in the period
    → total_sales - commission = payable
    → Create SupplierSettlement

Withdrawal:
  POST /api/supplier/withdraw (password confirmation)
    → Check withdrawable balance
    → Create SupplierWithdraw (status=pending)
    → Admin approval and payout
```

### 6.2 External API

```
POST /admin/api/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → Store hash('sha256', rawKey)
  ← {api_key: "sk_xxx..."} (shown only once)

Supplier usage:
  GET /api/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware signature verification
    → Filter data by supplierId
```

---

## 7. Domains & DNS

```
GET /api/domain/check/{domain}/{tld}    # Domain availability
GET /api/domain/tlds                     # Registrable TLD list (cached 1h)
GET /api/dns/{domain}                    # DNS record list
POST /api/dns/{domain}/records           # Add DNS record
DELETE /api/dns/{domain}/records/{id}    # Delete DNS record (password confirmation)
```

---

## 8. Ticket System

```
POST /api/tickets                    # Create ticket
GET /api/tickets                     # My tickets
GET /api/tickets/{id}                # Ticket details
POST /api/tickets/{id}/reply         # Reply to ticket

Admin:
  GET /admin/api/tickets              # Ticket queue
  POST /admin/api/tickets/{id}/assign # Assign support agent
  POST /admin/api/tickets/{id}/close  # Close ticket

Event-driven:
  TicketCreated event
    → AutoAssignListener: assign to the least-loaded support agent
    → WebSocket push 'ticket.created'
```

---

## 9. Notification System

### 9.1 Four-Channel Dispatch

```
Event triggered → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → Write directly to notifications table
```

### 9.2 Notification Types

| Event | Channel | Trigger |
|------|------|---------|
| Registration verification | email | After email registration |
| Abnormal login alert | email | New-IP login |
| Order payment success | email/push | Payment completed |
| Resource provisioning complete | email/push/in_app | Provisioning completed |
| Resource expiry reminder | email/push | 7d/3d/1d before |
| Ticket reply | email/push/in_app | New Ticket message |
| Refund complete | email/push | Refund processed |
| SSL certificate expiry | email | 30d before |
| Domain expiry | email | 30d before |

---

## 10. Monitoring & Alerts

### 10.1 Resource Monitoring

```
Cron: CollectMetrics (every 5 minutes)
  → Poll active resources
  → ProxmoxApi::status() / Provider API
  → Store metrics to Redis hash (TTL 1h)

Admin:
  GET /admin/api/monitor/dashboard
    → Overview stats + recent alerts
  GET /admin/api/monitor/resources/{id}
    → Live metrics (read from Redis)
```

### 10.2 Alert Rules

| Rule | Severity | Trigger condition |
|------|--------|---------|
| server_down | critical | 3 consecutive unreachable Pings |
| cpu_high | warning | CPU > 90% sustained 10min |
| disk_high | warning | Disk > 90% sustained 5min |
| ssl_expiring | warning | SSL certificate < 30 days to expiry |
| domain_expiring | warning | Domain < 30 days to expiry |
| provision_failed | critical | Provisioning tasks failing consecutively |

---

## 11. Scheduled Tasks

| Cron expression | Task | Purpose |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | Sync exchange rates every 4 hours |
| `37 2 * * *` | PaymentReconcile | Daily reconciliation |
| `17 4 * * 1` | SupplierSettlement | Settle suppliers every Monday |
| `23 6 * * *` | ExpirationCheck | Expiry check + notifications |
| `43 7 * * *` | SslCertificateCheck | SSL certificate check |
| `*/5 * * * *` | CollectMetrics | Resource metrics collection |
| `*/30 * * * *` | CheckExpirations | Resource expiry check |

---

## 12. Internationalization (i18n)

### 12.1 Request Flow

```
Client → Accept-Language: zh-CN
  → LocaleMiddleware (global middleware)
    → I18n::setLocale('zh-CN')
    → Load i18n/zh-CN/messages.php
```

### 12.2 Translation Methods

**Static text:** `I18n::trans('auth.login_success')` → `Login successful`
**JSON fields:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Parameter substitution:** `I18n::trans('validation.required', ['field' => 'email'])` → `email is required`

### 12.3 Coverage

120 entries covering all modules: authentication/products/orders/payments/resources/KYC/tickets/notifications/suppliers/Webhooks/system, etc. Language fallback supported (unsupported languages → en-US).

---

## 13. Feature Flags

```
config/features.php (default values)
  ↓ overridable by
.env FEATURE_* environment variables
  ↓ overridable at runtime by
Redis feature:{name} (TTL 1h, dynamically adjustable via the admin API)

Admin API:
  GET /admin/api/features → list all Flags with status/source
  PUT /admin/api/features/{name} → enable/disable/toggle/reset

Current Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL Certificates

The SSL certificate product supports three types — DV/OV/EV — automatically issued and renewed via the ACME protocol (Let's Encrypt) or external CA APIs (ZeroSSL/GoGetSSL).

**Key flow:**

    User selects an SSL plan → order and pay → ProvisionTask created
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 validation → certificate issued
      → Check expires_at daily → auto-renew 14 days before expiry
      → Expired → status=expired → notify user

**Data models:** `ssl_plans` (plans), `resource_ssl_certs` (certificate instances)

## 15. Object Storage (S3)

S3 API-compatible object storage, supporting AWS S3 and self-hosted MinIO. Users upload/download files via presigned URLs.

**Data model:** `resource_storage_buckets`

## 16. CDN Acceleration

The CDN product supports four providers (Cloudflare / AWS CloudFront / Aliyun CDN / Tencent CDN), allowing servers or storage buckets to be connected as origins, with cache purge and optional HTTPS certificate configuration.

**Adapter architecture:** one adapter per provider under `service/app/cdn/provider/`, all implementing `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration), dispatched by `CdnAdapterFactory` based on `provider_type`:

| provider_type | Adapter | Integration protocol | ICP registration required |
|---------------|---------|----------------------|---------------------------|
| `cloudflare` | CloudflareAdapter | REST v4 API (incl. SSL SaaS auto certificate) | No |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | No |
| `aliyun` | AliyunCdnAdapter | RPC signing | Yes |
| `tencent` | TencentCdnAdapter | TC3 signing | Yes |

**Provider account configuration:** admins maintain `provider_apis` accounts via `/admin/providers` CRUD (credentials stored Encrypted with Encryptable, `code` convention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`). User-side credential resolution priority: bound account (provider_account_id) → active account matching `code` → env config fallback.

**Strict snapshot binding:** `provider_account_id` is fixed at domain creation; subsequent deletion/cache purge only uses the bound account. Missing or disabled account returns 4003, never silently switching accounts. Aliyun/Tencent domains require ICP registration, returning 4002 when unregistered (with `requires_icp_registration` hint).

**Cache purge:** `POST /api/cdn/domains/{id}/purge`, URLs are automatically deduplicated and trimmed (max 100), only the domain itself or its subdomains are allowed, wildcards and external URLs are rejected, idempotent.

**Interfaces:** CdnAdapterInterface + CdnProvider (reuses the ProvisionProvider upgrade channel, supports plan upgrades)

**Data model:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; private keys are stripped from cert_config before persisting, only non-sensitive certificate info is stored)

## 17. Usage-Based Billing

A complete pipeline of usage collection → aggregation → billing → deduction:

    ResourceMonitor collects metrics every 5 minutes → resource_metrics
      → UsageAggregator aggregates hourly → usage_events
      → BillingEngine deducts balance daily → insufficient balance → suspend resource
      → SuspendCheck checks every 30 minutes → balance restored → unsuspend

**Data models:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Supplier Ratings

Purchasing users can rate suppliers across four dimensions (quality/support/delivery speed/value), once per order. Admins can review (approve/hide).

**Data models:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Affiliate

Users generate referral links (?ref=CODE); new users bind the affiliate_code at registration; commissions are auto-attributed after order payment.

**Event-driven:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Data models:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. GraphQL API

Provides two endpoints: POST /graphql (public queries) and POST /api/graphql (authenticated queries). Built on webonyx/graphql-php, query depth limited to 5 levels, complexity limited to 100.

**Sensitive operations stay REST-only:** payments, withdrawals, refunds, KYC review.

## 21. Observability

The Prometheus metrics endpoint is a separate process at 127.0.0.1:9100, unaffected by WAF/rate limiting. MetricsMiddleware records HTTP request counts and latency. Docker Compose pre-provisions Prometheus + Grafana + alert rules + dashboards.

**Health checks:** /health (public), /health/live, /health/ready (5 dependency checks), /health/deps (latency details)
