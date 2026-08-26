# Global Cloud Resource Trading Platform — System Design

## Project Overview

A cloud resource trading platform for global users, supporting a hybrid model of self-operated and third-party suppliers. Users can purchase cloud products such as servers, IPs, cloud disks, and domains. Fully automated resource provisioning, multiple payment channels, multi-currency, multi-language.

### Tech Stack

| Layer | Technology |
|------|------|
| User App | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Admin Panel | webman-admin |
| Backend | PHP webman (modular monolith) |
| Database | MySQL 8.0 (master-slave) |
| Cache/Queue | Redis (cache + Session + queue) |
| Storage | S3/OSS + CDN |
| Monitoring | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. Module Breakdown (12 Core Modules)

| Module | Responsibility |
|------|------|
| **User** | Registration/login (OAuth + email + phone), KYC real-name verification, membership tiers, balance accounts |
| **Product** | Product definition (SKU), multi-region pricing, inventory management, categories, search, reviews |
| **Order** | Cart, checkout, order lifecycle (pending payment → paid → provisioning → completed → refunded), renewal/upgrade |
| **Payment** | Payment channel routing, multi-currency quotes, exchange rates, refunds, reconciliation |
| **Provisioning** | Integration with cloud provider APIs, automatic resource creation/renewal/destruction |
| **Domain** | Domain lookup, registration, transfer, renewal, DNS management |
| **Supplier** | Supplier onboarding, approval, product listing, settlement, commission sharing |
| **Monitor** | Resource status probing, usage collection, alert rules |
| **Ticket** | Ticket submission, assignment, SLA tracking |
| **Notification** | Email/SMS/App Push/in-app messages, multi-template multi-language |
| **Report** | Revenue reports, supplier settlement reports, sales trends |
| **I18n** | Multi-language entries, multi-currency exchange rates, multi-timezone |

---

## 2. Core Data Models

### User Center (User)

- **users** — User master table (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — User profiles (user_id, avatar, nickname, country)
- **user_kyc** — Real-name verification (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — Balance accounts (user_id, currency, balance, frozen_balance)
- **user_balance_log** — Balance change records (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — User addresses (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Product Center (Product)

- **product_categories** — Product categories (id, parent_id, name, icon, sort)
- **products** — Product master table (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKUs (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — Regional pricing (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — Product images (product_id, url, sort)
- **product_attributes** — Custom attributes (product_id, key, value)
- **product_reviews** — Product reviews (user_id, product_id, order_id, rating, content)
- **regions** — Region table (id, name, continent, country, city, data_center, status)

### Order Center (Order)

- **carts** — Shopping cart (user_id, sku_id, region_id, quantity, cycle)
- **orders** — Order master table (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — Order line items (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — Order timeline (order_id, status, operator, remark, created_at)
- **order_invoices** — Invoices (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — Refund orders (order_id, user_id, amount, reason, status, handled_by)

### Payment Center (Payment)

- **payment_channels** — Payment channel config (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — Transaction records (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — Reconciliation table (date, channel_id, channel_total, system_total, diff, status)

### Resource Provisioning (Provisioning)

- **resources** — Resource master table (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — Server details (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP details (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — Cloud disk details (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — Domain details (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — Provisioning tasks (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — Cloud provider API config (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Physical Machine Resource Management (Host & IP Pool)

Self-operated physical servers use Proxmox VE (community edition, free) to manage virtual machines, creating/managing VMs, allocating IPs, and attaching disks via the REST API.

- **host_machines** — Host machines (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP pools (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP allocation records (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM disk details (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — Disk resize records (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Supplier (Supplier)

- **suppliers** — Supplier master table (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — Supplier product associations (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — Settlement statements (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — Withdrawal records (supplier_id, amount, method, account_info, status)

### Domain Services (Domain)

- **domain_tlds** — Supported TLDs (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — Domain transfers (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS zones (domain_name, user_id, zone_id)
- **dns_records** — DNS records (zone_id, type, name, value, ttl, priority)

### Tickets & Notifications (Ticket & Notification)

- **tickets** — Tickets (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — Ticket messages (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — Notification records (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — Notification templates (code, name, channels, title_template, body_template, variables)

---

## 3. API Design Conventions

### Version Management

The API version is specified via the HTTP header `X-Api-Version`, not in the URL path. The server injects the version header into internal routes via middleware.

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**Supported versions**: `v1` (default, used automatically when the header is missing)

**Versioning mechanism**: `VersionMiddleware` validates the `X-Api-Version` header for all `/api/*` and `/admin/api/*` paths; it defaults to `v1` when missing and returns `400` for unsupported versions. The URL path no longer contains the version number.

**Adding a new version**:
1. Append the version to the `VersionMiddleware::SUPPORTED` array
2. Register a new route group for the version in `route.php`
3. Controllers read the version via `$request->properties['api_version']` for version-specific handling

### RESTful Routes

```
统一前缀: /api
管理后台: /admin/api
```

**Route groups and middleware matrix:**

| Route Group | Middleware | Example Endpoints |
|--------|--------|---------|
| Public (no prefix) | Global middleware chain | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Global + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (user) | Global + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (sensitive) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Global + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (sensitive) | Global + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Unified Response Format

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

### Authentication

| Endpoint | Method |
|----|------|
| User client | JWT (access_token 2h + refresh_token 30d) + TOTP two-step verification + recovery codes |
| Admin | JWT (access_token 2h + refresh_token 7d) |
| Supplier API | API Key (`sk_` prefix, SHA256 hashed storage, shown only once at creation) |
| Cloud provider callbacks | Signature verification (HMAC-SHA256) |

**Implemented authentication features**:
- Email registration + email verification link
- Phone registration + Twilio SMS verification code (60s cooldown + IP rate limit 5/hour)
- Google OAuth login / Apple Sign In
- Password reset (email verification code + Redis 10min TTL)
- TOTP two-step verification (QR code setup, recovery codes as fallback)
- Active session management (view/revoke login devices, including client_platform info)
- Account deletion GDPR (password confirmation + soft delete + all tokens revoked)
- Login anomaly alerts (email notification for new IP logins)
- Login lockout (5 failed attempts locks for 15 minutes)

**User authentication flow:**

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

### Multi-language

- Request header: Accept-Language: zh-CN / en-US / ja-JP
- JSON columns store multilingual text: name: {"zh-CN":"Cloud Server","en":"Cloud Server"}
- i18n files manage static text; one set each for the frontend and backend

---

## 4. Security Architecture

### Layered Defense Model

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

### 4.1 Network Perimeter Defense

#### DDoS Protection

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

| Layer | Measure | Notes |
|------|------|------|
| CDN layer | Automatic DDoS scrubbing | Cloudflare's free plan already supports L3/L4 protection |
| CDN layer | Bot Management | Identify and block malicious crawlers/order-fraud scripts |
| Nginx layer | limit_req_zone | 10 req/s per IP, returns 429 when exceeded |
| Nginx layer | limit_conn | Max 20 concurrent connections per IP |
| webman layer | Token bucket rate-limiting middleware | Precise per-user/per-endpoint rate limiting |

#### WAF Rules (webman middleware)

The WAF middleware scans requests with 8 categories of regex rule groups; the rules are configured in `config/security.php` and hot-reloaded without a restart. The scan covers request body JSON, URL path + query string, User-Agent, and the raw request body (to prevent JSON-encoding bypass).

**8 detection rule categories (45+ rules):**

| Category | Coverage |
|------|---------|
| SQL injection | Single quotes/comment markers, SQL keywords, hex encoding, union query variants, always-true conditions (`' OR '1'='1`), time-based blind injection (`sleep`/`benchmark`), stacked queries, multi-line comment bypass |
| XSS | HTML tags (including encoded variants), script tags and variants, 13 JS event handlers, JS global objects/dangerous functions, `javascript:` pseudo-protocol, HTML entity encoding, Data URI injection, inline event attributes |
| Command injection | Pipe followed by command (`\| cat`), semicolon followed by command (`; whoami`), `$(cmd)` and backtick command substitution, standalone command keywords |
| File inclusion | Path traversal (multi-encoding), PHP pseudo-protocols (`php://`/`data://`/`phar://`), absolute path probing (`/etc/`/`C:\`), null byte injection |
| HTTP header injection | CRLF newline injection (`%0d%0a`/`\r\n`), Host/Cookie/Set-Cookie header injection |
| **SSRF** | Internal IPv4 addresses (127.x/10.x/172.16-31.x/192.168.x), localhost aliases, cloud metadata endpoints (169.254.169.254), file:// protocol |
| **NoSQL injection** | MongoDB operators ($where/$gt/$regex/$or etc.), $where JS injection, dangerous Redis commands (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Open redirect** | External URL detection in redirect_uri/return_url/next/callback params, double-encoding bypass |

**Request-level protections:**

| Item | Measure |
|--------|------|
| Request body size limit | Max 10MB (returns 413 when exceeded) |
| URL length limit | Max 2KB (returns 414 when exceeded, prevents ReDoS) |
| Content-Type whitelist | Only application/json, multipart/form-data, application/x-www-form-urlencoded allowed |

**WAF detection flow:**

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

#### IP Whitelist / Blacklist

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

### 4.2 Transport & Application Security

#### Global Middleware Execution Chain

All HTTP requests pass through the middleware in the following order; each middleware is independently testable:

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

#### Middleware Responsibilities

| Middleware | Registration | Responsibility |
|--------|---------|------|
| `VersionMiddleware` | Global | Validates the `X-Api-Version` header; defaults to `v1` when missing, returns `400` for unsupported versions |
| `CorsMiddleware` | Global | Handles OPTIONS preflight, reflects Origin to `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Global | Validates the `X-Client-Platform` header, identifies the client OS platform (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), injects `$request->properties['client_platform']` |
| `WafMiddleware` | Global (service) + admin instance | 8 categories 45+ rules + request size limits + Content-Type validation, records audit logs when blocking |
| `LocaleMiddleware` | Global | Parses the `Accept-Language` header, sets the locale |
| `HashidRequestMiddleware` | Global | Automatically decodes hashid strings in requests to real integer IDs |
| `MaintenanceMiddleware` | Global | Checks the `MAINTENANCE_MODE` environment variable, whitelisted IPs pass through |
| `EncryptionMiddleware` | Route groups (/api/auth, /api, /admin/api) | AES-256-GCM request/response body encryption, triggered by the `X-Encrypted: 1` header |
| `AuthMiddleware` | Route groups (/api, /admin/api) | JWT HS256 access_token validation, injects `$request->userId` and `$request->userRole` |
| `AdminRoleMiddleware` | Route group (/admin/api) | Admin RBAC permission checks |
| `ConfirmationMiddleware` | Route groups (sensitive operations) | Secondary password confirmation, Redis failure counter, 5 failures lock for 15 minutes |

#### ClientPlatform Middleware Details

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

**Data flow**: middleware injection → `AuditLogger` records automatically → `AuthService::issueTokens()` writes to `refresh_tokens` → `GET /api/user/sessions` returns platform info

#### HTTPS Enforcement

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

#### JWT Hardening

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

#### Password Policy

```
- bcrypt 加密，cost factor = 12
- 最小 8 字符，必须包含大小写字母 + 数字
- 注册/登录连续失败 5 次 → 账号锁定 15 分钟
- 密码修改后，所有已签发 token 立即失效
- 支持 TOTP 两步验证 (用户可选开启)
```

#### CORS Policy

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

#### File Upload Security

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

### 4.3 Data & Storage Security

#### Sensitive Data Encryption

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

#### Log Sanitization

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

#### Database Security

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

#### Backup & Recovery

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

### 4.4 Virtualization & Resource Isolation

#### Proxmox Hardening

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

#### Inter-VM Isolation

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

#### IP Allocation Security

```
- IP 分配记录完整审计 (谁、何时、分配了什么 IP)
- IP 释放后冷却期 24h (防止 IP 被立即分配给其他人导致的误用)
- IP 黑名单: 被投诉/滥用的 IP 标记为不可分配
- IP 使用监控: 定期检查分配的 IP 是否正常使用中
```

---

### 4.5 Payment Security

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

#### Refund Security

```
- 退款必须经过二级审批 (客服发起 → 管理员确认)
- 退款前校验: 订单状态、退款时限、退款次数
- 退款金额不能超过原订单实付金额
- 原路退回: 支付通道退款接口 + 余额退回
- 退款互斥锁 (Redis): 防止并发重复退款
```

---

### 4.6 Access Control & Permissions

#### RBAC Model

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

#### API Rate Limiting

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

#### Supplier Data Isolation

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

### 4.7 Operational Auditing

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

### 4.8 Risk Control Rules

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

### 4.9 Incident Response

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

## 5. Resource Provisioning Engine

### Provider Plugin Architecture

Each cloud product type × cloud provider combination implements a unified interface:

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

ProviderFactory routes to concrete implementations based on (product_type, provider):
- ProxmoxProvider (self-operated physical machines: servers/data disks/IPs)
- AwsServerProvider / AliyunServerProvider (third-party cloud servers)
- GcpIpProvider (third-party IPs)
- AzureDiskProvider (third-party cloud disks)
- NamecheapDomainProvider / GoDaddyDomainProvider (domains)

### Async Task Guarantees

- Provisioning Worker polls the provision_tasks table
- Concurrency is controlled per provider group (max 5 concurrent per provider)
- Retry policy: 1min → 5min → 15min → 1h → 6h → 24h (max 6 attempts)
- Non-retryable failures → alert + auto-generated ticket

### Complete Order-to-Provisioning Flow

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
4. GET /orders/{id}/payment-methods     7. 前端 confirmCardPayment()          ├→ HostSelector::select()
   → 获取可用支付通道                    8. Stripe webhook 回调               ├→ ProxmoxApi::create()
   ← [{channel, fee, total}]               → 验签 + 幂等检查                   │  createVM(CPU,RAM,Disk)
                                            → transaction=success              │  allocateIP()
                                            → 触发 OrderPaid 事件               │  startVM()
                                        重试策略 (失败时)                      ├→ 创建 Resource 记录
                                        ────────────────                     └→ 更新 host_machine
                                        1min → 5min → 15min                      已分配资源量
                                        → 1h → 6h → 24h
                                        (6 次后标记失败 + 告警)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        退款流程                                ::send('resource_ready')
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### Self-Operated Physical Machine Solution: Proxmox VE (Community Edition)

Self-operated servers use Proxmox VE (open source, free, AGPL v3); PHP manages the KVM virtual machine lifecycle and resource allocation via HTTP calls to the Proxmox REST API.

Architecture:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### ProxmoxApi Client Wrapper

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

#### Resource Operations

**Create VM (server):**
1. HostSelector picks a host with sufficient resources (sorted by cpu/ram/disk headroom + load balancing)
2. Allocate an IP from the host's ip_pool
3. ProxmoxApi.post("/nodes/{node}/qemu") creates the VM (setting vmid, name, cores, memory, net0, ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") attaches the system disk (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") starts the VM
6. Update host_machine.specs allocated amounts (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**Upgrade CPU (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**Upgrade memory (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Resize system disk:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Create data disk separately:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Create IP separately:**
Allocate from the IP pool → add a virtual NIC and configure the IP via the Proxmox API, or keep it as a standalone resource attached as an extra NIC to an existing VM.

**Destroy VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### Host Selection Strategy

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

#### Resource Operation Summary

| Operation | Implementation | Hot operation |
|------|----------|--------|
| Create VM (CPU + memory + system disk + IP) | Proxmox create qemu | — |
| Upgrade CPU only | PUT config cores | Online |
| Upgrade memory only | PUT config memory | Online |
| Resize system disk | PUT resize disk | Online (requires VM support) |
| Create data disk separately | POST config add disk | Online |
| Create IP separately | Allocate from IP pool + add NIC to VM | Online |

### Resource Lifecycle

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

Renewal: active → (renew) → active (extends expired_at)
Upgrade: active → (upgrade) → upgrading → active

### Resource Sources

| Source | Virtualization/API | Product Types | Notes |
|------|-----------|----------|------|
| Self-operated physical machines | Proxmox VE (community edition) | Servers, data disks, IPs | Self-owned data center hosting, PHP calls the Proxmox API |
| Third-party cloud providers | AWS/GCP/Aliyun/Huawei Cloud/Azure SDK | Servers, IPs, cloud disks | Reselling third-party cloud resources |
| Domain registrars | Namecheap/GoDaddy/Aliyun Wanwang API | Domain registration/transfer | Domain services |

### First-Phase Integrations

| Region | Servers | IPs | Cloud Disks | Domains |
|------|--------|----|------|------|
| Asia-Pacific | Aliyun, Huawei Cloud, AWS | Aliyun, GCP | Aliyun, Huawei Cloud | Aliyun Wanwang, Namecheap |
| Europe | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| North America | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## 6. Payment System

### Multi-Channel Routing

PaymentRouter queries available channels based on the user's currency preference, calculates the final payable amount per channel (including channel fees), and returns a list of payment options.

### Payment Flow (Stripe)

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

### Cryptocurrency Payments

1. User selects a currency (e.g. USDT-TRC20)
2. Backend generates a payment address via the Coinbase Commerce / BitPay API
3. A Worker checks blockchain confirmations every 30s (or via webhook)
4. Confirmed receipt → triggers the OrderPaid event

### Exchange Rates & Multi-Currency

- Exchange rate sources are fetched from exchangerate-api on a schedule and stored in Redis
- Product pricing is USD-based; other currencies are converted in real time
- Exchange rates are locked at order time; refunds are returned at the original rate

### Payment Channel Visibility Control

payment_channels table fields:
- is_visible: whether to show on the user client
- visible_regions: restricts visible regions; empty means all
- min_amount / max_amount: order amount range limits

### Reconciliation

Every day at dawn, settlement reports are pulled from each channel and reconciled line by line against system transactions; differences > $0.01 trigger an alert.

### Refund Policy

- Servers/VPS: full refund within 72h of purchase
- Domains: refundable within 5 days of registration (ICANN rules)
- IPs: non-refundable after purchase
- Cloud disks: same policy as servers
- Special promotional products: non-refundable

Refund flow: user request → Ticket created → support review → admin confirmation → provider.destroy() → payment.refund() → refund to original channel

---

## 7. Client Page Structure

### Flutter / HarmonyOS User Client

- **Authentication**: login/registration (email + password, Google OAuth, Apple ID, phone), password reset, two-step verification
- **Home**: region selector, product category entries, Banner/promotions, recommended products
- **Products**: list (multi-condition filters), details (config/region/price calculator), reviews
- **Cart & Payment**: shopping cart, order confirmation (payment method/billing address/balance/coupon), checkout, payment result
- **My Resources**: resource list (filter by status), detail actions (reboot/shutdown/renew/upgrade/destroy), console SSO, usage charts
- **Orders**: list (pending payment/paid/completed/refunded), details, invoices
- **Tickets**: list, create, conversation
- **Account Center**: profile/KYC, balance & top-up, notifications, address management, language/currency/security settings
- **Public**: help center, terms of service, about

### webman-admin Admin Panel

- **Dashboard**: overview + trend charts
- **User Management**: list/detail/KYC review
- **Product Management**: categories/list/pricing (SKU×region)/inventory/reviews
- **Order Management**: list/detail/refund review/invoices
- **Payment Management**: channel config/transaction records/reconciliation reports
- **Resource Management**: list/provisioning task monitoring/cloud provider API config
- **Supplier Management**: onboarding review/list/product assignment/settlement/withdrawals
- **Ticket Management**: queues/my tickets/SLA monitoring
- **Domain Management**: TLD pricing/registrar APIs/transfer management
- **Notifications**: template management/send records
- **System Settings**: admins & roles/operation logs/multi-language/exchange rates/regions/system parameters
- **Reports**: revenue/supplier settlement/product sales analysis/regional analysis

---

## 8. Notification System

### Four Channels

Email (SMTP/SendGrid) / SMS (Twilio/Aliyun SMS) / Push (FCM/HMS) / in-app messages

### Flow

Event trigger → Notification Dispatcher → match template (event code + language preference) → distribute to channels per user preferences → Redis Queue async send

### Notification Types

Registration verification codes, order payment success, resource provisioning complete, resource expiry reminders (7d/3d/1d), ticket replies, refund complete, security alerts, promotions

### Failure Retry

3 backoff retries, managed via webman redis-queue.

---

## 9. Supplier System

### Onboarding Flow

Register → submit company info + contact + settlement method → admin review → list products after approval → admin reviews products → user purchases → automatic revenue sharing → supplier requests withdrawal → admin pays out

### Permission Isolation

Suppliers can only see their own products/orders/settlement statements/tickets/withdrawal records. They cannot see platform revenue, other suppliers' data, or payment channel config.

### Revenue Sharing Rules

- Self-operated products: commission_rate = 100% (all to the platform)
- Third-party products: commission_rate = 5%~20% (platform commission)
- Settlement formula: order product amount - platform commission - channel fees = supplier receivable
- Settlement cycle: weekly / monthly

### Complete Supplier Business Flow

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

## 10. Monitoring & Operations

### Resource Monitoring

- Collected metrics: CPU/memory/disk/bandwidth usage, IP connectivity, cloud disk IOPS, DNS resolution, SSL certificate expiry
- Collection methods: Agent reporting / SNMP (self-owned) + cloud provider monitoring APIs (third-party) + WHOIS/DNS polling (domains)
- Collection interval: 5 minutes, stored in Prometheus + VictoriaMetrics

### Alert Rules

| Alert Event | Severity | Trigger Condition |
|----------|--------|----------|
| Server down | Critical | 3 consecutive unreachable pings |
| CPU/memory > 90% | Info | Sustained for 10 minutes |
| Disk > 90% | Warning | Sustained for 5 minutes |
| Bandwidth > 80% | Info | Sustained for 30 minutes |
| SSL certificate < 30 days to expiry | Warning | Daily check |
| Domain < 30 days to expiry | Warning | Daily check |
| Provisioning task failure | Critical | 2 consecutive failures |
| Payment reconciliation difference | Critical | Single transaction > $0.01 |

---

## 11. Deployment Architecture

### Production Environment

- Application servers × 2: webman (multi-process) + Nginx + Supervisor
- Database: MySQL 8.0 master-slave (1 master 2 replicas) + Redis Cluster
- Queue: webman redis-queue (payment callbacks/notifications/provisioning tasks)
- Scheduled tasks: Crontab (reconciliation/settlement/domain checks/renewal reminders)
- Storage: S3/OSS + CDN
- Log monitoring: ELK/Loki + Prometheus + Grafana + Sentry

### Directory Structure

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

### Key Composer Dependencies

workerman/webman-framework, webman/admin, webman/redis-queue, illuminate/database, firebase/php-jwt, stripe/stripe-php, phpseclib/phpseclib, monolog/monolog

### High-Concurrency Optimizations

#### 1. MySQL Read/Write Splitting

Eloquent automatically routes SELECT to the read connection and INSERT/UPDATE/DELETE to the write connection.

```
配置 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (主库)
  connections.mysql.read  → DB_READ_HOST  (从库，可配置多个实现负载均衡)
  sticky = true           → 同一请求周期内写后读走主库（防主从延迟）

环境变量:
  DB_HOST=10.0.1.1          # 主库（写）
  DB_READ_HOST=10.0.2.1     # 从库（读），可部署多个
```

**Read/write routing rules:**

| Operation Type | Route Target | Example |
|---------|---------|------|
| SELECT | read connection | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | write connection | `Order::create(...)` |
| All operations in a transaction | write connection | `DB::transaction(...)` |
| Read-after-write (sticky) | write connection | within the same request cycle |

#### 2. Redis Multi-Level Caching Strategy

`CacheService` caches high-frequency read data; it automatically degrades to direct database queries when Redis is unavailable.

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

#### 3. Nginx Response Compression + Rate Limiting

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

#### 4. Database Index Recommendations

Based on query-pattern analysis, the following indexes significantly reduce scanned rows in high-concurrency scenarios:

| Table | Recommended Index | Queries Covered |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | User order list + status filtering |
| `orders` | `(order_no)` (unique) | Exact order number lookup |
| `products` | `(status, category_id, sort)` | Frontend product list + category filtering + sorting |
| `product_skus` | `(product_id, status)` | SKU list + status filtering |
| `product_regions` | `(sku_id, region_id)` (unique) | Regional pricing lookup |
| `resources` | `(user_id, status)` | My resources list |
| `resources` | `(expired_at, status)` | Expiry check scheduled task |
| `provision_tasks` | `(status, next_retry_at)` | Worker polling of pending tasks |
| `refresh_tokens` | `(user_id, revoked)` | Session management queries |
| `payment_transactions` | `(order_id)` | Query transactions by order |
| `payment_transactions` | `(transaction_no)` (unique) | Webhook idempotency check |
| `tickets` | `(user_id, status)` | User ticket list |
| `notifications` | `(user_id, read_at, created_at)` | User notification list |

#### 5. Concurrent Connection Estimates

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

## 12. Implementation Status Summary

### Core Modules

| Module | Status | Notes |
|------|------|------|
| **User** | ✅ Complete | Registration/login/email verification/OAuth/TOTP/session management/GDPR deletion/address CRUD |
| **Product** | ✅ Complete | SKU×region pricing, categories, search (ES), reviews, attributes, bulk import/export |
| **Order** | ✅ Complete | Cart, checkout, lifecycle, refunds, invoices (PDF), coupons |
| **Payment** | ✅ Complete | Stripe channel, multi-channel routing, webhook signature verification, reconciliation |
| **Provisioning** | ✅ Complete | Proxmox + AWS EC2 + extensible ProviderFactory architecture |
| **Domain** | ✅ Complete | TLD pricing, DNS records, domain transfer approval |
| **Supplier** | ✅ Complete | Onboarding review, product listing, settlement, withdrawals, API Key management |
| **Monitor** | ✅ Complete | Resource probing, alert engine, SSL certificate monitoring |
| **Ticket** | ✅ Complete | Create/reply/assign/close/SLA tracking |
| **Notification** | ✅ Complete | Email/SMS/Push/in-app four channels + user preference management |
| **Report** | ✅ Complete | Revenue/supplier/regional reports |
| **I18n** | ✅ Complete | Multi-language, multi-currency, multi-timezone |

### Security System

| Feature | Status |
|------|------|
| WAF (8 categories 45+ rules: SQL injection/XSS/command injection/file inclusion/header injection/SSRF/NoSQL injection/open redirect) | ✅ |
| CORS middleware | ✅ |
| ClientPlatform platform-identification middleware (8 platforms) | ✅ |
| API rate limiting (Redis token bucket) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| Maintenance mode (environment variable switch + IP whitelist) | ✅ |
| Request/response encryption (AES-256-GCM) | ✅ |
| Audit logs (separate database, includes client_platform tracking) | ✅ |
| Data sanitization (automatic in logs/responses) | ✅ |
| JWT device-fingerprint binding + token rotation + client_platform recording | ✅ |
| bcrypt passwords (cost=12) + Encryptable secondary encryption | ✅ |
| Secondary password confirmation (ConfirmationMiddleware, 5 failures lock 15min) | ✅ |
| Admin panel WAF middleware | ✅ |
| Sentry exception monitoring (SentryBootstrap + before_send sanitization) | ✅ |
| Feature flags (Redis dynamic overrides + admin panel API) | ✅ |

### New Features (2026-05-21)

| Feature | Status |
|------|------|
| Supplier external API (API Key auth + order/resource/settlement/withdrawal endpoints) | ✅ |
| WebSocket real-time push (Workerman native WebSocket + event listeners) | ✅ |
| k6 load-testing scripts (smoke/products/concurrent) | ✅ |

### Backend Statistics

| Metric | Count |
|------|------|
| API endpoints | 135 |
| Data models | 50+ |
| Database tables | 50+ |
| Middleware | 15 (7 global + 6 route + 1 external API + admin WebSocket) |
| Scheduled tasks | 7 |
| Migration files | 22 |
| Tests | 362 tests / 579 assertions (Service 295 + Admin 67) |
| Test files | 22 |
| k6 load-testing scripts | 3 (smoke / products / concurrent) |

### Documentation

| Document | Path |
|------|------|
| System design spec | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Admin panel design | `docs/admin-design.md` |
| Supplier API docs | `docs/supplier-api.md` |
| Deployment checklist | `docs/deployment.md` |
| API smoke test script | `docs/api-test.sh` |

### Frontend Status

| Client | Status | Notes |
|----|------|------|
| Flutter | 🟡 In progress | ApiClient integrated with header versioning + ApiService unified data layer; login/product list/cart/resource list connected to the API; order history/notification center need build environment verification |
| HarmonyOS | 🔴 Early stage | Only login page and ApiClient |
| Admin Panel | ✅ Complete | Dashboard/user/product/order/payment/resource/supplier/ticket/domain/notification/system/reports/webhook/import-export all functional |
