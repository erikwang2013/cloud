# Supplier API Documentation v1

## Overview

The supplier feature provides two API sets:

| Type | Authentication | Prefix | Status |
|------|---------|------|------|
| **Internal API** | User Bearer Token | `/api/supplier/` | Available |
| **External API** | API Key (`sk_xxx`) | `/api/supplier/external/` | Available |

**Base URL**: `https://api.example.com`

**Versioning**: specified via the HTTP header `X-Api-Version: v1`. Defaults to `v1` when missing; unsupported versions return `400`. Only applies to `/api/*` and `/admin/api/*` paths, handled uniformly by `VersionMiddleware`.

---

## Internal API (currently available)

The internal API uses the same user Bearer Token authentication as other platform endpoints, suitable for logged-in supplier users calling from clients/frontends.

### Authentication

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

Users must first log in via `/api/auth/login` to obtain a Token, and the account role must be `supplier` (set by an admin after approving the supplier application).

---

### Response Format

#### Success Response

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Paginated Response

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### Error Response

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Description |
|------|------|
| 0 | Success |
| 400 | Invalid request parameters / unsupported API version |
| 401 | Not logged in or token expired |
| 403 | No access (non-supplier role / password confirmation failed) |
| 404 | Resource not found |
| 422 | Parameter validation failed |
| 429 | Request rate exceeded |

---

### Endpoints

#### 1. Supplier Onboarding

```
POST /api/supplier/apply
```

Apply to become a supplier. Each user can only submit one application.

**Request Body**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Parameter | Type | Required | Description |
|------|------|------|------|
| company_name | string | Yes | Company name |
| contact_name | string | Yes | Contact person name |
| contact_phone | string | Yes | Contact phone |
| contact_email | string | Yes | Contact email |
| settlement_method | string | No | Settlement method, default `bank` |

**Response**: supplier object with status `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> Sensitive fields (contact name, phone, email) are stored encrypted in the database and partially masked in API responses.

**Errors**:

| code | Scenario |
|------|------|
| 422 | Supplier application already submitted |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Product Management

##### Get Assigned Products

```
GET /api/supplier/products
```

**Query Parameters**:

| Parameter | Type | Required | Description |
|------|------|------|------|
| page | int | No | Page number, default 1 |

**Response**: paginated list, each item includes product info and commission rate

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### Add Product

```
POST /api/supplier/products
```

Associate an existing product with the current supplier.

**Request Body**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Parameter | Type | Required | Description |
|------|------|------|------|
| product_id | string | Yes | Product ID (Hashid) |
| commission_rate | float | No | Commission rate, default 0.1 |

**Response**: the created SupplierProduct object

**Errors**:

| code | Scenario |
|------|------|
| 422 | Product already assigned to this supplier |

##### Remove Product

```
DELETE /api/supplier/products/{id}
```

Remove the association between a product and the supplier.

**Response**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Settlement Management

##### Get Settlement List

```
GET /api/supplier/settlements
```

**Response**: all settlements of the current supplier, in descending creation order

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| Field | Description |
|------|------|
| total_sales | Total sales of completed orders in the period |
| commission | Total platform commission |
| payable | Amount payable to the supplier (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Withdrawals

##### Request Withdrawal

```
POST /api/supplier/withdraw
```

> This operation requires password second confirmation (`confirm_password` field), validated by `ConfirmationMiddleware`.
> Locked for 15 minutes after 5 failures.

**Request Body**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| Parameter | Type | Required | Description |
|------|------|------|------|
| amount | string | Yes | Withdrawal amount (string to avoid float precision issues) |
| confirm_password | string | Yes | User login password (second confirmation) |
| account_info | object | Yes | Receiving account info |
| account_info.method | string | Yes | Withdrawal method: `bank_transfer` / `alipay` / `wechat` |

**Withdrawable balance calculation**: sum of `payable` of all completed settlements - sum of `amount` of all in-progress withdrawals

**Response**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Errors**:

| code | Scenario |
|------|------|
| 422 | Insufficient withdrawable balance |
| 403 | Password confirmation failed |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Internal API Endpoint Summary

| Method | Path | Auth | Password confirm | Description |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | Apply to become a supplier |
| GET | `/api/supplier/products` | Token | - | View assigned products |
| POST | `/api/supplier/products` | Token | - | Add product association |
| DELETE | `/api/supplier/products/{id}` | Token | - | Remove product association |
| GET | `/api/supplier/settlements` | Token | - | View settlements |
| POST | `/api/supplier/withdraw` | Token | Required | Request withdrawal |

---

## External API (design spec, to be implemented)

The external API allows suppliers to manage orders, resources and settlements programmatically. All requests require API Key authentication.

**Base URL**: `https://api.example.com/api`

### Authentication

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

API Keys are generated by platform admins in the admin panel under `Supplier Management → API Keys`.

**Security requirements**:
- HTTPS access only
- API Key shown only once at creation, keep it safe
- Recommended to whitelist your server IP

---

### Response Format

Same as the internal API, additionally including `request_id` for tracking:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Endpoints

#### 1. Order Management

##### Get Order List

```
GET /api/supplier/orders
```

**Query Parameters**:

| Parameter | Type | Required | Description |
|------|------|------|------|
| page | int | No | Page number, default 1 |
| page_size | int | No | Items per page, default 20, max 50 |
| status | string | No | Filter status: pending/paid/completed/refunded |
| from | date | No | Start date YYYY-MM-DD |
| to | date | No | End date YYYY-MM-DD |

##### Get Order Details

```
GET /api/supplier/orders/{id}
```

---

#### 2. Resource Management

##### Get Resource List

```
GET /api/supplier/resources
```

**Query Parameters**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Get Resource Status

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. Settlement Management

##### Get Settlement List

```
GET /api/supplier/settlements
```

##### Get Settlement Details

```
GET /api/supplier/settlements/{id}
```

---

#### 4. Withdrawals

##### Request Withdrawal

```
POST /api/supplier/withdraw
```

##### Withdrawal Records

```
GET /api/supplier/withdraws
```

---

#### 5. Product Management

##### Get My Products

```
GET /api/supplier/products
```

##### Submit Product Listing Application

```
POST /api/supplier/products
```

---

### External API Endpoint Summary

| Method | Path | Description |
|------|------|------|
| GET | `/api/supplier/orders` | Order list |
| GET | `/api/supplier/orders/{id}` | Order details |
| GET | `/api/supplier/resources` | Resource list |
| GET | `/api/supplier/resources/{id}/status` | Resource status |
| GET | `/api/supplier/settlements` | Settlement list |
| GET | `/api/supplier/settlements/{id}` | Settlement details |
| POST | `/api/supplier/withdraw` | Request withdrawal |
| GET | `/api/supplier/withdraws` | Withdrawal records |
| GET | `/api/supplier/products` | Product list |
| POST | `/api/supplier/products` | Submit product |

---

## Webhooks (receive platform events)

Suppliers can register Webhook URLs to receive real-time events. Configured in the admin panel.

### Event Types

| Event | Trigger |
|------|----------|
| `order.paid` | User completed payment |
| `order.refunded` | Order refunded |
| `resource.provisioned` | Resource provisioning completed |
| `resource.expiring` | Resource expiring soon (within 7 days) |
| `resource.destroyed` | Resource destroyed |
| `settlement.created` | Settlement generated |
| `withdrawal.approved` | Withdrawal approved |

### Webhook Request Format

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**Signature verification**: `HMAC-SHA256(payload, webhook_secret)`

---

## Rate Limiting

| Endpoint | Limit |
|------|------|
| Internal API | 60 req/min per user (default) |
| Internal API login | 5 req/min |
| External API | 120 req/min per API Key (`supplier_api` rule, effective via `RateLimitMiddleware`) |
| External API withdrawal | 10 req/min (suggested value, adjustable in `config/security.php`) |

> The external API rate limit rule is defined in `rate_limits.supplier_api` of `config/security.php`,
> uniformly enforced by `RateLimitMiddleware` on `/api/supplier/external/*` paths (atomic INCR counting,
> fails open when Redis is unavailable).

Rate limit headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK Examples

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// Apply to become a supplier
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// Get settlements
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// Request withdrawal
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
    'X-Api-Version': 'v1',
}

# Get assigned products
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# Request withdrawal
resp = requests.post('https://api.example.com/api/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## Error Handling Recommendations

1. **429 rate limited**: wait `Retry-After` seconds before retrying
2. **401 unauthorized**: check whether the Token is valid and not expired
3. **403 forbidden**: check whether the account role is `supplier`; password confirmation failures require waiting out the lockout
4. **422 validation failed**: fix request parameters per the `message` field
5. **5xx server errors**: retry with exponential backoff (1s -> 5s -> 25s)

---

## Admin Panel Endpoint Reference

The following are the admin endpoints for managing suppliers (back office only, requires Admin role):

| Method | Path | Description |
|------|------|------|
| GET | `/admin/api/suppliers` | Supplier list (supports status filter) |
| GET | `/admin/api/suppliers/export` | Export supplier Excel |
| POST | `/admin/api/suppliers/{id}/approve` | Approve supplier |
| POST | `/admin/api/suppliers/{id}/settle` | Generate settlement |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Approve withdrawal |
| GET | `/admin/api/suppliers/{id}/api-keys` | View supplier API Key list |
| POST | `/admin/api/suppliers/{id}/api-keys` | Create API Key (raw Key returned only once) |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | Revoke API Key |
