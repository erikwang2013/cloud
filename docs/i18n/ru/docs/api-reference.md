# Документация API CloudPlatform

## Обзор

**Base URL:** `https://api.example.com`

**Версионирование:** версия API указывается в пути URL (например, `/api/v1/products`). Неподдерживаемые версии возвращают `400`.

**Способы аутентификации:**

| Сторона | Способ | Заголовок |
|----|------|--------|
| Пользователь | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Админ-панель | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Внешний API поставщика | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | Проверка подписи | `Stripe-Signature: ...` |

**Клиентские платформы:** во всех API-запросах рекомендуется передавать заголовок `X-Client-Platform`; поддерживаются `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Мультиязычность:** во всех API-запросах рекомендуется передавать заголовок `Accept-Language` (`zh-CN` / `en-US`); влияет на тексты переводов и возвращаемые значения мультиязычных JSON-полей. При отсутствии используется `en-US`.

---

## Единый формат ответа

### Успех

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Пагинация

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Ошибка

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP-статус-коды

| code | Описание |
|------|------|
| 0 | Успех |
| 400 | Ошибка параметров запроса / неподдерживаемая версия API / неподдерживаемая клиентская платформа |
| 401 | Не аутентифицирован |
| 403 | Нет прав / блокировка WAF |
| 404 | Ресурс не найден (firstOrFail/findOrFail без совпадения единообразно мапится в 404) |
| 413 | Тело запроса слишком велико (>10MB) |
| 414 | URL слишком длинный (>2KB) |
| 415 | Неподдерживаемый Content-Type |
| 422 | Ошибка валидации параметров |
| 429 | Превышен лимит частоты запросов |

---

## Группы маршрутов и матрица промежуточных слоёв

| Группа маршрутов | Промежуточные слои | Префикс |
|--------|--------|------|
| Публичные | Глобальная цепочка middleware | `/health`, `/api/v1/*` |
| `/health` (внутренние) | Глобальная + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | Глобальная + Encryption | `/api/v1/auth/*` |
| `/api/v1` (пользовательские) | Глобальная + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (чувствительные) | Глобальная + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | Внешний API поставщика |
| `/admin/api/v1` | Глобальная + Encryption + Auth + AdminRole | API админ-панели |
| `/admin/api/v1` (чувствительные) | Глобальная + Encryption + Auth + AdminRole + Confirmation | Чувствительные админ-операции |

---

## 1. Публичные конечные точки

### Проверка работоспособности

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Статус сервиса

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

### Товары

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

### Домены

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → 可用 TLD 列表 (Redis 缓存 1h)
```

### Центр помощи

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

## 2. Конечные точки аутентификации

### Captcha

```
POST /api/v1/captcha/create
  头: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Регистрация

```
POST /api/v1/auth/register
  头: X-Encrypted: 1
  体(加密): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 3 req/min
```

- `deviceFingerprint` (необязательно): при регистрации записывается отпечаток устройства, проверяется при входе/обновлении токена; если не передан — привязка отпечатка пропускается
- email/phone перед хранением шифруются детерминированным шифрованием Encryptable (ECB, поиск по равенству зашифрованного текста); проверка уникальности и запросы входа выполняются по зашифрованному тексту

### Вход

```
POST /api/v1/auth/login
  头: X-Encrypted: 1
  体(加密): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

限流: 5 req/min, 5 次失败锁 15min
```

- `login` ищется по зашифрованному тексту (детерминированное шифрование Encryptable); поиск по открытому тексту не попадает в зашифрованные столбцы

### Обновление токена

```
POST /api/v1/auth/refresh
  头: X-Encrypted: 1
  体(加密): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` не совпадает с записанным при регистрации → 401 `Device mismatch`; refresh-токен ищется по хэшу зашифрованного текста

### OAuth

Поддерживаемые провайдеры: google, apple, facebook, x, microsoft, linkedin, github
(включение определяется конфигурацией `{PROVIDER}_OAUTH_CLIENT_ID` и др. в .env)

```
GET /api/v1/auth/{provider}            → { url }        # 跳转授权页（PKCE/nonce 防重放）
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  体: { code, state }
```

- Apple/Microsoft возвращают id_token, сервер проверяет подпись через JWKS, а также iss/aud/exp/nonce
- Все провайдеры требуют `email_verified=true` для входа, иначе 422
- `state` отсутствует или не совпадает → 422 (защита от CSRF, срок действия 5 минут)
- Лимит OAuth-процесса: 10 запросов за 60 секунд (redirect + callback)

### Сброс пароля

```
POST /api/v1/auth/forgot-password
  体: { email }
  → 发送验证码邮件

POST /api/v1/auth/reset-password
  体: { email, code, password }
  → 重置成功
  → 错误累计 5 次 → 429 限流 10 分钟
```

### Подтверждение email

```
GET /api/v1/auth/verify-email?token=xxx
  → 验证成功
```

### SMS-подтверждение

```
POST /api/v1/auth/send-sms
  体: { phone }
  → 发送短信验证码 (60s 冷却)
```

### TOTP — двухфакторная аутентификация

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # 未持久化，10 分钟内需 verify 生效
POST /api/v1/user/totp/verify       体: { code } → { verified: true }   # 首次启用时返回启用成功消息
POST /api/v1/user/totp/disable      体: { password }             # 需密码确认，否则 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # 每次生成 8 个一次性码，需密码确认，否则 403
POST /api/v1/auth/login/recovery    体: { login, password, recovery_code }
```

- После включения TOTP вход пользователя требует `totp_code`, иначе 401
- 5 последовательных ошибок TOTP → блокировка пользователя на 15 минут (login_lock)

---

## 3. Пользовательские конечные точки (требуется аутентификация)

### Профиль

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  体: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC — верификация личности

```
POST /api/v1/user/kyc
  体: { id_type, id_number, real_name, front_image, back_image }
```

### Баланс

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  参数: page
  → 余额变动记录
```

### Управление адресами

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  体: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### Управление сессиями

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → 撤销指定会话

DELETE /api/v1/user/account
  体: { confirm_password }
  → GDPR 账号注销
```

### Уведомления

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

### Email

```
POST /api/v1/user/resend-verify-email
  → 重新发送验证邮件
```

### Загрузка файлов

```
POST /api/v1/upload
  体: multipart/form-data { file, type: avatar/kyc/attach }
  限制: avatar 2MB, kyc 5MB, attach 10MB
  允许: jpg, jpeg, png, gif, pdf
  说明: 类型白名单校验 + finfo 内容嗅探（扩展名与 MIME 不符 → 422）
```

---

## 4. Корзина и заказы

### Корзина

```
POST /api/v1/cart
  体: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  体: { quantity }
```

> Соглашение о полях суммы (решение D4/P4.2): все суммы передаются как string с 4 знаками после запятой (например, "9.9900"), number/float запрещены —
> это соответствует исходному выводу столбцов MySQL DECIMAL через PDO, точность обеспечивается самой строкой 4dp. Применяется ко всем конечным точкам заказов/баланса/отчётов.

### Заказы

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

### Купоны

```
POST /api/v1/coupons/validate
  体: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（如 "2.0000"）

422: 无效/过期/不满足使用条件
```

### Счета-фактуры

```
GET /api/v1/invoices
  参数: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF 下载
```

---

## 5. Управление ресурсами

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

## 6. Управление DNS

```
GET /api/v1/dns/{domain}
  → DNS 记录列表

POST /api/v1/dns/{domain}/records
  体: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 密码确认
```

---

## 7. Заявки

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

## 8. Поставщики (внутренний API)

```
POST /api/v1/supplier/apply
  体: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → 结算单列表

POST /api/v1/supplier/withdraw    🔒 密码确认
  体: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  体: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. Внешний API поставщиков

**Аутентификация:** `Authorization: Bearer sk_xxx...` (проверка подписи SHA256)

**Лимит запросов:** 120 req/min (вывод средств 10 req/min)

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

## 10. API админ-панели

**Аутентификация:** JWT Bearer Token + роль Admin

### Дашборд

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Управление пользователями

```
GET /admin/api/v1/users              参数: page, status, keyword
GET /admin/api/v1/users/export       → Excel 下载
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  体: { status }
```

### Проверка KYC

```
GET /admin/api/v1/kyc                参数: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 密码确认
  体: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 密码确认
  体: { confirm_password, reason }
```

### Управление товарами

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 密码确认
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV 下载
POST /admin/api/v1/products/import        → CSV 上传 upsert
```

### Управление заказами

```
GET /admin/api/v1/orders              参数: page, status, keyword
GET /admin/api/v1/orders/export       → Excel 下载
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 密码确认
  体: { confirm_password, amount?, reason }
```

### Управление платежами

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions  参数: page, channel, status
GET /admin/api/v1/payments/reconcile     参数: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run  参数: date; 触发按日对账
```

### Ресурсы и выделение

```
GET /admin/api/v1/provisioning/tasks              参数: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  体: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 密码确认
GET /admin/api/v1/provisioning/hosts
```

### Управление поставщиками

```
GET /admin/api/v1/suppliers                 参数: page, status
GET /admin/api/v1/suppliers/export          → Excel 下载

POST /admin/api/v1/suppliers/{id}/approve    🔒 密码确认
POST /admin/api/v1/suppliers/{id}/settle     🔒 密码确认
  体: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 密码确认
```

### API-ключи поставщиков

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  体: { name }
  ← { api_key: "sk_xxx...", prefix } (仅显示一次)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### Управление заявками

```
GET /admin/api/v1/tickets                  参数: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign     体: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### Управление доменами

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  体: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones             参数: page
GET /admin/api/v1/domains/transfers         参数: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### Управление уведомлениями

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  体: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log         参数: page
```

### Купоны

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  体: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### Статьи помощи

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  体: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → 软删除 (status=archived)
```

### API облачных провайдеров

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  体: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → 禁用 (status=disabled)
```

### Управление Webhook

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  体: { url }
DELETE /admin/api/v1/webhooks              体: { id }
POST /admin/api/v1/webhooks/test           体: { url }
```

### Отчёты

```
GET /admin/api/v1/reports/revenue           参数: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL) 与 bcmath 汇总一致）
GET /admin/api/v1/reports/supplier          参数: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region            参数: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Мониторинг

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Журналы аудита

```
GET /admin/api/v1/audit-logs                参数: page, user_id, action, from, to
  → 分页审计日志 (含 client_platform)
```

### Функциональные флаги

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  体: { action: enable/disable/toggle/reset }
```

### Конфигурация системы

```
PUT /admin/api/v1/system/config              🔒 密码确认
```

### Импорт/экспорт товаров

```
GET /admin/api/v1/products/export           → CSV 下载
POST /admin/api/v1/products/import          → CSV 上传 upsert
```

### Экспорт поставщиков и пользователей

```
GET /admin/api/v1/suppliers/export          → Excel 下载
GET /admin/api/v1/users/export              → Excel 下载
GET /admin/api/v1/orders/export             → Excel 下载
```

---

## 11. SSL-сертификаты

### Клиентская часть

```
GET /api/v1/ssl/plans
  → SSL 套餐列表（DV/OV/EV，价格含 register/renew/transfer）

GET /api/v1/ssl-certs
  → 我的证书列表（含 status: pending/active/expired/revoked）

GET /api/v1/ssl-certs/{id}
  → 证书详情（域名、签发机构、有效期、续期状态）

GET /api/v1/ssl-certs/{id}/download
  → 下载证书文件（证书链 + 私钥）

POST /api/v1/ssl-certs/{id}/auto-renew
  体: { auto_renew: true/false }
  → 切换自动续期
```

### Админ-панель

```
GET /admin/api/v1/ssl/plans              → 套餐列表
POST /admin/api/v1/ssl/plans             → 创建套餐
PUT /admin/api/v1/ssl/plans/{id}         → 更新套餐
DELETE /admin/api/v1/ssl/plans/{id}      → 删除套餐
GET /admin/api/v1/ssl/certs              → 全部证书
POST /admin/api/v1/ssl/certs/{id}/revoke → 吊销证书
```

---

## 12. Объектное хранилище

S3-совместимое объектное хранилище: загрузка/скачивание через предварительно подписанные URL, ключи доступа наружу не передаются.

```
GET /api/v1/storage/buckets
  → 我的存储桶列表（用量、状态）

GET /api/v1/storage/buckets/{id}
  → 存储桶详情

POST /api/v1/storage/buckets/{id}/presign-upload
  体: { filename, content_type, size }
  → { upload_url, object_key } 预签名上传 URL（限时）

POST /api/v1/storage/buckets/{id}/presign-download
  体: { object_key }
  → 预签名下载 URL（限时）

GET /api/v1/storage/buckets/{id}/credentials
  → 临时访问凭证（短期有效，用于 SDK 直传）
```

---

## 13. Ускорение CDN

### Клиентская часть

```
GET /api/v1/cdn/domains
  → список моих CDN-доменов (origin, статус, тариф)

POST /api/v1/cdn/domains
  тело: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
          origin_type (server|storage), origin_value, cert_config? }
  → создание CDN-домена (создание и привязка origin на стороне провайдера)
  → для provider_type=aliyun|tencent домен должен пройти ICP-регистрацию (иначе 4002)
  → в ответе есть поле-подсказка requires_icp_registration
  → разрешение учётных данных: сначала привязанная запись (provider_account_id),
    иначе активная запись provider_apis по code=cdn-{provider_type},
    иначе конфигурация env

GET /api/v1/cdn/domains/{id}
  → детали CDN-домена

DELETE /api/v1/cdn/domains/{id}
  → удаление CDN-домена (отключение домена на стороне провайдера, идемпотентно)

POST /api/v1/cdn/domains/{id}/purge
  тело: { urls: ["https://cdn.example.com/path"] }
  → очистка кэша (повторяющиеся URL автоматически дедуплицируются, идемпотентно; не более 100)

GET /api/v1/cdn/domains/{id}/stats
  → обзор домена (cdn_domain / provider_type / plan / status / purged_at)
```

### Админ-панель

```
GET /admin/api/v1/cdn/domains            → все CDN-домены (с владельцем-пользователем)
PUT /admin/api/v1/cdn/domains/{id}       → обновление тарифа домена (белый список plan: standard | pro | enterprise)
```

Админ-маршруты CDN защищены `RbacMiddleware('cdn.manage')`, изменение тарифа пишется в журнал аудита (`admin_cdn_update_plan`). Учётные данные провайдеров ведутся через CRUD `/admin/api/v1/providers` (RbacMiddleware `provider.config`, `code` по соглашению `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, учётные данные шифруются через Encryptable).

### Коды ошибок CDN

| code | Описание |
|------|----------|
| 4001 | Отсутствуют/некорректны параметры CDN (пустой urls, недопустимый provider_type, ошибка формата домена) |
| 4002 | Домен не прошёл ICP-регистрацию (маппится при отказе API Aliyun/Tencent) |
| 4003 | Учётные данные провайдера CDN не настроены (запись отсутствует/отключена, строгая привязка без тихого переключения) |
| 4005 | Сбой очистки кэша CDN |
| 5001 | Сбой вызова API провайдера CDN |

> Чужие/несуществующие CDN-ресурсы (не принадлежащие текущему пользователю) единообразно возвращают **404** (маппинг findOrFail, без раскрытия факта существования ресурса), отдельного бизнес-кода нет.

---

## 14. Оплата по факту использования (pay-as-you-go)

```
GET /admin/api/v1/billing/rates          → 计费费率列表（按资源类型/规格）
POST /admin/api/v1/billing/rates         → 创建费率
PUT /admin/api/v1/billing/rates/{id}     → 更新费率
DELETE /admin/api/v1/billing/rates/{id}  → 删除费率
GET /admin/api/v1/billing/usage          → 用量汇总（按用户/资源聚合）
```

Конвейер биллинга: ResourceMonitor собирает данные каждые 5 минут → UsageAggregator агрегирует каждый час → BillingEngine списывает ежедневно; при недостаточном балансе ресурсы приостанавливаются.

---

## 15. Партнёрские комиссии (Affiliate)

### Клиентская часть

```
GET /api/v1/affiliate/summary
  → 佣金总览（累计/待结算/可提现、链接数、转化率）

POST /api/v1/affiliate/links
  体: { source? }
  → 生成推广链接（?ref=CODE）

GET /api/v1/affiliate/earnings
  参数: status, page
  → 佣金明细（订单归属、比例、状态: pending/approved/paid）

POST /api/v1/affiliate/payout
  体: { amount, method }
  → 发起提现申请
```

### Админ-панель

```
GET /admin/api/v1/affiliate/plans                → 佣金方案列表
POST /admin/api/v1/affiliate/plans               → 创建佣金方案
GET /admin/api/v1/affiliate/earnings             → 全部佣金记录
POST /admin/api/v1/affiliate/earnings/{id}/approve → 审核佣金
GET /admin/api/v1/affiliate/payouts              → 提现申请列表
POST /admin/api/v1/affiliate/payouts/{id}/approve → 审核/打款提现
```

---

## 16. GraphQL

```
POST /graphql
  → 公开查询（商品、域名、帮助等只读数据）
  限制: 查询深度 5 层，复杂度 100

POST /api/v1/graphql                          🔒 需认证
  → 完整查询（含用户数据）
```

**Чувствительные операции остаются только в REST:** оплата, вывод средств, возвраты и проверка KYC не выполняются через GraphQL.

---

## 17. Рейтинги поставщиков и отзывы о товарах

### Публичные

```
GET /api/v1/regions
  → 可用区域列表（含货币/时区）

GET /api/v1/suppliers/{supplierId}/ratings
  → 供应商评分列表（四维度: 质量/支持/交付速度/性价比，仅返回 approved）
```

### Клиентская часть (требуется аутентификация)

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

### Админ-панель

```
GET /admin/api/v1/suppliers/{id}/ratings          → 全部评分（含 pending）
POST /admin/api/v1/suppliers/ratings/{id}/approve → 审核通过
POST /admin/api/v1/suppliers/ratings/{id}/hide    → 隐藏
```

---

## 18. Платёжный Webhook

```
POST /api/v1/payments/webhook/stripe
  头: Stripe-Signature: ...
  → Stripe 回调（支付成功/退款/争议），签名校验失败返回 400
```

---

## 19. События WebSocket

**Подключение:** `ws://host:8282` (при docker-развёртывании WS проксируется через nginx, адрес подключения — `ws://host/ws/`, порт 8282 доступен только внутри контейнера)

Аутентификация выполняется первым сообщением после установки соединения (токен не попадает в URL/журналы доступа): после подключения необходимо отправить сообщение `auth`, при отсутствии аутентификации в течение 30 секунд соединение разрывается; при неудачной аутентификации возвращается `error`, и соединение разрывается.

### Клиент → сервер

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Сервер → клиент

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Push-события

| Событие | Данные | Момент срабатывания |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Оплата прошла успешно |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Выделение ресурса завершено |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | Ресурс скоро истечёт |
| `ticket.updated` | `{ticket_id, title, status}` | Изменение статуса заявки |
| `notification.new` | `{notification_id, title, body}` | Новое уведомление |

---

## 20. Справочник кодов ошибок

| code | Описание |
|------|------|
| 400 | Ошибка параметров / неподдерживаемая версия API / неподдерживаемая клиентская платформа |
| 401 | Не аутентифицирован / токен истёк / недействительный API Key / несовпадение отпечатка устройства (Device mismatch) |
| 403 | Нет прав / не роль поставщика / блокировка WAF / не пройдено подтверждение пароля |
| 404 | Ресурс не найден (firstOrFail/findOrFail без совпадения единообразно мапится в 404) |
| 413 | Тело запроса превышает 10MB |
| 414 | URL превышает 2KB |
| 415 | Content-Type не в белом списке (разрешены только application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | Ошибка валидации параметров (email уже зарегистрирован / недостаточно остатка / недостаточно выводимого баланса / заявка уже подана) |
| 429 | Превышен лимит частоты запросов |
| 500 | Ошибка сервера |

### Частые сообщения 422

| Сообщение | Конечная точка |
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
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
