# API Overview

> Full API reference (200+ endpoints, with request/response examples and error codes): [API Reference](api-reference.md)
> Online debugging: [service API docs](http://localhost:8787/apidoc) · [admin API docs](http://localhost:8788/apidoc)

## Public Endpoints

| Method | Path | Description |
|------|------|------|
| GET | `/health` | Health check |
| POST | `/api/v1/auth/register` | User registration (request body must be AES-256-GCM encrypted) |
| POST | `/api/v1/auth/login` | User login (request body must be AES-256-GCM encrypted) |
| POST | `/api/v1/auth/refresh` | Refresh token (request body must be AES-256-GCM encrypted) |
| POST | `/api/v1/captcha/create` | Generate click CAPTCHA (obtain before login/registration) |
| GET | `/api/v1/products` | Product list (supports category/region/keyword filtering) |
| GET | `/api/v1/products/{id}` | Product details (id is a hashid string) |
| GET | `/api/v1/regions` | Available regions |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Domain availability check |
| GET | `/api/v1/domain/tlds` | List of registrable TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe webhook callback (signature validation, no encryption required) |

## Authenticated Endpoints (Bearer Token Required)

| Method | Path | Description |
|------|------|------|
| GET | `/api/v1/user/profile` | Personal profile |
| PUT | `/api/v1/user/profile` | Update profile |
| POST | `/api/v1/user/kyc` | Submit KYC verification |
| GET | `/api/v1/user/balance` | Account balance |
| GET/POST | `/api/v1/cart` | Shopping cart |
| POST/GET | `/api/v1/orders` | Orders |
| GET | `/api/v1/orders/{id}/payment-methods` | Available payment methods |
| POST | `/api/v1/orders/{id}/pay` | Initiate payment |
| GET/POST | `/api/v1/resources` | My resources |
| GET | `/api/v1/resources/{id}/status` | Resource status |
| GET | `/api/v1/resources/{id}/console` | VNC console link |
| GET/POST | `/api/v1/cdn/domains` | CDN domain list / create (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | CDN domain details / delete |
| POST | `/api/v1/cdn/domains/{id}/purge` | Purge cache (idempotent, max 100 URLs) |
| GET/POST | `/api/v1/tickets` | Tickets |
| POST | `/api/v1/tickets/{id}/reply` | Ticket reply |
| GET/POST | `/api/v1/dns/{domain}` | DNS management |
| POST | `/api/v1/supplier/apply` | Supplier application |
| GET | `/api/v1/supplier/settlements` | Supplier settlement records |
| POST | `/api/v1/supplier/withdraw` | Supplier withdrawal |

> **Note:** The API version is in the URL path (e.g. `/api/v1/...`). Requests and responses for authenticated and admin endpoints are processed by `EncryptionMiddleware`. The client sets the `X-Encrypted: 1` header; the request body format is `{"payload": "<base64(AES-256-GCM)>"}`, and the response body is likewise encrypted and wrapped in the `payload` field. All integer IDs are automatically converted to 12-character Hashid strings in API responses, and Hashid strings in requests are automatically decoded back to integer IDs by `HashidRequestMiddleware`.

## Admin Endpoints

| Method | Path | Description |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Operations dashboard |
| GET/PUT | `/admin/api/v1/users` | User management |
| GET/POST | `/admin/api/v1/kyc` | KYC review |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Product management |
| POST | `/admin/api/v1/products/{productId}/skus` | Create SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Set regional price |
| GET/POST | `/admin/api/v1/orders` | Order management (incl. refunds) |
| GET | `/admin/api/v1/orders/export` | Order export (.xlsx) |
| GET | `/admin/api/v1/users/export` | User export (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Supplier export (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Payment channels / transactions / reconciliation |
| GET/POST | `/admin/api/v1/provisioning/*` | Provisioning tasks / host machine management |
| GET/PUT | `/admin/api/v1/cdn/domains` | CDN domain management (plan changes) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Provider account credential management (shared CDN/provisioning, Encryptable encrypted) |
| GET/POST | `/admin/api/v1/suppliers/*` | Supplier approval / settlement / withdrawal |
| GET/POST | `/admin/api/v1/tickets` | Ticket assignment / closure |
| GET | `/admin/api/v1/reports/*` | Revenue / regional / supplier reports |
| GET | `/app/admin/report/*` | Admin reports (order daily / product ranking / channel stats / user growth, Excel export) |
| GET | `/admin/api/v1/monitor/*` | Monitoring dashboard / resource metrics |
| GET | `/admin/api/v1/audit-logs` | Audit logs |
| PUT | `/admin/api/v1/system/config` | System configuration |
