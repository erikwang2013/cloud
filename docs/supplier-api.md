# 供应商 API 文档 v1

## 概述

供应商 API 允许供应商通过编程方式管理订单、资源和结算。所有请求需要 API Key 认证。

**Base URL**: `https://api.example.com/api`

**版本控制**: 通过 HTTP 头 `X-Api-Version: v1` 指定

---

## 认证

所有请求必须包含 API Key 头：

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

API Key 由平台管理员在管理后台 `供应商管理 → API Keys` 中生成。

**安全要求**:
- 仅通过 HTTPS 访问
- API Key 仅创建时显示一次，请妥善保管
- 建议将服务器 IP 加入白名单

---

## 响应格式

### 成功响应

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

### 分页响应

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### 错误响应

```json
{
  "code": 401,
  "message": "Invalid API key",
  "data": null,
  "request_id": "req_abc123"
}
```

| code | 说明 |
|------|------|
| 0 | 成功 |
| 400 | 请求参数错误 |
| 401 | API Key 无效或已撤销 |
| 403 | 无权访问该资源 |
| 404 | 资源不存在 |
| 422 | 参数校验失败 |
| 429 | 请求频率超限 (限流: 120 req/min) |

---

## 端点

### 1. 订单管理

#### 获取订单列表

```
GET /api/supplier/orders
```

**Query 参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页数量，默认 20，最大 50 |
| status | string | 否 | 筛选状态：pending/paid/completed/refunded |
| from | date | 否 | 起始日期 YYYY-MM-DD |
| to | date | 否 | 截止日期 YYYY-MM-DD |

**响应**: 分页的订单列表，每个订单包含订单号、金额、币种、状态、创建时间

```bash
curl -H "Authorization: Bearer sk_xxx" \
     -H "X-Api-Version: v1" \
     "https://api.example.com/api/supplier/orders?status=paid&page=1"
```

#### 获取订单详情

```
GET /api/supplier/orders/{id}
```

**响应**: 订单完整信息，含订单项、客户信息（脱敏）、资源详情

---

### 2. 资源管理

#### 获取资源列表

```
GET /api/supplier/resources
```

**Query 参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码 |
| status | string | 否 | active/provisioning/stopped/destroyed |
| type | string | 否 | server/ip/disk/domain |

**响应示例**:

```json
{
  "code": 0,
  "data": [{
    "id": "abc123",
    "type": "server",
    "status": "active",
    "provisioned_at": "2026-05-15T10:30:00Z",
    "expired_at": "2026-06-15T10:30:00Z",
    "specs": {
      "cpu": 4,
      "ram": 8192,
      "disk": 100,
      "os": "Ubuntu 22.04"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 45 }
}
```

#### 获取资源状态

```
GET /api/supplier/resources/{id}/status
```

**响应**: 资源当前状态、IP 地址、带宽使用量等

---

### 3. 结算管理

#### 获取结算单列表

```
GET /api/supplier/settlements
```

**Query 参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码 |
| status | string | 否 | pending/paid |
| period_start | date | 否 | 结算周期起始 |
| period_end | date | 否 | 结算周期截止 |

**响应**: 结算单列表，每项含周期、销售额、佣金、应付金额、状态

#### 获取结算单详情

```
GET /api/supplier/settlements/{id}
```

---

### 4. 提现

#### 申请提现

```
POST /api/supplier/withdraw
```

**请求体**:

```json
{
  "amount": 500.00,
  "currency": "USD",
  "method": "bank_transfer",
  "account_info": {
    "bank_name": "Bank of America",
    "account_number": "****1234",
    "routing_number": "****5678"
  }
}
```

**响应**: 提现记录，状态 pending

#### 提现记录列表

```
GET /api/supplier/withdraws
```

---

### 5. 商品管理

#### 获取我的商品

```
GET /api/supplier/products
```

**响应**: 供应商已上架的商品列表，含审核状态

#### 提交商品上架申请

```
POST /api/supplier/products
```

**请求体**:

```json
{
  "name": "高性能云服务器",
  "category_id": 1,
  "description": "E5-2680 v4, DDR4 ECC, SSD RAID10",
  "skus": [{
    "specs": { "cpu": 4, "ram": 8192, "disk": 100, "bandwidth": 100 },
    "cycle": "monthly",
    "prices": [
      { "region_id": 1, "price": 29.99, "original_price": 49.99, "stock": 50 }
    ]
  }]
}
```

---

## 限流

| 端点 | 限制 |
|------|------|
| 全部 | 120 req/min 每 API Key |
| 提现 | 10 req/min |

限流头:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 98
X-RateLimit-Reset: 1680000000
```

---

## Webhook (接收平台事件)

供应商可以注册 Webhook URL 接收实时事件。在管理后台配置。

### 事件类型

| 事件 | 触发时机 |
|------|----------|
| `order.paid` | 用户完成支付 |
| `order.refunded` | 订单已退款 |
| `resource.provisioned` | 资源开通完成 |
| `resource.expiring` | 资源即将到期 (7天内) |
| `resource.destroyed` | 资源已销毁 |
| `settlement.created` | 生成结算单 |
| `withdrawal.approved` | 提现已批准 |

### Webhook 请求格式

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

**验签**: `HMAC-SHA256(payload, webhook_secret)`

---

## SDK 示例

### PHP

```php
$apiKey = 'sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$apiKey}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// 获取订单列表
$response = $client->get('supplier/orders', [
    'query' => ['status' => 'paid', 'page' => 1],
]);
$orders = json_decode($response->getBody(), true);

// 申请提现
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'   => 500.00,
        'currency' => 'USD',
        'method'   => 'bank_transfer',
        'account_info' => ['bank_name' => 'BOA', 'account_number' => '****1234'],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'X-Api-Version': 'v1',
}
resp = requests.get('https://api.example.com/api/supplier/orders',
                     headers=headers,
                     params={'status': 'paid', 'page': 1})
orders = resp.json()
```

---

## 错误处理建议

1. **429 限流**: 等待 `Retry-After` 秒后重试
2. **401 未授权**: 检查 API Key 是否有效，是否被撤销
3. **5xx 服务端错误**: 指数退避重试 (1s → 5s → 25s)
4. **幂等性**: 提现等写操作建议客户端生成幂等键 `X-Idempotency-Key`
