# Phase 0: Infrastruktur — Implementierungsplan

> **Für agentische Worker:** ERFORDERLICHES UNTER-SKILL: Verwende superpowers:subagent-driven-development (empfohlen) oder superpowers:executing-plans, um diesen Plan Aufgabe für Aufgabe zu implementieren. Schritte verwenden Checkbox-Syntax (`- [ ]`) zur Fortschrittsverfolgung.

**Ziel:** Projektgerüst, Datenbankmigrationen, gemeinsame Bibliotheken, Authentifizierung und Autorisierung, Mehrsprachigkeits-Infrastruktur und Sicherheits-Middleware aufbauen — die Grundlage für alle nachfolgenden Phasen.

**Architektur:** webman als modulares Monolith, aufgeteilt in separate Verzeichnismodule nach Geschäftsdomänen; gemeinsame Infrastruktur unter common/. Datenbank: MySQL + Eloquent ORM, Cache/Queue: Redis.

**Tech-Stack:** PHP 8.2+, webman 1.6+, illuminate/database, firebase/php-jwt, redis, monolog

---

## Dateistruktur (diese Phase)

```
service/
├── composer.json
├── start.php
├── config/
│   ├── app.php
│   ├── database.php
│   ├── redis.php
│   ├── auth.php
│   ├── i18n.php
│   └── security.php
├── database/
│   └── migrations/
│       ├── 0001_create_users_tables.php
│       ├── 0002_create_product_tables.php
│       ├── 0003_create_order_tables.php
│       ├── 0004_create_payment_tables.php
│       ├── 0005_create_provisioning_tables.php
│       ├── 0006_create_host_tables.php
│       ├── 0007_create_supplier_tables.php
│       ├── 0008_create_domain_tables.php
│       ├── 0009_create_ticket_notification_tables.php
│       └── 0010_create_audit_table.php
├── common/
│   ├── auth/
│   │   ├── JwtAuth.php
│   │   ├── Rbac.php
│   │   └── middleware/
│   │       ├── AuthMiddleware.php
│       │       └── RbacMiddleware.php
│   ├── i18n/
│   │   ├── I18n.php
│   │   └── middleware/
│   │       └── LocaleMiddleware.php
│   ├── security/
│   │   ├── WafMiddleware.php
│   │   ├── RateLimitMiddleware.php
│   │   ├── CorsMiddleware.php
│   │   ├── AuditLogger.php
│   │   └── LogSanitizer.php
│   └── helper/
│       ├── Response.php
│       └── Validator.php
├── app/
│   └── controller/
│       └── HealthController.php
├── i18n/
│   ├── zh-CN/
│   │   └── messages.php
│   └── en-US/
│       └── messages.php
└── storage/
    └── logs/
```

---

### Aufgabe 0.1: webman-Projekt initialisieren und Abhängigkeiten installieren

**Dateien:**
- Erstellen: `service/composer.json`

- [ ] **Schritt 1: composer.json erstellen**

```json
{
    "name": "cloud/platform",
    "type": "project",
    "require": {
        "php": ">=8.2",
        "workerman/webman-framework": "^1.6",
        "webman/admin": "^0.8",
        "illuminate/database": "^10.0",
        "illuminate/events": "^10.0",
        "firebase/php-jwt": "^6.0",
        "vlucas/phpdotenv": "^5.0",
        "monolog/monolog": "^3.0",
        "ext-json": "*",
        "ext-pdo": "*",
        "ext-redis": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Common\\": "common/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    }
}
```

- [ ] **Schritt 2: Abhängigkeiten installieren**

Ausführen: `cd service && composer install`
Erwartet: Alle Pakete installiert, vendor/ erstellt.

- [ ] **Schritt 3: config/app.php erstellen**

```php
<?php
return [
    'name'      => getenv('APP_NAME') ?: 'CloudPlatform',
    'debug'     => getenv('APP_DEBUG') === 'true',
    'timezone'  => 'UTC',
    'locale'    => 'en-US',
    'fallback_locale' => 'en-US',
    'currencies' => ['USD', 'CNY', 'EUR', 'JPY', 'GBP'],
    'base_currency' => 'USD',
];
```

- [ ] **Schritt 4: config/database.php erstellen**

```php
<?php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => getenv('DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('DB_PORT') ?: '3306',
            'database'  => getenv('DB_DATABASE') ?: 'cloud_platform',
            'username'  => getenv('DB_USERNAME') ?: 'app_user',
            'password'  => getenv('DB_PASSWORD') ?: '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ],
        'audit' => [
            'driver'    => 'mysql',
            'host'      => getenv('AUDIT_DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('AUDIT_DB_PORT') ?: '3306',
            'database'  => getenv('AUDIT_DB_DATABASE') ?: 'cloud_platform_audit',
            'username'  => getenv('AUDIT_DB_USERNAME') ?: 'app_user',
            'password'  => getenv('AUDIT_DB_PASSWORD') ?: '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
];
```

- [ ] **Schritt 5: config/redis.php erstellen**

```php
<?php
return [
    'default' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 0,
    ],
    'cache' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 1,
    ],
    'session' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 2,
    ],
];
```

- [ ] **Schritt 6: config/auth.php erstellen**

```php
<?php
return [
    'jwt' => [
        'algorithm'   => 'RS256',
        'private_key' => getenv('JWT_PRIVATE_KEY'),
        'public_key'  => getenv('JWT_PUBLIC_KEY'),
        'access_ttl'  => 7200,   // 2 Stunden
        'refresh_ttl' => 2592000, // 30 Tage
        'issuer'      => 'cloud-platform',
    ],
    'password' => [
        'algo'  => PASSWORD_BCRYPT,
        'cost'  => 12,
        'min_length' => 8,
    ],
    'mfa' => [
        'issuer' => 'CloudPlatform',
        'digits' => 6,
        'period' => 30,
        'algo'   => 'sha1',
    ],
];
```

- [ ] **Schritt 7: config/security.php erstellen**

```php
<?php
return [
    'rate_limits' => [
        'default'  => ['rate' => 60,  'burst' => 10, 'per' => 60],
        'login'    => ['rate' => 5,   'burst' => 2,  'per' => 60],
        'register' => ['rate' => 3,   'burst' => 0,  'per' => 60],
        'pay'      => ['rate' => 10,  'burst' => 3,  'per' => 60],
        'upload'   => ['rate' => 10,  'burst' => 2,  'per' => 60],
    ],
    'waf' => [
        'sqli_patterns' => [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/\b(union|select|insert|update|delete|drop|alter|create|truncate)\b/i',
        ],
        'xss_patterns' => [
            '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i',
            '/\b(onload|onerror|onclick|document\.|window\.|alert|eval)\b/i',
        ],
    ],
    'encryption' => [
        'algo'   => 'aes-256-gcm',
        'master_key' => getenv('ENCRYPTION_MASTER_KEY'),
    ],
];
```

- [ ] **Schritt 8: config/i18n.php erstellen**

```php
<?php
return [
    'default_locale'   => 'en-US',
    'fallback_locale'  => 'en-US',
    'supported_locales' => ['en-US', 'zh-CN', 'ja-JP', 'ko-KR', 'de-DE', 'fr-FR', 'es-ES'],
    'locale_map' => [
        'en' => 'en-US', 'zh' => 'zh-CN', 'ja' => 'ja-JP',
        'ko' => 'ko-KR', 'de' => 'de-DE', 'fr' => 'fr-FR', 'es' => 'es-ES',
    ],
];
```

- [ ] **Schritt 9: start.php-Bootstrap erstellen**

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

// .env laden
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Alle Konfigurationen laden
$configs = glob(__DIR__ . '/config/*.php');
foreach ($configs as $file) {
    $key = basename($file, '.php');
    config()->set($key, require $file);
}

// Eloquent initialisieren
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection(config('database.connections.mysql'), 'default');
$capsule->addConnection(config('database.connections.audit'), 'audit');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Zeitzone
date_default_timezone_set(config('app.timezone'));
```

- [ ] **Schritt 10: Commit**

```bash
git add service/composer.json service/config/ service/start.php
git commit -m "feat: initialize webman project with configuration files"
```

---

### Aufgabe 0.2: Alle Datenbankmigrationen erstellen

**Dateien:**
- Erstellen: `service/database/migrations/0001_create_users_tables.php`

- [ ] **Schritt 1: Users-Migration erstellen**

```php
<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// users
Capsule::schema()->create('users', function (Blueprint $table) {
    $table->id();
    $table->string('email', 255)->unique()->nullable();
    $table->string('phone', 20)->unique()->nullable();
    $table->string('password_hash', 255);
    $table->string('language', 10)->default('en-US');
    $table->string('currency', 5)->default('USD');
    $table->string('timezone', 40)->default('UTC');
    $table->string('status', 20)->default('active');
    $table->string('role', 20)->default('user'); // user/supplier/admin
    $table->json('notification_prefs')->nullable();
    $table->softDeletes();
    $table->timestamps();
    $table->index(['email', 'phone']);
});

// user_profiles
Capsule::schema()->create('user_profiles', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('avatar', 500)->nullable();
    $table->string('nickname', 100)->nullable();
    $table->string('country', 10)->nullable();
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->timestamps();
});

// user_kyc
Capsule::schema()->create('user_kyc', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('id_type', 20); // passport/driver_license/id_card
    $table->string('id_number_encrypted', 500);
    $table->string('real_name', 100);
    $table->string('front_image', 500);
    $table->string('back_image', 500)->nullable();
    $table->string('status', 20)->default('pending'); // pending/approved/rejected
    $table->text('reject_reason')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->unsignedBigInteger('verified_by')->nullable();
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->timestamps();
});

// user_balance
Capsule::schema()->create('user_balance', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('currency', 5)->default('USD');
    $table->decimal('balance', 16, 4)->default(0);
    $table->decimal('frozen_balance', 16, 4)->default(0);
    $table->unique(['user_id', 'currency']);
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->timestamps();
});

// user_balance_log
Capsule::schema()->create('user_balance_log', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('type', 30); // charge/payment/refund/freeze/unfreeze
    $table->string('currency', 5);
    $table->decimal('amount', 16, 4);
    $table->decimal('balance_before', 16, 4);
    $table->decimal('balance_after', 16, 4);
    $table->unsignedBigInteger('order_id')->nullable();
    $table->string('remark', 500)->nullable();
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->timestamps();
    $table->index(['user_id', 'created_at']);
});

// user_addresses
Capsule::schema()->create('user_addresses', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('type', 20)->default('billing'); // billing/shipping
    $table->string('name', 100);
    $table->string('phone', 20);
    $table->string('country', 10);
    $table->string('state', 100)->nullable();
    $table->string('city', 100);
    $table->string('address', 500);
    $table->string('postcode', 20)->nullable();
    $table->boolean('is_default')->default(false);
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->timestamps();
});

// refresh_tokens
Capsule::schema()->create('refresh_tokens', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('token_hash', 255)->unique();
    $table->string('device_fingerprint', 255);
    $table->timestamp('expires_at');
    $table->boolean('revoked')->default(false);
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->timestamps();
});

echo "Users tables created.\n";
```

- [ ] **Schritt 2: Products-Migration erstellen** — `service/database/migrations/0002_create_product_tables.php`

```php
<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// product_categories
Capsule::schema()->create('product_categories', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('parent_id')->nullable();
    $table->json('name');
    $table->string('icon', 255)->nullable();
    $table->integer('sort')->default(0);
    $table->foreign('parent_id')->references('id')->on('product_categories')->onDelete('set null');
    $table->timestamps();
});

// regions
Capsule::schema()->create('regions', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->string('continent', 50)->nullable();
    $table->string('country', 100)->nullable();
    $table->string('city', 100)->nullable();
    $table->string('data_center', 100)->nullable();
    $table->string('status', 20)->default('active');
    $table->timestamps();
});

// products
Capsule::schema()->create('products', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('supplier_id')->nullable();
    $table->unsignedBigInteger('category_id');
    $table->json('name');
    $table->string('slug', 200)->unique();
    $table->json('description')->nullable();
    $table->string('cover', 500)->nullable();
    $table->string('status', 20)->default('draft');
    $table->timestamps();
    $table->index('supplier_id');
    $table->index('category_id');
});

// product_skus
Capsule::schema()->create('product_skus', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id');
    $table->json('specs')->nullable(); // {cpu, ram, disk, bandwidth, os...}
    $table->string('cycle', 20)->default('monthly'); // monthly/quarterly/yearly/onetime
    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
    $table->timestamps();
});

// product_regions
Capsule::schema()->create('product_regions', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('sku_id');
    $table->unsignedBigInteger('region_id');
    $table->decimal('price', 14, 4);
    $table->decimal('original_price', 14, 4)->nullable();
    $table->integer('stock')->default(0);
    $table->string('currency', 5)->default('USD');
    $table->unique(['sku_id', 'region_id', 'currency']);
    $table->foreign('sku_id')->references('id')->on('product_skus')->onDelete('cascade');
    $table->foreign('region_id')->references('id')->on('regions')->onDelete('cascade');
    $table->timestamps();
});

// product_images
Capsule::schema()->create('product_images', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id');
    $table->string('url', 500);
    $table->integer('sort')->default(0);
    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
    $table->timestamps();
});

// product_attributes
Capsule::schema()->create('product_attributes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id');
    $table->string('key', 100);
    $table->string('value', 500);
    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
    $table->index(['key']);
});

// product_reviews
Capsule::schema()->create('product_reviews', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('product_id');
    $table->unsignedBigInteger('order_id')->nullable();
    $table->tinyInteger('rating')->unsigned();
    $table->text('content')->nullable();
    $table->string('status', 20)->default('published');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
    $table->unique(['user_id', 'product_id', 'order_id']);
    $table->timestamps();
});

echo "Product tables created.\n";
```

- [ ] **Schritt 3: Orders-Migration erstellen** — `service/database/migrations/0003_create_order_tables.php`

```php
<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// carts
Capsule::schema()->create('carts', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('sku_id');
    $table->unsignedBigInteger('region_id');
    $table->integer('quantity')->default(1);
    $table->string('cycle', 20)->default('monthly');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->unique(['user_id', 'sku_id', 'region_id']);
    $table->timestamps();
});

// orders
Capsule::schema()->create('orders', function (Blueprint $table) {
    $table->id();
    $table->string('order_no', 32)->unique();
    $table->unsignedBigInteger('user_id');
    $table->string('type', 20)->default('new'); // new/renew/upgrade
    $table->string('status', 20)->default('pending'); // pending/paid/provisioning/completed/refunding/refunded/cancelled
    $table->string('currency', 5)->default('USD');
    $table->decimal('subtotal', 14, 4);
    $table->decimal('discount', 14, 4)->default(0);
    $table->decimal('tax', 14, 4)->default(0);
    $table->decimal('total', 14, 4);
    $table->decimal('exchange_rate', 14, 6)->default(1.0);
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
    $table->index(['user_id', 'created_at']);
});

// order_items
Capsule::schema()->create('order_items', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('sku_id');
    $table->unsignedBigInteger('region_id');
    $table->unsignedBigInteger('product_id');
    $table->integer('quantity')->default(1);
    $table->string('cycle', 20);
    $table->decimal('unit_price', 14, 4);
    $table->decimal('total_price', 14, 4);
    $table->json('resource_snapshot')->nullable();
    $table->string('status', 20)->default('pending');
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->foreign('sku_id')->references('id')->on('product_skus');
    $table->timestamps();
});

// order_timeline
Capsule::schema()->create('order_timeline', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->string('status', 30);
    $table->string('operator', 100)->nullable();
    $table->text('remark')->nullable();
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->timestamps();
});

// order_invoices
Capsule::schema()->create('order_invoices', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('user_id');
    $table->string('type', 20)->default('personal'); // personal/company
    $table->string('title', 200);
    $table->string('tax_number', 50)->nullable();
    $table->decimal('amount', 14, 4);
    $table->string('file_url', 500)->nullable();
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->timestamps();
});

// refunds
Capsule::schema()->create('refunds', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('user_id');
    $table->decimal('amount', 14, 4);
    $table->text('reason')->nullable();
    $table->string('status', 20)->default('pending'); // pending/approved/rejected/processing/completed
    $table->unsignedBigInteger('handled_by')->nullable();
    $table->text('reject_reason')->nullable();
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->timestamps();
});

echo "Order tables created.\n";
```

- [ ] **Schritt 4: Migrationen 0004-0010**

Die restlichen Migrationen nach demselben Muster fortsetzen:

`0004_create_payment_tables.php` — payment_channels, payment_transactions, payment_reconcile

`0005_create_provisioning_tables.php` — resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis

`0006_create_host_tables.php` — host_machines, ip_pools, ip_allocations, disks, disk_resizes

`0007_create_supplier_tables.php` — suppliers, supplier_products, supplier_settlements, supplier_withdraws

`0008_create_domain_tables.php` — domain_tlds, domain_transfers, dns_zones, dns_records

`0009_create_ticket_notification_tables.php` — tickets, ticket_messages, notifications, notification_templates

`0010_create_audit_table.php` — audit_logs

Jede folgt demselben Muster wie oben mit `Capsule::schema()->create(...)`.

- [ ] **Schritt 5: Migrations-Runner erstellen** — `service/database/migrate.php`

```php
<?php
require_once __DIR__ . '/../start.php';

$files = glob(__DIR__ . '/migrations/*.php');
sort($files);

foreach ($files as $file) {
    echo "Running: " . basename($file) . "\n";
    require $file;
}

echo "All migrations complete.\n";
```

- [ ] **Schritt 6: Alle Migrationen ausführen**

Ausführen: `php service/database/migrate.php`
Erwartet: Alle Tabellen erfolgreich erstellt.

- [ ] **Schritt 7: Commit**

```bash
git add service/database/
git commit -m "feat: add all database migrations (10 files, 50+ tables)"
```

---

### Aufgabe 0.3: Gemeinsame Helper-Bibliothek erstellen

**Dateien:**
- Erstellen: `service/common/helper/Response.php`
- Erstellen: `service/common/helper/Validator.php`

- [ ] **Schritt 1: Response-Helper erstellen**

```php
<?php
namespace Common\Helper;

class Response
{
    public static function success($data = null, string $message = 'ok', array $meta = []): array
    {
        $body = [
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
            'request_id' => request_id(),
        ];
        if ($meta) {
            $body['meta'] = $meta;
        }
        return $body;
    }

    public static function error(int $code, string $message, $data = null, int $httpStatus = 200): array
    {
        return [
            'code'       => $code,
            'message'    => $message,
            'data'       => $data,
            'request_id' => request_id(),
        ];
    }

    public static function paginated($items, int $total, int $page, int $pageSize): array
    {
        return self::success($items, 'ok', [
            'page'      => $page,
            'page_size' => $pageSize,
            'total'     => $total,
        ]);
    }
}
```

- [ ] **Schritt 2: request_id-Helper erstellen**

```php
// In start.php:
function request_id(): string {
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(8));
    }
    return $id;
}
```

- [ ] **Schritt 3: Commit**

```bash
git add service/common/helper/
git commit -m "feat: add Response helper and request_id"
```

---

### Aufgabe 0.4: JWT-Authentifizierung erstellen

**Dateien:**
- Erstellen: `service/common/auth/JwtAuth.php`
- Erstellen: `service/common/auth/middleware/AuthMiddleware.php`

- [ ] **Schritt 1: JwtAuth erstellen**

```php
<?php
namespace Common\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtAuth
{
    private string $privateKey;
    private string $publicKey;
    private string $algorithm;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $cfg = config('auth.jwt');
        $this->privateKey = $cfg['private_key'];
        $this->publicKey  = $cfg['public_key'];
        $this->algorithm  = $cfg['algorithm'];
        $this->accessTtl  = $cfg['access_ttl'];
        $this->refreshTtl = $cfg['refresh_ttl'];
    }

    public function issueAccessToken(int $userId, string $role): string
    {
        $payload = [
            'iss'  => config('auth.jwt.issuer'),
            'sub'  => $userId,
            'role' => $role,
            'iat'  => time(),
            'exp'  => time() + $this->accessTtl,
            'jti'  => bin2hex(random_bytes(16)),
            'type' => 'access',
        ];
        return JWT::encode($payload, $this->privateKey, $this->algorithm);
    }

    public function issueRefreshToken(int $userId): string
    {
        $payload = [
            'iss'  => config('auth.jwt.issuer'),
            'sub'  => $userId,
            'iat'  => time(),
            'exp'  => time() + $this->refreshTtl,
            'jti'  => bin2hex(random_bytes(16)),
            'type' => 'refresh',
        ];
        return JWT::encode($payload, $this->privateKey, $this->algorithm);
    }

    public function verify(string $token): object
    {
        return JWT::decode($token, new Key($this->publicKey, $this->algorithm));
    }

    public function isRevoked(string $jti): bool
    {
        return Redis::exists("jwt:revoked:{$jti}");
    }

    public function revoke(string $jti): void
    {
        Redis::setex("jwt:revoked:{$jti}", $this->accessTtl, '1');
    }

    public function revokeAllUserTokens(int $userId): void
    {
        RefreshToken::where('user_id', $userId)->update(['revoked' => true]);
    }
}
```

- [ ] **Schritt 2: AuthMiddleware erstellen**

```php
<?php
namespace Common\Auth\Middleware;

use Common\Auth\JwtAuth;
use Common\Helper\Response;

class AuthMiddleware
{
    public function process($request, callable $next)
    {
        $header = $request->header('Authorization');
        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return json(Response::error(401, 'Unauthorized'));
        }

        $token = substr($header, 7);
        $jwt = new JwtAuth();

        try {
            $payload = $jwt->verify($token);
        } catch (\Exception $e) {
            return json(Response::error(401, 'Invalid token'));
        }

        if ($payload->type !== 'access') {
            return json(Response::error(401, 'Invalid token type'));
        }

        if ($jwt->isRevoked($payload->jti)) {
            return json(Response::error(401, 'Token revoked'));
        }

        $request->userId = $payload->sub;
        $request->userRole = $payload->role;

        return $next($request);
    }
}

class OptionalAuthMiddleware
{
    public function process($request, callable $next)
    {
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            $jwt = new JwtAuth();
            try {
                $payload = $jwt->verify($token);
                if ($payload->type === 'access' && !$jwt->isRevoked($payload->jti)) {
                    $request->userId = $payload->sub;
                    $request->userRole = $payload->role;
                }
            } catch (\Exception $e) {}
        }
        return $next($request);
    }
}
```

- [ ] **Schritt 3: Commit**

```bash
git add service/common/auth/
git commit -m "feat: add JWT authentication and auth middleware"
```

---

### Aufgabe 0.5: RBAC erstellen

**Dateien:**
- Erstellen: `service/common/auth/Rbac.php`
- Erstellen: `service/common/auth/middleware/RbacMiddleware.php`

- [ ] **Schritt 1: Rbac erstellen**

```php
<?php
namespace Common\Auth;

class Rbac
{
    private static array $permissions = [
        'super_admin' => ['*'],
        'admin' => [
            'user.view', 'user.update', 'user.kyc_review',
            'product.create', 'product.update', 'product.delete', 'product.review',
            'order.view', 'order.update', 'order.refund',
            'payment.view', 'payment.channel_config',
            'resource.view', 'resource.destroy', 'provider.config',
            'supplier.view', 'supplier.review', 'supplier.settle',
            'ticket.view', 'ticket.assign', 'ticket.reply',
            'notification.template', 'notification.send',
            'report.view', 'report.export',
            'system.config',
        ],
        'finance' => [
            'order.view', 'order.refund',
            'payment.view', 'payment.channel_config', 'payment.reconcile',
            'supplier.settle', 'supplier.withdraw_review',
            'report.view', 'report.export',
        ],
        'support' => [
            'user.view',
            'order.view',
            'resource.view',
            'ticket.view', 'ticket.reply',
        ],
        'supplier' => [
            'product.create', 'product.update_own',
            'order.view_own',
            'supplier.settle_view',
        ],
        'user' => [],
    ];

    public function hasPermission(string $role, string $permission): bool
    {
        $perms = self::$permissions[$role] ?? [];
        if (in_array('*', $perms)) return true;
        return in_array($permission, $perms);
    }
}
```

- [ ] **Schritt 2: RbacMiddleware erstellen**

```php
<?php
namespace Common\Auth\Middleware;

use Common\Auth\Rbac;
use Common\Helper\Response;

class RbacMiddleware
{
    public function process($request, callable $next, string $permission)
    {
        $role = $request->userRole ?? 'guest';
        $rbac = new Rbac();

        if (!$rbac->hasPermission($role, $permission)) {
            AuditLogger::unauthorized($request->userId ?? 0, $permission, $request);
            return json(Response::error(403, 'Forbidden'));
        }

        return $next($request);
    }
}
```

- [ ] **Schritt 3: Commit**

```bash
git add service/common/auth/Rbac.php service/common/auth/middleware/RbacMiddleware.php
git commit -m "feat: add RBAC permission system"
```

---

### Aufgabe 0.6: i18n-Infrastruktur erstellen

**Dateien:**
- Erstellen: `service/common/i18n/I18n.php`
- Erstellen: `service/common/i18n/middleware/LocaleMiddleware.php`
- Erstellen: `service/i18n/zh-CN/messages.php`
- Erstellen: `service/i18n/en-US/messages.php`

- [ ] **Schritt 1: I18n erstellen**

```php
<?php
namespace Common\I18n;

class I18n
{
    private static string $locale = 'en-US';
    private static array $messages = [];

    public static function setLocale(string $locale): void
    {
        $supported = config('i18n.supported_locales');
        if (in_array($locale, $supported)) {
            self::$locale = $locale;
        } else {
            $map = config('i18n.locale_map');
            self::$locale = $map[$locale] ?? config('i18n.fallback_locale');
        }
        self::loadMessages();
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function trans(string $key, array $replace = []): string
    {
        $message = self::$messages[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $message = str_replace(":{$k}", $v, $message);
        }
        return $message;
    }

    private static function loadMessages(): void
    {
        $path = base_path() . "/i18n/" . self::$locale . "/messages.php";
        if (file_exists($path)) {
            self::$messages = require $path;
        }
    }

    public static function translateField(?array $jsonValue): ?string
    {
        if (empty($jsonValue)) return null;
        return $jsonValue[self::$locale] ?? $jsonValue[config('i18n.fallback_locale')] ?? array_values($jsonValue)[0];
    }
}
```

- [ ] **Schritt 2: LocaleMiddleware erstellen**

```php
<?php
namespace Common\I18n\Middleware;

use Common\I18n\I18n;

class LocaleMiddleware
{
    public function process($request, callable $next)
    {
        $locale = $request->header('Accept-Language', config('i18n.default_locale'));
        // Locale aus dem Header parsen: "zh-CN,zh;q=0.9,en;q=0.8"
        $primary = explode(',', $locale)[0];
        $primary = explode(';', $primary)[0];
        I18n::setLocale(trim($primary));

        return $next($request);
    }
}
```

- [ ] **Schritt 3: i18n-Nachrichtendateien erstellen**

`service/i18n/en-US/messages.php`:
```php
<?php
return [
    'auth.login_success'   => 'Login successful',
    'auth.login_failed'    => 'Invalid email or password',
    'auth.register_success'=> 'Registration successful',
    'auth.token_expired'   => 'Token expired, please login again',
    'order.paid'           => 'Order paid successfully',
    'order.cancelled'      => 'Order cancelled',
    'resource.created'     => 'Resource provisioned successfully',
    'resource.destroyed'   => 'Resource destroyed',
    'validation.required'  => ':field is required',
    'error.server_error'   => 'Internal server error',
];
```

`service/i18n/zh-CN/messages.php`:
```php
<?php
return [
    'auth.login_success'   => '登录成功',
    'auth.login_failed'    => '邮箱或密码错误',
    'auth.register_success'=> '注册成功',
    'auth.token_expired'   => '令牌已过期，请重新登录',
    'order.paid'           => '订单支付成功',
    'order.cancelled'      => '订单已取消',
    'resource.created'     => '资源开通成功',
    'resource.destroyed'   => '资源已销毁',
    'validation.required'  => ':field 不能为空',
    'error.server_error'   => '服务器内部错误',
];
```

- [ ] **Schritt 4: Commit**

```bash
git add service/common/i18n/ service/i18n/
git commit -m "feat: add i18n infrastructure with en-US and zh-CN"
```

---

### Aufgabe 0.7: Sicherheits-Middleware erstellen

**Dateien:**
- Erstellen: `service/common/security/WafMiddleware.php`
- Erstellen: `service/common/security/RateLimitMiddleware.php`
- Erstellen: `service/common/security/CorsMiddleware.php`
- Erstellen: `service/common/security/AuditLogger.php`
- Erstellen: `service/common/security/LogSanitizer.php`

- [ ] **Schritt 1: WafMiddleware erstellen**

```php
<?php
namespace Common\Security;

class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all());
        $patterns = array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns')
        );
        // Pfad-Traversal-Muster
        $patterns[] = '/\.\.\/|\.\.\%2f|\.\.\\\\/i';

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(common\Helper\Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }
}
```

- [ ] **Schritt 2: RateLimitMiddleware erstellen**

```php
<?php
namespace Common\Security;

class RateLimitMiddleware
{
    public function process($request, callable $next, string $route = 'default')
    {
        $limits = config('security.rate_limits');
        $limit = $limits[$route] ?? $limits['default'];
        
        $key = "ratelimit:" . $request->getRealIp() . ":{$route}";
        $current = Redis::get($key) ?: 0;
        
        if ($current >= $limit['rate']) {
            return json(common\Helper\Response::error(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]));
        }
        
        if ($current == 0) {
            Redis::setex($key, $limit['per'], 1);
        } else {
            Redis::incr($key);
        }
        
        return $next($request);
    }
}
```

- [ ] **Schritt 3: CorsMiddleware erstellen**

```php
<?php
namespace Common\Security;

class CorsMiddleware
{
    public function process($request, callable $next)
    {
        $origin = $request->header('Origin');
        $allowedOrigins = config('cors.allowed_origins', ['*']);
        
        if ($request->method() === 'OPTIONS') {
            return response('', 204, [
                'Access-Control-Allow-Origin'  => $origin,
                'Access-Control-Allow-Methods' => 'GET,POST,PUT,DELETE,OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type,Authorization,Accept-Language',
                'Access-Control-Max-Age'       => '86400',
            ]);
        }
        
        $response = $next($request);
        if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
        }
        return $response;
    }
}
```

- [ ] **Schritt 4: LogSanitizer erstellen**

```php
<?php
namespace Common\Security;

class LogSanitizer
{
    private static array $sensitiveFields = [
        'password', 'password_hash', 'password_confirmation',
        'secret', 'api_key', 'api_secret', 'api_token',
        'token', 'access_token', 'refresh_token',
        'credit_card', 'cvv', 'card_number',
        'ssn', 'id_number', 'real_name',
        'login_password', 'private_key',
        'auth_code', 'answer',
    ];

    public static function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::isSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = self::sanitize($value);
            }
        }
        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::$sensitiveFields as $field) {
            if (str_contains($lower, $field)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Schritt 5: AuditLogger erstellen**

```php
<?php
namespace Common\Security;

use Illuminate\Database\Capsule\Manager as DB;

class AuditLogger
{
    public static function record(string $action, array $context = [], $request = null): void
    {
        DB::connection('audit')->table('audit_logs')->insert([
            'user_id'    => $context['user_id'] ?? 0,
            'ip'         => $request ? $request->getRealIp() : '',
            'method'     => $request ? $request->method() : '',
            'path'       => $request ? $request->path() : '',
            'action'     => $action,
            'input'      => $context['input'] ?? '{}',
            'status'     => $context['status'] ?? 'success',
            'request_id' => request_id(),
            'user_agent' => $request ? $request->header('User-Agent', '') : '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function unauthorized($userId, string $permission, $request): void
    {
        self::record('unauthorized', [
            'user_id' => $userId,
            'input'   => json_encode(['permission' => $permission]),
            'status'  => 'blocked',
        ], $request);
    }

    public static function threat(string $type, $request): void
    {
        self::record("threat_{$type}", [
            'user_id' => 0,
            'input'   => LogSanitizer::sanitize($request->all()),
            'status'  => 'blocked',
        ], $request);
    }
}
```

- [ ] **Schritt 6: Commit**

```bash
git add service/common/security/
git commit -m "feat: add security middleware (WAF, rate limit, CORS, audit)"
```

---

### Aufgabe 0.8: Health-Endpunkt erstellen und Middleware-Pipeline registrieren

**Dateien:**
- Erstellen: `service/app/controller/HealthController.php`
- Ändern: `service/start.php` (Middleware-Registrierung)
- Ändern: `service/config/route.php` (falls vorhanden, andernfalls erstellen)

- [ ] **Schritt 1: HealthController erstellen**

```php
<?php
namespace App\Controller;

use Common\Helper\Response;

class HealthController
{
    public function index()
    {
        return json(Response::success([
            'status'    => 'healthy',
            'timestamp' => date('c'),
            'version'   => '1.0.0',
        ]));
    }
}
```

- [ ] **Schritt 2: Globale Middleware in start.php registrieren**

```php
// Nach dem Bootstrap in start.php hinzufügen
use Webman\Middleware;

// Globale Middleware-Pipeline
Middleware::add(\Common\I18n\Middleware\LocaleMiddleware::class);
Middleware::add(\Common\Security\CorsMiddleware::class);
Middleware::add(\Common\Security\WafMiddleware::class);
```

- [ ] **Schritt 3: Routenkonfiguration erstellen**

`service/config/route.php`:
```php
<?php
use Webman\Route;

// Health
Route::get('/health', [\App\Controller\HealthController::class, 'index']);

// API v1
Route::group('/api', function () {
    // Auth-Routen
    Route::post('/auth/register', [\App\Controller\User\AuthController::class, 'register']);
    Route::post('/auth/login', [\App\Controller\User\AuthController::class, 'login']);
    Route::post('/auth/refresh', [\App\Controller\User\AuthController::class, 'refresh']);
})->middleware([
    \Common\Security\RateLimitMiddleware::class . ':register',
]);

Route::group('/api/user', function () {
    Route::get('/profile', [\App\Controller\User\ProfileController::class, 'show']);
})->middleware([
    \Common\Auth\Middleware\AuthMiddleware::class,
]);

// Admin-API
Route::group('/admin/api', function () {
    Route::get('/dashboard', [\App\Controller\Admin\DashboardController::class, 'index']);
})->middleware([
    \Common\Auth\Middleware\AuthMiddleware::class,
    \Common\Auth\Middleware\RbacMiddleware::class . ':admin',
]);
```

- [ ] **Schritt 4: Commit**

```bash
git add service/app/controller/HealthController.php service/start.php service/config/route.php
git commit -m "feat: add health endpoint and middleware pipeline registration"
```

---

### Aufgabe 0.9: Vollständigkeit von Phase 0 verifizieren

- [ ] **Schritt 1: webman-Entwicklungsserver starten**

Ausführen: `cd service && php start.php start`
Erwartet: Server startet auf http://0.0.0.0:8787

- [ ] **Schritt 2: Health-Endpunkt testen**

Ausführen: `curl http://localhost:8787/health`
Erwartet: `{"code":0,"message":"ok","data":{"status":"healthy",...}}`

- [ ] **Schritt 3: Rate-Limiting testen**

Ausführen: `for i in {1..10}; do curl -s http://localhost:8787/health; done`
Erwartet: Alle geben 200 zurück (health hat kein striktes Rate-Limit)

- [ ] **Schritt 4: WAF testen**

Ausführen: `curl -X POST http://localhost:8787/api/auth/login -d "email=test' OR '1'='1"` 
Erwartet: `{"code":403,"message":"Request blocked by WAF"}`

- [ ] **Schritt 5: DB-Verbindung verifizieren**

Ausführen: `php service/database/migrate.php`
Erwartet: Alle Migrationen laufen fehlerfrei durch.

- [ ] **Schritt 6: Lock-Datei committen**

```bash
git add service/composer.lock
git commit -m "chore: add composer.lock"
```

---

**Phase 0 abgeschlossen.** Grundlage fertig: Projektgerüst, 50+ DB-Tabellen, JWT-Authentifizierung, RBAC, i18n, WAF + Rate-Limiting + CORS, Audit-Protokollierung.
