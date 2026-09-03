# Dokumentasi Antarmuka API CloudPlatform

## Ikhtisar

**Base URL:** `https://api.example.com`

**Versioning:** versi API ditentukan di path URL (contoh: `/api/v1/...`). Versi yang tidak didukung mengembalikan `400`.

**Cara autentikasi:**

| Sisi | Cara | Header permintaan |
|----|------|--------|
| Pengguna | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Admin | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| API eksternal pemasok | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | Verifikasi tanda tangan | `Stripe-Signature: ...` |

**Platform klien:** semua permintaan API disarankan membawa header `X-Client-Platform`, mendukung `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Multi-bahasa:** semua permintaan API disarankan membawa header `Accept-Language` (`zh-CN` / `en-US`), memengaruhi teks terjemahan dan nilai balik kolom JSON multi-bahasa. Jika tidak ada, default `en-US`.

---

## Format Respons Seragam

### Sukses

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Berpaginasi

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Kesalahan

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### Kode Status HTTP

| code | Keterangan |
|------|------|
| 0 | Sukses |
| 400 | Kesalahan parameter permintaan / versi API tidak didukung / platform klien tidak didukung |
| 401 | Belum terautentikasi |
| 403 | Tanpa izin / diblokir WAF |
| 404 | Sumber daya tidak ada (firstOrFail/findOrFail tidak ditemukan dipetakan seragam ke 404) |
| 413 | Body permintaan terlalu besar (>10MB) |
| 414 | URL terlalu panjang (>2KB) |
| 415 | Content-Type tidak didukung |
| 422 | Validasi parameter gagal |
| 429 | Frekuensi permintaan melebihi batas |

---

## Matriks Grup Rute dan Middleware

| Grup rute | Middleware | Prefix |
|--------|--------|------|
| Publik | Rantai middleware global | `/health`, `/api/v1/*` |
| `/health` (internal) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | Global + Encryption | `/api/v1/auth/*` |
| `/api` (pengguna) | Global + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api` (sensitif) | Global + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | API eksternal pemasok |
| `/admin/api` | Global + Encryption + Auth + AdminRole | API panel admin |
| `/admin/api` (sensitif) | Global + Encryption + Auth + AdminRole + Confirmation | Operasi admin sensitif |

---

## 1. Endpoint Publik
### Pemeriksaan Kesehatan

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Status Layanan

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

### Produk

```
GET /api/v1/products
  参数: category_id, region_id, keyword, supplier_id, page (默认1), page_size (默认20, 最大50)
  → 分页产品列表 (含 category, skus.regionPrices)

GET /api/v1/products/search
  参数: q (必填), page
  → Elasticsearch 全文搜索

GET /api/v1/products/{id}
  → 产品详情 (含 category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → 评价列表 + avg_rating + total + distribution
  状态枚举: pending(待审核)/approved(已通过)/rejected(已拒绝)，仅返回 approved
```

### Domain

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → 可用 TLD 列表 (Redis 缓存 1h)
```

### Pusat Bantuan

```
GET /api/v1/help
  参数: category, page
  头: Accept-Language (en-US / zh-CN)
  → 分页帮助文章

GET /api/v1/help/categories
  → 文章分类列表

GET /api/v1/help/{slug}
  → 单篇文章详情
```

---

## 2. Endpoint Autentikasi
### Kode Verifikasi

```
POST /api/v1/captcha/create
  头: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registrasi

```
POST /api/v1/auth/register
  头: X-Encrypted: 1
  体(加密): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 3 req/min
```

- `deviceFingerprint`（opsional）: mencatat fingerprint perangkat saat registrasi, diverifikasi saat login/refresh; jika tidak dibawa, lewati pengikatan fingerprint
- email/phone sebelum disimpan melewati enkripsi deterministik Encryptable (ECB, query kesetaraan ciphertext), validasi keunikan dan kueri login semuanya berdasarkan ciphertext

### Login

```
POST /api/v1/auth/login
  头: X-Encrypted: 1
  体(加密): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 5 req/min, 5 次失败锁 15min
```

- `login` di-query berdasarkan kesetaraan ciphertext (enkripsi deterministik Encryptable), query plaintext tidak akan mengenai kolom terenkripsi

### Refresh Token

```
POST /api/v1/auth/refresh
  头: X-Encrypted: 1
  体(加密): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` tidak konsisten dengan yang dicatat saat registrasi → 401 `Device mismatch`; refresh token di-query dengan hash ciphertext

### OAuth

Provider yang didukung: google, apple, facebook, x, microsoft, linkedin, github
(apakah diaktifkan ditentukan oleh konfigurasi seperti `{PROVIDER}_OAUTH_CLIENT_ID` di .env)

```
GET /api/v1/auth/{provider}            → { url }        # 跳转授权页（PKCE/nonce 防重放）
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  体: { code, state }
```

- Apple/Microsoft mengembalikan id_token, server memverifikasi tanda tangan via JWKS, iss/aud/exp/nonce
- Semua provider mensyaratkan `email_verified=true` untuk diizinkan login, jika tidak 422
- `state` hilang atau tidak cocok → 422 (anti CSRF, kedaluwarsa 5 menit)
- Pembatasan alur OAuth: 10 kali per 60 detik (redirect + callback)

### Reset Kata Sandi

```
POST /api/v1/auth/forgot-password
  体: { email }
  → 发送验证码邮件

POST /api/v1/auth/reset-password
  体: { email, code, password }
  → 重置成功
  → 错误累计 5 次 → 429 限流 10 分钟
```

### Verifikasi Email

```
GET /api/v1/auth/verify-email?token=xxx
  → 验证成功
```

### Verifikasi SMS

```
POST /api/v1/auth/send-sms
  体: { phone }
  → 发送短信验证码 (60s 冷却)
```

### Verifikasi Dua Langkah TOTP

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # 未持久化，10 分钟内需 verify 生效
POST /api/v1/user/totp/verify       体: { code } → { verified: true }   # 首次启用时返回启用成功消息
POST /api/v1/user/totp/disable      体: { password }             # 需密码确认，否则 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # 每次生成 8 个一次性码，需密码确认，否则 403
POST /api/v1/auth/login/recovery    体: { login, password, recovery_code }
```

- Setelah pengguna mengaktifkan TOTP, login wajib membawa `totp_code`, jika tidak 401
- TOTP salah 5 kali berturut-turut → pengguna terkunci 15 menit (login_lock)

---

## 3. Endpoint Pengguna (perlu autentikasi)
### Profil Pribadi

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  体: { nickname?, avatar?, country?, language?, timezone? }
```

### Verifikasi Identitas KYC

```
POST /api/v1/user/kyc
  体: { id_type, id_number, real_name, front_image, back_image }
```

### Saldo

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  参数: page
  → 余额变动记录
```

### Manajemen Alamat

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  体: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### Manajemen Sesi

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → 撤销指定会话

DELETE /api/v1/user/account
  体: { confirm_password }
  → GDPR 账号注销
```

### Notifikasi

```
GET /api/v1/user/notifications
  参数: page
  → 分页通知列表

POST /api/v1/user/notifications/{id}/read
  → 标记已读

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  体: { email: {order_paid: true, ...}, push: {...} }
```

### Email

```
POST /api/v1/user/resend-verify-email
  → 重新发送验证邮件
```

### Unggah File

```
POST /api/v1/upload
  体: multipart/form-data { file, type: avatar/kyc/attach }
  限制: avatar 2MB, kyc 5MB, attach 10MB
  允许: jpg, jpeg, png, gif, pdf
  说明: 类型白名单校验 + finfo 内容嗅探（扩展名与 MIME 不符 → 422）
```

---

## 4. Keranjang Belanja dan Pesanan
### Keranjang Belanja

```
POST /api/v1/cart
  体: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  体: { quantity }
```

> Konvensi kolom jumlah uang (diputuskan di D4/P4.2): semua jumlah wajib string, 4 desimal (mis. "9.9900"), dilarang number/float——
> konsisten dengan output mentah kolom DECIMAL MySQL melalui PDO, presisi dibawa oleh string 4dp itu sendiri. Berlaku untuk semua endpoint pesanan/saldo/laporan.

### Pesanan

```
POST /api/v1/orders
  → 从购物车创建订单
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  参数: page, status (pending/paid/provisioning/completed/refunded，非法值返回 400)
  → 我的订单列表

GET /api/v1/orders/{id}
  → 订单详情 (含 items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → 可用支付通道 + 各通道实付金额

POST /api/v1/orders/{id}/pay    🔒 密码确认
  体: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Kupon

```
POST /api/v1/coupons/validate
  体: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（如 "2.0000"）

422: 无效/过期/不满足使用条件
```

### Faktur

```
GET /api/v1/invoices
  参数: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF 下载
```

---

## 5. Manajemen Sumber Daya
```
GET /api/v1/resources
  参数: page, status
  → 我的资源列表

GET /api/v1/resources/{id}
  → 资源详情

GET /api/v1/resources/{id}/status
  → 资源当前状态 + 指标

GET /api/v1/resources/{id}/console
  → VNC/控制台 URL

POST /api/v1/resources/batch
  体: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. Manajemen DNS
```
GET /api/v1/dns/{domain}
  → DNS 记录列表

POST /api/v1/dns/{domain}/records
  体: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 密码确认
```

---

## 7. Tiket
```
POST /api/v1/tickets
  体: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  参数: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  体: { content }
```

---

## 8. Pemasok (API Internal)
```
POST /api/v1/supplier/apply
  体: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → 结算单列表

POST /api/v1/supplier/withdraw    🔒 密码确认
  体: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  体: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. API Eksternal Pemasok
**Autentikasi:** `Authorization: Bearer sk_xxx...`（verifikasi tanda tangan SHA256）

**Pembatasan:** 120 req/menit (penarikan 10 req/menit)

```
GET /api/v1/supplier/external/orders
  参数: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → 订单详情（仅本供应商关联）

GET /api/v1/supplier/external/resources
  参数: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  参数: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  体: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  参数: page
```

---

## 10. API Panel Admin
**Autentikasi:** JWT Bearer Token + peran Admin

### Dasbor

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Manajemen Pengguna

```
GET /admin/api/v1/users              参数: page, status, keyword
GET /admin/api/v1/users/export       → Excel 下载
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  体: { status }
```

### Tinjauan KYC

```
GET /admin/api/v1/kyc                参数: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 密码确认
  体: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 密码确认
  体: { confirm_password, reason }
```

### Manajemen Produk

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 密码确认
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV 下载
POST /admin/api/v1/products/import        → CSV 上传 upsert
```

### Manajemen Pesanan

```
GET /admin/api/v1/orders              参数: page, status, keyword
GET /admin/api/v1/orders/export       → Excel 下载
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 密码确认
  体: { confirm_password, amount?, reason }
```

### Manajemen Pembayaran

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions  参数: page, channel, status
GET /admin/api/v1/payments/reconcile     参数: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run  参数: date; 触发按日对账
```

### Sumber Daya dan Pengaktifan

```
GET /admin/api/v1/provisioning/tasks              参数: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  体: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 密码确认
GET /admin/api/v1/provisioning/hosts
```

### Manajemen Pemasok

```
GET /admin/api/v1/suppliers                 参数: page, status
GET /admin/api/v1/suppliers/export          → Excel 下载

POST /admin/api/v1/suppliers/{id}/approve    🔒 密码确认
POST /admin/api/v1/suppliers/{id}/settle     🔒 密码确认
  体: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 密码确认
```

### API Key Pemasok

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  体: { name }
  ← { api_key: "sk_xxx...", prefix } (仅显示一次)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### Manajemen Tiket

```
GET /admin/api/v1/tickets                  参数: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign     体: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### Manajemen Domain

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  体: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones             参数: page
GET /admin/api/v1/domains/transfers         参数: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### Manajemen Notifikasi

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  体: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log         参数: page
```

### Kupon

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  体: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### Artikel Bantuan

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  体: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → 软删除 (status=archived)
```

### API Vendor Cloud

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  体: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → 禁用 (status=disabled)
```

### Manajemen Webhook

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  体: { url }
DELETE /admin/api/v1/webhooks              体: { id }
POST /admin/api/v1/webhooks/test           体: { url }
```

### Laporan

```
GET /admin/api/v1/reports/revenue           参数: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL) 与 bcmath 汇总一致）
GET /admin/api/v1/reports/supplier          参数: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region            参数: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Pemantauan

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Log Audit

```
GET /admin/api/v1/audit-logs                参数: page, user_id, action, from, to
  → 分页审计日志 (含 client_platform)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  体: { action: enable/disable/toggle/reset }
```

### Konfigurasi Sistem

```
PUT /admin/api/v1/system/config              🔒 密码确认
```

### Impor/Ekspor Produk

```
GET /admin/api/v1/products/export           → CSV 下载
POST /admin/api/v1/products/import          → CSV 上传 upsert
```

### Ekspor Pemasok + Pengguna

```
GET /admin/api/v1/suppliers/export          → Excel 下载
GET /admin/api/v1/users/export              → Excel 下载
GET /admin/api/v1/orders/export             → Excel 下载
```

---

## 11. Sertifikat SSL
### Sisi Pengguna

```
GET /api/v1/ssl/plans
  → SSL 套餐列表（DV/OV/EV，价格含 register/renew/transfer）

GET /api/v1/ssl-certs
  → 我的证书列表（含 status: pending/active/expired/revoked）

GET /api/v1/ssl-certs/{id}
  → 证书详情（域名、签发机构、有效期、续期状态）

GET /api/v1/ssl-certs/{id}/download
  → 下载证书文件（证书链 + 私钥）

POST /api/v1/ssl-certs/{id}/auto-renew
  体: { auto_renew: true/false }
  → 切换自动续期
```

### Sisi Admin

```
GET /admin/api/v1/ssl/plans              → 套餐列表
POST /admin/api/v1/ssl/plans             → 创建套餐
PUT /admin/api/v1/ssl/plans/{id}         → 更新套餐
DELETE /admin/api/v1/ssl/plans/{id}      → 删除套餐
GET /admin/api/v1/ssl/certs              → 全部证书
POST /admin/api/v1/ssl/certs/{id}/revoke → 吊销证书
```

---

## 12. Penyimpanan Objek
Penyimpanan objek kompatibel S3, unggah/unduh melalui URL presigned, kunci tidak dibawa keluar.

```
GET /api/v1/storage/buckets
  → 我的存储桶列表（用量、状态）

GET /api/v1/storage/buckets/{id}
  → 存储桶详情

POST /api/v1/storage/buckets/{id}/presign-upload
  体: { filename, content_type, size }
  → { upload_url, object_key } 预签名上传 URL（限时）

POST /api/v1/storage/buckets/{id}/presign-download
  体: { object_key }
  → 预签名下载 URL（限时）

GET /api/v1/storage/buckets/{id}/credentials
  → 临时访问凭证（短期有效，用于 SDK 直传）
```

---

## 13. Akselerasi CDN
### Sisi Pengguna

```
GET /api/v1/cdn/domains
  → 我的 CDN 域名列表（源站、状态、套餐）

POST /api/v1/cdn/domains
  体: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
        origin_type (server|storage), origin_value, cert_config? }
  → 创建 CDN 域名（服务商侧创建并绑定源站）
  → provider_type=aliyun|tencent 时域名需完成 ICP 备案（未备案返回 4002）
  → 响应含 requires_icp_registration 提示字段
  → 凭据解析：先取该域名绑定账号（provider_account_id），否则按 code=cdn-{provider_type}
    的活动 provider_apis 账号，均无则回退 env 配置

GET /api/v1/cdn/domains/{id}
  → CDN 域名详情

DELETE /api/v1/cdn/domains/{id}
  → 删除 CDN 域名（停用服务商侧域名，幂等）

POST /api/v1/cdn/domains/{id}/purge
  体: { urls: ["https://cdn.example.com/path"] }
  → 清除缓存（重复 URL 自动去重，幂等；最多 100 个）

GET /api/v1/cdn/domains/{id}/stats
  → 域名概览（cdn_domain / provider_type / plan / status / purged_at）
```

### Sisi Admin

```
GET /admin/api/v1/cdn/domains            → 全部 CDN 域名（含所属用户）
PUT /admin/api/v1/cdn/domains/{id}       → 更新域名套餐（plan 白名单: standard | pro | enterprise）
```

Rute CDN sisi admin dipasang `RbacMiddleware('cdn.manage')`, perubahan paket ditulis ke log audit (`admin_cdn_update_plan`). Kredensial akun penyedia dikelola melalui CRUD `/admin/api/v1/providers` (RbacMiddleware `provider.config`, konvensi `code`: `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, kredensial dienkripsi Encryptable saat disimpan).

### Kode Kesalahan CDN

| code | Keterangan |
|------|------------|
| 4001 | Parameter CDN hilang/tidak valid (urls kosong, provider_type tidak valid, format domain salah) |
| 4002 | Domain belum menyelesaikan ICP pendaftaran (dipetakan saat API Aliyun/Tencent menolak) |
| 4003 | Kredensial penyedia CDN belum dikonfigurasi (akun hilang/nonaktif, snapshot ketat tidak berpindah diam-diam) |
| 4005 | Gagal membersihkan cache CDN |
| 5001 | Gagal memanggil API penyedia CDN |

> Sumber daya CDN yang bukan milik pengguna ini (milik orang lain/tidak ada) seragam mengembalikan **404** (pemetaan findOrFail, tidak membocorkan keberadaan sumber daya), tanpa kode bisnis terpisah.

---

## 14. Penagihan Pemakaian
```
GET /admin/api/v1/billing/rates          → 计费费率列表（按资源类型/规格）
POST /admin/api/v1/billing/rates         → 创建费率
PUT /admin/api/v1/billing/rates/{id}     → 更新费率
DELETE /admin/api/v1/billing/rates/{id}  → 删除费率
GET /admin/api/v1/billing/usage          → 用量汇总（按用户/资源聚合）
```

Pipeline penagihan: ResourceMonitor mengumpulkan setiap 5 menit → UsageAggregator mengagregasi setiap jam → BillingEngine memotong saldo setiap hari, saldo tidak cukup maka sumber daya di-suspend.

---

## 15. Komisi Afiliasi
### Sisi Pengguna

```
GET /api/v1/affiliate/summary
  → 佣金总览（累计/待结算/可提现、链接数、转化率）

POST /api/v1/affiliate/links
  体: { source? }
  → 生成推广链接（?ref=CODE）

GET /api/v1/affiliate/earnings
  参数: status, page
  → 佣金明细（订单归属、比例、状态: pending/approved/paid）

POST /api/v1/affiliate/payout
  体: { amount, method }
  → 发起提现申请
```

### Sisi Admin

```
GET /admin/api/v1/affiliate/plans                → 佣金方案列表
POST /admin/api/v1/affiliate/plans               → 创建佣金方案
GET /admin/api/v1/affiliate/earnings             → 全部佣金记录
POST /admin/api/v1/affiliate/earnings/{id}/approve → 审核佣金
GET /admin/api/v1/affiliate/payouts              → 提现申请列表
POST /admin/api/v1/affiliate/payouts/{id}/approve → 审核/打款提现
```

---

## 16. GraphQL
```
POST /graphql
  → 公开查询（商品、域名、帮助等只读数据）
  限制: 查询深度 5 层，复杂度 100

POST /api/v1/graphql                          🔒 需认证
  → 完整查询（含用户数据）
```

**Operasi sensitif tetap REST-only:** pembayaran, penarikan, refund, tinjauan KYC tidak melalui GraphQL.

---

## 17. Rating Pemasok dan Ulasan Produk
### Publik

```
GET /api/v1/regions
  → 可用区域列表（含货币/时区）

GET /api/v1/suppliers/{supplierId}/ratings
  → 供应商评分列表（四维度: 质量/支持/交付速度/性价比，仅返回 approved）
```

### Sisi Pengguna (perlu autentikasi)

```
POST /api/v1/products/{productId}/reviews
  体: { rating, content, images? }
  → 提交商品评价（每订单一次，审核后展示）

POST /api/v1/supplier/ratings
  体: { supplier_id, quality, support, delivery_speed, value, comment? }
  → 提交供应商评分（每订单一次）

GET /api/v1/supplier/ratings/me
  → 我的评分记录
```

### Sisi Admin

```
GET /admin/api/v1/suppliers/{id}/ratings          → 全部评分（含 pending）
POST /admin/api/v1/suppliers/ratings/{id}/approve → 审核通过
POST /admin/api/v1/suppliers/ratings/{id}/hide    → 隐藏
```

---

## 18. Webhook Pembayaran
```
POST /api/v1/payments/webhook/stripe
  头: Stripe-Signature: ...
  → Stripe 回调（支付成功/退款/争议），签名校验失败返回 400
```

---

## 19. Event WebSocket
**Koneksi:** `ws://host:8282`（deployment docker WS melalui reverse proxy nginx, alamat koneksi `ws://host/ws/`, 8282 hanya diekspos dalam container）

Autentikasi lewat pesan pertama setelah koneksi (token tidak masuk URL/access log): setelah koneksi dibangun harus kirim pesan `auth` terlebih dahulu, jika tidak terautentikasi dalam 30 detik akan diputus; autentikasi gagal mengembalikan `error` lalu memutus.

### Klien → Server

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Server → Klien

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Event Push

| Event | Data | Waktu pemicu |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Pembayaran sukses |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Pengaktifan sumber daya selesai |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | Sumber daya akan segera kedaluwarsa |
| `ticket.updated` | `{ticket_id, title, status}` | Perubahan status tiket |
| `notification.new` | `{notification_id, title, body}` | Notifikasi baru |

---

## 20. Referensi Kode Kesalahan
| code | Keterangan |
|------|------|
| 400 | Kesalahan parameter / versi API tidak didukung / platform klien tidak didukung |
| 401 | Belum terautentikasi / Token kedaluwarsa / API Key tidak valid / fingerprint perangkat tidak cocok (Device mismatch) |
| 403 | Tanpa izin / bukan peran pemasok / diblokir WAF / konfirmasi kata sandi gagal |
| 404 | Sumber daya tidak ada (firstOrFail/findOrFail tidak ditemukan dipetakan seragam ke 404) |
| 413 | Body permintaan melebihi 10MB |
| 414 | URL melebihi 2KB |
| 415 | Content-Type tidak dalam whitelist (hanya mengizinkan application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | Validasi parameter gagal (email sudah terdaftar / stok tidak cukup / saldo penarikan tidak cukup / sudah pernah mengajukan) |
| 429 | Frekuensi permintaan melebihi batas |
| 500 | Kesalahan server |

### Pesan 422 Umum

| Pesan | Endpoint |
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
