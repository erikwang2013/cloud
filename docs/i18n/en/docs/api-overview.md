# API Overview

> Full API reference (200+ endpoints, with request/response examples and error codes): [API Reference](api-reference.md)
> Online debugging: [service API docs](http://localhost:8787/apidoc) · [admin API docs](http://localhost:8788/apidoc)

## Public Endpoints

| Method | Path | Description |
|------|------|------|
| GET | `/health` | Health check |
| POST | `/api/auth/register` | User registration (request body must be AES-256-GCM encrypted) |
| POST | `/api/auth/login` | User login (request body must be AES-256-GCM encrypted) |
| POST | `/api/auth/refresh` | Refresh token (request body must be AES-256-GCM encrypted) |
| POST | `/api/captcha/create` | Generate click CAPTCHA (obtain before login/registration) |
| GET | `/api/products` | Product list (supports category/region/keyword filtering) |
| GET | `/api/products/{id}` | Product details (id is a hashid string) |
| GET | `/api/regions` | Available regions |
| GET | `/api/domain/check/{domain}/{tld}` | Domain availability check |
| GET | `/api/domain/tlds` | List of registrable TLDs |
| POST | `/api/payments/webhook/stripe` | Stripe webhook callback (signature validation, no encryption required) |

## Authenticated Endpoints (Bearer Token Required)

| Method | Path | Description |
|------|------|------|
| GET | `/api/user/profile` | Personal profile |
| PUT | `/api/user/profile` | Update profile |
| POST | `/api/user/kyc` | Submit KYC verification |
| GET | `/api/user/balance` | Account balance |
| GET/POST | `/api/cart` | Shopping cart |
| POST/GET | `/api/orders` | Orders |
| GET | `/api/orders/{id}/payment-methods` | Available payment methods |
| POST | `/api/orders/{id}/pay` | Initiate payment |
| GET/POST | `/api/resources` | My resources |
| GET | `/api/resources/{id}/status` | Resource status |
| GET | `/api/resources/{id}/console` | VNC console link |
| GET/POST | `/api/tickets` | Tickets |
| POST | `/api/tickets/{id}/reply` | Ticket reply |
| GET/POST | `/api/dns/{domain}` | DNS management |
| POST | `/api/supplier/apply` | Supplier application |
| GET | `/api/supplier/settlements` | Supplier settlement records |
| POST | `/api/supplier/withdraw` | Supplier withdrawal |

> **Note:** All API requests must carry the `X-Api-Version: v1` header (defaults to `v1` when missing, validated by `VersionMiddleware`). Requests and responses for authenticated and admin endpoints are processed by `EncryptionMiddleware`. The client sets the `X-Encrypted: 1` header; the request body format is `{"payload": "<base64(AES-256-GCM)>"}`, and the response body is likewise encrypted and wrapped in the `payload` field. All integer IDs are automatically converted to 12-character Hashid strings in API responses, and Hashid strings in requests are automatically decoded back to integer IDs by `HashidRequestMiddleware`.

## Admin Endpoints

| Method | Path | Description |
|------|------|------|
| GET | `/admin/api/dashboard` | Operations dashboard |
| GET/PUT | `/admin/api/users` | User management |
| GET/POST | `/admin/api/kyc` | KYC review |
| GET/POST/PUT/DELETE | `/admin/api/products` | Product management |
| POST | `/admin/api/products/{productId}/skus` | Create SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Set regional price |
| GET/POST | `/admin/api/orders` | Order management (incl. refunds) |
| GET | `/admin/api/orders/export` | Order export (.xlsx) |
| GET | `/admin/api/users/export` | User export (.xlsx) |
| GET | `/admin/api/suppliers/export` | Supplier export (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Payment channels / transactions / reconciliation |
| GET/POST | `/admin/api/provisioning/*` | Provisioning tasks / host machine management |
| GET/POST | `/admin/api/suppliers/*` | Supplier approval / settlement / withdrawal |
| GET/POST | `/admin/api/tickets` | Ticket assignment / closure |
| GET | `/admin/api/reports/*` | Revenue / regional / supplier reports |
| GET | `/admin/api/monitor/*` | Monitoring dashboard / resource metrics |
| GET | `/admin/api/audit-logs` | Audit logs |
| PUT | `/admin/api/system/config` | System configuration |
