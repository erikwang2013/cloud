# توثيق واجهات CloudPlatform API

## نظرة عامة

**Base URL:** `https://api.example.com`

**التحكم بالإصدار:** عبر رأس طلب HTTP `X-Api-Version: v1`. الافتراضي `v1` عند الغياب، وتُرجع الإصدارات غير المدعومة `400`. الإصدار غير موجود في مسار URL.

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
| عامة | سلسلة الوسائط العامة | `/health`, `/api/*` |
| `/health` (داخلي) | العامة + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | العامة + Encryption | `/api/auth/*` |
| `/api` (المستخدم) | العامة + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (حساس) | العامة + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | واجهة الموردين الخارجية |
| `/admin/api` | العامة + Encryption + Auth + AdminRole | واجهات لوحة الإدارة |
| `/admin/api` (حساس) | العامة + Encryption + Auth + AdminRole + Confirmation | عمليات الإدارة الحساسة |

---

## 1. نقاط النهاية العامة
### فحص الصحة

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### حالة الخدمة

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

### المنتجات

```
GET /api/products
  المعاملات: category_id, region_id, keyword, supplier_id, page (الافتراضي 1), page_size (الافتراضي 20, الأقصى 50)
  → قائمة منتجات بترقيم صفحات (تتضمن category, skus.regionPrices)

GET /api/products/search
  المعاملات: q (إلزامي), page
  → بحث نصي كامل عبر Elasticsearch

GET /api/products/{id}
  → تفاصيل المنتج (تتضمن category, skus, images, reviews)

GET /api/products/{productId}/reviews
  → قائمة التقييمات + avg_rating + total + distribution
  تعداد الحالة: pending(قيد المراجعة)/approved(معتمد)/rejected(مرفوض)، يُرجع approved فقط
```

### النطاقات

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → قائمة نطاقات المستوى الأعلى المتاحة (ذاكرة Redis مؤقتة لمدة 1 ساعة)
```

### مركز المساعدة

```
GET /api/help
  المعاملات: category, page
  الرأس: Accept-Language (en-US / zh-CN)
  → مقالات مساعدة بترقيم صفحات

GET /api/help/categories
  → قائمة تصنيفات المقالات

GET /api/help/{slug}
  → تفاصيل مقالة واحدة
```

---

## 2. نقاط نهاية المصادقة
### كابتشا التحقق

```
POST /api/captcha/create
  الرأس: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### التسجيل

```
POST /api/auth/register
  الرأس: X-Encrypted: 1
  الجسم (مشفّر): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

الحد من التردد: 3 req/min
```

- `deviceFingerprint` (اختياري): يسجّل بصمة الجهاز عند التسجيل، ويتحقق منها عند تسجيل الدخول/التجديد؛ في حال عدم إرسالها يُتخطى ربط البصمة
- يُشفَّر email/phone بتشفير حتمي قبل التخزين عبر Encryptable (ECB، استعلام تكافؤ النص المشفر)، وتتم كل من تحقق التفرد واستعلامات تسجيل الدخول بالنص المشفر

### تسجيل الدخول

```
POST /api/auth/login
  الرأس: X-Encrypted: 1
  الجسم (مشفّر): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

الحد من التردد: 5 req/min، 5 محاولات فاشلة تقفل 15 دقيقة
```

- يُستعلم عن `login` بمكافئ النص المشفر (تشفير حتمي Encryptable)، ولا يصيب الاستعلام بالنص الصريح الأعمدة المشفرة

### تجديد Token

```
POST /api/auth/refresh
  الرأس: X-Encrypted: 1
  الجسم (مشفّر): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- عدم تطابق `deviceFingerprint` مع ما سُجّل عند التسجيل ← 401 `Device mismatch`؛ ويُستعلم عن رمز التجديد بتجزئة النص المشفر

### OAuth

مزودو الدعم: google, apple, facebook, x, microsoft, linkedin, github
(يُحدد التفعيل من إعدادات مثل `{PROVIDER}_OAUTH_CLIENT_ID` في .env)

```
GET /api/auth/{provider}            → { url }        # انتقال إلى صفحة التفويض (PKCE/nonce ضد إعادة الإرسال)
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  الجسم: { code, state }
```

- يُرجع Apple/Microsoft id_token، ويتحقق الخادم من التوقيع عبر JWKS ومن iss/aud/exp/nonce
- جميع المزودين يشترطون `email_verified=true` للسماح بتسجيل الدخول، وإلا 422
- غياب `state` أو عدم تطابقه ← 422 (ضد CSRF، تنتهي الصلاحية بعد 5 دقائق)
- حد تردد تدفق OAuth: 10 مرات كل 60 ثانية (redirect + callback)

### إعادة تعيين كلمة المرور

```
POST /api/auth/forgot-password
  الجسم: { email }
  → إرسال بريد بكود التحقق

POST /api/auth/reset-password
  الجسم: { email, code, password }
  → نجاح إعادة التعيين
  → تراكم 5 أخطاء ← 429 تقييد 10 دقائق
```

### التحقق من البريد الإلكتروني

```
GET /api/auth/verify-email?token=xxx
  → نجاح التحقق
```

### التحقق بالرسائل القصيرة

```
POST /api/auth/send-sms
  الجسم: { phone }
  → إرسال رمز تحقق عبر الرسائل القصيرة (فترة تبريد 60 ثانية)
```

### التحقق الثنائي TOTP

```
POST /api/user/totp/setup        → { secret, qr_url }        # لا يُخزَّن، يجب تفعيله عبر verify خلال 10 دقائق
POST /api/user/totp/verify       الجسم: { code } → { verified: true }   # عند أول تفعيل يُرجع رسالة نجاح التفعيل
POST /api/user/totp/disable      الجسم: { password }             # يتطلب تأكيد كلمة المرور، وإلا 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # يولّد 8 رموز لمرة واحدة في كل مرة، يتطلب تأكيد كلمة المرور، وإلا 403
POST /api/auth/login/recovery    الجسم: { login, password, recovery_code }
```

- بعد تفعيل المستخدم TOTP، يجب أن يحمل تسجيل الدخول `totp_code`، وإلا 401
- 5 أخطاء متتالية في TOTP ← قفل المستخدم 15 دقيقة (login_lock)

---

## 3. نقاط نهاية المستخدم (تتطلب المصادقة)
### الملف الشخصي

```
GET /api/user/profile
PUT /api/user/profile
  الجسم: { nickname?, avatar?, country?, language?, timezone? }
```

### تحقق الهوية KYC

```
POST /api/user/kyc
  الجسم: { id_type, id_number, real_name, front_image, back_image }
```

### الرصيد

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
  المعاملات: page
  → سجل تغييرات الرصيد
```

### إدارة العناوين

```
GET /api/user/addresses
POST /api/user/addresses
  الجسم: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### إدارة الجلسات

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → إلغاء الجلسة المحددة

DELETE /api/user/account
  الجسم: { confirm_password }
  → حذف الحساب وفقاً لـ GDPR
```

### الإشعارات

```
GET /api/user/notifications
  المعاملات: page
  → قائمة إشعارات بترقيم صفحات

POST /api/user/notifications/{id}/read
  → تعليم كمقروء

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
  الجسم: { email: {order_paid: true, ...}, push: {...} }
```

### البريد الإلكتروني

```
POST /api/user/resend-verify-email
  → إعادة إرسال بريد التحقق
```

### رفع الملفات

```
POST /api/upload
  الجسم: multipart/form-data { file, type: avatar/kyc/attach }
  الحدود: avatar 2MB, kyc 5MB, attach 10MB
  المسموح: jpg, jpeg, png, gif, pdf
  الوصف: التحقق من القائمة البيضاء للأنواع + استشعار المحتوى عبر finfo (عدم تطابق الامتداد مع MIME ← 422)
```

---

## 4. سلة التسوق والطلبات
### سلة التسوق

```
POST /api/cart
  الجسم: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
  الجسم: { quantity }
```

> اتفاقية حقول المبالغ (مقَررة في D4/P4.2): جميع المبالغ من نوع string بــ 4 خانات عشرية (مثل "9.9900")، ويُمنع number/float——
> متوافقة مع الإخراج الأصلي لأعمدة MySQL DECIMAL عبر PDO، وتحمل الدقة سلاسل 4dp نفسها. تشمل جميع نقاط نهاية الطلبات/الأرصدة/التقارير.

### الطلبات

```
POST /api/orders
  → إنشاء طلب من سلة التسوق
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
  المعاملات: page, status (pending/paid/provisioning/completed/refunded، القيمة غير القانونية ترجع 400)
  → قائمة طلباتي

GET /api/orders/{id}
  → تفاصيل الطلب (تتضمن items, timeline)

GET /api/orders/{id}/payment-methods
  → قنوات الدفع المتاحة + المبلغ الفعلي لكل قناة

POST /api/orders/{id}/pay    🔒 تأكيد كلمة المرور
  الجسم: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### القسائم

```
POST /api/coupons/validate
  الجسم: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (مثل "2.0000")

422: غير صالحة/منتهية/لا تستوفي شروط الاستخدام
```

### الفواتير

```
GET /api/invoices
  المعاملات: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → تنزيل PDF
```

---

## 5. إدارة الموارد
```
GET /api/resources
  المعاملات: page, status
  → قائمة مواردي

GET /api/resources/{id}
  → تفاصيل المورد

GET /api/resources/{id}/status
  → الحالة الحالية للمورد + المقاييس

GET /api/resources/{id}/console
  → رابط VNC/وحدة التحكم

POST /api/resources/batch
  الجسم: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. إدارة DNS
```
GET /api/dns/{domain}
  → قائمة سجلات DNS

POST /api/dns/{domain}/records
  الجسم: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 تأكيد كلمة المرور
```

---

## 7. التذاكر
```
POST /api/tickets
  الجسم: { resource_id?, category, priority?, title, content }

GET /api/tickets
  المعاملات: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
  الجسم: { content }
```

---

## 8. الموردون (واجهة داخلية)
```
POST /api/supplier/apply
  الجسم: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → قائمة مستندات التسوية

POST /api/supplier/withdraw    🔒 تأكيد كلمة المرور
  الجسم: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
  الجسم: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## 9. واجهة الموردين الخارجية
**المصادقة:** `Authorization: Bearer sk_xxx...` (التحقق من التوقيع SHA256)

**الحد من التردد:** 120 req/min (السحب 10 req/min)

```
GET /api/supplier/external/orders
  المعاملات: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → تفاصيل الطلب (المرتبطة بهذا المورد فقط)

GET /api/supplier/external/resources
  المعاملات: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
  المعاملات: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
  الجسم: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
  المعاملات: page
```

---

## 10. واجهات لوحة الإدارة
**المصادقة:** JWT Bearer Token + دور Admin

### لوحة المعلومات

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### إدارة المستخدمين

```
GET /admin/api/users              المعاملات: page, status, keyword
GET /admin/api/users/export       → تنزيل Excel
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status  الجسم: { status }
```

### مراجعة KYC

```
GET /admin/api/kyc                المعاملات: page, status

POST /admin/api/kyc/{id}/approve   🔒 تأكيد كلمة المرور
  الجسم: { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 تأكيد كلمة المرور
  الجسم: { confirm_password, reason }
```

### إدارة المنتجات

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 تأكيد كلمة المرور
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → تنزيل CSV
POST /admin/api/products/import        → رفع CSV upsert
```

### إدارة الطلبات

```
GET /admin/api/orders              المعاملات: page, status, keyword
GET /admin/api/orders/export       → تنزيل Excel
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 تأكيد كلمة المرور
  الجسم: { confirm_password, amount?, reason }
```

### إدارة الدفع

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions  المعاملات: page, channel, status
GET /admin/api/payments/reconcile     المعاملات: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run  المعاملات: date; تشغيل المطابقة اليومية
```

### الموارد والتسليم

```
GET /admin/api/provisioning/tasks              المعاملات: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
  الجسم: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 تأكيد كلمة المرور
GET /admin/api/provisioning/hosts
```

### إدارة الموردين

```
GET /admin/api/suppliers                 المعاملات: page, status
GET /admin/api/suppliers/export          → تنزيل Excel

POST /admin/api/suppliers/{id}/approve    🔒 تأكيد كلمة المرور
POST /admin/api/suppliers/{id}/settle     🔒 تأكيد كلمة المرور
  الجسم: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 تأكيد كلمة المرور
```

### مفاتيح API للموردين

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
  الجسم: { name }
  ← { api_key: "sk_xxx...", prefix } (يُعرض مرة واحدة فقط)

DELETE /admin/api/suppliers/api-keys/{id}
```

### إدارة التذاكر

```
GET /admin/api/tickets                  المعاملات: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign     الجسم: { user_id }
POST /admin/api/tickets/{id}/close
```

### إدارة النطاقات

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
  الجسم: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones             المعاملات: page
GET /admin/api/domains/transfers         المعاملات: page
POST /admin/api/domains/transfers/{id}/approve
```

### إدارة الإشعارات

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
  الجسم: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log         المعاملات: page
```

### القسائم

```
GET /admin/api/coupons
POST /admin/api/coupons
  الجسم: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### مقالات المساعدة

```
GET /admin/api/help
POST /admin/api/help
  الجسم: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → حذف ناعم (status=archived)
```

### واجهات مزودي السحابة

```
GET /admin/api/providers
POST /admin/api/providers
  الجسم: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → تعطيل (status=disabled)
```

### إدارة Webhook

```
GET /admin/api/webhooks
POST /admin/api/webhooks
  الجسم: { url }
DELETE /admin/api/webhooks               الجسم: { id }
POST /admin/api/webhooks/test            الجسم: { url }
```

### التقارير

```
GET /admin/api/reports/revenue            المعاملات: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (متوافق مع تجميع SUM(DECIMAL) عبر bcmath)
GET /admin/api/reports/supplier           المعاملات: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region             المعاملات: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### المراقبة

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### سجلات التدقيق

```
GET /admin/api/audit-logs                المعاملات: page, user_id, action, from, to
  → سجلات تدقيق بترقيم صفحات (تتضمن client_platform)
```

### مفاتيح الميزات

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
  الجسم: { action: enable/disable/toggle/reset }
```

### إعدادات النظام

```
PUT /admin/api/system/config              🔒 تأكيد كلمة المرور
```

### استيراد وتصدير المنتجات

```
GET /admin/api/products/export           → تنزيل CSV
POST /admin/api/products/import          → رفع CSV upsert
```

### تصدير الموردين والمستخدمين

```
GET /admin/api/suppliers/export          → تنزيل Excel
GET /admin/api/users/export              → تنزيل Excel
GET /admin/api/orders/export             → تنزيل Excel
```

---

## 11. شهادات SSL
### طرف المستخدم

```
GET /api/ssl/plans
  → قائمة باقات SSL (DV/OV/EV، السعر يتضمن register/renew/transfer)

GET /api/ssl-certs
  → قائمة شهاداتي (تتضمن status: pending/active/expired/revoked)

GET /api/ssl-certs/{id}
  → تفاصيل الشهادة (النطاق، جهة الإصدار، فترة الصلاحية، حالة التجديد)

GET /api/ssl-certs/{id}/download
  → تنزيل ملفات الشهادة (سلسلة الشهادة + المفتاح الخاص)

POST /api/ssl-certs/{id}/auto-renew
  الجسم: { auto_renew: true/false }
  → تبديل التجديد التلقائي
```

### طرف الإدارة

```
GET /admin/api/ssl/plans              → قائمة الباقات
POST /admin/api/ssl/plans             → إنشاء باقة
PUT /admin/api/ssl/plans/{id}         → تحديث باقة
DELETE /admin/api/ssl/plans/{id}      → حذف باقة
GET /admin/api/ssl/certs              → جميع الشهادات
POST /admin/api/ssl/certs/{id}/revoke → إبطال شهادة
```

---

## 12. التخزين الكائني
تخزين كائني متوافق مع S3، الرفع/التنزيل عبر روابط موقعة مسبقاً، والمفاتيح لا تُشارك خارجياً.

```
GET /api/storage/buckets
  → قائمة حاوياتي (الاستخدام، الحالة)

GET /api/storage/buckets/{id}
  → تفاصيل الحاوية

POST /api/storage/buckets/{id}/presign-upload
  الجسم: { filename, content_type, size }
  → { upload_url, object_key } رابط رفع موقّع مسبقاً (محدود المدة)

POST /api/storage/buckets/{id}/presign-download
  الجسم: { object_key }
  → رابط تنزيل موقّع مسبقاً (محدود المدة)

GET /api/storage/buckets/{id}/credentials
  → اعتماد وصول مؤقت (قصير الصلاحية، للرفع المباشر عبر SDK)
```

---

## 13. تسريع CDN
### طرف المستخدم

```
GET /api/cdn/domains
  → قائمة نطاقات CDN الخاصة بي (الخادم الأصلي، الحالة، الباقة)

GET /api/cdn/domains/{id}
  → تفاصيل نطاق CDN

POST /api/cdn/domains/{id}/purge
  → مسح الذاكرة المؤقتة (الموقع بالكامل أو قائمة URLs محددة)

GET /api/cdn/domains/{id}/stats
  المعاملات: range (day/week/month)
  → إحصائيات الحركة/عدد الطلبات/نسبة الإصابة
```

### طرف الإدارة

```
GET /admin/api/cdn/domains            → جميع نطاقات CDN
PUT /admin/api/cdn/domains/{id}       → تحديث باقة/إعداد النطاق
```

---

## 14. الفوترة حسب الاستخدام
```
GET /admin/api/billing/rates          → قائمة أسعار الفوترة (حسب نوع المورد/المواصفات)
POST /admin/api/billing/rates         → إنشاء سعر
PUT /admin/api/billing/rates/{id}     → تحديث سعر
DELETE /admin/api/billing/rates/{id}  → حذف سعر
GET /admin/api/billing/usage          → ملخص الاستخدام (مجمّع حسب المستخدم/المورد)
```

خط أنابيب الفوترة: ResourceMonitor يجمع كل 5 دقائق ← UsageAggregator يجمّع كل ساعة ← BillingEngine يخصم يومياً، وعند عدم كفاية الرصيد تُعلَّق الموارد.

---

## 15. عمولات التحالف (Affiliate)
### طرف المستخدم

```
GET /api/affiliate/summary
  → نظرة عامة على العمولات (المتراكمة/قيد التسوية/القابلة للسحب، عدد الروابط، معدل التحويل)

POST /api/affiliate/links
  الجسم: { source? }
  → توليد رابط ترويجي (?ref=CODE)

GET /api/affiliate/earnings
  المعاملات: status, page
  → تفاصيل العمولات (إسناد الطلب، النسبة، الحالة: pending/approved/paid)

POST /api/affiliate/payout
  الجسم: { amount, method }
  → تقديم طلب سحب
```

### طرف الإدارة

```
GET /admin/api/affiliate/plans                → قائمة خطط العمولات
POST /admin/api/affiliate/plans               → إنشاء خطة عمولات
GET /admin/api/affiliate/earnings             → جميع سجلات العمولات
POST /admin/api/affiliate/earnings/{id}/approve → مراجعة العمولة
GET /admin/api/affiliate/payouts              → قائمة طلبات السحب
POST /admin/api/affiliate/payouts/{id}/approve → مراجعة/صرف السحب
```

---

## 16. GraphQL
```
POST /graphql
  → استعلامات عامة (المنتجات والنطاقات والمساعدة وغيرها من البيانات للقراءة فقط)
  الحدود: عمق الاستعلام 5 مستويات، التعقيد 100

POST /api/graphql                          🔒 يتطلب المصادقة
  → استعلامات كاملة (تتضمن بيانات المستخدم)
```

**تبقى العمليات الحساسة REST-only:** الدفع والسحب والاسترداد ومراجعة KYC لا تمر عبر GraphQL.

---

## 17. تقييمات الموردين وتقييمات المنتجات
### عام

```
GET /api/regions
  → قائمة المناطق المتاحة (تتضمن العملة/المنطقة الزمنية)

GET /api/suppliers/{supplierId}/ratings
  → قائمة تقييمات الموردين (أربعة أبعاد: الجودة/الدعم/سرعة التسليم/القيمة، يُرجع approved فقط)
```

### طرف المستخدم (يتطلب المصادقة)

```
POST /api/products/{productId}/reviews
  الجسم: { rating, content, images? }
  → إرسال تقييم منتج (مرة واحدة لكل طلب، يُعرض بعد المراجعة)

POST /api/supplier/ratings
  الجسم: { supplier_id, quality, support, delivery_speed, value, comment? }
  → إرسال تقييم المورد (مرة واحدة لكل طلب)

GET /api/supplier/ratings/me
  → سجل تقييماتي
```

### طرف الإدارة

```
GET /admin/api/suppliers/{id}/ratings          → جميع التقييمات (تتضمن pending)
POST /admin/api/suppliers/ratings/{id}/approve → الموافقة على التقييم
POST /admin/api/suppliers/ratings/{id}/hide    → إخفاء
```

---

## 18. Webhook الدفع
```
POST /api/payments/webhook/stripe
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
