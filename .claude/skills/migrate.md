---
name: migrate
description: Run database migrations with advisory lock concurrency control
---
# Database Migrations

Migration runner with MySQL advisory locks for safe concurrent execution.

## Run migrations

```bash
# Admin
cd admin && php webman migrate

# Service
cd service && php webman migrate
```

## Create a migration

```bash
# Create migration file in database/migrations/
# Naming: YYYY_MM_DD_HHMMSS_description.php
```

Migration class must extend `support\Migration` (service) or `app\common\Migration` (admin):

```php
class CreateSomeTable extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('erik_some_table', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('erik_some_table');
    }
}
```

## Concurrency

- `MigrationRunner` acquires `GET_LOCK('migration_lock_<crc32>', 0)` before running
- Timeout 0 means non-blocking — fails fast if another process is migrating
- Each migration runs in a transaction (`beginTransaction` / `commit` / `rollBack`)
- Migration column is `string('migration', 512)` — tracks run migrations

## Schema reference

Full DDL at `docs/database.sql` — ~40 tables prefixed `erik_`.
