# نظرة عامة على API

> مرجع API الكامل (أكثر من 200 نقطة نهاية، أمثلة الطلب/الاستجابة وأكواد الأخطاء): [مرجع API](api-reference.md)
> التصحيح عبر الإنترنت: [توثيق API الخدمة](http://localhost:8787/apidoc) · [توثيق API اللوحة](http://localhost:8788/apidoc)

## نقاط النهاية العامة

| الطريقة | المسار | الوصف |
|--------|------|-------------|
| GET | `/health` | فحص الصحة |
| POST | `/api/v1/auth/register` | التسجيل (الجسم مشفّر AES-256-GCM) |
| POST | `/api/v1/auth/login` | تسجيل الدخول (الجسم مشفّر AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | تجديد الرمز (الجسم مشفّر AES-256-GCM) |
| POST | `/api/v1/captcha/create` | توليد كابتشا النقر (مطلوبة قبل تسجيل الدخول/التسجيل) |
| GET | `/api/v1/products` | قائمة المنتجات (قابلة للتصفية حسب التصنيف/المنطقة/الكلمة المفتاحية) |
| GET | `/api/v1/products/{id}` | تفاصيل المنتج (المعرّف سلسلة hashid) |
| GET | `/api/v1/regions` | المناطق المتاحة |
| GET | `/api/v1/domain/check/{domain}/{tld}` | فحص توفر النطاق |
| GET | `/api/v1/domain/tlds` | نطاقات المستوى الأعلى المتاحة |
| POST | `/api/v1/payments/webhook/stripe` | Webhook سترايب (تحقق من التوقيع، بدون تشفير) |

## نقاط النهاية المصدّقة (Bearer Token)

| الطريقة | المسار | الوصف |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | جلب الملف الشخصي |
| PUT | `/api/v1/user/profile` | تحديث الملف الشخصي |
| POST | `/api/v1/user/kyc` | إرسال KYC |
| GET | `/api/v1/user/balance` | رصيد الحساب |
| GET/POST | `/api/v1/cart` | سلة التسوق |
| POST/GET | `/api/v1/orders` | الطلبات |
| GET | `/api/v1/orders/{id}/payment-methods` | طرق الدفع المتاحة |
| POST | `/api/v1/orders/{id}/pay` | بدء الدفع |
| GET/POST | `/api/v1/resources` | مواردي |
| GET | `/api/v1/resources/{id}/status` | حالة المورد |
| GET | `/api/v1/resources/{id}/console` | عنوان وحدة تحكم VNC |
| GET/POST | `/api/v1/tickets` | تذاكر الدعم |
| POST | `/api/v1/tickets/{id}/reply` | الرد على التذكرة |
| GET/POST | `/api/v1/dns/{domain}` | إدارة DNS |
| POST | `/api/v1/supplier/apply` | التقدم بطلب كمورد |
| GET | `/api/v1/supplier/settlements` | سجل التسويات |
| POST | `/api/v1/supplier/withdraw` | طلب السحب |

> **ملاحظة:** يقع إصدار الـ API في مسار الـ URL، مثل `/api/v1/...`، ويتحقق منه `VersionMiddleware`. تُعالج نقاط نهاية المصادقة والإدارة عبر `EncryptionMiddleware`. يضبط العملاء رأس `X-Encrypted: 1` ويلفّون الجسم بصيغة `{"payload": "<base64(AES-256-GCM)>"}`. تُشفّر الاستجابات بالمثل وتُلّف في حقل `payload`. تُحوَّل المعرّفات الصحيحة في استجابات API تلقائياً إلى سلاسل Hashid من 12 حرفاً؛ وتُفكَّك سلاسل Hashid في الطلبات إلى معرّفات صحيحة عبر `HashidRequestMiddleware`.

## نقاط نهاية لوحة الإدارة

| الطريقة | المسار | الوصف |
|--------|------|-------------|
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
| GET/PUT | `/admin/api/v1/payments/*` | القنوات / المعاملات / المطابقة |
| GET/POST | `/admin/api/v1/provisioning/*` | مهام التسليم / إدارة الأجهزة |
| GET/POST | `/admin/api/v1/suppliers/*` | الموافقة على الموردين / التسوية / السحب |
| GET/POST | `/admin/api/v1/tickets` | توزيع / إغلاق التذاكر |
| GET | `/admin/api/v1/reports/*` | تقارير الإيرادات / المناطق / الموردين |
| GET | `/admin/api/v1/monitor/*` | لوحة المراقبة / مقاييس الموارد |
| GET | `/admin/api/v1/audit-logs` | سجلات التدقيق |
| PUT | `/admin/api/v1/system/config` | تحديث إعدادات النظام |
