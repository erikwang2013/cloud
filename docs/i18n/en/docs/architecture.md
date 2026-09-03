# CloudPlatform Architecture Design Document

## 1. System Overview

CloudPlatform is a global cloud resource trading platform supporting a hybrid model of self-operated physical machines + third-party suppliers. Users can purchase servers (VM), IP addresses, cloud disks, domains and other products via Web/mobile, and the system automatically handles payment processing and resource delivery.

### 1.1 Core Architecture Decisions

| Decision | Choice | Rationale |
|------|------|------|
| Backend framework | PHP webman (Workerman) | Resident memory, event-driven, multi-process, millisecond-level responses |
| Architecture pattern | Modular monolith | Modules vertically split by business, internal MVC layering, event-decoupled between modules |
| Admin panel | Standalone webman instance (webman-admin + Layui) | Isolates admin traffic from user traffic, separates failure domains |
| ORM | Illuminate/Eloquent | Mature Laravel ecosystem, relational queries, Scopes, events, migrations |
| Distributed PK | Snowflake 64-bit | No auto-increment dependency, natively supports sharding |
| ID obfuscation | Hashids | Hides real ID scale externally, prevents crawler enumeration |
| Authentication | JWT HS256 | Stateless auth, Access 15min + Refresh 30d |
| Transport encryption | AES-256-GCM | Transparent middleware encryption/decryption, GCM authenticated encryption prevents tampering |
| Field encryption | AES-128-ECB | Automatic Eloquent Cast encryption/decryption, deterministic encryption (ciphertext equality queries; login/uniqueness checks depend on it); ECB only |
| Message queue | Redis Queue | Async processing of payment callbacks, notification dispatch, resource provisioning |
| Search engine | database (default) / Elasticsearch 8.x | webman-scout defaults to the database driver (SQL LIKE fallback); IK tokenizer indexing enabled once ES is configured |
| Virtualization | Proxmox VE + kvm-server | Self-operated VMs provisioned by the Rust kvm-server (gRPC :50051, e-cat/etcd registration and discovery); the driver layer is currently a simulated driver, real libvirt driver in Phase 2 |
| Clients | Flutter | Single codebase for iOS/macOS/Windows/Linux/Web five platforms + HarmonyOS |

### 1.2 System Boundary

```
┌──────────────────────────────────────────────────────────────────┐
│                         Client side                             │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx reverse proxy                           │
│  SSL termination / gzip compression / rate limiting / WebSocket Upgrade │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman server (multi-process)                      │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │Global middleware chain: Version→CORS→SecurityHeaders→ClientPlatform│
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[route middleware]│     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket Server (:8282) — real-time push               │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (master/replica)│ (cache/queue)│    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  e-cat / etcd registration  │
    │  simulated driver (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  KVM/QEMU virtualization    │
    │  IP pool / disk pool / hosts│
    └─────────────────────────────┘
```

---

## 2. Component Architecture

### 2.1 Dual-Instance Design

The project contains two independent webman instances sharing the MySQL database:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   Middleware: WAF→ACL│
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 global+6 route middleware │
                    └─────────────────────┘
```

| Instance | Port | Responsibility | Middleware |
|------|------|------|--------|
| **service** | 8787 | User API + Admin API + WebSocket | 12 global + 6 route + SupplierApiKey |
| **admin** | 8788 | Admin HTML panel (Layui) | WafMiddleware + AccessControl |

### 2.2 Module Layering Structure

Each business module follows a uniform internal layering:

```
app/{Module}/
├── controller/     # HTTP layer: parameter validation, calls Service, returns Response
│   └── external/   # External API controllers (supplier API Key authentication)
├── service/        # Business logic: no HTTP dependencies, reusable by Controller/Queue Worker
├── model/          # Eloquent data models: relations, query scopes, Casts
├── event/          # Domain event definitions (OrderPaid, TicketCreated, etc.)
├── listener/       # Event listeners (Provisioning, WebSocket push)
├── provider/       # Cloud provider adapters (ProxmoxProvider, etc.)
├── queue/          # Queue consumers (ProvisionWorker, EmailSender, etc.)
└── cron/           # Scheduled tasks (ExchangeRateSync, ExpirationCheck, etc.)
```

### 2.3 Common Library Layering

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # Click CAPTCHA service
├── clientplatform/      # ClientPlatformMiddleware (X-Client-Platform header)
├── confirmation/        # Secondary password confirmation middleware
├── encryption/          # AES-256-GCM transport encryption middleware
├── feature/             # Feature Flags
├── hashid/              # Hashids request decode middleware + encode/decode service
├── helper/              # Response formatting + CacheService
├── http/                # HTTP client utilities
├── i18n/middleware/     # Multi-language LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # Snowflake ID service + Eloquent Trait
├── metrics/             # Prometheus metrics collector + renderer + HTTP request counting middleware
├── version/             # VersionMiddleware (parses version from URL path)
└── webhook/             # Webhook event dispatcher
```

### 2.4 CDN Module

The product-level CDN module (`service/app/cdn/`) connects four providers through an adapter pattern, using servers or storage buckets as CDN origins:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (SSL SaaS auto certificate), no ICP registration required
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), no ICP registration required
  ├── AliyunCdnAdapter    RPC signing, ICP registration required
  └── TencentCdnAdapter   TC3 signing, ICP registration required
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① bound account (provider_account_id) → ② active account with code=cdn-{type} → ③ env fallback
  strict=true (delete/purge): bound account only, 4003 if missing, no silent switch
```

**Account management:** reuses the `provider_apis` model (credentials stored encrypted with Encryptable), admin `/admin/providers` CRUD (RbacMiddleware), `code` convention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, env credentials degrade to fallback.

**Data model:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; private keys are stripped from cert_config before persisting). Permission isolation: CDN resources are ownership-checked via `resource.user_id`, non-owned resources uniformly return 404.

### 2.5 Report Module

Server-side reports (`service/app/report/`, `Report\ReportController` + `ReportService`) provide revenue / supplier / region reports; v3.1.0 adds a report center on the admin panel side (`admin/app/controller/ReportController.php` + `admin/app/view/report/index.html`), with routes auto-registered as `/app/admin/report/{action}`:

```
ReportController (admin panel instance, /app/admin/report/*)
  ├── index()        report page (Layui view)
  ├── order()        order daily (aggregated by paid_at, excludes refunded)   [v3.1.0]
  ├── product_top()  product ranking by sales volume (top N, limit 1-50)      [v3.1.0]
  ├── payment()      payment channel stats (successful, by channel + currency)[v3.1.0]
  ├── user_growth()  user growth (daily registrations, soft-deleted excluded)  [v3.1.0]
  └── export()       Excel export (type whitelist order/product/payment/user)
        └── ExcelExport (PhpSpreadsheet) + bcmath amount aggregation
```

The panel instance queries the business database directly (Order / OrderItem / PaymentTransaction / Product / BusinessUser models); amounts are aggregated with bcmath (`SUM(DECIMAL)` returned as strings by PDO), and `start_date` / `end_date` must be `YYYY-MM-DD` (default last 30 days).

**Dashboard statistics:** `DashboardController::index()` (admin panel) adds the operational metrics `today_orders` / `today_revenue` / `pending_orders` / `active_resources` alongside user stats, returned with the 7-day / 30-day user trends and system info — rendered with stat cards and ECharts charts.

---

## 3. Middleware Execution Pipeline

### 3.1 Global Middleware Chain (all requests)

```
HTTP Request
  │
  ▼
1. VersionMiddleware         ← validates the version segment in the URL path, 400 on invalid
  │                            Only applies to /api/v1/ and /admin/api/v1/
  ▼
2. CorsMiddleware            ← OPTIONS preflight returns CORS headers, Origin reflection
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy security response headers
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform header detection (8 platforms), injects properties
  │                            Only applies to /api/v1/ and /admin/api/v1/
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES country blocking (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← 8 categories 45+ rule scanning (JSON body + URL + UA + raw body)
  │                          ← Content-Type whitelist + 10MB request body limit + 2KB URL limit
  │                            Hit → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 attack detections (XSS/SQL injection/SSRF/deserialization, etc.), IP allow/deny lists
  ▼
8. RateLimitMiddleware       ← Full-route rate limiting (per-IP + per-token dual buckets)
  ▼
9. LocaleMiddleware          ← Accept-Language parsing, sets locale
  ▼
10. MetricsMiddleware        ← Prometheus HTTP request counting and latency recording
  ▼
11. HashidRequestMiddleware  ← Request param hashid strings → real integer ID decoding
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE check, whitelisted IPs pass through
  │
  ▼
[Route middleware — attached per route group]
  │
  ├─ /health (internal monitoring) ──
  │   InternalTokenMiddleware      ← Internal token validation /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM request/response body encryption/decryption
  │
  ├─ /api (user authenticated) ─────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token validation → $request->userId/role
  │
  ├─ /api (sensitive operations) ───
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← Secondary password confirmation, Redis counter, 5 failures lock 15min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 validation → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC permission check
  │
  └─ /admin/api (sensitive operations) ──
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller → Service → Model → DB
```

### 3.2 Middleware Details

| Middleware | Location | Registration | Responsibility |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | Global | Validates the version segment in the URL path, 400 on invalid |
| `CorsMiddleware` | common/Security | Global | OPTIONS preflight, Origin reflection |
| `SecurityHeadersMiddleware` | common/Security | Global | HSTS / X-Frame-Options / CSP / Referrer-Policy security response headers |
| `ClientPlatformMiddleware` | common/ClientPlatform | Global | `X-Client-Platform` 8-platform detection |
| `GeoBlockMiddleware` | common/Security | Global | GEO_BLOCKED_COUNTRIES geo-blocking (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | Global (service) + admin | 8 categories 45+ rules + request limits |
| `SecurityPlugin` | Erikwang2013\Security | Global | 31 attack detections, IP allow/deny lists |
| `RateLimitMiddleware` | common/Security | Global | Redis token-bucket rate limiting (per-IP + per-token dual buckets) |
| `LocaleMiddleware` | common/I18n | Global | Accept-Language parsing |
| `MetricsMiddleware` | common/Metrics | Global | Prometheus HTTP request counting and latency |
| `HashidRequestMiddleware` | common/Hashid | Global | Hashid request decoding |
| `MaintenanceMiddleware` | common/Security | Global | Maintenance mode + IP whitelist |
| `InternalTokenMiddleware` | common/Security | Route group | `/health/live|ready|deps` internal token validation |
| `EncryptionMiddleware` | common/Encryption | Route group | AES-256-GCM encryption/decryption |
| `AuthMiddleware` | common/Auth | Route group | JWT Bearer Token validation |
| `AdminRoleMiddleware` | common/Auth | Route group | Admin RBAC |
| `ConfirmationMiddleware` | common/Confirmation | Route group | Secondary password confirmation |
| `SupplierApiKeyMiddleware` | common/Auth | Route group | sk_xxx API Key SHA256 signature verification |

---

## 4. Data Architecture

### 4.1 Distributed Primary Keys: Snowflake

```
64-bit Snowflake ID structure:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  millisecond ts    datacenter  worker node  sequence
  Epoch: 2024-01-01
  Max lifespan: ~69 years
```

All Eloquent Models auto-generate IDs in the `creating` event via the `HasSnowflakeId` Trait. The database column type is `bigint unsigned`.

### 4.2 ID Obfuscation: Hashids

```
Request flow:
  Client: GET /api/v1/products/aB3xK7mQ9w
    → HashidRequestMiddleware decodes → int(1234567890)
      → Controller/Service operates with integer IDs
        → Response::success() / Response::paginated()
          → hashids_encode_ids() recursively encodes all id/*_id fields
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Database Connections

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL master (write)│     │  MySQL replica (read)│
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  persistent PDO conn │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  audit DB (separate) │
         │  isolated audit logs │
         └──────────────────────┘
```

### 4.4 Encryption Layering

| Layer | Algorithm | Implementation | Purpose |
|------|------|------|------|
| Transport | AES-256-GCM | EncryptionMiddleware | API request/response body encryption, GCM authentication |
| Field | AES-128-ECB | Encryptable Cast | Automatic encryption/decryption of sensitive fields (deterministic: same plaintext → same ciphertext, login/uniqueness checks query by ciphertext equality; ECB only, changing cipher requires a re-encryption migration) |
| Hash | bcrypt + SHA256 | JWT / API Key | Irreversible password/Token storage |
| PK | Hashids | Response + Middleware | External ID obfuscation |

### 4.5 Cache Layering

```
L1: Redis cache layer (CacheService)
    Product list TTL 5min | Product details TTL 10min
    Regions TTL 1h | Exchange rates TTL 30min | TLD TTL 1h
    Invalidation: proactive forget / forgetPattern on data change

L2: MySQL query layer (Eloquent + index optimization)
    13 composite/covering indexes cover high-frequency queries

L3: Nginx response compression (gzip level 6)
    70-85% compression ratio on JSON responses
```

### 4.6 Internationalization (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (global middleware)
         │  Parses primary language → zh-CN
         │  I18n::setLocale('zh-CN')
         │  Loads i18n/zh-CN/messages.php
         ▼
  Controller / Service
         │
         ├── I18n::trans('auth.login_success')  →  'Login successful'
         ├── I18n::translateField($jsonField)   →  value per language
         └── I18n::getLocale()                  →  'zh-CN'
```

| Capability | Description |
|------|------|
| Header parsing | `LocaleMiddleware` auto-parses the `Accept-Language` header |
| Language fallback | Unsupported languages → `fallback_locale` |
| Static translations | 120 entries covering 15 modules (`i18n/{locale}/messages.php`) |
| Parameter substitution | `I18n::trans('key', ['field' => 'value'])` |
| JSON fields | `translateField()` handles multilingual JSON columns |

---

## 5. Security Architecture

### 5.1 WAF Rule System (8 categories 45+ rules)

| Category | Rule count | Detection scope |
|------|--------|---------|
| SQL injection | 9 | Comment markers, keywords, hex encoding, union queries, always-true conditions, time-based blind injection, stacked queries |
| XSS | 8 | HTML tags, Script variants, 13 event handlers, JS pseudo-protocols, entity encoding, Data URIs |
| Command injection | 5 | Commands after pipes, commands after semicolons, $(cmd), backticks, standalone command keywords |
| File inclusion | 4 | Path traversal, PHP pseudo-protocols, absolute paths, Null bytes |
| HTTP header injection | 2 | CRLF line breaks, Host/Cookie/Set-Cookie injection |
| SSRF | 6 | Internal IPs, localhost, cloud metadata, file:// protocol |
| NoSQL injection | 3 | MongoDB operators, dangerous Redis commands |
| Open redirect | 2 | redirect_uri external URLs, double-encoding bypass |

**Scan scope:** value-injection rules (SQLi/XSS/command injection/header injection/SSRF/NoSQL/open redirect) scan the query string, request body, and User-Agent; the URL path is only structurally validated with file-inclusion (path traversal) patterns. Business paths contain ordinary words like select/insert/alert (e.g. `/order_item/select`); scanning the full path would false-positive all CRUD endpoints, so the path does not participate in value-injection matching.

**Request-level protections:** Content-Type whitelist, 10MB request body limit, 2KB URL limit

### 5.2 Authentication System

```
┌─────────────────────────────────────────────┐
│             Authentication methods          │
├──────────────┬──────────────┬───────────────┤
│  User side   │  Admin side  │  Supplier API │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min│  Access 2h   │  sk_xxx prefix │
│  Refresh 30d │  Refresh 7d  │  SHA256 stored│
│  TOTP optional│             │  shown once   │
│  OAuth optional│            │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. Deployment Architecture

### 6.1 Production Topology

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← platform's own edge protection (DDoS/Bot),
               │  DDoS / Bot     │    unrelated to the product-level CDN module
               └────────┬────────┘    (four providers, see §2.4)
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
         │ MySQL master/replica│ Redis Cluster │
         │ 1 master 2 replicas│ cache+queue   │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  e-cat / etcd registration│
         │  simulated driver (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Proxmox VE cluster        │
         │  physical machines × N     │
         │  KVM/QEMU virtualization   │
         └────────────────────────────┘
```

### 6.2 Process Model

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (default 8)
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

## 7. Technology Dependencies

### 7.1 Core Framework

| Package | Version | Purpose |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web framework (resident-memory multi-process) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | Event system |
| illuminate/redis | ^10.0 | Redis client |
| webman/redis-queue | ^1.0 | Redis message queue |

### 7.2 erikwang2013 Ecosystem Packages

| Package | Purpose |
|----|------|
| snowflake-php | 64-bit distributed primary keys |
| hashids | API ID obfuscation |
| encryptable | Database field encryption |
| encryption | Transport encryption AES-256-GCM |
| jwt-webman | JWT authentication |
| webman-scout | Elasticsearch full-text search |
| season | Country flag emoji |
| poster-php | Click CAPTCHA |

### 7.3 Third-Party Integrations

| Package | Purpose |
|----|------|
| stripe/stripe-php | Stripe payments |
| twilio/sdk | SMS |
| kreait/firebase-php | FCM push |
| guzzlehttp/guzzle | HTTP client (Proxmox API, etc.) |
| sentry/sentry | Exception monitoring |
| phpoffice/phpspreadsheet | Excel export |
