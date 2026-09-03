# CloudPlatform আর্কিটেকচার ডিজাইন ডকুমেন্ট

## 1. সিস্টেম ওভারভিউ

CloudPlatform হলো বিশ্বব্যাপী ক্লাউড রিসোর্স ট্রেডিং প্ল্যাটফর্ম, নিজস্ব ফিজিক্যাল মেশিন + থার্ড-পার্টি সাপ্লায়ার হাইব্রিড মোড সাপোর্ট করে। ইউজাররা Web/মোবাইল দিয়ে সার্ভার (VM), IP ঠিকানা, ক্লাউড ডিস্ক, ডোমেইন ইত্যাদি প্রোডাক্ট কিনতে পারেন, সিস্টেম স্বয়ংক্রিয়ভাবে পেমেন্ট প্রসেসিং ও রিসোর্স ডেলিভারি সম্পন্ন করে।

### 1.1 কোর আর্কিটেকচার সিদ্ধান্ত

| সিদ্ধান্ত | নির্বাচন | কারণ |
|------|------|------|
| ব্যাকএন্ড ফ্রেমওয়ার্ক | PHP webman (Workerman) | মেমরি-রেসিডেন্ট, ইভেন্ট-ড্রিভেন, মাল্টি-প্রসেস, মিলিসেকেন্ড রেসপন্স |
| আর্কিটেকচার প্যাটার্ন | মডুলার মনোলিথ | মডিউলগুলো বিজনেস অনুযায়ী ভার্টিক্যালি বিভক্ত, অভ্যন্তরীণ MVC লেয়ারিং, মডিউলগুলোর মধ্যে ইভেন্ট-ভিত্তিক ডিকপলিং |
| অ্যাডমিন প্যানেল | স্বাধীন webman ইন্সট্যান্স (webman-admin + Layui) | অ্যাডমিন ট্রাফিক ও ইউজার ট্রাফিক আলাদা করা, ফল্ট ডোমেইন সেপারেশন |
| ORM | Illuminate/Eloquent | Laravel ইকোসিস্টেম পরিণত, রিলেশন কোয়েরি, Scope, ইভেন্ট, মাইগ্রেশন |
| ডিস্ট্রিবিউটেড প্রাইমারি কী | Snowflake 64-bit | অটো-ইনক্রিমেন্ট নির্ভরতা নেই, ডেটাবেস শার্ডিং সাপোর্ট |
| ID অবফাসকেশন | Hashids | বাইরের কাছে প্রকৃত ID আকার লুকানো, ক্রলার ট্র্যাভার্সাল প্রতিরোধ |
| অথেনটিকেশন | JWT HS256 | স্টেটলেস অথেনটিকেশন, Access 15min + Refresh 30d |
| ট্রান্সমিশন এনক্রিপশন | AES-256-GCM | মিডলওয়্যার ট্রান্সপারেন্ট এনক্রিপশন/ডিক্রিপশন, GCM অথেনটিকেটেড এনক্রিপশন ট্যাম্পার প্রতিরোধ |
| ফিল্ড এনক্রিপশন | AES-128-ECB | Eloquent Cast অটো এনক্রিপশন/ডিক্রিপশন, ডিটারমিনিস্টিক এনক্রিপশন (সাইফারটেক্সটে সমান কোয়েরি করা যায়, লগইন/ইউনিকনেস ভ্যালিডেশন এর ওপর নির্ভর করে); শুধু ECB সাপোর্টেড |
| মেসেজ কিউ | Redis Queue | অ্যাসিঙ্ক প্রসেসিং: পেমেন্ট কলব্যাক, নোটিফিকেশন ডিসপ্যাচ, রিসোর্স প্রোভিশনিং |
| সার্চ ইঞ্জিন | database (ডিফল্ট) / Elasticsearch 8.x | webman-scout ডিফল্ট database ড্রাইভ (SQL LIKE ফলব্যাক); ES কনফিগ করলে IK টোকেনাইজেশন ইনডেক্স চালু |
| ভার্চুয়ালাইজেশন | Proxmox VE + kvm-server | নিজস্ব VM Rust kvm-server (gRPC :50051, e-cat/etcd রেজিস্ট্রেশন ডিসকভারি) দিয়ে সরবরাহ হয়; ড্রাইভ লেয়ার বর্তমানে সিমুলেশন, libvirt রিয়েল ড্রাইভ Phase 2 |
| ক্লায়েন্ট | Flutter | সিঙ্গেল কোডবেস iOS/macOS/Windows/Linux/Web পাঁচ প্ল্যাটফর্ম + HarmonyOS |

### 1.2 সিস্টেম বাউন্ডারি

```
┌──────────────────────────────────────────────────────────────────┐
│                          ইউজার সাইড                              │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx রিভার্স প্রক্সি                          │
│  SSL টার্মিনেশন / gzip কম্প্রেশন / রেট লিমিট / WebSocket Upgrade  │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman সার্ভার (মাল্টি-প্রসেস)                       │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ গ্লোবাল মিডলওয়্যার চেইন: Version→CORS→SecurityHeaders→ClientPlatform│
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[রাউট মিডলওয়্যার]  │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket সার্ভার (:8282) — রিয়েল-টাইম পুশ              │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (মাস্টার-স্লেভ) │ (ক্যাশ/কিউ) │ │    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  e-cat / etcd রেজিস্ট্রেশন  │
    │  সিমুলেটেড ড্রাইভ (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  KVM/QEMU ভার্চুয়ালাইজেশন  │
    │  IP পুল / ডিস্ক পুল / হোস্ট  │
    └─────────────────────────────┘
```

---

## 2. কম্পোনেন্ট আর্কিটেকচার

### 2.1 ডুয়াল-ইন্সট্যান্স ডিজাইন

প্রজেক্টে দুটি স্বাধীন webman ইন্সট্যান্স আছে, MySQL ডেটাবেস শেয়ার করে:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   মিডলওয়্যার: WAF→ACL │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 গ্লোবাল+6 রাউট মিডলওয়্যার │
                    └─────────────────────┘
```

| ইন্সট্যান্স | পোর্ট | দায়িত্ব | মিডলওয়্যার |
|------|------|------|--------|
| **service** | 8787 | ইউজার API + অ্যাডমিন API + WebSocket | গ্লোবাল ১২ + রাউট ৬ + SupplierApiKey |
| **admin** | 8788 | অ্যাডমিন প্যানেল HTML (Layui) | WafMiddleware + AccessControl |

### 2.2 মডিউল লেয়ারড স্ট্রাকচার

প্রতিটি বিজনেস মডিউল ভেতরে ইউনিফাইড লেয়ারিং অনুসরণ করে:

```
app/{Module}/
├── controller/     # HTTP লেয়ার: প্যারামিটার ভ্যালিডেশন, Service কল, Response রিটার্ন
│   └── external/   # এক্সটার্নাল API কন্ট্রোলার (সাপ্লায়ার API Key অথেনটিকেশন)
├── service/        # বিজনেস লজিক: HTTP ডিপেন্ডেন্সি নেই, Controller/Queue Worker রিইউজ করতে পারে
├── model/          # Eloquent ডেটা মডেল: রিলেশন ডেফিনিশন, কোয়েরি স্কোপ, Casts
├── event/          # ডোমেইন ইভেন্ট ডেফিনিশন (OrderPaid, TicketCreated ইত্যাদি)
├── listener/       # ইভেন্ট লিসেনার (Provisioning, WebSocket পুশ)
├── provider/       # ক্লাউড প্রোভাইডার অ্যাডাপ্টার (ProxmoxProvider ইত্যাদি)
├── queue/          # কিউ কনজিউমার (ProvisionWorker, EmailSender ইত্যাদি)
└── cron/           # ক্রন টাস্ক (ExchangeRateSync, ExpirationCheck ইত্যাদি)
```

### 2.3 কমন লাইব্রেরি লেয়ারিং

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # ক্লিক ক্যাপচা সার্ভিস
├── clientplatform/      # ClientPlatformMiddleware (X-Client-Platform হেডার)
├── confirmation/        # সেকেন্ডারি পাসওয়ার্ড কনফার্মেশন মিডলওয়্যার
├── encryption/          # AES-256-GCM ট্রান্সমিশন এনক্রিপশন মিডলওয়্যার
├── feature/             # Feature Flags ফিচার সুইচ
├── hashid/              # Hashids রিকোয়েস্ট ডিকোডিং মিডলওয়্যার + এনকোড/ডিকোড সার্ভিস
├── helper/              # Response ফরম্যাটিং + CacheService
├── http/                # HTTP ক্লায়েন্ট টুল
├── i18n/middleware/     # মাল্টিল্যাঙ্গুয়াল LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # Snowflake ID সার্ভিস + Eloquent Trait
├── metrics/             # Prometheus মেট্রিক্স কালেক্টর + রেন্ডারার + HTTP রিকোয়েস্ট কাউন্ট মিডলওয়্যার
├── version/             # VersionMiddleware (URL পাথ ভার্সন)
└── webhook/             # Webhook ইভেন্ট ডিসপ্যাচার
```

### 2.4 CDN মডিউল

প্রোডাক্ট-লেভেল CDN মডিউল (`service/app/cdn/`) অ্যাডাপ্টার প্যাটার্ন দিয়ে চারটি প্রোভাইডার ইন্টিগ্রেট করে, সার্ভার বা স্টোরেজ বাকেট অরিজিন হিসেবে CDN-এ যুক্ত করে:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (SSL SaaS অটো সার্টিফিকেট), ICP রেজিস্ট্রেশন লাগে না
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), ICP রেজিস্ট্রেশন লাগে না
  ├── AliyunCdnAdapter    RPC সিগনেচার, ICP রেজিস্ট্রেশন লাগে
  └── TencentCdnAdapter   TC3 সিগনেচার, ICP রেজিস্ট্রেশন লাগে
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① বাইন্ডেড অ্যাকাউন্ট (provider_account_id) → ② code=cdn-{type} অ্যাক্টিভ অ্যাকাউন্ট → ③ env ফলব্যাক
  strict=true (ডিলিট/পর্জ): শুধু বাইন্ডেড অ্যাকাউন্ট, অনুপস্থিত হলে 4003, সাইলেন্ট সুইচ না
```

**অ্যাকাউন্ট ম্যানেজমেন্ট:** `provider_apis` মডেল রিইউজ করে (ক্রেডেনশিয়াল Encryptable এনক্রিপশনে স্টোর), অ্যাডমিন সাইড `/admin/providers` CRUD (RbacMiddleware), `code` কনভেনশন `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, env ক্রেডেনশিয়াল fallback হিসেবে ডিগ্রেড হয়।

**ডেটা মডেল:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; cert_config স্টোরের আগে প্রাইভেট কী বাদ দেওয়া হয়)। পারমিশন আইসোলেশন: CDN রিসোর্স `resource.user_id` দিয়ে মালিকানা ভেরিফাই হয়, অন্যের রিসোর্স ইউনিফর্মলি 404।

---

## 3. মিডলওয়্যার এক্সিকিউশন পাইপলাইন

### 3.1 গ্লোবাল মিডলওয়্যার চেইন (সব রিকোয়েস্ট)

```
HTTP রিকোয়েস্ট
  │
  ▼
1. VersionMiddleware         ← URL পাথে API ভার্সন ভ্যালিডেশন, ইনভ্যালিড হলে 400
  │                            শুধু /api/v1/ ও /admin/api/v1/ এ কার্যকর
  ▼
2. CorsMiddleware            ← OPTIONS প্রিফ্লাইটে CORS হেডার, Origin রিফ্লেক্ট
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy সিকিউরিটি রেসপন্স হেডার
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform হেডার ডিটেকশন (৮ প্ল্যাটফর্ম), properties ইনজেক্ট
  │                            শুধু /api/v1/ ও /admin/api/v1/ এ কার্যকর
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES দেশ ব্লকিং (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← ৮ ক্যাটাগরি ৪৫+ রুল স্ক্যান (JSON body + URL + UA + র ক বডি)
  │                          ← Content-Type হোয়াইটলিস্ট + রিকোয়েস্ট বডি 10MB লিমিট + URL 2KB লিমিট
  │                            হিট → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← ৩১ ধরনের আক্রমণ ডিটেকশন (XSS/SQLi/SSRF/ডিসিরিয়ালাইজেশন ইত্যাদি), IP ব্ল্যাকলিস্ট
  ▼
8. RateLimitMiddleware       ← সব রাউটে রেট লিমিটিং (per-IP + per-token ডুয়াল বাকেট)
  ▼
9. LocaleMiddleware          ← Accept-Language পার্সিং, লোকেল সেট
  ▼
10. MetricsMiddleware        ← Prometheus HTTP রিকোয়েস্ট কাউন্ট ও লেটেন্সি রেকর্ডিং
  ▼
11. HashidRequestMiddleware  ← রিকোয়েস্ট প্যারামিটারের hashid স্ট্রিং → প্রকৃত ইন্টিজার ID ডিকোড
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE চেক, হোয়াইটলিস্ট IP বাইপাস
  │
  ▼
[রাউট মিডলওয়্যার — রাউট গ্রুপ অনুযায়ী]
  │
  ├─ /health (ইন্টারনাল মনিটরিং) ────
  │   InternalTokenMiddleware      ← ইন্টারনাল টোকেন ভ্যালিডেশন /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM রিকোয়েস্ট/রেসপন্স বডি এনক্রিপশন
  │
  ├─ /api/v1 (ইউজার অথেনটিকেশন) ───────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token ভেরিফিকেশন → $request->userId/role
  │
  ├─ /api/v1 (সংবেদনশীল অপারেশন) ──────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← পাসওয়ার্ড সেকেন্ডারি কনফার্মেশন, Redis কাউন্টার, ৫ বার লক 15min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 ভেরিফিকেশন → $request->supplierId
  │
  ├─ /admin/api/v1 ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC পারমিশন চেক
  │
  └─ /admin/api/v1 (সংবেদনশীল অপারেশন) ──
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
কন্ট্রোলার → Service → Model → DB
```

### 3.2 প্রতিটি মিডলওয়্যারের বিবরণ

| মিডলওয়্যার | অবস্থান | রেজিস্ট্রেশন | দায়িত্ব |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | গ্লোবাল | URL পাথে API ভার্সন ভ্যালিডেশন |
| `CorsMiddleware` | common/Security | গ্লোবাল | OPTIONS প্রিফ্লাইট, Origin রিফ্লেক্ট |
| `SecurityHeadersMiddleware` | common/Security | গ্লোবাল | HSTS / X-Frame-Options / CSP / Referrer-Policy সিকিউরিটি রেসপন্স হেডার |
| `ClientPlatformMiddleware` | common/ClientPlatform | গ্লোবাল | `X-Client-Platform` ৮ প্ল্যাটফর্ম ডিটেকশন |
| `GeoBlockMiddleware` | common/Security | গ্লোবাল | GEO_BLOCKED_COUNTRIES রিজিয়ন ব্লকিং (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | গ্লোবাল(service)+admin | ৮ ক্যাটাগরি ৪৫+ রুল + রিকোয়েস্ট লিমিট |
| `SecurityPlugin` | Erikwang2013\Security | গ্লোবাল | ৩১ ধরনের আক্রমণ ডিটেকশন, IP হোয়াইট/ব্ল্যাকলিস্ট |
| `RateLimitMiddleware` | common/Security | গ্লোবাল | Redis টোকেন বাকেট রেট লিমিট (per-IP + per-token ডুয়াল বাকেট) |
| `LocaleMiddleware` | common/I18n | গ্লোবাল | Accept-Language পার্সিং |
| `MetricsMiddleware` | common/Metrics | গ্লোবাল | Prometheus HTTP রিকোয়েস্ট কাউন্ট ও লেটেন্সি |
| `HashidRequestMiddleware` | common/Hashid | গ্লোবাল | hashid রিকোয়েস্ট ডিকোডিং |
| `MaintenanceMiddleware` | common/Security | গ্লোবাল | মেইনটেন্যান্স মোড + IP হোয়াইটলিস্ট |
| `InternalTokenMiddleware` | common/Security | রাউট গ্রুপ | `/health/live|ready|deps` ইন্টারনাল টোকেন ভ্যালিডেশন |
| `EncryptionMiddleware` | common/Encryption | রাউট গ্রুপ | AES-256-GCM এনক্রিপশন/ডিক্রিপশন |
| `AuthMiddleware` | common/Auth | রাউট গ্রুপ | JWT Bearer Token ভেরিফিকেশন |
| `AdminRoleMiddleware` | common/Auth | রাউট গ্রুপ | অ্যাডমিন RBAC |
| `ConfirmationMiddleware` | common/Confirmation | রাউট গ্রুপ | পাসওয়ার্ড সেকেন্ডারি কনফার্মেশন |
| `SupplierApiKeyMiddleware` | common/Auth | রাউট গ্রুপ | sk_xxx API Key SHA256 সিগনেচার ভেরিফিকেশন |

---

## 4. ডেটা আর্কিটেকচার

### 4.1 ডিস্ট্রিবিউটেড প্রাইমারি কী: Snowflake

```
64-bit Snowflake ID স্ট্রাকচার:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  মিলিসেকেন্ড টাইমস্ট্যাম্প  ডেটাসেন্টার  ওয়ার্কার নোড   সিকোয়েন্স
  ইপক: 2024-01-01
  সর্বোচ্চ আয়ু: ~৬৯ বছর
```

সব Eloquent Model `creating` ইভেন্টে `HasSnowflakeId` Trait দিয়ে স্বয়ংক্রিয়ভাবে জেনারেট হয়। ডেটাবেস কলাম টাইপ `bigint unsigned`।

### 4.2 ID অবফাসকেশন: Hashids

```
রিকোয়েস্ট ফ্লো:
  Client: GET /api/v1/products/aB3xK7mQ9w
    → HashidRequestMiddleware ডিকোড → int(1234567890)
      → Controller/Service ইন্টিজার ID দিয়ে অপারেশন
        → Response::success() / Response::paginated()
          → hashids_encode_ids() সব id/*_id ফিল্ড রিকার্সিভ এনকোড
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 ডেটাবেস সংযোগ

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL মাস্টার (রাইট)│     │  MySQL স্লেভ (রিড)  │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  পারসিস্টেন্ট সংযোগ (PDO) │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  audit ডেটাবেস (আলাদা সংযোগ) │
         │  অডিট লগ আইসোলেটেড স্টোরেজ   │
         └──────────────────────┘
```

### 4.4 এনক্রিপশন লেয়ারিং

| লেয়ার | অ্যালগরিদম | বাস্তবায়ন | ব্যবহার |
|------|------|------|------|
| ট্রান্সমিশন লেয়ার | AES-256-GCM | EncryptionMiddleware | API রিকোয়েস্ট/রেসপন্স বডি এনক্রিপশন, GCM অথেনটিকেশন |
| ফিল্ড লেয়ার | AES-128-ECB | Encryptable Cast | সংবেদনশীল ফিল্ড অটো এনক্রিপশন/ডিক্রিপশন (ডিটারমিনিস্টিক: একই প্লেইনটেক্সট → একই সাইফারটেক্সট, লগইন/ইউনিকনেস ভ্যালিডেশন সাইফারটেক্সটে সমান কোয়েরি; শুধু ECB সাপোর্টেড, cipher পরিবর্তনে রি-এনক্রিপশন মাইগ্রেশন দরকার) |
| হ্যাশ লেয়ার | bcrypt + SHA256 | JWT / API Key | পাসওয়ার্ড/টোকেন অপরিবর্তনীয় স্টোরেজ |
| প্রাইমারি কী লেয়ার | Hashids | Response + Middleware | ID বাইরে থেকে অবফাসকেটেড |

### 4.5 ক্যাশ লেয়ারিং

```
L1: Redis ক্যাশ লেয়ার (CacheService)
    প্রোডাক্ট লিস্ট TTL 5min | প্রোডাক্ট ডিটেইল TTL 10min
    রিজিয়ন TTL 1h | এক্সচেঞ্জ রেট TTL 30min | TLD TTL 1h
    ইনভ্যালিডেশন: ডেটা পরিবর্তনে অ্যাক্টিভ forget / forgetPattern

L2: MySQL কোয়েরি লেয়ার (Eloquent + ইনডেক্স অপ্টিমাইজেশন)
    ১৩টি কম্পোজিট/কভারিং ইনডেক্স হাই-ফ্রিকোয়েন্সি কোয়েরি কভার করে

L3: Nginx রেসপন্স কম্প্রেশন (gzip level 6)
    JSON রেসপন্স কম্প্রেশন রেট 70-85%
```

### 4.6 ইন্টারন্যাশনালাইজেশন (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (গ্লোবাল মিডলওয়্যার)
         │  মূল ভাষা পার্স → zh-CN
         │  I18n::setLocale('zh-CN')
         │  i18n/zh-CN/messages.php লোড
         ▼
  কন্ট্রোলার / Service
         │
         ├── I18n::trans('auth.login_success')  →  'লগইন সফল'
         ├── I18n::translateField($jsonField)   →  ভাষা অনুযায়ী মান
         └── I18n::getLocale()                  →  'zh-CN'
```

| ক্ষমতা | বিবরণ |
|------|------|
| হেডার পার্সিং | `LocaleMiddleware` স্বয়ংক্রিয়ভাবে `Accept-Language` হেডার পার্স করে |
| ভাষা ফলব্যাক | সাপোর্টেড না হলে → `fallback_locale` |
| স্ট্যাটিক ট্রান্সলেশন | ১২০ এন্ট্রি, ১৫ মডিউল কভার করে (`i18n/{locale}/messages.php`) |
| প্যারামিটার রিপ্লেসমেন্ট | `I18n::trans('key', ['field' => 'value'])` |
| JSON ফিল্ড | `translateField()` মাল্টিল্যাঙ্গুয়াল JSON কলাম হ্যান্ডেল করে |

---

## 5. সিকিউরিটি আর্কিটেকচার

### 5.1 WAF রুল সিস্টেম (৮ ক্যাটাগরি ৪৫+ রুল)

| ক্যাটাগরি | রুল সংখ্যা | ডিটেকশন রেঞ্জ |
|------|--------|---------|
| SQL ইনজেকশন | 9 | কমেন্ট ক্যারেক্টার, কীওয়ার্ড, হেক্স এনকোডিং, ইউনিয়ন কোয়েরি, সর্বদা-সত্য কন্ডিশন, টাইম-ব্লাইন্ড, স্ট্যাকড কোয়েরি |
| XSS | 8 | HTML ট্যাগ, Script ভ্যারিয়েন্ট, ১৩ ইভেন্ট হ্যান্ডলার, JS প্রোটোকল, এন্টিটি এনকোডিং, Data URI |
| কমান্ড ইনজেকশন | 5 | পাইপের পর কমান্ড, সেমিকোলনের পর কমান্ড, $(cmd), ব্যাকটিক, স্ট্যান্ডঅ্যালোন কমান্ড কীওয়ার্ড |
| ফাইল ইনক্লুশন | 4 | পাথ ট্রাভার্সাল, PHP ফেক প্রোটোকল, অ্যাবসলিউট পাথ, Null byte |
| HTTP হেডার ইনজেকশন | 2 | CRLF নিউলাইন, Host/Cookie/Set-Cookie ইনজেকশন |
| SSRF | 6 | ইন্টারনাল IP, localhost, ক্লাউড মেটাডেটা, file:// প্রোটোকল |
| NoSQL ইনজেকশন | 3 | MongoDB অপারেটর, Redis বিপজ্জনক কমান্ড |
| ওপেন রিডাইরেক্ট | 2 | redirect_uri এক্সটার্নাল URL, ডাবল-এনকোডিং বাইপাস |

**স্ক্যান রেঞ্জ:** ভ্যালু-ইনজেকশন রুলগুলো (SQLi/XSS/কমান্ড ইনজেকশন/হেডার ইনজেকশন/SSRF/NoSQL/ওপেন রিডাইরেক্ট) query string, রিকোয়েস্ট বডি, User-Agent স্ক্যান করে; URL path শুধু ফাইল ইনক্লুশন (পাথ ট্রাভার্সাল) প্যাটার্ন দিয়ে স্ট্রাকচারাল ভ্যালিডেশন করে। বিজনেস পাথে select/insert/alert-এর মতো সাধারণ শব্দ থাকে (যেমন `/order_item/select`), পুরো path স্ক্যান করলে সব CRUD এন্ডপয়েন্ট ফলস-পজিটিভ ব্লক হবে, তাই path ভ্যালু-ইনজেকশন ম্যাচিংয়ে অংশ নেয় না।

**রিকোয়েস্ট-লেভেল প্রোটেকশন:** Content-Type হোয়াইটলিস্ট, রিকোয়েস্ট বডি 10MB লিমিট, URL 2KB লিমিট

### 5.2 অথেনটিকেশন সিস্টেম

```
┌─────────────────────────────────────────────┐
│               অথেনটিকেশন পদ্ধতি              │
├──────────────┬──────────────┬───────────────┤
│  ইউজার সাইড  │  অ্যাডমিন সাইড │  সাপ্লায়ার API │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min │  Access 2h   │  sk_xxx প্রিফিক্স│
│  Refresh 30d  │  Refresh 7d  │  SHA256 স্টোরেজ │
│  TOTP ঐচ্ছিক │              │  শুধু একবার দেখানো │
│  OAuth ঐচ্ছিক │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. ডিপ্লয়মেন্ট আর্কিটেকচার

### 6.1 প্রোডাকশন টপোলজি

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← প্ল্যাটফর্মের নিজস্ব এজ প্রোটেকশন (DDoS/Bot),
               │  DDoS / Bot     │     প্রোডাক্ট-লেভেল CDN মডিউলের (চার প্রোভাইডার,
               └────────┬────────┘     §2.4 দেখুন) সাথে সম্পর্কহীন
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL মাস্টার-স্লেভ│ Redis Cluster │
         │ ১ মাস্টার ২ স্লেভ │ ক্যাশ+কিউ     │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  e-cat / etcd রেজিস্ট্রেশন │
         │  সিমুলেটেড ড্রাইভ (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Proxmox VE ক্লাস্টার      │
         │  ফিজিক্যাল মেশিন × N      │
         │  KVM/QEMU ভার্চুয়ালাইজেশন │
         └────────────────────────────┘
```

### 6.2 প্রসেস মডেল

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (ডিফল্ট 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. টেকনিক্যাল ডিপেন্ডেন্সি

### 7.1 কোর ফ্রেমওয়ার্ক

| প্যাকেজ | ভার্সন | ব্যবহার |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web ফ্রেমওয়ার্ক (মেমরি-রেসিডেন্ট মাল্টি-প্রসেস) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | ইভেন্ট সিস্টেম |
| illuminate/redis | ^10.0 | Redis ক্লায়েন্ট |
| webman/redis-queue | ^1.0 | Redis মেসেজ কিউ |

### 7.2 erikwang2013 ইকোসিস্টেম প্যাকেজ

| প্যাকেজ | ব্যবহার |
|----|------|
| snowflake-php | ৬৪-বিট ডিস্ট্রিবিউটেড প্রাইমারি কী |
| hashids | API ID অবফাসকেশন |
| encryptable | ডেটাবেস ফিল্ড এনক্রিপশন |
| encryption | ট্রান্সমিশন এনক্রিপশন AES-256-GCM |
| jwt-webman | JWT অথেনটিকেশন |
| webman-scout | Elasticsearch ফুল-টেক্সট সার্চ |
| season | দেশের ফ্ল্যাগ emoji |
| poster-php | ক্লিক ক্যাপচা |

### 7.3 থার্ড-পার্টি ইন্টিগ্রেশন

| প্যাকেজ | ব্যবহার |
|----|------|
| stripe/stripe-php | Stripe পেমেন্ট |
| twilio/sdk | SMS |
| kreait/firebase-php | FCM পুশ |
| guzzlehttp/guzzle | HTTP ক্লায়েন্ট (Proxmox API ইত্যাদি) |
| sentry/sentry | এরর মনিটরিং |
| phpoffice/phpspreadsheet | Excel এক্সপোর্ট |
