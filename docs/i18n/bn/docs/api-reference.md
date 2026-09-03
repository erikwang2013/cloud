# CloudPlatform API ইন্টারফেস ডকুমেন্টেশন

## ওভারভিউ

**Base URL:** `https://api.example.com`

**ভার্সন কন্ট্রোল:** API ভার্সন URL পাথে নির্দিষ্ট করা হয়, যেমন `/api/v1/...`। অসমর্থিত ভার্সনে `400` রিটার্ন।

**অথেনটিকেশন পদ্ধতি:**

| প্রান্ত | পদ্ধতি | রিকোয়েস্ট হেডার |
|----|------|--------|
| ইউজার সাইড | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| অ্যাডমিন সাইড | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| সাপ্লায়ার এক্সটার্নাল API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | সিগনেচার ভেরিফিকেশন | `Stripe-Signature: ...` |

**ক্লায়েন্ট প্ল্যাটফর্ম:** সব API রিকোয়েস্টে `X-Client-Platform` হেডার বহন করার পরামর্শ, সাপোর্টেড: `ios/android/macos/windows/linux/web/harmonyos/ipados`।

**মাল্টিল্যাঙ্গুয়াল:** সব API রিকোয়েস্টে `Accept-Language` হেডার বহন করার পরামর্শ (`zh-CN` / `en-US`), ট্রান্সলেটেড টেক্সট ও JSON মাল্টিল্যাঙ্গুয়াল ফিল্ডের রিটার্ন মানকে প্রভাবিত করে। অনুপস্থিত থাকলে ডিফল্ট `en-US`।

---

## ইউনিফাইড রেসপন্স ফরম্যাট

### সফল

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### পেজিনেটেড

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### এরর

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP স্ট্যাটাস কোড

| code | বিবরণ |
|------|------|
| 0 | সফল |
| 400 | রিকোয়েস্ট প্যারামিটার এরর / অসমর্থিত API ভার্সন / অসমর্থিত ক্লায়েন্ট প্ল্যাটফর্ম |
| 401 | আনঅথেনটিকেটেড |
| 403 | অনুমতি নেই / WAF ইন্টারসেপ্ট |
| 404 | রিসোর্স নেই (firstOrFail/findOrFail মিস হলে ইউনিফাইড 404 ম্যাপ) |
| 413 | রিকোয়েস্ট বডি বড় (>10MB) |
| 414 | URL দীর্ঘ (>2KB) |
| 415 | অসমর্থিত Content-Type |
| 422 | প্যারামিটার ভ্যালিডেশন ব্যর্থ |
| 429 | রিকোয়েস্ট রেট সীমা অতিক্রম |

---

## রাউট গ্রুপ ও মিডলওয়্যার ম্যাট্রিক্স

| রাউট গ্রুপ | মিডলওয়্যার | প্রিফিক্স |
|--------|--------|------|
| পাবলিক | গ্লোবাল মিডলওয়্যার চেইন | `/health`, `/api/v1/*` |
| `/health` (ইন্টারনাল) | গ্লোবাল + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | গ্লোবাল + Encryption | `/api/v1/auth/*` |
| `/api/v1` (ইউজার) | গ্লোবাল + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (সংবেদনশীল) | গ্লোবাল + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | সাপ্লায়ার এক্সটার্নাল API |
| `/admin/api/v1` | গ্লোবাল + Encryption + Auth + AdminRole | অ্যাডমিন প্যানেল API |
| `/admin/api/v1` (সংবেদনশীল) | গ্লোবাল + Encryption + Auth + AdminRole + Confirmation | সংবেদনশীল অ্যাডমিন অপারেশন |

---

## ১. পাবলিক এন্ডপয়েন্ট

### হেলথ চেক

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### সার্ভিস স্ট্যাটাস

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

### প্রোডাক্ট

```
GET /api/v1/products
   প্যারামিটার: category_id, region_id, keyword, supplier_id, page (ডিফল্ট 1), page_size (ডিফল্ট 20, সর্বোচ্চ 50)
  → পেজিনেটেড প্রোডাক্ট লিস্ট (category, skus.regionPrices সহ)

GET /api/v1/products/search
   প্যারামিটার: q (বাধ্যতামূলক), page
  → Elasticsearch ফুল-টেক্সট সার্চ

GET /api/v1/products/{id}
  → প্রোডাক্ট ডিটেইল (category, skus, images, reviews সহ)

GET /api/v1/products/{productId}/reviews
  → রিভিউ লিস্ট + avg_rating + total + distribution
   স্ট্যাটাস এনাম: pending(অপেক্ষমাণ)/approved(অনুমোদিত)/rejected(প্রত্যাখ্যাত), শুধু approved রিটার্ন
```

### ডোমেইন

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → উপলব্ধ TLD লিস্ট (Redis ক্যাশ 1h)
```

### হেল্প সেন্টার

```
GET /api/v1/help
   প্যারামিটার: category, page
   হেডার: Accept-Language (en-US / zh-CN)
  → পেজিনেটেড হেল্প আর্টিকেল

GET /api/v1/help/categories
  → আর্টিকেল ক্যাটাগরি লিস্ট

GET /api/v1/help/{slug}
  → একটি আর্টিকেলের ডিটেইল
```

---

## ২. অথেনটিকেশন এন্ডপয়েন্ট

### ক্যাপচা

```
POST /api/v1/captcha/create
   হেডার: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### রেজিস্ট্রেশন

```
POST /api/v1/auth/register
   হেডার: X-Encrypted: 1
   বডি (এনক্রিপ্টেড): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

রেট লিমিট: 3 req/min
```

- `deviceFingerprint` (ঐচ্ছিক): রেজিস্ট্রেশনের সময় ডিভাইস ফিঙ্গারপ্রিন্ট রেকর্ড, লগইন/রিফ্রেশে ভেরিফাই; না পাঠালে ফিঙ্গারপ্রিন্ট বাইন্ডিং স্কিপ
- email/phone স্টোরের আগে Encryptable ডিটারমিনিস্টিক এনক্রিপশন (ECB, সাইফারটেক্সটে সমান কোয়েরি), ইউনিকনেস ভেরিফিকেশন ও লগইন কোয়েরি দুটোই সাইফারটেক্সটে হয়

### লগইন

```
POST /api/v1/auth/login
   হেডার: X-Encrypted: 1
   বডি (এনক্রিপ্টেড): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

রেট লিমিট: 5 req/min, ৫ বার ব্যর্থে 15min লক
```

- `login` সাইফারটেক্সটে সমান কোয়েরি হয় (Encryptable ডিটারমিনিস্টিক এনক্রিপশন), প্লেইনটেক্সট কোয়েরি এনক্রিপ্টেড কলামে হিট করে না

### Token রিফ্রেশ

```
POST /api/v1/auth/refresh
   হেডার: X-Encrypted: 1
   বডি (এনক্রিপ্টেড): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` রেজিস্ট্রেশনের সময় রেকর্ডের সাথে না মিললে → 401 `Device mismatch`; রিফ্রেশ token সাইফারটেক্সট হ্যাশ দিয়ে কোয়েরি হয়

### OAuth

সাপোর্টেড প্রোভাইডার: google, apple, facebook, x, microsoft, linkedin, github
(.env-এ `{PROVIDER}_OAUTH_CLIENT_ID` ইত্যাদি কনফিগ এনাবল/ডিসেবল করে)

```
GET /api/v1/auth/{provider}            → { url }        # অথরাইজেশন পেজে রিডাইরেক্ট (PKCE/nonce রিপ্লে-প্রতিরোধ)
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  বডি: { code, state }
```

- Apple/Microsoft id_token রিটার্ন করে, সার্ভার JWKS দিয়ে সিগনেচার, iss/aud/exp/nonce ভেরিফাই করে
- সব প্রোভাইডারে `email_verified=true` বাধ্যতামূলক, না হলে 422
- `state` অনুপস্থিত বা মেলেনি → 422 (CSRF প্রতিরোধ, ৫ মিনিট মেয়াদ)
- OAuth ফ্লো রেট লিমিট: প্রতি ৬০ সেকেন্ডে ১০ বার (redirect + callback)

### পাসওয়ার্ড রিসেট

```
POST /api/v1/auth/forgot-password
   বডি: { email }
  → ভেরিফিকেশন কোড ইমেইল পাঠানো

POST /api/v1/auth/reset-password
   বডি: { email, code, password }
  → রিসেট সফল
  → এরর মোট ৫ বার → 429 রেট লিমিট ১০ মিনিট
```

### ইমেইল ভেরিফিকেশন

```
GET /api/v1/auth/verify-email?token=xxx
  → ভেরিফিকেশন সফল
```

### SMS ভেরিফিকেশন

```
POST /api/v1/auth/send-sms
   বডি: { phone }
  → SMS ভেরিফিকেশন কোড পাঠানো (60s কুলডাউন)
```

### TOTP টু-স্টেপ ভেরিফিকেশন

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # পারসিস্টেন্ট নয়, ১০ মিনিটের মধ্যে verify করলে কার্যকর
POST /api/v1/user/totp/verify       বডি: { code } → { verified: true }   # প্রথমবার এনাবলে সফল এনাবল মেসেজ রিটার্ন
POST /api/v1/user/totp/disable      বডি: { password }             # পাসওয়ার্ড কনফার্মেশন প্রয়োজন, না হলে 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # প্রতিবার ৮টি ওয়ান-টাইম কোড জেনারেট, পাসওয়ার্ড কনফার্মেশন প্রয়োজন, না হলে 403
POST /api/v1/auth/login/recovery    বডি: { login, password, recovery_code }
```

- ইউজার TOTP এনাবল করলে লগইনে `totp_code` বাধ্যতামূলক, না হলে 401
- TOTP টানা ৫ বার ভুল → সেই ইউজার ১৫ মিনিট লক (login_lock)

---

## ৩. ইউজার এন্ডপয়েন্ট (অথেনটিকেশন প্রয়োজন)

### প্রোফাইল

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
   বডি: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC রিয়েল-নেম ভেরিফিকেশন

```
POST /api/v1/user/kyc
   বডি: { id_type, id_number, real_name, front_image, back_image }
```

### ব্যালেন্স

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
   প্যারামিটার: page
  → ব্যালেন্স পরিবর্তন রেকর্ড
```

### অ্যাড্রেস ম্যানেজমেন্ট

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
   বডি: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### সেশন ম্যানেজমেন্ট

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → নির্দিষ্ট সেশন রিভোক

DELETE /api/v1/user/account
   বডি: { confirm_password }
  → GDPR অ্যাকাউন্ট ডিলিশন
```

### নোটিফিকেশন

```
GET /api/v1/user/notifications
   প্যারামিটার: page
  → পেজিনেটেড নোটিফিকেশন লিস্ট

POST /api/v1/user/notifications/{id}/read
  → পঠিত হিসেবে চিহ্নিত

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
   বডি: { email: {order_paid: true, ...}, push: {...} }
```

### ইমেইল

```
POST /api/v1/user/resend-verify-email
  → ভেরিফিকেশন ইমেইল পুনরায় পাঠানো
```

### ফাইল আপলোড

```
POST /api/v1/upload
   বডি: multipart/form-data { file, type: avatar/kyc/attach }
   সীমা: avatar 2MB, kyc 5MB, attach 10MB
   অনুমোদিত: jpg, jpeg, png, gif, pdf
   বিবরণ: টাইপ হোয়াইটলিস্ট ভ্যালিডেশন + finfo কনটেন্ট স্নিফিং (এক্সটেনশন ও MIME মিল না হলে → 422)
```

---

## ৪. কার্ট ও অর্ডার

### কার্ট

```
POST /api/v1/cart
   বডি: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
   বডি: { quantity }
```

> অ্যামাউন্ট ফিল্ড কনভেনশন (D4/P4.2 চূড়ান্ত): সব অ্যামাউন্ট string, ৪ দশমিক (যেমন "9.9900"), number/float নিষিদ্ধ —
> MySQL DECIMAL কলামের PDO র ক আউটপুটের সাথে সামঞ্জস্যপূর্ণ, প্রিসিশন 4dp স্ট্রিং নিজেই বহন করে। অর্ডার/ব্যালেন্স/রিপোর্ট সব এন্ডপয়েন্টে প্রযোজ্য।

### অর্ডার

```
POST /api/v1/orders
  → কার্ট থেকে অর্ডার তৈরি
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
   প্যারামিটার: page, status (pending/paid/provisioning/completed/refunded, অবৈধ মানে 400)
  → আমার অর্ডার লিস্ট

GET /api/v1/orders/{id}
  → অর্ডার ডিটেইল (items, timeline সহ)

GET /api/v1/orders/{id}/payment-methods
  → উপলব্ধ পেমেন্ট চ্যানেল + প্রতিটি চ্যানেলের প্রকৃত পেমেন্ট অ্যামাউন্ট

POST /api/v1/orders/{id}/pay    🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### কুপন

```
POST /api/v1/coupons/validate
   বডি: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (যেমন "2.0000")

422: অবৈধ/এক্সপায়ার্ড/ব্যবহার শর্ত পূরণ হয়নি
```

### ইনভয়েস

```
GET /api/v1/invoices
   প্যারামিটার: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF ডাউনলোড
```

---

## ৫. রিসোর্স ম্যানেজমেন্ট

```
GET /api/v1/resources
   প্যারামিটার: page, status
  → আমার রিসোর্স লিস্ট

GET /api/v1/resources/{id}
  → রিসোর্স ডিটেইল

GET /api/v1/resources/{id}/status
  → রিসোর্সের বর্তমান স্ট্যাটাস + মেট্রিক্স

GET /api/v1/resources/{id}/console
  → VNC/কনসোল URL

POST /api/v1/resources/batch
   বডি: { action: start/stop/restart, resource_ids: [...] }
```

---

## ৬. DNS ম্যানেজমেন্ট

```
GET /api/v1/dns/{domain}
  → DNS রেকর্ড লিস্ট

POST /api/v1/dns/{domain}/records
   বডি: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 পাসওয়ার্ড কনফার্মেশন
```

---

## ৭. টিকেট

```
POST /api/v1/tickets
   বডি: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
   প্যারামিটার: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
   বডি: { content }
```

---

## ৮. সাপ্লায়ার (ইন্টারনাল API)

```
POST /api/v1/supplier/apply
   বডি: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → সেটেলমেন্ট লিস্ট

POST /api/v1/supplier/withdraw    🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
   বডি: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## ৯. সাপ্লায়ার এক্সটার্নাল API

**অথেনটিকেশন:** `Authorization: Bearer sk_xxx...` (SHA256 সিগনেচার ভেরিফিকেশন)

**রেট লিমিট:** 120 req/min (উইথড্রয়াল 10 req/min)

```
GET /api/v1/supplier/external/orders
   প্যারামিটার: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → অর্ডার ডিটেইল (শুধু এই সাপ্লায়ারের সাথে সম্পর্কিত)

GET /api/v1/supplier/external/resources
   প্যারামিটার: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
   প্যারামিটার: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
   বডি: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
   প্যারামিটার: page
```

---

## ১০. অ্যাডমিন প্যানেল API

**অথেনটিকেশন:** JWT Bearer Token + Admin রোল

### ড্যাশবোর্ড

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### ইউজার ম্যানেজমেন্ট

```
GET /admin/api/v1/users               প্যারামিটার: page, status, keyword
GET /admin/api/v1/users/export       → Excel ডাউনলোড
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status   বডি: { status }
```

### KYC রিভিউ

```
GET /admin/api/v1/kyc                 প্যারামিটার: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { confirm_password, reason }
```

### প্রোডাক্ট ম্যানেজমেন্ট

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 পাসওয়ার্ড কনফার্মেশন
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV ডাউনলোড
POST /admin/api/v1/products/import        → CSV আপলোড upsert
```

### অর্ডার ম্যানেজমেন্ট

```
GET /admin/api/v1/orders               প্যারামিটার: page, status, keyword
GET /admin/api/v1/orders/export       → Excel ডাউনলোড
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { confirm_password, amount?, reason }
```

### পেমেন্ট ম্যানেজমেন্ট

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions   প্যারামিটার: page, channel, status
GET /admin/api/v1/payments/reconcile      প্যারামিটার: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run   প্যারামিটার: date; দৈনিক রিকনসিলিয়েশন ট্রিগার
```

### রিসোর্স ও প্রোভিশনিং

```
GET /admin/api/v1/provisioning/tasks               প্যারামিটার: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
   বডি: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 পাসওয়ার্ড কনফার্মেশন
GET /admin/api/v1/provisioning/hosts
```

### সাপ্লায়ার ম্যানেজমেন্ট

```
GET /admin/api/v1/suppliers                  প্যারামিটার: page, status
GET /admin/api/v1/suppliers/export          → Excel ডাউনলোড

POST /admin/api/v1/suppliers/{id}/approve    🔒 পাসওয়ার্ড কনফার্মেশন
POST /admin/api/v1/suppliers/{id}/settle     🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 পাসওয়ার্ড কনফার্মেশন
```

### সাপ্লায়ার API Key

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
   বডি: { name }
  ← { api_key: "sk_xxx...", prefix } (শুধু একবার দেখানো হয়)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### টিকেট ম্যানেজমেন্ট

```
GET /admin/api/v1/tickets                   প্যারামিটার: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign      বডি: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### ডোমেইন ম্যানেজমেন্ট

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
   বডি: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones              প্যারামিটার: page
GET /admin/api/v1/domains/transfers          প্যারামিটার: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### নোটিফিকেশন ম্যানেজমেন্ট

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
   বডি: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log          প্যারামিটার: page
```

### কুপন

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
   বডি: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### হেল্প আর্টিকেল

```
GET /admin/api/v1/help
POST /admin/api/v1/help
   বডি: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → সফট ডিলিট (status=archived)
```

### ক্লাউড প্রোভাইডার API

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
   বডি: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → ডিসেবল (status=disabled)
```

### Webhook ম্যানেজমেন্ট

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
   বডি: { url }
DELETE /admin/api/v1/webhooks               বডি: { id }
POST /admin/api/v1/webhooks/test            বডি: { url }
```

### রিপোর্ট

```
GET /admin/api/v1/reports/revenue            প্যারামিটার: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (SUM(DECIMAL) ও bcmath অ্যাগ্রিগেশনের সাথে সামঞ্জস্যপূর্ণ)
GET /admin/api/v1/reports/supplier           প্যারামিটার: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region             প্যারামিটার: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### মনিটরিং

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### অডিট লগ

```
GET /admin/api/v1/audit-logs                 প্যারামিটার: page, user_id, action, from, to
  → পেজিনেটেড অডিট লগ (client_platform সহ)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
   বডি: { action: enable/disable/toggle/reset }
```

### সিস্টেম কনফিগ

```
PUT /admin/api/v1/system/config              🔒 পাসওয়ার্ড কনফার্মেশন
```

### প্রোডাক্ট ইমপোর্ট/এক্সপোর্ট

```
GET /admin/api/v1/products/export           → CSV ডাউনলোড
POST /admin/api/v1/products/import          → CSV আপলোড upsert
```

### সাপ্লায়ার + ইউজার এক্সপোর্ট

```
GET /admin/api/v1/suppliers/export          → Excel ডাউনলোড
GET /admin/api/v1/users/export              → Excel ডাউনলোড
GET /admin/api/v1/orders/export             → Excel ডাউনলোড
```

---

## ১১. SSL সার্টিফিকেট

### ইউজার সাইড

```
GET /api/v1/ssl/plans
  → SSL প্ল্যান লিস্ট (DV/OV/EV, দাম register/renew/transfer সহ)

GET /api/v1/ssl-certs
  → আমার সার্টিফিকেট লিস্ট (status সহ: pending/active/expired/revoked)

GET /api/v1/ssl-certs/{id}
  → সার্টিফিকেট ডিটেইল (ডোমেইন, ইস্যুকারী সংস্থা, মেয়াদ, রিনিউ স্ট্যাটাস)

GET /api/v1/ssl-certs/{id}/download
  → সার্টিফিকেট ফাইল ডাউনলোড (সার্টিফিকেট চেইন + প্রাইভেট কী)

POST /api/v1/ssl-certs/{id}/auto-renew
   বডি: { auto_renew: true/false }
  → অটো রিনিউ টগল
```

### অ্যাডমিন সাইড

```
GET /admin/api/v1/ssl/plans              → প্ল্যান লিস্ট
POST /admin/api/v1/ssl/plans             → প্ল্যান তৈরি
PUT /admin/api/v1/ssl/plans/{id}         → প্ল্যান আপডেট
DELETE /admin/api/v1/ssl/plans/{id}      → প্ল্যান ডিলিট
GET /admin/api/v1/ssl/certs              → সব সার্টিফিকেট
POST /admin/api/v1/ssl/certs/{id}/revoke → সার্টিফিকেট রিভোক
```

---

## ১২. অবজেক্ট স্টোরেজ

S3-কমপ্যাটিবল অবজেক্ট স্টোরেজ, প্রিসাইনড URL দিয়ে আপলোড/ডাউনলোড, সিক্রেট বাইরে যায় না।

```
GET /api/v1/storage/buckets
  → আমার স্টোরেজ বাকেট লিস্ট (ইউসেজ, স্ট্যাটাস)

GET /api/v1/storage/buckets/{id}
  → স্টোরেজ বাকেট ডিটেইল

POST /api/v1/storage/buckets/{id}/presign-upload
   বডি: { filename, content_type, size }
  → { upload_url, object_key } প্রিসাইনড আপলোড URL (সময়সীমাযুক্ত)

POST /api/v1/storage/buckets/{id}/presign-download
   বডি: { object_key }
  → প্রিসাইনড ডাউনলোড URL (সময়সীমাযুক্ত)

GET /api/v1/storage/buckets/{id}/credentials
  → অস্থায়ী অ্যাক্সেস ক্রেডেনশিয়াল (স্বল্পমেয়াদী, SDK সরাসরি আপলোডের জন্য)
```

---

## ১৩. CDN অ্যাক্সিলারেশন

### ইউজার সাইড

```
GET /api/v1/cdn/domains
  → আমার CDN ডোমেইন লিস্ট (অরিজিন, স্ট্যাটাস, প্ল্যান)

POST /api/v1/cdn/domains
  বডি: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
         origin_type (server|storage), origin_value, cert_config? }
  → CDN ডোমেইন তৈরি (প্রোভাইডার সাইডে তৈরি করে অরিজিন বাইন্ড)
  → provider_type=aliyun|tencent হলে ডোমেইনে ICP রেজিস্ট্রেশন প্রয়োজন (না থাকলে 4002)
  → রেসপন্সে requires_icp_registration প্রম্পট ফিল্ড থাকে
  → ক্রেডেনশিয়াল রেজল্যুশন: আগে ডোমেইনের বাইন্ডেড অ্যাকাউন্ট (provider_account_id),
    না হলে code=cdn-{provider_type} এর অ্যাক্টিভ provider_apis অ্যাকাউন্ট, সব না থাকলে env কনফিগ ফলব্যাক

GET /api/v1/cdn/domains/{id}
  → CDN ডোমেইন ডিটেইল

DELETE /api/v1/cdn/domains/{id}
  → CDN ডোমেইন ডিলিট (প্রোভাইডার সাইড ডোমেইন ডিসেবল করে, ইডেম্পোটেন্ট)

POST /api/v1/cdn/domains/{id}/purge
  বডি: { urls: ["https://cdn.example.com/path"] }
  → ক্যাশ ক্লিয়ার (ডুপ্লিকেট URL অটো ডিডুপ, ইডেম্পোটেন্ট; সর্বোচ্চ ১০০টি)

GET /api/v1/cdn/domains/{id}/stats
  → ডোমেইন ওভারভিউ (cdn_domain / provider_type / plan / status / purged_at)
```

### অ্যাডমিন সাইড

```
GET /admin/api/v1/cdn/domains            → সব CDN ডোমেইন (মালিকানাধীন ইউজার সহ)
PUT /admin/api/v1/cdn/domains/{id}       → ডোমেইন প্ল্যান আপডেট (plan হোয়াইটলিস্ট: standard | pro | enterprise)
```

অ্যাডমিন CDN রুট `RbacMiddleware('cdn.manage')` এ মাউন্ট করা, প্ল্যান পরিবর্তন অডিট লগে লেখা হয় (`admin_cdn_update_plan`)। প্রোভাইডার অ্যাকাউন্ট ক্রেডেনশিয়াল `/admin/api/v1/providers` CRUD দিয়ে ম্যানটেইন হয় (RbacMiddleware `provider.config`, `code` কনভেনশন `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, ক্রেডেনশিয়াল Encryptable এনক্রিপশনে স্টোর)।

### CDN এরর কোড

| code | ব্যাখ্যা |
|------|---------|
| 4001 | CDN প্যারামিটার অনুপস্থিত/অবৈধ (urls খালি, provider_type অবৈধ, ডোমেইন ফরম্যাট ভুল) |
| 4002 | ডোমেইনের ICP রেজিস্ট্রেশন নেই (Aliyun/Tencent API রিজেক্ট করলে ম্যাপ করা হয়) |
| 4003 | CDN প্রোভাইডার ক্রেডেনশিয়াল কনফিগার করা নেই (অ্যাকাউন্ট অনুপস্থিত/ডিসেবল, স্ট্রিক্ট স্ন্যাপশট সাইলেন্ট সুইচ করে না) |
| 4005 | CDN ক্যাশ রিফ্রেশ ব্যর্থ |
| 5001 | CDN প্রোভাইডার API কল ব্যর্থ |

> অন্যের CDN রিসোর্স (অন্যদের/অস্তিত্বহীন রিসোর্স) ইউনিফর্মলি **404** রিটার্ন করে (findOrFail ম্যাপিং, রিসোর্স অস্তিত্ব লিক করে না), আলাদা বিজনেস কোড নেই।

---

## ১৪. পে-অ্যাস-ইউ-গো বিলিং

```
GET /admin/api/v1/billing/rates          → বিলিং রেট লিস্ট (রিসোর্স টাইপ/স্পেক অনুযায়ী)
POST /admin/api/v1/billing/rates         → রেট তৈরি
PUT /admin/api/v1/billing/rates/{id}     → রেট আপডেট
DELETE /admin/api/v1/billing/rates/{id}  → রেট ডিলিট
GET /admin/api/v1/billing/usage          → ইউসেজ সারাংশ (ইউজার/রিসোর্স অনুযায়ী অ্যাগ্রিগেট)
```

বিলিং পাইপলাইন: ResourceMonitor প্রতি ৫ মিনিটে কালেক্ট → UsageAggregator প্রতি ঘণ্টায় অ্যাগ্রিগেট → BillingEngine প্রতিদিন ডেবিট, ব্যালেন্স অপর্যাপ্ত হলে রিসোর্স সাসপেন্ড।

---

## ১৫. অ্যাফিলিয়েট কমিশন

### ইউজার সাইড

```
GET /api/v1/affiliate/summary
  → কমিশন ওভারভিউ (জমা/অপেক্ষমাণ সেটেলমেন্ট/উইথড্রেবল, লিংক সংখ্যা, কনভার্সন রেট)

POST /api/v1/affiliate/links
   বডি: { source? }
  → প্রোমো লিংক জেনারেশন (?ref=CODE)

GET /api/v1/affiliate/earnings
   প্যারামিটার: status, page
  → কমিশন বিস্তারিত (অর্ডার অ্যাট্রিবিউশন, রেট, স্ট্যাটাস: pending/approved/paid)

POST /api/v1/affiliate/payout
   বডি: { amount, method }
  → উইথড্রয়াল আবেদন শুরু
```

### অ্যাডমিন সাইড

```
GET /admin/api/v1/affiliate/plans                → কমিশন প্ল্যান লিস্ট
POST /admin/api/v1/affiliate/plans               → কমিশন প্ল্যান তৈরি
GET /admin/api/v1/affiliate/earnings             → সব কমিশন রেকর্ড
POST /admin/api/v1/affiliate/earnings/{id}/approve → কমিশন রিভিউ
GET /admin/api/v1/affiliate/payouts              → উইথড্রয়াল আবেদন লিস্ট
POST /admin/api/v1/affiliate/payouts/{id}/approve → উইথড্রয়াল রিভিউ/পেমেন্ট
```

---

## ১৬. GraphQL

```
POST /graphql
  → পাবলিক কোয়েরি (প্রোডাক্ট, ডোমেইন, হেল্প ইত্যাদি রিড-অনলি ডেটা)
   সীমা: কোয়েরি ডেপথ ৫ লেয়ার, কমপ্লেক্সিটি ১০০

POST /api/v1/graphql                          🔒 অথেনটিকেশন প্রয়োজন
  → সম্পূর্ণ কোয়েরি (ইউজার ডেটাসহ)
```

**সংবেদনশীল অপারেশন REST-only থাকে:** পেমেন্ট, উইথড্রয়াল, রিফান্ড, KYC রিভিউ GraphQL দিয়ে যায় না।

---

## ১৭. সাপ্লায়ার রেটিং ও প্রোডাক্ট রিভিউ

### পাবলিক

```
GET /api/v1/regions
  → উপলব্ধ রিজিয়ন লিস্ট (কারেন্সি/টাইমজোন সহ)

GET /api/v1/suppliers/{supplierId}/ratings
  → সাপ্লায়ার রেটিং লিস্ট (চার-ডাইমেনশন: কোয়ালিটি/সাপোর্ট/ডেলিভারি স্পিড/ভ্যালু, শুধু approved রিটার্ন)
```

### ইউজার সাইড (অথেনটিকেশন প্রয়োজন)

```
POST /api/v1/products/{productId}/reviews
   বডি: { rating, content, images? }
  → প্রোডাক্ট রিভিউ জমা (প্রতি অর্ডারে একবার, রিভিউর পর ডিসপ্লে)

POST /api/v1/supplier/ratings
   বডি: { supplier_id, quality, support, delivery_speed, value, comment? }
  → সাপ্লায়ার রেটিং জমা (প্রতি অর্ডারে একবার)

GET /api/v1/supplier/ratings/me
  → আমার রেটিং রেকর্ড
```

### অ্যাডমিন সাইড

```
GET /admin/api/v1/suppliers/{id}/ratings          → সব রেটিং (pending সহ)
POST /admin/api/v1/suppliers/ratings/{id}/approve → রিভিউ অ্যাপ্রুভ
POST /admin/api/v1/suppliers/ratings/{id}/hide    → হাইড
```

---

## ১৮. পেমেন্ট Webhook

```
POST /api/v1/payments/webhook/stripe
   হেডার: Stripe-Signature: ...
  → Stripe কলব্যাক (পেমেন্ট সফল/রিফান্ড/ডিসপিউট), সিগনেচার ভেরিফিকেশন ব্যর্থ হলে 400
```

---

## ১৯. WebSocket ইভেন্ট

**সংযোগ:** `ws://host:8282` (docker ডিপ্লয়মেন্টে WS nginx রিভার্স প্রক্সির মাধ্যমে, সংযোগ ঠিকানা `ws://host/ws/`, 8282 শুধু কনটেইনারের ভেতরে এক্সপোজ)

অথেনটিকেশন সংযোগের পর প্রথম মেসেজে হয় (token URL/অ্যাক্সেস লগে যায় না): সংযোগ স্থাপনের পর আগে `auth` মেসেজ পাঠাতে হবে, ৩০ সেকেন্ডের মধ্যে অথেনটিকেট না হলে সংযোগ বিচ্ছিন্ন; অথেনটিকেশন ব্যর্থ হলে `error` রিটার্ন ও বিচ্ছিন্ন।

### ক্লায়েন্ট → সার্ভার

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### সার্ভার → ক্লায়েন্ট

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### পুশ ইভেন্ট

| ইভেন্ট | ডেটা | ট্রিগার সময় |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | পেমেন্ট সফল |
| `resource.provisioned` | `{resource_id, type, ip_address}` | রিসোর্স প্রোভিশনিং সম্পন্ন |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | রিসোর্স এক্সপায়ার হতে চলেছে |
| `ticket.updated` | `{ticket_id, title, status}` | টিকেট স্ট্যাটাস পরিবর্তন |
| `notification.new` | `{notification_id, title, body}` | নতুন নোটিফিকেশন |

---

## ২০. এরর কোড রেফারেন্স

| code | বিবরণ |
|------|------|
| 400 | প্যারামিটার এরর / অসমর্থিত API ভার্সন / অসমর্থিত ক্লায়েন্ট প্ল্যাটফর্ম |
| 401 | আনঅথেনটিকেটেড / Token এক্সপায়ার্ড / অবৈধ API Key / ডিভাইস ফিঙ্গারপ্রিন্ট মেলেনি (Device mismatch) |
| 403 | অনুমতি নেই / নন-সাপ্লায়ার রোল / WAF ইন্টারসেপ্ট / পাসওয়ার্ড কনফার্মেশন ব্যর্থ |
| 404 | রিসোর্স নেই (firstOrFail/findOrFail মিস হলে ইউনিফাইড 404 ম্যাপ) |
| 413 | রিকোয়েস্ট বডি 10MB-র বেশি |
| 414 | URL 2KB-র বেশি |
| 415 | Content-Type হোয়াইটলিস্টে নেই (শুধু application/json, multipart/form-data, x-www-form-urlencoded অনুমোদিত) |
| 422 | প্যারামিটার ভ্যালিডেশন ব্যর্থ (ইমেইল রেজিস্টার্ড / ইনভেন্টরি অপর্যাপ্ত / উইথড্রেবল ব্যালেন্স অপর্যাপ্ত / আবেদন আগে জমা হয়েছে) |
| 429 | রিকোয়েস্ট রেট সীমা অতিক্রম |
| 500 | সার্ভার এরর |

### সাধারণ 422 মেসেজ

| মেসেজ | এন্ডপয়েন্ট |
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
