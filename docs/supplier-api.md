# 供应商 API 文档 v1

## 概述

供应商功能提供两套 API：

| 类型 | 认证方式 | 前缀 | 状态 |
|------|---------|------|------|
| **内部 API** | 用户 Bearer Token | `/api/v1/supplier/` | 可用 |
| **外部 API** | API Key (`sk_xxx`) | `/api/v1/supplier/external/` | 可用 |

**Base URL**: `https://api.example.com`

**版本控制**: API 版本位于 URL 路径中（如 `/api/v1/...`）。不支持的版本返回 `400`。仅对 `/api/v1/*` 和 `/admin/api/v1/*` 路径生效，由 `VersionMiddleware` 统一处理。

---

## 内部 API（当前可用）

内部 API 使用与平台其他接口相同的用户 Bearer Token 认证，适用于已登录的供应商用户在客户端/前端调用。

### 认证

```
Authorization: Bearer <user_access_token>
```

用户需先通过 `/api/v1/auth/login` 登录获取 Token，且账号角色须为 `supplier`（由管理员审批供应商申请后设置）。

---

### 响应格式

#### 成功响应

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### 分页响应

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

#### 错误响应

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | 说明 |
|------|------|
| 0 | 成功 |
| 400 | 请求参数错误 / 不支持的 API 版本 |
| 401 | 未登录或 Token 已过期 |
| 403 | 无权访问（非供应商角色 / 密码确认失败） |
| 404 | 资源不存在 |
| 422 | 参数校验失败 |
| 429 | 请求频率超限 |

---

### 端点

#### 1. 供应商入驻

```
POST /api/v1/supplier/apply
```

申请成为供应商。每个用户只能提交一次申请。

**请求体**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| company_name | string | 是 | 公司名称 |
| contact_name | string | 是 | 联系人姓名 |
| contact_phone | string | 是 | 联系电话 |
| contact_email | string | 是 | 联系邮箱 |
| settlement_method | string | 否 | 结算方式，默认 `bank` |

**响应**: 供应商对象，状态为 `pending`

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

> 敏感字段（联系人姓名、电话、邮箱）在数据库中加密存储，API 返回时部分脱敏。

**错误**:

| code | 场景 |
|------|------|
| 422 | 已提交过供应商申请 |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. 商品管理

##### 获取已分配商品

```
GET /api/v1/supplier/products
```

**Query 参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |

**响应**: 分页列表，每项含商品信息和佣金比例

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

##### 添加商品

```
POST /api/v1/supplier/products
```

将已有商品关联到当前供应商。

**请求体**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| product_id | string | 是 | 商品 ID（Hashid） |
| commission_rate | float | 否 | 佣金比例，默认 0.1 |

**响应**: 创建的 SupplierProduct 对象

**错误**:

| code | 场景 |
|------|------|
| 422 | 商品已分配给该供应商 |

##### 移除商品

```
DELETE /api/v1/supplier/products/{id}
```

取消商品与供应商的关联。

**响应**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. 结算管理

##### 获取结算单列表

```
GET /api/v1/supplier/settlements
```

**响应**: 当前供应商的所有结算单，按创建时间倒序

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

| 字段 | 说明 |
|------|------|
| total_sales | 周期内已完成订单的总销售额 |
| commission | 平台佣金总额 |
| payable | 应付供应商金额（total_sales - commission） |
| status | `pending` / `paid` |

---

#### 4. 提现

##### 申请提现

```
POST /api/v1/supplier/withdraw
```

> 此操作需要密码二次确认（`confirm_password` 字段），由 `ConfirmationMiddleware` 校验。
> 5 次失败后锁定 15 分钟。

**请求体**:

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

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| amount | string | 是 | 提现金额（字符串避免浮点精度问题） |
| confirm_password | string | 是 | 用户登录密码（二次确认） |
| account_info | object | 是 | 收款账户信息 |
| account_info.method | string | 是 | 提现方式：`bank_transfer` / `alipay` / `wechat` |

**可提现余额计算**: 所有已完成的结算单 `payable` 之和 - 所有处理中的提现 `amount` 之和

**响应**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**错误**:

| code | 场景 |
|------|------|
| 422 | 可提现余额不足 |
| 403 | 密码确认失败 |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### 内部 API 端点汇总

| 方法 | 路径 | 认证 | 密码确认 | 说明 |
|------|------|------|---------|------|
| POST | `/api/v1/supplier/apply` | Token | - | 申请成为供应商 |
| GET | `/api/v1/supplier/products` | Token | - | 查看已分配商品 |
| POST | `/api/v1/supplier/products` | Token | - | 添加商品关联 |
| DELETE | `/api/v1/supplier/products/{id}` | Token | - | 移除商品关联 |
| GET | `/api/v1/supplier/settlements` | Token | - | 查看结算单 |
| POST | `/api/v1/supplier/withdraw` | Token | 需要 | 申请提现 |

---

## 外部 API（设计规格，待实现）

外部 API 允许供应商通过编程方式管理订单、资源和结算。所有请求需要 API Key 认证。

**Base URL**: `https://api.example.com/api/v1`

### 认证

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API Key 由平台管理员在管理后台 `供应商管理 → API Keys` 中生成。

**安全要求**:
- 仅通过 HTTPS 访问
- API Key 仅创建时显示一次，请妥善保管
- 建议将服务器 IP 加入白名单

---

### 响应格式

与内部 API 一致，额外包含 `request_id` 用于追踪：

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### 端点

#### 1. 订单管理

##### 获取订单列表

```
GET /api/v1/supplier/orders
```

**Query 参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认 1 |
| page_size | int | 否 | 每页数量，默认 20，最大 50 |
| status | string | 否 | 筛选状态：pending/paid/completed/refunded |
| from | date | 否 | 起始日期 YYYY-MM-DD |
| to | date | 否 | 截止日期 YYYY-MM-DD |

##### 获取订单详情

```
GET /api/v1/supplier/orders/{id}
```

---

#### 2. 资源管理

##### 获取资源列表

```
GET /api/v1/supplier/resources
```

**Query 参数**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### 获取资源状态

```
GET /api/v1/supplier/resources/{id}/status
```

---

#### 3. 结算管理

##### 获取结算单列表

```
GET /api/v1/supplier/settlements
```

##### 获取结算单详情

```
GET /api/v1/supplier/settlements/{id}
```

---

#### 4. 提现

##### 申请提现

```
POST /api/v1/supplier/withdraw
```

##### 提现记录

```
GET /api/v1/supplier/withdraws
```

---

#### 5. 商品管理

##### 获取我的商品

```
GET /api/v1/supplier/products
```

##### 提交商品上架申请

```
POST /api/v1/supplier/products
```

---

### 外部 API 端点汇总

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/supplier/orders` | 订单列表 |
| GET | `/api/v1/supplier/orders/{id}` | 订单详情 |
| GET | `/api/v1/supplier/resources` | 资源列表 |
| GET | `/api/v1/supplier/resources/{id}/status` | 资源状态 |
| GET | `/api/v1/supplier/settlements` | 结算单列表 |
| GET | `/api/v1/supplier/settlements/{id}` | 结算单详情 |
| POST | `/api/v1/supplier/withdraw` | 申请提现 |
| GET | `/api/v1/supplier/withdraws` | 提现记录 |
| GET | `/api/v1/supplier/products` | 商品列表 |
| POST | `/api/v1/supplier/products` | 提交商品 |

---

## Webhook（接收平台事件）

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

## 限流

| 端点 | 限制 |
|------|------|
| 内部 API | 60 req/min 每用户（默认） |
| 内部 API 登录 | 5 req/min |
| 外部 API | 120 req/min 每 API Key（`supplier_api` 规则，经 `RateLimitMiddleware` 生效） |
| 外部 API 提现 | 10 req/min（建议值，可调 `config/security.php`） |

> 外部 API 限流规则定义在 `config/security.php` 的 `rate_limits.supplier_api`，
> 由 `RateLimitMiddleware` 对 `/api/v1/supplier/external/*` 路径统一执行（原子 INCR 计数，
> Redis 不可用时放行）。

限流头:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK 示例

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/v1/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
    ],
]);

// 申请成为供应商
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// 获取结算单
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// 申请提现
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
}

# 获取已分配商品
resp = requests.get('https://api.example.com/api/v1/supplier/products',
                     headers=headers)
products = resp.json()

# 申请提现
resp = requests.post('https://api.example.com/api/v1/supplier/withdraw',
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

## 错误处理建议

1. **429 限流**: 等待 `Retry-After` 秒后重试
2. **401 未授权**: 检查 Token 是否有效，是否已过期
3. **403 禁止**: 检查账号角色是否为 `supplier`；密码确认失败需等待锁定解除
4. **422 校验失败**: 根据 `message` 字段修正请求参数
5. **5xx 服务端错误**: 指数退避重试 (1s -> 5s -> 25s)

---

## 管理后台端点参考

以下是管理员管理供应商的相关端点（仅供后台使用，需 Admin 角色）：

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/admin/api/v1/suppliers` | 供应商列表（支持 status 筛选） |
| GET | `/admin/api/v1/suppliers/export` | 导出供应商 Excel |
| POST | `/admin/api/v1/suppliers/{id}/approve` | 审批通过供应商 |
| POST | `/admin/api/v1/suppliers/{id}/settle` | 生成结算单 |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | 批准提现 |
| GET | `/admin/api/v1/suppliers/{id}/api-keys` | 查看供应商 API Key 列表 |
| POST | `/admin/api/v1/suppliers/{id}/api-keys` | 创建 API Key（仅返回一次原始 Key） |
| DELETE | `/admin/api/v1/suppliers/api-keys/{id}` | 吊销 API Key |
