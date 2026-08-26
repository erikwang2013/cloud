# تقرير مراجعة CloudPlatform (الجولة الثانية، 2026-08-06)

> النطاق: إعادة فحص بعد إصلاح جميع مشكلات الجولة السابقة (audit-report-2026-08-06.md).
> خط أساس الاختبار: PHPUnit **319/319 ناجحاً (505 assertions)**؛ `php -l` على 253 ملف PHP **0 خطأ صياغة**.

---

## 1. الاختبارات والفحوصات الثابتة
| البند | النتيجة |
|------|------|
| PHPUnit الكامل | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | 253 ملفاً ناجحة جميعها |
| composer audit | **لا ثغرات أمنية**؛ حزمة مهجورة واحدة doctrine/annotations (تبعية مباشرة لـ hg/apidoc، أُبقي بعد التقييم) |
| composer.lock | أُدرج في التحكم بالإصدارات (مُحضر A) |

---

## 2. التحقق من إعدادات البيئة
### 2.1 استخدام env وتعريفه —— مكتمل ✓

- جميع مفاتيح `getenv()` في الكود (بما فيها النمط الديناميكي `{PROVIDER}_OAUTH_*`) معرّفة في `.env.example` أو موجودة كإعدادات اختيارية بصيغة تعليق (`#HASHIDS_ALPHABET` و`#POSTER_IMAGE_DRIVER` و`#EXCHANGE_RATE_API_URL` و`#COUNTRY_SEASON_DEFAULT` و`#SECURITY_HSTS_VALUE`)
- عناصر زائدة في القالب (منخفضة الخطورة): `MAIL_FROM_NAME` لا يشير إليه `getenv()` في الكود، أُبقي في القالب فقط

### 2.2 تثبيت التبعيات ✓

- `service/composer.lock` مُقدَّم؛ لم يعد مستبعداً في `.gitignore`؛ و`service/.phpunit.cache/` متجاهَل

### 2.3 ملاحظات البيئة

- المنفذ المحلي 8787 ما يزال مشغولاً بـ erp-php، لا يمكن تشغيل cloud-php محلياً (لا تعارض في بيئة النشر)
- `composer validate` يُرجع fatal بسبب تعارض Installer في إضافة vendor `erikwang2013/security-php` مع تقييم composer الذاتي (مشكلة حزمة طرف ثالث، ليست كود هذا المشروع)

---

## 3. التحقق من الحماية الأمنية
### 3.1 سلسلة الوسائط العامة (11 طبقة، تغطي جميع المسارات) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF (SQLi/XSS) → SecurityPlugin (31 نوعاً من اكتشاف الهجمات)
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 تقييد تردد المسارات العامة —— أُصلح موضع واحد في هذه الجولة

| المسار | الوسيط | قاعدة التقييد |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min، login 5/min |
| **forgot-password / reset-password** | **RateLimit (رُكّب في هذه الجولة)** | password_reset 3/5min |
| oauthRedirect / oauthCallback (GET+POST) | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **الإصلاح**: حددت الجولة السابقة قاعدة `password_reset` لمسارَي `forgot-password`/`reset-password` لكنها أغفلت تركيب الوسيط (سطح القصف البريدي/التخمين الشامل لرموز التحقق)، ورُكّب في هذه الجولة.

### 3.3 كشف ملفات الرفع —— أُصلح موضع واحد في هذه الجولة (عالي الخطورة)

**المشكلة**: إعداد nginx في `deployment.md` `location /storage/ { alias .../service/storage/; }` يكشف دليل storage بأكمله للعموم:

```
storage/
├── backups/    ← نسخ قاعدة البيانات الاحتياطية (.sql.gz) قابلة للتنزيل علناً
├── apple/      ← مفتاح AuthKey.p8 الخاص قابل للتنزيل علناً (يمكنه توقيع رموز Apple)
├── firebase/   ← اعتمادات حساب خدمة FCM (بما فيها المفتاح الخاص) قابلة للتنزيل علناً
├── geoip/      ← قاعدة بيانات GeoLite2
└── uploads/    ← الملفات المرفوعة (من المتوقع أن تكون علنية)
```

**الإصلاح**: عُدّل كل من deployment.md و docker/nginx.conf إلى `location ^~ /storage/uploads/`، ليكشف دليل uploads الفرعي فقط.

### 3.4 فحوصات أخرى ✓

- `verify-email`: رمز عشوائي لمرة واحدة (يُفرَّغ بعد التحقق)، لا سطح تخمين شامل/تعداد، لا حاجة لتقييد التردد
- واجهة الرفع: قائمة بيضاء للأنواع + استشعار محتوى MIME عبر finfo (أُصلحت الجولة السابقة)؛ uploads تُقدَّم عبر alias ثابت في nginx دون تنفيذ PHP
- JWT: HS256 + قائمة Redis سوداء (يُتحقق منها داخل المكتبة حسب jti)؛ فرض TOTP عند تسجيل الدخول + 5 محاولات فاشلة تقفل 15 دقيقة
- OAuth: التحقق من التوقيع عبر JWKS + iss/aud/exp/nonce + فرض email_verified (أُصلحت الجولة السابقة)
- مسارات الإدارة: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 4. اقتراحات متبقية (غير معطلة)
| المستوى | البند | الوصف |
|:---:|------|------|
| P3 | `service/service/` دليل قديم زائد (28K) | يتضمن نسخاً قديمة من Supplier/WebSocket، غير محملة عبر PSR-4 وغير متتبعة، سهلة التعديل الخاطئ؛ يُنصح بحذفها بعد تأكيد يدوي |
| P3 | `MAIL_FROM_NAME` زائد في القالب | الكود لا يستخدمه، يمكن إبقاؤه كإعداد محجوز لاسم مرسل البريد |
| P3 | doctrine/annotations مهجورة | تبعية مباشرة لـ hg/apidoc، إزالتها تتطلب استبدالاً متزامناً لخطة توليد توثيق API |
| P3 | تقوية دليل الرفع (توصية ثانية) | وضع `index.html` داخل دليل uploads والتأكد من عدم تنفيذ PHP على مستوى النشر (alias في nginx يتفادى ذلك بطبيعته، لكن سيناريو الخدمة المدمجة في webman يحتاج انتباهاً) |

---

## 5. الخلاصة
أُعيد فحص إصلاحات الجولة السابقة الخمسة عشر جميعها وتأكدت فعاليتها، وخط أساس الاختبار مستقر (319/505). اكتشفت هذه الجولة 3 مواضع وأصلحتها فوراً: **تقييد تردد مساري forgot/reset المنسي (P1)** و**كشف إعداد nginx في deployment.md للنسخ الاحتياطية والمفاتيح الخاصة (P0)** و**غياب الإعداد الثابت لـ uploads في nginx الخاص بـ docker (P2)**. أُعيد تشغيل الاختبارات الكاملة بعد الإصلاح ونجحت.

*طريقة توليد التقرير: PHPUnit الكامل، php -l على 253 ملفاً، تدقيق ثابت للمسارات/الوسائط، تدقيق إعدادات nginx/docker، مقارنة فرق المجموعات بين استخدام env وتعريفه، composer audit.*
