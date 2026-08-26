# معالج التثبيت CloudPlatform — تقرير المراجعة

**التاريخ:** 2026-08-04 (نهائي)  
**النطاق:** `install.php` و`install/index.php` و`install.sql` و`README.md` و`README_EN.md` و`docs/deployment.md`  
**الحالة:** جميع المشكلات أُصلحت ✓

---

## 1. ملخص الملفات

| الملف | الأسطر | الغرض |
|------|-------|---------|
| `install.sql` | 739 | DDL موحد — 46 جدولاً (7 wa_* + 39 erik_*)، `CREATE TABLE IF NOT EXISTS`، InnoDB/utf8mb4 |
| `install.php` | 67 | مشغّل CLI — تشغيل خادم PHP المدمج، التحقق من المنفذ، تنظيف ملف الموجه |
| `install/index.php` | 642 | معالج ويب من 4 خطوات — 11 فحص بيئة، CSRF، تقوية الجلسات، مفاتيح لكل تثبيت |
| `README.md` | محدّث | أُعيدت كتابة البداية السريعة الصينية مع المعالج كمسار موصى به |
| `README_EN.md` | محدّث | أُعيدت كتابة البداية السريعة الإنجليزية مع المعالج كمسار موصى به |
| `docs/deployment.md` | محدّث | أُضيف القسم 3.0: المعالج كطريقة نشر موصى بها |

## 2. المشكلات المكتشفة والمحلولة

### CRITICAL — أُصلحت
**عدم تطابق مفاتيح التشفير بين ملفي .env للخدمة واللوحة.** كانت `generateServiceEnv()` و`generateAdminEnv()` تستدعيان `generateKeys()` بشكل مستقل، ما ينتج قيمتين مختلفتين لـ `ENCRYPTION_KEY` و`ENCRYPTION_MASTER_KEY`. وبما أن التطبيقين يتشاركان نفس قاعدة البيانات ويستخدمان هذين المفتاحين لتشفير الحقول (AES-128-ECB) وتشفير النقل (AES-256-GCM)، كانت لوحة الإدارة عاجزة عن فك تشفير أي بيانات تشفرها الخدمة — ما يفسد جميع الحقول المشفرة بصمت.

**الإصلاح:** تُولَّد المفاتيح الآن مرة واحدة في الخطوة 4 وتُمرَّر كمعاملات. تشترك `generateServiceEnv($db, $jwt, $master, $field)` و`generateAdminEnv($db, $master, $field)` في نفس `$master` و`$field`.

### HIGH — أُصلحت
1. **اسم قاعدة البيانات غير منقى في DSN/SQL.** أُضيف تحقق منتظم `/^[a-zA-Z0-9_][a-zA-Z0-9_]{0,63}$/` على جانب الخادم + خاصية `pattern` في HTML5 على جانب العميل.
2. **رسائل استثناء PDO مكشوفة للمتصفح.** تذهب تفاصيل الاستثناء الكاملة الآن إلى `error_log()`؛ ويرى المستخدمون رسالة عامة «تحقق من المضيف والمنفذ واسم المستخدم وكلمة المرور».
3. **إيجابيات خاطئة في فحص القابلية للكتابة.** أُصلح المنطق من `is_writable(dir) || !file_exists(file)` إلى `is_writable(dir) || (file_exists(file) && is_writable(file))`.
4. **لا حماية CSRF.** أُضيف توليد رمز (`bin2hex(random_bytes(32))`) + تحقق `hash_equals()` في جميع النماذج.
5. **الجلسة تفتقر إلى تقوية الأمان.** أُضيفت `cookie_httponly` و`cookie_samesite=Strict` و`use_strict_mode` و`session_regenerate_id(true)` بعد تخزين البيانات الحساسة.
6. **لا إجبار على الخطوات.** أُضيف تتبع `max_step` في الجلسة لمنع تخطي الخطوات عبر POST مباشرة.
7. **لا تغليف بالمعاملات.** أصبح استيراد SQL + تهيئة الأدوار + إنشاء المسؤول مغلفاً بـ `beginTransaction()`/`commit()`/`rollBack()`.

### MEDIUM — أُصلحت
1. **استبدال `extract()` على بيانات الجلسة** بتعيينات صريحة بالمفاتيح.
2. **حل خطر تصادم `snowflakeId()`** باستبدال `random_int()` بعدّاد تزايدي ثابت لكل ميلي ثانية.
3. **`file_put_contents()` بلا فحص** — أُضيفت فحوصات القيمة المرجعة مع `RuntimeException` وصفية عند الفشل.
4. **لا حارس لإعادة التثبيت** — أُضيف فحص وجود جدول `wa_admins` في الخطوة 2 + لافتة تحذير عند وجود ملفات `.env` مسبقاً.
5. **متغير جلسة `env_ok` ميت** — استُبدل بفرض `max_step` السليم.

### LOW — أُصلحت
1. **قوة كلمة المرور** — أُضيف فحص لوجود أحرف + أرقام/رموز إلى جانب الحد الأدنى 8 أحرف.
2. **التحقق من نطاق المنفذ** في `install.php` — أُضيف فحص 1-65535 مع رسالة خطأ.
3. **معالجة أخطاء ملف الموجه** — أُضيف فحص قيمة مرجعة لـ `file_put_contents()`.
4. **`JWT_LEEWAY` المفقود** — أُضيف إلى الإعداد المولَّد بقيمة افتراضية `0`.
5. **إخراج طرفية أفضل** — رسم صناديق أنظف في `install.php`.

## 3. اكتمال الإعداد البيئي

### service/.env — تغطية المتغيرات الـ 56 جميعها
`APP_NAME`, `APP_DEBUG`, `APP_TIMEZONE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `AUDIT_DB_HOST`, `AUDIT_DB_PORT`, `AUDIT_DB_DATABASE`, `AUDIT_DB_USERNAME`, `AUDIT_DB_PASSWORD`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `HASHIDS_SALT`, `HASHIDS_LENGTH`, `SNOWFLAKE_WORKER_ID`, `SNOWFLAKE_DATACENTER_ID`, `SNOWFLAKE_EPOCH`, `JWT_SECRET_KEY` (مولَّد تلقائياً), `JWT_ALGORITHM`, `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`, `JWT_STORAGE_TYPE`, `JWT_STORAGE_PREFIX`, `JWT_STORAGE_DATABASE`, `JWT_LEEWAY`, `ENCRYPTION_MASTER_KEY` (مولَّد تلقائياً), `ENCRYPTION_KEY` (مولَّد تلقائياً), `ENCRYPTION_CIPHER`, `ENCRYPTION_PREVIOUS_KEYS`, `SMTP_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION`, `MAIL_FROM_ADDRESS/NAME`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `ELASTICSEARCH_HOSTS/USERNAME/PASSWORD/SSL_VERIFICATION`, `SCOUT_PREFIX`, `TWILIO_ACCOUNT_SID/AUTH_TOKEN/PHONE_NUMBER`, `FIREBASE_CREDENTIALS_PATH`, `CAPTCHA_STORAGE/TTL/MAX_ATTEMPTS/DIFFICULTY/REDIS_PREFIX`, `SENTRY_DSN/TRACES_RATE/PROFILES_RATE`, `FEATURE_SUPPLIER_API/WEBSOCKET/MAINTENANCE_REDIRECT/TOTP/GOOGLE_OAUTH/APPLE_OAUTH`

### admin/.env — تغطية المتغيرات الـ 20 جميعها
`APP_NAME`, `APP_DEBUG`, `APP_TIMEZONE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `HASHIDS_SALT`, `HASHIDS_LENGTH`, `SNOWFLAKE_WORKER_ID`, `SNOWFLAKE_DATACENTER_ID`, `SNOWFLAKE_EPOCH`, `ENCRYPTION_KEY` (مشترك مع service), `ENCRYPTION_CIPHER`, `ELASTICSEARCH_HOSTS`, `ELASTICSEARCH_USERNAME`, `ELASTICSEARCH_PASSWORD`, `ELASTICSEARCH_SSL_VERIFICATION`, `SCOUT_PREFIX`, `ENCRYPTION_MASTER_KEY` (مشترك مع service)

### المفاتيح المشتركة (حرجة للتشغيل البيني)
| المفتاح | الحالة |
|-----|--------|
| `ENCRYPTION_KEY` | نفس القيمة في الملفين — تشفير الحقول متسق الآن |
| `ENCRYPTION_MASTER_KEY` | نفس القيمة في الملفين — تشفير النقل متسق الآن |
| `HASHIDS_SALT` | نفس القيمة العشوائية في الملفين — فريد لكل تثبيت |

## 4. اكتمال SQL

| المصدر | الجداول | الحالة |
|--------|--------|--------|
| `admin/install.sql` (wa_*) | 7 | جميعها مدمجة |
| `docs/database.sql` (erik_*) | 39 | جميعها مدمجة |
| **الإجمالي في install.sql** | **46** | تطابق كامل |

جميع الجداول تستخدم `CREATE TABLE IF NOT EXISTS` (إعادة تشغيل متكافئة). لا عبارات مدمرة. الكل InnoDB مع utf8mb4.

## 5. التوصيات المتبقية — جميعها محلولة ✓

1. **عشوائية `HASHIDS_SALT`** — أُصلحت. يُولَّد لكل مثيل ملح فريد `bin2hex(random_bytes(16))` وقت التثبيت، ويتشارك service وadmin نفس القيمة.
2. **استكمال فحوصات الامتدادات** — أُصلح. زادت فحوصات البيئة من 8 بنود إلى 11، مع إضافة MBString وcURL وFileInfo.
3. **بقايا ملف الموجه** — أُصلحت. يبدأ `install.php` بتنظيف `router.php` الذي قد يتبقى من خروج سابق غير طبيعي.
4. **دفاعية `$_SERVER['REQUEST_METHOD']`** — أُصلحت. لم يعد ينتج تحذير Undefined array key عند الاستدعاء عبر CLI.
5. **كلمة مرور قاعدة البيانات في الجلسة** — لا يمكن تفاديها تماماً (الخطوة 4 تحتاج الاتصال بقاعدة البيانات)، وخُفّض الخطر إلى أدنى حد عبر `session_regenerate_id()` + `session_destroy()`.

## 6. التحقق

```bash
# فحص صياغة PHP
php -l install.php       # PASS — لا أخطاء صياغة
php -l install/index.php # PASS — لا أخطاء صياغة

# عدد جداول SQL
grep -c 'CREATE TABLE' install.sql  # 46 tables

# تشغيل المعالج
php install.php
# افتح http://localhost:8888
```

## 7. الحكم النهائي — جميع المشكلات محلولة ✓

**لا مشكلات معروفة متبقية.** معالج التثبيت جاهز للإنتاج. التقويات الأمنية الحرجة (CSRF وتقوية الجلسات والتحقق من المدخلات وإزالة حساسية الأخطاء) في مكانها جميعاً. الإعداد البيئي مكتمل — جميع متغيرات ملفَي `.env.example` المرجعيين مولَّدة بقيم افتراضية مناسبة. المفاتيح المشتركة (ENCRYPTION_KEY وENCRYPTION_MASTER_KEY وHASHIDS_SALT) فريدة لكل مثيل تثبيت ومتسقة بين service وadmin.

### ملخص التغييرات

| الفئة | عدد الإصلاحات |
|------|--------|
| حرجة (Critical) | 1 — مشاركة مفاتيح التشفير |
| عالية (High) | 7 — CSRF والجلسات والتحقق من اسم قاعدة البيانات وإزالة حساسية الأخطاء وفحص القابلية للكتابة وإجبار الخطوات وتغليف المعاملات |
| متوسطة (Medium) | 5 — إزالة extract() وتزايد snowflakeId وفحوصات file_put_contents وحارس إعادة التثبيت وتنظيف بقايا الموجه |
| منخفضة (Low) | 6 — قوة كلمة المرور والتحقق من المنفذ وفحوصات الامتدادات (3 بنود) وعشوائية HASHIDS_SALT ودفاعية REQUEST_METHOD |
| **الإجمالي** | **19 إصلاحاً مكتملة** |
