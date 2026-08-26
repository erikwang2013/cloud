# تقرير تدقيق الأمان — cloud-php

**التاريخ**: 2026-08-04
**النطاق**: المشروع بأكمله (service + admin)
**المنهجية**: مراجعة الإعدادات، تدقيق الوسائط، فحص الكود

---

## التقييم العام: **B+ (جيد، 4 فجوات للإصلاح)**

يمتلك المشروع بنية أمنية متعددة الطبقات متينة. إضافة erikwang2013/security-php المزودة بـ 31 كاشفاً هي أبرز ميزاته. فيما يلي التحليل التفصيلي.

---

## 1. الدفاعات القائمة (تم التحقق منها)

### النقل والتشفير
| الآلية | التنفيذ | الحالة |
|-----------|---------------|--------|
| تشفير نقل API | AES-256-GCM عبر erikwang2013/encryption | OK |
| تشفير حقول قاعدة البيانات | AES-128-ECB عبر erikwang2013/encryptable (حتمي، قابل للاستعلام) | OK |
| تدوير المفاتيح | ENCRYPTION_PREVIOUS_KEYS مفاتيح قديمة مفصولة بفواصل | OK |
| تشويش المعرّفات | Hashids بملح قابل للإعداد وطول أدنى 12 | OK |
| تجزئة كلمات المرور | bcrypt cost=12، طول أدنى 8 | OK |

### المصادقة والتحكم في الوصول
| الآلية | التنفيذ | الحالة |
|-----------|---------------|--------|
| مصادقة JWT | erikwang2013/jwt-webman، HS256، TTL للوصول 900s + تحديث 30 يوماً | OK |
| قائمة JWT السوداء | إبطال الرموز المدعومة بـ Redis | OK |
| MFA/TOTP | 6 خانات، دورة 30 ثانية، متوافق مع Google/MS Authenticator | OK |
| RBAC | وسيط AccessControl في admin + plugin\admin\api\Auth::canAccess() | OK |
| تخزين الجلسات | Redis (db2) | OK |
| كابتشا التحقق | erikwang2013/poster-php كابتشا نصية بالنقر لتسجيل الدخول/التسجيل | OK |

### اكتشاف الهجمات (WAF — طبقة مزدوجة)
| الطبقة | التغطية | الحالة |
|-------|----------|--------|
| WafMiddleware المخصص | SQLi وXSS وCMDi وعبور المسار وحقن الرؤوس وSSRF وNoSQLi وإعادة التوجيه المفتوح | OK |
| إضافة الأمان (31 كاشفاً) | كل ما سبق + XXE وإزالة التسلسل وLDAP ورؤوس البريد وSSTI وهجوم JWT ورأس Host وتهريب الطلبات وGraphQL وXPATH وJNDI/Log4Shell وSSI وحقن CSV وتسرب البيانات وتلوث النموذج الأولي وWebSocket وتجاوز CORS وإعادة ربط DNS | OK |

### تقييد التردد (service فقط)
| المسار | المعدل | الانفجار | لكل | الحالة |
|-------|------|-------|-----|--------|
| الافتراضي | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### حمايات أخرى
| الآلية | التنفيذ | الحالة |
|-----------|---------------|--------|
| حدود حجم الطلب | جسم 10MB، URL 2KB | OK |
| التحقق من Content-Type | قائمة بيضاء: JSON وmultipart وform-urlencoded | OK |
| عبارات قاعدة البيانات المعدة مسبقاً | PDO::ATTR_EMULATE_PREPARES = false | OK |
| فصل قراءة/كتابة قاعدة البيانات | الكتابة إلى الأساسي، القراءة من النسخة، جلسات لاصقة | OK |
| تسجيل التدقيق | قاعدة تدقيق مستقلة، LogSanitizer يزيل حساسية الحقول | OK |
| وضع الصيانة | قائمة IP بيضاء تتجاوزه، والبقية تحصل 503 + Retry-After | OK |
| الحظر التلقائي للـ IP | 5 مخالفات في 60 ثانية ثم حظر 15 دقيقة | OK |
| الوضع الصارم SQL | يمنع اقتطاع البيانات وتحويل الأنواع الضمني | OK |

---

## 2. الفجوات والتوصيات

### الفجوة 1 (متوسطة): CORS يعكس أي أصل
**الملف**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

يعيد هذا أي Origin يرسله العميل كما هو، ما يسمح فعلياً لأي موقع ويب بإجراء طلبات مصادقة عبر النطاقات. قد يلتقط كاشف cors في إضافة الأمان بعض حقن الرؤوس، لكن الوسيط نفسه لا يوفر قائمة أصول بيضاء.

**الإصلاح**: إضافة فحص القائمة البيضاء. إذا لم يكن الأصل ضمن القائمة المسموحة، استجب بـ `Access-Control-Allow-Origin: null` أو احذف الرأس بالكامل.

### الفجوة 2 (متوسطة): غياب رؤوس الاستجابة الأمنية
لا يضبط service ولا admin رؤوس أمان HTTP الحرجة:

| الرأس | الموصى به | الحالي |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | مفقود |
| X-Content-Type-Options | nosniff | مفقود |
| X-Frame-Options | DENY أو SAMEORIGIN | مفقود |
| Content-Security-Policy | سياسة مع nonce/hash | مفقود |
| X-XSS-Protection | 1; mode=block | مفقود |
| Referrer-Policy | strict-origin-when-cross-origin | مفقود |
| Permissions-Policy | تقييد الكاميرا/الميكروفون/الموقع الجغرافي | مفقود |

**التوصية**: إضافة SecurityHeadersMiddleware إلى مكدسي الوسائط في service وadmin. إصلاح عالي الأثر بجهد منخفض.

### الفجوة 3 (منخفضة): admin/config/security.php يفتقر إلى تقييد التردد
**الملف**: `admin/config/security.php`

لا تمتلك لوحة الإدارة إعداد rate_limits. يفحص وسيط WAF الخاص باللوحة حدود حجم الطلب/Content-Type فقط. هجوم القوة الغاشمة على تسجيل دخول اللوحة غير مقيّد على مستوى التطبيق.

**التوصية**: إضافة rate_limits إلى admin/config/security.php أو تطبيق RateLimitMiddleware على مسارات اللوحة.

### الفجوة 4 (منخفضة): GeoBlockMiddleware معرّف لكنه غير مفعّل
**الملف**: `service/common/security/GeoBlockMiddleware.php`

الوسيط موجود ويعمل، لكنه غير مسجل في `service/config/middleware.php`. إذا كان الحجب الجغرافي مطلوباً، أضفه إلى المكدس.

### الفجوة 5 (معلومة): عبء WAF المزدوج
يعمل كل من WafMiddleware (مخصص، أكثر من 40 نمط تعبير منتظم) وSecurityMiddleware (إضافة، 31 كاشفاً) على كل طلب. تتداخل تغطية أنماطهما بشكل كبير في SQLi وXSS وحقن الأوامر وعبور المسار وحقن الرؤوس وSSRF وNoSQLi وإعادة التوجيه المفتوح.

**التوصية**: إضافة الأمان أشمل (31 كاشفاً مقابل 8 فئات) وتمتلك قائمة IP سوداء وقائمة حقول بيضاء وإزالة تكرار السجلات. فكر في إزالة WafMiddleware المخصص والاعتماد على الإضافة وحدها، أو على الأقل إزالة الأنماط المتداخلة من WafMiddleware.

### الفجوة 6 (معلومة): فئة Validator ضئيلة
**الملف**: `service/common/helper/Validator.php`

تحتوي فقط required() وemail() وminLength(). مفقود: الحد الأقصى للطول، التحقق الرقمي، تنظيف السلاسل، التحقق من URL، مطابقة الأنماط. وحدات التحكم التي لا تستخدم تحققاً على مستوى الإطار معرضة لقبول مدخلات مشوهة.

---

## 3. إضافة الأمان — حالة الكاشفات الـ 31

| # | الكاشف | الوضع | ملاحظات |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF يطابق محتوى textarea، يجب بقاؤه log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} يطابق قوالب Vue/Angular |
| 13 | nosql_injection | **log** | $ne/$gt يطابق متغيرات الصدفة/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | Host الحلقة المحلية (127.0.0.1/localhost) لم يعد 403 (طبيعي في التطوير/الاختبار، يُسجل فقط) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

جميع الكاشفات الـ 31 مفعلة. 4 في وضع التسجيل فقط (خطر إيجابي كاذب موثق). إعداد صحيح.

---

## 4. ترتيب تنفيذ الوسائط (service)

```
1. VersionMiddleware          — تحليل رأس إصدار API
2. CorsMiddleware              — رؤوس CORS (متساهلة أكثر من اللازم، انظر الفجوة 1)
3. ClientPlatformMiddleware    — كشف نظام التشغيل/المنصة
4. WafMiddleware               — WAF المخصص (أكثر من 40 نمط تعبير منتظم)
5. SecurityMiddleware           — WAF الإضافة (31 كاشفاً)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — فك ترميز المعرّفات
8. MaintenanceMiddleware       — فحص وضع الصيانة
```

---

## 5. الملخص

| الفئة | الدرجة | القضايا الرئيسية |
|----------|-------|------------|
| اكتشاف الهجمات | **A** | 31 كاشفاً، طبقة WAF مزدوجة (زائدة لكنها شاملة) |
| المصادقة | **A-** | bcrypt+MFA+قائمة JWT سوداء، تقييد تردد اللوحة مفقود |
| أمان النقل | **B+** | AES-256-GCM جيد، رأسا HSTS/CSP مفقودان |
| التحقق من المدخلات | **B** | WAF يلتقط الهجمات، تحقق مستوى التطبيق رفيع |
| التحكم في الوصول | **A-** | RBAC + فحص الجلسة، CORS متساهل أكثر من اللازم |
| التدقيق/التسجيل | **A** | قاعدة تدقيق مستقلة، إزالة حساسية الحقول |
| تقييد التردد | **B+** | مهيأ جيداً للخدمة، مفقود للوحة |

**ترتيب أولويات الإصلاح:**
1. إضافة رؤوس استجابة أمنية (HSTS وCSP وX-Frame-Options وغيرها)
2. تقييد CORS إلى قائمة بيضاء بدلاً من عكس أي أصل
3. إضافة تقييد تردد للوحة الإدارة
4. تفعيل GeoBlockMiddleware إذا كان الحجب الجغرافي مطلوباً
5. التفكير في دمج طبقات WAF لتقليل عبء التعبيرات المنتظمة لكل طلب

---

## 6. المعالجات المطبقة (2026-08-04)

### أُصلح
| الفجوة | الإصلاح | الملفات المعدلة |
|-----|-----|---------------|
| CORS يعكس أي أصل | وضع قائمة بيضاء عبر متغير `CORS_ALLOWED_ORIGINS`، يدعم أحرف البدل `*.example.com` و`*` للكل | `service/common/security/CorsMiddleware.php` |
| رؤوس أمنية مفقودة | إضافة `SecurityHeadersMiddleware` جديد لمكدسي service وadmin: X-Content-Type-Options وX-Frame-Options وReferrer-Policy وX-XSS-Protection وPermissions-Policy وHSTS (اختياري عبر env) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| اللوحة بلا تقييد تردد | إضافة إعداد `rate_limits` + `RateLimitMiddleware` للوحة (افتراضي 60/min، login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock غير مفعل | تسجيل `GeoBlockMiddleware` في مكدس وسائط service | `service/config/middleware.php` |

### متغيرات بيئة جديدة
| المتغير | الغرض | الافتراضي |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | الأصول المسموحة مفصولة بفواصل | (فارغ = رفض الكل) |
| `SECURITY_HSTS_ENABLE` | تفعيل رأس HSTS | false |
| `SECURITY_HSTS_VALUE` | قيمة رأس HSTS | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | قيمة X-Frame-Options | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | رموز الدول المحجوبة (ISO 3166-1) | (فارغ = معطل) |
| `GEOIP_DB_PATH` | مسار ملف GeoLite2 .mmdb | storage_path('geoip/GeoLite2-Country.mmdb') |

### خط أنابيب الوسائط المحدّث

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
