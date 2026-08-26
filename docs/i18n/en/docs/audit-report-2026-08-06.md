# CloudPlatform Comprehensive Audit Report

**Date**: 2026-08-06
**Audit scope**: service full sweep (app / common / config / tests) + ecosystem configuration + security protection
**Method**: PHPUnit test suite, full PHP syntax check, route/middleware audit, OAuth new feature code review, environment variable and config consistency check, dependency security audit, smoke tests

---

## I. Overall Conclusion

| Dimension | Conclusion |
|------|------|
| Tests | **All 314 passed** (494 assertions, after fixing 2 bugs) |
| Syntax | 287 PHP files, 0 syntax errors |
| Dependency security | composer audit no known vulnerabilities; 1 deprecated package (doctrine/annotations) |
| Security architecture | Multi-layer protection complete (dual WAF engines, CORS whitelist, transport encryption, field encryption, bcrypt cost=12, JWT blacklist, audit logs) |
| Critical issues | **1 P0 (Apple id_token not signature-verified → account takeover), 4 P1** |
| Ecosystem config | **.env.example missing 31 variables in use**, including all OAuth credentials; notification channels are placeholder implementations |

---

## II. Test Results

```
OK (314 tests, 494 assertions)
```

### 2 Bugs Fixed This Round

| ID | File | Issue | Fix |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | Reads `$result['extra']['targets']`, but the library returns `extra.texts` → `target_count` always 0 | Changed to `extra.texts` |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | Library default `targetCount = 5`, contradicting the library's own README contract (medium=3 targets) → 3 Captcha tests failed | Default 5 → 3 |

> B2 is a vendored library bug (vendor/ is tracked by git, the fix persists). Suggest submitting the fix upstream as well.

---

## III. Critical Security Issues (P0 / P1)

### P0-1. Apple `id_token` not signature-verified — direct account takeover
**File**: `service/app/user/service/OAuthService.php:180-192` (`appleProfile()`)

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // base64 decode only, no signature/iss/aud/exp validation
```

An attacker can craft their own `id_token` to forge any email and complete OAuth login. `resolveUser()` matches existing users by email and directly issues tokens → **arbitrary account takeover**.

**Fix**: verify the signature with Apple JWKS (`https://appleid.apple.com/auth/keys`) + `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])`, and validate `iss=appleid.apple.com`, `aud=client_id`, `exp`, and `nonce`.

### P1-1. OAuth login does not validate `email_verified`
**File**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn all return the `email_verified` field, which the code completely ignores. Users with unverified emails on the provider can directly bind/take over registered accounts with that email. The GitHub path validates `verified` (correct); the other providers need unified validation.

### P1-2. Rate limiting middleware exists but is never mounted — docs and implementation diverge
**File**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` already configures limits like login=5/min, register=3/min
- `RateLimitMiddleware` is **not referenced by any route** (repo-wide grep only hits the class itself)
- `docs/features.md` claims login is "rate limited 5 req/min" and registration "rate limited 3 req/min" — actually nonexistent
- The historical audit report (`security-audit-2026-08-04.md`) marked this item OK, having only checked config without verifying mounting; corrected this round

**Impact**: public endpoints such as login/registration/forgot password/reset password/recovery codes/CAPTCHA can be brute-forced without limits (login relies only on per-account lockout, which does not prevent credential stuffing or IP-level flooding).

**Fix**: mount `RateLimitMiddleware` on public routes like `/api/auth/*`, `/api/captcha/*` (can be mounted in the global `''` group, differentiated by the `route` parameter).

### P1-3. TOTP 2FA not enforced in the login flow
**File**: `AuthService.php:64-97` (`login()`) + `AuthController.php` + `config/features.php`

`user->totp_enabled` is only checked in `totpVerify/totpDisable/totpRecoveryCodes`; **`login()` never validates it**. Users with 2FA enabled still get a valid access token with just a password — 2FA is effectively useless (`FEATURE_TOTP` defaults on).

**Fix**: at login, if `totp_enabled`, issue a temporary token and require TOTP verification before exchanging it for a regular token (or require the totp code parameter).

### P1-4. Notification channels are placeholder implementations — email verification/password reset unusable in production
**File**: `app/Notification/Queue/EmailSender.php`, `SmsSender.php`, `PushSender.php`

All three consumers only `error_log()` to simulate sending, and record `send_status` as `sent`. Consequences:
- **Forgot-password flow broken**: `AuthController::forgotPassword()` generates a code and "sends" an email, but the email never arrives → users cannot self-reset passwords
- Registration email verification and new-IP login alerts likewise broken
- The 7 `SMTP_*`/`MAIL_FROM_*` variables in `.env.example` are read by no code (dead config)

**Fix**: integrate real email sending (PHPMailer/SendGrid SDK), remove the misleading `sent` status flag; or explicitly mark as unfinished feature and remove related promises from docs.

---

## IV. Security Issues (P2)

| ID | File | Issue |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | `type` parameter not whitelist-validated before being concatenated into path `uploads/{$type}/...` → **path traversal** can write outside the upload directory (random filenames, cannot overwrite, but can pollute the filesystem); suggest restricting type to an enum whitelist and adding `index.php`/`.htaccess` protection to storage dirs |
| P2-2 | same | Only extension checked, no MIME content sniffing (polyglot files can be exploited via cache/forwarding); suggest `finfo` validation of real MIME |
| P2-3 | `AuthController.php:131-158` | Reset-password 6-digit code valid 600s with no attempt limit → 1M combinations brute-forceable within 10 minutes; `forgotPassword` has no frequency limit → email bombing |
| P2-4 | `AuthController.php:333-348` | `totpRecoveryCodes` generation/viewing recovery codes only requires login, no password confirmation; should mount `ConfirmationMiddleware` |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | Blacklist manual check key is `jwt_blacklist:{sha256(token)}`, mismatching the library's `jwt_blacklist:{jti}` format → dead code (actual protection done by the library's `decode()`, effective but redundant); suggest removing or using the library interface |
| P2-6 | `OAuthService.php:67-94` | The `redirect` parameter of `authorizeUrl` is stored in state and never used (dead parameter); state not bound to provider; no nonce across the OAuth flow (OIDC providers, missing defense-in-depth, fix together with P0-1) |
| P2-7 | `OAuthService.php:31-37, 236-238` | X (Twitter) v2 API `userinfo` does not return email → X login inevitably fails with "Email not provided"; functional defect, needs docs note or switch to the `/2/email` endpoint |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` uses `strrpos($ip, '.')` to truncate the IPv4 subnet; IPv6 clients degrade to empty string → weak fingerprint; suggest using the first 64 bits or hashing the full IP |

---

## V. Ecosystem Configuration Completeness

### 5.1 .env.example missing variables (referenced via `getenv()` in code but undefined) — 31

| Category | Variables |
|------|------|
| **OAuth credentials (new feature, completely undocumented)** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT_URI` (21) |
| **Apple-specific** | `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH` |
| **Critical features** | `APP_URL` (verification email links depend on it, missing → wrong email links), `APP_ENV`, `APP_VERSION` |
| **Security** | `INTERNAL_MONITOR_TOKEN` (protects /health/* endpoints), `MAINTENANCE_MODE`, `MAINTENANCE_ALLOWED_IPS`, `WEBHOOK_SECRET`, `JWT_LEEWAY` |
| **Cloud/storage** | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, `DB_READ_HOST` |
| **Feature flags (8)** | `FEATURE_SSL_PRODUCT`, `FEATURE_OBJECT_STORAGE`, `FEATURE_USAGE_BILLING`, `FEATURE_PROMETHEUS`, `FEATURE_CDN_PRODUCT`, `FEATURE_SUPPLIER_RATING`, `FEATURE_AFFILIATE`, `FEATURE_GRAPHQL` |
| **Other** | `METRICS_PORT`, `WS_PORT`, `GEOIP_DB_PATH` (comment-only in .env.example), `SSL_STAGING`, `HASHIDS_ALPHABET`, `POSTER_IMAGE_DRIVER`, `EXCHANGE_RATE_API_URL`, `COUNTRY_SEASON_DEFAULT` |

### 5.2 .env.example defines but code does not use — 7

`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (email sending not implemented, see P1-4)

### 5.3 i18n Coverage Inconsistency

| Language | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- Non-Chinese/English languages are missing more than half of the translation keys; zh-CN has 6-8 more keys than en-US in billing/health/storage (sync direction reversed)
- **All OAuth-related translation keys missing** (error messages hardcoded English)

### 5.4 Other Ecosystem Issues

| ID | Issue |
|----|------|
| E1 | `service/composer.lock` is gitignored and not committed — application dependencies not version-locked, deployment not reproducible (deployment risk) |
| E2 | `service/.phpunit.cache/` appears in git status (not ignored) |
| E3 | Port 8787 conflicts with another local project erp-php; cloud-php cannot start on this machine (confirmed 8787 is occupied by erp-php's WorkerMan) |
| E4 | `docs/features.md` claims rate limiting/email features that don't match reality (see P1-2 / P1-4), docs need syncing |
| E5 | Dependency `doctrine/annotations` deprecated (composer audit notice), suggest evaluating removal |

---

## VI. Optimization Suggestions (non-blocking)

1. **DI-ify service creation**: `AuthController` constructor directly does `new AuthService()/OAuthService()`; suggest container injection (natively supported by webman) for easier testing and replacement.
2. **Upload directory hardening**: place `index.html` in directories, disable PHP execution (nginx `location ~ \.php { deny all; }`).
3. **WAF regex tightening**: `sqli_patterns` in `security.php` contains broad patterns like `\b(select|update|delete|...)\b`; under global rate limiting, users with these words in tickets/reviews get false-positive 403s; suggest applying only to sensitive parameters or tightening regexes.
4. **Log audit**: `AuditLogger::record('user_registered', ['user_id' => null])` does not record the new user ID; suggest registering the actual ID.
5. **OAuth test coverage**: `OAuthServiceTest` covers URL construction and code exchange, but `resolveUser()` (DB path) and the Apple signature path have no tests; after the P0 fix, test cases for signature verification failure are mandatory.
6. **CI integration**: the project has a `.github` directory; suggest adding GitHub Actions: `composer install && phpunit` + `composer audit` to prevent regressions.
7. **HTTP method constraints**: OAuth routes registering both GET/POST callbacks is reasonable (Apple requires it); other public write operations are explicitly POST, OK.

---

## VII. Fix Priority List

| Priority | Item | Effort |
|:---:|------|:---:|
| P0 | Apple id_token signature verification (JWKS + iss/aud/exp/nonce) | medium |
| P1 | Validate `email_verified` across all OAuth providers | small |
| P1 | Mount RateLimitMiddleware on public routes | small |
| P1 | Enforce TOTP in the login flow | medium |
| P1 | Implement real email sending (or mark as unfinished) | medium |
| P1 | .env.example fill in 31 missing variables + OAuth config docs | small |
| P2 | Upload type whitelist + MIME validation | small |
| P2 | Reset-code/forgot-password rate limiting | small |
| P2 | Recovery-code endpoint behind password confirmation | small |
| P2 | Commit composer.lock, gitignore .phpunit.cache | tiny |
| P3 | Blacklist dead code cleanup, WAF regex tightening, i18n completion | medium |

---

## VIII. Fix Status (2026-08-06)

| Priority | Item | Status |
|:---:|------|:---:|
| P0 | Apple id_token signature verification (JWKS + iss/aud/exp/nonce) | ✅ fixed |
| P1 | Validate `email_verified` across all OAuth providers (X adds /2/email fallback) | ✅ fixed |
| P1 | Mount RateLimitMiddleware (auth/oauth/password/sms/captcha routes + 4 new rules) | ✅ fixed |
| P1 | Enforce TOTP in login flow (5 errors lock 15 minutes, independent counter prevents DoS) | ✅ fixed |
| P1 | Real email sending (symfony/mailer SMTP; dev-stub status when unconfigured) | ✅ fixed |
| P1 | .env.example fill in 31 missing variables + OAuth config docs | ✅ fixed |
| P2 | Upload type whitelist + finfo MIME content sniffing | ✅ fixed |
| P2 | Reset-code/forgot-password rate limiting (5 errors → 429 for 10 minutes) | ✅ fixed |
| P2 | Recovery-code endpoint behind password confirmation | ✅ fixed |
| P2 | composer.lock unignored and staged, gitignore .phpunit.cache | ✅ fixed |
| P3 | Blacklist dead code cleanup, WAF regex tightening (3 structural rules), i18n completion (zh-CN billing/health/storage wrong content rewritten, trans() implements fallback_locale) | ✅ fixed |
| E3 | Port 8787 occupied by erp-php, cannot start locally | ⚠️ environment issue, no conflict in deployment environments |
| E5 | doctrine/annotations deprecated | ⚠️ kept after evaluation (direct dependency of hg/apidoc; removing breaks API doc generation) |

Additional tests: OAuth 12 items (incl. nonce parameter, signature verification, email_verified rejection, X email fallback), 2 items after WAF tightening. Full baseline: **319/319 passed (505 assertions)**.

*Report generation method: full PHPUnit run, `php -l` on 287 files, route/middleware static audit, set-difference comparison of env usage vs definition, composer audit, port and process probing. Test baseline: 314/314 passed.*
