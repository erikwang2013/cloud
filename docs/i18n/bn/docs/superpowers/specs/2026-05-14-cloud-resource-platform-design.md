# বৈশ্বিক ক্লাউড রিসোর্স ট্রেডিং প্ল্যাটফর্ম — সিস্টেম ডিজাইন

## প্রকল্প সংক্ষিপ্ত বিবরণ

বিশ্বব্যাপী ব্যবহারকারীদের জন্য ক্লাউড রিসোর্স ট্রেডিং প্ল্যাটফর্ম, যা স্ব-পরিচালিত + তৃতীয় পক্ষের সরবরাহকারীদের মিশ্র মডেল সমর্থন করে। ব্যবহারকারীরা সার্ভার, IP, ক্লাউড ডিস্ক, ডোমেইন ইত্যাদি ক্লাউড পণ্য কিনতে পারেন। সম্পূর্ণ স্বয়ংক্রিয় রিসোর্স প্রভিশনিং, একাধিক পেমেন্ট চ্যানেল, একাধিক মুদ্রা, একাধিক ভাষা।

### টেকনোলজি স্ট্যাক

| স্তর | প্রযুক্তি |
|------|------|
| ব্যবহারকারী অ্যাপ | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| অ্যাডমিন প্যানেল | webman-admin |
| সার্ভার সাইড | PHP webman (মডুলার মনোলিথ) |
| ডেটাবেস | MySQL 8.0 (মাস্টার-স্লেভ) |
| ক্যাশ/কিউ | Redis (ক্যাশ + Session + কিউ) |
| স্টোরেজ | S3/OSS + CDN |
| মনিটরিং | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. মডিউল বিভাজন (12টি মূল মডিউল)

| মডিউল | দায়িত্ব |
|------|------|
| **User** | রেজিস্ট্রেশন/লগইন (OAuth+ইমেইল+মোবাইল), KYC রিয়েল-নেম ভেরিফিকেশন, সদস্য লেভেল, ব্যালেন্স অ্যাকাউন্ট |
| **Product** | পণ্য সংজ্ঞা (SKU), মাল্টি-রিজিয়ন প্রাইসিং, স্টক ম্যানেজমেন্ট, ক্যাটাগরি, সার্চ, রিভিউ |
| **Order** | কার্ট, অর্ডার প্লেসমেন্ট, অর্ডার লাইফসাইকেল (পেন্ডিং→পেইড→প্রভিশনিং→সম্পন্ন→রিফান্ড), রিনিউ/আপগ্রেড |
| **Payment** | পেমেন্ট চ্যানেল রাউটিং, মাল্টি-কারেন্সি কোটেশন, এক্সচেঞ্জ রেট, রিফান্ড, রিকনসাইলেশন |
| **Provisioning** | বিভিন্ন ক্লাউড ভেন্ডর API ইন্টিগ্রেশন, স্বয়ংক্রিয়ভাবে রিসোর্স তৈরি/রিনিউ/ধ্বংস |
| **Domain** | ডোমেইন কোয়েরি, রেজিস্ট্রেশন, ট্রান্সফার, রিনিউ, DNS ম্যানেজমেন্ট |
| **Supplier** | সরবরাহকারী অনবোর্ডিং, অনুমোদন, পণ্য লিস্টিং, সেটেলমেন্ট, কমিশন |
| **Monitor** | রিসোর্স স্ট্যাটাস প্রোব, ইউসেজ কালেকশন, অ্যালার্ট রুল |
| **Ticket** | টিকিট সাবমিশন, অ্যাসাইনমেন্ট, SLA ট্র্যাকিং |
| **Notification** | ইমেইল/এসএমএস/অ্যাপ পুশ/ইন-অ্যাপ মেসেজ, মাল্টি-টেমপ্লেট মাল্টি-ল্যাঙ্গুয়েজ |
| **Report** | রাজস্ব রিপোর্ট, সরবরাহকারী সেটেলমেন্ট রিপোর্ট, বিক্রয় ট্রেন্ড |
| **I18n** | মাল্টি-ল্যাঙ্গুয়েজ এন্ট্রি, মাল্টি-কারেন্সি এক্সচেঞ্জ রেট, মাল্টি-টাইমজোন |

---

## 2. মূল ডেটা মডেল

### ইউজার সেন্টার (User)

- **users** — ইউজার মাস্টার টেবিল (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — ইউজার প্রোফাইল (user_id, avatar, nickname, country)
- **user_kyc** — রিয়েল-নেম ভেরিফিকেশন (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — ব্যালেন্স অ্যাকাউন্ট (user_id, currency, balance, frozen_balance)
- **user_balance_log** — ব্যালেন্স পরিবর্তন লগ (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — ইউজার ঠিকানা (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### প্রোডাক্ট সেন্টার (Product)

- **product_categories** — পণ্য ক্যাটাগরি (id, parent_id, name, icon, sort)
- **products** — পণ্য মাস্টার টেবিল (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — রিজিয়ন প্রাইসিং (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — পণ্য ছবি (product_id, url, sort)
- **product_attributes** — কাস্টম অ্যাট্রিবিউট (product_id, key, value)
- **product_reviews** — পণ্য রিভিউ (user_id, product_id, order_id, rating, content)
- **regions** — রিজিয়ন টেবিল (id, name, continent, country, city, data_center, status)

### অর্ডার সেন্টার (Order)

- **carts** — কার্ট (user_id, sku_id, region_id, quantity, cycle)
- **orders** — অর্ডার মাস্টার টেবিল (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — অর্ডার আইটেম (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — অর্ডার টাইমলাইন (order_id, status, operator, remark, created_at)
- **order_invoices** — ইনভয়েস (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — রিফান্ড রেকর্ড (order_id, user_id, amount, reason, status, handled_by)

### পেমেন্ট সেন্টার (Payment)

- **payment_channels** — পেমেন্ট চ্যানেল কনফিগ (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — ট্রানজেকশন রেকর্ড (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — রিকনসাইলেশন টেবিল (date, channel_id, channel_total, system_total, diff, status)

### রিসোর্স প্রভিশনিং (Provisioning)

- **resources** — রিসোর্স মাস্টার টেবিল (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — সার্ভার বিবরণ (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP বিবরণ (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — ক্লাউড ডিস্ক বিবরণ (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — ডোমেইন বিবরণ (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — প্রভিশনিং টাস্ক (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — ক্লাউড ভেন্ডর API কনফিগ (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### ফিজিক্যাল মেশিন রিসোর্স ম্যানেজমেন্ট (Host & IP Pool)

স্ব-পরিচালিত ফিজিক্যাল সার্ভারে Proxmox VE (কমিউনিটি এডিশন, ফ্রি) ব্যবহার করে ভার্চুয়াল মেশিন পরিচালনা করা হয়; REST API-এর মাধ্যমে VM তৈরি/ম্যানেজ, IP বরাদ্দ এবং ডিস্ক মাউন্ট করা হয়।

- **host_machines** — হোস্ট মেশিন (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP পুল (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP বরাদ্দ রেকর্ড (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM ডিস্ক বিবরণ (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — ডিস্ক সম্প্রসারণ রেকর্ড (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### সরবরাহকারী (Supplier)

- **suppliers** — সরবরাহকারী মাস্টার টেবিল (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — সরবরাহকারী-পণ্য সম্পর্ক (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — সেটেলমেন্ট রেকর্ড (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — উত্তোলন রেকর্ড (supplier_id, amount, method, account_info, status)

### ডোমেইন সার্ভিস (Domain)

- **domain_tlds** — সমর্থিত TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — ডোমেইন ট্রান্সফার (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS জোন (domain_name, user_id, zone_id)
- **dns_records** — DNS রেকর্ড (zone_id, type, name, value, ttl, priority)

### টিকিট ও নোটিফিকেশন (Ticket & Notification)

- **tickets** — টিকিট (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — টিকিট মেসেজ (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — নোটিফিকেশন রেকর্ড (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — নোটিফিকেশন টেমপ্লেট (code, name, channels, title_template, body_template, variables)

---

## 3. API ডিজাইন স্পেসিফিকেশন

### ভার্সন ম্যানেজমেন্ট

API ভার্সন HTTP রিকোয়েস্ট হেডার `X-Api-Version`-এর মাধ্যমে নির্ধারিত হয়, URL পাথে নয়। সার্ভার সাইড মিডলওয়্যারের মাধ্যমে ভার্সন হেডারটি অভ্যন্তরীণ রুটে ইনজেক্ট করে।

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**সমর্থিত ভার্সন**: `v1` (ডিফল্ট, হেডার না থাকলে স্বয়ংক্রিয়ভাবে ব্যবহৃত হয়)

**ভার্সন নিয়ন্ত্রণ প্রক্রিয়া**: `VersionMiddleware` সব `/api/*` এবং `/admin/api/*` পাথের `X-Api-Version` হেডার যাচাই করে; অনুপস্থিত থাকলে ডিফল্ট `v1`, অসমর্থিত ভার্সনে `400` রিটার্ন হয়। URL পাথে আর ভার্সন নম্বর থাকে না।

**নতুন ভার্সন যোগ করার ধাপ**:
1. `VersionMiddleware::SUPPORTED` অ্যারেতে ভার্সন নম্বর যোগ করুন
2. `route.php`-এ নতুন ভার্সনের রুট গ্রুপ নিবন্ধন করুন
3. কন্ট্রোলারে `$request->properties['api_version']` দিয়ে ভার্সন নম্বর পেয়ে ভিন্ন ভিন্ন প্রসেসিং করুন

### RESTful রাউটিং

```
统一前缀: /api
管理后台: /admin/api
```

**রুট গ্রুপ ও মিডলওয়্যার ম্যাট্রিক্স:**

| রুট গ্রুপ | মিডলওয়্যার | এন্ডপয়েন্ট উদাহরণ |
|--------|--------|---------|
| পাবলিক (প্রিফিক্সবিহীন) | গ্লোবাল মিডলওয়্যার চেইন | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | গ্লোবাল + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (ইউজার) | গ্লোবাল + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (সংবেদনশীল) | গ্লোবাল + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | গ্লোবাল + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (সংবেদনশীল) | গ্লোবাল + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### ইউনিফাইড রেসপন্স ফরম্যাট

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

### অথেনটিকেশন স্কিম

| এন্ড | পদ্ধতি |
|----|------|
| ইউজার এন্ড | JWT (access_token 2h + refresh_token 30d) + TOTP টু-স্টেপ ভেরিফিকেশন + রিকভারি কোড |
| অ্যাডমিন এন্ড | JWT (access_token 2h + refresh_token 7d) |
| সরবরাহকারী API | API Key (sk_ প্রিফিক্স, SHA256 হ্যাশে সংরক্ষিত, শুধুমাত্র তৈরি করার সময় একবার দেখানো হয়) |
| ক্লাউড ভেন্ডর কলব্যাক | সিগনেচার ভেরিফিকেশন (HMAC-SHA256) |

**বাস্তবায়িত অথেনটিকেশন ফিচার**:
- ইমেইল রেজিস্ট্রেশন + ইমেইল ভেরিফিকেশন লিংক
- মোবাইল নম্বর রেজিস্ট্রেশন + Twilio SMS ভেরিফিকেশন কোড (60s কুলডাউন + IP রেট লিমিট ৫ বার/ঘণ্টা)
- Google OAuth লগইন / Apple Sign In
- পাসওয়ার্ড ভুলে গেলে (ইমেইল ভেরিফিকেশন কোড + Redis 10min TTL)
- TOTP টু-স্টেপ ভেরিফিকেশন (QR কোড স্ক্যান করে সেটআপ, রিকভারি কোড ব্যাকআপ)
- অ্যাক্টিভ সেশন ম্যানেজমেন্ট (লগইন ডিভাইস দেখুন/রিভোক করুন, client_platform তথ্যসহ)
- GDPR অ্যাকাউন্ট মুছে ফেলা (পাসওয়ার্ড কনফার্মেশন + সফট ডিলিট + সব token রিভোক)
- অস্বাভাবিক লগইন অ্যালার্ট (নতুন IP থেকে লগইনে ইমেইল নোটিফিকেশন)
- লগইন লক (৫ বার ব্যর্থ হলে ১৫ মিনিট লক)

**ইউজার অথেনটিকেশন ফ্লো:**

```
注册流程                             登录流程
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(点击位置)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 扫描]                         → [WAF 扫描]
   → [限流: 3 req/min]                  → [限流: 5 req/min]
   → [密码 bcrypt(cost=12)]             → [Hash::check()]
   → [设备指纹: sha256(UA+IP)]           → [设备指纹: sha256(UA+IP)]
   → [client_platform 记录]              → [client_platform 记录]
   → User::create()                    → [失败 5 次 → 锁 15min]
   → RefreshToken::create()            → [新 IP 检测 → 邮件告警]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (验证邮件)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 授权 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. 验证 Google token
                                      5. 新建或查找用户
                                      6. 签发 token（含 client_platform）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 两步验证                          会话管理
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 成功
   或 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 密码确认 + 软删除 + 全部 token 撤销
登录锁定机制
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### মাল্টি-ল্যাঙ্গুয়েজ স্কিম

- রিকোয়েস্ট হেডার: Accept-Language: zh-CN / en-US / ja-JP
- JSON কলামে মাল্টি-ল্যাঙ্গুয়েজ টেক্সট সংরক্ষণ: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n ফাইলে স্ট্যাটিক টেক্সট ম্যানেজ করা হয়; ফ্রন্টএন্ড ও ব্যাকএন্ডে আলাদা সেট

---

## 4. নিরাপত্তা প্রতিরক্ষা ব্যবস্থা

### স্তরভিত্তিক প্রতিরক্ষা মডেল

```
┌─────────────────────────────────────────────────────┐
│ 第一层: 网络边界防护                                    │
│   DDoS清洗 / WAF / IP黑白名单 / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ 第二层: 传输与应用防护                                  │
│   HTTPS+TLS1.3 / CSP / CORS / JWT鉴权 / 限流          │
├─────────────────────────────────────────────────────┤
│ 第三层: 数据与存储安全                                  │
│   加密存储 / 脱敏 / 审计日志 / 备份                     │
├─────────────────────────────────────────────────────┤
│ 第四层: 虚拟化与资源隔离                                 │
│   Proxmox安全加固 / VM间隔离 / 网络隔离                 │
├─────────────────────────────────────────────────────┤
│ 第五层: 运营与风控                                     │
│   操作审计 / 异常检测 / 告警 / 应急响应                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 নেটওয়ার্ক বাউন্ডারি সুরক্ষা

#### DDoS সুরক্ষা

```
用户请求 → CDN (Cloudflare / 阿里云CDN)
              │
              ├── JS质询 / 验证码 (可疑流量)
              ├── 速率限制 (每IP每秒请求数)
              ├── 区域封禁 (阻断指定国家/地区)
              │
              ▼
          源站 (Nginx + webman)
```

| স্তর | ব্যবস্থা | ব্যাখ্যা |
|------|------|------|
| CDN স্তর | স্বয়ংক্রিয় DDoS ক্লিনিং | Cloudflare ফ্রি প্ল্যানেই L3/L4 সুরক্ষা সমর্থন করে |
| CDN স্তর | Bot Management | দূষিত ক্রলার/বট অর্ডার স্ক্রিপ্ট শনাক্ত ও ব্লক |
| Nginx স্তর | limit_req_zone | প্রতি IP 10 req/s, সীমা ছাড়ালে 429 |
| Nginx স্তর | limit_conn | প্রতি IP সর্বোচ্চ ২০টি কনকারেন্ট সংযোগ |
| webman স্তর | টোকেন বাকেট রেট-লিমিট মিডলওয়্যার | ইউজার/এন্ডপয়েন্ট গ্র্যানুলারিটিতে সঠিক রেট লিমিট |

#### WAF রুল (webman মিডলওয়্যার)

WAF মিডলওয়্যার ৮টি ক্যাটাগরির রেজেক্স রুল গ্রুপ দিয়ে রিকোয়েস্ট স্ক্যান করে; রুল কনফিগার করা থাকে `config/security.php`-এ, হট-রিলোড হয়, রিস্টার্ট লাগে না। স্ক্যানের আওতায় থাকে রিকোয়েস্ট বডি JSON, URL পাথ + কোয়েরি স্ট্রিং, User-Agent এবং র-বডি (JSON এনকোডিং এস্কেপ প্রতিরোধের জন্য)।

**৮টি ক্যাটাগরির ডিটেকশন রুল (৪৫+টি):**

| ক্যাটাগরি | কভারেজ |
|------|---------|
| SQL ইনজেকশন | সিঙ্গেল কোট/কমেন্ট চিহ্ন, SQL কিওয়ার্ড, হেক্স এনকোডিং, ইউনিয়ন কোয়েরি ভ্যারিয়েন্ট, চিরসত্য শর্ত (`' OR '1'='1`), টাইম-ব্লাইন্ড (`sleep`/`benchmark`), স্ট্যাকড কোয়েরি, মাল্টিলাইন কমেন্ট বাইপাস |
| XSS | HTML ট্যাগ (এনকোডিং ভ্যারিয়েন্টসহ), Script ট্যাগ ও ভ্যারিয়েন্ট, ১৩টি JS ইভেন্ট হ্যান্ডলার, JS গ্লোবাল অবজেক্ট/বিপজ্জনক ফাংশন, `javascript:` সিউডো-প্রোটোকল, HTML এন্টিটি এনকোডিং, Data URI ইনজেকশন, ইনলাইন ইভেন্ট অ্যাট্রিবিউট |
| কমান্ড ইনজেকশন | পাইপ চিহ্নের পর কমান্ড (`\| cat`), সেমিকোলনের পর কমান্ড (`; whoami`), `$(cmd)` এবং ব্যাকটিক কমান্ড সাবস্টিটিউশন, বিচ্ছিন্ন কমান্ড কিওয়ার্ড |
| ফাইল ইনক্লুশন | পাথ ট্রাভার্সাল (মাল্টি-এনকোডিং), PHP সিউডো-প্রোটোকল (`php://`/`data://`/`phar://`), অ্যাবসলিউট পাথ প্রোব (`/etc/`/`C:\`), Null বাইট ইনজেকশন |
| HTTP হেডার ইনজেকশন | CRLF নিউলাইন ইনজেকশন (`%0d%0a`/`\r\n`), Host/Cookie/Set-Cookie হেডার ইনজেকশন |
| **SSRF** | ইন্টারনাল IPv4 অ্যাড্রেস (127.x/10.x/172.16-31.x/192.168.x), localhost অ্যালিয়াস, ক্লাউড মেটাডেটা এন্ডপয়েন্ট (169.254.169.254), file:// প্রোটোকল |
| **NoSQL ইনজেকশন** | MongoDB অপারেটর ($where/$gt/$regex/$or ইত্যাদি), $where JS ইনজেকশন, Redis বিপজ্জনক কমান্ড (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **ওপেন রিডাইরেক্ট** | redirect_uri/return_url/next/callback ইত্যাদি প্যারামিটারে বাহ্যিক URL ডিটেকশন, ডাবল-এনকোডিং বাইপাস |

**রিকোয়েস্ট-লেভেল সুরক্ষা:**

| সুরক্ষা আইটেম | ব্যবস্থা |
|--------|------|
| রিকোয়েস্ট বডি সাইজ লিমিট | সর্বোচ্চ 10MB (অতিক্রম করলে 413) |
| URL দৈর্ঘ্য লিমিট | সর্বোচ্চ 2KB (অতিক্রম করলে 414, ReDoS প্রতিরোধ) |
| Content-Type হোয়াইটলিস্ট | শুধুমাত্র application/json, multipart/form-data, application/x-www-form-urlencoded অনুমোদিত |

**WAF ডিটেকশন ফ্লো:**

```
请求进入
  │
  ▼
1. 获取待扫描文本
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 请求体
   │     └── false → serialize() 回退
   ├── mb_substr(path + queryString, 0, 2048)                # URL（防 ReDoS 截断）
   ├── User-Agent 头                                          # UA
   └── file_get_contents('php://input')                      # 原始体（防 JSON 编码逃逸）
  │
  ▼
2. 加载规则（从 config/security.php）
   ├── security.waf.sqli_patterns               (9 条)
   ├── security.waf.xss_patterns                (8 条)
   ├── security.waf.cmd_injection_patterns      (5 条)
   ├── security.waf.file_inclusion_patterns     (4 条)
   ├── security.waf.header_injection_patterns   (2 条)
   ├── security.waf.ssrf_patterns               (6 条)
   ├── security.waf.nosql_injection_patterns    (3 条)
   └── security.waf.open_redirect_patterns      (2 条)
   → array_merge() + array_unique()
  │
  ▼
3. 逐条匹配
   foreach patterns as pattern:
     match($pattern, $input) ───→ 命中 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 命中 → 返回 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ 命中 →
     match($pattern, $raw)   ───→ 命中 →
  │
  ▼
4. match() 严格检查
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 命中 ✓
   ├── $result === 0    → 未命中（安全放行）
   └── $result === false → 模式错误 → error_log() → 作为未命中处理
  │
  ▼
5. 全部未命中 → $next($request) 放行到下一中间件
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### IP ব্ল্যাকলিস্ট/হোয়াইটলিস্ট

```
黑名单:
- 已知恶意 IP 库 (定期同步 AbuseIPDB)
- 频繁触发 WAF 规则的 IP (自动加入，Redis TTL 24h)
- 暴力破解登录的 IP (5次失败 → 锁定 30min)

白名单:
- Proxmox 宿主机 IP
- 云厂商回调 IP 段
- 支付网关 webhook IP 段
- 管理员办公网络 IP (可选)
```

#### Geo-Blocking

```php
// GeoIP2 库 (MaxMind)
$country = geoip($request->getRealIp());

// 可配置的阻断列表
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 ট্রান্সপোর্ট ও অ্যাপ্লিকেশন সুরক্ষা

#### গ্লোবাল মিডলওয়্যার এক্সিকিউশন চেইন

সব HTTP রিকোয়েস্ট নিম্নোক্ত ক্রমে মিডলওয়্যারগুলোর মধ্য দিয়ে যায়; প্রতিটি মিডলওয়্যার স্বাধীনভাবে টেস্টযোগ্য:

```
请求 → VersionMiddleware        # X-Api-Version 校验（缺失默认 v1，无效返回 400）
     → CorsMiddleware            # CORS 跨域响应头
     → ClientPlatformMiddleware  # X-Client-Platform 识别（8 种平台），注入 $request->properties
     → WafMiddleware             # 8 类 45+ 规则安全扫描（SQLi/XSS/命令注入/文件包含/头注入/SSRF/NoSQL/开放重定向）
     → LocaleMiddleware          # Accept-Language 解析，设置区域
     → HashidRequestMiddleware   # 请求参数 hashid → 真实 ID 解码
     → MaintenanceMiddleware     # 维护模式（IP 白名单放行）
     ↓
  [路由中间件—按路由组附加]
     → EncryptionMiddleware      # AES-256-GCM 请求/响应体加密
     → Captcha                   # 点击验证码校验（登录/注册前）
     → AuthMiddleware            # JWT Bearer Token 验证 + 角色注入
     → AdminRoleMiddleware       # 管理员 RBAC 权限检查
     → ConfirmationMiddleware    # 敏感操作二次密码确认（5 次失败锁 15min）
     ↓
     控制器
```

#### প্রতিটি মিডলওয়্যারের দায়িত্ব

| মিডলওয়্যার | নিবন্ধন পদ্ধতি | দায়িত্ব |
|--------|---------|------|
| `VersionMiddleware` | গ্লোবাল | `X-Api-Version` হেডার যাচাই, অনুপস্থিত থাকলে ডিফল্ট `v1`, অসমর্থিত ভার্সনে `400` |
| `CorsMiddleware` | গ্লোবাল | OPTIONS প্রিফ্লাইট হ্যান্ডলিং, Origin রিফ্লেক্ট করে `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | গ্লোবাল | `X-Client-Platform` হেডার যাচাই করে ক্লায়েন্ট OS প্ল্যাটফর্ম শনাক্ত (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), `$request->properties['client_platform']`-এ ইনজেক্ট |
| `WafMiddleware` | গ্লোবাল(service) + admin ইনস্ট্যান্স | ৮ ক্যাটাগরি ৪৫+ রুল + রিকোয়েস্ট সাইজ লিমিট + Content-Type যাচাই, ব্লক করলে অডিট লগ |
| `LocaleMiddleware` | গ্লোবাল | `Accept-Language` হেডার পার্স করে লোকেল সেট |
| `HashidRequestMiddleware` | গ্লোবাল | রিকোয়েস্টের hashid স্ট্রিং স্বয়ংক্রিয়ভাবে আসল ইন্টিজার ID-তে ডিকোড |
| `MaintenanceMiddleware` | গ্লোবাল | `MAINTENANCE_MODE` এনভায়রনমেন্ট ভেরিয়েবল চেক, হোয়াইটলিস্টেড IP পাস |
| `EncryptionMiddleware` | রুট গ্রুপ (/api/auth, /api, /admin/api) | AES-256-GCM রিকোয়েস্ট/রেসপন্স বডি এনক্রিপশন, `X-Encrypted: 1` হেডারে ট্রিগার |
| `AuthMiddleware` | রুট গ্রুপ (/api, /admin/api) | JWT HS256 access_token যাচাই, `$request->userId` ও `$request->userRole` ইনজেক্ট |
| `AdminRoleMiddleware` | রুট গ্রুপ (/admin/api) | অ্যাডমিন RBAC পারমিশন চেক |
| `ConfirmationMiddleware` | রুট গ্রুপ (সংবেদনশীল অপারেশন) | সেকেন্ডারি পাসওয়ার্ড কনফার্মেশন, Redis ফেইল কাউন্টার, ৫ বার ব্যর্থ হলে ১৫ মিনিট লক |

#### ClientPlatform মিডলওয়্যার বিবরণ

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**ডেটা ফ্লো**: মিডলওয়্যার ইনজেকশন → `AuditLogger` স্বয়ংক্রিয় রেকর্ড → `AuthService::issueTokens()` `refresh_tokens`-এ লেখে → `GET /api/user/sessions` প্ল্যাটফর্ম তথ্য রিটার্ন করে

#### HTTPS বাধ্যতামূলক

```nginx
# Nginx 配置
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### JWT সিকিউরিটি হার্ডেনিং

```
- access_token 有效期 2h，refresh_token 有效期 30d
- 密钥使用 RSA256 (非对称)，定期轮换 (90天)
- jti (JWT ID) 存入 Redis 实现主动吊销
- refresh_token 绑定设备指纹 (User-Agent + IP 段)
- 换发 refresh_token 时旧 token 立即失效 (rotation)
- 敏感操作 (支付/销毁资源) 需二次验证

设备指纹:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 表记录此指纹，换发时校验
```

#### পাসওয়ার্ড নীতি

```
- bcrypt 加密，cost factor = 12
- 最小 8 字符，必须包含大小写字母 + 数字
- 注册/登录连续失败 5 次 → 账号锁定 15 分钟
- 密码修改后，所有已签发 token 立即失效
- 支持 TOTP 两步验证 (用户可选开启)
```

#### CORS নীতি

```php
// webman 中间件
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### ফাইল আপলোড সুরক্ষা

```
- 白名单校验扩展名 (仅允许: jpg, jpeg, png, pdf, gif)
- 校验文件 MIME 类型 (不允许伪造 Content-Type)
- 文件大小限制: 头像 2MB, KYC 证件 5MB, 附件 10MB
- 上传后重命名: {uuid}.{ext}, 不保留原始文件名
- 图片二次处理: GD/Imagick 去除 EXIF + 元数据
- 存储路径在 web 不可访问目录, 通过 PHP 代理读取
- 病毒扫描: ClamAV (KYC 证件/用户上传文件)
```

---

### 4.3 ডেটা ও স্টোরেজ সুরক্ষা

#### সংবেদনশীল ডেটা এনক্রিপশন

```
加密算法: AES-256-GCM (带认证的加密，防篡改)
密钥管理: 主密钥存于环境变量，每个字段使用独立派生密钥

需要加密存储的字段:
| 数据类型 | 字段 | 加密方式 |
|----------|------|----------|
| 密码 | users.password_hash | bcrypt (单向) |
| 支付密钥 | payment_channels.api_key | AES-256-GCM |
| 云厂商密钥 | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 证件号 | user_kyc.id_number | AES-256-GCM |
| 支付账号 | 提现账号 | AES-256-GCM |
| 登录密码(VNC) | resource_servers.login_password | AES-256-GCM |

密钥派生:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### লগ ডিম্যাস্কিং (ডেটা স্যানিটাইজেশন)

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### ডেটাবেস সুরক্ষা

```
- MySQL 使用 prepared statement (Eloquent 自动处理)
- 数据库访问账号最小权限原则:
  - app_user: SELECT, INSERT, UPDATE, DELETE (无 DDL)
  - migration_user: DDL 权限 (仅迁移时使用，IP 限制)
  - read_user: SELECT 只读 (报表/数据分析使用)
- 连接使用 SSL/TLS (PHP PDO SSL options)
- 数据库端口不对公网开放 (仅内网可访问)
- 定期备份: 全量备份 1天, binlog 实时同步
```

#### ডেটা ব্যাকআপ ও রিকভারি

```
备份策略:
- MySQL: 每日全量 + binlog 实时增量
- Redis: RDB 每小时 + AOF 实时持久化
- 用户上传文件: S3/OSS 自动多副本 + 跨区域复制
- Proxmox VM 快照: 每周一次 (保留 4 周)
- 备份加密: AES-256 加密后存储

恢复演练:
- 每季度执行一次灾难恢复演练
- 恢复时间目标 (RTO): < 4 小时
- 恢复点目标 (RPO): < 1 小时
```

---

### 4.4 ভার্চুয়ালাইজেশন ও রিসোর্স আইসোলেশন

#### Proxmox সিকিউরিটি হার্ডেনিং

```
1. API 访问控制:
   - Proxmox API 仅监听内网 IP (不绑定公网)
   - Token 权限最小化: 每个 role 仅授予必要权限
   - API 端口 (8006) 仅允许 PHP 应用服务器 IP 访问 (iptables)

2. SSH 加固:
   - 禁用密码登录，仅允许密钥认证
   - 禁用 root 登录，使用专用管理账户
   - SSH 端口改为非标准端口 (减少扫描)
   - Fail2ban: 5 次失败锁定 1 小时

3. 系统更新:
   - Proxmox 订阅安全更新邮件列表
   - 定期 apt update && apt upgrade
   - 内核 livepatch (Canonical Livepatch Service)

4. 防火墙 (iptables/nftables):
   - 默认拒绝所有入站
   - 仅开放: 8006 (仅应用服务器IP), SSH端口 (仅管理IP)
   - VM 网桥与宿主机管理网络的隔离
```

#### VM-গুলোর মধ্যে আইসোলেশন

```
- 每个 VM 使用独立的虚拟网桥 VLAN
- 禁止 VM 间通信 (Proxmox 防火墙规则 + VLAN 隔离)
- 用户仅能通过公网 IP 访问自己的 VM
- VM 资源限制 (cgroup): 防止单个 VM 耗尽宿主机资源
  - CPU limit: 购买的核数上限
  - RAM limit: 购买的容量上限
  - Disk IOPS limit: 防止磁盘争用
  - Network bandwidth limit: 购买的带宽上限
```

#### IP বরাদ্দ সুরক্ষা

```
- IP 分配记录完整审计 (谁、何时、分配了什么 IP)
- IP 释放后冷却期 24h (防止 IP 被立即分配给其他人导致的误用)
- IP 黑名单: 被投诉/滥用的 IP 标记为不可分配
- IP 使用监控: 定期检查分配的 IP 是否正常使用中
```

---

### 4.5 পেমেন্ট সুরক্ষা

```
1. PCI DSS 合规:
   - 信用卡数据不经过自有服务器 (Stripe Elements / Checkout)
   - card_token 由 Stripe 前端直接生成，后端仅接收 token
   - 不在日志/数据库中存储任何 CVV/完整卡号

2. 加密货币:
   - 收款私钥冷存储 (离线签名)
   - 热钱包仅保留日常周转额度
   - 收款地址生成后验证校验和
   - 大额交易 ( > $10000) 人工审核后手动确认

3. 支付防欺诈:
   - 同一用户/IP 短时间内高频支付 → 风控冻结
   - 新注册用户大额支付 → 人工审核
   - 支付金额异常 (与商品价格不匹配) → 阻断
   - 退款率过高的用户 → 标记风控

4. 回调验签:
   - Stripe: 验证 webhook signature (stripe-signature header)
   - Coinbase: 验证 webhook signature (X-CC-Webhook-Signature header)
   - 支付宝: 验证 notify_id 回调支付宝服务器二次确认
   - 所有回调: 验证 IP 是否为已知支付网关 IP 段
```

#### রিফান্ড সুরক্ষা

```
- 退款必须经过二级审批 (客服发起 → 管理员确认)
- 退款前校验: 订单状态、退款时限、退款次数
- 退款金额不能超过原订单实付金额
- 原路退回: 支付通道退款接口 + 余额退回
- 退款互斥锁 (Redis): 防止并发重复退款
```

---

### 4.6 অ্যাক্সেস কন্ট্রোল ও পারমিশন

#### RBAC মডেল

```
角色层级:
  super_admin    (超级管理员 — 全部权限)
  admin          (管理员 — 除系统配置外全部)
  finance        (财务 — 支付/对账/退款/结算)
  support        (客服 — 用户/订单/工单管理)
  supplier       (供应商 — 自己的商品/订单/结算)
  user           (普通用户 — 自己的资源/订单/工单)

权限定义:
  {module}.{action}
  例: order.view, order.create, order.refund, resource.destroy

权限检查中间件:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### API রেট লিমিট

```php
// webman 限流中间件 (Redis 令牌桶)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### সরবরাহকারী ডেটা আইসোলেশন

```
数据隔离原则:
- 供应商只能查询和操作自己的资源
- 所有涉及 supplier_id 的查询自动追加 WHERE supplier_id = auth()->supplier_id

实现方式:
  // 全局 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // 在 Product/Order 等 Model 上注册
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 অপারেশনাল অডিট

```
审计日志记录内容:
- 操作者 ID、IP、User-Agent
- 操作时间
- 操作模块 (哪个菜单/接口)
- 操作类型: 创建/修改/删除/导出/审批
- 操作对象: 哪个资源的哪个字段
- 操作前值 / 操作后值 (字段级变更)
- 操作结果: 成功/失败
- 请求 ID (全链路追踪)

记录范围:
- 所有管理端操作 (100% 记录)
- 用户端敏感操作: 支付/销毁资源/KYC提交/修改密码 (100% 记录)
- 登录/登出 (100% 记录)
- API Key 创建/撤销 (100% 记录)

存储与保留:
- 审计日志写入独立数据库 (audit_db)，与应用库分离
- 至少保留 1 年，金融相关保留 3 年
- 支持导出为 CSV/JSON 供合规审查

审计日志中间件:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 রিস্ক কন্ট্রোল রুল

```
实时风控引擎:

规则 1: 新账号异常行为
  条件: 注册时间 < 24h AND (支付总额 > $500 OR 创建工单 > 5)
  动作: 标记账号为"观察中"，通知风控管理员

规则 2: 批量注册检测
  条件: 同一 IP 24h 内注册 > 3 个账号
  动作: 拒绝新注册，冻结该 IP 下新账号

规则 3: 支付异常
  条件: 同一用户 1h 内支付失败 > 5 次
  动作: 冻结支付功能 2h，生成风控工单

规则 4: 退款滥用
  条件: 同一用户 30 天内退款 > 3 笔 OR 退款率 > 20%
  动作: 限制该账号退款权限，新订单标记风控审查

规则 5: API 滥用
  条件: 单 token 1h 内 API 调用 > 10000 次
  动作: 该 token 降级 (降低限流阈值)，通知管理员

规则 6: 资源滥用
  条件: VM 被投诉 spam/DDoS/挖矿 (接收 Abuse 通知)
  动作: 自动关机，冻结资源，生成高优先级工单

风控动作:
- 标记 (flag): 仅记录，不影响使用
- 降级 (throttle): 降低限流阈值
- 冻结 (freeze): 暂时禁用特定功能
- 封禁 (ban): 账号永久封禁
```

---

### 4.9 জরুরি প্রতিক্রিয়া

```
安全事件分级:

P0 (紧急) — 数据泄露、资金损失、平台宕机
  → 立即通知 CTO + 安全团队
  → 30 分钟内启动应急响应
  → 下线上游受影响服务，保留证据
  → 修复后 24h 内发布事件报告

P1 (严重) — 单账号被盗、支付欺诈、WAF 触发异常上升
  → 通知安全负责人
  → 2h 内处理
  → 冻结受影响账号/资源

P2 (一般) — 漏洞扫描发现中低危漏洞、异常登录告警
  → 录入工单系统
  → 下一个迭代修复

应急联系:
- 触发 P0/P1 告警后自动通知 (邮件 + 短信 + 电话)
- webman 健康检查端点: GET /health (返回 200 或告警)
- 值班表: 7×24 轮值，至少 2 人备岗
```

---

## 5. রিসোর্স প্রভিশনিং ইঞ্জিন

### Provider প্লাগইন আর্কিটেকচার

প্রতিটি ক্লাউড পণ্যের ধরন × ক্লাউড ভেন্ডর কম্বিনেশন একটি ইউনিফাইড ইন্টারফেস বাস্তবায়ন করে:

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

ProviderFactory (product_type, provider) অনুযায়ী নির্দিষ্ট ইমপ্লিমেন্টেশনে রুট করে:
- ProxmoxProvider (স্ব-পরিচালিত ফিজিক্যাল মেশিন: সার্ভার/ডেটা ডিস্ক/IP)
- AwsServerProvider / AliyunServerProvider (তৃতীয় পক্ষের ক্লাউড সার্ভার)
- GcpIpProvider (তৃতীয় পক্ষের IP)
- AzureDiskProvider (তৃতীয় পক্ষের ক্লাউড ডিস্ক)
- NamecheapDomainProvider / GoDaddyDomainProvider (ডোমেইন)

### অ্যাসিঙ্ক্রোনাস টাস্ক গ্যারান্টি

- Provisioning Worker `provision_tasks` টেবিল পোল করে
- provider অনুযায়ী গ্রুপ করে কনকারেন্সি নিয়ন্ত্রণ (প্রতি provider সর্বোচ্চ ৫ কনকারেন্ট)
- রিট্রাই কৌশল: 1min → 5min → 15min → 1h → 6h → 24h (সর্বোচ্চ ৬ বার)
- অ-রিট্রায়েবল ব্যর্থতা → অ্যালার্ট + স্বয়ংক্রিয় টিকিট তৈরি

### অর্ডার থেকে রিসোর্স প্রভিশনিং পর্যন্ত সম্পূর্ণ চেইন

```
用户下单                               支付                             资源开通
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 事件
   → addToCart(sku, region, qty)          → 密码二次确认 (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          选择支付通道                   10. 每个 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                                                             status=pending
                                        6. StripeChannel::
3. 应用优惠券                               createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                                                        12. Provider->create()
4. GET /orders/{id}/payment-methods     7. 前端 confirmCardPayment()           ├→ HostSelector::select()
   → 获取可用支付通道                  8. Stripe webhook 回调                   ├→ ProxmoxApi::create()
   ← [{channel, fee, total}]               → 验签 + 幂等检查                   │  createVM(CPU,RAM,Disk)
                                            → transaction=success              │  allocateIP()
                                            → 触发 OrderPaid 事件               │  startVM()
                                                                              ├→ 创建 Resource 记录
                                        重试策略 (失败时)                      └→ 更新 host_machine
                                        ────────────────                        已分配资源量
                                        1min → 5min → 15min
                                        → 1h → 6h → 24h                 13. Order::status = completed
                                        (6 次后标记失败 + 告警)               → NotificationDispatcher
                                                                              ::send('resource_ready')
                                        退款流程
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### স্ব-পরিচালিত ফিজিক্যাল মেশিন সমাধান: Proxmox VE (কমিউনিটি এডিশন)

স্ব-পরিচালিত সার্ভারে Proxmox VE (ওপেন-সোর্স, ফ্রি, AGPL v3) ব্যবহৃত হয়; PHP HTTP-র মাধ্যমে Proxmox REST API কল করে KVM ভার্চুয়াল মেশিনের লাইফসাইকেল ও রিসোর্স বরাদ্দ ম্যানেজ করে।

আর্কিটেকচার:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### ProxmoxApi ক্লায়েন্ট র্যাপার

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

#### রিসোর্স অপারেশন

**VM তৈরি (সার্ভার):**
1. HostSelector এমন একটি হোস্ট মেশিন বেছে নেয় যার রিসোর্স যথেষ্ট (cpu/ram/disk অবশিষ্ট + লোড ব্যালেন্স অনুযায়ী সাজানো)
2. সেই হোস্টের ip_pool থেকে একটি IP বরাদ্দ করা হয়
3. ProxmoxApi.post("/nodes/{node}/qemu") দিয়ে VM তৈরি (vmid, name, cores, memory, net0, ipconfig0 সেট করে)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") দিয়ে সিস্টেম ডিস্ক মাউন্ট (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") দিয়ে VM শুরু
6. host_machine.specs-এর বরাদ্দকৃত পরিমাণ আপডেট (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**CPU আপগ্রেড (অনলাইন):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**মেমোরি আপগ্রেড (অনলাইন):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**সিস্টেম ডিস্ক সম্প্রসারণ:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**আলাদা ডেটা ডিস্ক তৈরি:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**আলাদা IP তৈরি:**
IP পুল থেকে বরাদ্দ → Proxmox API দিয়ে ভার্চুয়াল নেটওয়ার্ক কার্ড যোগ + IP কনফিগ, অথবা স্বাধীন রিসোর্স হিসেবে রেখে বিদ্যমান VM-এর অতিরিক্ত নেটওয়ার্ক কার্ডে বরাদ্দ।

**VM ধ্বংস:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### হোস্ট নির্বাচন কৌশল

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

#### রিসোর্স অপারেশন সারসংক্ষেপ

| অপারেশন | বাস্তবায়ন পদ্ধতি | হট অপারেশন |
|------|----------|--------|
| VM তৈরি (CPU+মেমোরি+সিস্টেম ডিস্ক+IP) | Proxmox create qemu | — |
| আলাদা CPU আপগ্রেড | PUT config cores | অনলাইন |
| আলাদা মেমোরি আপগ্রেড | PUT config memory | অনলাইন |
| সিস্টেম ডিস্ক সম্প্রসারণ | PUT resize disk | অনলাইন (VM সাপোর্ট করলে) |
| আলাদা ডেটা ডিস্ক তৈরি | POST config ডিস্ক যোগ | অনলাইন |
| আলাদা IP তৈরি | IP পুল থেকে বরাদ্দ + VM-এ নেটওয়ার্ক কার্ড যোগ | অনলাইন |

### রিসোর্স লাইফসাইকেল

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

রিনিউ: active → (renew) → active (expired_at দীর্ঘায়িত)
আপগ্রেড: active → (upgrade) → upgrading → active

### রিসোর্স উৎস

| উৎস | ভার্চুয়ালাইজেশন/API | পণ্যের ধরন | ব্যাখ্যা |
|------|-----------|----------|------|
| স্ব-পরিচালিত ফিজিক্যাল মেশিন | Proxmox VE (কমিউনিটি এডিশন) | সার্ভার, ডেটা ডিস্ক, IP | নিজস্ব ডেটা সেন্টার হোস্টিং, PHP Proxmox API কল করে |
| তৃতীয় পক্ষের ক্লাউড ভেন্ডর | AWS/GCP/আলিবাবা ক্লাউড/হুয়াওয়ে ক্লাউড/Azure SDK | সার্ভার, IP, ক্লাউড ডিস্ক | তৃতীয় পক্ষের ক্লাউড রিসোর্স রিসেল |
| ডোমেইন রেজিস্ট্রার | Namecheap/GoDaddy/আলিবাবা ওয়ানওয়াং API | ডোমেইন রেজিস্ট্রেশন/ট্রান্সফার | ডোমেইন সার্ভিস |

### প্রথম পর্বের ইন্টিগ্রেশন

| অঞ্চল | সার্ভার | IP | ক্লাউড ডিস্ক | ডোমেইন |
|------|--------|----|------|------|
| এশিয়া-প্যাসিফিক | আলিবাবা ক্লাউড, হুয়াওয়ে ক্লাউড, AWS | আলিবাবা ক্লাউড, GCP | আলিবাবা ক্লাউড, হুয়াওয়ে ক্লাউড | আলিবাবা ওয়ানওয়াং, Namecheap |
| ইউরোপ | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| উত্তর আমেরিকা | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## 6. পেমেন্ট সিস্টেম

### মাল্টি-চ্যানেল রাউটিং

PaymentRouter ব্যবহারকারীর মুদ্রা পছন্দ অনুযায়ী উপলব্ধ চ্যানেল খুঁজে বের করে, প্রতিটি চ্যানেলের প্রকৃত প্রদেয় পরিমাণ (চ্যানেল ফিসহ) হিসাব করে, পেমেন্ট অপশন তালিকা রিটার্ন করে।

### পেমেন্ট ফ্লো (Stripe)

```
用户端 (Flutter)               服务端 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. 选择 Stripe 支付
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. 创建 payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 用户确认支付
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  验签 (stripe-signature)
                                  幂等检查 (transaction_no)
                               8. 更新 transaction=success
                               9. 触发 OrderPaid 事件
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 支付成功页面               ← 返回订单状态
```

### ক্রিপ্টোকারেন্সি পেমেন্ট

1. ব্যবহারকারী মুদ্রা নির্বাচন করে (যেমন USDT-TRC20)
2. ব্যাকএন্ড Coinbase Commerce / BitPay API দিয়ে পেমেন্ট অ্যাড্রেস তৈরি করে
3. Worker প্রতি 30s ব্লকচেইন কনফার্মেশন চেক করে (অথবা webhook)
4. কনফার্মড হলে → OrderPaid ইভেন্ট ট্রিগার

### এক্সচেঞ্জ রেট ও মাল্টি-কারেন্সি

- এক্সচেঞ্জ রেট উৎস নিয়মিত exchangerate-api থেকে নিয়ে Redis-এ সংরক্ষিত হয়
- পণ্যের মূল্য USD-ভিত্তিক, অন্যান্য মুদ্রায় রিয়েল-টাইম রূপান্তর
- অর্ডারের সময় রেট লক করা হয়, রিফান্ডে মূল রেটে ফেরত দেওয়া হয়

### পেমেন্ট চ্যানেল ভিজিবিলিটি কন্ট্রোল

payment_channels টেবিলের ফিল্ড:
- is_visible: ইউজার এন্ডে দেখানো হবে কি না
- visible_regions: দৃশ্যমান অঞ্চল সীমাবদ্ধ, খালি মানে সব
- min_amount / max_amount: অর্ডার অ্যামাউন্ট রেঞ্জ সীমা

### রিকনসাইলেশন

প্রতিদিন ভোরে প্রতিটি চ্যানেলের সেটেলমেন্ট রিপোর্ট টেনে সিস্টেমের transaction-এর সঙ্গে লাইন-বাই-লাইন মিলানো হয়; পার্থক্য > $0.01 হলে অ্যালার্ট।

### রিফান্ড নীতি

- সার্ভার/VPS: কেনার ৭২ ঘণ্টার মধ্যে সম্পূর্ণ রিফান্ড
- ডোমেইন: রেজিস্ট্রেশনের ৫ দিনের মধ্যে রিফান্ড সম্ভব (ICANN নিয়ম)
- IP: কেনার পর রিফান্ড করা যায় না
- ক্লাউড ডিস্ক: সার্ভার নীতির মতোই
- বিশেষ প্রমোশনাল পণ্য: রিফান্ড করা যায় না

রিফান্ড ফ্লো: ইউজার রিকোয়েস্ট → Ticket তৈরি → কাস্টমার সার্ভিস রিভিউ → admin কনফার্ম → provider.destroy() → payment.refund() → মূল পথে ফেরত

---

## 7. ক্লায়েন্ট পেজ স্ট্রাকচার

### Flutter / HarmonyOS ইউজার এন্ড

- **অথেনটিকেশন**: লগইন/রেজিস্ট্রেশন (ইমেইল+পাসওয়ার্ড, Google OAuth, Apple ID, মোবাইল নম্বর), পাসওয়ার্ড ভুলে গেলে, টু-স্টেপ ভেরিফিকেশন
- **হোমপেজ**: অঞ্চল নির্বাচক, পণ্য ক্যাটাগরি এন্ট্রি, Banner/প্রমোশন, প্রস্তাবিত পণ্য
- **প্রোডাক্ট**: তালিকা (মাল্টি-কন্ডিশন ফিল্টার), বিবরণ (কনফিগ/অঞ্চল/প্রাইস ক্যালকুলেটর), রিভিউ
- **শপিং ও পেমেন্ট**: কার্ট, অর্ডার কনফার্মেশন (পেমেন্ট পদ্ধতি/বিলিং অ্যাড্রেস/ব্যালেন্স/কুপন কোড), চেকআউট, পেমেন্ট ফলাফল
- **আমার রিসোর্স**: রিসোর্স তালিকা (স্ট্যাটাস অনুযায়ী ফিল্টার), বিবরণ অপারেশন (রিস্টার্ট/শাটডাউন/রিনিউ/আপগ্রেড/ধ্বংস), কনসোল SSO, ইউসেজ চার্ট
- **অর্ডার**: তালিকা (পেন্ডিং/পেইড/সম্পন্ন/রিফান্ডেড), বিবরণ, ইনভয়েস
- **টিকিট**: তালিকা, নতুন, কথোপকথন
- **পার্সোনাল সেন্টার**: প্রোফাইল/KYC, ব্যালেন্স ও রিচার্জ, নোটিফিকেশন, অ্যাড্রেস ম্যানেজমেন্ট, ভাষা/মুদ্রা/সিকিউরিটি সেটিংস
- **পাবলিক**: হেল্প সেন্টার, পরিষেবার শর্তাবলী, আমাদের সম্পর্কে

### webman-admin অ্যাডমিন প্যানেল

- **ড্যাশবোর্ড**: সামগ্রিক ভিউ + ট্রেন্ড চার্ট
- **ইউজার ম্যানেজমেন্ট**: তালিকা/বিবরণ/KYC রিভিউ
- **প্রোডাক্ট ম্যানেজমেন্ট**: ক্যাটাগরি/তালিকা/প্রাইসিং (SKU×অঞ্চল)/স্টক/রিভিউ
- **অর্ডার ম্যানেজমেন্ট**: তালিকা/বিবরণ/রিফান্ড রিভিউ/ইনভয়েস
- **পেমেন্ট ম্যানেজমেন্ট**: চ্যানেল কনফিগ/ট্রানজেকশন রেকর্ড/রিকনসাইলেশন রিপোর্ট
- **রিসোর্স ম্যানেজমেন্ট**: তালিকা/প্রভিশনিং টাস্ক মনিটরিং/ক্লাউড ভেন্ডর API কনফিগ
- **সরবরাহকারী ম্যানেজমেন্ট**: অনবোর্ডিং রিভিউ/তালিকা/পণ্য বরাদ্দ/সেটেলমেন্ট/উত্তোলন
- **টিকিট ম্যানেজমেন্ট**: কিউ/আমার টিকিট/SLA মনিটরিং
- **ডোমেইন ম্যানেজমেন্ট**: TLD প্রাইসিং/রেজিস্ট্রার API/ট্রান্সফার ম্যানেজমেন্ট
- **মেসেজ নোটিফিকেশন**: টেমপ্লেট ম্যানেজমেন্ট/প্রেরণ রেকর্ড
- **সিস্টেম সেটিংস**: অ্যাডমিন ও রোল/অপারেশন লগ/মাল্টি-ল্যাঙ্গুয়েজ/এক্সচেঞ্জ রেট/অঞ্চল/সিস্টেম প্যারামিটার
- **রিপোর্ট**: রাজস্ব/সরবরাহকারী সেটেলমেন্ট/পণ্য বিক্রয় বিশ্লেষণ/অঞ্চল বিশ্লেষণ

---

## 8. মেসেজ নোটিফিকেশন সিস্টেম

### চারটি চ্যানেল

Email (SMTP/SendGrid) / SMS (Twilio/আলিবাবা SMS) / Push (FCM/HMS) / ইন-অ্যাপ মেসেজ

### ফ্লো

ইভেন্ট ট্রিগার → Notification Dispatcher → টেমপ্লেট ম্যাচ (ইভেন্ট কোড+ভাষা পছন্দ) → ইউজার পছন্দ অনুযায়ী চ্যানেলে বিতরণ → Redis Queue-তে অ্যাসিঙ্ক্রোনাস প্রেরণ

### নোটিফিকেশন ধরন

রেজিস্ট্রেশন ভেরিফিকেশন কোড, অর্ডার পেমেন্ট সফল, রিসোর্স প্রভিশনিং সম্পন্ন, রিসোর্স মেয়াদ শেষ হওয়ার অনুস্মারক (7d/3d/1d), টিকিট উত্তর, রিফান্ড সম্পন্ন, সিকিউরিটি অ্যালার্ট, প্রমোশনাল অ্যাক্টিভিটি

### ব্যর্থতা পুনরায় চেষ্টা

৩ বার ব্যাকঅফ, webman redis-queue দিয়ে ম্যানেজ করা হয়।

---

## 9. সরবরাহকারী সিস্টেম

### অনবোর্ডিং ফ্লো

রেজিস্ট্রেশন → কোম্পানির তথ্য+যোগাযোগ+সেটেলমেন্ট পদ্ধতি জমা → অ্যাডমিন রিভিউ → অনুমোদনের পর পণ্য লিস্টিং → admin পণ্য রিভিউ → ইউজার ক্রয় → স্বয়ংক্রিয় কমিশন বণ্টন → সরবরাহকারী উত্তোলন রিকোয়েস্ট → admin টাকা প্রদান

### পারমিশন আইসোলেশন

সরবরাহকারী শুধুমাত্র নিজের পণ্য/অর্ডার/সেটেলমেন্ট রেকর্ড/টিকিট/উত্তোলন রেকর্ড দেখতে পারে। প্ল্যাটফর্মের রাজস্ব, অন্য সরবরাহকারীর ডেটা, পেমেন্ট চ্যানেল কনফিগ দেখতে পারে না।

### কমিশন বণ্টন নিয়ম

- স্ব-পরিচালিত পণ্য: commission_rate = 100% (সব প্ল্যাটফর্মের)
- তৃতীয় পক্ষের পণ্য: commission_rate = 5%~20% (প্ল্যাটফর্ম কমিশন)
- সেটেলমেন্ট সূত্র: অর্ডার পণ্যের পরিমাণ - প্ল্যাটফর্ম কমিশন - চ্যানেল ফি = সরবরাহকারীর প্রাপ্য
- সেটেলমেন্ট সাইকেল: সাপ্তাহিক / মাসিক

### সরবরাহকারীর সম্পূর্ণ ব্যবসায়িক ফ্লো

```
供应商入驻                              管理员审批
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 审核供应商信息
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 确认密码
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 成功
商品上架
────────
POST /supplier/products               管理员审核
  → {product_id, commission_rate}        → 关联供应商商品 + 设置佣金比例
  ← {supplier_product}                    → 商品状态: published

用户下单 ──→ 支付完成 ──→ 资源开通 ──→ 订单完成

定时结算 (每周一 04:17)                   提现
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 统计周期内已完成订单                    → 密码二次确认 (ConfirmationMiddleware)
  → 计算 total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → 检查可提现余额
  → 创建 SupplierSettlement                 → 创建 SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← 成功

管理员打款                              管理员 API Key 管理
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → 生成 sk_xxx (SHA256 存储)
  → 确认密码                               ← {api_key} (仅显示一次)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. মনিটরিং ও অপারেশন

### রিসোর্স মনিটরিং

- কালেক্টেড মেট্রিক্স: CPU/মেমোরি/ডিস্ক/ব্যান্ডউইথ ব্যবহার, IP কানেক্টিভিটি, ক্লাউড ডিস্ক IOPS, DNS রেজোলিউশন, SSL সার্টিফিকেট মেয়াদ
- কালেকশন পদ্ধতি: Agent রিপোর্ট / SNMP (স্ব-পরিচালিত) + ক্লাউড ভেন্ডর মনিটরিং API (তৃতীয় পক্ষ) + WHOIS/DNS পোলিং (ডোমেইন)
- কালেকশন সাইকেল: ৫ মিনিট, Prometheus + VictoriaMetrics স্টোরেজ

### অ্যালার্ট রুল

| অ্যালার্ট ইভেন্ট | গুরুতরতা | ট্রিগার শর্ত |
|----------|--------|----------|
| সার্ভার ডাউন | গুরুতর | ধারাবাহিক ৩ বার Ping অপ্রাপ্য |
| CPU/মেমোরি > 90% | তথ্য | ১০ মিনিট ধরে |
| ডিস্ক > 90% | সতর্কতা | ৫ মিনিট ধরে |
| ব্যান্ডউইথ > 80% | তথ্য | ৩০ মিনিট ধরে |
| SSL সার্টিফিকেট < ৩০ দিনে মেয়াদ শেষ | সতর্কতা | প্রতিদিন চেক |
| ডোমেইন < ৩০ দিনে মেয়াদ শেষ | সতর্কতা | প্রতিদিন চেক |
| প্রভিশনিং টাস্ক ব্যর্থ | গুরুতর | ধারাবাহিক ২ বার ব্যর্থ |
| পেমেন্ট রিকনসাইলেশন পার্থক্য | গুরুতর | একক লেনদেন > $0.01 |

---

## 11. ডিপ্লয়মেন্ট আর্কিটেকচার

### প্রোডাকশন এনভায়রনমেন্ট

- অ্যাপ্লিকেশন সার্ভার × 2: webman (মাল্টি-প্রসেস) + Nginx + Supervisor
- ডেটাবেস: MySQL 8.0 মাস্টার-স্লেভ (১ মাস্টার ২ স্লেভ) + Redis Cluster
- কিউ: webman redis-queue (পেমেন্ট কলব্যাক/নোটিফিকেশন/প্রভিশনিং টাস্ক)
- ক্রন: Crontab (রিকনসাইলেশন/সেটেলমেন্ট/ডোমেইন চেক/রিনিউ রিমাইন্ডার)
- স্টোরেজ: S3/OSS + CDN
- লগ মনিটরিং: ELK/Loki + Prometheus + Grafana + Sentry

### ডিরেক্টরি স্ট্রাকচার

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

### মূল Composer ডিপেন্ডেন্সি

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### উচ্চ কনকারেন্সি অপ্টিমাইজেশন

#### 1. MySQL রিড-রাইট সেপারেশন

Eloquent স্বয়ংক্রিয়ভাবে SELECT-কে read কানেকশনে, INSERT/UPDATE/DELETE-কে write কানেকশনে রুট করে।

```
配置 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (主库)
  connections.mysql.read  → DB_READ_HOST  (从库，可配置多个实现负载均衡)
  sticky = true           → 同一请求周期内写后读走主库（防主从延迟）

环境变量:
  DB_HOST=10.0.1.1          # 主库（写）
  DB_READ_HOST=10.0.2.1     # 从库（读），可部署多个
```

**রিড-রাইট রাউটিং নিয়ম:**

| অপারেশন ধরন | রাউটিং লক্ষ্য | উদাহরণ |
|---------|---------|------|
| SELECT | read কানেকশন | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | write কানেকশন | `Order::create(...)` |
| ট্রানজেকশনের সব অপারেশন | write কানেকশন | `DB::transaction(...)` |
| লেখার পরে পড়া (sticky) | write কানেকশন | একই রিকোয়েস্ট সাইকেলের মধ্যে |

#### 2. Redis মাল্টি-লেভেল ক্যাশ কৌশল

উচ্চ-ফ্রিকোয়েন্সি রিড ডেটার জন্য `CacheService` ব্যবহার করে ক্যাশ করা হয়; Redis অপ্রাপ্ত হলে স্বয়ংক্রিয়ভাবে সরাসরি ডেটাবেস কোয়েরিতে ডিগ্রেড হয়।

```
缓存分层:
  L1: Redis (进程间共享，毫秒级)
  L2: MySQL (持久化，兜底)

缓存策略:
  产品列表        TTL 5min    按 region_id + category_id + keyword 分键
  产品详情        TTL 10min   按 product_id 分键，内容变更时主动失效
  区域列表        TTL 1h      区域数据极少变动
  汇率            TTL 30min   定时任务刷新 + 主动更新
  TLD 定价        TTL 1h      TLD 价格变动频率低
  帮助文章        TTL 10min   发布/修改时主动失效
  商品分类        TTL 10min   分类树变更时主动失效

缓存预热 (部署后):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

主动失效 (数据变更时):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// 使用示例
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Nginx রেসপন্স কম্প্রেশন + রেট লিমিট

```
gzip 压缩:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  效果: JSON 响应压缩率 70-85%，节省带宽

proxy 优化:
  proxy_buffering on           # 缓冲上游响应，慢客户端不占 worker
  proxy_http_version 1.1       # HTTP/1.1 长连接复用
  keep-alive 到上游             # 减少 TCP 握手

限流:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  /health 端点不限流（关闭 access_log 减 I/O）
```

#### 4. ডেটাবেস ইনডেক্স সুপারিশ

কোয়েরি প্যাটার্ন বিশ্লেষণের ভিত্তিতে, নিচের ইনডেক্সগুলো উচ্চ কনকারেন্সি পরিস্থিতিতে স্ক্যান করা সারি উল্লেখযোগ্যভাবে কমায়:

| টেবিল | প্রস্তাবিত ইনডেক্স | কভার করা কোয়েরি |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | ইউজার অর্ডার তালিকা + স্ট্যাটাস ফিল্টার |
| `orders` | `(order_no)` (ইউনিক) | অর্ডার নম্বর সুনির্দিষ্ট কোয়েরি |
| `products` | `(status, category_id, sort)` | ফ্রন্ট-এন্ড পণ্য তালিকা + ক্যাটাগরি ফিল্টার + সর্টিং |
| `product_skus` | `(product_id, status)` | SKU তালিকা + স্ট্যাটাস ফিল্টার |
| `product_regions` | `(sku_id, region_id)` (ইউনিক) | রিজিয়ন প্রাইসিং লুকআপ |
| `resources` | `(user_id, status)` | আমার রিসোর্স তালিকা |
| `resources` | `(expired_at, status)` | মেয়াদ চেক ক্রন টাস্ক |
| `provision_tasks` | `(status, next_retry_at)` | Worker পোলিং পেন্ডিং টাস্ক |
| `refresh_tokens` | `(user_id, revoked)` | সেশন ম্যানেজমেন্ট কোয়েরি |
| `payment_transactions` | `(order_id)` | অর্ডার অনুযায়ী ট্রানজেকশন কোয়েরি |
| `payment_transactions` | `(transaction_no)` (ইউনিক) | Webhook আইডেমপোটেন্সি চেক |
| `tickets` | `(user_id, status)` | ইউজার টিকিট তালিকা |
| `notifications` | `(user_id, read_at, created_at)` | ইউজার নোটিফিকেশন তালিকা |

#### 5. কনকারেন্ট কানেকশন অনুমান

```
webman 多进程:
  CPU 核数 × 进程数 = worker 数
  例: 4核 × 8 worker = 32 worker 进程

MySQL 连接数:
  每个 worker 维持 1 个持久连接
  32 worker × 2 实例 (service + admin) = 64 连接
  主库 32 + 从库 32，保守建议 MySQL max_connections ≥ 200

Nginx 连接数:
  worker_connections 1024 × worker_processes auto
  峰值并发 ≈ worker_connections × worker_processes / 2
  4核服务器 ≈ 2048 并发连接
```

---

## 12. ইমপ্লিমেন্টেশন স্ট্যাটাস সারসংক্ষেপ

### মূল মডিউল

| মডিউল | স্ট্যাটাস | ব্যাখ্যা |
|------|------|------|
| **User** | ✅ সম্পন্ন | রেজিস্ট্রেশন/লগইন/ইমেইল ভেরিফিকেশন/OAuth/TOTP/সেশন ম্যানেজমেন্ট/GDPR ডিলিট/অ্যাড্রেস CRUD |
| **Product** | ✅ সম্পন্ন | SKU×রিজিয়ন প্রাইসিং, ক্যাটাগরি, সার্চ (ES), রিভিউ, অ্যাট্রিবিউট, বাল্ক ইমপোর্ট-এক্সপোর্ট |
| **Order** | ✅ সম্পন্ন | কার্ট, অর্ডার, লাইফসাইকেল, রিফান্ড, ইনভয়েস (PDF), কুপন |
| **Payment** | ✅ সম্পন্ন | Stripe চ্যানেল, মাল্টি-চ্যানেল রাউটিং, webhook সিগনেচার ভেরিফিকেশন, রিকনসাইলেশন |
| **Provisioning** | ✅ সম্পন্ন | Proxmox + AWS EC2 + ProviderFactory এক্সটেনসিবল আর্কিটেকচার |
| **Domain** | ✅ সম্পন্ন | TLD প্রাইসিং, DNS রেকর্ড, ডোমেইন ট্রান্সফার অনুমোদন |
| **Supplier** | ✅ সম্পন্ন | অনবোর্ডিং অনুমোদন, পণ্য লিস্টিং, সেটেলমেন্ট, উত্তোলন, API Key ম্যানেজমেন্ট |
| **Monitor** | ✅ সম্পন্ন | রিসোর্স প্রোব, অ্যালার্ট ইঞ্জিন, SSL সার্টিফিকেট মনিটরিং |
| **Ticket** | ✅ সম্পন্ন | তৈরি/উত্তর/বরাদ্দ/বন্ধ/SLA ট্র্যাকিং |
| **Notification** | ✅ সম্পন্ন | ইমেইল/SMS/Push/ইন-অ্যাপ চার চ্যানেল + ইউজার পছন্দ ম্যানেজমেন্ট |
| **Report** | ✅ সম্পন্ন | রাজস্ব/সরবরাহকারী/অঞ্চল রিপোর্ট |
| **I18n** | ✅ সম্পন্ন | মাল্টি-ল্যাঙ্গুয়েজ, মাল্টি-কারেন্সি, মাল্টি-টাইমজোন |

### নিরাপত্তা ব্যবস্থা

| ফিচার | স্ট্যাটাস |
|------|------|
| WAF (৮ ক্যাটাগরি ৪৫+ রুল: SQL ইনজেকশন/XSS/কমান্ড ইনজেকশন/ফাইল ইনক্লুশন/হেডার ইনজেকশন/SSRF/NoSQL ইনজেকশন/ওপেন রিডাইরেক্ট) | ✅ |
| CORS মিডলওয়্যার | ✅ |
| ClientPlatform প্ল্যাটফর্ম শনাক্তকরণ মিডলওয়্যার (৮টি প্ল্যাটফর্ম) | ✅ |
| API রেট লিমিট (Redis টোকেন বাকেট) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| মেইনটেন্যান্স মোড (এনভায়রনমেন্ট ভেরিয়েবল সুইচ + IP হোয়াইটলিস্ট) | ✅ |
| রিকোয়েস্ট/রেসপন্স এনক্রিপশন (AES-256-GCM) | ✅ |
| অডিট লগ (স্বাধীন ডেটাবেস, client_platform ট্র্যাকিংসহ) | ✅ |
| ডেটা ডিম্যাস্কিং (লগ/রেসপন্স স্বয়ংক্রিয়) | ✅ |
| JWT ডিভাইস ফিঙ্গারপ্রিন্ট বাইন্ডিং + token রোটেশন + client_platform রেকর্ড | ✅ |
| bcrypt পাসওয়ার্ড (cost=12) + Encryptable সেকেন্ডারি এনক্রিপশন | ✅ |
| সেকেন্ডারি পাসওয়ার্ড কনফার্মেশন (ConfirmationMiddleware, ৫ বার ব্যর্থ হলে ১৫ মিনিট লক) | ✅ |
| Admin প্যানেল WAF মিডলওয়্যার | ✅ |
| Sentry এক্সেপশন মনিটরিং (SentryBootstrap + before_send ডিম্যাস্কিং) | ✅ |
| Feature Flags ফিচার সুইচ (Redis ডাইনামিক ওভাররাইড + অ্যাডমিন API) | ✅ |

### নতুন ফিচার (2026-05-21)

| ফিচার | স্ট্যাটাস |
|------|------|
| সরবরাহকারী এক্সটার্নাল API (API Key অথেনটিকেশন + অর্ডার/রিসোর্স/সেটেলমেন্ট/উত্তোলন এন্ডপয়েন্ট) | ✅ |
| WebSocket রিয়েল-টাইম পুশ (Workerman নেটিভ WebSocket + ইভেন্ট লিসেনার) | ✅ |
| k6 লোড টেস্ট স্ক্রিপ্ট (স্মোক/প্রোডাক্ট/কনকারেন্সি) | ✅ |

### ব্যাকএন্ড পরিসংখ্যান

| মেট্রিক | সংখ্যা |
|------|------|
| API এন্ডপয়েন্ট | 135 |
| ডেটা মডেল | 50+ |
| ডেটাবেস টেবিল | 50+ |
| মিডলওয়্যার | ১৫টি (গ্লোবাল ৭ + রুট ৬ + এক্সটার্নাল API ১ + admin WebSocket) |
| ক্রন টাস্ক | ৭টি |
| মাইগ্রেশন ফাইল | ২২টি |
| টেস্ট | 362 tests / 579 assertions (Service 295 + Admin 67) |
| টেস্ট ফাইল | ২২টি |
| k6 লোড টেস্ট স্ক্রিপ্ট | ৩টি (smoke / products / concurrent) |

### ডকুমেন্টেশন

| ডকুমেন্ট | পাথ |
|------|------|
| সিস্টেম ডিজাইন স্পেসিফিকেশন | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| অ্যাডমিন প্যানেল ডিজাইন | `docs/admin-design.md` |
| সরবরাহকারী API ডকুমেন্টেশন | `docs/supplier-api.md` |
| ডিপ্লয়মেন্ট চেকলিস্ট | `docs/deployment.md` |
| API স্মোক টেস্ট স্ক্রিপ্ট | `docs/api-test.sh` |

### ফ্রন্টএন্ড স্ট্যাটাস

| এন্ড | স্ট্যাটাস | ব্যাখ্যা |
|----|------|------|
| Flutter | 🟡 চলমান | ApiClient-এ হেডার ভার্সন নম্বর যুক্ত হয়েছে + ApiService ইউনিফাইড ডেটা লেয়ার; লগইন/পণ্য তালিকা/কার্ট/রিসোর্স তালিকা API-তে যুক্ত হয়েছে; অর্ডার হিস্টোরি/নোটিফিকেশন সেন্টারে বিল্ড এনভায়রনমেন্ট যাচাই প্রয়োজন |
| HarmonyOS | 🔴 প্রাথমিক | শুধুমাত্র লগইন পেজ ও ApiClient |
| Admin Panel | ✅ সম্পন্ন | ড্যাশবোর্ড/ইউজার/প্রোডাক্ট/অর্ডার/পেমেন্ট/রিসোর্স/সরবরাহকারী/টিকিট/ডোমেইন/নোটিফিকেশন/সিস্টেম/রিপোর্ট/Webhook/ইমপোর্ট-এক্সপোর্ট সব ফিচার |
