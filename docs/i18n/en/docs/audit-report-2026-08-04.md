# CloudPlatform Comprehensive Audit Report

**Date:** 2026-08-04  
**Audit scope:** entire project (code quality, security, ecosystem configuration, deployment, documentation)  
**Branch:** main  
**Latest commit:** e321bcc — the 3 remaining issues fixed this round

---

## I. Project Overview

| Dimension | Status |
|------|------|
| Project type | PHP 8.2+ / webman cloud resource trading platform |
| Code scale | service (15 modules, 295 tests) + admin (53 controllers, 67 tests) + Flutter + HarmonyOS |
| Database | MySQL 8.0, 46 tables (7 wa_* + 39 erik_*) |
| Deployment | One-click installation wizard / Docker Compose / manual |
| Documentation | 10 docs + 11 SVG architecture diagrams |

---

## II. Issues Found

### CRITICAL

#### C1. Docker Deployment Missing the Admin Panel

**Issue:** The Dockerfile only copies the `service/` directory, and docker-compose only proxies port 8787. The admin panel (port 8788) is not Dockerized at all.

```dockerfile
# docker/Dockerfile — currently only handles service
COPY service/ /app/
```

**Impact:** Users deploying with Docker cannot use the admin panel. This contradicts the README's claim of "Docker Compose one-click startup".

**Suggestion:** Add a Dockerfile for `admin/` or use a multi-stage build to deploy both services.

---

#### C2. Docker Database Ports Exposed to the Host

**Issue:** In docker-compose.yml, MySQL (3306) and Redis (6379) ports are mapped directly to the host:

```yaml
mysql:
  ports:
    - "3306:3306"    # exposed to the public
redis:
  ports:
    - "6379:6379"    # exposed to the public
```

**Impact:** If the server has a public IP, the databases are exposed externally. This is a common source of security incidents.

**Suggestion:** Remove the `ports` mapping, or at least bind `127.0.0.1:3306:3306`. Docker's internal network already provides connectivity.

---

#### C3. Missing LICENSE File

**Issue:** The README declares "Lite Edition — MIT License", but there is no `LICENSE` file in the project root.

**Impact:** The legal requirement of open source is missing. GitHub cannot recognize the project's license type.

**Suggestion:** Create a `LICENSE` file in the root with the standard MIT License text.

---

### HIGH

#### H1. Duplicate SQL Files Cause Confusion

**Issue:** The project contains 3 SQL DDL files:

| File | Lines | Tables | Status |
|------|------|------|------|
| `install.sql` (root) | 739 | 46 | **currently used** |
| `admin/install.sql` | 152 | 7 (wa_* only) | legacy, not deleted |
| `docs/database.sql` | 629 | 39 (erik_* only) | legacy, not deleted |

**Impact:** Maintainers may edit the wrong file, causing drift.

**Suggestion:** Delete `admin/install.sql` and `docs/database.sql`, or add a prominent deprecation notice at the top pointing to `install.sql`.

---

#### H2. Installation Wizard Does Not Create the Audit Database

**Issue:** `install/index.php` includes audit database config when generating `service/.env`:
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
But the wizard never creates this database. If the app tries to write audit logs after startup, it fails with `Unknown database`.

**Impact:** Audit logging is unusable, compliance is affected.

**Suggestion:** In step 4 of the installation execution, add `CREATE DATABASE IF NOT EXISTS cloud_platform_audit`.

---

#### H3. Docker Missing the Elasticsearch Service

**Issue:** docker-compose.yml only has app + mysql + redis. The README tech stack explicitly lists Elasticsearch 8.x as a required component.

**Impact:** Full-text search (products, users, orders, tickets) is completely unavailable in Docker deployments.

**Suggestion:** Add an Elasticsearch service to docker-compose.yml.

---

#### H4. Dockerfile Missing PHP Extensions

**Issue:** The PHP extensions installed by the Dockerfile are: `gd pdo_mysql zip bcmath redis`. But the environment check requires 9 extensions; missing:
- `intl` (PHP internationalization)
- `xml` (XML parsing)
- `fileinfo` (file type detection)

**Impact:** Some features may fail silently in Docker environments.

**Suggestion:** Add the missing extensions: `docker-php-ext-install intl xml fileinfo`

---

### MEDIUM

#### M1. admin/.env.example Config Items Not Detailed Enough

**Issue:** service/.env.example (146 lines) vs admin/.env.example (64 lines); the latter has notably fewer comments and config items.

**Suggestion:** Supplement the comments in admin/.env.example, at least marking which fields must match the service side.

---

#### M2. Hardcoded HASHIDS_SALT in .env.example

**Issue:** Both `.env.example` files contain:
```ini
HASHIDS_SALT=cloud-platform-hashids
```
If ops runs `cp .env.example .env` without changing this value, all instances share the same salt.

**Suggestion:** Use a placeholder in `.env.example` and emphasize in comments that "a unique random value must be generated".

---

#### M3. Installation Wizard Success Page Has Invalid Links

**Issue:** Links on the install-complete page use `href="#"`, with no actually clickable URL.

**Suggestion:** At least display the specific URL/port information, with the startup command.

---

#### M4. Docker Does Not Include the Installation Wizard

**Issue:** The Dockerfile does not copy `install.php` or the `install/` directory. Docker users cannot use the one-click wizard.

**Suggestion:** Clearly document that Docker deployment requires manual configuration, or integrate the wizard into the image.

---

#### M5. Docker Compose Environment Variables Incomplete

**Issue:** The `environment` block in docker-compose.yml lacks several required configs: JWT keys, Hashids salt, encryption keys, SMTP, Stripe etc.

**Suggestion:** Complete the environment variable list, or reference the `.env` file.

---

### LOW

#### L1. Weak Docker Section in Documentation

The Docker deployment section in the README is only a few lines, with no explanation of how to configure environment variables, initialize the database, or access the admin panel.

**Suggestion:** Add complete Docker deployment documentation.

---

#### L2. Missing .editorconfig

**Issue:** The project has no `.editorconfig` file. For multi-contributor projects, consistent indentation and line-ending settings matter.

**Suggestion:** Add a standard `.editorconfig` with 4-space indentation for PHP, UTF-8, LF line endings.

---

#### L3. Hardcoded Defaults in Code Could Be Centralized

**Issue:** `install/index.php` has multiple hardcoded defaults (database host, port, database name, admin username), easy to miss when changing.

**Suggestion:** Extract them into constants at the top of the file.

---

## III. Ecosystem Configuration Completeness Assessment

### .env Variable Coverage

| Config domain | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| Database connection | ✓ | ✓ | ✓ |
| Audit database | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| JWT authentication | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| Transport encryption (AES-256-GCM) | ✓ | ✓ | ✓ |
| Field encryption (AES-128-ECB) | ✓ | ✓ | ✓ |
| SMTP email | ✓ | N/A | ✓ |
| Stripe payments | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| Twilio SMS | ✓ | N/A | ✓ |
| Firebase push | ✓ | N/A | ✓ |
| Click CAPTCHA | ✓ | N/A | ✓ |
| Sentry monitoring | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| Key rotation | ✓ | N/A | ✓ |
| **Assessment** | **Complete** | **Complete** | **Complete** |

### Shared Key Consistency Generated by the Wizard

| Key | service | admin | Consistent |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **Assessment** | **Passed** | **Passed** | **Passed** |

---

## IV. Security Assessment

| Check | Status | Description |
|--------|:--:|------|
| CSRF protection | ✓ | Token generation + hash_equals validation |
| Session security | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| Input validation | ✓ | DB name regex validation, port range check |
| Password strength | ✓ | Min 8 chars + letter + number/special char |
| Password hashing | ✓ | password_hash(PASSWORD_DEFAULT) |
| Key generation | ✓ | openssl rand or random_bytes |
| SQL injection protection | ✓ | PDO prepared statements |
| Error redaction | ✓ | Detailed errors only written to error_log, users see generic messages |
| XSS protection | ✓ | htmlspecialchars() output escaping |
| Reinstall protection | ✓ | Detects existing tables + .env files |
| Step enforcement | ✓ | session max_step prevents skipping steps |
| Transaction wrapping | ✓ | beginTransaction/commit/rollBack |
| Docker port exposure | ✗ | MySQL:3306 / Redis:6379 mapped to host |
| Audit database creation | ✗ | Wizard does not create the _audit database |
| **Overall score** | **A-** | Core security measures solid, Docker config needs improvement |

---

## V. SQL Completeness

| Check | Result |
|--------|------|
| Total tables | 46 (7 wa_* + 39 erik_*) ✓ |
| Engine | All InnoDB ✓ |
| Charset | All utf8mb4 ✓ |
| Primary key type | BIGINT UNSIGNED (non-auto-increment) ✓ |
| CREATE IF NOT EXISTS | Used everywhere ✓ |
| Destructive statements | None (no DROP TABLE) ✓ |
| Legacy SQL files | 2 legacy files still exist, need cleanup ⚠ |

---

## VI. Test Coverage Assessment

| Test suite | Framework | Tests | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**Assessment:** Test count is adequate (362 tests), CI/CD covers dual PHP version syntax checks + dual-side unit tests.

---

## VII. Documentation Completeness

| Document | Content | Status |
|------|------|:--:|
| README.md | Project overview, architecture, quick start, API overview | ✓ |
| README_EN.md | English README | ✓ |
| docs/architecture.md | System architecture design | ✓ |
| docs/features.md | 12-module feature design | ✓ |
| docs/api-reference.md | 135+ endpoint reference | ✓ |
| docs/admin-design.md | Admin panel design | ✓ |
| docs/supplier-api.md | Supplier API | ✓ |
| docs/deployment.md | Deployment checklist | ✓ |
| docs/editions.md | Edition comparison | ✓ |
| docs/diagrams/ (11 SVG) | Architecture/security/business flow | ✓ |
| LICENSE file | **missing** | ✗ |

---

## VIII. Fix Recommendation Summary

### First Priority (fix before next release)

| # | Issue | Level |
|---|------|:--:|
| 1 | Create LICENSE file (MIT) | CRITICAL |
| 2 | Delete legacy SQL files (admin/install.sql, docs/database.sql) | HIGH |
| 3 | Do not expose Docker MySQL/Redis ports to host | CRITICAL |
| 4 | Wizard creates audit database `_audit` | HIGH |

### Second Priority (fix soon)

| # | Issue | Level |
|---|------|:--:|
| 5 | Docker support for the admin panel | CRITICAL |
| 6 | Add Elasticsearch service to Docker Compose | HIGH |
| 7 | Dockerfile add PHP extensions (intl, xml, fileinfo) | HIGH |
| 8 | .env.example HASHIDS_SALT uses placeholder | MEDIUM |

### Third Priority (continuous improvement)

| # | Issue | Level |
|---|------|:--:|
| 9 | Improve Docker deployment docs | LOW |
| 10 | Add .editorconfig | LOW |
| 11 | Clean up hardcoded defaults in code | LOW |
| 12 | Unify config items of the .env generation functions | LOW |

---

## IX. Conclusion

The project's overall quality is good; after the previous round of audit, all security issues in the core installation wizard have been fixed. Code organization is clear, modularity is high, documentation is complete. The main issues concentrate on **incomplete Docker deployment configuration** — missing admin panel, search service, PHP extensions, plus the security risk of exposed database ports.

**Overall rating: B+** — features complete, security core in place, Docker ecosystem configuration needs completion.
