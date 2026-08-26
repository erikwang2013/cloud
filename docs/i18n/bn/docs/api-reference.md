# CloudPlatform API ইন্টারফেস ডকুমেন্টেশন

## ওভারভিউ

**Base URL:** `https://api.example.com`

**ভার্সন কন্ট্রোল:** HTTP রিকোয়েস্ট হেডার `X-Api-Version: v1` দিয়ে নির্দিষ্ট করা হয়। অনুপস্থিত থাকলে ডিফল্ট `v1`, অসমর্থিত ভার্সনে `400` রিটার্ন। ভার্সন URL পাথে থাকে না।

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
| পাবলিক | গ্লোবাল মিডলওয়্যার চেইন | `/health`, `/api/*` |
| `/health` (ইন্টারনাল) | গ্লোবাল + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | গ্লোবাল + Encryption | `/api/auth/*` |
| `/api` (ইউজার) | গ্লোবাল + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (সংবেদনশীল) | গ্লোবাল + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | সাপ্লায়ার এক্সটার্নাল API |
| `/admin/api` | গ্লোবাল + Encryption + Auth + AdminRole | অ্যাডমিন প্যানেল API |
| `/admin/api` (সংবেদনশীল) | গ্লোবাল + Encryption + Auth + AdminRole + Confirmation | সংবেদনশীল অ্যাডমিন অপারেশন |

---

## ১. পাবলিক এন্ডপয়েন্ট

### হেলথ চেক

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### সার্ভিস স্ট্যাটাস

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

### প্রোডাক্ট

```
GET /api/products
   প্যারামিটার: category_id, region_id, keyword, supplier_id, page (ডিফল্ট 1), page_size (ডিফল্ট 20, সর্বোচ্চ 50)
  → পেজিনেটেড প্রোডাক্ট লিস্ট (category, skus.regionPrices সহ)

GET /api/products/search
   প্যারামিটার: q (বাধ্যতামূলক), page
  → Elasticsearch ফুল-টেক্সট সার্চ

GET /api/products/{id}
  → প্রোডাক্ট ডিটেইল (category, skus, images, reviews সহ)

GET /api/products/{productId}/reviews
  → রিভিউ লিস্ট + avg_rating + total + distribution
   স্ট্যাটাস এনাম: pending(অপেক্ষমাণ)/approved(অনুমোদিত)/rejected(প্রত্যাখ্যাত), শুধু approved রিটার্ন
```

### ডোমেইন

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → উপলব্ধ TLD লিস্ট (Redis ক্যাশ 1h)
```

### হেল্প সেন্টার

```
GET /api/help
   প্যারামিটার: category, page
   হেডার: Accept-Language (en-US / zh-CN)
  → পেজিনেটেড হেল্প আর্টিকেল

GET /api/help/categories
  → আর্টিকেল ক্যাটাগরি লিস্ট

GET /api/help/{slug}
  → একটি আর্টিকেলের ডিটেইল
```

---

## ২. অথেনটিকেশন এন্ডপয়েন্ট

### ক্যাপচা

```
POST /api/captcha/create
   হেডার: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### রেজিস্ট্রেশন

```
POST /api/auth/register
   হেডার: X-Encrypted: 1
   বডি (এনক্রিপ্টেড): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

রেট লিমিট: 3 req/min
```

- `deviceFingerprint` (ঐচ্ছিক): রেজিস্ট্রেশনের সময় ডিভাইস ফিঙ্গারপ্রিন্ট রেকর্ড, লগইন/রিফ্রেশে ভেরিফাই; না পাঠালে ফিঙ্গারপ্রিন্ট বাইন্ডিং স্কিপ
- email/phone স্টোরের আগে Encryptable ডিটারমিনিস্টিক এনক্রিপশন (ECB, সাইফারটেক্সটে সমান কোয়েরি), ইউনিকনেস ভেরিফিকেশন ও লগইন কোয়েরি দুটোই সাইফারটেক্সটে হয়

### লগইন

```
POST /api/auth/login
   হেডার: X-Encrypted: 1
   বডি (এনক্রিপ্টেড): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

রেট লিমিট: 5 req/min, ৫ বার ব্যর্থে 15min লক
```

- `login` সাইফারটেক্সটে সমান কোয়েরি হয় (Encryptable ডিটারমিনিস্টিক এনক্রিপশন), প্লেইনটেক্সট কোয়েরি এনক্রিপ্টেড কলামে হিট করে না

### Token রিফ্রেশ

```
POST /api/auth/refresh
   হেডার: X-Encrypted: 1
   বডি (এনক্রিপ্টেড): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` রেজিস্ট্রেশনের সময় রেকর্ডের সাথে না মিললে → 401 `Device mismatch`; রিফ্রেশ token সাইফারটেক্সট হ্যাশ দিয়ে কোয়েরি হয়

### OAuth

সাপোর্টেড প্রোভাইডার: google, apple, facebook, x, microsoft, linkedin, github
(.env-এ `{PROVIDER}_OAUTH_CLIENT_ID` ইত্যাদি কনফিগ এনাবল/ডিসেবল করে)

```
GET /api/auth/{provider}            → { url }        # অথরাইজেশন পেজে রিডাইরেক্ট (PKCE/nonce রিপ্লে-প্রতিরোধ)
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  বডি: { code, state }
```

- Apple/Microsoft id_token রিটার্ন করে, সার্ভার JWKS দিয়ে সিগনেচার, iss/aud/exp/nonce ভেরিফাই করে
- সব প্রোভাইডারে `email_verified=true` বাধ্যতামূলক, না হলে 422
- `state` অনুপস্থিত বা মেলেনি → 422 (CSRF প্রতিরোধ, ৫ মিনিট মেয়াদ)
- OAuth ফ্লো রেট লিমিট: প্রতি ৬০ সেকেন্ডে ১০ বার (redirect + callback)

### পাসওয়ার্ড রিসেট

```
POST /api/auth/forgot-password
   বডি: { email }
  → ভেরিফিকেশন কোড ইমেইল পাঠানো

POST /api/auth/reset-password
   বডি: { email, code, password }
  → রিসেট সফল
  → এরর মোট ৫ বার → 429 রেট লিমিট ১০ মিনিট
```

### ইমেইল ভেরিফিকেশন

```
GET /api/auth/verify-email?token=xxx
  → ভেরিফিকেশন সফল
```

### SMS ভেরিফিকেশন

```
POST /api/auth/send-sms
   বডি: { phone }
  → SMS ভেরিফিকেশন কোড পাঠানো (60s কুলডাউন)
```

### TOTP টু-স্টেপ ভেরিফিকেশন

```
POST /api/user/totp/setup        → { secret, qr_url }        # পারসিস্টেন্ট নয়, ১০ মিনিটের মধ্যে verify করলে কার্যকর
POST /api/user/totp/verify       বডি: { code } → { verified: true }   # প্রথমবার এনাবলে সফল এনাবল মেসেজ রিটার্ন
POST /api/user/totp/disable      বডি: { password }             # পাসওয়ার্ড কনফার্মেশন প্রয়োজন, না হলে 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # প্রতিবার ৮টি ওয়ান-টাইম কোড জেনারেট, পাসওয়ার্ড কনফার্মেশন প্রয়োজন, না হলে 403
POST /api/auth/login/recovery    বডি: { login, password, recovery_code }
```

- ইউজার TOTP এনাবল করলে লগইনে `totp_code` বাধ্যতামূলক, না হলে 401
- TOTP টানা ৫ বার ভুল → সেই ইউজার ১৫ মিনিট লক (login_lock)

---

## ৩. ইউজার এন্ডপয়েন্ট (অথেনটিকেশন প্রয়োজন)

### প্রোফাইল

```
GET /api/user/profile
PUT /api/user/profile
   বডি: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC রিয়েল-নেম ভেরিফিকেশন

```
POST /api/user/kyc
   বডি: { id_type, id_number, real_name, front_image, back_image }
```

### ব্যালেন্স

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
   প্যারামিটার: page
  → ব্যালেন্স পরিবর্তন রেকর্ড
```

### অ্যাড্রেস ম্যানেজমেন্ট

```
GET /api/user/addresses
POST /api/user/addresses
   বডি: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### সেশন ম্যানেজমেন্ট

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → নির্দিষ্ট সেশন রিভোক

DELETE /api/user/account
   বডি: { confirm_password }
  → GDPR অ্যাকাউন্ট ডিলিশন
```

### নোটিফিকেশন

```
GET /api/user/notifications
   প্যারামিটার: page
  → পেজিনেটেড নোটিফিকেশন লিস্ট

POST /api/user/notifications/{id}/read
  → পঠিত হিসেবে চিহ্নিত

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
   বডি: { email: {order_paid: true, ...}, push: {...} }
```

### ইমেইল

```
POST /api/user/resend-verify-email
  → ভেরিফিকেশন ইমেইল পুনরায় পাঠানো
```

### ফাইল আপলোড

```
POST /api/upload
   বডি: multipart/form-data { file, type: avatar/kyc/attach }
   সীমা: avatar 2MB, kyc 5MB, attach 10MB
   অনুমোদিত: jpg, jpeg, png, gif, pdf
   বিবরণ: টাইপ হোয়াইটলিস্ট ভ্যালিডেশন + finfo কনটেন্ট স্নিফিং (এক্সটেনশন ও MIME মিল না হলে → 422)
```

---

## ৪. কার্ট ও অর্ডার

### কার্ট

```
POST /api/cart
   বডি: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
   বডি: { quantity }
```

> অ্যামাউন্ট ফিল্ড কনভেনশন (D4/P4.2 চূড়ান্ত): সব অ্যামাউন্ট string, ৪ দশমিক (যেমন "9.9900"), number/float নিষিদ্ধ —
> MySQL DECIMAL কলামের PDO র ক আউটপুটের সাথে সামঞ্জস্যপূর্ণ, প্রিসিশন 4dp স্ট্রিং নিজেই বহন করে। অর্ডার/ব্যালেন্স/রিপোর্ট সব এন্ডপয়েন্টে প্রযোজ্য।

### অর্ডার

```
POST /api/orders
  → কার্ট থেকে অর্ডার তৈরি
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
   প্যারামিটার: page, status (pending/paid/provisioning/completed/refunded, অবৈধ মানে 400)
  → আমার অর্ডার লিস্ট

GET /api/orders/{id}
  → অর্ডার ডিটেইল (items, timeline সহ)

GET /api/orders/{id}/payment-methods
  → উপলব্ধ পেমেন্ট চ্যানেল + প্রতিটি চ্যানেলের প্রকৃত পেমেন্ট অ্যামাউন্ট

POST /api/orders/{id}/pay    🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### কুপন

```
POST /api/coupons/validate
   বডি: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (যেমন "2.0000")

422: অবৈধ/এক্সপায়ার্ড/ব্যবহার শর্ত পূরণ হয়নি
```

### ইনভয়েস

```
GET /api/invoices
   প্যারামিটার: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → PDF ডাউনলোড
```

---

## ৫. রিসোর্স ম্যানেজমেন্ট

```
GET /api/resources
   প্যারামিটার: page, status
  → আমার রিসোর্স লিস্ট

GET /api/resources/{id}
  → রিসোর্স ডিটেইল

GET /api/resources/{id}/status
  → রিসোর্সের বর্তমান স্ট্যাটাস + মেট্রিক্স

GET /api/resources/{id}/console
  → VNC/কনসোল URL

POST /api/resources/batch
   বডি: { action: start/stop/restart, resource_ids: [...] }
```

---

## ৬. DNS ম্যানেজমেন্ট

```
GET /api/dns/{domain}
  → DNS রেকর্ড লিস্ট

POST /api/dns/{domain}/records
   বডি: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 পাসওয়ার্ড কনফার্মেশন
```

---

## ৭. টিকেট

```
POST /api/tickets
   বডি: { resource_id?, category, priority?, title, content }

GET /api/tickets
   প্যারামিটার: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
   বডি: { content }
```

---

## ৮. সাপ্লায়ার (ইন্টারনাল API)

```
POST /api/supplier/apply
   বডি: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → সেটেলমেন্ট লিস্ট

POST /api/supplier/withdraw    🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
   বডি: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## ৯. সাপ্লায়ার এক্সটার্নাল API

**অথেনটিকেশন:** `Authorization: Bearer sk_xxx...` (SHA256 সিগনেচার ভেরিফিকেশন)

**রেট লিমিট:** 120 req/min (উইথড্রয়াল 10 req/min)

```
GET /api/supplier/external/orders
   প্যারামিটার: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → অর্ডার ডিটেইল (শুধু এই সাপ্লায়ারের সাথে সম্পর্কিত)

GET /api/supplier/external/resources
   প্যারামিটার: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
   প্যারামিটার: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
   বডি: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
   প্যারামিটার: page
```

---

## ১০. অ্যাডমিন প্যানেল API

**অথেনটিকেশন:** JWT Bearer Token + Admin রোল

### ড্যাশবোর্ড

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### ইউজার ম্যানেজমেন্ট

```
GET /admin/api/users               প্যারামিটার: page, status, keyword
GET /admin/api/users/export       → Excel ডাউনলোড
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status   বডি: { status }
```

### KYC রিভিউ

```
GET /admin/api/kyc                 প্যারামিটার: page, status

POST /admin/api/kyc/{id}/approve   🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { confirm_password, reason }
```

### প্রোডাক্ট ম্যানেজমেন্ট

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 পাসওয়ার্ড কনফার্মেশন
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → CSV ডাউনলোড
POST /admin/api/products/import        → CSV আপলোড upsert
```

### অর্ডার ম্যানেজমেন্ট

```
GET /admin/api/orders               প্যারামিটার: page, status, keyword
GET /admin/api/orders/export       → Excel ডাউনলোড
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { confirm_password, amount?, reason }
```

### পেমেন্ট ম্যানেজমেন্ট

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions   প্যারামিটার: page, channel, status
GET /admin/api/payments/reconcile      প্যারামিটার: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run   প্যারামিটার: date; দৈনিক রিকনসিলিয়েশন ট্রিগার
```

### রিসোর্স ও প্রোভিশনিং

```
GET /admin/api/provisioning/tasks               প্যারামিটার: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
   বডি: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 পাসওয়ার্ড কনফার্মেশন
GET /admin/api/provisioning/hosts
```

### সাপ্লায়ার ম্যানেজমেন্ট

```
GET /admin/api/suppliers                  প্যারামিটার: page, status
GET /admin/api/suppliers/export          → Excel ডাউনলোড

POST /admin/api/suppliers/{id}/approve    🔒 পাসওয়ার্ড কনফার্মেশন
POST /admin/api/suppliers/{id}/settle     🔒 পাসওয়ার্ড কনফার্মেশন
   বডি: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 পাসওয়ার্ড কনফার্মেশন
```

### সাপ্লায়ার API Key

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
   বডি: { name }
  ← { api_key: "sk_xxx...", prefix } (শুধু একবার দেখানো হয়)

DELETE /admin/api/suppliers/api-keys/{id}
```

### টিকেট ম্যানেজমেন্ট

```
GET /admin/api/tickets                   প্যারামিটার: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign      বডি: { user_id }
POST /admin/api/tickets/{id}/close
```

### ডোমেইন ম্যানেজমেন্ট

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
   বডি: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones              প্যারামিটার: page
GET /admin/api/domains/transfers          প্যারামিটার: page
POST /admin/api/domains/transfers/{id}/approve
```

### নোটিফিকেশন ম্যানেজমেন্ট

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
   বডি: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log          প্যারামিটার: page
```

### কুপন

```
GET /admin/api/coupons
POST /admin/api/coupons
   বডি: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### হেল্প আর্টিকেল

```
GET /admin/api/help
POST /admin/api/help
   বডি: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → সফট ডিলিট (status=archived)
```

### ক্লাউড প্রোভাইডার API

```
GET /admin/api/providers
POST /admin/api/providers
   বডি: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → ডিসেবল (status=disabled)
```

### Webhook ম্যানেজমেন্ট

```
GET /admin/api/webhooks
POST /admin/api/webhooks
   বডি: { url }
DELETE /admin/api/webhooks               বডি: { id }
POST /admin/api/webhooks/test            বডি: { url }
```

### রিপোর্ট

```
GET /admin/api/reports/revenue            প্যারামিটার: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (SUM(DECIMAL) ও bcmath অ্যাগ্রিগেশনের সাথে সামঞ্জস্যপূর্ণ)
GET /admin/api/reports/supplier           প্যারামিটার: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region             প্যারামিটার: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### মনিটরিং

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### অডিট লগ

```
GET /admin/api/audit-logs                 প্যারামিটার: page, user_id, action, from, to
  → পেজিনেটেড অডিট লগ (client_platform সহ)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
   বডি: { action: enable/disable/toggle/reset }
```

### সিস্টেম কনফিগ

```
PUT /admin/api/system/config              🔒 পাসওয়ার্ড কনফার্মেশন
```

### প্রোডাক্ট ইমপোর্ট/এক্সপোর্ট

```
GET /admin/api/products/export           → CSV ডাউনলোড
POST /admin/api/products/import          → CSV আপলোড upsert
```

### সাপ্লায়ার + ইউজার এক্সপোর্ট

```
GET /admin/api/suppliers/export          → Excel ডাউনলোড
GET /admin/api/users/export              → Excel ডাউনলোড
GET /admin/api/orders/export             → Excel ডাউনলোড
```

---

## ১১. SSL সার্টিফিকেট

### ইউজার সাইড

```
GET /api/ssl/plans
  → SSL প্ল্যান লিস্ট (DV/OV/EV, দাম register/renew/transfer সহ)

GET /api/ssl-certs
  → আমার সার্টিফিকেট লিস্ট (status সহ: pending/active/expired/revoked)

GET /api/ssl-certs/{id}
  → সার্টিফিকেট ডিটেইল (ডোমেইন, ইস্যুকারী সংস্থা, মেয়াদ, রিনিউ স্ট্যাটাস)

GET /api/ssl-certs/{id}/download
  → সার্টিফিকেট ফাইল ডাউনলোড (সার্টিফিকেট চেইন + প্রাইভেট কী)

POST /api/ssl-certs/{id}/auto-renew
   বডি: { auto_renew: true/false }
  → অটো রিনিউ টগল
```

### অ্যাডমিন সাইড

```
GET /admin/api/ssl/plans              → প্ল্যান লিস্ট
POST /admin/api/ssl/plans             → প্ল্যান তৈরি
PUT /admin/api/ssl/plans/{id}         → প্ল্যান আপডেট
DELETE /admin/api/ssl/plans/{id}      → প্ল্যান ডিলিট
GET /admin/api/ssl/certs              → সব সার্টিফিকেট
POST /admin/api/ssl/certs/{id}/revoke → সার্টিফিকেট রিভোক
```

---

## ১২. অবজেক্ট স্টোরেজ

S3-কমপ্যাটিবল অবজেক্ট স্টোরেজ, প্রিসাইনড URL দিয়ে আপলোড/ডাউনলোড, সিক্রেট বাইরে যায় না।

```
GET /api/storage/buckets
  → আমার স্টোরেজ বাকেট লিস্ট (ইউসেজ, স্ট্যাটাস)

GET /api/storage/buckets/{id}
  → স্টোরেজ বাকেট ডিটেইল

POST /api/storage/buckets/{id}/presign-upload
   বডি: { filename, content_type, size }
  → { upload_url, object_key } প্রিসাইনড আপলোড URL (সময়সীমাযুক্ত)

POST /api/storage/buckets/{id}/presign-download
   বডি: { object_key }
  → প্রিসাইনড ডাউনলোড URL (সময়সীমাযুক্ত)

GET /api/storage/buckets/{id}/credentials
  → অস্থায়ী অ্যাক্সেস ক্রেডেনশিয়াল (স্বল্পমেয়াদী, SDK সরাসরি আপলোডের জন্য)
```

---

## ১৩. CDN অ্যাক্সিলারেশন

### ইউজার সাইড

```
GET /api/cdn/domains
  → আমার CDN ডোমেইন লিস্ট (অরিজিন, স্ট্যাটাস, প্ল্যান)

GET /api/cdn/domains/{id}
  → CDN ডোমেইন ডিটেইল

POST /api/cdn/domains/{id}/purge
  → ক্যাশ ক্লিয়ার (পুরো সাইট বা নির্দিষ্ট URL লিস্ট)

GET /api/cdn/domains/{id}/stats
   প্যারামিটার: range (day/week/month)
  → ট্রাফিক/রিকোয়েস্ট সংখ্যা/হিট রেট পরিসংখ্যান
```

### অ্যাডমিন সাইড

```
GET /admin/api/cdn/domains            → সব CDN ডোমেইন
PUT /admin/api/cdn/domains/{id}       → ডোমেইন প্ল্যান/কনফিগ আপডেট
```

---

## ১৪. পে-অ্যাস-ইউ-গো বিলিং

```
GET /admin/api/billing/rates          → বিলিং রেট লিস্ট (রিসোর্স টাইপ/স্পেক অনুযায়ী)
POST /admin/api/billing/rates         → রেট তৈরি
PUT /admin/api/billing/rates/{id}     → রেট আপডেট
DELETE /admin/api/billing/rates/{id}  → রেট ডিলিট
GET /admin/api/billing/usage          → ইউসেজ সারাংশ (ইউজার/রিসোর্স অনুযায়ী অ্যাগ্রিগেট)
```

বিলিং পাইপলাইন: ResourceMonitor প্রতি ৫ মিনিটে কালেক্ট → UsageAggregator প্রতি ঘণ্টায় অ্যাগ্রিগেট → BillingEngine প্রতিদিন ডেবিট, ব্যালেন্স অপর্যাপ্ত হলে রিসোর্স সাসপেন্ড।

---

## ১৫. অ্যাফিলিয়েট কমিশন

### ইউজার সাইড

```
GET /api/affiliate/summary
  → কমিশন ওভারভিউ (জমা/অপেক্ষমাণ সেটেলমেন্ট/উইথড্রেবল, লিংক সংখ্যা, কনভার্সন রেট)

POST /api/affiliate/links
   বডি: { source? }
  → প্রোমো লিংক জেনারেশন (?ref=CODE)

GET /api/affiliate/earnings
   প্যারামিটার: status, page
  → কমিশন বিস্তারিত (অর্ডার অ্যাট্রিবিউশন, রেট, স্ট্যাটাস: pending/approved/paid)

POST /api/affiliate/payout
   বডি: { amount, method }
  → উইথড্রয়াল আবেদন শুরু
```

### অ্যাডমিন সাইড

```
GET /admin/api/affiliate/plans                → কমিশন প্ল্যান লিস্ট
POST /admin/api/affiliate/plans               → কমিশন প্ল্যান তৈরি
GET /admin/api/affiliate/earnings             → সব কমিশন রেকর্ড
POST /admin/api/affiliate/earnings/{id}/approve → কমিশন রিভিউ
GET /admin/api/affiliate/payouts              → উইথড্রয়াল আবেদন লিস্ট
POST /admin/api/affiliate/payouts/{id}/approve → উইথড্রয়াল রিভিউ/পেমেন্ট
```

---

## ১৬. GraphQL

```
POST /graphql
  → পাবলিক কোয়েরি (প্রোডাক্ট, ডোমেইন, হেল্প ইত্যাদি রিড-অনলি ডেটা)
   সীমা: কোয়েরি ডেপথ ৫ লেয়ার, কমপ্লেক্সিটি ১০০

POST /api/graphql                          🔒 অথেনটিকেশন প্রয়োজন
  → সম্পূর্ণ কোয়েরি (ইউজার ডেটাসহ)
```

**সংবেদনশীল অপারেশন REST-only থাকে:** পেমেন্ট, উইথড্রয়াল, রিফান্ড, KYC রিভিউ GraphQL দিয়ে যায় না।

---

## ১৭. সাপ্লায়ার রেটিং ও প্রোডাক্ট রিভিউ

### পাবলিক

```
GET /api/regions
  → উপলব্ধ রিজিয়ন লিস্ট (কারেন্সি/টাইমজোন সহ)

GET /api/suppliers/{supplierId}/ratings
  → সাপ্লায়ার রেটিং লিস্ট (চার-ডাইমেনশন: কোয়ালিটি/সাপোর্ট/ডেলিভারি স্পিড/ভ্যালু, শুধু approved রিটার্ন)
```

### ইউজার সাইড (অথেনটিকেশন প্রয়োজন)

```
POST /api/products/{productId}/reviews
   বডি: { rating, content, images? }
  → প্রোডাক্ট রিভিউ জমা (প্রতি অর্ডারে একবার, রিভিউর পর ডিসপ্লে)

POST /api/supplier/ratings
   বডি: { supplier_id, quality, support, delivery_speed, value, comment? }
  → সাপ্লায়ার রেটিং জমা (প্রতি অর্ডারে একবার)

GET /api/supplier/ratings/me
  → আমার রেটিং রেকর্ড
```

### অ্যাডমিন সাইড

```
GET /admin/api/suppliers/{id}/ratings          → সব রেটিং (pending সহ)
POST /admin/api/suppliers/ratings/{id}/approve → রিভিউ অ্যাপ্রুভ
POST /admin/api/suppliers/ratings/{id}/hide    → হাইড
```

---

## ১৮. পেমেন্ট Webhook

```
POST /api/payments/webhook/stripe
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
