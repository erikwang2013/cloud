# API Overview

> Full API reference (200+ endpoints, request/response examples and error codes): [API Reference](api-reference.md)
> Online debugging: [service API docs](http://localhost:8787/apidoc) · [admin API docs](http://localhost:8788/apidoc)

## Public Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Health check |
| POST | `/api/v1/auth/register` | Register (body AES-256-GCM encrypted) |
| POST | `/api/v1/auth/login` | Login (body AES-256-GCM encrypted) |
| POST | `/api/v1/auth/refresh` | Refresh token (body AES-256-GCM encrypted) |
| POST | `/api/v1/captcha/create` | Generate click CAPTCHA (required before login/register) |
| GET | `/api/v1/products` | Product listing (filterable by category/region/keyword) |
| GET | `/api/v1/products/{id}` | Product detail (id is a hashid string) |
| GET | `/api/v1/regions` | Available regions |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Domain availability check |
| GET | `/api/v1/domain/tlds` | Available TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe webhook (signature verified, no encryption) |

## Authenticated Endpoints (Bearer Token)

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

> **Note:** The API version is in the URL path (e.g. `/api/v1/...`). Authenticated and admin endpoints are processed by `EncryptionMiddleware`. Clients set `X-Encrypted: 1` header and wrap body as `{"payload": "<base64(AES-256-GCM)>"}`. Responses are likewise encrypted and wrapped in a `payload` field. Integer IDs in API responses are automatically converted to 12-character Hashid strings; Hashid strings in requests are decoded back to integer IDs by `HashidRequestMiddleware`.

## Admin Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Operations dashboard |
| GET/PUT | `/admin/api/v1/users` | User management |
| GET/POST | `/admin/api/v1/kyc` | KYC review |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Product management |
| POST | `/admin/api/v1/products/{productId}/skus` | Create SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Set regional price |
| GET/POST | `/admin/api/v1/orders` | Order management (incl. refunds) |
| GET | `/admin/api/v1/orders/export` | Export orders (.xlsx) |
| GET | `/admin/api/v1/users/export` | Export users (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Export suppliers (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Channels / transactions / reconciliation |
| GET/POST | `/admin/api/v1/provisioning/*` | Provisioning tasks / host management |
| GET/POST | `/admin/api/v1/suppliers/*` | Supplier approval / settlement / withdrawal |
| GET/POST | `/admin/api/v1/tickets` | Ticket assignment / closure |
| GET | `/admin/api/v1/reports/*` | Revenue / regional / supplier reports |
| GET | `/admin/api/v1/monitor/*` | Monitoring dashboard / resource metrics |
| GET | `/admin/api/v1/audit-logs` | Audit logs |
| PUT | `/admin/api/v1/system/config` | System config update |
