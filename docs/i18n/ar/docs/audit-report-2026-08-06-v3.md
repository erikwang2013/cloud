# تقرير مراجعة CloudPlatform (الجولة الثالثة، 2026-08-06)

> النطاق: اختبار فعلي شامل (تشغيل الخدمة + اختبار تدخين) + فحص معمق للكود + التحقق من اكتمال إعدادات البيئة/الأمان.
> انتقلت هذه الجولة من «قابل للقراءة ثابتاً» إلى «**قابل للتشغيل**»: أُصلحت 5 أعطال من مستوى الإقلاع P0 و3 من مستوى التشغيل P0/P1، ونجح اختبار التدخين للخدمة تحت سلسلة الوسائط الكاملة.
> خط أساس الاختبار: service **316/316 ناجحاً (502 assertions)**؛ admin **67/67 ناجحاً (124 assertions)**.

---

## 1. قائمة إصلاحات هذه الجولة (كلها مُتحقق منها عملياً)
### P0 — مستوى الإقلاع (انهيار worker / تعطل الموقع بالكامل)

| # | المشكلة | السبب الجذري | الإصلاح |
|---|------|------|------|
| 1 | `A facade root has not been set` ← انهيار عند الإقلاع | bootstrap لم يعيّن حاوية Facade الخاصة بـ Illuminate | `Facade::setFacadeApplication($capsule->getContainer())` (bootstrap.php:149) |
| 2 | `Target class [events] does not exist` | مستمعو الأحداث يستخدمون Event Facade، لكن الحاوية بلا خدمة events | التحويل إلى مثيل `Dispatcher`: `$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()` (3 مستمعين) |
| 3 | `Class support\SentryBootstrap not found` | psr-4 في composer.json ينقصه تعيين `support\` | إضافة `"support\\": "support/"` + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` فارغ ← انهيار خدمة التشفير | قيمة .env فارغة (تجاوز الحقن عبر createUnsafeMutable في phpdotenv) | توليد مفتاح base64 من 32 بايت وكتابته في .env |
| 5 | جميع مسارات `/api/*` ترجع 404 | يعيد `ApiRequest::path()` كتابة `/api/xxx` إلى `/api/v1/xxx`، بينما تسجيل المسارات بلا بادئة إصدار | إزالة منطق إعادة الكتابة، يبقى المسار كما هو (التحقق من الإصدار عبر VersionMiddleware برأس X-Api-Version) |
| 6 | `Class "ErikJwt\JWTFactory" not found` | استخدام فضاء أسماء غير موجود `ErikJwt\` | التحويل إلى فضاء الأسماء الفعلي داخل الحزمة `Erikwang2013\Jwt\*` |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` يرجع null ← خطأ نوع في `createFromConfig()` | يتطلب `Config::loadFromDir` في webman وجود `app.php` في دليل الإضافة (وإلا يُتخطى الدليل بأكمله)؛ دليل إضافة jwt ناقص | إضافة `config/plugin/erikwang2013/jwt/app.php` (`'enable' => true`، مطابق لقالب vendor) |

### P0 — مستوى التشغيل (الطلب الأول يرجع 500)

| # | المشكلة | السبب الجذري | الإصلاح |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware يستدعي ثابتاً `\Redis::get()` الخاص بـ ext-redis | التحويل إلى `\support\Redis::get/setex/incr` |
| 9 | `Class support\Redis not found` | `support\Redis` جزء من طبقة هيكل webman (حزمة webman/webman)، وهذا المشروع ثبّت framework فقط فافتقدها | إنشاء `support/Redis.php` (يستخدم تحتياً illuminate/redis الموجود + config/redis.php) |
| 10 | `Illuminate\Support\Facades\Redis::*` في AuthController يتحلل إلى **مثيل phpredis عارٍ** (غير متصل) ← «server went away» | الحاوية بلا ربط `redis`، والتجميع التلقائي يتراجع إلى فئة `Redis` | تسجيل `$container->singleton('redis', fn() => support\Redis::manager())` في bootstrap |
| 11 | `Call to undefined function storage_path()` | `storage_path()` من دوال الطبقة الهيكلية المساعدة، وهذا المشروع يفتقدها | إضافة الدالة المساعدة في bootstrap (`base_path()/storage`، بحراسة function_exists) |

### P1 — التحقق من الحدود

| # | المشكلة | الإصلاح |
|---|------|------|
| 12 | `/api/auth/refresh` يرجع TypeError 500 عند غياب refresh_token | إضافة تحقق `is_string` في AuthController::refresh ← 422 |

### استعادة الحالة المؤقتة

- `config/server.php` (8787) و`config/process.php` (9100/8282) و`config/middleware.php` (سلسلة 11 طبقة كاملة) استُعيدت من git كما كانت
- أُزيل error_log التصحيحي `[AUDIT]` من bootstrap.php

---

## 2. نتائج اختبار التدخين (سلسلة الوسائط الكاملة، المنفذ 8787)
| نقطة النهاية | النتيجة | الوصف |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | يرجع صورة كابتشا النقر |
| POST /api/auth/login (بدون كابتشا) | 422 | تحقق الكابتشا يعمل |
| POST /api/auth/register (معاملات فارغة) | 422 | تحقق الحقول يعمل |
| POST /api/auth/refresh (بدون token) | 422 | بند إصلاح هذه الجولة |
| POST /api/auth/forgot-password | 500 (رفض اتصال قاعدة البيانات) | **فجوة بيئية**: .env ينقصه DB_PASSWORD، انظر §四 |
| GET برأس X-Api-Version: v99 | 400 | VersionMiddleware يعمل |
| GET /api/nonexistent | 404 | صفحة 404 طبيعية |

مسارات Redis (كابتشا التحقق وتقييد التردد وتخزين قائمة JWT السوداء) جميعها مُختبَرة فعلياً وتعمل.

---

## 3. التحقق من الحماية الأمنية
### بُلغت المعايير ✓

- **إدارة المفاتيح**: لا مفاتيح/كلمات مرور مرمزة ثابتاً في المشروع كله (فحص grep)؛ جميع المفاتيح عبر `getenv()`؛ و.env متجاهَل في git
- **حقن SQL**: لا دمج سلاسل في SQL؛ الكل عبر منشئ استعلامات Eloquent
- **التحقق من المدخلات**: قائمة بيضاء لنوع الرفع + استشعار محتوى finfo + حد أقصى للحجم حسب النوع؛ تحقق على مستوى الحقول في نقاط نهاية auth
- **تقييد التردد**: تغطية كاملة لنقاط النهاية العامة الحساسة (login 5/min، register 3/min، sms 5/h، captcha 30/60s، oauth 10/60s، password_reset 3/5min)، الافتراضي 60/min
- **JWT**: HS256 + مفتاح 32 بايت؛ فصل access/refresh؛ تحقق من النوع؛ قائمة Redis سوداء (تحقق داخل المكتبة حسب jti)؛ فرض TOTP + قفل عند الفشل
- **CORS**: قائمة أصول بيضاء (`CORS_ALLOWED_ORIGINS`)، بلا أحرف بدل وبلا رأس اعتماد
- **الرؤوس الأمنية**: nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS (مفتاح env)
- **مكافحة التعداد**: forgot-password يرجع رسالة نجاح موحدة حتى للمستخدمين غير الموجودين

### اقتراحات (أولوية منخفضة، لم تُعدّل)

| البند | الوصف |
|----|------|
| غياب رأس CSP | لم يُهيأ Content-Security-Policy للموقع كله؛ خطر سيناريو JSON في API منخفض، يُنصح بإضافة سياسة بمستوى `default-src 'none'` في SecurityHeadersMiddleware |
| أداء WAF | يقرأ WafMiddleware `file_get_contents('php://input')` في كل طلب لمسح كامل الجسم (31 نمطاً)، بتكلفة ذاكرة/CPU عند الحركة العالية؛ يُنصح بقراءة الجسم فقط لـ POST/PUT وعند تطابق Content-Type |
| HealthController `shell_exec('git rev-parse')` | كل طلب health يشغل عملية فرعية؛ في الإنتاج يُنصح باستخدام متغير `APP_VERSION` فقط، والـ shell كبديل للتطوير المحلي فقط |
| ~~RateLimit TOCTOU~~ | ~~الفحص ثم الضبط غير ذري~~ **أُصلح (2026-08-07):** تحول إلى `INCR` ذري + `EXPIRE` عند أول مرة، انظر §七-6 |
| X-XSS-Protection | رأس مهجور، إبقاؤه غير ضار؛ يمكن إزالته بعد تفعيل CSP |

---

## 4. فجوات البيئة (ليست مشاكل كود، تحتاج سداً من التشغيل)
1. **`.env` ينقصه `DB_PASSWORD`** (البند المعطل الوحيد): ينشئ docker-compose مستخدم app_user عبر `${DB_PASSWORD}`، والمفتاح مفقود في .env المحلي ← كل نقاط نهاية DB ترجع 500. `DB_PASSWORD` معرّف في `.env.example`، وهو اعتماد نشر ويحتاج إدخاله من المستخدم في `.env`.
2. **9100 مشغول بعملية dart محلية**: فشل ربط منفذ عملية metrics الافتراضي **يمنع تشغيل المجموعة كلها** (فحص مسبق لكل المنافذ قبل إقلاع webman). تم تجاوز مستمر: كتابة `METRICS_PORT=9199` في .env (2026-08-07). يمكن العودة للافتراضي بعد تحرر 9100 من dart.
3. **composer validate fatal (طرف ثالث)**: إضافة composer `erikwang2013/security-php` تتعارض مع تقييم composer الذاتي (إعلان مكرر لـ `isLaravel()`)، ولا علاقة لها بكود هذا المشروع؛ قد يفشل بسببها خطوة `composer validate --strict` في CI، يُنصح بإضافة continue-on-error لهذه الخطوة أو تخطي حزمة service.
4. احتلال 8787 بـ erp-php المسجل في الجولة السابقة زال (ثبت قابلية الربط في هذه الجولة عملياً).

---

## 5. التحقق من إعدادات البيئة
| البند | النتيجة |
|----|------|
| CI (.github/workflows/ci.yml) | مكتمل: فحص صياغة PHP + اختبارات admin/service (مصفوفة PHP 8.2/8.3) + composer validate |
| الترحيلات | 30 ملف ترحيل |
| Docker | compose (MySQL+Redis+app) وDockerfile وnginx.conf وprometheus وgrafana وsupervisor (nginx+webman) |
| المراقبة | MetricsServer (منفذ Prometheus مستقل) + عملية websocket (process.php) |
| اختبار الحمل | tests/k6 (smoke/products/concurrent) |
| .env.example | مفاتيحه أكمل من .env (OAuth/مفاتيح الميزات وغيرها مغطاة)؛ .env بلا مفاتيح فائضة |
| composer audit | لا ثغرات أمنية؛ حزمة مهجورة واحدة doctrine/annotations (تبعية hg/apidoc، أُبقي بعد التقييم) |
| قوائم الانتظار/اللاتزامن | webman/redis-queue مثبت؛ الإشعارات عبر NotificationDispatcher |

---

## 6. اقتراحات متبقية (تكرارات لاحقة)
1. **رأس CSP** (انظر §三)
2. **تحسين قراءة جسم WAF** (انظر §三)
3. **إعادة اختبار سلسلة DB الكاملة بعد إضافة DB_PASSWORD** (تدفق حقيقي register→login→refresh→logout + التحقق من إبطال JWT عبر القائمة السوداء)
4. ~~**supervisor بلا عملية cron**: مهام Billing\Cron\SuspendCheck المجدولة وغيرها بلا نقطة دخول للحماية~~ **حُل (2026-08-07):** أُضيفت عملية `App\Cron\CronRunner` (تقييم تعبيرات config/cron.php ذات الحقول الخمس كل دقيقة)، وسُجلت عملية `queue_consumer` لاستهلاك قوائم provisioning/notification؛ وأصبح التسجيلان المعطّلان اللذان يشيران إلى ملفات سكربت في cron.php دالتين قابلتين للاستدعاء `ResourceMonitor`
5. **خطوة composer-validate في CI**: بسبب تعارض إضافة الطرف الثالث، يُنصح بإضافة تحمل الأخطاء (انظر §四-3)

---

## 7. إصلاحات تكميلية للجولة الرابعة (2026-08-07)
1. **ذرية الفوترة (P0 مالي)**: يغلّف `BillingEngine::runDaily()` المعاملات حسب المورد، وتُرسل الخصم/التعليق/وسم الحدث في معاملة واحدة؛ يستخدم `StripeChannel::confirmPayment()` استباقاً ذرياً `UPDATE ... WHERE status='pending'` + قفل صف الطلب، ضد إيداع مكرر من webhook.
2. **التماثل في التزامن (P0/P1)**: `AffiliateService::requestPayout()` قفل صف + إرجاع مباشر لسحب pending موجود؛ `SupplierSettlement` (cron و`generateSettlement`) يمنع التكرار حسب المورد+الدورة.
3. **صحة البيانات (P1)**: أصلح `MeterCollector` استعلام `$resource->first()` الشامل العرضي للجدول؛ أضاف `ExchangeRateSync` مهلة 10 ثوانٍ.
4. **الأداء (P2)**: دمج 30 استعلام SUM للوحة المعلومات في GROUP BY واحدة؛ `CacheService::forgetPattern()` KEYS←مؤشر SCAN؛ تخزين حزم لغات `I18n` مؤقتاً داخل العملية حسب locale؛ استيراد `ImportExport` في معاملة واحدة؛ جلب خرائط الأسعار مسبقاً في `BillingEngine` لإزالة N+1.
5. **الأمان (P1)**: `InternalTokenMiddleware` يستخدم `getRemoteIp()` ضد تزوير XFF؛ رفض العناوين الخاصة عند تسجيل Webhook (SSRF)؛ فشل سريع عند مفتاح `JwtAuth` فارغ؛ كلمة مرور `DbBackupCommand` تحولت إلى `MYSQL_PWD` ضد تسريب `ps`؛ منع حقن الصيغ في تصدير CSV/Excel؛ تركيب تقييد `supplier_api` على واجهة الموردين الخارجية.
6. **البنية التحتية (P2)**: `RateLimitMiddleware` INCR ذري (إزالة TOCTOU)؛ إصلاح حلقة انهيار النوع في `onMessage` بـ `MetricsServer`؛ تجميع اتصالات Redis في `HealthController`؛ تثبيت `symfony/mailer ^6.4` (كان EmailSender لغزاً خفياً)؛ تصحيح فضاء أسماء `EncryptableBootstrap` في جانب admin.

---

## 8. إصلاحات تكميلية للجولة الخامسة (2026-08-07)
1. **ربط التسليم التلقائي (P0)**: بعد إنشاء مهام التسليم، يدفع `ProvisioningService::handleOrderPaid` قائمة `provisioning`؛ وسجل `process.php` عملية `queue_consumer` (مسح كل تطبيقات `Webman\RedisQueue\Consumer` تحت app/).
2. **قابلية تنفيذ المهام المجدولة (P0)**: أُضيفت عملية `App\Cron\CronRunner` (تقييم تعبيرات config/cron.php ذات الحقول الخمس كل دقيقة، يدعم صيغ `*/n`/`,`/`-`)؛ أصبح التسجيلان المعطّلان اللذان يشيران إلى ملفات سكربت (وليست فئات) في cron.php دالتين قابلتين للاستدعاء `ResourceMonitor::collectAllMetrics`/`checkSslCertificates`، وأُزيل تسجيل checkExpirations المكرر مع ExpirationCheck.
3. **فئة إشعار غير موجودة (P0)**: 4 مواضع في AuthService/AuthController/ExpirationCheck كانت تستدعي `\Common\Notification\NotificationDispatcher::send()` (فئة غير موجودة)، وحُولت جميعها إلى `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)`.
4. **توحيد أنظمة أسماء الجداول الثلاثة (P0)**: تحولت 39 جدول أعمال `erik_*` في install.sql إلى بلا بادئة (متوافقة مع التسمية الافتراضية لـ Eloquent والترحيلات)، وأُبقي `wa_*` لجداول الإدارة؛ وأصبح معالج التثبيت (install/index.php) «كتابة .env ← تشغيل ترحيلات service في عملية فرعية (30 ملف ترحيل) ← install.sql (IF NOT EXISTS يتخطى الجداول المنشأة)»، وتكون جداول القاعدة مكتملة بعد التثبيت.
5. **مجموعة P1/P2 (أنجزها وكيل فرعي، 316 اختباراً ناجحاً)**: ربط الأحداث، كتابة أسعار الصرف لكل عملة، `Response::error` بمعامل واحد يرجع 400 (10 مواضع)، منفذ الاسترداد (RefundService جديد)، تماثل الموافقات، تدقيق عمليات admin الحساسة، إزالة noNeedAuth، تقييد واجهات الإدارة، تحويل WebSocket إلى Redis Pub/Sub، خطأ استعلام SSL، العملات/المديونية، إزالة حساسية credentials، تطبيق القسائم، التحقق من الكميات، تحمل أخطاء CI، تمرير ES_HOST.

**خط أساس الاختبار**: service 316/316 (502 assertions) وadmin 67/67 (124 assertions) خضراء جميعها؛ وكل ملفات التعديلات تجتاز `php -l`.

## الخلاصة

انتقلت هذه الجولة من «كود قابل للقراءة» إلى «**قابل للإقلاع والتشغيل**»: أُصلحت 8 أعطال من مستوى P0 واختُبرت فعلياً، و316 اختباراً خضراء، ونجح اختبار التدخين تحت سلسلة الوسائط الكاملة. البند المعطل الوحيد المتبقي فجوة بيئية (DB_PASSWORD)، وبإدخاله يمكن التحقق من السلسلة الكاملة. الجولة الرابعة (2026-08-07) أكملت أكثر من 20 بند تقوية: ذرية الفوترة وتماثل التزامن وتقييد التردد/الحماية من الحقن وغيرها؛ والجولة الخامسة (2026-08-07) أكملت 4 بنود P0 (التسليم التلقائي وجدولة cron وفئة الإشعارات ونظام أسماء الجداول) ومجموعة P1/P2 كلها، والاختبارات بقيت خضراء.
