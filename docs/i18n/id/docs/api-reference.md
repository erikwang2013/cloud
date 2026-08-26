# Dokumentasi Antarmuka API CloudPlatform

## Ikhtisar

**Base URL:** `https://api.example.com`

**Versioning:** ditentukan melalui header permintaan HTTP `X-Api-Version: v1`. Jika tidak ada, default `v1`, versi yang tidak didukung mengembalikan `400`. Versi tidak ada di path URL.

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
| Publik | Rantai middleware global | `/health`, `/api/*` |
| `/health` (internal) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | Global + Encryption | `/api/auth/*` |
| `/api` (pengguna) | Global + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (sensitif) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | API eksternal pemasok |
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
GET /api/status
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
GET /api/products
  参数: category_id, region_id, keyword, supplier_id, page (默认1), page_size (默认20, 最大50)
  → 分页产品列表 (含 category, skus.regionPrices)

GET /api/products/search
  参数: q (必填), page
  → Elasticsearch 全文搜索

GET /api/products/{id}
  → 产品详情 (含 category, skus, images, reviews)

GET /api/products/{productId}/reviews
  → 评价列表 + avg_rating + total + distribution
  状态枚举: pending(待审核)/approved(已通过)/rejected(已拒绝)，仅返回 approved
```

### Domain

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → 可用 TLD 列表 (Redis 缓存 1h)
```

### Pusat Bantuan

```
GET /api/help
  参数: category, page
  头: Accept-Language (en-US / zh-CN)
  → 分页帮助文章

GET /api/help/categories
  → 文章分类列表

GET /api/help/{slug}
  → 单篇文章详情
```

---

## 2. Endpoint Autentikasi
### Kode Verifikasi

```
POST /api/captcha/create
  头: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registrasi

```
POST /api/auth/register
  头: X-Encrypted: 1
  体(加密): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 3 req/min
```

- `deviceFingerprint`（opsional）: mencatat fingerprint perangkat saat registrasi, diverifikasi saat login/refresh; jika tidak dibawa, lewati pengikatan fingerprint
- email/phone sebelum disimpan melewati enkripsi deterministik Encryptable (ECB, query kesetaraan ciphertext), validasi keunikan dan kueri login semuanya berdasarkan ciphertext

### Login

```
POST /api/auth/login
  头: X-Encrypted: 1
  体(加密): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 5 req/min, 5 次失败锁 15min
```

- `login` di-query berdasarkan kesetaraan ciphertext (enkripsi deterministik Encryptable), query plaintext tidak akan mengenai kolom terenkripsi

### Refresh Token

```
POST /api/auth/refresh
  头: X-Encrypted: 1
  体(加密): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` tidak konsisten dengan yang dicatat saat registrasi → 401 `Device mismatch`; refresh token di-query dengan hash ciphertext

### OAuth

Provider yang didukung: google, apple, facebook, x, microsoft, linkedin, github
(apakah diaktifkan ditentukan oleh konfigurasi seperti `{PROVIDER}_OAUTH_CLIENT_ID` di .env)

```
GET /api/auth/{provider}            → { url }        # 跳转授权页（PKCE/nonce 防重放）
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  体: { code, state }
```

- Apple/Microsoft mengembalikan id_token, server memverifikasi tanda tangan via JWKS, iss/aud/exp/nonce
- Semua provider mensyaratkan `email_verified=true` untuk diizinkan login, jika tidak 422
- `state` hilang atau tidak cocok → 422 (anti CSRF, kedaluwarsa 5 menit)
- Pembatasan alur OAuth: 10 kali per 60 detik (redirect + callback)

### Reset Kata Sandi

```
POST /api/auth/forgot-password
  体: { email }
  → 发送验证码邮件

POST /api/auth/reset-password
  体: { email, code, password }
  → 重置成功
  → 错误累计 5 次 → 429 限流 10 分钟
```

### Verifikasi Email

```
GET /api/auth/verify-email?token=xxx
  → 验证成功
```

### Verifikasi SMS

```
POST /api/auth/send-sms
  体: { phone }
  → 发送短信验证码 (60s 冷却)
```

### Verifikasi Dua Langkah TOTP

```
POST /api/user/totp/setup        → { secret, qr_url }        # 未持久化，10 分钟内需 verify 生效
POST /api/user/totp/verify       体: { code } → { verified: true }   # 首次启用时返回启用成功消息
POST /api/user/totp/disable      体: { password }             # 需密码确认，否则 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # 每次生成 8 个一次性码，需密码确认，否则 403
POST /api/auth/login/recovery    体: { login, password, recovery_code }
```

- Setelah pengguna mengaktifkan TOTP, login wajib membawa `totp_code`, jika tidak 401
- TOTP salah 5 kali berturut-turut → pengguna terkunci 15 menit (login_lock)

---

## 3. Endpoint Pengguna (perlu autentikasi)
### Profil Pribadi

```
GET /api/user/profile
PUT /api/user/profile
  体: { nickname?, avatar?, country?, language?, timezone? }
```

### Verifikasi Identitas KYC

```
POST /api/user/kyc
  体: { id_type, id_number, real_name, front_image, back_image }
```

### Saldo

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
  参数: page
  → 余额变动记录
```

### Manajemen Alamat

```
GET /api/user/addresses
POST /api/user/addresses
  体: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### Manajemen Sesi

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → 撤销指定会话

DELETE /api/user/account
  体: { confirm_password }
  → GDPR 账号注销
```

### Notifikasi

```
GET /api/user/notifications
  参数: page
  → 分页通知列表

POST /api/user/notifications/{id}/read
  → 标记已读

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
  体: { email: {order_paid: true, ...}, push: {...} }
```

### Email

```
POST /api/user/resend-verify-email
  → 重新发送验证邮件
```

### Unggah File

```
POST /api/upload
  体: multipart/form-data { file, type: avatar/kyc/attach }
  限制: avatar 2MB, kyc 5MB, attach 10MB
  允许: jpg, jpeg, png, gif, pdf
  说明: 类型白名单校验 + finfo 内容嗅探（扩展名与 MIME 不符 → 422）
```

---

## 4. Keranjang Belanja dan Pesanan
### Keranjang Belanja

```
POST /api/cart
  体: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
  体: { quantity }
```

> Konvensi kolom jumlah uang (diputuskan di D4/P4.2): semua jumlah wajib string, 4 desimal (mis. "9.9900"), dilarang number/float——
> konsisten dengan output mentah kolom DECIMAL MySQL melalui PDO, presisi dibawa oleh string 4dp itu sendiri. Berlaku untuk semua endpoint pesanan/saldo/laporan.

### Pesanan

```
POST /api/orders
  → 从购物车创建订单
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
  参数: page, status (pending/paid/provisioning/completed/refunded，非法值返回 400)
  → 我的订单列表

GET /api/orders/{id}
  → 订单详情 (含 items, timeline)

GET /api/orders/{id}/payment-methods
  → 可用支付通道 + 各通道实付金额

POST /api/orders/{id}/pay    🔒 密码确认
  体: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Kupon

```
POST /api/coupons/validate
  体: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（如 "2.0000"）

422: 无效/过期/不满足使用条件
```

### Faktur

```
GET /api/invoices
  参数: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → PDF 下载
```

---

## 5. Manajemen Sumber Daya
```
GET /api/resources
  参数: page, status
  → 我的资源列表

GET /api/resources/{id}
  → 资源详情

GET /api/resources/{id}/status
  → 资源当前状态 + 指标

GET /api/resources/{id}/console
  → VNC/控制台 URL

POST /api/resources/batch
  体: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. Manajemen DNS
```
GET /api/dns/{domain}
  → DNS 记录列表

POST /api/dns/{domain}/records
  体: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 密码确认
```

---

## 7. Tiket
```
POST /api/tickets
  体: { resource_id?, category, priority?, title, content }

GET /api/tickets
  参数: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
  体: { content }
```

---

## 8. Pemasok (API Internal)
```
POST /api/supplier/apply
  体: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → 结算单列表

POST /api/supplier/withdraw    🔒 密码确认
  体: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
  体: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## 9. API Eksternal Pemasok
**Autentikasi:** `Authorization: Bearer sk_xxx...`（verifikasi tanda tangan SHA256）

**Pembatasan:** 120 req/menit (penarikan 10 req/menit)

```
GET /api/supplier/external/orders
  参数: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → 订单详情（仅本供应商关联）

GET /api/supplier/external/resources
  参数: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
  参数: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
  体: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
  参数: page
```

---

## 10. API Panel Admin
**Autentikasi:** JWT Bearer Token + peran Admin

### Dasbor

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Manajemen Pengguna

```
GET /admin/api/users              参数: page, status, keyword
GET /admin/api/users/export       → Excel 下载
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status  体: { status }
```

### Tinjauan KYC

```
GET /admin/api/kyc                参数: page, status

POST /admin/api/kyc/{id}/approve   🔒 密码确认
  体: { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 密码确认
  体: { confirm_password, reason }
```

### Manajemen Produk

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 密码确认
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → CSV 下载
POST /admin/api/products/import        → CSV 上传 upsert
```

### Manajemen Pesanan

```
GET /admin/api/orders              参数: page, status, keyword
GET /admin/api/orders/export       → Excel 下载
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 密码确认
  体: { confirm_password, amount?, reason }
```

### Manajemen Pembayaran

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions  参数: page, channel, status
GET /admin/api/payments/reconcile     参数: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run  参数: date; 触发按日对账
```

### Sumber Daya dan Pengaktifan

```
GET /admin/api/provisioning/tasks              参数: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
  体: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 密码确认
GET /admin/api/provisioning/hosts
```

### Manajemen Pemasok

```
GET /admin/api/suppliers                 参数: page, status
GET /admin/api/suppliers/export          → Excel 下载

POST /admin/api/suppliers/{id}/approve    🔒 密码确认
POST /admin/api/suppliers/{id}/settle     🔒 密码确认
  体: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 密码确认
```

### API Key Pemasok

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
  体: { name }
  ← { api_key: "sk_xxx...", prefix } (仅显示一次)

DELETE /admin/api/suppliers/api-keys/{id}
```

### Manajemen Tiket

```
GET /admin/api/tickets                  参数: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign     体: { user_id }
POST /admin/api/tickets/{id}/close
```

### Manajemen Domain

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
  体: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones             参数: page
GET /admin/api/domains/transfers         参数: page
POST /admin/api/domains/transfers/{id}/approve
```

### Manajemen Notifikasi

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
  体: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log         参数: page
```

### Kupon

```
GET /admin/api/coupons
POST /admin/api/coupons
  体: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### Artikel Bantuan

```
GET /admin/api/help
POST /admin/api/help
  体: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → 软删除 (status=archived)
```

### API Vendor Cloud

```
GET /admin/api/providers
POST /admin/api/providers
  体: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → 禁用 (status=disabled)
```

### Manajemen Webhook

```
GET /admin/api/webhooks
POST /admin/api/webhooks
  体: { url }
DELETE /admin/api/webhooks              体: { id }
POST /admin/api/webhooks/test           体: { url }
```

### Laporan

```
GET /admin/api/reports/revenue           参数: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL) 与 bcmath 汇总一致）
GET /admin/api/reports/supplier          参数: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region            参数: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Pemantauan

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Log Audit

```
GET /admin/api/audit-logs                参数: page, user_id, action, from, to
  → 分页审计日志 (含 client_platform)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
  体: { action: enable/disable/toggle/reset }
```

### Konfigurasi Sistem

```
PUT /admin/api/system/config              🔒 密码确认
```

### Impor/Ekspor Produk

```
GET /admin/api/products/export           → CSV 下载
POST /admin/api/products/import          → CSV 上传 upsert
```

### Ekspor Pemasok + Pengguna

```
GET /admin/api/suppliers/export          → Excel 下载
GET /admin/api/users/export              → Excel 下载
GET /admin/api/orders/export             → Excel 下载
```

---

## 11. Sertifikat SSL
### Sisi Pengguna

```
GET /api/ssl/plans
  → SSL 套餐列表（DV/OV/EV，价格含 register/renew/transfer）

GET /api/ssl-certs
  → 我的证书列表（含 status: pending/active/expired/revoked）

GET /api/ssl-certs/{id}
  → 证书详情（域名、签发机构、有效期、续期状态）

GET /api/ssl-certs/{id}/download
  → 下载证书文件（证书链 + 私钥）

POST /api/ssl-certs/{id}/auto-renew
  体: { auto_renew: true/false }
  → 切换自动续期
```

### Sisi Admin

```
GET /admin/api/ssl/plans              → 套餐列表
POST /admin/api/ssl/plans             → 创建套餐
PUT /admin/api/ssl/plans/{id}         → 更新套餐
DELETE /admin/api/ssl/plans/{id}      → 删除套餐
GET /admin/api/ssl/certs              → 全部证书
POST /admin/api/ssl/certs/{id}/revoke → 吊销证书
```

---

## 12. Penyimpanan Objek
Penyimpanan objek kompatibel S3, unggah/unduh melalui URL presigned, kunci tidak dibawa keluar.

```
GET /api/storage/buckets
  → 我的存储桶列表（用量、状态）

GET /api/storage/buckets/{id}
  → 存储桶详情

POST /api/storage/buckets/{id}/presign-upload
  体: { filename, content_type, size }
  → { upload_url, object_key } 预签名上传 URL（限时）

POST /api/storage/buckets/{id}/presign-download
  体: { object_key }
  → 预签名下载 URL（限时）

GET /api/storage/buckets/{id}/credentials
  → 临时访问凭证（短期有效，用于 SDK 直传）
```

---

## 13. Akselerasi CDN
### Sisi Pengguna

```
GET /api/cdn/domains
  → 我的 CDN 域名列表（源站、状态、套餐）

GET /api/cdn/domains/{id}
  → CDN 域名详情

POST /api/cdn/domains/{id}/purge
  → 清除缓存（全站或指定 URL 列表）

GET /api/cdn/domains/{id}/stats
  参数: range (day/week/month)
  → 流量/请求数/命中率统计
```

### Sisi Admin

```
GET /admin/api/cdn/domains            → 全部 CDN 域名
PUT /admin/api/cdn/domains/{id}       → 更新域名套餐/配置
```

---

## 14. Penagihan Pemakaian
```
GET /admin/api/billing/rates          → 计费费率列表（按资源类型/规格）
POST /admin/api/billing/rates         → 创建费率
PUT /admin/api/billing/rates/{id}     → 更新费率
DELETE /admin/api/billing/rates/{id}  → 删除费率
GET /admin/api/billing/usage          → 用量汇总（按用户/资源聚合）
```

Pipeline penagihan: ResourceMonitor mengumpulkan setiap 5 menit → UsageAggregator mengagregasi setiap jam → BillingEngine memotong saldo setiap hari, saldo tidak cukup maka sumber daya di-suspend.

---

## 15. Komisi Afiliasi
### Sisi Pengguna

```
GET /api/affiliate/summary
  → 佣金总览（累计/待结算/可提现、链接数、转化率）

POST /api/affiliate/links
  体: { source? }
  → 生成推广链接（?ref=CODE）

GET /api/affiliate/earnings
  参数: status, page
  → 佣金明细（订单归属、比例、状态: pending/approved/paid）

POST /api/affiliate/payout
  体: { amount, method }
  → 发起提现申请
```

### Sisi Admin

```
GET /admin/api/affiliate/plans                → 佣金方案列表
POST /admin/api/affiliate/plans               → 创建佣金方案
GET /admin/api/affiliate/earnings             → 全部佣金记录
POST /admin/api/affiliate/earnings/{id}/approve → 审核佣金
GET /admin/api/affiliate/payouts              → 提现申请列表
POST /admin/api/affiliate/payouts/{id}/approve → 审核/打款提现
```

---

## 16. GraphQL
```
POST /graphql
  → 公开查询（商品、域名、帮助等只读数据）
  限制: 查询深度 5 层，复杂度 100

POST /api/graphql                          🔒 需认证
  → 完整查询（含用户数据）
```

**Operasi sensitif tetap REST-only:** pembayaran, penarikan, refund, tinjauan KYC tidak melalui GraphQL.

---

## 17. Rating Pemasok dan Ulasan Produk
### Publik

```
GET /api/regions
  → 可用区域列表（含货币/时区）

GET /api/suppliers/{supplierId}/ratings
  → 供应商评分列表（四维度: 质量/支持/交付速度/性价比，仅返回 approved）
```

### Sisi Pengguna (perlu autentikasi)

```
POST /api/products/{productId}/reviews
  体: { rating, content, images? }
  → 提交商品评价（每订单一次，审核后展示）

POST /api/supplier/ratings
  体: { supplier_id, quality, support, delivery_speed, value, comment? }
  → 提交供应商评分（每订单一次）

GET /api/supplier/ratings/me
  → 我的评分记录
```

### Sisi Admin

```
GET /admin/api/suppliers/{id}/ratings          → 全部评分（含 pending）
POST /admin/api/suppliers/ratings/{id}/approve → 审核通过
POST /admin/api/suppliers/ratings/{id}/hide    → 隐藏
```

---

## 18. Webhook Pembayaran
```
POST /api/payments/webhook/stripe
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
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
