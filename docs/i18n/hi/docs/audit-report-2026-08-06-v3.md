# CloudPlatform ऑडिट रिपोर्ट (तीसरा दौर, 2026-08-06)

> दायरा: समग्र वास्तविक परीक्षण (सेवा शुरू करना + स्मोक टेस्ट) + गहन कोड निरीक्षण + इकोसिस्टम/सुरक्षा कॉन्फ़िगरेशन पूर्णता जाँच।
> इस दौर में "स्थैतिक पठनीय" से "**चलाने योग्य**" तक आगे बढ़े: 5 स्टार्टअप-स्तरीय P0 और 3 रनटाइम-स्तरीय P0/P1 ठीक, सेवा पूर्ण मिडलवेयर श्रृंखला के साथ स्मोक पास।
> परीक्षण बेसलाइन: service **316/316 पास (502 assertions)**; admin **67/67 पास (124 assertions)**।

---

## एक, इस दौर की फिक्स सूची (सभी वास्तविक परीक्षण द्वारा सत्यापित)

### P0 — स्टार्टअप-स्तरीय (worker क्रैश / पूरी साइट अनुपलब्ध)

| # | समस्या | मूल कारण | फिक्स |
|---|------|------|------|
| 1 | `A facade root has not been set` → स्टार्टअप क्रैश | bootstrap ने Illuminate Facade के लिए कंटेनर सेट नहीं किया | `Facade::setFacadeApplication($capsule->getContainer())` (bootstrap.php:149) |
| 2 | `Target class [events] does not exist` | इवेंट लिसनर Event Facade उपयोग करते हैं, लेकिन कंटेनर में events सेवा नहीं | `Dispatcher` इंस्टेंस उपयोग करें: `$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()` (3 लिसनर) |
| 3 | `Class support\SentryBootstrap not found` | composer.json psr-4 में `support\` मैपिंग अनुपस्थित | `"support\\": "support/"` जोड़ें + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` खाली → एन्क्रिप्शन सेवा क्रैश | .env में खाली मान (phpdotenv createUnsafeMutable ओवरराइड इंजेक्शन) | 32-बाइट base64 कुंजी उत्पन्न कर .env में लिखें |
| 5 | सभी `/api/*` रूट्स 404 | `ApiRequest::path()` `/api/xxx` को `/api/v1/xxx` में फिर लिखता है, जबकि रूट पंजीकरण में वर्जन प्रीफ़िक्स नहीं | फिर-लिखने का लॉजिक हटाएँ, पथ यथावत रखें (वर्जन सत्यापन VersionMiddleware द्वारा X-Api-Version हेडर पर आधारित) |
| 6 | `Class "ErikJwt\JWTFactory" not found` | अस्तित्वहीन `ErikJwt\` नेमस्पेस उपयोग | पैकेज के वास्तविक नेमस्पेस `Erikwang2013\Jwt\*` में बदलें |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` null लौटाता है → `createFromConfig()` टाइप त्रुटि | webman `Config::loadFromDir` के लिए प्लगइन निर्देशिका में `app.php` अनिवार्य (अन्यथा पूरी निर्देशिका छूट जाती है); jwt प्लगइन निर्देशिका अनुपस्थित | `config/plugin/erikwang2013/jwt/app.php` जोड़ें (`'enable' => true`, vendor टेम्पलेट के अनुरूप) |

### P0 — रनटाइम-स्तरीय (पहला अनुरोध ही 500)

| # | समस्या | मूल कारण | फिक्स |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware सीधे स्टैटिक रूप से ext-redis `\Redis::get()` कॉल करता है | `\support\Redis::get/setex/incr` उपयोग करें |
| 9 | `Class support\Redis not found` | `support\Redis` webman स्केलेटन परत का है (webman/webman पैकेज), इस प्रोजेक्ट ने केवल framework स्थापित किया है इसलिए अनुपस्थित | नई `support/Redis.php` (नीचे मौजूद illuminate/redis + config/redis.php) |
| 10 | AuthController का `Illuminate\Support\Facades\Redis::*` **नंगे phpredis इंस्टेंस** (बिना कनेक्शन) में हल → "server went away" | कंटेनर में `redis` बाइंडिंग नहीं, ऑटो-वायरिंग `Redis` क्लास पर फ़ॉलबैक करती है | bootstrap में `$container->singleton('redis', fn() => support\Redis::manager())` पंजीकृत करें |
| 11 | `Call to undefined function storage_path()` | `storage_path()` स्केलेटन हेल्पर है, इस प्रोजेक्ट में अनुपस्थित | bootstrap में हेल्पर जोड़ें (`base_path()/storage`, function_exists गार्ड) |

### P1 — सीमा सत्यापन

| # | समस्या | फिक्स |
|---|------|------|
| 12 | `/api/auth/refresh` में refresh_token अनुपस्थित होने पर TypeError 500 | AuthController::refresh में `is_string` सत्यापन जोड़ें → 422 |

### अस्थायी स्थिति बहाली

- `config/server.php` (8787), `config/process.php` (9100/8282), `config/middleware.php` (पूर्ण 11-परत श्रृंखला) git से मूल स्थिति में बहाल
- bootstrap.php का `[AUDIT]` डिबग error_log हटाया गया

---

## दो, स्मोक टेस्ट परिणाम (पूर्ण मिडलवेयर श्रृंखला, पोर्ट 8787)

| एंडपॉइंट | परिणाम | विवरण |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | क्लिक कैप्चा छवि लौटाता है |
| POST /api/auth/login (बिना कैप्चा) | 422 | captcha सत्यापन प्रभावी |
| POST /api/auth/register (खाली पैरामीटर) | 422 | फ़ील्ड सत्यापन प्रभावी |
| POST /api/auth/refresh (टोकन अनुपस्थित) | 422 | इस दौर की फिक्स आइटम |
| POST /api/auth/forgot-password | 500 (DB कनेक्शन अस्वीकृत) | **पर्यावरण अंतराल**: .env में DB_PASSWORD नहीं, §चार देखें |
| GET X-Api-Version: v99 के साथ | 400 | VersionMiddleware प्रभावी |
| GET /api/nonexistent | 404 | सामान्य 404 पृष्ठ |

Redis पथ (कैप्चा, रेट लिमिट, JWT ब्लैकलिस्ट स्टोरेज) सभी वास्तविक परीक्षण में उपलब्ध।

---

## तीन, सुरक्षा सुरक्षा जाँच

### मानक पूर्ण ✓

- **कुंजी प्रबंधन**: पूरे प्रोजेक्ट में कोई हार्डकोडेड कुंजी/पासवर्ड नहीं (grep स्कैन); सभी कुंजियाँ `getenv()` से; .env gitignore है
- **SQL इंजेक्शन**: कोई स्ट्रिंग कॉन्केटेनेशन SQL नहीं; सभी Eloquent क्वेरी बिल्डर से
- **इनपुट सत्यापन**: अपलोड type व्हाइटलिस्ट + finfo सामग्री स्निफिंग + प्रकार-वार आकार सीमा; auth एंडपॉइंट्स पर फ़ील्ड-स्तरीय सत्यापन
- **रेट लिमिट**: सार्वजनिक संवेदनशील एंडपॉइंट्स पूर्ण कवर (login 5/min, register 3/min, sms 5/h, captcha 30/60s, oauth 10/60s, password_reset 3/5min), default 60/min
- **JWT**: HS256 + 32-बाइट कुंजी; access/refresh पृथक; type सत्यापन; Redis ब्लैकलिस्ट (डेटाबेस में jti के अनुसार); TOTP अनिवार्य + विफल लॉक
- **CORS**: Origin व्हाइटलिस्ट (`CORS_ALLOWED_ORIGINS`), कोई वाइल्डकार्ड नहीं, कोई क्रेडेंशियल हेडर नहीं
- **सुरक्षा हेडर**: nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS (env स्विच)
- **एन्यूमरेशन रोकथाम**: forgot-password अस्तित्वहीन उपयोगकर्ता के लिए समान सफलता संदेश लौटाता है

### सुझाव (कम प्राथमिकता, बदला नहीं)

| आइटम | विवरण |
|----|------|
| CSP हेडर अनुपस्थित | पूरी साइट पर Content-Security-Policy कॉन्फ़िगर नहीं; API JSON परिदृश्य में जोखिम कम, SecurityHeadersMiddleware में `default-src 'none'` स्तर नीति जोड़ने का सुझाव |
| WAF प्रदर्शन | WafMiddleware हर अनुरोध पर `file_get_contents('php://input')` से पूरा body पढ़कर स्कैन करता है (31 पैटर्न), उच्च ट्रैफ़िक पर मेमोरी/CPU खर्च; केवल POST/PUT और Content-Type मेल खाने पर body पढ़ने का सुझाव |
| HealthController `shell_exec('git rev-parse')` | हर health अनुरोध पर सबप्रोसेस; प्रोडक्शन में केवल `APP_VERSION` env उपयोग करने का सुझाव, shell केवल स्थानीय डेवलपमेंट फ़ॉलबैक |
| ~~RateLimit TOCTOU~~ | ~~check-then-set गैर-परमाणु~~ **ठीक हुआ (2026-08-07):** परमाणु `INCR` + पहले `EXPIRE` में बदला, §सात-6 देखें |
| X-XSS-Protection | बहिष्कृत हेडर, रखना हानिरहित; CSP आने के बाद हटाया जा सकता है |

---

## चार, पर्यावरण अंतराल (कोड समस्या नहीं, ऑप्स को भरना आवश्यक)

1. **`.env` में `DB_PASSWORD` अनुपस्थित** (एकमात्र अवरोधक आइटम): docker-compose `app_user` को `${DB_PASSWORD}` से बनाता है, स्थानीय .env में वह कुंजी अनुपस्थित → सभी DB एंडपॉइंट्स 500। `DB_PASSWORD` `.env.example` में परिभाषित है, यह डिप्लॉयमेंट क्रेडेंशियल है, उपयोगकर्ता को `.env` में भरना आवश्यक।
2. **9100 इस मशीन के dart प्रोसेस द्वारा उपयोग में**: metrics प्रोसेस का डिफ़ॉल्ट पोर्ट बाइंड विफल होने पर **पूरे समूह का स्टार्टअप अवरुद्ध** होता है (webman स्टार्टअप से पहले सभी पोर्टों का प्री-चेक)। स्थायी बाइपास: `.env` में `METRICS_PORT=9199` लिखा गया (2026-08-07)। dart द्वारा 9100 छोड़े जाने के बाद डिफ़ॉल्ट पर वापस किया जा सकता है।
3. **composer validate fatal (तृतीय-पक्ष)**: `erikwang2013/security-php` का composer प्लगइन composer स्वयं के eval से टकराता है (`isLaravel()` दोहरी घोषणा), इस प्रोजेक्ट के कोड से असंबंधित; CI में `composer validate --strict` चरण इससे विफल हो सकता है, उस चरण पर continue-on-error जोड़ने या service पैकेज छोड़ने का सुझाव।
4. पिछले दौर में दर्ज 8787 का erp-php टकराव हल हो चुका (इस दौर में वास्तविक बाइंड सफल)।

---

## पांच, इकोसिस्टम कॉन्फ़िगरेशन जाँच

| आइटम | परिणाम |
|----|------|
| CI (.github/workflows/ci.yml) | पूर्ण: PHP सिंटैक्स जाँच + admin/service परीक्षण (PHP 8.2/8.3 मैट्रिक्स) + composer validate |
| माइग्रेशन | 30 माइग्रेशन फ़ाइलें |
| Docker | compose (MySQL+Redis+app), Dockerfile, nginx.conf, prometheus, grafana, supervisor (nginx+webman) |
| मॉनिटरिंग | MetricsServer (Prometheus स्वतंत्र पोर्ट) + websocket प्रोसेस (process.php) |
| लोड टेस्ट | tests/k6 (smoke/products/concurrent) |
| .env.example | कुंजियाँ .env से अधिक पूर्ण (OAuth/Feature स्विच आदि सभी कवर); .env में कोई सुपरसेट कुंजी नहीं |
| composer audit | कोई सुरक्षा भेद्यता नहीं; 1 बहिष्कृत पैकेज doctrine/annotations (hg/apidoc निर्भरता, मूल्यांकन कर रखा) |
| क्यू/असिंक | webman/redis-queue स्थापित; नोटिफिकेशन NotificationDispatcher से |

---

## छह, बचे हुए सुझाव (आगे के इटरेशन)

1. **CSP हेडर** (§तीन देखें)
2. **WAF body पठन ऑप्टिमाइज़ेशन** (§तीन देखें)
3. **DB_PASSWORD भरने के बाद DB पूर्ण-श्रृंखला पुनः परीक्षण** (register→login→refresh→logout वास्तविक प्रक्रिया + JWT ब्लैकलिस्ट अमान्यकरण सत्यापन)
4. ~~**supervisor में cron प्रोसेस नहीं**: Billing\Cron\SuspendCheck आदि क्रोन जॉब्स के लिए डेमन एंट्री नहीं~~ **हल (2026-08-07):** नया `App\Cron\CronRunner` प्रोसेस (हर मिनट config/cron.php के 5-फ़ील्ड एक्सप्रेशन का मूल्यांकन), और `queue_consumer` प्रोसेस पंजीकृत जो provisioning/notification क्यू खपाता है; cron.php में स्क्रिप्ट फ़ाइलों की ओर इशारा करने वाली दो अमान्य रजिस्ट्रेशन `ResourceMonitor` कॉल करने योग्य विधियों में बदली गईं
5. **CI composer-validate चरण**: तृतीय-पक्ष प्लगइन टकराव के कारण सहनशीलता जोड़ने का सुझाव (§चार-3 देखें)

---

## सात, चौथे दौर की पूरक फिक्स (2026-08-07)

1. **बिलिंग परमाणुता (P0 वित्त)**: `BillingEngine::runDaily()` संसाधन के अनुसार ट्रांज़ैक्शन लपेटता है, कटौती/सस्पेंड/इवेंट चिह्न उसी ट्रांज़ैक्शन में कमिट; `StripeChannel::confirmPayment()` `UPDATE ... WHERE status='pending'` परमाणु प्री-एम्प्शन + ऑर्डर पंक्ति लॉक उपयोग करता है, webhook डुप्लीकेट जमा रोकथाम।
2. **कंकरेंसी इडेम्पोटेंसी (P0/P1)**: `AffiliateService::requestPayout()` पंक्ति लॉक + मौजूदा pending विदड्रॉल पर सीधे वापसी; `SupplierSettlement` (cron और `generateSettlement`) सप्लायर+अवधि के अनुसार डुप्लीकेट जाँच।
3. **डेटा सहीता (P1)**: `MeterCollector` में `$resource->first()` के आकस्मिक पूर्ण-तालिका क्वेरी का फिक्स; `ExchangeRateSync` में 10s टाइमआउट जोड़ा।
4. **प्रदर्शन (P2)**: Dashboard की 30 SUM क्वेरियाँ एकल GROUP BY में मिलाईं; `CacheService::forgetPattern()` KEYS→SCAN कर्सर; `I18n` भाषा पैक locale के अनुसार प्रोसेस-आंतरिक कैश; `ImportExport` आयात पूर्ण-दौर ट्रांज़ैक्शन; `BillingEngine` दर मैपिंग प्री-फ़ेच से N+1 उन्मूलन।
5. **सुरक्षा (P1)**: `InternalTokenMiddleware` में `getRemoteIp()` से XFF जालसाज़ी रोकथाम; Webhook पंजीकरण निजी नेटवर्क पते अस्वीकार (SSRF); `JwtAuth` खाली कुंजी पर fail-fast; `DbBackupCommand` पासवर्ड `MYSQL_PWD` में बदला (`ps` लीक रोकथाम); CSV/Excel निर्यात में फॉर्मूला इंजेक्शन रोकथाम; सप्लायर बाह्य API पर `supplier_api` रेट लिमिट।
6. **इंफ्रास्ट्रक्चर (P2)**: `RateLimitMiddleware` परमाणु INCR (TOCTOU उन्मूलन); `MetricsServer` में `onMessage` टाइप क्रैश लूप फिक्स; `HealthController` Redis कनेक्शन पूलिंग; `symfony/mailer ^6.4` स्थापित (EmailSender पहले छिपा खतरा था); admin साइड `EncryptableBootstrap` नेमस्पेस सुधार।

---

## आठ, पाँचवें दौर की पूरक फिक्स (2026-08-07)

1. **स्वचालित डिलीवरी कनेक्शन (P0)**: `ProvisioningService::handleOrderPaid` डिलीवरी कार्य बनाने के बाद `provisioning` क्यू में भेजता है; `process.php` में `queue_consumer` प्रोसेस पंजीकृत (app/ के नीचे सभी `Webman\RedisQueue\Consumer` कार्यान्वयन स्कैन)।
2. **क्रोन जॉब्स निष्पादन योग्य (P0)**: नया `App\Cron\CronRunner` प्रोसेस (हर मिनट config/cron.php के 5-फ़ील्ड एक्सप्रेशन का मूल्यांकन, `*/n`/`,`/`-` सिंटैक्स समर्थित); cron.php में स्क्रिप्ट फ़ाइलों (गैर-क्लास) की ओर इशारा करने वाली दो अमान्य रजिस्ट्रेशन `ResourceMonitor::collectAllMetrics`/`checkSslCertificates` कॉल करने योग्य विधियों में बदली गईं, और ExpirationCheck के साथ डुप्लीकेट checkExpirations रजिस्ट्रेशन हटाई गई।
3. **नोटिफिकेशन क्लास अनुपस्थित (P0)**: AuthService/AuthController/ExpirationCheck में 4 स्थानों पर `\Common\Notification\NotificationDispatcher::send()` (क्लास अनुपस्थित) एकीकृत रूप से `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)` में बदला।
4. **टेबल नाम तीन प्रणालियों का एकीकरण (P0)**: install.sql में 39 `erik_*` बिज़नेस टेबलें बिना प्रीफ़िक्स की गईं (Eloquent डिफ़ॉल्ट नामकरण, migrations के अनुरूप), `wa_*` एडमिन टेबल बनी रहीं; इंस्टॉलेशन विज़ार्ड (install/index.php) → 「.env लिखें → सबप्रोसेस में service migrations (30 माइग्रेशन फ़ाइलें) पूरा करें → install.sql (IF NOT EXISTS से बनी टेबलें छोड़ें)」में बदला, इंस्टॉलेशन के बाद डेटाबेस टेबलें पूर्ण।
5. **P1/P2 समूह (सबएजेंट द्वारा पूरा, 316 परीक्षण सत्यापित पास)**: इवेंट वायरिंग, विनिमय दर प्रति-करेंसी लेखन, `Response::error` एकल-पैरामीटर पर 400 (10 स्थान), रिफंड निष्पादक (RefundService नया), अनुमोदन इडेम्पोटेंसी, admin संवेदनशील ऑपरेशन ऑडिट, noNeedAuth हटाना, एडमिन API रेट लिमिट, WebSocket Redis Pub/Sub में परिवर्तन, SSL क्वेरी बग, करेंसी/बकाया, क्रेडेंशियल डी-सेंसिटाइज़ेशन, कूपन एप्लिकेशन, मात्रा सत्यापन, CI सहनशीलता, ES_HOST ट्रांसमिशन।

**परीक्षण बेसलाइन**: service 316/316 (502 assertions), admin 67/67 (124 assertions) सभी हरे; सभी बदली गई फ़ाइलें `php -l` पास।

## निष्कर्ष

इस दौर में "कोड पठनीय" से "**शुरू होने योग्य, चलने योग्य**" तक आगे बढ़े: 8 P0-स्तरीय विफलताएँ सभी ठीक और वास्तविक परीक्षण में सत्यापित, 316 परीक्षण सभी हरे, पूर्ण मिडलवेयर श्रृंखला के साथ स्मोक पास। शेष अवरोध केवल एक पर्यावरण अंतराल (DB_PASSWORD) है, भरने के बाद पूर्ण-श्रृंखला सत्यापन संभव। चौथे दौर (2026-08-07) में आगे बिलिंग परमाणुता, कंकरेंसी इडेम्पोटेंसी, रेट लिमिट/इंजेक्शन सुरक्षा आदि 20+ सख्तीकरण पूरे; पाँचवाँ दौर (2026-08-07) स्वचालित डिलीवरी, cron शेड्यूलिंग, नोटिफिकेशन क्लास, टेबल नाम प्रणाली — 4 P0 और P1/P2 समूह सभी ठीक, परीक्षण हरे बने।
