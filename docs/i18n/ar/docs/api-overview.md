# نظرة عامة على API

> مرجع الواجهات الكامل (أكثر من 200 نقطة نهاية، يتضمن أمثلة الطلب/الاستجابة وأكواد الأخطاء): [توثيق واجهات API](api-reference.md)
> التصحيح عبر الإنترنت: [توثيق API الخدمة](http://localhost:8787/apidoc) · [توثيق API اللوحة](http://localhost:8788/apidoc)

## الواجهات العامة

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/health` | فحص الصحة |
| POST | `/api/auth/register` | تسجيل المستخدم (يجب تشفير جسم الطلب AES-256-GCM) |
| POST | `/api/auth/login` | تسجيل دخول المستخدم (يجب تشفير جسم الطلب AES-256-GCM) |
| POST | `/api/auth/refresh` | تجديد Token (يجب تشفير جسم الطلب AES-256-GCM) |
| POST | `/api/captcha/create` | توليد كابتشا النقر (الحصول عليها قبل تسجيل الدخول/التسجيل) |
| GET | `/api/products` | قائمة المنتجات (تدعم التصفية حسب التصنيف/المنطقة/الكلمة المفتاحية) |
| GET | `/api/products/{id}` | تفاصيل المنتج (المعرّف سلسلة hashid) |
| GET | `/api/regions` | المناطق المتاحة |
| GET | `/api/domain/check/{domain}/{tld}` | الاستعلام عن توفر النطاق |
| GET | `/api/domain/tlds` | قائمة اللواحق القابلة للتسجيل |
| POST | `/api/payments/webhook/stripe` | رد Stripe (التحقق من التوقيع، بدون تشفير) |

## واجهات المصادقة (تتطلب Bearer Token)

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/api/user/profile` | المعلومات الشخصية |
| PUT | `/api/user/profile` | تحديث المعلومات |
| POST | `/api/user/kyc` | إرسال التحقق من الهوية |
| GET | `/api/user/balance` | رصيد الحساب |
| GET/POST | `/api/cart` | سلة التسوق |
| POST/GET | `/api/orders` | الطلبات |
| GET | `/api/orders/{id}/payment-methods` | طرق الدفع المتاحة |
| POST | `/api/orders/{id}/pay` | بدء الدفع |
| GET/POST | `/api/resources` | مواردي |
| GET | `/api/resources/{id}/status` | حالة المورد |
| GET | `/api/resources/{id}/console` | رابط وحدة تحكم VNC |
| GET/POST | `/api/tickets` | التذاكر |
| POST | `/api/tickets/{id}/reply` | الرد على التذكرة |
| GET/POST | `/api/dns/{domain}` | إدارة DNS |
| POST | `/api/supplier/apply` | طلب الانضمام كمورد |
| GET | `/api/supplier/settlements` | سجل تسويات الموردين |
| POST | `/api/supplier/withdraw` | سحب الموردين |

> **ملاحظة:** يجب أن تحمل جميع طلبات API رأس `X-Api-Version: v1` (الافتراضي `v1` عند الغياب، وتتحقق منه `VersionMiddleware`). تخضع طلبات/استجابات واجهات المصادقة وواجهات الإدارة لمعالجة `EncryptionMiddleware`. يضبط العميل رأس `X-Encrypted: 1`، ويكون تنسيق جسم الطلب `{"payload": "<base64(AES-256-GCM)>"}`، ويُشفَّر جسم الاستجابة أيضاً ويُغلَّف في حقل `payload`. تُحوَّل جميع المعرّفات الصحيحة تلقائياً إلى سلاسل Hashid من 12 حرفاً في استجابات API، بينما تُفكَّك سلاسل Hashid في الطلبات تلقائياً إلى معرّفات صحيحة عبر `HashidRequestMiddleware`.

## واجهات الإدارة

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/admin/api/dashboard` | لوحة العمليات |
| GET/PUT | `/admin/api/users` | إدارة المستخدمين |
| GET/POST | `/admin/api/kyc` | مراجعة KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | إدارة المنتجات |
| POST | `/admin/api/products/{productId}/skus` | إنشاء SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | ضبط السعر الإقليمي |
| GET/POST | `/admin/api/orders` | إدارة الطلبات (بما فيها الاستردادات) |
| GET | `/admin/api/orders/export` | تصدير الطلبات (.xlsx) |
| GET | `/admin/api/users/export` | تصدير المستخدمين (.xlsx) |
| GET | `/admin/api/suppliers/export` | تصدير الموردين (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | قنوات الدفع / المعاملات / المطابقة |
| GET/POST | `/admin/api/provisioning/*` | مهام التسليم / إدارة الأجهزة |
| GET/POST | `/admin/api/suppliers/*` | الموافقة على الموردين / التسوية / السحب |
| GET/POST | `/admin/api/tickets` | توزيع / إغلاق التذاكر |
| GET | `/admin/api/reports/*` | تقارير الإيرادات / المناطق / الموردين |
| GET | `/admin/api/monitor/*` | لوحة المراقبة / مقاييس الموارد |
| GET | `/admin/api/audit-logs` | سجلات التدقيق |
| PUT | `/admin/api/system/config` | إعدادات النظام |
