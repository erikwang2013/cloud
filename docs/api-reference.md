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
| `/health` (内部) | 全局 + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
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
  状态枚举: pending(待审核)/approved(已通过)/rejected(已拒绝)，仅返回 approved
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

支持提供商：google, apple, facebook, x, microsoft, linkedin, github
（由 .env 中 `{PROVIDER}_OAUTH_CLIENT_ID` 等配置决定是否启用）

```
GET /api/auth/{provider}            → { url }        # 跳转授权页（PKCE/nonce 防重放）
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  体: { code, state }
```

- Apple/Microsoft 返回 id_token，服务端经 JWKS 校验签名、iss/aud/exp/nonce
- 所有提供商要求 `email_verified=true` 才允许登录，否则 422
- `state` 缺失或不匹配 → 422（防 CSRF，5 分钟过期）
- OAuth 流程限流：每 60 秒 10 次（redirect + callback）

### 密码重置

```
POST /api/auth/forgot-password
  体: { email }
  → 发送验证码邮件

POST /api/auth/reset-password
  体: { email, code, password }
  → 重置成功
  → 错误累计 5 次 → 429 限流 10 分钟
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
POST /api/user/totp/setup        → { secret, qr_url }        # 未持久化，10 分钟内需 verify 生效
POST /api/user/totp/verify       体: { code } → { verified: true }   # 首次启用时返回启用成功消息
POST /api/user/totp/disable      体: { password }             # 需密码确认，否则 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # 每次生成 8 个一次性码，需密码确认，否则 403
POST /api/auth/login/recovery    体: { login, password, recovery_code }
```

- 用户启用 TOTP 后登录必须携带 `totp_code`，否则 401
- TOTP 连续错误 5 次 → 该用户锁定 15 分钟（login_lock）

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
  说明: 类型白名单校验 + finfo 内容嗅探（扩展名与 MIME 不符 → 422）
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

> 金额字段约定（D4/P4.2 定案）：所有金额一律 string、4 位小数（如 "9.9900"），禁止 number/float——
> 与 MySQL DECIMAL 列经 PDO 的原始输出一致，精度由 4dp 字符串本身承载。涉及订单/余额/报表全端点。

### 订单

```
POST /api/orders
  → 从购物车创建订单
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
  参数: page, status (pending/paid/provisioning/completed/refunded，非法值返回 400)
  → 我的订单列表

GET /api/orders/{id}
  → 订单详情 (含 items, timeline)

GET /api/orders/{id}/payment-methods
  → 可用支付通道 + 各通道实付金额

POST /api/orders/{id}/pay    🔒 密码确认
  体: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### 优惠券

```
POST /api/coupons/validate
  体: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（如 "2.0000"）

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
GET /admin/api/payments/reconcile     参数: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run  参数: date; 触发按日对账
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
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL) 与 bcmath 汇总一致）
GET /admin/api/reports/supplier          参数: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region            参数: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
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

## 十一、SSL 证书

### 用户端

```
GET /api/ssl/plans
  → SSL 套餐列表（DV/OV/EV，价格含 register/renew/transfer）

GET /api/ssl-certs
  → 我的证书列表（含 status: pending/active/expired/revoked）

GET /api/ssl-certs/{id}
  → 证书详情（域名、签发机构、有效期、续期状态）

GET /api/ssl-certs/{id}/download
  → 下载证书文件（证书链 + 私钥）

POST /api/ssl-certs/{id}/auto-renew
  体: { auto_renew: true/false }
  → 切换自动续期
```

### 管理端

```
GET /admin/api/ssl/plans              → 套餐列表
POST /admin/api/ssl/plans             → 创建套餐
PUT /admin/api/ssl/plans/{id}         → 更新套餐
DELETE /admin/api/ssl/plans/{id}      → 删除套餐
GET /admin/api/ssl/certs              → 全部证书
POST /admin/api/ssl/certs/{id}/revoke → 吊销证书
```

---

## 十二、对象存储

S3 兼容对象存储，通过预签名 URL 上传/下载，密钥不外传。

```
GET /api/storage/buckets
  → 我的存储桶列表（用量、状态）

GET /api/storage/buckets/{id}
  → 存储桶详情

POST /api/storage/buckets/{id}/presign-upload
  体: { filename, content_type, size }
  → { upload_url, object_key } 预签名上传 URL（限时）

POST /api/storage/buckets/{id}/presign-download
  体: { object_key }
  → 预签名下载 URL（限时）

GET /api/storage/buckets/{id}/credentials
  → 临时访问凭证（短期有效，用于 SDK 直传）
```

---

## 十三、CDN 加速

### 用户端

```
GET /api/cdn/domains
  → 我的 CDN 域名列表（源站、状态、套餐）

GET /api/cdn/domains/{id}
  → CDN 域名详情

POST /api/cdn/domains/{id}/purge
  → 清除缓存（全站或指定 URL 列表）

GET /api/cdn/domains/{id}/stats
  参数: range (day/week/month)
  → 流量/请求数/命中率统计
```

### 管理端

```
GET /admin/api/cdn/domains            → 全部 CDN 域名
PUT /admin/api/cdn/domains/{id}       → 更新域名套餐/配置
```

---

## 十四、按量计费

```
GET /admin/api/billing/rates          → 计费费率列表（按资源类型/规格）
POST /admin/api/billing/rates         → 创建费率
PUT /admin/api/billing/rates/{id}     → 更新费率
DELETE /admin/api/billing/rates/{id}  → 删除费率
GET /admin/api/billing/usage          → 用量汇总（按用户/资源聚合）
```

计费管线：ResourceMonitor 每 5 分钟采集 → UsageAggregator 每小时聚合 → BillingEngine 每日扣款，余额不足挂起资源。

---

## 十五、联盟佣金（Affiliate）

### 用户端

```
GET /api/affiliate/summary
  → 佣金总览（累计/待结算/可提现、链接数、转化率）

POST /api/affiliate/links
  体: { source? }
  → 生成推广链接（?ref=CODE）

GET /api/affiliate/earnings
  参数: status, page
  → 佣金明细（订单归属、比例、状态: pending/approved/paid）

POST /api/affiliate/payout
  体: { amount, method }
  → 发起提现申请
```

### 管理端

```
GET /admin/api/affiliate/plans                → 佣金方案列表
POST /admin/api/affiliate/plans               → 创建佣金方案
GET /admin/api/affiliate/earnings             → 全部佣金记录
POST /admin/api/affiliate/earnings/{id}/approve → 审核佣金
GET /admin/api/affiliate/payouts              → 提现申请列表
POST /admin/api/affiliate/payouts/{id}/approve → 审核/打款提现
```

---

## 十六、GraphQL

```
POST /graphql
  → 公开查询（商品、域名、帮助等只读数据）
  限制: 查询深度 5 层，复杂度 100

POST /api/graphql                          🔒 需认证
  → 完整查询（含用户数据）
```

**敏感操作保持 REST-only：** 支付、提现、退款、KYC 审核不走 GraphQL。

---

## 十七、供应商评分与商品评价

### 公开

```
GET /api/regions
  → 可用区域列表（含货币/时区）

GET /api/suppliers/{supplierId}/ratings
  → 供应商评分列表（四维度: 质量/支持/交付速度/性价比，仅返回 approved）
```

### 用户端（需认证）

```
POST /api/products/{productId}/reviews
  体: { rating, content, images? }
  → 提交商品评价（每订单一次，审核后展示）

POST /api/supplier/ratings
  体: { supplier_id, quality, support, delivery_speed, value, comment? }
  → 提交供应商评分（每订单一次）

GET /api/supplier/ratings/me
  → 我的评分记录
```

### 管理端

```
GET /admin/api/suppliers/{id}/ratings          → 全部评分（含 pending）
POST /admin/api/suppliers/ratings/{id}/approve → 审核通过
POST /admin/api/suppliers/ratings/{id}/hide    → 隐藏
```

---

## 十八、支付 Webhook

```
POST /api/payments/webhook/stripe
  头: Stripe-Signature: ...
  → Stripe 回调（支付成功/退款/争议），签名校验失败返回 400
```

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
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
