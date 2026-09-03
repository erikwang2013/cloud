# توثيق واجهات CloudPlatform API

## نظرة عامة

**Base URL:** `https://api.example.com`

**التحكم بالإصدار:** عبر مسار الـ URL، مثل `/api/v1/...`. تُرجع الإصدارات غير المدعومة `400`.

**طرق المصادقة:**

| الطرف | الطريقة | الرأس |
|----|------|--------|
| المستخدم | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| الإدارة | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| واجهة الموردين الخارجية | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | التحقق من التوقيع | `Stripe-Signature: ...` |

**منصة العميل:** يُنصح بأن تحمل جميع طلبات API رأس `X-Client-Platform`، ويدعم `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**تعدد اللغات:** يُنصح بأن تحمل جميع طلبات API رأس `Accept-Language` (`zh-CN` / `en-US`)، ويؤثر على النصوص المترجمة وقيم حقول JSON متعددة اللغات. الافتراضي `en-US` عند الغياب.

---

## تنسيق الاستجابة الموحد

### النجاح

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### الترقيم الصفحي

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### الخطأ

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### رموز حالة HTTP

| code | الوصف |
|------|------|
| 0 | نجاح |
| 400 | خطأ في معاملات الطلب / إصدار API غير مدعوم / منصة عميل غير مدعومة |
| 401 | غير مصادق |
| 403 | لا صلاحية / اعتراض WAF |
| 404 | المورد غير موجود (عدم إصابة firstOrFail/findOrFail يُعيّن 404 بشكل موحد) |
| 413 | جسم الطلب كبير جداً (>10MB) |
| 414 | URL طويل جداً (>2KB) |
| 415 | Content-Type غير مدعوم |
| 422 | فشل التحقق من المعاملات |
| 429 | تجاوز حد تردد الطلبات |

---

## مجموعات المسارات ومصفوفة الوسائط

| مجموعة المسارات | الوسائط | البادئة |
|--------|--------|------|
| عامة | سلسلة الوسائط العامة | `/health`, `/api/v1/*` |
| `/health` (داخلي) | العامة + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | العامة + Encryption | `/api/v1/auth/*` |
| `/api/v1` (المستخدم) | العامة + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (حساس) | العامة + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | واجهة الموردين الخارجية |
| `/admin/api/v1` | العامة + Encryption + Auth + AdminRole | واجهات لوحة الإدارة |
| `/admin/api/v1` (حساس) | العامة + Encryption + Auth + AdminRole + Confirmation | عمليات الإدارة الحساسة |

---

## 1. نقاط النهاية العامة
### فحص الصحة

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### حالة الخدمة

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

### المنتجات

```
GET /api/v1/products
  المعاملات: category_id, region_id, keyword, supplier_id, page (الافتراضي 1), page_size (الافتراضي 20, الأقصى 50)
  → قائمة منتجات بترقيم صفحات (تتضمن category, skus.regionPrices)

GET /api/v1/products/search
  المعاملات: q (إلزامي), page
  → بحث نصي كامل عبر Elasticsearch

GET /api/v1/products/{id}
  → تفاصيل المنتج (تتضمن category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → قائمة التقييمات + avg_rating + total + distribution
  تعداد الحالة: pending(قيد المراجعة)/approved(معتمد)/rejected(مرفوض)، يُرجع approved فقط
```

### النطاقات

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → قائمة نطاقات المستوى الأعلى المتاحة (ذاكرة Redis مؤقتة لمدة 1 ساعة)
```

### مركز المساعدة

```
GET /api/v1/help
  المعاملات: category, page
  الرأس: Accept-Language (en-US / zh-CN)
  → مقالات مساعدة بترقيم صفحات

GET /api/v1/help/categories
  → قائمة تصنيفات المقالات

GET /api/v1/help/{slug}
  → تفاصيل مقالة واحدة
```

---

## 2. نقاط نهاية المصادقة
### كابتشا التحقق

```
POST /api/v1/captcha/create
  الرأس: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### التسجيل

```
POST /api/v1/auth/register
  الرأس: X-Encrypted: 1
  الجسم (مشفّر): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

الحد من التردد: 3 req/min
```

- `deviceFingerprint` (اختياري): يسجّل بصمة الجهاز عند التسجيل، ويتحقق منها عند تسجيل الدخول/التجديد؛ في حال عدم إرسالها يُتخطى ربط البصمة
- يُشفَّر email/phone بتشفير حتمي قبل التخزين عبر Encryptable (ECB، استعلام تكافؤ النص المشفر)، وتتم كل من تحقق التفرد واستعلامات تسجيل الدخول بالنص المشفر

### تسجيل الدخول

```
POST /api/v1/auth/login
  الرأس: X-Encrypted: 1
  الجسم (مشفّر): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

الحد من التردد: 5 req/min، 5 محاولات فاشلة تقفل 15 دقيقة
```

- يُستعلم عن `login` بمكافئ النص المشفر (تشفير حتمي Encryptable)، ولا يصيب الاستعلام بالنص الصريح الأعمدة المشفرة

### تجديد Token

```
POST /api/v1/auth/refresh
  الرأس: X-Encrypted: 1
  الجسم (مشفّر): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- عدم تطابق `deviceFingerprint` مع ما سُجّل عند التسجيل ← 401 `Device mismatch`؛ ويُستعلم عن رمز التجديد بتجزئة النص المشفر

### OAuth

مزودو الدعم: google, apple, facebook, x, microsoft, linkedin, github
(يُحدد التفعيل من إعدادات مثل `{PROVIDER}_OAUTH_CLIENT_ID` في .env)

```
GET /api/v1/auth/{provider}            → { url }        # انتقال إلى صفحة التفويض (PKCE/nonce ضد إعادة الإرسال)
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  الجسم: { code, state }
```

- يُرجع Apple/Microsoft id_token، ويتحقق الخادم من التوقيع عبر JWKS ومن iss/aud/exp/nonce
- جميع المزودين يشترطون `email_verified=true` للسماح بتسجيل الدخول، وإلا 422
- غياب `state` أو عدم تطابقه ← 422 (ضد CSRF، تنتهي الصلاحية بعد 5 دقائق)
- حد تردد تدفق OAuth: 10 مرات كل 60 ثانية (redirect + callback)

### إعادة تعيين كلمة المرور

```
POST /api/v1/auth/forgot-password
  الجسم: { email }
  → إرسال بريد بكود التحقق

POST /api/v1/auth/reset-password
  الجسم: { email, code, password }
  → نجاح إعادة التعيين
  → تراكم 5 أخطاء ← 429 تقييد 10 دقائق
```

### التحقق من البريد الإلكتروني

```
GET /api/v1/auth/verify-email?token=xxx
  → نجاح التحقق
```

### التحقق بالرسائل القصيرة

```
POST /api/v1/auth/send-sms
  الجسم: { phone }
  → إرسال رمز تحقق عبر الرسائل القصيرة (فترة تبريد 60 ثانية)
```

### التحقق الثنائي TOTP

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # لا يُخزَّن، يجب تفعيله عبر verify خلال 10 دقائق
POST /api/v1/user/totp/verify       الجسم: { code } → { verified: true }   # عند أول تفعيل يُرجع رسالة نجاح التفعيل
POST /api/v1/user/totp/disable      الجسم: { password }             # يتطلب تأكيد كلمة المرور، وإلا 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # يولّد 8 رموز لمرة واحدة في كل مرة، يتطلب تأكيد كلمة المرور، وإلا 403
POST /api/v1/auth/login/recovery    الجسم: { login, password, recovery_code }
```

- بعد تفعيل المستخدم TOTP، يجب أن يحمل تسجيل الدخول `totp_code`، وإلا 401
- 5 أخطاء متتالية في TOTP ← قفل المستخدم 15 دقيقة (login_lock)

---

## 3. نقاط نهاية المستخدم (تتطلب المصادقة)
### الملف الشخصي

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  الجسم: { nickname?, avatar?, country?, language?, timezone? }
```

### تحقق الهوية KYC

```
POST /api/v1/user/kyc
  الجسم: { id_type, id_number, real_name, front_image, back_image }
```

### الرصيد

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  المعاملات: page
  → سجل تغييرات الرصيد
```

### إدارة العناوين

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  الجسم: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### إدارة الجلسات

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → إلغاء الجلسة المحددة

DELETE /api/v1/user/account
  الجسم: { confirm_password }
  → حذف الحساب وفقاً لـ GDPR
```

### الإشعارات

```
GET /api/v1/user/notifications
  المعاملات: page
  → قائمة إشعارات بترقيم صفحات

POST /api/v1/user/notifications/{id}/read
  → تعليم كمقروء

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  الجسم: { email: {order_paid: true, ...}, push: {...} }
```

### البريد الإلكتروني

```
POST /api/v1/user/resend-verify-email
  → إعادة إرسال بريد التحقق
```

### رفع الملفات

```
POST /api/v1/upload
  الجسم: multipart/form-data { file, type: avatar/kyc/attach }
  الحدود: avatar 2MB, kyc 5MB, attach 10MB
  المسموح: jpg, jpeg, png, gif, pdf
  الوصف: التحقق من القائمة البيضاء للأنواع + استشعار المحتوى عبر finfo (عدم تطابق الامتداد مع MIME ← 422)
```

---

## 4. سلة التسوق والطلبات
### سلة التسوق

```
POST /api/v1/cart
  الجسم: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  الجسم: { quantity }
```

> اتفاقية حقول المبالغ (مقَررة في D4/P4.2): جميع المبالغ من نوع string بــ 4 خانات عشرية (مثل "9.9900")، ويُمنع number/float——
> متوافقة مع الإخراج الأصلي لأعمدة MySQL DECIMAL عبر PDO، وتحمل الدقة سلاسل 4dp نفسها. تشمل جميع نقاط نهاية الطلبات/الأرصدة/التقارير.

### الطلبات

```
POST /api/v1/orders
  → إنشاء طلب من سلة التسوق
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  المعاملات: page, status (pending/paid/provisioning/completed/refunded، القيمة غير القانونية ترجع 400)
  → قائمة طلباتي

GET /api/v1/orders/{id}
  → تفاصيل الطلب (تتضمن items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → قنوات الدفع المتاحة + المبلغ الفعلي لكل قناة

POST /api/v1/orders/{id}/pay    🔒 تأكيد كلمة المرور
  الجسم: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### القسائم

```
POST /api/v1/coupons/validate
  الجسم: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (مثل "2.0000")

422: غير صالحة/منتهية/لا تستوفي شروط الاستخدام
```

### الفواتير

```
GET /api/v1/invoices
  المعاملات: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → تنزيل PDF
```

---

## 5. إدارة الموارد
```
GET /api/v1/resources
  المعاملات: page, status
  → قائمة مواردي

GET /api/v1/resources/{id}
  → تفاصيل المورد

GET /api/v1/resources/{id}/status
  → الحالة الحالية للمورد + المقاييس

GET /api/v1/resources/{id}/console
  → رابط VNC/وحدة التحكم

POST /api/v1/resources/batch
  الجسم: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. إدارة DNS
```
GET /api/v1/dns/{domain}
  → قائمة سجلات DNS

POST /api/v1/dns/{domain}/records
  الجسم: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 تأكيد كلمة المرور
```

---

## 7. التذاكر
```
POST /api/v1/tickets
  الجسم: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  المعاملات: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  الجسم: { content }
```

---

## 8. الموردون (واجهة داخلية)
```
POST /api/v1/supplier/apply
  الجسم: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → قائمة مستندات التسوية

POST /api/v1/supplier/withdraw    🔒 تأكيد كلمة المرور
  الجسم: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  الجسم: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. واجهة الموردين الخارجية
**المصادقة:** `Authorization: Bearer sk_xxx...` (التحقق من التوقيع SHA256)

**الحد من التردد:** 120 req/min (السحب 10 req/min)

```
GET /api/v1/supplier/external/orders
  المعاملات: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → تفاصيل الطلب (المرتبطة بهذا المورد فقط)

GET /api/v1/supplier/external/resources
  المعاملات: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  المعاملات: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  الجسم: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  المعاملات: page
```

---

## 10. واجهات لوحة الإدارة
**المصادقة:** JWT Bearer Token + دور Admin

### لوحة المعلومات

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### إدارة المستخدمين

```
GET /admin/api/v1/users              المعاملات: page, status, keyword
GET /admin/api/v1/users/export       → تنزيل Excel
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  الجسم: { status }
```

### مراجعة KYC

```
GET /admin/api/v1/kyc                المعاملات: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 تأكيد كلمة المرور
  الجسم: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 تأكيد كلمة المرور
  الجسم: { confirm_password, reason }
```

### إدارة المنتجات

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 تأكيد كلمة المرور
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → تنزيل CSV
POST /admin/api/v1/products/import        → رفع CSV upsert
```

### إدارة الطلبات

```
GET /admin/api/v1/orders              المعاملات: page, status, keyword
GET /admin/api/v1/orders/export       → تنزيل Excel
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 تأكيد كلمة المرور
  الجسم: { confirm_password, amount?, reason }
```

### إدارة الدفع

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions  المعاملات: page, channel, status
GET /admin/api/v1/payments/reconcile     المعاملات: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run  المعاملات: date; تشغيل المطابقة اليومية
```

### الموارد والتسليم

```
GET /admin/api/v1/provisioning/tasks              المعاملات: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  الجسم: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 تأكيد كلمة المرور
GET /admin/api/v1/provisioning/hosts
```

### إدارة الموردين

```
GET /admin/api/v1/suppliers                 المعاملات: page, status
GET /admin/api/v1/suppliers/export          → تنزيل Excel

POST /admin/api/v1/suppliers/{id}/approve    🔒 تأكيد كلمة المرور
POST /admin/api/v1/suppliers/{id}/settle     🔒 تأكيد كلمة المرور
  الجسم: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 تأكيد كلمة المرور
```

### مفاتيح API للموردين

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  الجسم: { name }
  ← { api_key: "sk_xxx...", prefix } (يُعرض مرة واحدة فقط)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### إدارة التذاكر

```
GET /admin/api/v1/tickets                  المعاملات: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign     الجسم: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### إدارة النطاقات

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  الجسم: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones             المعاملات: page
GET /admin/api/v1/domains/transfers         المعاملات: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### إدارة الإشعارات

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  الجسم: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log         المعاملات: page
```

### القسائم

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  الجسم: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### مقالات المساعدة

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  الجسم: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → حذف ناعم (status=archived)
```

### واجهات مزودي السحابة

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  الجسم: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → تعطيل (status=disabled)
```

### إدارة Webhook

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  الجسم: { url }
DELETE /admin/api/v1/webhooks               الجسم: { id }
POST /admin/api/v1/webhooks/test            الجسم: { url }
```

### التقارير

```
GET /admin/api/v1/reports/revenue            المعاملات: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (متوافق مع تجميع SUM(DECIMAL) عبر bcmath)
GET /admin/api/v1/reports/supplier           المعاملات: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region             المعاملات: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### المراقبة

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### سجلات التدقيق

```
GET /admin/api/v1/audit-logs                المعاملات: page, user_id, action, from, to
  → سجلات تدقيق بترقيم صفحات (تتضمن client_platform)
```

### مفاتيح الميزات

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  الجسم: { action: enable/disable/toggle/reset }
```

### إعدادات النظام

```
PUT /admin/api/v1/system/config              🔒 تأكيد كلمة المرور
```

### استيراد وتصدير المنتجات

```
GET /admin/api/v1/products/export           → تنزيل CSV
POST /admin/api/v1/products/import          → رفع CSV upsert
```

### تصدير الموردين والمستخدمين

```
GET /admin/api/v1/suppliers/export          → تنزيل Excel
GET /admin/api/v1/users/export              → تنزيل Excel
GET /admin/api/v1/orders/export             → تنزيل Excel
```

---

## 11. شهادات SSL
### طرف المستخدم

```
GET /api/v1/ssl/plans
  → قائمة باقات SSL (DV/OV/EV، السعر يتضمن register/renew/transfer)

GET /api/v1/ssl-certs
  → قائمة شهاداتي (تتضمن status: pending/active/expired/revoked)

GET /api/v1/ssl-certs/{id}
  → تفاصيل الشهادة (النطاق، جهة الإصدار، فترة الصلاحية، حالة التجديد)

GET /api/v1/ssl-certs/{id}/download
  → تنزيل ملفات الشهادة (سلسلة الشهادة + المفتاح الخاص)

POST /api/v1/ssl-certs/{id}/auto-renew
  الجسم: { auto_renew: true/false }
  → تبديل التجديد التلقائي
```

### طرف الإدارة

```
GET /admin/api/v1/ssl/plans              → قائمة الباقات
POST /admin/api/v1/ssl/plans             → إنشاء باقة
PUT /admin/api/v1/ssl/plans/{id}         → تحديث باقة
DELETE /admin/api/v1/ssl/plans/{id}      → حذف باقة
GET /admin/api/v1/ssl/certs              → جميع الشهادات
POST /admin/api/v1/ssl/certs/{id}/revoke → إبطال شهادة
```

---

## 12. التخزين الكائني
تخزين كائني متوافق مع S3، الرفع/التنزيل عبر روابط موقعة مسبقاً، والمفاتيح لا تُشارك خارجياً.

```
GET /api/v1/storage/buckets
  → قائمة حاوياتي (الاستخدام، الحالة)

GET /api/v1/storage/buckets/{id}
  → تفاصيل الحاوية

POST /api/v1/storage/buckets/{id}/presign-upload
  الجسم: { filename, content_type, size }
  → { upload_url, object_key } رابط رفع موقّع مسبقاً (محدود المدة)

POST /api/v1/storage/buckets/{id}/presign-download
  الجسم: { object_key }
  → رابط تنزيل موقّع مسبقاً (محدود المدة)

GET /api/v1/storage/buckets/{id}/credentials
  → اعتماد وصول مؤقت (قصير الصلاحية، للرفع المباشر عبر SDK)
```

---

## 13. تسريع CDN
### طرف المستخدم

```
GET /api/v1/cdn/domains
  → قائمة نطاقات CDN الخاصة بي (الخادم الأصلي، الحالة، الباقة)

POST /api/v1/cdn/domains
  الجسم: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
           origin_type (server|storage), origin_value, cert_config? }
  → إنشاء نطاق CDN (إنشاء عند المزود وربط الخادم الأصلي)
  → عندما provider_type=aliyun|tencent يجب إتمام تسجيل ICP للنطاق (يُعاد 4002 عند عدم التسجيل)
  → تحتوي الاستجابة على حقل التنبيه requires_icp_registration
  → تحليل الاعتمادات: الحساب المربوط للنطاق أولاً (provider_account_id)، وإلا حساب provider_apis
    نشط حسب code=cdn-{provider_type}، وفي غيابهما الرجوع إلى إعداد env

GET /api/v1/cdn/domains/{id}
  → تفاصيل نطاق CDN

DELETE /api/v1/cdn/domains/{id}
  → حذف نطاق CDN (تعطيل النطاق عند المزود، العملية idempotent)

POST /api/v1/cdn/domains/{id}/purge
  الجسم: { urls: ["https://cdn.example.com/path"] }
  → مسح التخزين المؤقت (إزالة تكرار عناوين URL تلقائياً، idempotent؛ بحد أقصى 100)

GET /api/v1/cdn/domains/{id}/stats
  → نظرة عامة على النطاق (cdn_domain / provider_type / plan / status / purged_at)
```

### طرف الإدارة

```
GET /admin/api/v1/cdn/domains            → جميع نطاقات CDN (بما فيها المستخدم المالك)
PUT /admin/api/v1/cdn/domains/{id}       → تحديث باقة النطاق (قائمة بيضاء للـ plan: standard | pro | enterprise)
```

مسارات CDN في الإدارة مسبوقة بـ `RbacMiddleware('cdn.manage')`، وتُكتب تغييرات الباقة في سجل التدقيق (`admin_cdn_update_plan`). تُدار اعتمادات حسابات المزودين عبر CRUD `/admin/api/v1/providers` (RbacMiddleware `provider.config`، `code` بميثاق `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`، الاعتمادات مشفّرة عبر Encryptable عند التخزين).

### أكواد أخطاء CDN

| code | الشرح |
|------|--------|
| 4001 | معامل CDN مفقود/غير صالح (urls فارغ، provider_type غير صالح، صيغة نطاق خاطئة) |
| 4002 | النطاق لم يُكمل تسجيل ICP (تُرسم عند رفض واجهة Alibaba Cloud/Tencent Cloud) |
| 4003 | اعتمادات مزود CDN غير مُهيأة (حساب مفقود/معطّل، لقطة صارمة دون تبديل بصمت) |
| 4005 | فشل مسح التخزين المؤقت لـ CDN |
| 5001 | فشل استدعاء واجهة مزود CDN |

> تُعيد موارد CDN غير المملوكة للمستخدم (موارد الآخرين/غير الموجودة) **404** موحّداً (رسم عبر findOrFail دون كشف وجود المورد)، ولا يوجد كود أعمال مستقل لها.

---

## 14. الفوترة حسب الاستخدام
```
GET /admin/api/v1/billing/rates          → قائمة أسعار الفوترة (حسب نوع المورد/المواصفات)
POST /admin/api/v1/billing/rates         → إنشاء سعر
PUT /admin/api/v1/billing/rates/{id}     → تحديث سعر
DELETE /admin/api/v1/billing/rates/{id}  → حذف سعر
GET /admin/api/v1/billing/usage          → ملخص الاستخدام (مجمّع حسب المستخدم/المورد)
```

خط أنابيب الفوترة: ResourceMonitor يجمع كل 5 دقائق ← UsageAggregator يجمّع كل ساعة ← BillingEngine يخصم يومياً، وعند عدم كفاية الرصيد تُعلَّق الموارد.

---

## 15. عمولات التحالف (Affiliate)
### طرف المستخدم

```
GET /api/v1/affiliate/summary
  → نظرة عامة على العمولات (المتراكمة/قيد التسوية/القابلة للسحب، عدد الروابط، معدل التحويل)

POST /api/v1/affiliate/links
  الجسم: { source? }
  → توليد رابط ترويجي (?ref=CODE)

GET /api/v1/affiliate/earnings
  المعاملات: status, page
  → تفاصيل العمولات (إسناد الطلب، النسبة، الحالة: pending/approved/paid)

POST /api/v1/affiliate/payout
  الجسم: { amount, method }
  → تقديم طلب سحب
```

### طرف الإدارة

```
GET /admin/api/v1/affiliate/plans                → قائمة خطط العمولات
POST /admin/api/v1/affiliate/plans               → إنشاء خطة عمولات
GET /admin/api/v1/affiliate/earnings             → جميع سجلات العمولات
POST /admin/api/v1/affiliate/earnings/{id}/approve → مراجعة العمولة
GET /admin/api/v1/affiliate/payouts              → قائمة طلبات السحب
POST /admin/api/v1/affiliate/payouts/{id}/approve → مراجعة/صرف السحب
```

---

## 16. GraphQL
```
POST /graphql
  → استعلامات عامة (المنتجات والنطاقات والمساعدة وغيرها من البيانات للقراءة فقط)
  الحدود: عمق الاستعلام 5 مستويات، التعقيد 100

POST /api/v1/graphql                          🔒 يتطلب المصادقة
  → استعلامات كاملة (تتضمن بيانات المستخدم)
```

**تبقى العمليات الحساسة REST-only:** الدفع والسحب والاسترداد ومراجعة KYC لا تمر عبر GraphQL.

---

## 17. تقييمات الموردين وتقييمات المنتجات
### عام

```
GET /api/v1/regions
  → قائمة المناطق المتاحة (تتضمن العملة/المنطقة الزمنية)

GET /api/v1/suppliers/{supplierId}/ratings
  → قائمة تقييمات الموردين (أربعة أبعاد: الجودة/الدعم/سرعة التسليم/القيمة، يُرجع approved فقط)
```

### طرف المستخدم (يتطلب المصادقة)

```
POST /api/v1/products/{productId}/reviews
  الجسم: { rating, content, images? }
  → إرسال تقييم منتج (مرة واحدة لكل طلب، يُعرض بعد المراجعة)

POST /api/v1/supplier/ratings
  الجسم: { supplier_id, quality, support, delivery_speed, value, comment? }
  → إرسال تقييم المورد (مرة واحدة لكل طلب)

GET /api/v1/supplier/ratings/me
  → سجل تقييماتي
```

### طرف الإدارة

```
GET /admin/api/v1/suppliers/{id}/ratings          → جميع التقييمات (تتضمن pending)
POST /admin/api/v1/suppliers/ratings/{id}/approve → الموافقة على التقييم
POST /admin/api/v1/suppliers/ratings/{id}/hide    → إخفاء
```

---

## 18. Webhook الدفع
```
POST /api/v1/payments/webhook/stripe
  الرأس: Stripe-Signature: ...
  → رد Stripe (نجاح الدفع/الاسترداد/الاعتراض)، فشل التحقق من التوقيع يرجع 400
```

---

## 19. أحداث WebSocket
**الاتصال:** `ws://host:8282` (في النشر عبر docker يعبر WS عبر nginx عكسي، عنوان الاتصال `ws://host/ws/`، و8282 مكشوف داخل الحاوية فقط)

المصادقة عبر أول رسالة بعد الاتصال (الرمز لا يدخل URL/سجلات الوصول): بعد إنشاء الاتصال يجب إرسال رسالة `auth` أولاً، ويُقطع الاتصال إذا لم يتم التحقق خلال 30 ثانية؛ فشل المصادقة يُرجع `error` ويُقطع الاتصال.

### العميل ← الخادم

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### الخادم ← العميل

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### أحداث الدفع

| الحدث | البيانات | لحظة الإطلاق |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | نجاح الدفع |
| `resource.provisioned` | `{resource_id, type, ip_address}` | اكتمال تسليم المورد |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | المورد على وشك الانتهاء |
| `ticket.updated` | `{ticket_id, title, status}` | تغيير حالة التذكرة |
| `notification.new` | `{notification_id, title, body}` | إشعار جديد |

---

## 20. مرجع أكواد الأخطاء
| code | الوصف |
|------|------|
| 400 | خطأ في المعاملات / إصدار API غير مدعوم / منصة عميل غير مدعومة |
| 401 | غير مصادق / انتهاء Token / API Key غير صالح / عدم تطابق بصمة الجهاز (Device mismatch) |
| 403 | لا صلاحية / ليس دور مورد / اعتراض WAF / فشل تأكيد كلمة المرور |
| 404 | المورد غير موجود (عدم إصابة firstOrFail/findOrFail يُعيّن 404 بشكل موحد) |
| 413 | جسم الطلب يتجاوز 10MB |
| 414 | URL يتجاوز 2KB |
| 415 | Content-Type خارج القائمة البيضاء (يُسمح فقط application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | فشل التحقق من المعاملات (البريد مسجل بالفعل / مخزون غير كافٍ / رصيد السحب غير كافٍ / تقديم طلب مسبق) |
| 429 | تجاوز حد تردد الطلبات |
| 500 | خطأ في الخادم |

### رسائل 422 الشائعة

| الرسالة | نقطة النهاية |
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
