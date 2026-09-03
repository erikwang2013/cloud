# نظرة عامة على API

> مرجع الواجهات الكامل (أكثر من 200 نقطة نهاية، يتضمن أمثلة الطلب/الاستجابة وأكواد الأخطاء): [توثيق واجهات API](api-reference.md)
> التصحيح عبر الإنترنت: [توثيق API الخدمة](http://localhost:8787/apidoc) · [توثيق API اللوحة](http://localhost:8788/apidoc)

## الواجهات العامة

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/health` | فحص الصحة |
| POST | `/api/v1/auth/register` | تسجيل المستخدم (يجب تشفير جسم الطلب AES-256-GCM) |
| POST | `/api/v1/auth/login` | تسجيل دخول المستخدم (يجب تشفير جسم الطلب AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | تجديد Token (يجب تشفير جسم الطلب AES-256-GCM) |
| POST | `/api/v1/captcha/create` | توليد كابتشا النقر (الحصول عليها قبل تسجيل الدخول/التسجيل) |
| GET | `/api/v1/products` | قائمة المنتجات (تدعم التصفية حسب التصنيف/المنطقة/الكلمة المفتاحية) |
| GET | `/api/v1/products/{id}` | تفاصيل المنتج (المعرّف سلسلة hashid) |
| GET | `/api/v1/regions` | المناطق المتاحة |
| GET | `/api/v1/domain/check/{domain}/{tld}` | الاستعلام عن توفر النطاق |
| GET | `/api/v1/domain/tlds` | قائمة اللواحق القابلة للتسجيل |
| POST | `/api/v1/payments/webhook/stripe` | رد Stripe (التحقق من التوقيع، بدون تشفير) |

## واجهات المصادقة (تتطلب Bearer Token)

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/api/v1/user/profile` | المعلومات الشخصية |
| PUT | `/api/v1/user/profile` | تحديث المعلومات |
| POST | `/api/v1/user/kyc` | إرسال التحقق من الهوية |
| GET | `/api/v1/user/balance` | رصيد الحساب |
| GET/POST | `/api/v1/cart` | سلة التسوق |
| POST/GET | `/api/v1/orders` | الطلبات |
| GET | `/api/v1/orders/{id}/payment-methods` | طرق الدفع المتاحة |
| POST | `/api/v1/orders/{id}/pay` | بدء الدفع |
| GET/POST | `/api/v1/resources` | مواردي |
| GET | `/api/v1/resources/{id}/status` | حالة المورد |
| GET | `/api/v1/resources/{id}/console` | رابط وحدة تحكم VNC |
| GET/POST | `/api/v1/cdn/domains` | قائمة/إنشاء نطاقات CDN (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | تفاصيل/حذف نطاق CDN |
| POST | `/api/v1/cdn/domains/{id}/purge` | مسح التخزين المؤقت (idempotent، بحد أقصى 100 عنوان URL) |
| GET/POST | `/api/v1/tickets` | التذاكر |
| POST | `/api/v1/tickets/{id}/reply` | الرد على التذكرة |
| GET/POST | `/api/v1/dns/{domain}` | إدارة DNS |
| POST | `/api/v1/supplier/apply` | طلب الانضمام كمورد |
| GET | `/api/v1/supplier/settlements` | سجل تسويات الموردين |
| POST | `/api/v1/supplier/withdraw` | سحب الموردين |

> **ملاحظة:** يقع إصدار الـ API في مسار الـ URL، مثل `/api/v1/...`، ويتحقق منه `VersionMiddleware`. تخضع طلبات/استجابات واجهات المصادقة وواجهات الإدارة لمعالجة `EncryptionMiddleware`. يضبط العميل رأس `X-Encrypted: 1`، ويكون تنسيق جسم الطلب `{"payload": "<base64(AES-256-GCM)>"}`، ويُشفَّر جسم الاستجابة أيضاً ويُغلَّف في حقل `payload`. تُحوَّل جميع المعرّفات الصحيحة تلقائياً إلى سلاسل Hashid من 12 حرفاً في استجابات API، بينما تُفكَّك سلاسل Hashid في الطلبات تلقائياً إلى معرّفات صحيحة عبر `HashidRequestMiddleware`.

## واجهات الإدارة

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | لوحة العمليات |
| GET/PUT | `/admin/api/v1/users` | إدارة المستخدمين |
| GET/POST | `/admin/api/v1/kyc` | مراجعة KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | إدارة المنتجات |
| POST | `/admin/api/v1/products/{productId}/skus` | إنشاء SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | ضبط السعر الإقليمي |
| GET/POST | `/admin/api/v1/orders` | إدارة الطلبات (بما فيها الاستردادات) |
| GET | `/admin/api/v1/orders/export` | تصدير الطلبات (.xlsx) |
| GET | `/admin/api/v1/users/export` | تصدير المستخدمين (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | تصدير الموردين (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | قنوات الدفع / المعاملات / المطابقة |
| GET/POST | `/admin/api/v1/provisioning/*` | مهام التسليم / إدارة الأجهزة |
| GET/PUT | `/admin/api/v1/cdn/domains` | إدارة نطاقات CDN (تغيير الباقة) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | إدارة اعتمادات حسابات المزودين (مشتركة بين CDN/التسليم، مشفّرة عبر Encryptable) |
| GET/POST | `/admin/api/v1/suppliers/*` | الموافقة على الموردين / التسوية / السحب |
| GET/POST | `/admin/api/v1/tickets` | توزيع / إغلاق التذاكر |
| GET | `/admin/api/v1/reports/*` | تقارير الإيرادات / المناطق / الموردين |
| GET | `/admin/api/v1/monitor/*` | لوحة المراقبة / مقاييس الموارد |
| GET | `/admin/api/v1/audit-logs` | سجلات التدقيق |
| PUT | `/admin/api/v1/system/config` | إعدادات النظام |
