# CloudPlatform API Reference

## Overview

**Base URL:** `https://api.example.com`

**Versioning:** the API version is in the URL path (e.g. `/api/v1/...`). Unsupported versions return `400`.

**Authentication:**

| Side | Method | Header |
|----|------|--------|
| User | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Admin | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Supplier external API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | Signature verification | `Stripe-Signature: ...` |

**Client platform:** all API requests should carry the `X-Client-Platform` header, supporting `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Multi-language:** all API requests should carry the `Accept-Language` header (`zh-CN` / `en-US`), which affects translated text and the returned values of JSON multilingual fields. Defaults to `en-US` when missing.

---

## Unified Response Format

### Success

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Paginated

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Error

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP Status Codes

| code | Description |
|------|------|
| 0 | Success |
| 400 | Invalid request parameters / unsupported API version / unsupported client platform |
| 401 | Unauthenticated |
| 403 | No permission / blocked by WAF |
| 404 | Resource not found (firstOrFail/findOrFail misses uniformly mapped to 404) |
| 413 | Request body too large (>10MB) |
| 414 | URL too long (>2KB) |
| 415 | Unsupported Content-Type |
| 422 | Parameter validation failed |
| 429 | Request rate exceeded |

---

## Route Groups & Middleware Matrix

| Route group | Middleware | Prefix |
|--------|--------|------|
| Public | Global middleware chain | `/health`, `/api/v1/*` |
| `/health` (internal) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | Global + Encryption | `/api/v1/auth/*` |
| `/api` (user) | Global + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api` (sensitive) | Global + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | Supplier external API |
| `/admin/api` | Global + Encryption + Auth + AdminRole | Admin API |
| `/admin/api` (sensitive) | Global + Encryption + Auth + AdminRole + Confirmation | Sensitive admin operations |

---

## 1. Public Endpoints

### Health Check

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Service Status

```
GET /api/v1/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### Products

```
GET /api/v1/products
  Params: category_id, region_id, keyword, supplier_id, page (default 1), page_size (default 20, max 50)
  → Paginated product list (includes category, skus.regionPrices)

GET /api/v1/products/search
  Params: q (required), page
  → Elasticsearch full-text search

GET /api/v1/products/{id}
  → Product details (includes category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → Review list + avg_rating + total + distribution
  Status enum: pending/approved/rejected, only approved returned
```

### Domains

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → Available TLD list (Redis cache 1h)
```

### Help Center

```
GET /api/v1/help
  Params: category, page
  Headers: Accept-Language (en-US / zh-CN)
  → Paginated help articles

GET /api/v1/help/categories
  → Article category list

GET /api/v1/help/{slug}
  → Single article details
```

---

## 2. Authentication Endpoints

### CAPTCHA

```
POST /api/v1/captcha/create
  Headers: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Register

```
POST /api/v1/auth/register
  Headers: X-Encrypted: 1
  Body (encrypted): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Rate limit: 3 req/min
```

- `deviceFingerprint` (optional): records the device fingerprint at registration, validated at login/refresh; skipping binds no fingerprint
- email/phone are deterministically encrypted with Encryptable before storage (ECB, ciphertext equality queries); uniqueness checks and login queries all use the ciphertext

### Login

```
POST /api/v1/auth/login
  Headers: X-Encrypted: 1
  Body (encrypted): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Rate limit: 5 req/min, 5 failures lock 15min
```

- `login` is queried by ciphertext equality (Encryptable deterministic encryption); plaintext queries do not match encrypted columns

### Token Refresh

```
POST /api/v1/auth/refresh
  Headers: X-Encrypted: 1
  Body (encrypted): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` mismatch with the one recorded at registration → 401 `Device mismatch`; refresh tokens are looked up by ciphertext hash

### OAuth

Supported providers: google, apple, facebook, x, microsoft, linkedin, github
(enabled depending on `{PROVIDER}_OAUTH_CLIENT_ID` etc. in .env)

```
GET /api/v1/auth/{provider}            → { url }        # Redirect to authorization page (PKCE/nonce anti-replay)
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  Body: { code, state }
```

- Apple/Microsoft return an id_token, verified server-side via JWKS signature, iss/aud/exp/nonce
- All providers require `email_verified=true` for login, otherwise 422
- `state` missing or mismatched → 422 (anti-CSRF, 5-minute expiry)
- OAuth flow rate limit: 10 per 60 seconds (redirect + callback)

### Password Reset

```
POST /api/v1/auth/forgot-password
  Body: { email }
  → Sends verification code email

POST /api/v1/auth/reset-password
  Body: { email, code, password }
  → Reset successful
  → 5 accumulated errors → 429 rate limited for 10 minutes
```

### Email Verification

```
GET /api/v1/auth/verify-email?token=xxx
  → Verification successful
```

### SMS Verification

```
POST /api/v1/auth/send-sms
  Body: { phone }
  → Sends SMS verification code (60s cooldown)
```

### TOTP Two-Factor

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # Not persisted; must verify within 10 min to take effect
POST /api/v1/user/totp/verify       Body: { code } → { verified: true }   # Returns enable-success message on first enable
POST /api/v1/user/totp/disable      Body: { password }             # Requires password confirmation, otherwise 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # Generates 8 one-time codes per call, requires password confirmation, otherwise 403
POST /api/v1/auth/login/recovery    Body: { login, password, recovery_code }
```

- After enabling TOTP, login must include `totp_code`, otherwise 401
- 5 consecutive TOTP errors → user locked for 15 minutes (login_lock)

---

## 3. User Endpoints (authenticated)

### Profile

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  Body: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC Verification

```
POST /api/v1/user/kyc
  Body: { id_type, id_number, real_name, front_image, back_image }
```

### Balance

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  Params: page
  → Balance change records
```

### Address Management

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  Body: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### Session Management

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → Revoke the specified session

DELETE /api/v1/user/account
  Body: { confirm_password }
  → GDPR account deletion
```

### Notifications

```
GET /api/v1/user/notifications
  Params: page
  → Paginated notification list

POST /api/v1/user/notifications/{id}/read
  → Mark as read

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  Body: { email: {order_paid: true, ...}, push: {...} }
```

### Email

```
POST /api/v1/user/resend-verify-email
  → Resend verification email
```

### File Upload

```
POST /api/v1/upload
  Body: multipart/form-data { file, type: avatar/kyc/attach }
  Limits: avatar 2MB, kyc 5MB, attach 10MB
  Allowed: jpg, jpeg, png, gif, pdf
  Notes: type whitelist validation + finfo content sniffing (extension-MIME mismatch → 422)
```

---

## 4. Cart & Orders

### Cart

```
POST /api/v1/cart
  Body: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  Body: { quantity }
```

> Amount field convention (settled in D4/P4.2): all amounts are strings with 4 decimal places (e.g. "9.9900"), never number/float —
> consistent with the raw output of MySQL DECIMAL columns via PDO; precision is carried by the 4dp string itself. Applies to all order/balance/report endpoints.

### Orders

```
POST /api/v1/orders
  → Create order from cart
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  Params: page, status (pending/paid/provisioning/completed/refunded, invalid values return 400)
  → My order list

GET /api/v1/orders/{id}
  → Order details (includes items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → Available payment channels + actual amount payable per channel

POST /api/v1/orders/{id}/pay    🔒 password confirmation
  Body: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Coupons

```
POST /api/v1/coupons/validate
  Body: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (e.g. "2.0000")

422: invalid/expired/conditions not met
```

### Invoices

```
GET /api/v1/invoices
  Params: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF download
```

---

## 5. Resource Management

```
GET /api/v1/resources
  Params: page, status
  → My resource list

GET /api/v1/resources/{id}
  → Resource details

GET /api/v1/resources/{id}/status
  → Current resource status + metrics

GET /api/v1/resources/{id}/console
  → VNC/console URL

POST /api/v1/resources/batch
  Body: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. DNS Management

```
GET /api/v1/dns/{domain}
  → DNS record list

POST /api/v1/dns/{domain}/records
  Body: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 password confirmation
```

---

## 7. Tickets

```
POST /api/v1/tickets
  Body: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  Params: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  Body: { content }
```

---

## 8. Supplier (Internal API)

```
POST /api/v1/supplier/apply
  Body: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → Settlement list

POST /api/v1/supplier/withdraw    🔒 password confirmation
  Body: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  Body: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. Supplier External API

**Authentication:** `Authorization: Bearer sk_xxx...` (SHA256 signature verification)

**Rate limit:** 120 req/min (withdrawal 10 req/min)

```
GET /api/v1/supplier/external/orders
  Params: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → Order details (only those linked to this supplier)

GET /api/v1/supplier/external/resources
  Params: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  Params: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  Body: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  Params: page
```

---

## 10. Admin API

**Authentication:** JWT Bearer Token + Admin role

### Dashboard

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### User Management

```
GET /admin/api/v1/users               Params: page, status, keyword
GET /admin/api/v1/users/export       → Excel download
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  Body: { status }
```

### KYC Review

```
GET /admin/api/v1/kyc                 Params: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 password confirmation
  Body: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 password confirmation
  Body: { confirm_password, reason }
```

### Product Management

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 password confirmation
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV download
POST /admin/api/v1/products/import        → CSV upload upsert
```

### Order Management

```
GET /admin/api/v1/orders               Params: page, status, keyword
GET /admin/api/v1/orders/export       → Excel download
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 password confirmation
  Body: { confirm_password, amount?, reason }
```

### Payment Management

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions   Params: page, channel, status
GET /admin/api/v1/payments/reconcile      Params: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run   Params: date; triggers daily reconciliation
```

### Resources & Provisioning

```
GET /admin/api/v1/provisioning/tasks               Params: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  Body: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 password confirmation
GET /admin/api/v1/provisioning/hosts
```

### Supplier Management

```
GET /admin/api/v1/suppliers                  Params: page, status
GET /admin/api/v1/suppliers/export          → Excel download

POST /admin/api/v1/suppliers/{id}/approve    🔒 password confirmation
POST /admin/api/v1/suppliers/{id}/settle     🔒 password confirmation
  Body: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 password confirmation
```

### Supplier API Keys

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  Body: { name }
  ← { api_key: "sk_xxx...", prefix } (shown only once)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### Ticket Management

```
GET /admin/api/v1/tickets                   Params: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign      Body: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### Domain Management

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  Body: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones              Params: page
GET /admin/api/v1/domains/transfers          Params: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### Notification Management

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  Body: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log          Params: page
```

### Coupons

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  Body: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### Help Articles

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  Body: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → Soft delete (status=archived)
```

### Cloud Provider APIs

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  Body: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → Disable (status=disabled)
```

### Webhook Management

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  Body: { url }
DELETE /admin/api/v1/webhooks               Body: { id }
POST /admin/api/v1/webhooks/test            Body: { url }
```

### Reports

```
GET /admin/api/v1/reports/revenue            Params: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (consistent with SUM(DECIMAL) and bcmath aggregation)
GET /admin/api/v1/reports/supplier           Params: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region             Params: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Admin Reports (Panel ReportController, v3.1.0)

The admin panel has a built-in report center (`admin/app/controller/ReportController.php`), routes auto-registered as `/app/admin/report/{action}`, page entry `/app/admin/report/index`. Shared params: `start_date` / `end_date` (YYYY-MM-DD, default last 30 days); amounts aggregated with bcmath 4dp.

```
GET /app/admin/report/order            Params: start_date, end_date
  → { range: {start, end}, totals: {orders, revenue_by_currency}, daily: [{date, currency, orders, revenue}] }
  # aggregated by paid_at, excludes refunded; totals.revenue_by_currency: {CNY: string 4dp, ...} per-currency, daily[].revenue: string 4dp
GET /app/admin/report/product_top      Params: start_date, end_date, limit(1-50, default 10)
  → { range, items: [{product_id, qty, amount, name}] }     # amount: string 4dp
GET /app/admin/report/payment          Params: start_date, end_date
  → { range, items: [{channel, currency, transactions, amount}] }   # amount: string 4dp
GET /app/admin/report/user_growth      Params: start_date, end_date
  → { range, items: [{date, count}] }                        # soft-deleted excluded
GET /app/admin/report/export           Params: start_date, end_date, type, limit
  → downloads {title}_{YYYYmmddHHMMSS}.xlsx   # type whitelist: order / product / payment / user
```

### Monitoring

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Audit Logs

```
GET /admin/api/v1/audit-logs                 Params: page, user_id, action, from, to
  → Paginated audit logs (include client_platform)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  Body: { action: enable/disable/toggle/reset }
```

### System Config

```
PUT /admin/api/v1/system/config              🔒 password confirmation
```

### Product Import/Export

```
GET /admin/api/v1/products/export           → CSV download
POST /admin/api/v1/products/import          → CSV upload upsert
```

### Supplier + User Export

```
GET /admin/api/v1/suppliers/export          → Excel download
GET /admin/api/v1/users/export              → Excel download
GET /admin/api/v1/orders/export             → Excel download
```

---

## 11. SSL Certificates

### User side

```
GET /api/v1/ssl/plans
  → SSL plan list (DV/OV/EV, prices include register/renew/transfer)

GET /api/v1/ssl-certs
  → My certificate list (with status: pending/active/expired/revoked)

GET /api/v1/ssl-certs/{id}
  → Certificate details (domain, issuer, validity, renewal status)

GET /api/v1/ssl-certs/{id}/download
  → Download certificate files (certificate chain + private key)

POST /api/v1/ssl-certs/{id}/auto-renew
  Body: { auto_renew: true/false }
  → Toggle auto-renewal
```

### Admin side

```
GET /admin/api/v1/ssl/plans              → Plan list
POST /admin/api/v1/ssl/plans             → Create plan
PUT /admin/api/v1/ssl/plans/{id}         → Update plan
DELETE /admin/api/v1/ssl/plans/{id}      → Delete plan
GET /admin/api/v1/ssl/certs              → All certificates
POST /admin/api/v1/ssl/certs/{id}/revoke → Revoke certificate
```

---

## 12. Object Storage

S3-compatible object storage, uploaded/downloaded via presigned URLs, keys never leave the server.

```
GET /api/v1/storage/buckets
  → My bucket list (usage, status)

GET /api/v1/storage/buckets/{id}
  → Bucket details

POST /api/v1/storage/buckets/{id}/presign-upload
  Body: { filename, content_type, size }
  → { upload_url, object_key } presigned upload URL (time-limited)

POST /api/v1/storage/buckets/{id}/presign-download
  Body: { object_key }
  → Presigned download URL (time-limited)

GET /api/v1/storage/buckets/{id}/credentials
  → Temporary access credentials (short-lived, for direct SDK upload)
```

---

## 13. CDN Acceleration

### User side

```
GET /api/v1/cdn/domains
  → My CDN domain list (origin, status, plan)

POST /api/v1/cdn/domains
  Body: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
          origin_type (server|storage), origin_value, cert_config? }
  → Create CDN domain (provisioned at the provider and bound to the origin)
  → Domains with provider_type=aliyun|tencent must complete ICP registration (4002 otherwise)
  → Response includes requires_icp_registration hint field
  → Credential resolution: the domain's bound account (provider_account_id) first,
    otherwise the active provider_apis account with code=cdn-{provider_type},
    otherwise env config fallback

GET /api/v1/cdn/domains/{id}
  → CDN domain details

DELETE /api/v1/cdn/domains/{id}
  → Delete CDN domain (disables the provider-side domain, idempotent)

POST /api/v1/cdn/domains/{id}/purge
  Body: { urls: ["https://cdn.example.com/path"] }
  → Purge cache (duplicate URLs auto-deduplicated, idempotent; max 100)

GET /api/v1/cdn/domains/{id}/stats
  → Domain overview (cdn_domain / provider_type / plan / status / purged_at)
```

### Admin side

```
GET /admin/api/v1/cdn/domains            → All CDN domains (incl. owning user)
PUT /admin/api/v1/cdn/domains/{id}       → Update domain plan (plan whitelist: standard | pro | enterprise)
```

Admin CDN routes are guarded by `RbacMiddleware('cdn.manage')`, and plan changes are written to the audit log (`admin_cdn_update_plan`). Provider account credentials are maintained via `/admin/api/v1/providers` CRUD (RbacMiddleware `provider.config`, `code` convention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, credentials stored encrypted with Encryptable).

### CDN Error Codes

| code | Description |
|------|-------------|
| 4001 | CDN parameter missing/invalid (empty urls, invalid provider_type, malformed domain) |
| 4002 | Domain not ICP-registered (mapped when Aliyun/Tencent API rejects) |
| 4003 | CDN provider credentials not configured (account missing/disabled, strict snapshot does not silently switch) |
| 4005 | CDN cache purge failed |
| 5001 | CDN provider API call failed |

> CDN resources not owned by the user (someone else's or non-existent) uniformly return **404** (findOrFail mapping, never leaking resource existence), no dedicated business code.

---

## 14. Usage-Based Billing

```
GET /admin/api/v1/billing/rates          → Billing rate list (by resource type/spec)
POST /admin/api/v1/billing/rates         → Create rate
PUT /admin/api/v1/billing/rates/{id}     → Update rate
DELETE /admin/api/v1/billing/rates/{id}  → Delete rate
GET /admin/api/v1/billing/usage          → Usage summary (aggregated by user/resource)
```

Billing pipeline: ResourceMonitor collects every 5 minutes → UsageAggregator aggregates hourly → BillingEngine deducts daily, suspends resources on insufficient balance.

---

## 15. Affiliate

### User side

```
GET /api/v1/affiliate/summary
  → Commission overview (cumulative/pending/withdrawable, link count, conversion rate)

POST /api/v1/affiliate/links
  Body: { source? }
  → Generate referral link (?ref=CODE)

GET /api/v1/affiliate/earnings
  Params: status, page
  → Commission details (attributed order, rate, status: pending/approved/paid)

POST /api/v1/affiliate/payout
  Body: { amount, method }
  → Submit payout request
```

### Admin side

```
GET /admin/api/v1/affiliate/plans                → Commission plan list
POST /admin/api/v1/affiliate/plans               → Create commission plan
GET /admin/api/v1/affiliate/earnings             → All commission records
POST /admin/api/v1/affiliate/earnings/{id}/approve → Approve commission
GET /admin/api/v1/affiliate/payouts              → Payout request list
POST /admin/api/v1/affiliate/payouts/{id}/approve → Approve/pay payout
```

---

## 16. GraphQL

```
POST /graphql
  → Public queries (products, domains, help and other read-only data)
  Limits: query depth 5, complexity 100

POST /api/v1/graphql                          🔒 requires authentication
  → Full queries (including user data)
```

**Sensitive operations stay REST-only:** payments, withdrawals, refunds, and KYC review do not go through GraphQL.

---

## 17. Supplier Ratings & Product Reviews

### Public

```
GET /api/v1/regions
  → Available region list (with currency/timezone)

GET /api/v1/suppliers/{supplierId}/ratings
  → Supplier rating list (four dimensions: quality/support/delivery speed/value, only approved returned)
```

### User side (authenticated)

```
POST /api/v1/products/{productId}/reviews
  Body: { rating, content, images? }
  → Submit product review (once per order, displayed after review)

POST /api/v1/supplier/ratings
  Body: { supplier_id, quality, support, delivery_speed, value, comment? }
  → Submit supplier rating (once per order)

GET /api/v1/supplier/ratings/me
  → My rating records
```

### Admin side

```
GET /admin/api/v1/suppliers/{id}/ratings          → All ratings (including pending)
POST /admin/api/v1/suppliers/ratings/{id}/approve → Approve
POST /admin/api/v1/suppliers/ratings/{id}/hide    → Hide
```

---

## 18. Payment Webhooks

```
POST /api/v1/payments/webhook/stripe
  Headers: Stripe-Signature: ...
  → Stripe callback (payment success/refund/dispute), signature verification failure returns 400
```

---

## 19. WebSocket Events

**Connection:** `ws://host:8282` (under docker deployment the WS goes through an nginx reverse proxy, connection address is `ws://host/ws/`, 8282 is only exposed inside the container)

Authentication goes through the first message after connection (token never enters the URL/access logs): an `auth` message must be sent first after the connection is established; connections not authenticated within 30 seconds are disconnected; authentication failure returns `error` and disconnects.

### Client → Server

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Server → Client

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Push Events

| Event | Data | Trigger |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Payment successful |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Resource provisioning completed |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | Resource expiring soon |
| `ticket.updated` | `{ticket_id, title, status}` | Ticket status changed |
| `notification.new` | `{notification_id, title, body}` | New notification |

---

## 20. Error Code Reference

| code | Description |
|------|------|
| 400 | Parameter error / unsupported API version / unsupported client platform |
| 401 | Unauthenticated / token expired / invalid API key / device fingerprint mismatch (Device mismatch) |
| 403 | No permission / non-supplier role / blocked by WAF / password confirmation failed |
| 404 | Resource not found (firstOrFail/findOrFail misses uniformly mapped to 404) |
| 413 | Request body exceeds 10MB |
| 414 | URL exceeds 2KB |
| 415 | Content-Type not in whitelist (only application/json, multipart/form-data, x-www-form-urlencoded allowed) |
| 422 | Parameter validation failed (email already registered / insufficient stock / insufficient withdrawable balance / application already submitted) |
| 429 | Request rate exceeded |
| 500 | Server error |

### Common 422 Messages

| Message | Endpoint |
|------|------|
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
