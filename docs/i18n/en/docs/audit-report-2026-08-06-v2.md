# CloudPlatform Audit Report (Round 2, 2026-08-06)

> Scope: re-inspection after all issues from the previous round (audit-report-2026-08-06.md) were fixed.
> Test baseline: PHPUnit **319/319 passed (505 assertions)**; `php -l` on 253 PHP files **0 syntax errors**.

---

## I. Tests and Static Checks

| Item | Result |
|------|------|
| Full PHPUnit | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | All 253 files passed |
| composer audit | **no security vulnerabilities**; 1 deprecated package doctrine/annotations (direct dependency of hg/apidoc, kept after evaluation) |
| composer.lock | under version control (staged A) |

---

## II. Ecosystem Configuration Check

### 2.1 env Usage vs Definition — Complete ✓

- All `getenv()` keys in code (incl. dynamic `{PROVIDER}_OAUTH_*` patterns) have definitions or comment-form optional config in `.env.example` (`#HASHIDS_ALPHABET`, `#POSTER_IMAGE_DRIVER`, `#EXCHANGE_RATE_API_URL`, `#COUNTRY_SEASON_DEFAULT`, `#SECURITY_HSTS_VALUE`)
- Template redundancy (low risk): `MAIL_FROM_NAME` has no `getenv()` reference in code, kept only in the template

### 2.2 Dependency Locking ✓

- `service/composer.lock` committed; no longer excluded in `.gitignore`; `service/.phpunit.cache/` ignored

### 2.3 Environment Notes

- Local port 8787 still occupied by erp-php; cloud-php cannot start locally (no conflict in deployment environments)
- `composer validate` fatal due to an Installer eval conflict between the vendor plugin `erikwang2013/security-php` and composer itself (third-party package issue, not this project's code)

---

## III. Security Protection Check

### 3.1 Global Middleware Chain (11 layers, covering all routes) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF (SQLi/XSS) → SecurityPlugin (31 attack detections)
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 Public Route Rate Limiting — 1 fix this round

| Route | Middleware | Rate limit rule |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min, login 5/min |
| **forgot-password / reset-password** | **RateLimit (mounted this round)** | password_reset 3/5min |
| oauthRedirect / oauthCallback (GET+POST) | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **Fix**: the `forgot-password`/`reset-password` routes had the `password_reset` rule defined last round but the middleware mount was missed (email-bombing/CAPTCHA-brute-force surface); mounted this round.

### 3.3 Upload File Exposure — 1 fix this round (high risk)

**Issue**: the nginx config in `deployment.md` `location /storage/ { alias .../service/storage/; }` exposes the entire storage directory publicly:

```
storage/
├── backups/    ← database backups (.sql.gz) publicly downloadable
├── apple/      ← AuthKey.p8 private key publicly downloadable (can sign Apple tokens)
├── firebase/   ← FCM service account credentials (incl. private key) publicly downloadable
├── geoip/      ← GeoLite2 database
└── uploads/    ← uploaded files (expected public)
```

**Fix**: both deployment.md and docker/nginx.conf changed to `location ^~ /storage/uploads/`, exposing only the uploads subdirectory.

### 3.4 Other Checks ✓

- `verify-email`: one-time random token (nulled after verification), no brute-force/enumeration surface, no rate limiting needed
- Upload endpoint: type whitelist + finfo MIME content sniffing (fixed last round); uploads served directly by nginx static alias, no PHP execution
- JWT: HS256 + Redis blacklist (validated by jti in library); TOTP enforced at login + 5 failures lock 15 minutes
- OAuth: JWKS signature verification + iss/aud/exp/nonce + email_verified enforced (fixed last round)
- Admin routes: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## IV. Remaining Recommendations (non-blocking)

| Level | Item | Description |
|:---:|------|------|
| P3 | `service/service/` redundant legacy directory (28K) | Contains outdated Supplier/WebSocket copies, not PSR-4 loaded, untracked, easy to mis-edit; suggest deleting after manual confirmation |
| P3 | `MAIL_FROM_NAME` template redundancy | Not used by code, can be kept as a reserved config for the email sender name |
| P3 | doctrine/annotations deprecation | Direct dependency of hg/apidoc; removal requires replacing the API doc generation approach |
| P3 | Upload directory hardening (second suggestion) | Place `index.html` in the uploads directory, confirm no PHP execution at deployment layer (nginx alias already avoids it natively; watch the webman built-in server scenario) |

---

## V. Conclusion

All 15 fixes from the previous round re-verified as effective, test baseline stable (319/505). This round found and fixed 3 issues on the spot: **forgot/reset routes missing rate limiting mount (P1)**, **deployment.md nginx config exposing backups and private keys (P0)**, **docker nginx missing uploads static config (P2)**. Full test suite re-run passed after the fixes.

*Report generation method: full PHPUnit, php -l on 253 files, route/middleware static audit, nginx/docker config audit, env usage vs definition set-difference comparison, composer audit.*
