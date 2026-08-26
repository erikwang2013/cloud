# API Overview

> Full API reference (200+ endpoints, request/response examples and error codes): [API Reference](api-reference.md)
> Online debugging: [service API docs](http://localhost:8787/apidoc) · [admin API docs](http://localhost:8788/apidoc)

## Public Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Health check |
| POST | `/api/auth/register` | Register (body AES-256-GCM encrypted) |
| POST | `/api/auth/login` | Login (body AES-256-GCM encrypted) |
| POST | `/api/auth/refresh` | Refresh token (body AES-256-GCM encrypted) |
| POST | `/api/captcha/create` | Generate click CAPTCHA (required before login/register) |
| GET | `/api/products` | Product listing (filterable by category/region/keyword) |
| GET | `/api/products/{id}` | Product detail (id is a hashid string) |
| GET | `/api/regions` | Available regions |
| GET | `/api/domain/check/{domain}/{tld}` | Domain availability check |
| GET | `/api/domain/tlds` | Available TLDs |
| POST | `/api/payments/webhook/stripe` | Stripe webhook (signature verified, no encryption) |

## Authenticated Endpoints (Bearer Token)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/user/profile` | Get profile |
| PUT | `/api/user/profile` | Update profile |
| POST | `/api/user/kyc` | Submit KYC |
| GET | `/api/user/balance` | Account balance |
| GET/POST | `/api/cart` | Shopping cart |
| POST/GET | `/api/orders` | Orders |
| GET | `/api/orders/{id}/payment-methods` | Available payment methods |
| POST | `/api/orders/{id}/pay` | Initiate payment |
| GET/POST | `/api/resources` | My resources |
| GET | `/api/resources/{id}/status` | Resource status |
| GET | `/api/resources/{id}/console` | VNC console URL |
| GET/POST | `/api/tickets` | Support tickets |
| POST | `/api/tickets/{id}/reply` | Reply to ticket |
| GET/POST | `/api/dns/{domain}` | DNS management |
| POST | `/api/supplier/apply` | Apply as supplier |
| GET | `/api/supplier/settlements` | Settlement history |
| POST | `/api/supplier/withdraw` | Request withdrawal |

> **Note:** All API requests must include the `X-Api-Version: v1` header (defaults to `v1` if omitted, validated by `VersionMiddleware`). Authenticated and admin endpoints are processed by `EncryptionMiddleware`. Clients set `X-Encrypted: 1` header and wrap body as `{"payload": "<base64(AES-256-GCM)>"}`. Responses are likewise encrypted and wrapped in a `payload` field. Integer IDs in API responses are automatically converted to 12-character Hashid strings; Hashid strings in requests are decoded back to integer IDs by `HashidRequestMiddleware`.

## Admin Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Operations dashboard |
| GET/PUT | `/admin/api/users` | User management |
| GET/POST | `/admin/api/kyc` | KYC review |
| GET/POST/PUT/DELETE | `/admin/api/products` | Product management |
| POST | `/admin/api/products/{productId}/skus` | Create SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Set regional price |
| GET/POST | `/admin/api/orders` | Order management (incl. refunds) |
| GET | `/admin/api/orders/export` | Export orders (.xlsx) |
| GET | `/admin/api/users/export` | Export users (.xlsx) |
| GET | `/admin/api/suppliers/export` | Export suppliers (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Channels / transactions / reconciliation |
| GET/POST | `/admin/api/provisioning/*` | Provisioning tasks / host management |
| GET/POST | `/admin/api/suppliers/*` | Supplier approval / settlement / withdrawal |
| GET/POST | `/admin/api/tickets` | Ticket assignment / closure |
| GET | `/admin/api/reports/*` | Revenue / regional / supplier reports |
| GET | `/admin/api/monitor/*` | Monitoring dashboard / resource metrics |
| GET | `/admin/api/audit-logs` | Audit logs |
| PUT | `/admin/api/system/config` | System config update |
