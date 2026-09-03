# وثيقة تصميم بنية CloudPlatform

## 1. نظرة عامة على النظام

CloudPlatform منصة تداول موارد سحابية موجهة للعالم، تدعم النمط المختلط من الأجهزة المادية الذاتية + موردين من طرف ثالث. يمكن للمستخدمين عبر الويب/الجوال شراء منتجات مثل الخوادم (VM) وعناوين IP والأقراص السحابية وأسماء النطاقات، ويكمل النظام تلقائياً معالجة الدفع وتسليم الموارد.

### 1.1 قرارات البنية الأساسية

| القرار | الاختيار | السبب |
|------|------|------|
| إطار الواجهة الخلفية | PHP webman (Workerman) | مقيم بالذاكرة، قيادة بالأحداث، متعدد العمليات، استجابة بمستوى المللي ثانية |
| نمط البنية | وحدة واحدة معيارية | الوحدات مقسمة رأسياً حسب الأعمال، طبقات MVC داخلية، فك الاقتران بين الوحدات عبر الأحداث |
| لوحة الإدارة | مثيل webman مستقل (webman-admin + Layui) | عزل حركة الإدارة عن حركة المستخدمين، فصل نطاقات الأعطال |
| ORM | Illuminate/Eloquent | بيئة Laravel ناضجة، استعلامات العلاقات، Scopes، الأحداث، الترحيلات |
| المفتاح الأساسي الموزع | Snowflake 64-bit | بلا اعتماد على الزيادة الذاتية، دعم طبيعي لتقسيم القواعد والجداول |
| تشويش المعرّفات | Hashids | إخفاء الحجم الحقيقي للمعرّفات عن الخارج، منع زحف الروبوتات |
| المصادقة | JWT HS256 | مصادقة بلا حالة، Access 15د + Refresh 30يوم |
| تشفير النقل | AES-256-GCM | تشفير/فك تشفير شفاف عبر الوسيط، تشفير مصادق GCM يمنع العبث |
| تشفير الحقول | AES-128-ECB | تشفير/فك تشفير تلقائي عبر Eloquent Cast، تشفير حتمي (يمكن الاستعلام المتساوي على النص المشفر، يعتمد عليه تسجيل الدخول/فحص التفرد)؛ يدعم ECB فقط |
| قائمة الرسائل | Redis Queue | معالجة غير متزامنة لردود الدفع وتوزيع الإشعارات وتوفير الموارد |
| محرك البحث | database (الافتراضي)/ Elasticsearch 8.x | webman-scout بمحرك database الافتراضي (تراجع SQL LIKE)؛ بعد إعداد ES يُفعَّل فهرسة تقسيم IK |
| المحاكاة الافتراضية | Proxmox VE + kvm-server | تُوفَّر الأجهزة الافتراضية الذاتية عبر خادم Rust kvm-server (gRPC :50051، اكتشاف تسجيل e-cat/etcd)؛ المحرك الحالي محرك محاكى، والمحرك الفعلي libvirt في Phase 2 |
| العميل | Flutter | قاعدة كود واحدة لخمس منصات iOS/macOS/Windows/Linux/Web + HarmonyOS |

### 1.2 حدود النظام

```
┌──────────────────────────────────────────────────────────────────┐
│                         جانب المستخدم                             │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    الوكيل العكسي Nginx                            │
│  إنهاء SSL / ضغط gzip / تقييد التردد / ترقية WebSocket           │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              خادم webman (متعدد العمليات)                        │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ سلسلة الوسائط العامة: Version→CORS→SecurityHeaders→ClientPlatform │     │
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[وسائط المسارات]  │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ خادم WebSocket (:8282) — الدفع الفوري                    │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (رئيسي/نسخة)│ │(تخزين مؤقت/│ │    8.x        │
    │             │ │  قوائم)    │ │                │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  اكتشاف تسجيل e-cat / etcd  │
    │  محرك محاكى (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  واجهة Proxmox VE (:8006)   │
    │  محاكاة KVM/QEMU الافتراضية  │
    │  تجمع IP / تجمع أقراص / مضيفات │
    └─────────────────────────────┘
```

---

## 2. بنية المكونات

### 2.1 تصميم المثيلين

يضم المشروع مثيلي webman مستقلين يتشاركان قاعدة بيانات MySQL:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   الوسائط: WAF→ACL   │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 وسيط عام + 6 مسارات │
                    └─────────────────────┘
```

| المثيل | المنفذ | المسؤولية | الوسائط |
|------|------|------|--------|
| **service** | 8787 | API المستخدمين + API الإدارة + WebSocket | عام 12 + مسارات 6 + SupplierApiKey |
| **admin** | 8788 | لوحة HTML للإدارة (Layui) | WafMiddleware + AccessControl |

### 2.2 بنية طبقات الوحدات

تتبع كل وحدة أعمال بنية موحدة من الطبقات الداخلية:

```
app/{Module}/
├── controller/     # طبقة HTTP: التحقق من المعاملات، استدعاء Service، إرجاع Response
│   └── external/   # وحدات تحكم API الخارجي (مصادقة API Key للموردين)
├── service/        # منطق الأعمال: بلا اعتماد على HTTP، قابل لإعادة الاستخدام من Controller/Queue Worker
├── model/          # نماذج Eloquent للبيانات: تعريف العلاقات، نطاقات الاستعلام، Casts
├── event/          # تعريف أحداث النطاق (OrderPaid، TicketCreated وغيرها)
├── listener/       # مستمعو الأحداث (Provisioning، دفع WebSocket)
├── provider/       # محولات مزودي السحابة (ProxmoxProvider وغيرها)
├── queue/          # مستهلكو قوائم الانتظار (ProvisionWorker، EmailSender وغيرها)
└── cron/           # المهام المجدولة (ExchangeRateSync، ExpirationCheck وغيرها)
```

### 2.3 طبقات المكتبة العامة

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # خدمة كابتشا النقر
├── clientplatform/      # ClientPlatformMiddleware (رأس X-Client-Platform)
├── confirmation/        # وسيط تأكيد كلمة المرور الثانوي
├── encryption/          # وسيط تشفير النقل AES-256-GCM
├── feature/             # مفاتيح الميزات Feature Flags
├── hashid/              # وسيط فك ترميز طلبات Hashids + خدمة الترميز/فك الترميز
├── helper/              # تنسيق Response + CacheService
├── http/                # أدوات عميل HTTP
├── i18n/middleware/     # وسيط التعدد اللغوي LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # خدمة معرّفات Snowflake + Eloquent Trait
├── metrics/             # جامع مقاييس Prometheus + العارض + وسيط عد طلبات HTTP
├── version/             # VersionMiddleware (إصدار مسار الـ URL)
└── webhook/             # موزع أحداث Webhook
```

### 2.4 وحدة CDN

وحدة CDN على مستوى المنتج (`service/app/cdn/`) تتعامل مع أربعة مزودين عبر نمط المحولات، لربط الخوادم أو الجرافات التخزينية كمصدر في CDN:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (شهادة تلقائية عبر SSL SaaS)، بدون تسجيل ICP
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM)، بدون تسجيل ICP
  ├── AliyunCdnAdapter    توقيع RPC، يتطلب تسجيل ICP
  └── TencentCdnAdapter   توقيع TC3، يتطلب تسجيل ICP
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① الحساب المربوط (provider_account_id) → ② حساب نشط حسب code=cdn-{type} → ③ env كبديل أخير
  strict=true (حذف/purge): استخدام الحساب المربوط فقط، 4003 عند غيابه، دون تبديل بصمت
```

**إدارة الحسابات:** إعادة استخدام نموذج `provider_apis` (الاعتمادات مشفّرة عبر Encryptable عند التخزين)، CRUD من الإدارة عبر `/admin/providers` (RbacMiddleware)، `code` بميثاق `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`، وتُعامل اعتمادات env كبديل fallback.

**نموذج البيانات:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config؛ يُستبعد المفتاح الخاص من cert_config قبل التخزين). عزل الصلاحيات: تُتحقق موارد CDN من الملكية عبر `resource.user_id`، وأي مورد ليس ملك المستخدم يُعيد 404 موحّداً.

---

## 3. خط أنابيب تنفيذ الوسائط

### 3.1 سلسلة الوسائط العامة (جميع الطلبات)

```
طلب HTTP
  │
  ▼
1. VersionMiddleware         ← التحقق من إصدار الـ API في مسار الـ URL، و400 عند الإبطال
  │                            يسري فقط على /api/v1/ و /admin/api/v1/
  ▼
2. CorsMiddleware            ← طلبات OPTIONS الأولية تُعيد رؤوس CORS، وعكس Origin
  ▼
3. SecurityHeadersMiddleware ← رؤوس استجابة أمنية HSTS / X-Frame-Options / CSP / Referrer-Policy
  ▼
4. ClientPlatformMiddleware  ← التعرف على رأس X-Client-Platform (8 منصات)، حقن الخصائص
  │                            يسري فقط على /api/v1/ و /admin/api/v1/
  ▼
5. GeoBlockMiddleware        ← حجب الدول GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← فحص 8 فئات بأكثر من 45 قاعدة (JSON body + URL + UA + الجسم الخام)
  │                          ← قائمة Content-Type البيضاء + حد جسم 10MB + حد URL 2KB
  │                             عند الإصابة ← AuditLogger::threat() ← 403
  ▼
7. SecurityPlugin            ← 31 نوعاً من كشف الهجمات (XSS/حقن SQL/SSRF/إزالة التسلسل وغيرها)، قائمة IP سوداء/بيضاء
  ▼
8. RateLimitMiddleware       ← تقييد تردد كل المسارات (دلوّان per-IP + per-token)
  ▼
9. LocaleMiddleware          ← تحليل Accept-Language، تعيين المنطقة
  ▼
10. MetricsMiddleware        ← عد طلبات HTTP وتأخيرها لـ Prometheus
  ▼
11. HashidRequestMiddleware  ← فك ترميز سلاسل hashid في معاملات الطلب ← معرّفات أعداد صحيحة حقيقية
  ▼
12. MaintenanceMiddleware    ← فحص MAINTENANCE_MODE، السماح لقائمة IP البيضاء
  │
  ▼
[وسائط المسارات — تُلحق حسب مجموعة المسار]
  │
  ├─ /health (مراقبة داخلية) ────────────
  │   InternalTokenMiddleware      ← التحقق من الرمز الداخلي /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← تشفير/فك تشفير جسم الطلب/الاستجابة AES-256-GCM
  │
  ├─ /api/v1 (مصادقة المستخدم) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← التحقق من JWT Bearer Token ← $request->userId/role
  │
  ├─ /api/v1 (عمليات حساسة) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← تأكيد كلمة المرور الثانوي، عدّاد Redis، 5 محاولات تقفل 15د
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← التحقق من SHA256 لـ sk_xxx ← $request->supplierId
  │
  ├─ /admin/api/v1 ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← فحص صلاحيات RBAC
  │
  └─ /admin/api/v1 (عمليات حساسة) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller ← Service ← Model ← DB
```

### 3.2 تفاصيل كل وسيط

| الوسيط | الموقع | طريقة التسجيل | المسؤولية |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | عام | التحقق من إصدار الـ API في مسار الـ URL |
| `CorsMiddleware` | common/Security | عام | طلبات OPTIONS الأولية، عكس Origin |
| `SecurityHeadersMiddleware` | common/Security | عام | رؤوس استجابة أمنية HSTS / X-Frame-Options / CSP / Referrer-Policy |
| `ClientPlatformMiddleware` | common/ClientPlatform | عام | التعرف على 8 منصات عبر `X-Client-Platform` |
| `GeoBlockMiddleware` | common/Security | عام | الحجب الجغرافي GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | عام(service)+admin | أكثر من 45 قاعدة في 8 فئات + حدود الطلبات |
| `SecurityPlugin` | Erikwang2013\Security | عام | 31 نوعاً من كشف الهجمات، قائمة IP بيضاء/سوداء |
| `RateLimitMiddleware` | common/Security | عام | تقييد تردد دلاء Redis (دلوّان per-IP + per-token) |
| `LocaleMiddleware` | common/I18n | عام | تحليل Accept-Language |
| `MetricsMiddleware` | common/Metrics | عام | عد طلبات HTTP وتأخيرها لـ Prometheus |
| `HashidRequestMiddleware` | common/Hashid | عام | فك ترميز طلبات hashid |
| `MaintenanceMiddleware` | common/Security | عام | وضع الصيانة + قائمة IP البيضاء |
| `InternalTokenMiddleware` | common/Security | مجموعة مسارات | التحقق من الرمز الداخلي `/health/live|ready|deps` |
| `EncryptionMiddleware` | common/Encryption | مجموعة مسارات | تشفير/فك تشفير AES-256-GCM |
| `AuthMiddleware` | common/Auth | مجموعة مسارات | التحقق من JWT Bearer Token |
| `AdminRoleMiddleware` | common/Auth | مجموعة مسارات | RBAC للمسؤولين |
| `ConfirmationMiddleware` | common/Confirmation | مجموعة مسارات | تأكيد كلمة المرور الثانوي |
| `SupplierApiKeyMiddleware` | common/Auth | مجموعة مسارات | التحقق من توقيع SHA256 لمفتاح API sk_xxx |

---

## 4. البنية البياناتية

### 4.1 المفتاح الأساسي الموزع: Snowflake

```
بنية معرّف Snowflake 64-bit:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  طابع زمني بالمللي ثانية  مركز بيانات   عقدة عمل     رقم تسلسلي
  العصر: 2024-01-01
  أقصى عمر: ~69 سنة
```

تولّد جميع نماذج Eloquent المعرّف تلقائياً في حدث `creating` عبر Trait `HasSnowflakeId`. نوع عمود قاعدة البيانات `bigint unsigned`.

### 4.2 تشويش المعرّفات: Hashids

```
تدفق الطلب:
  Client: GET /api/v1/products/aB3xK7mQ9w
    ← HashidRequestMiddleware فك الترميز ← int(1234567890)
      ← Controller/Service يعمل بالمعرّف الصحيح
        ← Response::success() / Response::paginated()
          ← hashids_encode_ids() ترميز متكرر لجميع حقول id/*_id
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 اتصالات قاعدة البيانات

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL رئيسي (كتابة) │     │  MySQL نسخة (قراءة) │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  اتصال دائم (PDO)    │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  قاعدة audit (اتصال مستقل) │
         │  تخزين معزول لسجلات التدقيق │
         └──────────────────────┘
```

### 4.4 طبقات التشفير

| الطبقة | الخوارزمية | التنفيذ | الغرض |
|------|------|------|------|
| طبقة النقل | AES-256-GCM | EncryptionMiddleware | تشفير جسم طلب/استجابة API، مصادقة GCM |
| طبقة الحقول | AES-128-ECB | Encryptable Cast | تشفير/فك تشفير تلقائي للحقول الحساسة (تشفير حتمي: نفس النص الصريح ← نفس النص المشفر، استعلام متساوٍ على النص المشفر لتسجيل الدخول/فحص التفرد؛ يدعم ECB فقط، تغيير cipher يتطلب ترحيل إعادة تشفير) |
| طبقة التجزئة | bcrypt + SHA256 | JWT / API Key | تخزين لا رجعة فيه لكلمات المرور/الرموز |
| طبقة المفاتيح الأساسية | Hashids | Response + Middleware | تشويش المعرّفات للخارج |

### 4.5 طبقات التخزين المؤقت

```
L1: طبقة التخزين المؤقت Redis (CacheService)
    قوائم المنتجات TTL 5د | تفاصيل المنتج TTL 10د
    المناطق TTL ساعة | أسعار الصرف TTL 30د | TLD TTL ساعة
    استراتيجية الإبطال: forget / forgetPattern نشط عند تغيير البيانات

L2: طبقة استعلام MySQL (Eloquent + تحسين الفهارس)
    13 فهرساً مركباً/شاملاً يغطي الاستعلامات عالية التردد

L3: ضغط استجابات Nginx (gzip level 6)
    نسبة ضغط استجابات JSON 70-85%
```

### 4.6 الترجمة الدولية (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (وسيط عام)
         │  تحليل اللغة الرئيسية ← zh-CN
         │  I18n::setLocale('zh-CN')
         │  تحميل i18n/zh-CN/messages.php
         ▼
  Controller / Service
         │
         ├── I18n::trans('auth.login_success')  ←  'تم تسجيل الدخول بنجاح'
         ├── I18n::translateField($jsonField)   ←  القيمة حسب اللغة
         └── I18n::getLocale()                  ←  'zh-CN'
```

| القدرة | الوصف |
|------|------|
| تحليل الرؤوس | `LocaleMiddleware` يحلل رأس `Accept-Language` تلقائياً |
| الرجوع اللغوي | اللغات غير المدعومة ← `fallback_locale` |
| الترجمة الثابتة | 120 مدخلاً تغطي 15 وحدة (`i18n/{locale}/messages.php`) |
| استبدال المعاملات | `I18n::trans('key', ['field' => 'value'])` |
| حقول JSON | `translateField()` تتعامل مع أعمدة JSON متعددة اللغات |

---

## 5. البنية الأمنية

### 5.1 نظام قواعد WAF (8 فئات أكثر من 45 قاعدة)

| الفئة | عدد القواعد | نطاق الكشف |
|------|--------|---------|
| حقن SQL | 9 | رموز التعليقات والكلمات المفتاحية والتشفير السداسي واستعلامات الوصل والشروط الصحيحة دائماً والحقن الأعمى الزمني والاستعلامات المكدسة |
| XSS | 8 | وسوم HTML وأشكال Script و13 معالج أحداث وبروتوكول JS الزائف وترميز الكيانات وData URI |
| حقن الأوامر | 5 | أوامر بعد الأنبوب وأوامر بعد الفاصلة المنقوطة و$(cmd) وعلامات الاقتباس الخلفية وكلمات أوامر مستقلة |
| تضمين الملفات | 4 | عبور المسار وبروتوكول PHP الزائف والمسارات المطلقة وNull byte |
| حقن رؤوس HTTP | 2 | سطر CRLF وحقن Host/Cookie/Set-Cookie |
| SSRF | 6 | عناوين IP الداخلية وlocalhost وmetadata السحابية وبروتوكول file:// |
| حقن NoSQL | 3 | عوامل تشغيل MongoDB وأوامر Redis الخطرة |
| إعادة التوجيه المفتوح | 2 | redirect_uri لعناوين خارجية والالتفاف بالترميز المزدوج |

**نطاق الفحص:** قواعد حقن القيم (SQLi/XSS/حقن الأوامر/حقن الرؤوس/SSRF/NoSQL/إعادة التوجيه المفتوح) تفحص سلسلة الاستعلام وجسم الطلب وUser-Agent؛ ومسار URL يستخدم فقط التحقق البنيوي لنمط تضمين الملفات (عبور المسار). المسارات التجارية تحتوي كلمات عادية مثل select/insert/alert (مثل `/order_item/select`)، وفحص المسار كاملاً كان سيقتل جميع نقاط نهاية CRUD، لذلك لا يشارك path في مطابقة حقن القيم.

**حماية طبقة الطلب:** قائمة Content-Type البيضاء، حد جسم الطلب 10MB، حد URL 2KB

### 5.2 نظام المصادقة

```
┌─────────────────────────────────────────────┐
│                طرق المصادقة                  │
├──────────────┬──────────────┬───────────────┤
│  جانب المستخدم │  جانب الإدارة  │  API الموردين   │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15د  │  Access 2س   │  بادئة sk_xxx  │
│  Refresh 30يوم│  Refresh 7أيام │  تخزين SHA256  │
│  TOTP اختياري │              │  يُعرض مرة واحدة │
│  OAuth اختياري│              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. بنية النشر

### 6.1 طوبولوجيا الإنتاج

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← حماية طرفية للمنصة نفسها (DDoS/Bot)،
               │  DDoS / Bot     │     لا علاقة لها بوحدة CDN على مستوى
               └────────┬────────┘     المنتج (أربعة مزودين، انظر §2.4)
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL رئيسي/نسخة │ Redis Cluster │
         │ 1 رئيسي 2 نسختان│ تخزين مؤقت+قوائم│
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  تسجيل e-cat / etcd        │
         │  محرك محاكى (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  مجموعة Proxmox VE         │
         │  أجهزة مادية × N           │
         │  محاكاة KVM/QEMU الافتراضية │
         └────────────────────────────┘
```

### 6.2 نموذج العمليات

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (الافتراضي 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. التبعيات التقنية

### 7.1 الأطر الأساسية

| الحزمة | الإصدار | الغرض |
|----|------|------|
| workerman/webman-framework | ^2.1 | إطار الويب (مقيم بالذاكرة متعدد العمليات) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | نظام الأحداث |
| illuminate/redis | ^10.0 | عميل Redis |
| webman/redis-queue | ^1.0 | قائمة رسائل Redis |

### 7.2 حزم بيئة erikwang2013

| الحزمة | الغرض |
|----|------|
| snowflake-php | مفتاح أساسي موزع 64-bit |
| hashids | تشويش معرّفات API |
| encryptable | تشفير حقول قاعدة البيانات |
| encryption | تشفير النقل AES-256-GCM |
| jwt-webman | مصادقة JWT |
| webman-scout | البحث النصي الكامل عبر Elasticsearch |
| season | رموز emoji لأعلام الدول |
| poster-php | كابتشا النقر |

### 7.3 التكاملات من طرف ثالث

| الحزمة | الغرض |
|----|------|
| stripe/stripe-php | الدفع عبر Stripe |
| twilio/sdk | الرسائل القصيرة |
| kreait/firebase-php | دفع FCM |
| guzzlehttp/guzzle | عميل HTTP (واجهة Proxmox وغيرها) |
| sentry/sentry | مراقبة الاستثناءات |
| phpoffice/phpspreadsheet | تصدير Excel |
