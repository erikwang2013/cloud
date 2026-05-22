---
name: project-overview
description: CloudPlatform architecture overview — webman, payment, notification, provisioning stack
---
# CloudPlatform — Project Overview

## Architecture

```
cloud-php/
├── admin/          # Admin panel (webman + Layui + Pear Admin)
│   ├── app/model/       # Eloquent models (wa_* admin + erik_* business)
│   ├── app/controller/  # Crud-based controllers
│   ├── app/view/        # Layui templates
│   ├── config/          # menu.php, database.php, etc.
│   └── generate.php     # Code generator
├── service/        # Backend API (webman)
│   ├── app/             # Business logic (Payment, Notification, Provisioning)
│   ├── common/          # Shared services (Hashid, Snowflake, Encryptable)
│   ├── config/          # Service configuration
│   └── database/        # Migrations
├── docs/           # Documentation + database.sql DDL
├── docker/         # Docker configuration
└── apps/           # Client applications
```

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | webman v2.1 (PHP) |
| ORM | illuminate/database v10 (Eloquent) |
| Search | erikwang2013/webman-scout (Elasticsearch) |
| IDs | Snowflake 64-bit distributed IDs |
| ID Obfuscation | Hashids (12-char) |
| Encryption | AES-128-ECB (maize-tech/laravel-encryptable) |
| Click CAPTCHA | erikwang2013/poster-php |
| Payments | Stripe PHP ^15.0 |
| SMS | Twilio PHP ^8.0 |
| Push | Firebase PHP ^7.0 (FCM) |
| Admin UI | Layui 2.8.12 + Pear Admin |
| Testing | PHPUnit 10.5/11.5 |

## Key Patterns

### Model → Controller → View
Each business entity follows a 3-file CRUD pattern:
- Model: `extends app\model\Base` (Snowflake PK, `$table`, `$fillable`)
- Controller: `extends app\controller\Crud` (select/insert/update/delete/export with hashids)
- Views: `index.html`, `insert.html`, `update.html` (Layui data tables + forms)

### ID Handling
- Snowflake generates 64-bit integers at application level (no auto-increment)
- Hashids encode/decode at HTTP boundary (APIs receive/produce hashids, DB stores ints)
- `hashids_encode()` / `hashids_decode()` helpers handle conversion

### Payment Flow
1. PaymentRouter filters channels by status, amount range, currency, region
2. Channel (e.g. StripeChannel) creates PaymentIntent with idempotency key
3. Webhook handler verifies signature, processes `payment_intent.succeeded`
4. bcmath for all financial calculations: `bcadd(bcmul($amount, $rate, 8), $fixed, 4)`

### Secondary Confirmation
- `ConfirmationMiddleware` requires password re-entry for 12 sensitive endpoints
- Rate-limited: 5 failures → 15-minute Redis lock (`confirm_lock:{userId}`)
- Applies to: payment, resource deletion, refunds, KYC approval, supplier settlement, DNS deletion
- All attempts (success/failure/lock) audit-logged to independent audit database
- `verifyPassword()` is a protected method — tests override it to avoid DB dependency

### Concurrency
- MySQL advisory locks (`GET_LOCK`/`RELEASE_LOCK`) for migrations
- Snowflake IDs guarantee uniqueness without coordination
- Idempotency keys prevent duplicate payment processing
