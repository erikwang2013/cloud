# CloudPlatform API 接口文档

## 概述

**Base URL:** `https://api.example.com`

**版本控制:** API 版本位于 URL 路径中（如 `/api/v1/...`）。不支持的版本返回 `400`。

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
| 404 | 资源不存在（firstOrFail/findOrFail 未命中统一映射 404） |
| 413 | 请求体过大 (>10MB) |
| 414 | URL 过长 (>2KB) |
| 415 | 不支持的 Content-Type |
| 422 | 参数校验失败 |
| 429 | 请求频率超限 |

---

## 路由分组与中间件矩阵

| 路由组 | 中间件 | 前缀 |
|--------|--------|------|
| 公开 | 全局中间件链 | `/health`, `/api/v1/*` |
| 站点落地页 | 全局中间件链 | `/`（回吐 `public/index.html`，非 JSON API） |
| `/health` (内部) | 全局 + InternalToken（live/ready）；`/health/deps` 强制 InternalTokenRequired | `/health/live`, `/health/ready`, `/health/deps` |
| `/internal/v1` (服务内部) | InternalTokenRequired（非空 token + hash_equals，无 loopback 旁路） | `/internal/v1/refunds/execute` |
| `/api/v1/auth` | 全局 + Encryption | `/api/v1/auth/*` |
| `/api` (用户) | 全局 + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api` (敏感) | 全局 + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | 供应商外部 API |

---

## 一、公开端点

### 站点落地页

```
GET /
→ 200 text/html   # 回吐 service/public/index.html
→ 404             # 该文件不存在时
```

不是 JSON 接口，仅用于浏览器访问站点根路径；同源的 `/favicon.svg`、`/favicon.ico`、`/mascot.svg` 由 webman 静态文件服务提供。

### 健康检查

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### 服务状态

```
GET /api/v1/status
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
GET /api/v1/products
  参数: category_id, region_id, keyword, supplier_id, page (默认1), page_size (默认20, 最大50)
  → 分页产品列表 (含 category, skus.regionPrices)

GET /api/v1/products/search
  参数: q (必填), page
  → Elasticsearch 全文搜索

GET /api/v1/products/{id}
  → 产品详情 (含 category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → 评价列表 + avg_rating + total + distribution
  状态枚举: pending(待审核)/approved(已通过)/rejected(已拒绝)，仅返回 approved
```

### 域名

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → 可用 TLD 列表 (Redis 缓存 1h)
```

### 帮助中心

```
GET /api/v1/help
  参数: category, page
  头: Accept-Language (en-US / zh-CN)
  → 分页帮助文章

GET /api/v1/help/categories
  → 文章分类列表

GET /api/v1/help/{slug}
  → 单篇文章详情
```

---

## 二、认证端点

### 验证码

```
POST /api/v1/captcha/create
  头: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### 注册

```
POST /api/v1/auth/register
  头: X-Encrypted: 1
  体(加密): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 3 req/min
```

- `deviceFingerprint`（可选）：注册时记录设备指纹，登录/刷新时校验；未携带则跳过指纹绑定
- email/phone 存储前经 Encryptable 确定性加密（ECB，密文等值查询），唯一性校验与登录查询均按密文进行

### 登录

```
POST /api/v1/auth/login
  头: X-Encrypted: 1
  体(加密): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 5 req/min, 5 次失败锁 15min
```

- `login` 按密文等值查询（Encryptable 确定性加密），明文查询不命中加密列

### Token 刷新

```
POST /api/v1/auth/refresh
  头: X-Encrypted: 1
  体(加密): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` 与注册时记录不一致 → 401 `Device mismatch`；刷新 token 以密文哈希查询

### OAuth

支持提供商：google, apple, facebook, x, microsoft, linkedin, github
（由 .env 中 `{PROVIDER}_OAUTH_CLIENT_ID` 等配置决定是否启用）

```
GET /api/v1/auth/{provider}            → { url }        # 跳转授权页（PKCE/nonce 防重放）
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  体: { code, state }
```

- Apple/Microsoft 返回 id_token，服务端经 JWKS 校验签名、iss/aud/exp/nonce
- 所有提供商要求 `email_verified=true` 才允许登录，否则 422
- `state` 缺失或不匹配 → 422（防 CSRF，5 分钟过期）
- OAuth 流程限流：每 60 秒 10 次（redirect + callback）

### 密码重置

```
POST /api/v1/auth/forgot-password
  体: { email }
  → 发送验证码邮件

POST /api/v1/auth/reset-password
  体: { email, code, password }
  → 重置成功
  → 错误累计 5 次 → 429 限流 10 分钟
```

### 邮箱验证

```
GET /api/v1/auth/verify-email?token=xxx
  → 验证成功
```

### 短信验证

```
POST /api/v1/auth/send-sms
  体: { phone }
  → 发送短信验证码 (60s 冷却)
```

### TOTP 两步验证

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # 未持久化，10 分钟内需 verify 生效
POST /api/v1/user/totp/verify       体: { code } → { verified: true }   # 首次启用时返回启用成功消息
POST /api/v1/user/totp/disable      体: { password }             # 需密码确认，否则 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # 每次生成 8 个一次性码，需密码确认，否则 403
POST /api/v1/auth/login/recovery    体: { login, password, recovery_code }
```

- 用户启用 TOTP 后登录必须携带 `totp_code`，否则 401
- TOTP 连续错误 5 次 → 该用户锁定 15 分钟（login_lock）

---

## 三、用户端点（需认证）

### 个人资料

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  体: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC 实名认证

```
POST /api/v1/user/kyc
  体: { id_type, id_number, real_name, front_image, back_image }
```

### 余额

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  参数: page
  → 余额变动记录
```

### 地址管理

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  体: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### 会话管理

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → 撤销指定会话

DELETE /api/v1/user/account
  体: { confirm_password }
  → GDPR 账号注销
```

### 通知

```
GET /api/v1/user/notifications
  参数: page
  → 分页通知列表

POST /api/v1/user/notifications/{id}/read
  → 标记已读

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  体: { email: {order_paid: true, ...}, push: {...} }
```

### 邮箱

```
POST /api/v1/user/resend-verify-email
  → 重新发送验证邮件
```

### 文件上传

```
POST /api/v1/upload
  体: multipart/form-data { file, type: avatar/kyc/attach }
  限制: avatar 2MB, kyc 5MB, attach 10MB
  允许: jpg, jpeg, png, gif, pdf
  说明: 类型白名单校验 + finfo 内容嗅探（扩展名与 MIME 不符 → 422）
```

---

## 四、购物车与订单

### 购物车

```
POST /api/v1/cart
  体: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  体: { quantity }
```

> 金额字段约定（D4/P4.2 定案）：所有金额一律 string、4 位小数（如 "9.9900"），禁止 number/float——
> 与 MySQL DECIMAL 列经 PDO 的原始输出一致，精度由 4dp 字符串本身承载。涉及订单/余额/报表全端点。

### 订单

```
POST /api/v1/orders
  → 从购物车创建订单
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  参数: page, status (pending/paid/provisioning/completed/refunded，非法值返回 400)
  → 我的订单列表

GET /api/v1/orders/{id}
  → 订单详情 (含 items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → 可用支付通道 + 各通道实付金额

POST /api/v1/orders/{id}/pay    🔒 密码确认
  体: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### 优惠券

```
POST /api/v1/coupons/validate
  体: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（如 "2.0000"）

422: 无效/过期/不满足使用条件
```

### 发票

```
GET /api/v1/invoices
  参数: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF 下载
```

---

## 五、资源管理

```
GET /api/v1/resources
  参数: page, status
  → 我的资源列表

GET /api/v1/resources/{id}
  → 资源详情

GET /api/v1/resources/{id}/status
  → 资源当前状态 + 指标

GET /api/v1/resources/{id}/console
  → VNC/控制台 URL

POST /api/v1/resources/batch
  体: { action: start/stop/restart, resource_ids: [...] }
```

---

## 六、DNS 管理

```
GET /api/v1/dns/{domain}
  → DNS 记录列表

POST /api/v1/dns/{domain}/records
  体: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 密码确认
```

---

## 七、工单

```
POST /api/v1/tickets
  体: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  参数: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  体: { content }
```

---

## 八、供应商（内部 API）

```
POST /api/v1/supplier/apply
  体: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → 结算单列表

POST /api/v1/supplier/withdraw    🔒 密码确认
  体: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  体: { product_id }
  说明: commission_rate 由平台设置，供应商请求中的该字段会被忽略；结算只使用已审批费率
DELETE /api/v1/supplier/products/{id}
```

---

## 九、供应商外部 API

**认证:** `Authorization: Bearer sk_xxx...`（SHA256 验签）

**限流:** 120 req/min（提现 10 req/min）

```
GET /api/v1/supplier/external/orders
  参数: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → 订单详情（仅本供应商关联）

GET /api/v1/supplier/external/resources
  参数: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  参数: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  体: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  参数: page
```

---

## 十七、供应商评分与商品评价

### 公开

```
GET /api/v1/regions
  → 可用区域列表（含货币/时区）

GET /api/v1/suppliers/{supplierId}/ratings
  → 供应商评分列表（四维度: 质量/支持/交付速度/性价比，仅返回 approved）
```

### 用户端（需认证）

```
POST /api/v1/products/{productId}/reviews
  体: { rating, content, images? }
  → 提交商品评价（每订单一次，审核后展示）

POST /api/v1/supplier/ratings
  体: { supplier_id, quality, support, delivery_speed, value, comment? }
  → 提交供应商评分（每订单一次）

GET /api/v1/supplier/ratings/me
  → 我的评分记录
```

---

## 十八、支付 Webhook

```
POST /api/v1/payments/webhook/stripe
  头: Stripe-Signature: ...
  → Stripe 回调（支付成功/退款/争议），签名校验失败返回 400
```

---

## 十八点五、服务内部 API

```
POST /internal/v1/refunds/execute
  头: X-Internal-Token: <INTERNAL_MONITOR_TOKEN>
  体: { refund_id, operator_id }
  → 执行 pending/failed 退款单（整单全额）；admin 审批调用
  → 400 参数非法 / 403 token 缺失或错误 / 404 退款单或订单不存在 / 409 业务拒绝 / 502 Stripe 失败
```

> 该端点不对外暴露：nginx 对 `/internal/` 直接 404；缺少非空 `INTERNAL_MONITOR_TOKEN` 时 fail-closed。

---

## 十九、WebSocket 事件

**连接:** `ws://host:8282`（docker 部署下 WS 经 nginx 反代，连接地址为 `ws://host/ws/`，8282 仅容器内暴露）

认证走连接后首条消息（token 不进 URL/访问日志）：连接建立后须先发 `auth` 消息，30 秒内未认证会被断开；认证失败返回 `error` 并断开。

### 客户端 → 服务端

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### 服务端 → 客户端

```json
{ "type": "connected", "user_id": 123 }
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

## 二十、错误码参考

| code | 说明 |
|------|------|
| 400 | 参数错误 / 不支持的 API 版本 / 不支持的客户端平台 |
| 401 | 未认证 / Token 过期 / 无效 API Key / 设备指纹不匹配（Device mismatch） |
| 403 | 无权限 / 非供应商角色 / WAF 拦截 / 密码确认失败 |
| 404 | 资源不存在（firstOrFail/findOrFail 未命中统一映射 404） |
| 413 | 请求体超过 10MB |
| 414 | URL 超过 2KB |
| 415 | Content-Type 不在白名单 (仅允许 application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | 参数校验失败（邮箱已注册 / 库存不足 / 可提现余额不足 / 已提交过申请） |
| 429 | 请求频率超限 |
| 500 | 服务端错误 |

### 常见 422 消息

| 消息 | 端点 |
|------|------|
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | 管理后台功能开关 |
| `Refund window expired: server orders are refundable within 72 hours of payment` | 管理后台退款操作 |
| `Refund window expired: domain orders are refundable within 5 days of payment` | 管理后台退款操作 |
| `This product type (IP) is not refundable` | 管理后台退款操作 |
