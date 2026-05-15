# Admin Panel Design Document

## Overview

`admin/` is a standalone webman v2.1 instance providing a Layui-based management dashboard. It runs independently from the `service/` backend, sharing only the MySQL database and the 6 erikwang2013 packages.

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
│  │         6 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season              │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

## Directory Layout

```
admin/
├── app/
│   ├── bootstrap/       # Per-process startup
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 16 controllers
│   │   ├── Base.php     # json() with hashids_encode_ids
│   │   ├── Crud.php     # Select/Insert/Update/Delete/Export with hashids decode
│   │   ├── DashboardController.php  # Dashboard data API (user stats + trends)
│   │   ├── AccountController.php    # Login/logout/profile/password
│   │   ├── AdminController.php      # Admin CRUD + roles
│   │   ├── RoleController.php       # Role CRUD + rule tree
│   │   └── ...
│   ├── model/            # 8 Eloquent models
│   │   ├── Base.php     # Snowflake PK + Encryptable support
│   │   ├── Admin.php    # Encryptable: password, email, mobile
│   │   ├── User.php     # Encryptable: 6 fields + Searchable trait
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # Public API (plugin\admin\api)
│   └── Auth.php          # canAccess() ACL
├── config/
│   ├── plugin/erikwang2013/  # 6 plugin configs
│   ├── hashids.php       # Hashids connections (main + alternative)
│   └── encryption.php    # Encryption config (master key, cipher)
├── tests/                # PHPUnit 11 test suite
│   ├── HashidsTest.php   # 21 tests
│   ├── BaseJsonTest.php  # 13 tests
│   ├── CrudHashidsTest.php # 14 tests
│   └── Support/          # RequestMock, TestableCrud
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

## ACL System

### Controller-level

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // Skip login
protected $noNeedAuth = ['select'];                         // Skip auth
```

Checked by `api/Auth::canAccess()` via `ReflectionClass`.

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
| `GET /admin/api/v1/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/v1/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/v1/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

Export routes are placed BEFORE `/{id}` parameter routes to avoid conflicts.

## Dashboard Visualization

### Architecture

The admin dashboard (`/app/admin/dashboard`) displays real-time operational metrics with ECharts visualizations. Data is served by `DashboardController::index()` as JSON and rendered client-side.

### DashboardController (`app/controller/DashboardController.php`)

Extends `Base`, `$noNeedAuth = ['index']`. Returns JSON with:

```php
[
    'stats' => [
        'today_users'   => ...,  // Users registered today
        'week_users'    => ...,  // Users registered in last 7 days
        'month_users'   => ...,  // Users registered in last 30 days
        'total_users'   => ...,  // Total registered users
    ],
    'user_trend_7d'  => [...],   // Daily registration count, last 7 days
    'user_trend_30d' => [...],   // Daily registration count, last 30 days
    'system' => [
        'php_version'       => ...,
        'workerman_version' => ...,
        'webman_version'    => ...,
        'admin_version'     => ...,
        'mysql_version'     => ...,
        'os'                => ...,
    ],
]
```

### Dashboard View (`app/view/index/dashboard.html`)

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
PHPUnit 11.5 | 48 tests | 81 assertions
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

## Key Design Decisions

1. **Standalone instance**: admin/ runs as its own webman instance, not as a plugin within service/. This isolates admin traffic and failures from the customer-facing API.

2. **Encryptable + password hashing**: Passwords are bcrypt-hashed first, then AES-encrypted. The Encryptable cast operates at the Eloquent level (above hashing), so the layering is: `input → bcrypt hash → model attribute set → Encryptable::set() encrypts → DB`. On read: `DB → Encryptable::get() decrypts → bcrypt hash → password_verify()`.

3. **Hashids at the Controller boundary**: Encoding/decoding happens at the HTTP boundary (controllers), not at the model or ORM level. This keeps models database-agnostic and makes hashids a pure presentation concern.

4. **Container-based service resolution**: Services (Snowflake, HashidsManager, EncryptionManager) are registered as singletons via Bootstrap classes at worker start. Container resolution via `\support\Container::instance()` uses lazy instantiation — services are only created on first access.
