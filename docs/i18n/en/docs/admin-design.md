# Admin Panel Design Document

## Overview

`admin/` is a standalone webman v2.1 instance providing a Layui-based management dashboard. It runs independently from the `service/` backend, sharing only the MySQL database and the 7 erikwang2013 packages.

## Architecture

```
┌─────────────────────────────────────────────────┐
│                  Admin Panel                     │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │ Controller│  │  Model   │  │   Bootstrap   │ │
│  │ (Layui)  │  │(Eloquent)│  │(worker start) │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │             │               │          │
│  ┌────┴─────────────┴───────────────┴─────────┐ │
│  │         7 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season │ Poster     │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

### Module Dependency Map

![Module Dependency Map](diagrams/module-dependency.svg)

## Directory Layout

```
admin/
├── app/
│   ├── bootstrap/       # Per-process startup
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 54 controller files (Base/Crud + per-entity CRUD)
│   │   ├── Base.php     # json() with hashids_encode_ids
│   │   ├── Crud.php     # Select/Insert/Update/Delete/Export with hashids decode
│   │   ├── DashboardController.php  # Dashboard data API (user stats + trends)
│   │   ├── AccountController.php    # Login/logout/profile/password
│   │   ├── AdminController.php      # Admin CRUD + roles
│   │   ├── RoleController.php       # Role CRUD + rule tree
│   │   └── ...
│   ├── model/            # 44 Eloquent models (36 map to service's prefix-free business tables + alerts (defined in install.sql) + 7 wa_* admin tables)
│   │   ├── Base.php     # Snowflake PK + Encryptable support
│   │   ├── Admin.php    # Encryptable: password, email, mobile
│   │   ├── User.php     # Encryptable: 6 fields + Searchable trait
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # WafMiddleware + AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # Public API (plugin\admin\api)
│   └── Auth.php          # canAccess() ACL
├── config/
│   ├── plugin/erikwang2013/  # 7 plugin configs
│   ├── hashids.php       # Hashids connections (main + alternative)
│   └── encryption.php    # Encryption config (master key, cipher)
├── tests/                # PHPUnit 11 test suite (286 tests, 962 assertions)
│   ├── HashidsTest.php   # 21 tests
│   ├── BaseJsonTest.php  # 13 tests
│   ├── CrudHashidsTest.php # 14 tests
│   ├── TreeTest.php      # 19 tests
│   ├── AccessControlMiddlewareTest.php # 7 tests (401/403/pass-through)
│   ├── AdminControllersTest.php        # 48 controller reflection regression
│   ├── UtilTest.php      # 17 tests
│   ├── DictTest.php      # 5 tests
│   ├── ExcelExportTest.php # 4 tests
│   ├── LayuiTest.php     # 5 tests
│   └── support/          # RequestMock, TestableCrud
├── install.sql           # DDL (bigint unsigned PKs, no auto-increment)
└── phpunit.xml
```

## Package Integration Details

### 1. Snowflake (Distributed Primary Keys)

**Config**: `config/plugin/erikwang2013/snowflake-php/app.php`
**Bootstrap**: `app/bootstrap/SnowflakeBootstrap.php`

```php
// Model Base::boot() — creating event
static::creating(function (Model $model) {
    if (empty($model->getKey())) {
        $snowflake = Container::instance()->get(Snowflake::class);
        $model->setAttribute($model->getKeyName(), $snowflake->id());
    }
});
```

- 64-bit IDs: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`
- Epoch: 2024-01-01 (max lifespan ~69 years)
- `$incrementing = false`, `$keyType = 'int'` on Base model
- All PK and FK columns: `bigint unsigned NOT NULL`

### 2. Hashids (ID Obfuscation)

**Config**: `config/hashids.php` + `config/plugin/erikwang2013/hashids/app.php`

**Encode path** (response):
- `Base::json()` calls `hashids_encode_ids($data)` recursively
- Fields named `id`, `*_id`, `*_ids` with positive integers → hashid strings
- `Crud::formatNormal()` also applies encoding (fixed in code review)

**Decode path** (request):
- `Crud::selectInput()`: decodes `id`/`*_id` hashid strings in WHERE clause
- `Crud::updateInput()`: decodes primary key from `$request->post()`
- `Crud::deleteInput()`: decodes array of PKs from `$request->post()`
- `AdminController::update()`: uses `updateInput()` return value directly (deduped)
- `RoleController::select()`/`rules()`: decode `$request->get('id')`

**Helper functions** (in `app/functions.php`):
- `hashids_encode(int $id): string`
- `hashids_decode(string $hash): int` — returns 0 on failure
- `hashids_encode_ids(array $data): array` — recursive, handles `is_numeric()` strings

### 3. Encryptable (Database Field Encryption)

**Config**: `config/plugin/erikwang2013/encryptable/app.php`
**Bootstrap**: `app/bootstrap/EncryptableBootstrap.php`

Uses Eloquent `CastsAttributes` interface:
- `get()`: AES decrypts value on read from DB
- `set()`: AES encrypts value on write to DB

**Encrypted fields**:
| Model | Fields |
|-------|--------|
| Admin | password, email, mobile |
| User | password, email, mobile, token, last_ip, join_ip |

**Critical rule**: Always use model instance `save()`, never Query Builder `update()`. Using `Admin::where(...)->update(...)` bypasses Eloquent casts and stores raw values. This was fixed in `AccountController` during code review.

**Password layering**: Passwords are bcrypt-hashed first (in `insertInput`/`updateInput`), then the hash is AES-encrypted by Encryptable cast on `save()`. On read: AES decrypt → bcrypt hash → `password_verify()`.

### 4. Encryption (API Transport)

**Config**: `config/encryption.php`
**Bootstrap**: `app/bootstrap/EncryptionBootstrap.php`

Reserved for API-level request/response encryption (AES-256-GCM). Provides:
- `encrypt_data(string $plaintext): string`
- `decrypt_data(string $ciphertext): string`

Throws `RuntimeException` with clear message if `ENCRYPTION_MASTER_KEY` not configured.

### 5. Webman-Scout (Elasticsearch)

**Config**: `config/plugin/erikwang2013/webman-scout/app.php` + `command.php`

User model uses `Searchable` trait:
```php
class User extends Base
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
```

### 6. Season (Country Flags)

**Config**: `config/plugin/erikwang2013/season/app.php`

Global helper: `country_season_flag(string $code): string`
- `country_season_flag('CN')` → 🇨🇳
- `country_season_flag('US')` → 🇺🇸

Also provides localized season names via `CountrySeason` class.

### 7. Poster-PHP (Click CAPTCHA)

**Config**: `config/poster.php` + `config/plugin/erikwang2013/poster/app.php`
**Bootstrap**: `config/plugin/erikwang2013/poster/bootstrap.php` → `CaptchaPlugin`

Provides click-based CAPTCHA verification for login and registration:

```
Client                         Server
──────                         ──────
POST /api/captcha/create
  Header: X-Api-Version: v1
  → CaptchaService::create()
    → captcha_create('click')
      → ClickCaptcha::generate()
        → GD renders image with n randomly-placed Chinese words
        → Stores targets + key in Redis/File storage
      ← {key, image (base64), target_count, expires_in}

POST /api/auth/login
  Header: X-Api-Version: v1
  (with captcha_key + captcha_points)
  → AuthController::verifyCaptcha()
    → CaptchaService::verify(key, [[x1,y1], [x2,y2], ...])
      → captcha_verify(key, 'click', points)
        → CaptchaManager checks Euclidean distance ≤ 18px tolerance
      ← true/false
```

**Security features**:
- One-time keys: deleted after successful verification
- Brute-force protection: max 3 failed attempts per key, then deleted
- 300-second TTL (configurable via `CAPTCHA_TTL`)
- Click tolerance: 18px radius (configurable)
- Difficulty levels: easy (2 targets), medium (3), hard (4)
- Storage: auto-detect Redis → file fallback, configurable via `CAPTCHA_STORAGE`

**Wrapper**: `Common\Captcha\CaptchaService` loads custom config from `config/poster.php`, provides `create()` (strips targets from response for security) and `verify()` methods. Used by `AuthController::register()` and `AuthController::login()`.

### 8. ConfirmationMiddleware (Password Re-verification)

**Config**: Route group middleware in `config/route.php`

Protects destructive and sensitive operations by requiring the user to re-enter their password. Applied as a middleware on 12 sensitive route endpoints:

```
Client                              Server
──────                              ──────
POST /api/orders/{id}/pay
  Header: X-Api-Version: v1
  (with confirm_password field)
    → ConfirmationMiddleware::process()
      → Checks userId present (401 if missing)
      → Checks Redis lock key (429 if locked out)
      → Validates password non-empty (422 if missing)
      → User::find() + Hash::check() verifies bcrypt
      → On failure:
        → Redis INCR confirm_failed:{userId} counter
        → If count ≥ 5, SETEX confirm_lock:{userId} for 900s
        → AuditLogger::record('confirm_failed', ...)
        → Returns 403
      → On success:
        → DEL confirm_failed:{userId} counter
        → AuditLogger::record('confirm_success', ...)
        → Calls $next($request)
```

**Sensitive user endpoints** (Auth + Confirmation):
| Method | Path | Operation |
|--------|------|-----------|
| POST | `/api/orders/{id}/pay` | Initiate payment |
| POST | `/api/supplier/withdraw` | Request withdrawal |
| DELETE | `/api/dns/{domain}/records/{id}` | Delete DNS record |

**Sensitive admin endpoints** (Auth + AdminRole + Confirmation):
| Method | Path | Operation |
|--------|------|-----------|
| DELETE | `/admin/api/products/{id}` | Delete product |
| POST | `/admin/api/orders/{id}/refund` | Refund order |
| POST | `/admin/api/provisioning/resources/{id}/destroy` | Destroy resource |
| POST | `/admin/api/kyc/{id}/approve` | Approve KYC |
| POST | `/admin/api/kyc/{id}/reject` | Reject KYC |
| POST | `/admin/api/suppliers/{id}/approve` | Approve supplier |
| POST | `/admin/api/suppliers/{id}/settle` | Generate settlement |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Approve withdrawal |
| PUT | `/admin/api/system/config` | Update system config |

API version is carried in the `X-Api-Version` header (default: `v1`), not in the URL path.

**Security features**:
- bcrypt password verification via `Hash::check()`
- Rate limiting: 5 failed attempts triggers 15-minute lock (900s TTL)
- Lock applies per-user via Redis keys (`confirm_lock:{userId}`, `confirm_failed:{userId}`)
- Success resets failure counter
- All attempts logged to audit database (success, failed, locked)
- `verifyPassword()` is a protected method, enabling testability via anonymous subclass override

**Testability**: `ConfirmationMiddlewareTest` (11 tests) uses an anonymous subclass that overrides `verifyPassword()` to return a fixed boolean, avoiding Eloquent/DB dependency. Tests cover: 401 unauthenticated, 422 missing/empty password, 403 wrong password, success pass-through, rate limit key format, lock key format, and max failure threshold boundary (4→no lock, 5→locked, 6→locked).

## ACL System

### Controller-level

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // Skip login
protected $noNeedAuth = ['select'];                         // Skip auth
```

Checked by `api/Auth::canAccess()` via `ReflectionClass`.

**AccessControlMiddleware response** (`middleware/AccessControl.php`):
- Not logged in (outside `noNeedLogin`) → **HTTP 401**, body is a script redirecting to the login page
- Logged in but insufficient permissions → **HTTP 403** error page (status 403, no longer 500)
- On the allow list (login page/captcha, etc.) → passed through normally

### Role-based

- Roles have `rules` (comma-separated rule IDs or `*` for super admin)
- Rules stored in `wa_rules` as `{Controller}@{action}` keys
- `api/Auth::canAccess()` resolves `$controller@$action` key against role's rules
- Super admin (`rules = '*'`) bypasses all checks

### Data limits

```php
protected $dataLimit = null;     // No limit
protected $dataLimit = 'auth';   // Admin sees own + descendants' data
protected $dataLimit = 'personal'; // Admin sees only own data
protected $dataLimitField = 'admin_id';
```

## Code Review Findings (Fixed)

During review of the initial commit, the following were found and fixed:

### Critical
1. **AccountController bypassing Encryptable**: `password()` and `update()` used `Admin::where()->update()` which bypasses Eloquent casts → stored raw values in encrypted columns. Fixed by using `Admin::find()->save()`.
2. **Crud::formatNormal() not encoding IDs**: Called global `json()` instead of applying `hashids_encode_ids()`. Fixed.

### Important
3. **hashids_encode_ids strict `is_int`**: Large bigint values from PDO arrive as PHP strings. Changed to `is_numeric()` with whole-number check.
4. **AdminController duplicate ID decode**: `update()` decoded the same PK twice. Deduped, fixed loop variable shadowing in `insert()`.
5. **Dead password code in AccountController::update()**: Password field not in allow list. Removed.
6. **Hardcoded MySQL driver**: Changed to `config('database.default')`.

## Excel Export

### Architecture

Excel export uses PhpSpreadsheet ^2.0 to generate .xlsx files server-side. The admin panel has two separate export paths because there are two CRUD mechanisms:

```
Export request (with current table filters)
  ├── Crud-based controllers (User, Admin, Role, etc.)
  │     → Crud::export()
  │       → selectInput() reuses query parsing (hashids decode, WHERE, ORDER)
  │       → doSelect() builds Eloquent query
  │       → 10,000 row cap
  │       → hashids_encode_ids() applied to result data
  │       → ExcelExport::export() generates .xlsx
  │
  └── TableController (generic tables like wa_dict, wa_rules)
        → TableController::export()
          → Builds query from table schema + request params
          → hashids_encode_ids() applied
          → ExcelExport::export() generates .xlsx
```

### ExcelExport Utility (`app/common/ExcelExport.php`)

Fluent wrapper around PhpSpreadsheet:

- `setColumns(array $columns)` — define column order
- `setLabels(array $labels)` — set human-readable column headers
- `addRow(array $row)` / `addRows(array $rows)` — populate data
- `save(string $title): string` — write .xlsx to `runtime/exports/`, return file path
- Static helper: `ExcelExport::export($title, $columns, $data, $labels)` — one-shot export
- Auto-sizes columns via `Worksheet::getColumnDimension()`

### Crud::export()

```php
public function export(Request $request): Response
{
    [$where, $format, $limit, $field, $order] = $this->selectInput($request);
    $query = $this->doSelect($where, $field, $order);
    $maxRows = 10000;
    $total = min($query->count(), $maxRows);
    $items = $query->limit($maxRows)->get();
    if (method_exists($this, 'afterQuery')) {
        $items = call_user_func([$this, 'afterQuery'], $items);
    }
    $data = array_map(fn($item) => ...toArray(), $items->toArray());
    $data = hashids_encode_ids($data);
    // Derive column labels from table schema comments
    $path = ExcelExport::export($table, $columns, $data, $labels);
    return response()->download($path, $table . '_' . date('YmdHis') . '.xlsx');
}
```

All Crud-based controllers (Admin, User, Role, etc.) inherit `export()` automatically.

### Frontend Wiring

- Layui's built-in `"exports"` toolbar item (client-side CSV) is replaced with a custom `{title: "导出", layEvent: "export"}` button
- The `export` event handler calls `window.exportExcel()` which collects current table filter params and opens the download URL
- `Layui::buildTable()` generates the toolbar with the custom export button for all CRUD pages

### Service Admin API Export

The service backend (`service/`) also has Excel export via its own `Common\ExcelExport` wrapper:

| Endpoint | Controller | Exported Data |
|----------|-----------|---------------|
| `GET /admin/api/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

All API endpoints require `X-Api-Version` header (default: `v1`).

Export routes are placed BEFORE `/{id}` parameter routes to avoid conflicts.

## Service Admin API — Extended Features

### Admin API Endpoints (Service Layer)

All admin REST endpoints are prefixed with `/admin/api` and require `AdminRoleMiddleware`.

| Group | Endpoints | Controller |
|-------|-----------|------------|
| Dashboard | `GET /dashboard`, `/kyc`, `POST /kyc/{id}/approve`, `/reject` | `Admin\DashboardController` |
| Users | `GET /users`, `/users/export`, `/users/{id}`, `PUT /users/{id}/status` | `Admin\UserController` |
| Products | `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}`, `POST /products/{id}/skus`, `PUT /skus/{id}`, `POST /skus/{id}/region-price` | `Admin\ProductController` |
| Product Import/Export | `GET /products/export` (CSV), `POST /products/import` (CSV upsert) | `Admin\ImportExportController` |
| Orders | `GET /orders`, `/orders/export`, `/orders/{id}`, `POST /orders/{id}/refund` | `Admin\OrderController` |
| Invoices | `GET /invoices`, `POST /invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Payments | `GET /payments/channels`, `PUT /payments/channels/{id}`, `GET /payments/transactions`, `/reconcile` | `Admin\PaymentController` |
| Provisioning | `GET /provisioning/tasks`, `POST /tasks/{id}/retry`, `POST /resources/{id}/upgrade`, `/destroy`, `GET /hosts` | `Provisioning\TaskController` |
| Provider APIs | `GET /providers`, `POST /providers`, `PUT /providers/{id}`, `DELETE /providers/{id}` | `Admin\ProviderApiController` |
| CDN | `GET /cdn/domains`, `PUT /cdn/domains/{id}` | `Admin\CdnController` |
| Suppliers | `GET /suppliers`, `/suppliers/export`, `POST /suppliers/{id}/approve`, `/settle`, `/withdraws/{id}/approve` | `Admin\SupplierController` |
| Supplier API Keys | `GET /suppliers/{id}/api-keys`, `POST /suppliers/{id}/api-keys`, `DELETE /suppliers/api-keys/{id}` | `Admin\SupplierController` |
| Tickets | `GET /tickets`, `POST /tickets/{id}/assign`, `/close` | `Ticket\TicketController` |
| Coupons | `GET /coupons`, `POST /coupons`, `DELETE /coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET /webhooks`, `POST /webhooks`, `DELETE /webhooks`, `POST /webhooks/test` | `Admin\WebhookController` |
| Domains | `GET /domains/tlds`, `POST /domains/tlds`, `PUT /domains/tlds/{id}`, `DELETE /domains/tlds/{id}`, `GET /domains/zones`, `/transfers`, `POST /transfers/{id}/approve` | `Admin\DomainController` |
| Notifications | `GET /notifications/templates`, `PUT /notifications/templates/{id}`, `GET /notifications/log` | `Admin\NotificationController` |
| Help Articles | `GET /help`, `POST /help`, `PUT /help/{id}`, `DELETE /help/{id}` | `Admin\HelpController` |
| Reports | `GET /reports/revenue`, `/supplier`, `/region` | `Report\ReportController` |
| Monitoring | `GET /monitor/dashboard`, `/resources/{id}` | `Monitor\MonitorController` |
| Audit | `GET /audit-logs` | `Admin\SystemController` |
| System Config | `PUT /system/config` | `Admin\SystemController` |

### Report Center (v3.1.0)

Panel-side report center (`admin/app/controller/ReportController.php` + `admin/app/view/report/index.html`), routes auto-registered as `/app/admin/report/{action}`; all endpoints validate `start_date` / `end_date` (YYYY-MM-DD, default last 30 days), amounts aggregated with bcmath 4dp:

| Action | Description | Extra params | Returned `data` |
|--------|-------------|--------------|-----------------|
| `GET /app/admin/report/index` | Report page (Layui view) | — | — |
| `GET /app/admin/report/order` | Order daily report (grouped by `paid_at`, excludes refunded) | — | `{range, totals: {orders, revenue}, daily: [{date, currency, orders, revenue}]}` |
| `GET /app/admin/report/product_top` | Product ranking by sales volume (top N) | `limit` (1-50, default 10) | `{range, items: [{product_id, qty, amount, name}]}` |
| `GET /app/admin/report/payment` | Payment channel statistics (successful transactions by channel + currency) | — | `{range, items: [{channel, currency, transactions, amount}]}` |
| `GET /app/admin/report/user_growth` | User growth (daily registrations, soft-deleted excluded) | — | `{range, items: [{date, count}]}` |
| `GET /app/admin/report/export` | Excel export via `ExcelExport` (type whitelist) | `type` ∈ order / product / payment / user, `limit` | Downloads `{title}_{YYYYmmddHHMMSS}.xlsx` |

Amounts are aggregated with bcmath (`SUM(DECIMAL)` + PDO string returns) to avoid floating-point errors.

Dashboard statistics are served by `Admin\DashboardController::index()` (today's orders / revenue / new users / active resources, 30-day revenue trend, region distribution, pending orders / KYC / tickets) for the home page stat cards and ECharts charts.

### CDN Resource Management

The CDN product supports four providers (Cloudflare / CloudFront / Aliyun / Tencent), managed from the admin panel in two parts:

**Provider account configuration** (reuses the ProviderApi model, `Admin\ProviderApiController`):

- `GET/POST /admin/api/providers`, `PUT/DELETE /admin/api/providers/{id}`, guarded by `RbacMiddleware('provider.config')`
- `code` convention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`; credential fields stored encrypted with Encryptable, non-sensitive metadata in the `config` JSON column
- User-side credential resolution priority: bound account → active account matching `code` → env fallback; delete/purge use strict snapshot (bound account only, missing/disabled returns 4003)

**CDN domain management** (`Admin\CdnController`):

```
GET /admin/api/cdn/domains        → all domains (incl. owning user_id), guarded by RbacMiddleware('cdn.manage')
PUT /admin/api/cdn/domains/{id}   → update plan, whitelist standard | pro | enterprise,
                                    invalid value returns 400; changes written to audit log admin_cdn_update_plan
```

### Dashboard Data (Service Layer)

`Admin\DashboardController::index()` provides real operational metrics:

```php
[
    'today_stats' => [todayOrders, todayRevenue, newUsers, activeResources],
    'revenue_trend_30d' => [...],   // Daily revenue for last 30 days
    'region_distribution' => [...],  // Active resources grouped by region
    'pending_orders' => ...,         // Orders awaiting payment
    'pending_kyc' => ...,            // KYC submissions awaiting review
    'open_tickets' => ...,           // Open or in-progress tickets
]
```

### Admin Panel Dashboard View (`app/view/index/dashboard.html`)

- **8 animated stat cards**: today/week/month/total users + today orders + today revenue + pending orders + active resources — each with count-up animation via Layui `count` module
- **3 ECharts charts**:
  1. 7-day user registration trend — area line chart
  2. 30-day user registration trend — bar chart
  3. User summary — donut/pie chart (today / week / month)
- **System info table**: dynamically populated with PHP/Workerman/Webman/Admin/MySQL/OS versions
- **Toolbar**: PDF export and refresh buttons
- All data fetched via AJAX from `/app/admin/dashboard/data`

### Route

```
Route::any('/app/admin/dashboard/data', [DashboardController::class, 'index']);
```

Besides explicitly registered routes, `admin/config/route.php` automatically registers `/app/admin/{snake_case_controller}/{action}` routes for every public method of each controller under `app/controller/` (e.g. `/app/admin/order_item/index`), with URLs matching the snake_case controller names used by the menu; `/app/admin` and `/app/admin/index` are the admin homepage/login page entries (renders the login view when not logged in); unmatched requests uniformly return 404.

## PDF Export

Client-side PDF generation on the dashboard page:

- Uses **html2canvas 1.4.1** (CDN) to capture the dashboard DOM as a canvas
- Uses **jsPDF 2.5.1** (CDN) to create a downloadable A4 PDF
- Captures stat cards and ECharts charts (rendered as canvas elements)
- Includes title, timestamp, and branding in the PDF
- Triggered by the "Export PDF" button in the dashboard toolbar

```
Dashboard DOM → html2canvas screenshot → jsPDF document → browser download
```

### Implementation

```javascript
// In dashboard.html
html2canvas(document.querySelector('.layui-body'), {scale: 2}).then(canvas => {
    const pdf = new jsPDF('p', 'mm', 'a4');
    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 10, 10, 190, 0);
    pdf.save('dashboard_' + new Date().toISOString().slice(0, 10) + '.pdf');
});
```

## Test Suite

```
PHPUnit 11.5 | 67 tests | 124 assertions
```

### HashidsTest (21 tests)
- encode/decode roundtrip (0 to PHP_INT_MAX)
- Deterministic encoding
- Invalid/empty string handling
- `hashids_encode_ids` field patterns (`id`, `*_id`, `*_ids`)
- Zero/negative skip, numeric string support
- Nested array recursion, non-id field preservation

### BaseJsonTest (13 tests)
- `json()`/`success()`/`fail()` apply hashids encoding
- Nested object encoding
- Snowflake-size ID handling
- Non-id field preservation
- Zero handling
- Response structure verification

### CrudHashidsTest (14 tests)
- `selectInput`: hashid decode in `id`/`*_id` WHERE fields
- `selectInput`: numeric string/raw int pass-through
- `updateInput`: hashid PK decode
- `updateInput`: numeric string PK cast to int
- `deleteInput`: batch ID decode, mixed types
- `deleteInput`: empty array, single ID handling

## Database Migration System

### Architecture

Both `service/` and `admin/` instances have independent migration systems built on `illuminate/database` Schema Builder. Each instance registers Symfony Console commands via `config/command.php` that are discoverable by webman's console runner.

```
php webman migrate          # Run pending migrations
php webman migrate:rollback # Rollback last batch
php webman migrate:status   # Show migration status
```

### MigrationRunner (`service/support/MigrationRunner.php`, `admin/app/common/MigrationRunner.php`)

Core engine shared by both instances:

- **`ensureTable()`** — Creates the `migrations` tracking table (id, migration name, batch number) on first run
- **`migrate()`** — Scans migration files from `database/migrations/`, runs pending `up()` methods, records batch
- **`rollback()`** — Reverses the last batch by calling `down()` on each migration in reverse order
- **`status()`** — Lists all migrations with their batch numbers
- **`resolve()`** — Instantiates migration classes from files

### Migration Base Class (`service/support/Migration.php`, `admin/app/common/Migration.php`)

```php
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
```

Each migration file returns a class extending `Migration` with timestamp-prefixed filenames (e.g., `2024_01_01_000001_create_initial_schema.php`).

### Service Migrations

**Directory**: `service/database/migrations/` — 38 migration files (prefix-free table names, mapped directly by admin models)

| Migration | Tables |
|-----------|--------|
| `0001_create_users_tables` | users, user_profiles, user_kyc, user_balance, user_balance_log, user_addresses, refresh_tokens |
| `0002_create_product_tables` | product_categories, regions, products, product_skus, product_regions, product_images, product_attributes, product_reviews |
| `0003_create_order_tables` | carts, orders, order_items, order_timeline, order_invoices, refunds |
| `0004_create_payment_tables` | payment_channels, payment_transactions, payment_reconcile |
| `0005_create_provisioning_tables` | resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis |
| `0006_create_host_tables` | host_machines, ip_pools, ip_allocations, disks, disk_resizes |
| `0007_create_supplier_tables` | suppliers, supplier_products, supplier_settlements, supplier_withdraws |
| `0008_create_domain_tables` | domain_tlds, domain_transfers, dns_zones, dns_records |
| `0009_create_ticket_notification_tables` | tickets, ticket_messages, notifications, notification_templates |
| `0010_create_audit_table` | audit_logs |
| `0011_create_kvm_service_tables` | network_services, firewall_services, switch_services |
| `2024_01_01_000001_create_initial_schema` | Runs `docs/database.sql` via `Capsule::unprepared()`, drops all in `down()` |
| `2025_05_16_000002_add_fcm_token_to_users` | Adds `fcm_token`, `fcm_platform` columns + index to users |
| `2026_08_26_000003_widen_encrypted_columns` | users.phone / user_addresses.phone / suppliers.contact_phone VARCHAR(20)→VARCHAR(255) (Encryptable ciphertext length) |

### Admin Migrations

**Directory**: `admin/database/migrations/` — 1 migration file

| Migration | Description |
|-----------|-------------|
| `2024_01_01_000001_create_admin_schema` | Runs `admin/install.sql` via `Capsule::unprepared()` — creates wa_* tables with seed data |

### Console Command Registration

**`service/config/command.php`**:
```php
return [
    \App\Command\MigrateCommand::class,
    \App\Command\MigrateRollbackCommand::class,
    \App\Command\MigrateStatusCommand::class,
];
```

**`admin/config/command.php`** — same pattern under `app\command` namespace.

## Stripe Production Integration

### Architecture

Replaced fake `random_bytes()` payment IDs with real Stripe API integration via `stripe/stripe-php` ^15.0.

**File**: `service/app/payment/service/channels/StripeChannel.php`

```
Client-side                    Server-side                    Stripe API
───────────                    ───────────                    ──────────
Select Stripe at checkout
  → POST /orders/{id}/pay
    → StripeChannel::createPaymentIntent()
      → StripeClient->paymentIntents->create(amount, currency)
        ← {id, client_secret}
      → Save pi_xxx as transaction_no
      ← Return client_secret
  → Stripe.js confirmCardPayment(client_secret)
    ← Payment confirmed by Stripe
      → POST /payments/webhook/stripe
        → StripeChannel::handleWebhook()
          → Webhook::constructEvent(payload, signature, secret)
          → Verify idempotency (skip non-pending transactions)
          → Update order status, create transaction record
```

### PaymentIntent Creation

```php
public function createPaymentIntent(Order $order): array
{
    $intent = $this->stripe()->paymentIntents->create([
        'amount'   => (int) round($order->total * 100),  // cents
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id, 'order_no' => $order->order_no],
    ]);
    return [
        'transaction_no' => $intent->id,          // pi_xxxxxxxxxxxxx
        'client_secret'  => $intent->client_secret, // pi_xxx_secret_yyy
    ];
}
```

- `$this->stripe()` lazy-initializes `\Stripe\StripeClient` with `STRIPE_SECRET_KEY` from env
- Falls back to `$this->channel->api_key_encrypted` (decrypted via Encryptable) if env var not set
- Amount converted to cents: `(int) round($order->total * 100)`

### Webhook Signature Verification

```php
public function handleWebhook(string $payload, string $signature): void
{
    $event = \Stripe\Webhook::constructEvent(
        $payload, $signature, $this->channel->webhook_secret_encrypted
    );
    // Idempotency: skip if transaction already processed
    $existing = Transaction::where('transaction_no', $event->id)->first();
    if ($existing && $existing->status !== 'pending') return;
    
    match ($event->type) {
        'payment_intent.succeeded' => $this->confirmPayment($event),
        'payment_intent.payment_failed' => $this->failPayment($event),
        default => null,
    };
}
```

- Uses `Webhook::constructEvent()` to verify Stripe signature header
- **Idempotency guard**: checks for duplicate webhook deliveries by `transaction_no`
- Supports both success and failure event types

## Twilio SMS Integration

### Architecture

Replaced `error_log()` stub with real SMS delivery via `twilio/sdk` ^8.0.

**File**: `service/app/notification/queue/SmsSender.php`

### Message Sending

```php
public function consume(): void
{
    $client = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );
    $message = $client->messages->create(
        $this->notification->recipient_phone,
        ['from' => getenv('TWILIO_PHONE_NUMBER'), 'body' => $this->notification->body]
    );
    $this->notification->provider_message_id = $message->sid;
}
```

### Error Handling

- Catches `Twilio\Exceptions\RestException` — captures Twilio error code and message
- Creates a failed Notification record with `send_status = 'failed'`
- Records `provider_message_id` (Twilio SID) for delivery tracking
- Falls back to `error_log()` when Twilio credentials are unset (dev mode)

### Configuration

Env vars: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER`

## FCM Push Integration

### Architecture

Replaced `error_log()` stub with real push delivery via `kreait/firebase-php` ^7.0.

**File**: `service/app/notification/queue/PushSender.php`

### Device Token Storage

Added to `users` table via migration:
- `fcm_token VARCHAR(512) DEFAULT NULL` — device registration token
- `fcm_platform VARCHAR(16) DEFAULT NULL` — `ios` / `android` / `web`
- `INDEX idx_fcm_token (fcm_token)` — lookup by token

User model: `fcm_token` and `fcm_platform` added to `$fillable`.

### Push Sending

```php
public function consume(): void
{
    $factory = new \Kreait\Firebase\Factory();
    if ($credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH')) {
        $factory = $factory->withServiceAccount($credentialsPath);
    }
    $messaging = $factory->createMessaging();
    
    $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget(
        'token', $this->user->fcm_token
    )->withNotification([
        'title' => $this->notification->title,
        'body'  => $this->notification->body,
    ]);
    
    $result = $messaging->send($message);
}
```

### Token Cleanup

- Catches `Kreait\Firebase\Exception\Messaging\InvalidToken` — nullifies user's `fcm_token`
- Catches `Kreait\Firebase\Exception\Messaging\NotFound` — removes unregistered token
- Falls back to `error_log()` when Firebase credentials are unset (dev mode)

### Configuration

Env vars: `FIREBASE_CREDENTIALS_PATH` (service account JSON), `FCM_SERVER_KEY` (legacy)

## Business Flow Diagrams

### Order → Payment → Provisioning (Core Business Flow)

![Order Payment Provisioning Flow](diagrams/order-payment-provisioning.svg)

### Event-Driven Provisioning Detail

![Event-Driven Provisioning](diagrams/provisioning-detail.svg)

### Notification Dispatch

![Notification Dispatch](diagrams/notification-dispatch.svg)

### Supplier Lifecycle

![Supplier Lifecycle](diagrams/supplier-lifecycle.svg)

### Ticket Lifecycle

![Ticket Lifecycle](diagrams/ticket-lifecycle.svg)

## Service-Layer Test Suite

### Overview

```
PHPUnit 10.5 | 295 tests | 455 assertions
```

**Directory**: `service/tests/` — 12 test files across 7 modules

**Config**: `service/phpunit.xml` — single `unit` testsuite, covers `app/` and `common/` source

### Test Bootstrap

`service/tests/bootstrap.php` loads Composer autoload and defines two global helpers needed by the code under test:

- `request_id()` — returns unique request ID string
- `now()` — returns current `DateTime` object

Critical learning: `Webman\Config` cannot be loaded in test context because `loadFromDir()` triggers `route.php` which calls `Route::addRoute()` on null. Tests bypass Config entirely — `HashidServiceTest` uses `new Hashids()` directly, `ResponseTest` uses local helper methods.

### Test Files

| File | Tests | Coverage |
|------|-------|----------|
| `Captcha/CaptchaServiceTest.php` | 9 | create structure, difficulty levels, verify pass/fail, one-time use, unique keys |
| `Confirmation/ConfirmationMiddlewareTest.php` | 11 | auth required, missing password, wrong password, success pass-through, rate limit key format, lock key format, max failure thresholds |
| `Common/HashidServiceTest.php` | 17 | encode/decode roundtrip, determinism, salt isolation, recursive ID walk |
| `Common/ResponseTest.php` | 16 | success/error/paginated structure, request_id consistency, HTTP error codes |
| `Common/SnowflakeTest.php` | 6 | timestamp ordering, uniqueness, bigint range, init pattern |
| `Common/ValidatorTest.php` | 22 | required(), email(), minLength() validation rules |
| `Common/LogSanitizerTest.php` | 34 | PII redaction, nested arrays, case-insensitive matching, 20 sensitive field types |
| `Payment/StripeChannelTest.php` | 19 | channel config, amount calculation, webhook signatures, idempotency |
| `Payment/PaymentRouterTest.php` | 10 | channel filtering, amount constraints, currency/region support, fee calculation |
| `Notification/NotificationDispatcherTest.php` | 8 | template rendering, channel routing, inactive user skip |
| `Provisioning/ProviderFactoryTest.php` | 12 | register, create, createFromResource, error cases |
| `Provisioning/RetryLogicTest.php` | 12 | exponential backoff, max retries, status transitions, host selection |
| `ClientPlatform/ClientPlatformMiddlewareTest.php` | 13 | valid platforms, missing/default header, unsupported platform, case-insensitive, non-API skip, admin routes, downstream access |
| `Security/WafMiddlewareTest.php` | 37 | SQLi (4), XSS (6), CMDi (4), file inclusion (3), header injection/CRLF (2), SSRF (5), NoSQL injection (4), open redirect (2), safe pass-through (5), URL scanning, UA scanning |
| `Version/VersionMiddlewareTest.php` | 6 | valid version, missing version default, unsupported version 400, non-API skip, admin API validation, error response headers |

### Test Infrastructure

- `tests/TestCase.php` — base class extending PHPUnit TestCase
- `tests/Support/RequestMock.php` — mock request with constructor-injected params

## CI/CD Pipeline

### Architecture

GitHub Actions workflow at `.github/workflows/ci.yml`.

**Triggers**: push to `main`, pull requests to `main`

### Jobs

| Job | Strategy | Description |
|-----|----------|-------------|
| `syntax` | PHP 8.2 | `php -l` lint all `.php` files in admin/ and service/ |
| `admin-tests` | PHP 8.2, 8.3 | `composer install -d admin` → `admin/vendor/bin/phpunit` |
| `service-tests` | PHP 8.2, 8.3 | `composer install -d service` → `service/vendor/bin/phpunit` |
| `composer-validate` | PHP 8.2 | `composer validate --strict` on both composer.json files |

### PHP Version Matrix

Both test jobs run on PHP 8.2 and 8.3 via `shivammathur/setup-php@v2`.

### Current Status

All 4 jobs pass: 243 total tests (67 admin + 176 service), 400 assertions, both PHP versions green.

## Database Entity Relationship

![Database Entity Relationship](diagrams/database-er.svg)

## Key Design Decisions

1. **Standalone instance**: admin/ runs as its own webman instance, not as a plugin within service/. This isolates admin traffic and failures from the customer-facing API.

2. **Encryptable + password hashing**: Passwords are bcrypt-hashed first, then AES-encrypted. The Encryptable cast operates at the Eloquent level (above hashing), so the layering is: `input → bcrypt hash → model attribute set → Encryptable::set() encrypts → DB`. On read: `DB → Encryptable::get() decrypts → bcrypt hash → password_verify()`.

3. **Hashids at the Controller boundary**: Encoding/decoding happens at the HTTP boundary (controllers), not at the model or ORM level. This keeps models database-agnostic and makes hashids a pure presentation concern.

4. **Container-based service resolution**: Services (Snowflake, HashidsManager, EncryptionManager) are registered as singletons via Bootstrap classes at worker start. Container resolution via `\support\Container::instance()` uses lazy instantiation — services are only created on first access.

## Extended Features (2026-05-20)

### Service Admin API — New Endpoints

| Group | Endpoints | Controller |
|-------|-----------|------------|
| Invoices | `GET /admin/api/invoices`, `POST .../invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Provider APIs | `GET/POST /admin/api/providers`, `PUT/DELETE .../providers/{id}` | `Admin\ProviderApiController` |
| Supplier API Keys | `GET/POST /admin/api/suppliers/{id}/api-keys`, `DELETE .../api-keys/{id}` | `Admin\SupplierController` |
| Coupons | `GET/POST /admin/api/coupons`, `DELETE .../coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET/POST/DELETE /admin/api/webhooks`, `POST .../webhooks/test` | `Admin\WebhookController` |
| Product Import/Export | `GET /admin/api/products/export`, `POST .../products/import` | `Admin\ImportExportController` |
| Domain Management | `GET/POST/PUT/DELETE /admin/api/domains/tlds`, `GET .../zones`, `GET .../transfers`, `POST .../transfers/{id}/approve` | `Admin\DomainController` |
| Notification Templates | `GET /admin/api/notifications/templates`, `PUT .../templates/{id}`, `GET .../log` | `Admin\NotificationController` |
| Help Articles | `GET/POST /admin/api/help`, `PUT/DELETE .../help/{id}` | `Admin\HelpController` |

### New Middleware

| Middleware | Purpose |
|------------|---------|
| `VersionMiddleware` | Reads and validates API version from the X-Api-Version header |
| `RateLimitMiddleware` | Redis token-bucket rate limiting (default 60 req/min, login 5 req/min) |
| `GeoBlockMiddleware` | MaxMind GeoIP2 geo-blocking |
| `MaintenanceMiddleware` | Maintenance mode (env var switch + IP whitelist) |
| `ClientPlatformMiddleware` | Client platform detection (X-Client-Platform header), 8 platforms supported |
| `SupplierApiKeyMiddleware` | Supplier external API authentication (sk_xxx key SHA256 signature verification) |
| `WafMiddleware` (admin) | Admin panel WAF middleware, 8 categories 45+ rules + request size limit + Content-Type validation |

### Scheduled Tasks

| Schedule | Task | Purpose |
|----------|------|---------|
| `13 */4 * * *` | ExchangeRateSync | Exchange rate update |
| `37 2 * * *` | PaymentReconcile | Daily payment reconciliation |
| `17 4 * * 1` | SupplierSettlement | Weekly supplier settlement |
| `23 6 * * *` | ExpirationCheck | Resource/domain expiration check + notification |
| `43 7 * * *` | SslCertificateCheck | SSL certificate expiration check + notification |
| `*/5 * * * *` | CollectMetrics | Resource metrics collection |
| `*/30 * * * *` | CheckExpirations | Resource expiration check |

### CLI Commands

| Command | Purpose |
|---------|---------|
| `php webman migrate` | Run pending migrations |
| `php webman migrate:rollback` | Roll back the last batch |
| `php webman migrate:status` | View migration status |
| `php webman db:backup` | Backup database to SQL file (optional --s3 upload) |

### Database Migrations Added (2026-05-20)

| Migration | Tables/Columns |
|-----------|---------------|
| 000003 | users + totp_secret, totp_enabled |
| 000004 | coupons, user_coupons |
| 000005 | help_articles, supplier_api_keys |
| 000006 | roles, permissions, role_permission + seed data |
| 000007 | users + email_verified_at, email_verify_token |
| 000008 | users + last_login_ip, last_login_at |

## Documentation Index

### Core Documents

| Document | Path | Description |
|----------|------|-------------|
| Architecture design | `docs/architecture.md` | System architecture, component relationships, middleware pipeline, security layers, data architecture, deployment topology |
| Feature design | `docs/features.md` | Detailed feature design for 21 modules, with flowcharts, data models, interaction notes |
| API reference | `docs/api-reference.md` | Complete reference for 200+ endpoints, grouped by module, with request/response examples and error codes |
| API online docs (service) | `http://localhost:8787/apidoc` | Auto-generated by hg/apidoc, grouped by feature, supports online debugging |
| API online docs (admin) | `http://localhost:8788/apidoc` | Auto-generated by hg/apidoc, 54 controllers in 13 feature groups |
| System design spec | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` | Complete architecture, data models, API design, security policy |
| Admin panel design | `docs/admin-design.md` | Admin panel architecture, package integration, ACL permissions, test suite |
| Supplier API docs | `docs/supplier-api.md` | Supplier API reference (internal API + external API), SDK examples |
| Deployment checklist | `docs/deployment.md` | Server configuration, environment variables, database migrations, Nginx, HTTPS, scheduled tasks |

### Implementation Plans

| Document | Path | Description |
|----------|------|-------------|
| Phase 0 — Foundation | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase0.md` | Project skeleton, directory structure, core infrastructure |
| Phase 1 — Users & Mall | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase1.md` | User authentication, product management, cart, orders |
| Phase 2 — Resources & Suppliers | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase2.md` | Resource provisioning, DNS, supplier onboarding |
| Phase 3 — Clients & Delivery | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase3.md` | Flutter client, multi-platform adaptation, CI/CD |

### Tools & Resources

| Document | Path | Description |
|----------|------|-------------|
| API smoke tests | `docs/api-test.sh` | curl-based automated test script for API endpoints |
| Database DDL | `docs/database.sql` | Database table creation statements |

## Final Test Stats

```
OK (362 tests, 579 assertions)
Test files: 22
```
- Admin: 67 tests, 124 assertions
- Service: 295 tests, 455 assertions
