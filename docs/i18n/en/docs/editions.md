# Cloud Platform — Global Cloud Resource Trading Platform

A cloud resource trading platform for global users, supporting online purchase and automatic delivery of servers (VM), IP addresses, cloud disks, domains and other products. Self-operated physical machines are delivered through Proxmox VE virtualization, while third-party suppliers can also onboard and sell.


## Edition Overview

| | Lite | Standard | Full |
|---|:---:|:---:|:---:|
| **License** | Open source (MIT) | Commercial license | Commercial license |
| **Contact** | GitHub | erik@erik.xyz | erik@erik.xyz |
| **Use cases** | Personal projects/learning/small stores | Small-to-medium cloud providers | Large cloud platforms / multi-supplier |

---

## I. Feature Comparison

### 1.1 User System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Email/phone registration & login | ✅ | ✅ | ✅ |
| JWT authentication (Access + Refresh) | ✅ | ✅ | ✅ |
| Password reset | ✅ | ✅ | ✅ |
| Device fingerprint binding + token rotation | ❌ | ✅ | ✅ |
| Login lockout (5 failures lock 15min) | ❌ | ✅ | ✅ |
| Google OAuth login | ❌ | ✅ | ✅ |
| Apple Sign In | ❌ | ✅ | ✅ |
| TOTP two-factor + recovery codes | ❌ | ✅ | ✅ |
| Email verification | ❌ | ✅ | ✅ |
| SMS verification code | ❌ | ✅ | ✅ |
| Session management (view/revoke) | ✅ | ✅ | ✅ |
| GDPR account deletion | ✅ | ✅ | ✅ |
| Profile management | ✅ | ✅ | ✅ |
| KYC verification | ❌ | ✅ | ✅ |
| Address management | ❌ | ✅ | ✅ |
| Balance account | ❌ | ✅ | ✅ |
| New-IP login alert | ❌ | ✅ | ✅ |
| Client platform detection | ❌ | ✅ | ✅ |
| Multilingual i18n (120 entries) | ✅ | ✅ | ✅ |

### 1.2 Product System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Product list (category/region filtering) | ✅ | ✅ | ✅ |
| Product details (incl. SKU + regional pricing) | ✅ | ✅ | ✅ |
| Elasticsearch full-text search | ✅ | ✅ | ✅ |
| Product reviews (rating + content) | ✅ | ✅ | ✅ |
| Product attributes | ❌ | ✅ | ✅ |
| Click CAPTCHA | ❌ | ✅ | ✅ |
| Bulk import/export (CSV) | ❌ | ✅ | ✅ |

### 1.3 Order System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Shopping cart (CRUD) | ✅ | ✅ | ✅ |
| Place order | ✅ | ✅ | ✅ |
| Order list + details | ✅ | ✅ | ✅ |
| Coupons | ❌ | ✅ | ✅ |
| Invoices (generation + PDF download) | ❌ | ✅ | ✅ |
| Refunds | ❌ | ✅ | ✅ |

### 1.4 Payment System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Stripe payments | ❌ | ✅ | ✅ |
| Multi-channel routing | ❌ | ✅ | ✅ |
| Webhook signature validation | ❌ | ✅ | ✅ |
| Daily reconciliation | ❌ | ✅ | ✅ |
| Multi-currency exchange rates | ❌ | ✅ | ✅ |
| Refund to original method | ❌ | ✅ | ✅ |

### 1.5 Resource Delivery

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Proxmox VE virtualization | ❌ | ✅ | ✅ |
| Full server (VM) lifecycle | ❌ | ✅ | ✅ |
| Cloud disks (create/resize) | ❌ | ✅ | ✅ |
| IP pool management + allocation | ❌ | ✅ | ✅ |
| Host selection strategy (load balancing) | ❌ | ✅ | ✅ |
| Online CPU/memory/disk upgrade | ❌ | ✅ | ✅ |
| VNC console | ❌ | ✅ | ✅ |
| Async provisioning queue | ❌ | ✅ | ✅ |
| Retry strategy (6 attempts with backoff) | ❌ | ✅ | ✅ |
| Provider plugin architecture | ❌ | ✅ | ✅ |
| Resource expiry monitoring | ❌ | ✅ | ✅ |

### 1.6 Domains and DNS

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Domain availability check | ❌ | ✅ | ✅ |
| TLD pricing management | ❌ | ✅ | ✅ |
| DNS record management | ❌ | ✅ | ✅ |
| Domain transfer approval | ❌ | ✅ | ✅ |

### 1.7 Ticket System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Create/reply tickets | ❌ | ✅ | ✅ |
| Ticket list + details | ❌ | ✅ | ✅ |
| Support staff assignment | ❌ | ✅ | ✅ |
| SLA tracking | ❌ | ✅ | ✅ |
| Auto-assignment (load balancing) | ❌ | ✅ | ✅ |

### 1.8 Notification System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Email notifications | ❌ | ✅ | ✅ |
| SMS notifications (Twilio) | ❌ | ✅ | ✅ |
| App Push (FCM) | ❌ | ✅ | ✅ |
| In-app messages | ❌ | ✅ | ✅ |
| Notification template management | ❌ | ✅ | ✅ |
| User notification preferences | ❌ | ✅ | ✅ |

### 1.9 Admin Panel

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ |
| User management (list/detail/status) | ✅ | ✅ | ✅ |
| Product management (CRUD) | ✅ | ✅ | ✅ |
| Order management (list/detail) | ✅ | ✅ | ✅ |
| Audit logs | ✅ | ✅ | ✅ |
| KYC review | ❌ | ✅ | ✅ |
| SKU + regional pricing management | ❌ | ✅ | ✅ |
| Payment channel management + transaction records | ❌ | ✅ | ✅ |
| Resource provisioning task monitoring | ❌ | ✅ | ✅ |
| Host machine management | ❌ | ✅ | ✅ |
| Ticket assignment/closure | ❌ | ✅ | ✅ |
| Domain TLD + DNS zone management | ❌ | ✅ | ✅ |
| Notification template management | ❌ | ✅ | ✅ |
| Coupon management | ❌ | ✅ | ✅ |
| Help article management | ❌ | ✅ | ✅ |
| Webhook management | ❌ | ✅ | ✅ |
| Cloud provider API management | ❌ | ✅ | ✅ |
| Product import/export | ❌ | ✅ | ✅ |
| User/order/supplier export | ❌ | ✅ | ✅ |
| Reports (revenue/regional) | ❌ | ✅ | ✅ |
| Monitoring dashboard + resource metrics | ❌ | ✅ | ✅ |
| Supplier management | ❌ | ❌ | ✅ |
| Supplier API Key management | ❌ | ❌ | ✅ |
| Feature Flags dynamic toggles | ❌ | ❌ | ✅ |

### 1.10 Supplier System

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Supplier onboarding + approval | ❌ | ❌ | ✅ |
| Product listing + commissions | ❌ | ❌ | ✅ |
| Settlements (weekly/monthly) | ❌ | ❌ | ✅ |
| Withdrawal requests + approval | ❌ | ❌ | ✅ |
| External API (API Key authentication) | ❌ | ❌ | ✅ |
| Supplier data isolation | ❌ | ❌ | ✅ |

### 1.11 Real-Time Communication

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| WebSocket real-time push | ❌ | ❌ | ✅ |
| Sentry exception monitoring | ❌ | ❌ | ✅ |
| k6 load testing scripts | ❌ | ✅ | ✅ |


### 1.12 SSL Certificates

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| SSL certificate purchase (DV/OV/EV) | ❌ | ❌ | ✅ |
| Let's Encrypt auto-issuance | ❌ | ❌ | ✅ |
| Auto-renewal (14 days before expiry) | ❌ | ❌ | ✅ |
| Certificate download (PEM/KEY) | ❌ | ❌ | ✅ |
| SSL plan management (admin) | ❌ | ❌ | ✅ |

### 1.13 Object Storage

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| S3-compatible object storage | ❌ | ❌ | ✅ |
| MinIO self-hosted storage | ❌ | ❌ | ✅ |
| Presigned upload/download URLs | ❌ | ❌ | ✅ |
| Storage quota management | ❌ | ❌ | ✅ |

### 1.14 CDN Acceleration

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| CDN domain management | ❌ | ❌ | ✅ |
| Cache purge | ❌ | ❌ | ✅ |
| Origin types (server/storage) | ❌ | ❌ | ✅ |
| Cloudflare integration | ❌ | ❌ | ✅ |

### 1.15 Usage-Based Billing

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Per-hour/traffic billing | ❌ | ❌ | ✅ |
| Usage collection and aggregation | ❌ | ❌ | ✅ |
| Automatic balance deduction | ❌ | ❌ | ✅ |
| Resource suspend/resume on arrears | ❌ | ❌ | ✅ |

### 1.16 Supplier Ratings

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Four-dimension rating (quality/support/delivery/value) | ❌ | ❌ | ✅ |
| Purchased-user restriction | ❌ | ❌ | ✅ |
| Rating review (admin) | ❌ | ❌ | ✅ |
| Supplier average score display | ❌ | ❌ | ✅ |

### 1.17 Referral Distribution

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Referral link generation | ❌ | ❌ | ✅ |
| Order attribution (ref parameter) | ❌ | ❌ | ✅ |
| Commission calculation and withdrawal | ❌ | ❌ | ✅ |
| Distribution plan management (admin) | ❌ | ❌ | ✅ |

### 1.18 GraphQL

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| GraphQL endpoint (public + authenticated) | ❌ | ❌ | ✅ |
| Product/order/resource queries | ❌ | ❌ | ✅ |
| Query depth limiting | ❌ | ❌ | ✅ |

### 1.19 Observability

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Prometheus metrics export | ❌ | ❌ | ✅ |
| Grafana pre-built dashboards | ❌ | ❌ | ✅ |
| Alert rules (queue/error rate/latency) | ❌ | ❌ | ✅ |
| Health checks (live/ready/deps) | ❌ | ❌ | ✅ |
| i18n 7 languages (550+ entries) | ❌ | ❌ | ✅ |

### 1.12 Clients

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Flutter client | ❌ | ❌ | ✅ |
| HarmonyOS client | ❌ | ❌ | ✅ |

---

## II. Architecture Design Comparison

### 2.1 Middleware

| Middleware | Lite | Standard | Full |
|--------|:---:|:---:|:---:|
| CorsMiddleware (CORS) | ✅ | ✅ | ✅ |
| LocaleMiddleware (multilingual) | ✅ | ✅ | ✅ |
| HashidRequestMiddleware (ID decoding) | ✅ | ✅ | ✅ |
| AuthMiddleware (JWT authentication) | ✅ | ✅ | ✅ |
| RateLimitMiddleware (rate limiting) | ✅ | ✅ | ✅ |
| WafMiddleware basic (SQLi/XSS) | ✅ | ✅ | ✅ |
| WafMiddleware full (8 categories 45+ rules) | ❌ | ✅ | ✅ |
| AdminRoleMiddleware (RBAC) | ❌ | ✅ | ✅ |
| EncryptionMiddleware (AES-256-GCM) | ❌ | ✅ | ✅ |
| VersionMiddleware (API version) | ❌ | ✅ | ✅ |
| ClientPlatformMiddleware (platform detection) | ❌ | ✅ | ✅ |
| ConfirmationMiddleware (password confirmation) | ❌ | ✅ | ✅ |
| GeoBlockMiddleware (geo-blocking) | ❌ | ✅ | ✅ |
| MaintenanceMiddleware (maintenance mode) | ❌ | ✅ | ✅ |
| SupplierApiKeyMiddleware | ❌ | ❌ | ✅ |
| FeatureFlags | ❌ | ❌ | ✅ |
| RbacMiddleware | ❌ | ✅ | ✅ |

### 2.2 Data Architecture

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Snowflake distributed primary keys | ✅ | ✅ | ✅ |
| Hashids ID obfuscation | ✅ | ✅ | ✅ |
| MySQL single database | ✅ | ❌ | ❌ |
| MySQL master-slave read/write splitting | ❌ | ✅ | ✅ |
| Separate audit database | ❌ | ✅ | ✅ |
| AES-256-GCM transport encryption | ❌ | ✅ | ✅ |
| AES-128-ECB field encryption | ❌ | ✅ | ✅ |
| Redis multi-level cache | ❌ | ✅ | ✅ |
| Elasticsearch full-text search | ✅ | ✅ | ✅ |
| Database index optimization (13) | ❌ | ✅ | ✅ |

### 2.3 Security Protection

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| SQL injection detection (2 rules) | ✅ | ✅ | ✅ |
| XSS detection (3 rules) | ✅ | ✅ | ✅ |
| Command injection detection | ❌ | ✅ | ✅ |
| File inclusion detection | ❌ | ✅ | ✅ |
| HTTP header injection detection | ❌ | ✅ | ✅ |
| SSRF detection | ❌ | ✅ | ✅ |
| NoSQL injection detection | ❌ | ✅ | ✅ |
| Open redirect detection | ❌ | ✅ | ✅ |
| Request body size limit | ❌ | ✅ | ✅ |
| Content-Type whitelist | ❌ | ✅ | ✅ |

### 2.4 High Concurrency

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| webman multi-process | ✅ | ✅ | ✅ |
| Nginx gzip compression | ❌ | ✅ | ✅ |
| Nginx proxy buffering | ❌ | ✅ | ✅ |
| Nginx limit_req/limit_conn | ❌ | ✅ | ✅ |
| Redis cache layer | ❌ | ✅ | ✅ |
| Proactive cache invalidation | ❌ | ✅ | ✅ |
| MySQL read/write splitting | ❌ | ✅ | ✅ |
| Database composite indexes | ❌ | ✅ | ✅ |
| WebSocket push | ❌ | ❌ | ✅ |

---

## III. Deployment and Operations

| Feature | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Docker Compose deployment | ✅ | ✅ | ✅ |
| Nginx reverse proxy | ✅ | ✅ | ✅ |
| CI/CD (GitHub Actions) | ✅ | ✅ | ✅ |
| PHPUnit tests | 95 tests | 295 tests | 295 tests |
| Cron jobs (7) | ❌ | ✅ | ✅ |
| Redis Queue async processing | ❌ | ✅ | ✅ |
| Database migration commands | ✅ | ✅ | ✅ |
| Database backup commands | ❌ | ✅ | ✅ |
| Health check endpoints | ✅ | ✅ | ✅ |
| Service status endpoints | ✅ | ✅ | ✅ |
| Sentry exception monitoring | ❌ | ❌ | ✅ |
| Feature Flags canary release | ❌ | ❌ | ✅ |
| k6 load testing | ❌ | ❌ | ✅ |

---

## IV. Statistics

| Metric | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| API endpoints | ~35 | ~130 | 200+ |
| Data models | 15 | 50+ | 70+ |
| Database tables | 15 | 50+ | 60+ |
| Global middleware | 3 | 7 | 9 |
| Route middleware | 1 | 5 | 6 |
| Cron jobs | 0 | 7 | 10 |
| Migration files | 5 | 20 | 27 |
| Tests | 95 | 295 | 295 |
| WAF rules | 5 | 45+ | 45+ |
| Docs | 2 | 6 | 8 |
| hg/apidoc online docs | ✅ | ✅ | ✅ |
| GraphQL API endpoints | ❌ | ❌ | ✅ |
| Prometheus metrics | ❌ | ❌ | ✅ |
| Supplier rating system | ❌ | ❌ | ✅ |
| Affiliate referral system | ❌ | ❌ | ✅ |

---

## V. Upgrade Path

```
Lite
  │
  │  + payments + delivery + domains + tickets + notifications
  │  + full admin panel + full security + high-concurrency optimization
  ▼
Standard
  │
  │  + supplier system + external API + WebSocket
  │  + Sentry + Feature Flags + Flutter client
  ▼
Full
```

**Data compatibility:** the Lite database schema is compatible with the Standard core tables and can be migrated/upgraded directly. Standard to Full is purely incremental (new supplier-related tables added), no data migration required.

---

## VI. How to Get

| Edition | How to get |
|------|---------|
| **Lite** | Open source on GitHub, MIT license |
| **Standard** | Commercial license, contact **erik@erik.xyz** |
| **Full** | Commercial license, contact **erik@erik.xyz** |
