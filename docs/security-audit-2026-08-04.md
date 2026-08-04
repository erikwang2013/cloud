# Security Audit Report — cloud-php

**Date**: 2026-08-04
**Scope**: Full project (service + admin)
**Methodology**: Configuration review, middleware audit, code inspection

---

## Overall Assessment: **B+ (Good, 4 gaps to fix)**

The project has a solid multi-layered security architecture. The erikwang2013/security-php plugin with 31 detectors is the standout feature. Below is the detailed breakdown.

---

## 1. Defenses In Place (verified)

### Transport and Encryption
| Mechanism | Implementation | Status |
|-----------|---------------|--------|
| API transport encryption | AES-256-GCM via erikwang2013/encryption | OK |
| DB field encryption | AES-128-ECB via erikwang2013/encryptable (deterministic, queryable) | OK |
| Key rotation | ENCRYPTION_PREVIOUS_KEYS comma-separated old keys | OK |
| ID obfuscation | Hashids with configurable salt and min length 12 | OK |
| Password hashing | bcrypt cost=12, min length 8 | OK |

### Authentication and Access Control
| Mechanism | Implementation | Status |
|-----------|---------------|--------|
| JWT auth | erikwang2013/jwt-webman, HS256, access TTL 900s + refresh 30d | OK |
| JWT blacklist | Redis-backed token revocation | OK |
| MFA/TOTP | 6-digit, 30s period, Google/MS Authenticator compatible | OK |
| RBAC | Admin AccessControl middleware + plugin\admin\api\Auth::canAccess() | OK |
| Session storage | Redis (db2) | OK |
| Captcha | erikwang2013/poster-php click-text captcha for login/register | OK |

### Attack Detection (WAF — Dual Layer)
| Layer | Coverage | Status |
|-------|----------|--------|
| Custom WafMiddleware | SQLi, XSS, CMDi, path traversal, header injection, SSRF, NoSQLi, open redirect | OK |
| Security Plugin (31 detectors) | All above + XXE, deserialization, LDAP, mail header, SSTI, JWT attack, Host header, request smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, CSV injection, data leak, prototype pollution, WebSocket, CORS bypass, DNS rebinding | OK |

### Rate Limiting (service only)
| Route | Rate | Burst | Per | Status |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### Other Protections
| Mechanism | Implementation | Status |
|-----------|---------------|--------|
| Request size limits | 10MB body, 2KB URL | OK |
| Content-Type validation | Whitelist: JSON, multipart, form-urlencoded | OK |
| Database prepared statements | PDO::ATTR_EMULATE_PREPARES = false | OK |
| DB read/write separation | Write to master, Read to replica, sticky sessions | OK |
| Audit logging | Separate audit DB, LogSanitizer redacts sensitive fields | OK |
| Maintenance mode | Whitelist IPs bypass, others get 503 + Retry-After | OK |
| IP auto-ban | 5 violations in 60s then 15min ban | OK |
| SQL strict mode | Prevents data truncation and implicit type conversion | OK |

---

## 2. Gaps and Recommendations

### Gap 1 (Medium): CORS is mirror-any-origin
**File**: `service/common/Security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

This echoes back whatever Origin the client sends, effectively allowing any website to make authenticated cross-origin requests. The security plugin's cors detector may catch some header injection, but the middleware itself provides no origin whitelist.

**Fix**: Add a whitelist check. If the origin is not in the allowed list, respond with `Access-Control-Allow-Origin: null` or omit the header entirely.

### Gap 2 (Medium): Missing security response headers
Neither service nor admin sets critical HTTP security headers:

| Header | Recommended | Current |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | Missing |
| X-Content-Type-Options | nosniff | Missing |
| X-Frame-Options | DENY or SAMEORIGIN | Missing |
| Content-Security-Policy | Policy with nonce/hash | Missing |
| X-XSS-Protection | 1; mode=block | Missing |
| Referrer-Policy | strict-origin-when-cross-origin | Missing |
| Permissions-Policy | Restrict camera/mic/geolocation | Missing |

**Recommendation**: Add a SecurityHeadersMiddleware to both service and admin middleware stacks. High-impact, low-effort fix.

### Gap 3 (Low): admin/config/security.php lacks rate limiting
**File**: `admin/config/security.php`

The admin panel has no rate_limits configuration. The admin WAF middleware only checks request size/Content-Type limits. A brute-force attack on the admin login is not rate-limited at the application layer.

**Recommendation**: Either add rate_limits to admin/config/security.php or apply the RateLimitMiddleware to admin routes.

### Gap 4 (Low): GeoBlockMiddleware defined but not activated
**File**: `service/common/Security/GeoBlockMiddleware.php`

The middleware exists and is functional, but it is not registered in `service/config/middleware.php`. If geo-blocking is needed, add it to the stack.

### Gap 5 (Info): Dual WAF overhead
Both WafMiddleware (custom, 40+ regex patterns) and SecurityMiddleware (plugin, 31 detectors) run on every request. Their pattern coverage overlaps significantly for SQLi, XSS, command injection, path traversal, header injection, SSRF, NoSQLi, and open redirect.

**Recommendation**: The security plugin is more comprehensive (31 detectors vs 8 categories) and has IP blacklisting, field whitelisting, and log dedup. Consider removing the custom WafMiddleware and relying solely on the plugin, or at minimum remove the overlapping patterns from WafMiddleware.

### Gap 6 (Info): Validator class is minimal
**File**: `service/common/Helper/Validator.php`

Only has required(), email(), minLength(). Missing: max length, numeric validation, string sanitization, URL validation, pattern matching. Controllers that do not use framework-level validation are at risk of accepting malformed input.

---

## 3. Security Plugin — 31 Detector Status

| # | Detector | Mode | Notes |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF matches textarea content, must stay log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} matches Vue/Angular templates |
| 13 | nosql_injection | **log** | $ne/$gt matches shell vars/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | block | |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

All 31 detectors enabled. 3 in log-only mode (documented false-positive risk). Correct configuration.

---

## 4. Middleware Execution Order (service)

```
1. VersionMiddleware          — API version header parsing
2. CorsMiddleware              — CORS headers (too permissive, see Gap 1)
3. ClientPlatformMiddleware    — OS/platform detection
4. WafMiddleware               — Custom WAF (40+ regex patterns)
5. SecurityMiddleware           — Plugin WAF (31 detectors)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — ID decoding
8. MaintenanceMiddleware       — Maintenance mode check
```

---

## 5. Summary

| Category | Grade | Key Issues |
|----------|-------|------------|
| Attack Detection | **A** | 31 detectors, dual WAF layer (redundant but thorough) |
| Authentication | **A-** | bcrypt+MFA+JWT blacklist, admin rate limit missing |
| Transport Security | **B+** | AES-256-GCM fine, missing HSTS/CSP headers |
| Input Validation | **B** | WAF catches attacks, app-level validation is thin |
| Access Control | **A-** | RBAC + session check, CORS too permissive |
| Audit/Logging | **A** | Separate audit DB, sensitive field redaction |
| Rate Limiting | **B+** | Well-configured for service, missing for admin |

**Priority fix order:**
1. Add security response headers (HSTS, CSP, X-Frame-Options, etc.)
2. Restrict CORS to a whitelist instead of mirroring any origin
3. Add rate limiting to admin panel
4. Activate GeoBlockMiddleware if geo-blocking is needed
5. Consider consolidating WAF layers to reduce per-request regex overhead

---

## 6. Remediation Applied (2026-08-04)

### Fixed
| Gap | Fix | Files Changed |
|-----|-----|---------------|
| CORS mirror-any-origin | Whitelist mode with `CORS_ALLOWED_ORIGINS` env var, supports `*.example.com` wildcards and `*` for all | `service/common/Security/CorsMiddleware.php` |
| Missing security headers | New `SecurityHeadersMiddleware` added to both service and admin stacks: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (opt-in via env) | `service/common/Security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| Admin no rate limiting | Added `rate_limits` config + `RateLimitMiddleware` to admin panel (default 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock not activated | Registered `GeoBlockMiddleware` in service middleware stack | `service/config/middleware.php` |

### New Env Variables
| Variable | Purpose | Default |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Comma-separated allowed origins | (empty = deny all) |
| `SECURITY_HSTS_ENABLE` | Enable HSTS header | false |
| `SECURITY_HSTS_VALUE` | HSTS header value | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | X-Frame-Options value | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Blocked country codes (ISO 3166-1) | (empty = disabled) |
| `GEOIP_DB_PATH` | GeoLite2 .mmdb path | storage_path('geoip/GeoLite2-Country.mmdb') |

### Updated Middleware Pipeline

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
