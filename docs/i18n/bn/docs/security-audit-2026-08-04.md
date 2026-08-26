# Security Audit Report — cloud-php

**তারিখ**: 2026-08-04
**সুযোগ**: পুরো প্রজেক্ট (service + admin)
**পদ্ধতি**: কনফিগারেশন রিভিউ, মিডলওয়্যার অডিট, কোড পরিদর্শন

---

## সামগ্রিক মূল্যায়ন: **B+ (ভালো, ফিক্স করার ৪টি ঘাটতি)**

প্রজেক্টে শক্ত মাল্টি-লেয়ারড সিকিউরিটি আর্কিটেকচার আছে। ৩১টি ডিটেক্টর সহ erikwang2013/security-php প্লাগইন হলো সেরা ফিচার। নিচে বিস্তারিত বিভাজন দেওয়া হলো।

---

## 1. বর্তমানে থাকা ডিফেন্স (ভেরিফাইড)

### ট্রান্সপোর্ট ও এনক্রিপশন
| মেকানিজম | বাস্তবায়ন | স্ট্যাটাস |
|-----------|---------------|--------|
| API ট্রান্সপোর্ট এনক্রিপশন | erikwang2013/encryption দিয়ে AES-256-GCM | OK |
| DB ফিল্ড এনক্রিপশন | erikwang2013/encryptable দিয়ে AES-128-ECB (ডিটারমিনিস্টিক, কুয়েরি করা যায়) | OK |
| কী রোটেশন | ENCRYPTION_PREVIOUS_KEYS কমা-বিচ্ছিন্ন পুরনো কী | OK |
| ID অবফাসকেশন | কনফিগারেবল সল্ট ও ন্যূনতম ১২ দৈর্ঘ্যের Hashids | OK |
| পাসওয়ার্ড হ্যাশিং | bcrypt cost=12, ন্যূনতম ৮ অক্ষর | OK |

### অথেনটিকেশন ও অ্যাক্সেস কন্ট্রোল
| মেকানিজম | বাস্তবায়ন | স্ট্যাটাস |
|-----------|---------------|--------|
| JWT অথেনটিকেশন | erikwang2013/jwt-webman, HS256, access TTL 900s + refresh 30d | OK |
| JWT ব্ল্যাকলিস্ট | Redis-ব্যাকড টোকেন রিভোকেশন | OK |
| MFA/TOTP | ৬-অঙ্ক, ৩০s পিরিয়ড, Google/MS Authenticator সামঞ্জস্যপূর্ণ | OK |
| RBAC | Admin AccessControl মিডলওয়্যার + plugin\admin\api\Auth::canAccess() | OK |
| Session স্টোরেজ | Redis (db2) | OK |
| ক্যাপচা | লগইন/রেজিস্ট্রেশনের জন্য erikwang2013/poster-php ক্লিক-টেক্সট ক্যাপচা | OK |

### আক্রমণ সনাক্তকরণ (WAF — ডুয়াল লেয়ার)
| লেয়ার | কভারেজ | স্ট্যাটাস |
|-------|----------|--------|
| কাস্টম WafMiddleware | SQLi, XSS, CMDi, পাথ ট্রাভার্সাল, হেডার ইনজেকশন, SSRF, NoSQLi, ওপেন রিডাইরেক্ট | OK |
| Security Plugin (৩১ ডিটেক্টর) | উপরের সবগুলো + XXE, ডিসিরিয়ালাইজেশন, LDAP, মেইল হেডার, SSTI, JWT আক্রমণ, Host হেডার, রিকোয়েস্ট স্মাগলিং, GraphQL, XPATH, JNDI/Log4Shell, SSI, CSV ইনজেকশন, ডেটা লিক, প্রোটোটাইপ পলিউশন, WebSocket, CORS বাইপাস, DNS রিবাইন্ডিং | OK |

### রেট লিমিটিং (শুধু service)
| রাউট | রেট | Burst | Per | স্ট্যাটাস |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### অন্যান্য প্রোটেকশন
| মেকানিজম | বাস্তবায়ন | স্ট্যাটাস |
|-----------|---------------|--------|
| রিকোয়েস্ট সাইজ সীমা | 10MB body, 2KB URL | OK |
| Content-Type ভ্যালিডেশন | হোয়াইটলিস্ট: JSON, multipart, form-urlencoded | OK |
| ডেটাবেস prepared statements | PDO::ATTR_EMULATE_PREPARES = false | OK |
| DB রিড/রাইট সেপারেশন | মাস্টারে রাইট, রেপ্লিকায় রিড, sticky session | OK |
| অডিট লগিং | আলাদা অডিট DB, LogSanitizer সংবেদনশীল ফিল্ড রিড্যাক্ট করে | OK |
| মেইনটেন্যান্স মোড | হোয়াইটলিস্ট IP বাইপাস, বাকিরা 503 + Retry-After | OK |
| IP অটো-ব্যান | 60s-এ ৫টি ভায়োলেশন তারপর 15min ব্যান | OK |
| SQL strict mode | ডেটা ট্রানকেশন ও ইমপ্লিসিট টাইপ কনভার্সন প্রতিরোধ | OK |

---

## 2. ঘাটতি ও সুপারিশ

### ঘাটতি 1 (মাঝারি): CORS যেকোনো অরিজিন মিরর করে
**ফাইল**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

এটা ক্লায়েন্ট যা Origin পাঠায় তাই প্রতিধ্বনি করে, কার্যকরভাবে যেকোনো ওয়েবসাইটকে অথেনটিকেটেড ক্রস-অরিজিন রিকোয়েস্ট করার সুযোগ দেয়। সিকিউরিটি প্লাগইনের cors ডিটেক্টর কিছু হেডার ইনজেকশন ধরতে পারে, কিন্তু মিডলওয়্যার নিজে কোনো অরিজিন হোয়াইটলিস্ট দেয় না।

**ফিক্স**: হোয়াইটলিস্ট চেক যোগ করুন। অরিজিন অনুমোদিত লিস্টে না থাকলে `Access-Control-Allow-Origin: null` রেসপন্ড করুন বা হেডার সম্পূর্ণ বাদ দিন।

### ঘাটতি 2 (মাঝারি): সিকিউরিটি রেসপন্স হেডার নেই
service ও admin কোনোটাই সমালোচনামূলক HTTP সিকিউরিটি হেডার সেট করে না:

| হেডার | সুপারিশকৃত | বর্তমান |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | অনুপস্থিত |
| X-Content-Type-Options | nosniff | অনুপস্থিত |
| X-Frame-Options | DENY বা SAMEORIGIN | অনুপস্থিত |
| Content-Security-Policy | nonce/hash সহ পলিসি | অনুপস্থিত |
| X-XSS-Protection | 1; mode=block | অনুপস্থিত |
| Referrer-Policy | strict-origin-when-cross-origin | অনুপস্থিত |
| Permissions-Policy | ক্যামেরা/মাইক/জিওলোকেশন সীমিত করুন | অনুপস্থিত |

**সুপারিশ**: service ও admin উভয়ের মিডলওয়্যার স্ট্যাকে SecurityHeadersMiddleware যোগ করুন। উচ্চ-প্রভাব, কম-পরিশ্রমের ফিক্স।

### ঘাটতি 3 (কম): admin/config/security.php-এ রেট লিমিটিং নেই
**ফাইল**: `admin/config/security.php`

অ্যাডমিন প্যানেলে rate_limits কনফিগারেশন নেই। অ্যাডমিন WAF মিডলওয়্যার শুধু রিকোয়েস্ট সাইজ/Content-Type সীমা চেক করে। অ্যাডমিন লগইনে ব্রুট-ফোর্স আক্রমণ অ্যাপ্লিকেশন লেয়ারে রেট-লিমিটেড নয়।

**সুপারিশ**: admin/config/security.php-এ rate_limits যোগ করুন অথবা RateLimitMiddleware অ্যাডমিন রাউটে প্রয়োগ করুন।

### ঘাটতি 4 (কম): GeoBlockMiddleware ডেফাইন করা কিন্তু সক্রিয় নয়
**ফাইল**: `service/common/security/GeoBlockMiddleware.php`

মিডলওয়্যার আছে ও কার্যকর, কিন্তু `service/config/middleware.php`-এ রেজিস্টার করা হয়নি। জিও-ব্লকিং প্রয়োজন হলে স্ট্যাকে যোগ করুন।

### ঘাটতি 5 (তথ্য): ডুয়াল WAF ওভারহেড
WafMiddleware (কাস্টম, ৪০+ regex প্যাটার্ন) ও SecurityMiddleware (প্লাগইন, ৩১ ডিটেক্টর) দুটোই প্রতিটি রিকোয়েস্টে চলে। SQLi, XSS, কমান্ড ইনজেকশন, পাথ ট্রাভার্সাল, হেডার ইনজেকশন, SSRF, NoSQLi, ওপেন রিডাইরেক্টের জন্য তাদের প্যাটার্ন কভারেজ ব্যাপকভাবে ওভারল্যাপ করে।

**সুপারিশ**: সিকিউরিটি প্লাগইন বেশি কমপ্রিহেনসিভ (৩১ ডিটেক্টর বনাম ৮ ক্যাটাগরি) এবং IP ব্ল্যাকলিস্টিং, ফিল্ড হোয়াইটলিস্টিং, লগ ডিডুপ আছে। কাস্টম WafMiddleware সরিয়ে শুধু প্লাগইনের ওপর নির্ভর করার কথা বিবেচনা করুন, অথবা অন্ততপক্ষে WafMiddleware থেকে ওভারল্যাপিং প্যাটার্নগুলো সরান।

### ঘাটতি 6 (তথ্য): Validator ক্লাস ন্যূনতম
**ফাইল**: `service/common/helper/Validator.php`

শুধু required(), email(), minLength() আছে। অনুপস্থিত: ম্যাক্স লেন্থ, নিউমেরিক ভ্যালিডেশন, স্ট্রিং স্যানিটাইজেশন, URL ভ্যালিডেশন, প্যাটার্ন ম্যাচিং। ফ্রেমওয়ার্ক-লেভেল ভ্যালিডেশন ব্যবহার করে না এমন কন্ট্রোলাররা ম্যালফর্মড ইনপুট গ্রহণের ঝুঁকিতে।

---

## 3. সিকিউরিটি প্লাগইন — ৩১ ডিটেক্টর স্ট্যাটাস

| # | ডিটেক্টর | মোড | নোট |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF textarea কনটেন্টের সাথে মেলে, log-ই থাকতে হবে |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} Vue/Angular টেমপ্লেটের সাথে মেলে |
| 13 | nosql_injection | **log** | $ne/$gt শেল ভেরিয়েবল/LaTeX-এর সাথে মেলে |
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
| 27 | dns_rebinding | **log** | loopback Host (127.0.0.1/localhost) আর 403 দেয় না (ডেভেলপমেন্ট/টেস্টের স্বাভাবিক অবস্থা, শুধু রেকর্ড) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

সব ৩১টি ডিটেক্টর সক্রিয়। ৪টি log-only মোডে (ডকুমেন্টেড ফলস-পজিটিভ ঝুঁকি)। সঠিক কনফিগারেশন।

---

## 4. মিডলওয়্যার এক্সিকিউশন ক্রম (service)

```
1. VersionMiddleware          — API ভার্সন হেডার পার্সিং
2. CorsMiddleware              — CORS হেডার (খুবই অনুমতিপ্রদ, ঘাটতি 1 দেখুন)
3. ClientPlatformMiddleware    — OS/প্ল্যাটফর্ম ডিটেকশন
4. WafMiddleware               — কাস্টম WAF (৪০+ regex প্যাটার্ন)
5. SecurityMiddleware           — প্লাগইন WAF (৩১ ডিটেক্টর)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — ID ডিকোডিং
8. MaintenanceMiddleware       — মেইনটেন্যান্স মোড চেক
```

---

## 5. সামারি

| ক্যাটাগরি | গ্রেড | মূল সমস্যা |
|----------|-------|------------|
| আক্রমণ সনাক্তকরণ | **A** | ৩১ ডিটেক্টর, ডুয়াল WAF লেয়ার (রিডানড্যান্ট কিন্তু পূর্ণাঙ্গ) |
| অথেনটিকেশন | **A-** | bcrypt+MFA+JWT ব্ল্যাকলিস্ট, অ্যাডমিন রেট লিমিট নেই |
| ট্রান্সপোর্ট সিকিউরিটি | **B+** | AES-256-GCM ঠিক আছে, HSTS/CSP হেডার নেই |
| ইনপুট ভ্যালিডেশন | **B** | WAF আক্রমণ ধরে, অ্যাপ-লেভেল ভ্যালিডেশন পাতলা |
| অ্যাক্সেস কন্ট্রোল | **A-** | RBAC + session চেক, CORS খুব অনুমতিপ্রদ |
| অডিট/লগিং | **A** | আলাদা অডিট DB, সংবেদনশীল ফিল্ড রিড্যাকশন |
| রেট লিমিটিং | **B+** | service-এর জন্য ভালো কনফিগ, admin-এর জন্য নেই |

**প্রায়োরিটি ফিক্স ক্রম:**
1. সিকিউরিটি রেসপন্স হেডার যোগ (HSTS, CSP, X-Frame-Options ইত্যাদি)
2. CORS-কে হোয়াইটলিস্টে সীমিত করুন, যেকোনো অরিজিন মিরর নয়
3. অ্যাডমিন প্যানেলে রেট লিমিটিং যোগ
4. জিও-ব্লকিং প্রয়োজন হলে GeoBlockMiddleware সক্রিয়
5. প্রতি-রিকোয়েস্ট regex ওভারহেড কমাতে WAF লেয়ার একত্রীকরণ বিবেচনা

---

## 6. প্রয়োগ করা রিমেডিয়েশন (2026-08-04)

### ফিক্স হয়েছে
| ঘাটতি | ফিক্স | পরিবর্তিত ফাইল |
|-----|-----|---------------|
| CORS যেকোনো অরিজিন মিরর | `CORS_ALLOWED_ORIGINS` env ভেরিয়েবল সহ হোয়াইটলিস্ট মোড, `*.example.com` ওয়াইল্ডকার্ড ও `*` (সব) সাপোর্ট | `service/common/security/CorsMiddleware.php` |
| সিকিউরিটি হেডার নেই | service ও admin উভয় স্ট্যাকে নতুন `SecurityHeadersMiddleware`: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (env দিয়ে অপ্ট-ইন) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| অ্যাডমিনে রেট লিমিটিং নেই | অ্যাডমিন প্যানেলে `rate_limits` কনফিগ + `RateLimitMiddleware` যোগ (ডিফল্ট 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock সক্রিয় নয় | service মিডলওয়্যার স্ট্যাকে `GeoBlockMiddleware` রেজিস্টার | `service/config/middleware.php` |

### নতুন Env ভেরিয়েবল
| ভেরিয়েবল | উদ্দেশ্য | ডিফল্ট |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | কমা-বিচ্ছিন্ন অনুমোদিত অরিজিন | (খালি = সব বন্ধ) |
| `SECURITY_HSTS_ENABLE` | HSTS হেডার সক্রিয় | false |
| `SECURITY_HSTS_VALUE` | HSTS হেডার মান | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | X-Frame-Options মান | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | ব্লক করা দেশের কোড (ISO 3166-1) | (খালি = নিষ্ক্রিয়) |
| `GEOIP_DB_PATH` | GeoLite2 .mmdb পাথ | storage_path('geoip/GeoLite2-Country.mmdb') |

### আপডেটেড মিডলওয়্যার পাইপলাইন

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
