# Cloud Platform — منصة تداول موارد الحوسبة السحابية العالمية

## اللغات (Languages)

| Language | Docs |
|----------|------|
| 简体中文 | [README.md](../../../README.md) |
| English | [README_EN.md](../../../README_EN.md) |
| English | [en docs](../../en/README.md) |
| 한국어 | [ko docs](../../ko/README.md) |
| Русский | [ru docs](../../ru/README.md) |
| Deutsch | [de docs](../../de/README.md) |
| Français | [fr docs](../../fr/README.md) |
| Español | [es docs](../../es/README.md) |
| Português | [pt docs](../../pt/README.md) |
| हिन्दी | [hi docs](../../hi/README.md) |
| العربية | [ar docs](../../ar/README.md) |
| বাংলা | [bn docs](../../bn/README.md) |
| Bahasa Indonesia | [id docs](../../id/README.md) |
| 日本語 | [ja docs](../../ja/README.md) |

<p align="center">
  <img src="docs/diagrams/c.svg" alt="تميمة مشروع CloudPlatform" width="220">
</p>

منصة تداول موارد حوسبة سحابية موجّهة للمستخدمين حول العالم، تدعم الشراء عبر الإنترنت والتسليم التلقائي للخوادم (VM) وعناوين IP والأقراص السحابية والنطاقات وشهادات SSL والتخزين الكائني (S3) وتسريع CDN وغيرها من المنتجات. تُسلَّم الأجهزة الفعلية ذاتية التشغيل عبر تقنية المحاكاة الافتراضية Proxmox VE، كما تدعم المنصة انضمام موردين خارجيين للبيع. توفر الفوترة حسب الاستخدام والتوزيع بالإحالة وواجهة GraphQL API ومراقبة عبر Prometheus/Grafana.

## حزمة التقنيات

| الطبقة | التقنية |
|------|------|
| إطار الواجهة الخلفية | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| لوحة الإدارة | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| المصادقة | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| المفاتيح الموزعة | Snowflake ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| تشويش المعرّفات | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| تشفير النقل | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| تشفير الحقول | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| البحث النصي الكامل | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| أعلام الدول | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| كابتشا النقر | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| الحماية الأمنية | 31 نوعًا من اكتشاف الهجمات ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| تصدير الجداول | PhpSpreadsheet ^2.0 |
| SDK الدفع | Stripe PHP ^15.0 |
| SDK الرسائل القصيرة | Twilio PHP ^8.0 |
| SDK الإشعارات | Firebase PHP ^7.0 |
| قوائم الانتظار | webman redis-queue |
| قاعدة البيانات | MySQL 8.0 (اتصال مزدوج: أساسي + تدقيق) |
| محرك البحث | Elasticsearch 8.x |
| المحاكاة الافتراضية | Proxmox VE (قناة gRPC kvm-server بلغة Rust، تسجيل e-cat/etcd) |
| العميل | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| التخزين الكائني | AWS S3 SDK PHP ^3.300 |
| المراقبة | Prometheus + Grafana (لوحات جاهزة) |
| تعدد اللغات | i18n 7 لغات (صيني/إنجليزي/ياباني/كوري/ألماني/فرنسي/إسباني) |
| النشر | تشغيل بنقرة واحدة عبر Docker Compose |

## بنية النظام

![بنية النظام](docs/diagrams/system-architecture-zh.svg)

## سير العمل الأساسي

سير عمل شامل من تسجيل المستخدم حتى تسليم الموارد، ويشمل الاختيار والطلب والدفع والتسليم التلقائي وإدارة ما بعد البيع ودورة التجديد.

![سير العمل الأساسي](docs/diagrams/business-flowchart-zh.svg)

## التسوية متعددة العملات

يدعم النظام أصلاً التسعير والدفع والتسوية بعدة عملات، ويغطي السلسلة الكاملة من إعداد عملة المستخدم والتسعير الإقليمي ولقطة سعر الصرف إلى تحصيل المدفوعات وإيداع الأرصدة وتسوية الموردين.

![مخطط تدفق التسوية متعددة العملات](docs/diagrams/currency-settlement-zh.svg)

**1. حسابات الأرصدة متعددة العملات**

يسجّل `user_balances` الحسابات حسب `(user_id, currency)` لكل عملة على حدة (فهرس فريد `uk_user_currency`). عند التسجيل يتم إنشاء حسابين افتراضياً بالدولار الأمريكي واليوان الصيني، وتُدار الأرصدة والأرصدة المجمّدة بشكل مستقل لكل عملة، مع إمكانية التوسع لأي عملة يدعمها Stripe.

**2. التسعير الإقليمي متعدد العملات**

يدعم `product_regions` تسعير نفس وحدة SKU بعدة عملات في نفس المنطقة (فهرس فريد `uk_sku_region_currency`). تعرض الواجهة الأمامية السعر بعملة المستخدم المفضلة، وعند الطلب يستخدم `OrderService` (sku_id, region_id, currency) لاستخراج السعر بدقة.

**3. نظام أسعار الصرف**

تزامن مهمة `ExchangeRateSync` المجدولة أسعار الصرف من exchangerate-api وتكتبها في Redis (ذاكرة تخزين مؤقت بفترة صلاحية 30 دقيقة). يسجّل كل طلب لقطة `exchange_rate` لسعر الصرف وقت الطلب، ما يضمن إمكانية تتبع التسويات لاحقاً.

**4. الدفع متعدد العملات**

يعلن `payment_channels.currency_support` القائمة البيضاء للعملات التي يدعمها كل قناة دفع، ويقوم `PaymentRouter` بفلترة القنوات المتاحة ديناميكياً حسب العملة/نطاق المبلغ/المناطق الظاهرة. يجمع Stripe PaymentIntent مباشرة بعملة الطلب، مع معالجة مدمجة لـ 16 عملة بدون كسور عشرية (JPY / KRW / VND وغيرها)، وتتحقق ردود Webhook من تطابق المبلغ والعملة.

**5. التسوية والتقارير**

تحتفظ معاملات الدفع (`payment_transactions`) وتسويات الموردين (`supplier_settlements`) وتقارير الإيرادات بحقول العملة وسعر الصرف، وتُجمّع الإحصائيات حسب العملة.

## نظرة عامة على الوحدات الوظيفية

يُنظم النظام وفق بنية من أربع طبقات: طبقة العميل (6 منصات)، وطبقة بوابة API (12 وسيطاً)، وطبقة خدمات الأعمال (20+ وحدة وظيفية)، وطبقة البنية التحتية (8 مكونات أساسية).

![نظرة عامة على الوحدات الوظيفية](docs/diagrams/module-overview-zh.svg)

## دورة حياة الموارد

تمر الموارد من الإنشاء حتى الإنهاء بـ 6 حالات، تقودها 8 أحداث لدورة الحياة، وتدعم التسليم التلقائي والتعليق والاستئناف وتذكير الانتهاء والتنظيف عند الإتلاف.

![دورة حياة الموارد](docs/diagrams/resource-lifecycle-zh.svg)

## دليل التوثيق

| المستند | الوصف |
|------|------|
| [مستند تصميم البنية](docs/architecture.md) | بنية النظام، علاقات المكونات، خط الوسائط، طبقات الأمان، بنية البيانات، طوبولوجيا النشر |
| [مستند تصميم الوظائف](docs/features.md) | تصميم تفصيلي لـ 21 وحدة، يتضمن مخططات التدفق ونماذج البيانات وشرح التفاعل |
| [توثيق واجهات API](docs/api-reference.md) | مرجع كامل لأكثر من 200 نقطة نهاية، مجمّعة حسب الوحدة، مع أمثلة الطلب/الاستجابة وأكواد الأخطاء |
| [توثيق API عبر الإنترنت (service)](http://localhost:8787/apidoc) | مولّد تلقائياً عبر hg/apidoc، مجمّع حسب الوظيفة، يدعم التصحيح عبر الإنترنت |
| [توثيق API عبر الإنترنت (admin)](http://localhost:8788/apidoc) | مولّد تلقائياً عبر hg/apidoc، 54 وحدة تحكم في 13 مجموعة وظيفية |
| [تصميم لوحة الإدارة](docs/admin-design.md) | بنية لوحة Admin وتكامل الحزم وصلاحيات ACL ومجموعة الاختبارات |
| [توثيق API الموردين](docs/supplier-api.md) | مرجع API الموردين (داخلي + خارجي) وأمثلة SDK |
| [قائمة النشر](docs/deployment.md) | إعدادات الخادم والمتغيرات البيئية وNginx وHTTPS والمهام المجدولة |
| [تقرير المراجعة](docs/review-report-2026-08-04.md) | تقرير مراجعة التوسع البيئي، يتضمن بيانات إحصائية وتتبع المشكلات واقتراحات التوسع |
| [مقارنة الإصدارات](docs/editions.md) | مقارنة الوظائف والتصميم والبنية للإصدارات المبسطة/القياسية/الكاملة |

## بنية الدليل

```
cloud-php/
├── .claude/                    # إعدادات Claude Code (settings / skills)
├── .github/workflows/          # خط أنابيب CI/CD (فحص الصيغة + PHPUnit للطرفين)
├── admin/                      # لوحة الإدارة (مثيل webman مستقل)
│   ├── app/                    # كود الإضافات (PSR-4: app\)
│   │   ├── bootstrap/          # تهيئة تشغيل العمليات (Snowflake / Encryptable / Encryption)
│   │   ├── command/            # أوامر وحدة التحكم (Migrate / Rollback / Status)
│   │   ├── common/             # فئات الأدوات (Auth / Tree / Layui / Util / ExcelExport / Migration)
│   │   ├── controller/         # 54 ملف وحدة تحكم (Base / فئات أساسية Crud + عمليات CRUD لكل الأعمال)
│   │   ├── exception/          # معالجة الاستثناءات
│   │   ├── middleware/          # وسائط التحكم في الوصول (WafMiddleware + AccessControl)
│   │   ├── model/              # 46 نموذج Eloquent (فئة أساسية Base بمفتاح Snowflake + Encryptable)
│   │   ├── view/               # قوالب العرض (لوحة Layui)
│   │   └── functions.php       # دوال مساعدة عامة (hashids / encrypt / decrypt)
│   ├── api/                    # واجهات خارجية (PSR-4: plugin\admin\api)
│   │   ├── Auth.php            # واجهة المصادقة
│   │   ├── Menu.php            # واجهة القوائم
│   │   ├── Install.php         # واجهة التثبيت
│   │   └── Middleware.php      # واجهة الوسائط
│   ├── config/                 # إعدادات التطبيق
│   │   ├── plugin/erikwang2013/ # إعدادات 6 حزم erikwang2013
│   │   │   ├── snowflake-php/  # توليد معرّف Snowflake
│   │   │   ├── hashids/        # تشويش المعرّفات
│   │   │   ├── encryptable/    # تشفير على مستوى الحقول
│   │   │   ├── encryption/     # تشفير النقل
│   │   │   ├── webman-scout/   # مزامنة Elasticsearch
│   │   │   └── season/         # أعلام الدول
│   │   ├── route.php           # تعريف المسارات
│   │   ├── middleware.php       # إعداد الوسائط
│   │   ├── database.php        # اتصال قاعدة البيانات
│   │   └── ...                 # 18 ملف إعداد
│   ├── database/migrations/    # ملفات ترحيل قاعدة البيانات
│   ├── tests/                  # اختبارات الوحدة (PHPUnit 11, 286 tests / 962 assertions)
│   │   ├── HashidsTest.php     # ترميز/فك ترميز hashids (21 tests)
│   │   ├── BaseJsonTest.php    # ترميز Base::json() للمعرّفات (13 tests)
│   │   ├── CrudHashidsTest.php # فك ترميز إدخالات Crud (14 tests)
│   │   ├── TreeTest.php        # البنية الشجرية (19 tests)
│   │   ├── AccessControlMiddlewareTest.php # التحكم في الوصول RBAC
│   │   ├── AdminControllersTest.php        # انحدار وحدات التحكم
│   │   └── support/            # فئات مساعدة للاختبار
│   ├── public/                 # جذر المستندات (الموارد الثابتة)
│   ├── vendor/                 # تبعيات Composer
│   ├── .env.example            # قالب المتغيرات البيئية
│   ├── composer.json           # إعلان التبعيات
│   ├── generate.php            # مولّد الأكواد
│   ├── phpunit.xml             # إعداد PHPUnit
│   └── start.php               # نقطة البدء
├── service/                    # خدمة الواجهة الخلفية (مثيل webman مستقل)
│   ├── app/                    # الوحدات الوظيفية (PSR-4: App\)، كل وحدة تتضمن طبقات Controller / Model / Service
│   │   ├── admin/controller/   # واجهات برمجية للوحة الإدارة (15 وحدة تحكم: Dashboard / User / Product / Order / Payment / Supplier / Coupon / Invoice / Domain / Webhook وغيرها)
│   │   ├── affiliate/          # عمولات التحالف / توزيع الإحالة (Controller / Listener / Model / Service)
│   │   ├── billing/            # الفوترة حسب الاستخدام / الفواتير (Cron / Service)
│   │   ├── captcha/controller/ # كابتشا النقر
│   │   ├── cdn/                # استضافة موارد CDN (Controller / Model / Provider / Service)
│   │   ├── command/            # أوامر وحدة التحكم (Migrate / Rollback / Status / DbBackup)
│   │   ├── controller/         # وحدات تحكم عامة (Health / Status / Help / Upload)
│   │   ├── cron/               # المهام المجدولة (مجدول CronRunner + ExchangeRateSync / PaymentReconcile / SupplierSettlement / ExpirationCheck / SslCertificateCheck)
│   │   ├── domain/             # تسجيل النطاقات / إدارة DNS (Controller / Model / Service)
│   │   ├── graphql/            # واجهة GraphQL API (Mutation / Query / Schema)
│   │   ├── grpc/               # عميل kvm-server gRPC + تسجيل etcd (KvmClient / EtcdRegistry)
│   │   ├── model/              # نماذج عامة (HelpArticle / Role / Permission)
│   │   ├── monitor/            # مراقبة الموارد / التنبيهات (Controller / Cron / Model / Service)
│   │   ├── notification/       # إشعارات الرسائل (Controller / Model / Queue / Service)
│   │   ├── order/              # سلة التسوق / الطلبات / القسائم / الفواتير (Controller / Model / Service)
│   │   ├── payment/            # توجيه الدفع / قناة Stripe (Controller / Event / Model / Service)
│   │   ├── product/            # المنتجات / SKU / التسعير الإقليمي / التقييمات (Controller / Model / Service)
│   │   ├── provisioning/       # محرك تسليم الموارد (Controller / Event / Listener / Model / Provider / Queue / Service)
│   │   ├── report/             # تقارير الإيرادات / الموردين / المناطق (Controller / Service)
│   │   ├── ssl/                # إصدار / إدارة شهادات SSL (Controller / Model / Service)
│   │   ├── storage/            # موارد التخزين الكائني (Controller / Model / Provider / Service)
│   │   ├── supplier/           # انضمام الموردين / التسوية / السحب + واجهة خارجية (Controller / Model / Service)
│   │   ├── ticket/             # نظام التذاكر (Controller / Event / Listener / Model / Service)
│   │   ├── user/               # المستخدمون / المصادقة / KYC / الأرصدة / العناوين (Controller / Model / Service)
│   │   ├── webhook/            # قائمة انتظار رسائل Webhook (Queue)
│   │   └── websocket/          # خادم WebSocket + مستمعو الأحداث
│   ├── common/                 # مكتبة عامة (PSR-4: Common\)
│   │   ├── auth/middleware/     # AuthMiddleware / AdminRoleMiddleware / RbacMiddleware / RoleMiddleware / SupplierApiKeyMiddleware
│   │   ├── captcha/            # خدمة كابتشا النقر
│   │   ├── confirmation/       # وسيط التأكيد الثانوي (إعادة إدخال كلمة المرور)
│   │   ├── encryption/middleware/ # وسيط تشفير النقل AES-256-GCM
│   │   ├── hashid/middleware/   # وسيط فك ترميز Hashids التلقائي للطلبات + خدمة الترميز
│   │   ├── helper/             # تنسيق الاستجابة (ترميز hashid تلقائي)
│   │   ├── http/               # أدوات عميل HTTP (ApiRequest)
│   │   ├── i18n/middleware/     # وسيط تعدد اللغات (Locale)
│   │   ├── security/           # CORS / WAF / الحد من التردد / الحظر الجغرافي / وضع الصيانة / سجلات التدقيق
│   │   ├── snowflake/          # خدمة توليد معرّفات Snowflake / Trait Eloquent HasSnowflakeId
│   │   ├── version/middleware/  # وسيط إصدار API (التحقق من رأس X-Api-Version)
│   │   ├── clientplatform/middleware/  # وسيط منصة العميل (التعرف على رأس X-Client-Platform)
│   │   ├── feature/            # خدمة مفاتيح الميزات Feature Flags
│   │   └── webhook/            # موزّع أحداث Webhook
│   ├── config/                 # 17 ملف إعداد (route / middleware / database / redis / cron / auth / security / i18n / ...)
│   │   └── plugin/             # إعدادات الإضافات
│   │       ├── erikwang2013/   # encryptable / hashids / jwt / poster / season / webman-scout
│   │       └── webman/         # event / redis-queue
│   ├── database/migrations/    # ملفات ترحيل قاعدة البيانات (37 ترحيلاً)
│   ├── i18n/                   # موارد تعدد اللغات (en-US / zh-CN)
│   ├── support/                # تهيئة Bootstrap (Eloquent / Redis / Event / التشفير / Snowflake / Hashids / Scout / MigrationRunner)
│   ├── tests/                  # اختبارات الوحدة (PHPUnit 10, 672 tests / 1632 assertions)
│   │   ├── admin/              # ImportExport / SupplierWithdrawApprove
│   │   ├── affiliate/          # AffiliateService
│   │   ├── auth/               # JwtAuth / RbacSeed / Rbac
│   │   ├── billing/            # MeterCollector / UsageAggregator / SuspendCheck
│   │   ├── captcha/            # CaptchaService
│   │   ├── cdn/                # ResourceCdn
│   │   ├── clientplatform/     # ClientPlatformMiddleware
│   │   ├── common/             # Response / Hashid / Snowflake / Validator / LogSanitizer / Totp / ApiRequest
│   │   ├── confirmation/       # ConfirmationMiddleware
│   │   ├── cron/               # SupplierSettlement
│   │   ├── domain/             # DomainService / DomainTransfer
│   │   ├── graphql/            # Schema
│   │   ├── grpc/               # KvmClient / EtcdRegistry
│   │   ├── monitor/            # AlertEngine
│   │   ├── notification/       # NotificationDispatcher
│   │   ├── order/              # Coupon / Invoice
│   │   ├── payment/            # StripeChannel / PaymentRouter
│   │   ├── product/            # ProductService / Search / ReviewStatus
│   │   ├── provisioning/       # ProviderFactory / RetryLogic
│   │   ├── report/             # ReportService
│   │   ├── security/           # RateLimit / Maintenance / UploadSecurity
│   │   ├── ssl/                # SslCertificate
│   │   ├── storage/            # StorageBucket
│   │   ├── supplier/           # SupplierService / Settlement / Rating / Webhook
│   │   ├── ticket/             # TicketUpdatedWiring
│   │   ├── user/               # AddressController
│   │   ├── version/            # VersionMiddleware
│   │   ├── webhook/            # WebhookDispatcher / WebhookE2E
│   │   ├── websocket/          # WebSocketAuth / EventsWiring
│   │   ├── support/            # RequestMock
│   │   ├── bootstrap.php       # تهيئة الاختبار
│   │   └── TestCase.php        # الفئة الأساسية للاختبارات
│   ├── runtime/                # ملفات وقت التشغيل (سجلات / ذاكرة تخزين مؤقت)
│   ├── vendor/                 # تبعيات Composer
│   ├── .env.example            # قالب المتغيرات البيئية
│   ├── .env                    # المتغيرات البيئية المحلية (gitignore)
│   ├── composer.json           # إعلان التبعيات
│   ├── phpunit.xml             # إعداد PHPUnit
│   └── start.php               # نقطة البدء
├── apps/
│   ├── flutter/                # عميل Flutter (iOS / macOS / Windows / Linux / Web)
│   │   ├── lib/                # كود Dart (core / features)
│   │   ├── ios/                # مشروع iOS
│   │   ├── macos/              # مشروع macOS
│   │   ├── windows/            # مشروع Windows
│   │   ├── linux/              # مشروع Linux
│   │   ├── web/                # مشروع Web
│   │   ├── test/               # اختبارات Flutter
│   │   ├── pubspec.yaml        # إعلان التبعيات
│   │   └── analysis_options.yaml # إعداد التحليل الثابت Dart
│   └── harmonyos/              # هيكل عميل HarmonyOS
│       └── entry/src/          # كود ArkTS
├── docker/                     # نشر Docker
│   ├── Dockerfile              # صورة PHP 8.2
│   ├── docker-compose.yml      # تنسيق الخدمات
│   ├── nginx.conf              # إعداد Nginx
│   └── supervisor.conf         # حماية العمليات Supervisor
├── infrastructure/             # البنية التحتية بلغة Rust (workspace e-cat)
│   ├── kvm-server/             # خدمة السحابة الذاتية: خدمة gRPC لتزويد VM (:50051، تسجيل etcd)
│   │   ├── src/                # main / grpc / driver (محرك محاكاة، libvirt في المرحلة 2)
│   │   ├── tests/              # اختبارات التكامل
│   │   └── Cargo.toml          # إعلان عضو workspace e-cat
│   └── ecat-*/                 # crates البنية التحتية e-cat (transport-grpc / registry-etcd / protos / config / data وغيرها)
├── docs/                       # التوثيق
│   ├── admin-design.md         # مستند تصميم لوحة الإدارة
│   ├── supplier-api.md         # مستند API الموردين
│   ├── deployment.md           # قائمة النشر
│   ├── api-test.sh             # سكربت اختبار API
│   ├── database.sql            # DDL قاعدة البيانات
│   ├── alipay.png / weixinpay.png  # رموز QR للتبرع
│   ├── diagrams/               # 18 مخطط بنية SVG (بنية النظام / خط الأمان / مخطط ER / سير العمل / التسوية متعددة العملات وغيرها)
│   ├── test-reports/           # تقارير الاختبار (PHPUnit / Rust / API / UI + لقطات شاشة)
│   └── superpowers/            # مواصفات التصميم وخطط التنفيذ
│       ├── specs/              # مستندات مواصفات تصميم النظام
│       └── plans/              # خطط التنفيذ المرحلية Phase 0~3
├── scripts/                     # سكربتات التشغيل (push-release.sh قواعد نشر الإصدارات: زيادة الإصدار + tag)
├── tests/k6/                    # سكربتات اختبار الحمل k6 (تدخين/منتجات/توافق)
├── install.php                 # نقطة دخول معالج التثبيت بنقرة واحدة
├── install/                    # صفحة معالج التثبيت
│   └── index.php               # تطبيق ويب المعالج
├── install.sql                 # DDL موحد لقاعدة البيانات (46 جدولاً)
├── .gitignore
├── README.md                   # وصف المشروع (الصينية)
└── README_EN.md                # وصف المشروع (الإنجليزية)
```

## البدء السريع

### متطلبات البيئة

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### التثبيت بنقرة واحدة (موصى به)

يوفر المشروع معالج تثبيت ويب يمكنه إكمال جميع الإعدادات من خلال المتصفح:

```bash
# 1. تثبيت التبعيات
cd service && composer install && cd ../admin && composer install && cd ..

# 2. تشغيل معالج التثبيت
php install.php
# افتح المتصفح وانتقل إلى http://localhost:8888

# 3. أكمل حسب تعليمات المعالج:
#    - فحص البيئة
#    - إعداد قاعدة البيانات (المضيف والمنفذ واسم القاعدة واسم المستخدم وكلمة المرور)
#    - إعداد حساب مسؤول اللوحة (اسم المستخدم وكلمة المرور والبريد الإلكتروني)
#    - تنفيذ التثبيت بنقرة واحدة (إنشاء الجداول + كتابة الإعدادات)
```

بعد اكتمال التثبيت، سيقوم المعالج تلقائياً بـ:
- إنشاء جميع جداول قاعدة البيانات الـ 46 (جداول إدارة wa_* + جداول الأعمال بدون بادئة)
- إنشاء دور وحساب المسؤول المتميز
- توليد ملفي الإعداد `service/.env` و `admin/.env` (يتضمنان مفاتيح JWT/التشفير المولدة تلقائياً)

### التثبيت اليدوي

```bash
cd service

# 1. تثبيت التبعيات
composer install

# 2. إعداد المتغيرات البيئية
cp .env.example .env
# عدّل .env واملأ كلمة مرور قاعدة البيانات ومفتاح JWT ومفاتيح التشفير وغيرها
# توليد ENCRYPTION_MASTER_KEY: openssl rand -base64 32
# توليد ENCRYPTION_KEY: echo -n "$(openssl rand -base64 16)" | base64 -w0
# توليد JWT_SECRET_KEY: openssl rand -base64 32

# 3. إنشاء قاعدة البيانات واستيرادها
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cloud_platform < ../install.sql

# 4. تشغيل الخدمة (وضع التطوير)
php start.php start
# الوصول عبر http://localhost:8787
```

### النشر عبر Docker

```bash
# من جذر المشروع
cp service/.env.example .env
# عدّل .env واملأ المفاتيح

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### لوحة الإدارة

```bash
cd admin

# 1. تثبيت التبعيات
composer install

# 2. إعداد المتغيرات البيئية
cp .env.example .env
# إذا استخدمت معالج التثبيت بنقرة واحدة، فقد تم توليد هذا الملف تلقائياً

# 3. تشغيل الخدمة (وضع التطوير)
php start.php start
# الوصول عبر http://localhost:8787/app/admin
```

### وضع عملية الخدمة الخلفية (Daemon)

```bash
php start.php start -d          # تشغيل
php start.php status            # عرض الحالة
php start.php restart           # إعادة تشغيل
php start.php stop              # إيقاف
```

## نظرة عامة على API

الواجهات مجمّعة حسب الوحدة، وتتضمن أمثلة الطلب/الاستجابة وأكواد الأخطاء: [نظرة عامة على API](docs/api-overview.md) (مختارة) · [توثيق واجهات API](docs/api-reference.md) (مرجع كامل لأكثر من 200 نقطة نهاية) · [تصحيح عبر الإنترنت](http://localhost:8787/apidoc)

## بنية لوحة الإدارة

### التكامل التقني

لوحة الإدارة هي مثيل webman مستقل يدمج 7 حزم erikwang2013:

| الحزمة | الغرض | طريقة التنفيذ |
|---|------|---------|
| snowflake-php | مفاتيح موزعة 64 بت | توليد تلقائي عبر حدث `Base::boot()` creating |
| hashids | تشويش معرّفات API | ترميز الاستجابة عبر `Base::json()`، فك ترميز الطلبات عبر `Crud::selectInput/updateInput/deleteInput` |
| encryptable | تشفير حقول قاعدة البيانات | cast `Encryptable` الخاص بـ Eloquent، تشفير/فك تشفير شفاف لـ Admin (password/email/mobile) وUser (6 حقول) |
| encryption | تشفير نقل API | دوال مساعدة محجوزة `encrypt_data()`/`decrypt_data()` |
| webman-scout | البحث النصي الكامل ES | Trait `Searchable` لنموذج User، مزامنة فهارس تلقائية |
| season | رموز أعلام الدول | دالة مساعدة عامة `country_season_flag()` |
| poster-php | كابتشا النقر | Bootstrap `CaptchaPlugin`، دالتان عامتان `captcha_create()`/`captcha_verify()` |

### طبقات الأمان

```
الطلب ← فك ترميز Hashids (Crud::selectInput/updateInput/deleteInput)
  ← مصادقة ACL (api/Auth.php، noNeedLogin/noNeedAuth في وحدة التحكم)
  ← معالجة الأعمال (CRUD / أحداث النموذج)
  ← تشفير حقول Encryptable (casts الخاصة بـ Eloquent)
  ← الكتابة إلى قاعدة البيانات
الاستجابة ← ترميز Hashids (Base::json → hashids_encode_ids)

تسجيل الدخول/التسجيل: التحقق Captcha ← Auth ← معالجة الأعمال
```

### تدفق البيانات

- **مسار الكتابة**: معرّف الطلب (hashid) ← فك ترميزه إلى int ← عملية CRUD ← توليد معرّف Snowflake جديد ← تشفير الحقول الحساسة عبر Encryptable ← قاعدة البيانات
- **مسار القراءة**: قاعدة البيانات ← فك تشفير Encryptable ← ترميز المعرّف عبر Hashids ← استجابة JSON

### تغطية الاختبارات

```
phpunit.xml (PHPUnit 11, 286 tests / 962 assertions)
├── HashidsTest              (21 tests) encode/decode/encode_ids
├── BaseJsonTest             (13 tests) ترميز Base::json/success/fail
├── CrudHashidsTest          (14 tests) فك ترميز إدخالات Crud (select/update/delete)
├── TreeTest                 (19 tests) البنية الشجرية / الأبناء / الأجداد / العقد اليتيمة
├── AccessControlMiddlewareTest (7 tests) غير مسجل 401 / صفحة 403 / السماح
├── AdminControllersTest     (data provider) تجميع 48 وحدة تحكم / سطح CRUD / مسارات عرض GET
├── UtilTest                 (17 tests) كلمة المرور / الوقت / البايت / تصفية الإدخال / خصائص عناصر التحكم
├── DictTest                 (5 tests) تحويل اسم القاموس ↔ خيار / save/get/delete
├── ExcelExportTest          (4 tests) رأس الجدول / تسطيح JSON / رقم الصف / الخلايا الفارغة
└── LayuiTest                (5 tests) input / inputNumber / تهريب label / switch / html
```

## أفكار التصميم

### 1. وحدات متراصة (Modular Monolith)

تنقسم الوحدات عمودياً حسب مجال الأعمال (User / Product / Order / Payment / Provisioning / Ticket / Notification وغيرها)، وتتبع كل وحدة داخلياً طبقات MVC:

- **Controller** — طبقة HTTP، التحقق من المعاملات، استدعاء Service، إرجاع Response
- **Service** — منطق الأعمال، بدون تبعية HTTP، قابل لإعادة الاستخدام من Controller وQueue Worker
- **Model** — نماذج بيانات Eloquent، تعرّف العلاقات ونطاقات الاستعلام

تُفصل الوحدات عن بعضها عبر **الأحداث** و**الواجهات**، دون استدعاء مباشر لخدمات بعضها. مثال: اكتمال الدفع ← حدث `OrderPaid` ← فتح الموارد تلقائياً عبر `ProvisioningService`، وإنشاء تذكرة ← حدث `TicketCreated` ← توزيع تلقائي على خدمة العملاء.

### 2. التسليم المدفوع بالأحداث

```
الطلب من المستخدم ← نجاح الدفع ← حدث OrderPaid
  ← ProvisioningService.handleOrderPaid()
    ← إنشاء ProvisionTask لكل OrderItem (status=pending)
    ← مستهلك قائمة Redis Queue: ProvisionWorker
      ← ProviderFactory.create(task) لتحليل Provider
      ← ProxmoxProvider.create()
        ← HostSelector لاختيار الجهاز الفعلي الأكثر فراغاً
        ← ProxmoxApi لإنشاء VM / تركيب القرص / تخصيص IP
          (خدمة التزويد kvm-server gRPC بلغة Rust مدمجة: اكتشاف تسجيل e-cat/etcd،
           وربط KvmClient في طرف PHP؛ محرك محاكاة، والمحرك الفعلي libvirt في المرحلة 2)
        ← إنشاء سجل Resource / Disk
      ← تحديث حالة Order إلى completed
```

يُعاد إرسال التسليم الفاشل تلقائياً مع استراتيجية التراجع: 1min ← 5min ← 15min ← 1h ← 6h ← 24h، وبعد أكثر من 6 محاولات يُعلَّم الفشل ويُطلق تنبيه.

### 3. بنية إضافات Provider

يتم تسليم الموارد عبر تجريد `ProviderInterface`، وتنفذ البنى التحتية المختلفة نفس الواجهة:

```
ProviderInterface
  ├── ProxmoxProvider    (Proxmox VE ذاتي التشغيل)
  ├── AliyunProvider     (مستقبلاً: Alibaba Cloud)
  ├── AwsProvider        (مستقبلاً: AWS EC2)
  └── DomainProvider     (مستقبلاً: مسجّلو النطاقات)
```

يسجّل `ProviderFactory` دوال المصانع بمفتاح `productType:provider`، ويُحل ديناميكياً أثناء التشغيل وفقاً لـ ProvisionTask.

### 4. توجيه الدفع المتعدد

يُرجع `PaymentRouter` قنوات الدفع المتاحة ديناميكياً حسب مبلغ الطلب / العملة / المنطقة، ويمكن للواجهة الأمامية التبديل بين القنوات لبدء الدفع. تُهيأ قنوات الدفع عبر جدول `PaymentChannel` (الرسوم، الحد الأدنى/الأقصى للمبالغ، المناطق الظاهرة)، دون الحاجة لتغيير الكود لتفعيلها أو إيقافها.

### 5. البنية الأمنية

سلسلة الوسائط العامة: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [المسارات: Encryption → Captcha → Auth → Confirmation]`

![خط وسائط الأمان](docs/diagrams/security-middleware-zh.svg)

- **CORS** — معالجة رؤوس الطلبات عبر النطاقات (وضع القائمة البيضاء، يدعم أحرف البدل *.example.com)
- **SecurityHeaders** — رؤوس استجابة أمنية (HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy)
- **GeoBlock** — الحظر الجغرافي (منع الوصول من دول محددة وفقاً لـ GEO_BLOCKED_COUNTRIES، بناءً على GeoIP2)
- **WAF** — 8 فئات بأكثر من 45 قاعدة (SQL Injection/XSS/Command Injection/File Inclusion/Header Injection/SSRF/NoSQL Injection/Open Redirect) + حد حجم الطلب + التحقق من Content-Type (فحص القيم في query/body/UA، وفحص المسار لعبور المسار فقط)
- **Security Plugin** — اكتشاف 31 نوعاً من الهجمات (XSS/SQL Injection/Command Injection/SSRF/Deserialization/Host Header Attack/Request Smuggling/GraphQL Injection/تسرب البيانات الحساسة وغيرها)، قائمة IP بيضاء + حظر تلقائي عبر القائمة السوداء
- **Locale** — تحليل Accept-Language وتحديد اللغة
- **HashidRequest** — فك ترميز سلاسل hashid في الطلبات تلقائياً إلى معرّفات أعداد صحيحة حقيقية
- **Version** — التحقق من رأس `X-Api-Version`، الافتراضي `v1` عند الغياب، وإرجاع `400` للإصدارات غير المدعومة
- **ClientPlatform** — التحقق من رأس `X-Client-Platform` والتعرف على منصة نظام تشغيل العميل (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)
- **Encryption** — تشفير النقل AES-256-GCM (واجهات المصادقة ولوحة الإدارة)، لمنع التنصت والتلاعب في منتصف المسار
- **Captcha** — كابتشا النقر، التحقق قبل تسجيل الدخول/التسجيل (رسم GD + تخزين Redis، مفتاح لمرة واحدة، صلاحية 300 ثانية، حد 3 محاولات)
- **Auth** — مصادقة JWT HS256، Access Token 15 دقيقة، Refresh Token 30 يوماً، قائمة Redis سوداء
- **Confirmation** — العمليات الحساسة (الدفع/الحذف/الاسترداد/الموافقات وغيرها) تتطلب إعادة إدخال كلمة المرور للتأكيد، 5 محاولات فاشلة تقفل لمدة 15 دقيقة
- **الحد من التردد** — الافتراضي 60 مرة/دقيقة، تسجيل الدخول 5 مرات/دقيقة، التسجيل 3 مرات/دقيقة، الدفع 10 مرات/دقيقة
- **سجلات التدقيق** — جميع العمليات الحساسة تُكتب في قاعدة تدقيق مستقلة

### 6. أمان البيانات

**استراتيجية التشفير متعدد الطبقات:**

| الطبقة | التقنية | الوصف |
|------|------|------|
| طبقة النقل | AES-256-GCM | تشفير جسم طلبات/استجابات API، تشفير GCM المصدَّق يمنع التلاعب |
| طبقة الحقول | AES-256-CBC | تشفير/فك تشفير تلقائي للحقول الحساسة في النماذج، IV عشوائي في CBC لا يكشف أنماط القيم |
| طبقة المفاتيح | Hashids | تشويش المعرّفات الخارجية إلى سلاسل من 12 حرفاً، لإخفاء الحجم الحقيقي للبيانات |

**تشفير الحقول الحساسة:** 14 حقلاً في 7 نماذج تستخدم `Encryptable::class` للتشفير/فك التشفير التلقائي — `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**إدارة المفاتيح:** يستخدم تشفير النقل وتشفير الحقول مفتاحين مستقلين مختلفين (`ENCRYPTION_MASTER_KEY` مقابل `ENCRYPTION_KEY`)، مع دعم قائمة المفاتيح القديمة (`ENCRYPTION_PREVIOUS_KEYS`) لتدوير المفاتيح دون توقف الخدمة.

### 7. توليد المعرّفات الموزعة

يستخدم خوارزمية Twitter Snowflake لتوليد معرّفات فريدة عامة 64 بت: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. تولّد جميع نماذج Eloquent الـ 46 معرّفات Snowflake تلقائياً في حدث `creating`، دون الاعتماد على الزيادة التلقائية في قاعدة البيانات، ما يدعم بطبيعة الحال تقسيم قواعد البيانات والجداول.

### 8. تعدد اللغات (i18n)

**تحليل تلقائي عبر الوسيط العام:**
- يقرأ `LocaleMiddleware` رأس `Accept-Language` ويحدد اللغة الحالية تلقائياً
- دعم الرجوع عن اللغة: اللغات غير المدعومة ← `fallback_locale` (en-US)

**ترجمة النصوص الثابتة:**
- `I18n::trans('auth.login_success')` ← `登录成功` / `Login successful`
- ملفات الترجمة: `i18n/{locale}/messages.php`، 120 عبارة تغطي جميع الوحدات الـ 15
- دعم استبدال المعاملات: `I18n::trans('validation.required', ['field' => '邮箱'])`

**حقول JSON متعددة اللغات:**
- اسم/وصف المنتج يُخزَّن بصيغة `{"zh-CN":"云服务器","en-US":"Cloud Server"}`
- `I18n::translateField($json)` يأخذ القيمة تلقائياً حسب اللغة الحالية
- قوالب الإشعارات تدعم أيضاً تعدد اللغات، وتُرسل حسب لغة المستخدم المفضلة

### 9. البحث النصي الكامل

ترتبط 4 نماذج (المنتجات والمستخدمون والطلبات والتذاكر) بالبحث عبر Trait `Erikwang2013\WebmanScout\Searchable`. المحرك الافتراضي `database` (كتابة no-op، بحث SQL LIKE كحل بديل، بدون تبعية ES)؛ وعند إعداد محرك Elasticsearch تتم مزامنة الفهارس تلقائياً، مع دعم:

- **تقسيم الكلمات متعدد اللغات** — IK Analyzer (ik_max_word / ik_smart)
- **البحث النصي الكامل بالصينية** — أسماء المنتجات والأوصاف وعناوين التذاكر
- **التصفية الدقيقة** — حسب الحالة والتصنيف ونطاق السعر والنطاق الزمني
- **المزامنة الجماعية** — `php webman scout:import "App\Product\Model\Product"`
- **مثال بحث** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. أعلام الدول

دعم رموز أعلام الدول عبر حزمة `erikwang2013/season`:

- `country_season_flag('CN')` ← 🇨🇳، `country_season_flag('JP')` ← 🇯🇵
- التعرف التلقائي على نصف الكرة الشمالي/الجنوبي وإرجاع الموسم المقابل (باللغتين الصينية والإنجليزية)
- دعم أسماء الفصول المترجمة بأكثر من 30 لغة
- يمكن استخدامها مباشرة في اختيار المنطقة بالواجهة الأمامية وعرض جنسية المستخدم وغيرها

## قائمة الأعمال المنجزة

- [x] DDL قاعدة البيانات (`install.sql`، 46 جدولاً، جداول إدارة wa_* + جداول الأعمال بدون بادئة، مفاتيح BigInt بدون زيادة تلقائية)
- [x] توليد معرّفات Snowflake (`erikwang2013/snowflake-php`)
- [x] مصادقة JWT (`erikwang2013/jwt-webman`، HS256 + قائمة Redis سوداء)
- [x] تشويش معرّفات API (`erikwang2013/hashids`، فك ترميز الطلبات تلقائياً + ترميز الاستجابات تلقائياً)
- [x] تشفير النقل (`erikwang2013/encryption`، وسيط AES-256-GCM)
- [x] تشفير على مستوى الحقول (`erikwang2013/encryptable`، تشفير/فك تشفير تلقائي للحقول الحساسة)
- [x] البحث النصي الكامل (`erikwang2013/webman-scout`، محرك database الافتراضي مع SQL LIKE كحل بديل، وElasticsearch + تقسيم IK اختياري)
- [x] أعلام الدول (`erikwang2013/season`، رموز Unicode للعلم)
- [x] لوحة الإدارة (`admin/`، webman-admin + تكامل 7 حزم، 286 اختبار وحدة)
- [x] مراجعة الكود (تم تطبيق إصلاحين أساسيين و4 إصلاحات مهمة)
- [x] تصدير Excel (PhpSpreadsheet ^2.0، Crud/Table بلوحة الإدارة + واجهات إدارة الخادم)
- [x] مرئيات لوحة المعلومات (رسوم ECharts + بطاقات إحصائية متحركة + لوحة معلومات النظام)
- [x] تصدير PDF (html2canvas + jsPDF، تصدير لقطات لوحة المعلومات)
- [x] سكربت ترحيل قاعدة البيانات (`install.sql` DDL موحد، أمر `php webman migrate`)
- [x] تكامل Stripe الفعلي (stripe-php SDK، PaymentIntent + التحقق من توقيع Webhook)
- [x] تكامل رسائل Twilio الفعلية (twilio/sdk، يتضمن معالجة فشل الإرسال)
- [x] تكامل دفع FCM الفعلي (kreait/firebase-php، يتضمن تنظيف الرموز غير الصالحة)
- [x] كابتشا النقر (erikwang2013/poster-php، التحقق للعمليات الحساسة عند تسجيل الدخول/التسجيل)
- [x] التأكيد الثانوي (ConfirmationMiddleware، إعادة إدخال كلمة المرور للعمليات الحساسة، 5 محاولات فاشلة تقفل 15 دقيقة)
- [x] اختبارات وحدة الخادم (672 tests / 1632 assertions، 15 skipped)
- [x] التعرف على منصة العميل (ClientPlatformMiddleware، رأس X-Client-Platform يدعم 8 منصات)
- [x] تعزيز أمان WAF (8 فئات بأكثر من 45 قاعدة: SQL Injection/XSS/Command Injection/File Inclusion/Header Injection/SSRF/NoSQL Injection/Open Redirect + حد حجم الطلب + التحقق من Content-Type)
- [x] Security Plugin (erikwang2013/security-php، اكتشاف 31 نوعاً من الهجمات + حظر IP تلقائي عبر القائمة السوداء + تدوير السجلات)
- [x] وسيط WAF للوحة Admin
- [x] فصل القراءة/الكتابة MySQL (اتصالات Eloquent read/write + sticky)
- [x] طبقة ذاكرة تخزين مؤقت متعددة المستويات Redis (CacheService: المنتجات/المناطق/أسعار الصرف/TLD/المستخدمون، TTL + إبطال استباقي + تسخين)
- [x] ضغط استجابات Nginx + تحسين الاتصالات (gzip/proxy_buffering/keep-alive/limit_req+limit_conn)
- [x] اقتراحات فهارس قاعدة البيانات (13 فهرساً مركباً/مغطياً موصى به)
- [x] مراقبة الاستثناءات Sentry (SentryBootstrap + استدعاء إزالة البيانات الحساسة before_send)
- [x] مفاتيح الميزات Feature Flags (تجاوز ديناميكي Redis + واجهات لوحة الإدارة)
- [x] واجهة الموردين الخارجية (مصادقة API Key + نقاط نهاية الطلبات/الموارد/التسويات/السحب)
- [x] الدفع الفوري WebSocket (WebSocket أصلي في Workerman + مستمعو أحداث الطلبات/التذاكر)
- [x] سكربتات اختبار الحمل k6 (تدخين/منتجات/توافق)
- [x] خط أنابيب CI/CD (GitHub Actions، فحص الصيغة + PHPUnit للطرفين + التحقق من Composer)
- [x] معالج التثبيت بنقرة واحدة (واجهة ويب، فحص البيئة + إعداد قاعدة البيانات + إنشاء المسؤول + توليد .env تلقائياً)

## المشروع مفتوح المصدر — نرحب بدعمكم

| WeChat | Alipay |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

### التحويلات العالمية (حوالة مصرفية)

**معلومات المستفيد**

- اسم المستفيد: WANG KEXUN
- رقم حساب المستفيد: 881015918251

**البنك المستفيد (ZA Bank)**

- SWIFT Code: AABLHKHHXXX
- اسم البنك: ZA Bank Limited
- رقم البنك: 387
- عنوان البنك: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**البنك الوكيل للتحويلات الدولية (عند الحاجة)**

> يُرجى الانتباه إلى أن هذه معلومات البنك الوكيل للتحويلات الدولية (البنك الوسيط)، وليست معلومات البنك المستفيد. يرجى الاستفسار من البنك المحوِّل عما إذا كان مطلوباً تقديم معلومات البنك الوكيل للتحويلات الدولية.

- البنك الوكيل لتحويلات الدولار الهونغ كونغي واليوان الصيني والدولار الأمريكي هو **Citibank**:
  - اسم البنك: Citibank N.A. Hong Kong
  - SWIFT Code: CITIHKHXXXX
  - رقم البنك: 006
  - اسم الفرع: Hong Kong Branch
  - رقم الفرع: 391
  - عنوان البنك: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- البنك الوكيل لتحويلات العملات الأخرى هو **BNY Mellon**:
  - اسم البنك: THE BANK OF NEW YORK MELLON
  - SWIFT Code: IRVTUS3NXXX
  - عنوان البنك: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

---

## License

الإصدار المبسط — MIT License | الإصدار القياسي/الكامل — Proprietary
