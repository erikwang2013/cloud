# CloudPlatform — Global Cloud Resource Marketplace

A cloud resource trading platform serving global users. Supports purchasing servers (VM), IP addresses, cloud disks, and domains with automatic provisioning. Self-operated bare-metal servers are virtualized via Proxmox VE, while third-party suppliers can onboard and sell through the marketplace.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| ORM | Illuminate/Eloquent 10.x |
| Auth | JWT RS256 (firebase/php-jwt) |
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
│   │   ├── Auth/              # JWT middleware / RBAC
│   │   ├── Helper/            # Response formatting
│   │   ├── I18n/              # Internationalization
│   │   └── Security/          # CORS / WAF / rate limiting / audit logging
│   ├── config/                # Routes / middleware / logging / database / queue
│   └── support/               # Bootstrap (Eloquent / Events / env)
├── apps/
│   ├── flutter/               # Flutter client (PC-first web layout)
│   └── harmonyos/             # HarmonyOS client skeleton
├── docker/                    # Dockerfile / docker-compose / nginx / supervisor
└── docs/                      # Design docs / implementation plans
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
# Edit .env with your database password, JWT keys, etc.

# 3. Create databases
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Start (dev mode)
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
| POST | `/api/v1/auth/register` | Register |
| POST | `/api/v1/auth/login` | Login |
| POST | `/api/v1/auth/refresh` | Refresh token |
| GET | `/api/v1/products` | Product listing (filterable by category/region/keyword) |
| GET | `/api/v1/products/{id}` | Product detail |
| GET | `/api/v1/regions` | Available regions |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Domain availability check |
| GET | `/api/v1/domain/tlds` | Available TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe webhook |

### Authenticated Endpoints (Bearer Token)
| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Get profile |
| PUT | `/api/v1/user/profile` | Update profile |
| POST | `/api/v1/user/kyc` | Submit KYC |
| GET | `/api/v1/user/balance` | Account balance |
| GET/POST | `/api/v1/cart` | Shopping cart |
| POST/GET | `/api/v1/orders` | Orders |
| GET/POST | `/api/v1/resources` | My resources |
| GET/POST | `/api/v1/tickets` | Support tickets |
| GET/POST | `/api/v1/dns/{domain}` | DNS management |
| POST | `/api/v1/supplier/apply` | Apply as supplier |

### Admin Endpoints
| Method | Path | Description |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Operations dashboard |
| GET/PUT | `/admin/api/v1/users` | User management |
| GET/POST | `/admin/api/v1/kyc` | KYC review |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Product management |
| GET/POST | `/admin/api/v1/orders` | Order management (incl. refunds) |
| GET/PUT | `/admin/api/v1/payments/*` | Channels / transactions / reconciliation |
| GET | `/admin/api/v1/provisioning/*` | Provisioning tasks / host management |
| GET/POST | `/admin/api/v1/suppliers/*` | Supplier approval / settlement |
| GET/POST | `/admin/api/v1/tickets` | Ticket assignment / closure |
| GET | `/admin/api/v1/reports/*` | Revenue / regional / supplier reports |
| GET | `/admin/api/v1/monitor/*` | Monitoring dashboard / resource metrics |

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

Global middleware pipeline: `CORS → WAF → Locale → Auth (authenticated routes) → RBAC (admin routes)`

- **WAF** — Blocks SQL injection / XSS / path traversal attacks
- **Rate Limiting** — Registration 5/hour, login 10/hour (per IP)
- **JWT RS256** — Access Token (15 min) + Refresh Token (30 days, with token rotation)
- **Audit Logging** — All sensitive operations written to a separate audit database

### 6. Internationalization

- Product names / descriptions stored as JSON `{"en": "...", "zh": "..."}`. API returns content matching the `Accept-Language` header.
- Notification templates support multiple languages and dispatch in the user's preferred language.
- Flutter client sends locale via an Interceptor.

## Roadmap

- [ ] Database migration scripts (DDL generation from models)
- [ ] Stripe production integration (currently mocked)
- [ ] Twilio / Alibaba Cloud SMS production integration
- [ ] FCM push notification production integration
- [ ] Unit and integration tests
- [ ] CI/CD pipeline

## License

Proprietary — All rights reserved.
