# CloudPlatform API 接口文档

## 概述

**Base URL:** `https://api.example.com`

**版本控制:** 通过 HTTP 请求头 `X-Api-Version: v1` 指定。缺失默认 `v1`，不支持的版本返回 `400`。版本不在 URL 路径中。

**认证方式:**

| 端 | 方式 | 请求头 |
|----|------|--------|
| 用户端 | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| 管理端 | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| 供应商外部 API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | 签名验证 | `Stripe-Signature: ...` |

**客户端平台:** 所有 API 请求建议携带 `X-Client-Platform` 头，支持 `ios/android/macos/windows/linux/web/harmonyos/ipados`。

**多语言:** 所有 API 请求建议携带 `Accept-Language` 头（`zh-CN` / `en-US`），影响翻译文本和 JSON 多语言字段的返回值。缺失默认 `en-US`。

---

## 统一响应格式

### 成功

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### 分页

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### 错误

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP 状态码

| code | 说明 |
|------|------|
| 0 | 成功 |
| 400 | 请求参数错误 / 不支持的 API 版本 / 不支持的客户端平台 |
| 401 | 未认证 |
| 403 | 无权限 / WAF 拦截 |
| 404 | 资源不存在 |
| 413 | 请求体过大 (>10MB) |
| 414 | URL 过长 (>2KB) |
| 415 | 不支持的 Content-Type |
| 422 | 参数校验失败 |
| 429 | 请求频率超限 |

---

## 路由分组与中间件矩阵

| 路由组 | 中间件 | 前缀 |
|--------|--------|------|
| 公开 | 全局中间件链 | `/health`, `/api/*` |
| `/api/auth` | 全局 + Encryption | `/api/auth/*` |
| `/api` (用户) | 全局 + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (敏感) | 全局 + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | 供应商外部 API |
| `/admin/api` | 全局 + Encryption + Auth + AdminRole | 管理后台 API |
| `/admin/api` (敏感) | 全局 + Encryption + Auth + AdminRole + Confirmation | 敏感管理操作 |

---

## 一、公开端点

### 健康检查

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### 服务状态

```
GET /api/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### 产品

```
GET /api/products
  参数: category_id, region_id, keyword, supplier_id, page (默认1), page_size (默认20, 最大50)
  → 分页产品列表 (含 category, skus.regionPrices)

GET /api/products/search
  参数: q (必填), page
  → Elasticsearch 全文搜索

GET /api/products/{id}
  → 产品详情 (含 category, skus, images, reviews)

GET /api/products/{productId}/reviews
  → 评价列表 + avg_rating + total + distribution
```

### 域名

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → 可用 TLD 列表 (Redis 缓存 1h)
```

### 帮助中心

```
GET /api/help
  参数: category, page
  头: Accept-Language (en-US / zh-CN)
  → 分页帮助文章

GET /api/help/categories
  → 文章分类列表

GET /api/help/{slug}
  → 单篇文章详情
```

---

## 二、认证端点

### 验证码

```
POST /api/captcha/create
  头: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### 注册

```
POST /api/auth/register
  头: X-Encrypted: 1
  体(加密): { email?, phone?, password, language? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 3 req/min
```

### 登录

```
POST /api/auth/login
  头: X-Encrypted: 1
  体(加密): { login (email/phone), password, captcha_key, captcha_points }
  → { access_token, refresh_token, expires_in, token_type }

限流: 5 req/min, 5 次失败锁 15min
```

### Token 刷新

```
POST /api/auth/refresh
  头: X-Encrypted: 1
  体(加密): { refresh_token }
  → { access_token, refresh_token, expires_in, token_type }
```

### OAuth

```
GET /api/auth/google            → { url }
GET /api/auth/google/callback?code=xxx
GET /api/auth/apple             → { url }
GET /api/auth/apple/callback?code=xxx
```

### 密码重置

```
POST /api/auth/forgot-password
  体: { email }
  → 发送验证码邮件

POST /api/auth/reset-password
  体: { email, code, password }
  → 重置成功
```

### 邮箱验证

```
GET /api/auth/verify-email?token=xxx
  → 验证成功
```

### 短信验证

```
POST /api/auth/send-sms
  体: { phone }
  → 发送短信验证码 (60s 冷却)
```

### TOTP 两步验证

```
POST /api/user/totp/setup        → { secret, qr_code_url }
POST /api/user/totp/verify       体: { code } → { recovery_codes }
POST /api/user/totp/disable      体: { code }
GET /api/user/totp/recovery-codes → { codes }
POST /api/auth/login/recovery    体: { login, recovery_code }
```

---

## 三、用户端点（需认证）

### 个人资料

```
GET /api/user/profile
PUT /api/user/profile
  体: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC 实名认证

```
POST /api/user/kyc
  体: { id_type, id_number, real_name, front_image, back_image }
```

### 余额

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
  参数: page
  → 余额变动记录
```

### 地址管理

```
GET /api/user/addresses
POST /api/user/addresses
  体: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### 会话管理

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → 撤销指定会话

DELETE /api/user/account
  体: { confirm_password }
  → GDPR 账号注销
```

### 通知

```
GET /api/user/notifications
  参数: page
  → 分页通知列表

POST /api/user/notifications/{id}/read
  → 标记已读

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
  体: { email: {order_paid: true, ...}, push: {...} }
```

### 邮箱

```
POST /api/user/resend-verify-email
  → 重新发送验证邮件
```

### 文件上传

```
POST /api/upload
  体: multipart/form-data { file, type: avatar/kyc/attach }
  限制: avatar 2MB, kyc 5MB, attach 10MB
  允许: jpg, jpeg, png, gif, pdf
```

---

## 四、购物车与订单

### 购物车

```
POST /api/cart
  体: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
  体: { quantity }
```

### 订单

```
POST /api/orders
  → 从购物车创建订单
  ← { order, order_no, items, subtotal, discount, tax, total }

GET /api/orders
  参数: page, status
  → 我的订单列表

GET /api/orders/{id}
  → 订单详情 (含 items, timeline)

GET /api/orders/{id}/payment-methods
  → 可用支付通道 + 各通道实付金额

POST /api/orders/{id}/pay    🔒 密码确认
  体: { channel_code, confirm_password }
  → { client_secret, transaction_no }
```

### 优惠券

```
POST /api/coupons/validate
  体: { code, order_total }
  → { coupon_id, discount, type }

422: 无效/过期/不满足使用条件
```

### 发票

```
GET /api/invoices
  参数: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → PDF 下载
```

---

## 五、资源管理

```
GET /api/resources
  参数: page, status
  → 我的资源列表

GET /api/resources/{id}
  → 资源详情

GET /api/resources/{id}/status
  → 资源当前状态 + 指标

GET /api/resources/{id}/console
  → VNC/控制台 URL

POST /api/resources/batch
  体: { action: start/stop/restart, resource_ids: [...] }
```

---

## 六、DNS 管理

```
GET /api/dns/{domain}
  → DNS 记录列表

POST /api/dns/{domain}/records
  体: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 密码确认
```

---

## 七、工单

```
POST /api/tickets
  体: { resource_id?, category, priority?, title, content }

GET /api/tickets
  参数: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
  体: { content }
```

---

## 八、供应商（内部 API）

```
POST /api/supplier/apply
  体: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → 结算单列表

POST /api/supplier/withdraw    🔒 密码确认
  体: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
  体: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## 九、供应商外部 API

**认证:** `Authorization: Bearer sk_xxx...`（SHA256 验签）

**限流:** 120 req/min（提现 10 req/min）

```
GET /api/supplier/external/orders
  参数: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → 订单详情（仅本供应商关联）

GET /api/supplier/external/resources
  参数: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
  参数: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
  体: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
  参数: page
```

---

## 十、管理后台 API

**认证:** JWT Bearer Token + Admin 角色

### 仪表盘

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### 用户管理

```
GET /admin/api/users              参数: page, status, keyword
GET /admin/api/users/export       → Excel 下载
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status  体: { status }
```

### KYC 审核

```
GET /admin/api/kyc                参数: page, status

POST /admin/api/kyc/{id}/approve   🔒 密码确认
  体: { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 密码确认
  体: { confirm_password, reason }
```

### 产品管理

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 密码确认
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → CSV 下载
POST /admin/api/products/import        → CSV 上传 upsert
```

### 订单管理

```
GET /admin/api/orders              参数: page, status, keyword
GET /admin/api/orders/export       → Excel 下载
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 密码确认
  体: { confirm_password, amount?, reason }
```

### 支付管理

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions  参数: page, channel, status
GET /admin/api/payments/reconcile     参数: date
```

### 资源与开通

```
GET /admin/api/provisioning/tasks              参数: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
  体: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 密码确认
GET /admin/api/provisioning/hosts
```

### 供应商管理

```
GET /admin/api/suppliers                 参数: page, status
GET /admin/api/suppliers/export          → Excel 下载

POST /admin/api/suppliers/{id}/approve    🔒 密码确认
POST /admin/api/suppliers/{id}/settle     🔒 密码确认
  体: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 密码确认
```

### 供应商 API Key

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
  体: { name }
  ← { api_key: "sk_xxx...", prefix } (仅显示一次)

DELETE /admin/api/suppliers/api-keys/{id}
```

### 工单管理

```
GET /admin/api/tickets                  参数: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign     体: { user_id }
POST /admin/api/tickets/{id}/close
```

### 域名管理

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
  体: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones             参数: page
GET /admin/api/domains/transfers         参数: page
POST /admin/api/domains/transfers/{id}/approve
```

### 通知管理

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
  体: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log         参数: page
```

### 优惠券

```
GET /admin/api/coupons
POST /admin/api/coupons
  体: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### 帮助文章

```
GET /admin/api/help
POST /admin/api/help
  体: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → 软删除 (status=archived)
```

### 云厂商 API

```
GET /admin/api/providers
POST /admin/api/providers
  体: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → 禁用 (status=disabled)
```

### Webhook 管理

```
GET /admin/api/webhooks
POST /admin/api/webhooks
  体: { url }
DELETE /admin/api/webhooks              体: { id }
POST /admin/api/webhooks/test           体: { url }
```

### 报表

```
GET /admin/api/reports/revenue           参数: from, to, granularity
GET /admin/api/reports/supplier          参数: from, to
GET /admin/api/reports/region            参数: from, to
```

### 监控

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### 审计日志

```
GET /admin/api/audit-logs                参数: page, user_id, action, from, to
  → 分页审计日志 (含 client_platform)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
  体: { action: enable/disable/toggle/reset }
```

### 系统配置

```
PUT /admin/api/system/config              🔒 密码确认
```

### 产品导入导出

```
GET /admin/api/products/export           → CSV 下载
POST /admin/api/products/import          → CSV 上传 upsert
```

### 供应商 + 用户导出

```
GET /admin/api/suppliers/export          → Excel 下载
GET /admin/api/users/export              → Excel 下载
GET /admin/api/orders/export             → Excel 下载
```

---

## 十一、WebSocket 事件

**连接:** `ws://host:8282?token=<jwt_access_token>`

### 客户端 → 服务端

```json
{ "type": "ping" }
```

### 服务端 → 客户端

```json
{ "type": "pong", "ts": 1680000000 }
```

### 推送事件

| 事件 | 数据 | 触发时机 |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | 支付成功 |
| `resource.provisioned` | `{resource_id, type, ip_address}` | 资源开通完成 |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | 资源即将到期 |
| `ticket.updated` | `{ticket_id, title, status}` | 工单状态变更 |
| `notification.new` | `{notification_id, title, body}` | 新通知 |

---

## 十二、错误码参考

| code | 说明 |
|------|------|
| 400 | 参数错误 / 不支持的 API 版本 / 不支持的客户端平台 |
| 401 | 未认证 / Token 过期 / 无效 API Key |
| 403 | 无权限 / 非供应商角色 / WAF 拦截 / 密码确认失败 |
| 404 | 资源不存在 |
| 413 | 请求体超过 10MB |
| 414 | URL 超过 2KB |
| 415 | Content-Type 不在白名单 (仅允许 application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | 参数校验失败（邮箱已注册 / 库存不足 / 可提现余额不足 / 已提交过申请） |
| 429 | 请求频率超限 |
| 500 | 服务端错误 |

### 常见 422 消息

| 消息 | 端点 |
|------|------|
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
