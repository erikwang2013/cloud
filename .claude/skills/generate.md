---
name: generate
description: Generate admin CRUD models, controllers, and views for erik_* business tables from docs/database.sql
---
# Admin Code Generator

Generate models, controllers, and Layui views for all `erik_*` business tables.

## Usage

```bash
cd admin && php generate.php
```

## What it does

1. Parses `docs/database.sql` for all `CREATE TABLE erik_*` statements
2. Creates models in `admin/app/model/` extending `app\model\Base`
3. Creates controllers in `admin/app/controller/` extending `app\controller\Crud`
4. Creates views in `admin/app/view/{entity}/` (index.html, insert.html, update.html)
5. Generates menu config at `admin/config/business_menu.php`

## Configuration

- `$skipTables` — tables to skip entirely (e.g. `erik_users` managed by admin's own User model)
- `$readOnlyTables` — system-generated records, no insert/update/delete UI
- `fieldOptions()` — enum dropdown values for status, type, priority, etc.
- `colLabel()` — Chinese display labels for all column names

## After generation

1. Verify: `cd admin && php vendor/bin/phpunit -c phpunit.xml`
2. Start: `cd admin && php start.php start`
3. Visit `/app/admin/{entity}/index` to verify CRUD works
