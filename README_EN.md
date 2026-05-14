# CloudPlatform — Global Cloud Resource Marketplace

A cloud resource trading platform serving global users. Supports purchasing servers (VM), IP addresses, cloud disks, and domains with automatic provisioning. Self-operated bare-metal servers are virtualized via Proxmox VE, while third-party suppliers can onboard and sell through the marketplace.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| ORM | Illuminate/Eloquent 10.x |
| Auth | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| Distributed PK | Snowflake ID ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| ID Obfuscation | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| Transport Encryption | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| Field Encryption | AES-128-ECB ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| Queue | webman redis-queue |
| Database | MySQL 8.0 (main + audit dual connection) |
| Virtualization | Proxmox VE REST API |
| Clients | Flutter (iOS / Android / Web PC) + HarmonyOS ArkTS |
| Deployment | Docker Compose |

## Directory Structure

```
cloud-php/
├── service/                   # Backend service
│   ├── app/                   # Business modules (PSR-4: App\)
│   │   ├── Admin/             # Admin panel controllers
│   │   ├── Controller/        # Health check
│   │   ├── Domain/            # Domain registration / DNS management
│   │   ├── Monitor/           # Resource monitoring / alert engine / cron jobs
│   │   ├── Notification/      # Notifications (in-app / email / SMS / push)
│   │   ├── Order/             # Shopping cart / orders
│   │   ├── Payment/           # Payment routing / Stripe channel / webhooks
│   │   ├── Product/           # Products / SKUs / regional pricing
│   │   ├── Provisioning/      # Resource delivery engine / Proxmox provider
│   │   ├── Report/            # Revenue / supplier / regional reports
│   │   ├── Supplier/          # Supplier onboarding / settlement / withdrawal
│   │   ├── Ticket/            # Support tickets / SLA auto-assignment
│   │   └── User/              # Users / auth / KYC / balances
│   ├── common/                # Shared libraries (PSR-4: Common\)
│   │   ├── Auth/              # JWT authentication / middleware
│   │   ├── Encryption/        # Transport encryption middleware (AES-256-GCM) / service
│   │   ├── Hashid/            # Hashids request middleware / encode-decode service
│   │   ├── Helper/            # Response formatting (auto hashid encoding)
│   │   ├── I18n/              # Internationalization
│   │   ├── Security/          # CORS / WAF / rate limiting / audit logging
│   │   └── Snowflake/         # Snowflake ID service / Eloquent model trait
│   ├── config/                # 17 configs: routes / middleware / logging / DB / queue / crypto
│   └── support/               # Bootstrap (Eloquent / Events / encryption / snowflake / hashids init)
├── apps/
│   ├── flutter/               # Flutter client (PC-first web layout)
│   └── harmonyos/             # HarmonyOS client skeleton
├── docker/                    # Dockerfile / docker-compose / nginx / supervisor
├── docs/                      # Database DDL / design docs / implementation plans
└── README*.md                 # Project documentation
```

## Quick Start

### Prerequisites

- PHP 8.2+ (ext-json, ext-pdo, ext-redis)
- MySQL 8.0
- Redis 7

### Local Development

```bash
cd service

# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env with database password, JWT key, encryption keys, etc.
# ENCRYPTION_MASTER_KEY: openssl rand -base64 32
# ENCRYPTION_KEY:       echo -n "$(openssl rand -base64 16)" | base64 -w0
# JWT_SECRET_KEY:       openssl rand -base64 32

# 3. Create databases
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Import schema
mysql -u root -p cloud_platform < ../docs/database.sql
mysql -u root -p cloud_platform_audit < ../docs/database_audit.sql

# 5. Start (dev mode)
php start.php start
# Visit http://localhost:8787
```

### Docker Deployment

```bash
# From project root
cp service/.env.example .env
# Edit .env with required keys

docker compose -f docker/docker-compose.yml up -d
# API available at http://localhost
```

### Daemon Mode

```bash
php start.php start -d          # Start as daemon
php start.php status            # Check status
php start.php restart           # Restart
php start.php stop              # Stop
```

## API Overview

### Public Endpoints
| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Health check |
| POST | `/api/v1/auth/register` | Register (body AES-256-GCM encrypted) |
| POST | `/api/v1/auth/login` | Login (body AES-256-GCM encrypted) |
| POST | `/api/v1/auth/refresh` | Refresh token (body AES-256-GCM encrypted) |
| GET | `/api/v1/products` | Product listing (filterable by category/region/keyword) |
| GET | `/api/v1/products/{id}` | Product detail (id is a hashid string) |
| GET | `/api/v1/regions` | Available regions |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Domain availability check |
| GET | `/api/v1/domain/tlds` | Available TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe webhook (signature verified, no encryption) |

### Authenticated Endpoints (Bearer Token)
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Get profile |
| PUT | `/api/v1/user/profile` | Update profile |
| POST | `/api/v1/user/kyc` | Submit KYC |
| GET | `/api/v1/user/balance` | Account balance |
| GET/POST | `/api/v1/cart` | Shopping cart |
| POST/GET | `/api/v1/orders` | Orders |
| GET | `/api/v1/orders/{id}/payment-methods` | Available payment methods |
| POST | `/api/v1/orders/{id}/pay` | Initiate payment |
| GET/POST | `/api/v1/resources` | My resources |
| GET | `/api/v1/resources/{id}/status` | Resource status |
| GET | `/api/v1/resources/{id}/console` | VNC console URL |
| GET/POST | `/api/v1/tickets` | Support tickets |
| POST | `/api/v1/tickets/{id}/reply` | Reply to ticket |
| GET/POST | `/api/v1/dns/{domain}` | DNS management |
| POST | `/api/v1/supplier/apply` | Apply as supplier |
| GET | `/api/v1/supplier/settlements` | Settlement history |
| POST | `/api/v1/supplier/withdraw` | Request withdrawal |

> **Note:** Authenticated and admin endpoints are processed by `EncryptionMiddleware`. Clients set `X-Encrypted: 1` header and wrap body as `{"payload": "<base64(AES-256-GCM)>"}`. Responses are likewise encrypted and wrapped in a `payload` field. Integer IDs in API responses are automatically converted to 12-character Hashid strings; Hashid strings in requests are decoded back to integer IDs by `HashidRequestMiddleware`.

### Admin Endpoints
| Method | Path | Description |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Operations dashboard |
| GET/PUT | `/admin/api/v1/users` | User management |
| GET/POST | `/admin/api/v1/kyc` | KYC review |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Product management |
| POST | `/admin/api/v1/products/{productId}/skus` | Create SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Set regional price |
| GET/POST | `/admin/api/v1/orders` | Order management (incl. refunds) |
| GET/PUT | `/admin/api/v1/payments/*` | Channels / transactions / reconciliation |
| GET/POST | `/admin/api/v1/provisioning/*` | Provisioning tasks / host management |
| GET/POST | `/admin/api/v1/suppliers/*` | Supplier approval / settlement / withdrawal |
| GET/POST | `/admin/api/v1/tickets` | Ticket assignment / closure |
| GET | `/admin/api/v1/reports/*` | Revenue / regional / supplier reports |
| GET | `/admin/api/v1/monitor/*` | Monitoring dashboard / resource metrics |
| GET | `/admin/api/v1/audit-logs` | Audit logs |
| PUT | `/admin/api/v1/system/config` | System config update |

## Design Philosophy

### 1. Modular Monolith

Modules are vertically sliced by business domain (User / Product / Order / Payment / Provisioning / Ticket / Notification). Each module follows MVC layering:

- **Controller** — HTTP layer: validates input, calls Service, returns Response
- **Service** — Business logic: no HTTP dependency, reusable by Controllers and Queue Workers
- **Model** — Eloquent models: defines relationships and query scopes

Modules communicate through **events** and **interfaces**, avoiding direct Service calls across modules. Example: payment confirmed → `OrderPaid` event → `ProvisioningService` automatically provisions resources. Ticket created → `TicketCreated` event → auto-assigns support staff.

### 2. Event-Driven Provisioning

```
User places order → Payment succeeds → OrderPaid event
  → ProvisioningService.handleOrderPaid()
    → Creates ProvisionTask per OrderItem (status=pending)
    → Redis Queue consumer: ProvisionWorker
      → ProviderFactory.create(task) resolves the provider
      → ProxmoxProvider.create()
        → HostSelector picks least-loaded physical host
        → ProxmoxApi creates VM / attaches disk / allocates IP
        → Creates Resource / Disk records
      → Updates Order status to completed
```

Failed deliveries retry with exponential backoff: 1min → 5min → 15min → 1h → 6h → 24h. After 6 retries, the task is marked failed and an alert is triggered.

### 3. Provider Plugin Architecture

Resource delivery is abstracted through `ProviderInterface`. Different infrastructure providers implement the same interface:

```
ProviderInterface
  ├── ProxmoxProvider    (self-operated Proxmox VE)
  ├── AliyunProvider     (future: Alibaba Cloud)
  ├── AwsProvider        (future: AWS EC2)
  └── DomainProvider     (future: domain registrars)
```

`ProviderFactory` registers factory functions keyed by `productType:provider`. The runtime resolves providers dynamically from ProvisionTask data.

### 4. Multi-Payment Routing

`PaymentRouter` dynamically returns available payment channels based on order amount / currency / region. Frontend switches channels to initiate payment. Channels are configured in the `PaymentChannel` table (fee rate, min/max amount, visible regions) — channels can be toggled without code changes.

### 5. Security Architecture

Global middleware pipeline: `CORS → WAF → Locale → HashidRequest → [Route: Encryption → Auth]`

- **CORS** — Cross-origin request headers
- **WAF** — Blocks SQL injection / XSS / path traversal attacks
- **Locale** — Parses Accept-Language, sets locale
- **HashidRequest** — Auto-decodes hashid strings in requests to real integer IDs
- **Encryption** — AES-256-GCM transport encryption (auth + admin routes), prevents MITM eavesdropping and tampering
- **Auth** — JWT HS256, Access Token 15 min, Refresh Token 30 days, Redis blacklist
- **Rate Limiting** — Default 60/min, login 5/min, register 3/min, payment 10/min
- **Audit Logging** — All sensitive operations written to a separate audit database

### 6. Data Security

**Layered encryption strategy:**

| Layer | Technology | Description |
|-------|-----------|-------------|
| Transport | AES-256-GCM | API request/response body encryption, GCM provides authenticated encryption |
| Field | AES-128-ECB | Auto encrypt/decrypt sensitive model attributes, ECB is deterministic (queryable) |
| Primary Key | Hashids | External IDs obfuscated to 12-char strings, hides true data scale |

**Encrypted fields:** 14 fields across 7 models use `Encryptable::class` auto-casting — `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**Key management:** Transport and field encryption use independent keys (`ENCRYPTION_MASTER_KEY` vs `ENCRYPTION_KEY`). Previous key list (`ENCRYPTION_PREVIOUS_KEYS`) enables zero-downtime key rotation.

### 7. Distributed ID Generation

Twitter Snowflake algorithm generates 64-bit globally unique IDs: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. All 38 Eloquent models auto-generate Snowflake IDs on the `creating` event. No database auto-increment dependency — natively supports sharding.

### 8. Internationalization

- Product names / descriptions stored as JSON `{"en": "...", "zh": "..."}`. API returns content matching the `Accept-Language` header.
- Notification templates support multiple languages and dispatch in the user's preferred language.
- Flutter client sends locale via an Interceptor.

## Roadmap

- [x] Database DDL (`docs/database.sql`, 39 tables, erik_ prefix, BigInt non-auto-increment PKs)
- [x] Snowflake ID generation (`erikwang2013/snowflake-php`)
- [x] JWT authentication (`erikwang2013/jwt-webman`, HS256 + Redis blacklist)
- [x] API ID obfuscation (`erikwang2013/hashids`, auto decode requests + auto encode responses)
- [x] Transport encryption (`erikwang2013/encryption`, AES-256-GCM middleware)
- [x] Field-level encryption (`erikwang2013/encryptable`, auto encrypt/decrypt sensitive fields)
- [ ] Database migration scripting (`docs/database.sql` ready, pending migration command)
- [ ] Stripe production integration (currently mocked)
- [ ] Twilio / Alibaba Cloud SMS production integration
- [ ] FCM push notification production integration
- [ ] Unit and integration tests
- [ ] CI/CD pipeline

## License

Proprietary — All rights reserved.
