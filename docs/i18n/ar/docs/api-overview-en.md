# نظرة عامة على API

> مرجع API الكامل (أكثر من 200 نقطة نهاية، أمثلة الطلب/الاستجابة وأكواد الأخطاء): [مرجع API](api-reference.md)
> التصحيح عبر الإنترنت: [توثيق API الخدمة](http://localhost:8787/apidoc) · [توثيق API اللوحة](http://localhost:8788/apidoc)

## نقاط النهاية العامة

| الطريقة | المسار | الوصف |
|--------|------|-------------|
| GET | `/health` | فحص الصحة |
| POST | `/api/auth/register` | التسجيل (الجسم مشفّر AES-256-GCM) |
| POST | `/api/auth/login` | تسجيل الدخول (الجسم مشفّر AES-256-GCM) |
| POST | `/api/auth/refresh` | تجديد الرمز (الجسم مشفّر AES-256-GCM) |
| POST | `/api/captcha/create` | توليد كابتشا النقر (مطلوبة قبل تسجيل الدخول/التسجيل) |
| GET | `/api/products` | قائمة المنتجات (قابلة للتصفية حسب التصنيف/المنطقة/الكلمة المفتاحية) |
| GET | `/api/products/{id}` | تفاصيل المنتج (المعرّف سلسلة hashid) |
| GET | `/api/regions` | المناطق المتاحة |
| GET | `/api/domain/check/{domain}/{tld}` | فحص توفر النطاق |
| GET | `/api/domain/tlds` | نطاقات المستوى الأعلى المتاحة |
| POST | `/api/payments/webhook/stripe` | Webhook سترايب (تحقق من التوقيع، بدون تشفير) |

## نقاط النهاية المصدّقة (Bearer Token)

| الطريقة | المسار | الوصف |
|--------|------|-------------|
| GET | `/api/user/profile` | جلب الملف الشخصي |
| PUT | `/api/user/profile` | تحديث الملف الشخصي |
| POST | `/api/user/kyc` | إرسال KYC |
| GET | `/api/user/balance` | رصيد الحساب |
| GET/POST | `/api/cart` | سلة التسوق |
| POST/GET | `/api/orders` | الطلبات |
| GET | `/api/orders/{id}/payment-methods` | طرق الدفع المتاحة |
| POST | `/api/orders/{id}/pay` | بدء الدفع |
| GET/POST | `/api/resources` | مواردي |
| GET | `/api/resources/{id}/status` | حالة المورد |
| GET | `/api/resources/{id}/console` | عنوان وحدة تحكم VNC |
| GET/POST | `/api/tickets` | تذاكر الدعم |
| POST | `/api/tickets/{id}/reply` | الرد على التذكرة |
| GET/POST | `/api/dns/{domain}` | إدارة DNS |
| POST | `/api/supplier/apply` | التقدم بطلب كمورد |
| GET | `/api/supplier/settlements` | سجل التسويات |
| POST | `/api/supplier/withdraw` | طلب السحب |

> **ملاحظة:** يجب أن تتضمن جميع طلبات API رأس `X-Api-Version: v1` (الافتراضي `v1` عند الغياب، وتتحقق منه `VersionMiddleware`). تُعالج نقاط نهاية المصادقة والإدارة عبر `EncryptionMiddleware`. يضبط العملاء رأس `X-Encrypted: 1` ويلفّون الجسم بصيغة `{"payload": "<base64(AES-256-GCM)>"}`. تُشفّر الاستجابات بالمثل وتُلّف في حقل `payload`. تُحوَّل المعرّفات الصحيحة في استجابات API تلقائياً إلى سلاسل Hashid من 12 حرفاً؛ وتُفكَّك سلاسل Hashid في الطلبات إلى معرّفات صحيحة عبر `HashidRequestMiddleware`.

## نقاط نهاية لوحة الإدارة

| الطريقة | المسار | الوصف |
|--------|------|-------------|
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
| GET/PUT | `/admin/api/payments/*` | القنوات / المعاملات / المطابقة |
| GET/POST | `/admin/api/provisioning/*` | مهام التسليم / إدارة الأجهزة |
| GET/POST | `/admin/api/suppliers/*` | الموافقة على الموردين / التسوية / السحب |
| GET/POST | `/admin/api/tickets` | توزيع / إغلاق التذاكر |
| GET | `/admin/api/reports/*` | تقارير الإيرادات / المناطق / الموردين |
| GET | `/admin/api/monitor/*` | لوحة المراقبة / مقاييس الموارد |
| GET | `/admin/api/audit-logs` | سجلات التدقيق |
| PUT | `/admin/api/system/config` | تحديث إعدادات النظام |
