# CloudPlatform অডিট রিপোর্ট (তৃতীয় রাউন্ড, 2026-08-06)

> সুযোগ: সামগ্রিক রিয়েল টেস্ট (সার্ভিস স্টার্ট + স্মোক টেস্ট) + গভীর কোড পরীক্ষা + ইকোলজিক্যাল/সিকিউরিটি কনফিগারেশন সম্পূর্ণতা যাচাই।
> এই রাউন্ড "স্ট্যাটিক রিডেবল" থেকে "**রানেবল**"-এ উন্নীত: ৫টি স্টার্ট-লেভেল P0 ও ৩টি রান-লেভেল P0/P1 ফিক্স করা হয়েছে, সার্ভিস সম্পূর্ণ মিডলওয়্যার চেইনে স্মোক পাস করেছে।
> টেস্ট বেসলাইন: service **316/316 পাস (502 assertions)**; admin **67/67 পাস (124 assertions)**।

---

## ১. এই রাউন্ডের ফিক্স তালিকা (সব রিয়েল টেস্টে ভেরিফাইড)

### P0 — স্টার্ট-লেভেল (worker ক্র্যাশ / পুরো সাইট অপ্রাপ্য)

| # | সমস্যা | মূল কারণ | ফিক্স |
|---|------|------|------|
| 1 | `A facade root has not been set` → স্টার্টে ক্র্যাশ | bootstrap Illuminate Facade-এর জন্য কন্টেইনার সেট করেনি | `Facade::setFacadeApplication($capsule->getContainer())` (bootstrap.php:149) |
| 2 | `Target class [events] does not exist` | ইভেন্ট লিসেনার Event Facade ব্যবহার করে, কিন্তু কন্টেইনারে events সার্ভিস নেই | `Dispatcher` ইন্সট্যান্স ব্যবহার: `$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()` (৩টি লিসেনার) |
| 3 | `Class support\SentryBootstrap not found` | composer.json psr-4-এ `support\` ম্যাপিং নেই | `"support\\": "support/"` যোগ + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` খালি → এনক্রিপশন সার্ভিস ক্র্যাশ | .env-এ খালি মান (phpdotenv createUnsafeMutable ওভাররাইড ইনজেকশন) | ৩২-বাইট base64 কী জেনারেট করে .env-এ লেখা |
| 5 | সব `/api/*` রাউট 404 | `ApiRequest::path()` `/api/xxx`-কে `/api/v1/xxx`-এ রিরাইট করে, কিন্তু রাউট রেজিস্ট্রেশনে ভার্সন প্রিফিক্স নেই | রিরাইট লজিক অপসারণ, পাথ অপরিবর্তিত রাখা (ভার্সন ভ্যালিডেশন VersionMiddleware-এ X-Api-Version হেডার ভিত্তিতে) |
| 6 | `Class "ErikJwt\JWTFactory" not found` | অস্তিত্বহীন `ErikJwt\` নেমস্পেস ব্যবহার | প্যাকেজের আসল নেমস্পেস `Erikwang2013\Jwt\*`-তে পরিবর্তন |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` null রিটার্ন → `createFromConfig()` টাইপ এরর | webman `Config::loadFromDir`-এর জন্য প্লাগইন ডিরেক্টরিতে অবশ্যই `app.php` থাকতে হবে (নাহলে পুরো ডিরেক্টরি স্কিপ হয়); jwt প্লাগইন ডিরেক্টরিতে নেই | `config/plugin/erikwang2013/jwt/app.php` যোগ (`'enable' => true`, vendor টেমপ্লেটের সাথে সামঞ্জস্যপূর্ণ) |

### P0 — রান-লেভেল (প্রথম রিকোয়েস্টেই 500)

| # | সমস্যা | মূল কারণ | ফিক্স |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware সরাসরি ext-redis `\Redis::get()` স্ট্যাটিকভাবে কল করে | `\support\Redis::get/setex/incr` ব্যবহার |
| 9 | `Class support\Redis not found` | `support\Redis` webman স্কেলটন লেয়ারের (webman/webman প্যাকেজ), এই প্রজেক্টে শুধু framework ইনস্টল করা তাই অনুপস্থিত | নতুন `support/Redis.php` (নিচে ইতোমধ্যে থাকা illuminate/redis + config/redis.php) |
| 10 | AuthController-এর `Illuminate\Support\Facades\Redis::*` রেজল্যুশনে **বেয়ার phpredis ইন্সট্যান্স** (সংযুক্ত নয়) → "server went away" | কন্টেইনারে `redis` বাইন্ডিং নেই, অটো-ওয়্যারিং `Redis` ক্লাসে ফলব্যাক | bootstrap-এ `$container->singleton('redis', fn() => support\Redis::manager())` |
| 11 | `Call to undefined function storage_path()` | `storage_path()` স্কেলটন হেল্পার, এই প্রজেক্টে নেই | bootstrap-এ হেল্পার যোগ (`base_path()/storage`, function_exists গার্ড) |

### P1 — বাউন্ডারি ভ্যালিডেশন

| # | সমস্যা | ফিক্স |
|---|------|------|
| 12 | `/api/auth/refresh`-এ refresh_token নেই → TypeError 500 | AuthController::refresh-এ `is_string` ভ্যালিডেশন যোগ → 422 |

### সাময়িক অবস্থা পুনরুদ্ধার

- `config/server.php` (8787), `config/process.php` (9100/8282), `config/middleware.php` (সম্পূর্ণ ১১-লেয়ার চেইন) git থেকে আগের অবস্থায় ফেরত
- bootstrap.php-এর `[AUDIT]` ডিবাগ error_log সরানো হয়েছে

---

## ২. স্মোক টেস্ট ফলাফল (সম্পূর্ণ মিডলওয়্যার চেইন, পোর্ট 8787)

| এন্ডপয়েন্ট | ফলাফল | বিবরণ |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | ক্লিক ক্যাপচা ইমেজ রিটার্ন |
| POST /api/auth/login (ক্যাপচা ছাড়া) | 422 | captcha ভ্যালিডেশন কার্যকর |
| POST /api/auth/register (খালি প্যারামিটার) | 422 | ফিল্ড ভ্যালিডেশন কার্যকর |
| POST /api/auth/refresh (টোকেন ছাড়া) | 422 | এই রাউন্ডের ফিক্স আইটেম |
| POST /api/auth/forgot-password | 500 (DB কানেকশন প্রত্যাখ্যাত) | **এনভায়রনমেন্ট ঘাটতি**: .env-এ DB_PASSWORD নেই, §৪ দেখুন |
| GET with X-Api-Version: v99 | 400 | VersionMiddleware কার্যকর |
| GET /api/nonexistent | 404 | স্বাভাবিক 404 পেজ |

Redis পাথ (ক্যাপচা, রেট লিমিট, JWT ব্ল্যাকলিস্ট স্টোরেজ) সব রিয়েল টেস্টে কাজ করছে।

---

## ৩. সিকিউরিটি প্রোটেকশন যাচাই

### পাস হয়েছে ✓

- **কী ম্যানেজমেন্ট**: পুরো প্রজেক্টে কোনো হার্ডকোডেড কী/পাসওয়ার্ড নেই (grep স্ক্যান); সব কী `getenv()` দিয়ে যায়; .env gitignore করা
- **SQL ইনজেকশন**: কোনো স্ট্রিং কনক্যাটেনেশন SQL নেই; সব Eloquent কুয়েরি বিল্ডার দিয়ে
- **ইনপুট ভ্যালিডেশন**: আপলোড type হোয়াইটলিস্ট + finfo কনটেন্ট স্নিফিং + টাইপভেদে সাইজ সীমা; auth এন্ডপয়েন্টে ফিল্ড-লেভেল ভ্যালিডেশন
- **রেট লিমিট**: পাবলিক সেনসিটিভ এন্ডপয়েন্ট সম্পূর্ণ কভার (login 5/min, register 3/min, sms 5/h, captcha 30/60s, oauth 10/60s, password_reset 3/5min), default 60/min
- **JWT**: HS256 + ৩২-বাইট কী; access/refresh আলাদা; type ভ্যালিডেশন; Redis ব্ল্যাকলিস্ট (লাইব্রেরিতে jti ভিত্তিক); TOTP বাধ্যতামূলক + ব্যর্থ হলে লক
- **CORS**: Origin হোয়াইটলিস্ট (`CORS_ALLOWED_ORIGINS`), কোনো ওয়াইল্ডকার্ড নেই, ক্রেডেনশিয়াল হেডার নেই
- **সিকিউরিটি হেডার**: nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS (env সুইচ)
- **এনুমারেশন প্রতিরোধ**: forgot-password অস্তিত্বহীন ইউজারের জন্যও একই সফল মেসেজ রিটার্ন

### সুপারিশ (কম অগ্রাধিকার, পরিবর্তন করা হয়নি)

| আইটেম | বিবরণ |
|----|------|
| CSP হেডার নেই | সাইটজুড়ে Content-Security-Policy কনফিগার নেই; API JSON সিনারিওতে ঝুঁকি কম, SecurityHeadersMiddleware-এ `default-src 'none'` লেভেল পলিসি যোগ করার সুপারিশ |
| WAF পারফরম্যান্স | WafMiddleware প্রতি রিকোয়েস্টে `file_get_contents('php://input')` দিয়ে পুরো body স্ক্যান করে (৩১ প্যাটার্ন), উচ্চ ট্রাফিকে মেমোরি/CPU ওভারহেড; শুধু POST/PUT এবং Content-Type ম্যাচ করলে body পড়ার সুপারিশ |
| HealthController `shell_exec('git rev-parse')` | প্রতি health রিকোয়েস্টে সাবপ্রসেস চালু; প্রোডাকশনে শুধু `APP_VERSION` env ব্যবহার করা, shell শুধু লোকাল ডেভেলপমেন্ট ফলব্যাক |
| ~~RateLimit TOCTOU~~ | ~~check-then-set নন-অ্যাটমিক~~ **ফিক্স হয়েছে (2026-08-07):** অ্যাটমিক `INCR` + প্রথমবার `EXPIRE`, §৭-৬ দেখুন |
| X-XSS-Protection | ডিপ্রিকেটেড হেডার, ক্ষতিকর নয়, রাখা হয়েছে; CSP যোগ হলে অপসারণযোগ্য |

---

## ৪. এনভায়রনমেন্ট ঘাটতি (কোড সমস্যা নয়, অপারেশনকে পূরণ করতে হবে)

1. **`.env`-এ `DB_PASSWORD` নেই** (একমাত্র ব্লকিং আইটেম): docker-compose `${DB_PASSWORD}` দিয়ে app_user তৈরি করে, লোকাল .env-এ এই কী নেই → সব DB এন্ডপয়েন্ট 500। `DB_PASSWORD` `.env.example`-এ ডেফাইন করা আছে, ডিপ্লয়মেন্ট ক্রেডেনশিয়াল, ইউজারকে `.env`-এ পূরণ করতে হবে।
2. **9100 লোকাল dart প্রসেস দখল করেছে**: metrics প্রসেস ডিফল্ট পোর্টে বাইন্ড ব্যর্থ হলে **পুরো গ্রুপের স্টার্ট ব্লক হয়** (webman স্টার্টের আগে সব পোর্ট প্রি-চেক)। স্থায়ী বাইপাস: `.env`-এ `METRICS_PORT=9199` লেখা হয়েছে (2026-08-07)। dart 9100 ছাড়লে ডিফল্টে ফেরত যাওয়া যাবে।
3. **composer validate fatal** (থার্ড-পার্টি): `erikwang2013/security-php`-এর composer প্লাগইন composer-এর নিজস্ব eval-এর সাথে দ্বন্দ্ব (`isLaravel()` ডুপ্লিকেট ডিক্লারেশন), এই প্রজেক্টের কোডের সাথে সম্পর্কহীন; CI-তে `composer validate --strict` ধাপ এতে ব্যর্থ হতে পারে, CI ধাপে continue-on-error দেওয়া বা service প্যাকেজ স্কিপ করার সুপারিশ।
4. আগের রাউন্ডে রেকর্ড করা 8787 erp-php দখল সমাধান হয়েছে (এই রাউন্ডে বাইন্ড করা যায়)।

---

## ৫. ইকোলজিক্যাল কনফিগারেশন যাচাই

| আইটেম | ফলাফল |
|----|------|
| CI (.github/workflows/ci.yml) | সম্পূর্ণ: PHP সিনট্যাক্স চেক + admin/service টেস্ট (PHP 8.2/8.3 ম্যাট্রিক্স) + composer validate |
| মাইগ্রেশন | ৩০টি মাইগ্রেশন ফাইল |
| Docker | compose (MySQL+Redis+app), Dockerfile, nginx.conf, prometheus, grafana, supervisor (nginx+webman) |
| মনিটরিং | MetricsServer (Prometheus আলাদা পোর্ট) + websocket প্রসেস (process.php) |
| লোড টেস্ট | tests/k6 (smoke/products/concurrent) |
| .env.example | কী .env-এর চেয়ে বেশি সম্পূর্ণ (OAuth/Feature সুইচ ইত্যাদি সব কভার); .env-এ কোনো সুপারসেট কী নেই |
| composer audit | কোনো সিকিউরিটি ভ্যালনারেবিলিটি নেই; ১টি ডিপ্রিকেটেড প্যাকেজ doctrine/annotations (hg/apidoc ডিপেন্ডেন্সি, রাখার মূল্যায়ন) |
| কিউ/অ্যাসিঙ্ক | webman/redis-queue ইনস্টল করা; নোটিফিকেশন NotificationDispatcher দিয়ে যায় |

---

## ৬. অবশিষ্ট সুপারিশ (পরবর্তী ইটারেশন)

1. **CSP হেডার** (§৩ দেখুন)
2. **WAF body রিড অপ্টিমাইজেশন** (§৩ দেখুন)
3. **DB_PASSWORD পূরণের পর DB ফুল চেইন পুনরায় টেস্ট** (register→login→refresh→logout রিয়েল ফ্লো + JWT ব্ল্যাকলিস্ট ইনভ্যালিডেশন ভেরিফাই)
4. ~~**supervisor-এ cron প্রসেস নেই**: Billing\Cron\SuspendCheck ইত্যাদি ক্রন টাস্কের কোনো ডেমন এন্ট্রি নেই~~ **সমাধান (2026-08-07):** নতুন `App\Cron\CronRunner` প্রসেস (প্রতি মিনিটে config/cron.php-এর ৫-ফিল্ড এক্সপ্রেশন মূল্যায়ন) + provisioning/notification কিউ কনজিউম করার জন্য `queue_consumer` প্রসেস রেজিস্টার করা; cron.php-এ স্ক্রিপ্ট ফাইল নির্দেশ করা দুটি অবৈধ রেজিস্ট্রেশন `ResourceMonitor` কলেবল মেথডে পরিবর্তন
5. **CI composer-validate ধাপ**: থার্ড-পার্টি প্লাগইন দ্বন্দ্বের কারণে ফল্ট টলারেন্স যোগের সুপারিশ (§৪-৩ দেখুন)

---

## ৭. চতুর্থ রাউন্ডের অতিরিক্ত ফিক্স (2026-08-07)

1. **বিলিং অ্যাটমিসিটি (P0 ফাইন্যান্সিয়াল)**: `BillingEngine::runDaily()` রিসোর্স অনুযায়ী ট্রানজেকশনে মোড়ায়, ডেবিট/সাসপেন্ড/ইভেন্ট মার্ক একই ট্রানজেকশনে কমিট; `StripeChannel::confirmPayment()` `UPDATE ... WHERE status='pending'` অ্যাটমিক প্রিম্পশন + অর্ডার রো লক, ওয়েবহুক ডুপ্লিকেট ক্রেডিট প্রতিরোধ।
2. **কনকারেন্সি ইডেম্পোটেন্সি (P0/P1)**: `AffiliateService::requestPayout()` রো লক + ইতোমধ্যে থাকা pending উইথড্রয়াল সরাসরি রিটার্ন; `SupplierSettlement` (cron ও `generateSettlement`) সাপ্লায়ার+পিরিয়ড দিয়ে ডুপ্লিকেট নির্ণয়।
3. **ডেটা সঠিকতা (P1)**: `MeterCollector`-এ `$resource->first()` ভুলবশত ফুল-টেবিল কুয়েরি ফিক্স; `ExchangeRateSync`-এ ১০s টাইমআউট।
4. **পারফরম্যান্স (P2)**: Dashboard-এর ৩০টি SUM কুয়েরি একক GROUP BY-তে একীভূত; `CacheService::forgetPattern()` KEYS→SCAN কার্সর; `I18n` ভাষা প্যাক locale অনুযায়ী প্রসেস-ইন্টারনাল ক্যাশ; `ImportExport` ইমপোর্ট পুরো রাউন্ড ট্রানজেকশনে; `BillingEngine` রেট ম্যাপ প্রিফেচ দিয়ে N+1 নির্মূল।
5. **সিকিউরিটি (P1)**: `InternalTokenMiddleware` `getRemoteIp()` দিয়ে XFF জালিয়াতি প্রতিরোধ; Webhook রেজিস্ট্রেশন প্রাইভেট নেটওয়ার্ক ঠিকানা নিষিদ্ধ (SSRF); `JwtAuth` খালি কীতে fail-fast; `DbBackupCommand` পাসওয়ার্ড `MYSQL_PWD` দিয়ে `ps` লিক প্রতিরোধ; CSV/Excel এক্সপোর্টে ফর্মুলা ইনজেকশন প্রতিরোধ; সাপ্লায়ার এক্সটার্নাল API-তে `supplier_api` রেট লিমিট।
6. **ইনফ্রাস্ট্রাকচার (P2)**: `RateLimitMiddleware` অ্যাটমিক INCR (TOCTOU নির্মূল); `MetricsServer` `onMessage` টাইপ ক্র্যাশ লুপ ফিক্স; `HealthController` Redis কানেকশন পুল; `symfony/mailer ^6.4` ইনস্টল (EmailSender আগে ছিল লুকানো ঝুঁকি); admin সাইড `EncryptableBootstrap` নেমস্পেস সংশোধন।

---

## ৮. পঞ্চম রাউন্ডের অতিরিক্ত ফিক্স (2026-08-07)

1. **অটো ডেলিভারি সংযোগ (P0)**: `ProvisioningService::handleOrderPaid` ডেলিভারি টাস্ক তৈরি করে `provisioning` কিউতে ডিসপ্যাচ; `process.php`-এ `queue_consumer` প্রসেস রেজিস্টার (app/-এর নিচে সব `Webman\RedisQueue\Consumer` ইমপ্লিমেন্টেশন স্ক্যান)।
2. **ক্রন টাস্ক এক্সিকিউটেবল (P0)**: নতুন `App\Cron\CronRunner` প্রসেস (প্রতি মিনিটে config/cron.php-এর ৫-ফিল্ড এক্সপ্রেশন মূল্যায়ন, `*/n`/`,`/`-` সিনট্যাক্স সাপোর্ট); cron.php-এ স্ক্রিপ্ট ফাইল (ক্লাস নয়) নির্দেশ করা দুটি অবৈধ রেজিস্ট্রেশন `ResourceMonitor::collectAllMetrics`/`checkSslCertificates` কলেবল মেথডে পরিবর্তন, এবং ExpirationCheck-এর সাথে ডুপ্লিকেট checkExpirations রেজিস্ট্রেশন অপসারণ।
3. **নোটিফিকেশন ক্লাস নেই (P0)**: AuthService/AuthController/ExpirationCheck-এ ৪ জায়গায় `\Common\Notification\NotificationDispatcher::send()` (ক্লাস নেই) ইউনিফর্মলি `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)`-তে পরিবর্তন।
4. **টেবিল নাম তিন সিস্টেম একীভূতকরণ (P0)**: install.sql-এ ৩৯টি `erik_*` বিজনেস টেবিল প্রিফিক্সবিহীন করা হয়েছে (Eloquent ডিফল্ট নামকরণ, migrations-এর সাথে সামঞ্জস্যপূর্ণ), `wa_*` অ্যাডমিন টেবিল বহাল; ইনস্টলেশন উইজার্ড (install/index.php) পরিবর্তন করে "লিখুন .env → সাবপ্রসেসে service migrations চালান (৩০টি মাইগ্রেশন ফাইল) → install.sql (IF NOT EXISTS আগের তৈরি টেবিল স্কিপ)" — ইনস্টলের পর ডেটাবেস সম্পূর্ণ।
5. **P1/P2 গ্রুপ (সাবএজেন্টে সম্পন্ন, ৩১৬ টেস্ট পাস ভেরিফাইড)**: ইভেন্ট ওয়্যারিং, রেট কারেন্সিভিত্তিক লেখা, `Response::error` সিঙ্গেল-প্যারামিটার ৪০০ (১০ জায়গা), রিফান্ড এক্সিকিউটর (RefundService নতুন), অ্যাপ্রুভাল ইডেম্পোটেন্সি, admin সংবেদনশীল অপারেশন অডিট, noNeedAuth অপসারণ, ম্যানেজমেন্ট API রেট লিমিট, WebSocket Redis Pub/Sub-এ পরিবর্তন, SSL কুয়েরি বাগ, কারেন্সি/বকেয়া, ক্রেডেনশিয়াল ডিসেন্সিটাইজেশন, কুপন প্রয়োগ, সংখ্যা ভ্যালিডেশন, CI ফল্ট টলারেন্স, ES_HOST ট্রান্সপারেন্সি।

**টেস্ট বেসলাইন**: service 316/316 (502 assertions), admin 67/67 (124 assertions) সব গ্রিন; সব পরিবর্তিত ফাইল `php -l` পাস।

## উপসংহার

এই রাউন্ড "কোড রিডেবল" থেকে "**স্টার্টযোগ্য, রানযোগ্য**"-তে উন্নীত: ৮টি P0 লেভেল ফল্ট সব ফিক্স ও রিয়েল টেস্ট, ৩১৬টি টেস্ট সব গ্রিন, সম্পূর্ণ মিডলওয়্যার চেইন স্মোক পাস। অবশিষ্ট একমাত্র ব্লকার একটি এনভায়রনমেন্ট ঘাটতি (DB_PASSWORD), পূরণ করলেই ফুল চেইন ভেরিফিকেশন করা যাবে। চতুর্থ রাউন্ডে (2026-08-07) বিলিং অ্যাটমিসিটি, কনকারেন্সি ইডেম্পোটেন্সি, রেট লিমিট/ইনজেকশন প্রোটেকশন ইত্যাদি ২০+ আইটেম হার্ডেনিং সম্পন্ন; পঞ্চম রাউন্ডে (2026-08-07) অটো ডেলিভারি, cron শিডিউলিং, নোটিফিকেশন ক্লাস, টেবিল নাম সিস্টেম ইত্যাদি ৪টি P0 ও P1/P2 গ্রুপের সব ফিক্স সম্পন্ন, টেস্ট সব গ্রিন।
