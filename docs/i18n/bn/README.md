# Cloud Platform — বিশ্বব্যাপী ক্লাউড রিসোর্স ট্রেডিং প্ল্যাটফর্ম

## Languages

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
  <img src="docs/diagrams/c.svg" alt="CloudPlatform প্রজেক্ট ম্যাসকট" width="220">
</p>

বিশ্বব্যাপী ব্যবহারকারীদের জন্য একটি ক্লাউড রিসোর্স ট্রেডিং প্ল্যাটফর্ম, যা সার্ভার (VM), IP ঠিকানা, ক্লাউড ডিস্ক, ডোমেইন, SSL সার্টিফিকেট, অবজেক্ট স্টোরেজ (S3), CDN অ্যাক্সিলারেশন ইত্যাদি পণ্যের অনলাইন ক্রয় ও স্বয়ংক্রিয় ডেলিভারি সমর্থন করে। নিজস্ব ফিজিক্যাল মেশিন Proxmox VE ভার্চুয়ালাইজেশনের মাধ্যমে ডেলিভারি করা হয়, সাথে তৃতীয় পক্ষের সাপ্লায়ারদের পণ্য বিক্রির জন্য যুক্ত হওয়ার সুযোগ রয়েছে। পে-অ্যাস-ইউ-গো বিলিং, রেফারেল ডিস্ট্রিবিউশন, GraphQL API এবং Prometheus/Grafana অবজারভেবিলিটি প্রদান করে।

## টেকনোলজি স্ট্যাক

| স্তর | প্রযুক্তি |
|------|------|
| ব্যাকএন্ড ফ্রেমওয়ার্ক | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| অ্যাডমিন প্যানেল | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| অথেনটিকেশন | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| ডিস্ট্রিবিউটেড প্রাইমারি কী | Snowflake আইডি ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| ID অবফাসকেশন | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| ট্রান্সমিশন এনক্রিপশন | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| ফিল্ড এনক্রিপশন | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| ফুল-টেক্সট সার্চ | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| দেশের পতাকা | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| ক্লিক ক্যাপচা | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| নিরাপত্তা সুরক্ষা | ৩১ ধরনের আক্রমণ সনাক্তকরণ ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| টেবিল এক্সপোর্ট | PhpSpreadsheet ^2.0 |
| পেমেন্ট SDK | Stripe PHP ^15.0 |
| এসএমএস SDK | Twilio PHP ^8.0 |
| পুশ SDK | Firebase PHP ^7.0 |
| কিউ | webman redis-queue |
| ডেটাবেস | MySQL 8.0 (প্রধান DB + অডিট DB ডুয়াল কানেকশন) |
| সার্চ ইঞ্জিন | Elasticsearch 8.x |
| ভার্চুয়ালাইজেশন | Proxmox VE (Rust kvm-server gRPC চ্যানেল, e-cat/etcd রেজিস্ট্রেশন) |
| ক্লায়েন্ট | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| অবজেক্ট স্টোরেজ | AWS S3 SDK PHP ^3.300 |
| অবজারভেবিলিটি | Prometheus + Grafana (প্রি-কনফিগারড ড্যাশবোর্ড) |
| মাল্টিল্যাঙ্গুয়াল | i18n ৭টি ভাষা (চীনা/ইংরেজি/জাপানি/কোরিয়ান/জার্মান/ফ্রেঞ্চ/স্প্যানিশ) |
| ডিপ্লয়মেন্ট | Docker Compose এক-ক্লিক স্টার্ট |

## সিস্টেম আর্কিটেকচার

![সিস্টেম আর্কিটেকচার](docs/diagrams/system-architecture-zh.svg)

## কোর বিজনেস প্রসেস

ব্যবহারকারী রেজিস্ট্রেশন থেকে রিসোর্স ডেলিভারি পর্যন্ত সম্পূর্ণ এন্ড-টু-এন্ড বিজনেস প্রসেস, যার মধ্যে রয়েছে পণ্য নির্বাচন, অর্ডার, পেমেন্ট, স্বয়ংক্রিয় ডেলিভারি, আফটার-সেলস ম্যানেজমেন্ট এবং রিনিউয়াল চক্র।

![কোর বিজনেস প্রসেস](docs/diagrams/business-flowchart-zh.svg)

## মাল্টি-কারেন্সি সেটেলমেন্ট

সিস্টেম নেটিভভাবে মাল্টি-কারেন্সি প্রাইসিং, পেমেন্ট ও সেটেলমেন্ট সমর্থন করে, যা ব্যবহারকারীর কারেন্সি সেটিং, রিজিওনাল প্রাইসিং, এক্সচেঞ্জ রেট স্ন্যাপশট থেকে পেমেন্ট কালেকশন, ব্যালেন্স ক্রেডিট ও সাপ্লায়ার সেটেলমেন্ট পর্যন্ত সম্পূর্ণ চেইন কভার করে।

![মাল্টি-কারেন্সি সেটেলমেন্ট ফ্লো](docs/diagrams/currency-settlement-zh.svg)

**1. মাল্টি-কারেন্সি ব্যালেন্স অ্যাকাউন্ট**

`user_balances` কারেন্সি অনুযায়ী `(user_id, currency)` কমান্ডার হিসাব করে (ইউনিক ইনডেক্স `uk_user_currency`)। রেজিস্ট্রেশনের সময় ডিফল্টভাবে USD + CNY দুটি কারেন্সি অ্যাকাউন্ট তৈরি হয়; ব্যালেন্স ও ফ্রোজেন ব্যালেন্স কারেন্সি অনুযায়ী আলাদাভাবে ম্যানেজ করা হয়, এবং Stripe সমর্থিত যেকোনো কারেন্সিতে সম্প্রসারণযোগ্য।

**2. মাল্টি-কারেন্সি রিজিওনাল প্রাইসিং**

`product_regions` একই SKU-এর জন্য একই রিজিয়নে একাধিক কারেন্সিতে প্রাইসিং সমর্থন করে (ইউনিক ইনডেক্স `uk_sku_region_currency`)। ফ্রন্টএন্ড ব্যবহারকারীর পছন্দের কারেন্সি অনুযায়ী মূল্য দেখায়; অর্ডারের সময় `OrderService` `(sku_id, region_id, currency)` অনুযায়ী সঠিক মূল্য নেয়।

**3. এক্সচেঞ্জ রেট সিস্টেম**

`ExchangeRateSync` ক্রন টাস্ক exchangerate-api থেকে এক্সচেঞ্জ রেট সিঙ্ক করে Redis-এ লেখে (৩০ মিনিট TTL ক্যাশ)। প্রতিটি অর্ডারে অর্ডার সময়ের `exchange_rate` স্ন্যাপশট রেকর্ড করা হয়, যাতে পরবর্তী সেটেলমেন্ট ট্রেসযোগ্য হয়।

**4. মাল্টি-কারেন্সি পেমেন্ট**

`payment_channels.currency_support` প্রতিটি পেমেন্ট চ্যানেলের সমর্থিত কারেন্সি হোয়াইটলিস্ট ঘোষণা করে; `PaymentRouter` কারেন্সি / অ্যামাউন্ট রেঞ্জ / দৃশ্যমান রিজিয়ন অনুযায়ী উপলব্ধ চ্যানেলগুলো ডাইনামিকভাবে ফিল্টার করে। Stripe PaymentIntent সরাসরি অর্ডারের কারেন্সিতে পেমেন্ট সংগ্রহ করে, ১৬টি জিরো-ডেসিমেল কারেন্সির (JPY / KRW / VND ইত্যাদি) ডেসিমেল প্লেস হ্যান্ডলিং অন্তর্নির্মিত, এবং Webhook কলব্যাক অ্যামাউন্ট ও কারেন্সি সামঞ্জস্য যাচাই করে।

**5. সেটেলমেন্ট ও রিপোর্ট**

পেমেন্ট ট্রানজেকশন (`payment_transactions`), সাপ্লায়ার সেটেলমেন্ট (`supplier_settlements`) এবং রেভিনিউ রিপোর্ট সবকটিতে কারেন্সি ও এক্সচেঞ্জ রেট ফিল্ড সংরক্ষিত থাকে, কারেন্সি অনুযায়ী স্ট্যাটিস্টিকস ও সামারি করা হয়।

## ফিচার মডিউল ওভারভিউ

সিস্টেম চার-স্তর আর্কিটেকচারে সংগঠিত: ক্লায়েন্ট লেয়ার (৬টি প্ল্যাটফর্ম ইন্টিগ্রেশন), API গেটওয়ে লেয়ার (১২টি মিডলওয়্যার), বিজনেস সার্ভিস লেয়ার (২০+ ফিচার মডিউল), ইনফ্রাস্ট্রাকচার লেয়ার (৮টি কোর কম্পোনেন্ট)।

![ফিচার মডিউল ওভারভিউ](docs/diagrams/module-overview-zh.svg)

## রিসোর্স লাইফসাইকেল

রিসোর্স তৈরি থেকে টার্মিনেশন পর্যন্ত ৬টি স্টেট অতিক্রম করে, যা ৮টি লাইফসাইকেল ইভেন্ট দ্বারা চালিত হয়; স্বয়ংক্রিয় ডেলিভারি, সাসপেন্ড-রিজিউম, এক্সপায়ারি রিমাইন্ডার এবং ডেস্ট্রাকশন ক্লিনআপ সমর্থন করে।

![রিসোর্স লাইফসাইকেল](docs/diagrams/resource-lifecycle-zh.svg)

## ডকুমেন্টেশন নেভিগেশন

| ডকুমেন্ট | বিবরণ |
|------|------|
| [আর্কিটেকচার ডিজাইন ডকুমেন্ট](docs/architecture.md) | সিস্টেম আর্কিটেকচার, কম্পোনেন্ট সম্পর্ক, মিডলওয়্যার পাইপলাইন, সিকিউরিটি লেয়ারিং, ডেটা আর্কিটেকচার, ডিপ্লয়মেন্ট টপোলজি |
| [ফিচার ডিজাইন ডকুমেন্ট](docs/features.md) | ২১টি মডিউলের বিস্তারিত ফিচার ডিজাইন, ফ্লোচার্ট, ডেটা মডেল ও ইন্টারঅ্যাকশন বিবরণ সহ |
| [API ইন্টারফেস ডকুমেন্ট](docs/api-reference.md) | ২০০+ এন্ডপয়েন্টের সম্পূর্ণ রেফারেন্স, মডিউল অনুযায়ী গ্রুপকৃত, রিকোয়েস্ট/রেসপন্স উদাহরণ ও এরর কোড সহ |
| [API অনলাইন ডকুমেন্ট (service)](http://localhost:8787/apidoc) | hg/apidoc অটো-জেনারেটেড, ফিচার অনুযায়ী গ্রুপকৃত, অনলাইন ডিবাগিং সমর্থন করে |
| [API অনলাইন ডকুমেন্ট (admin)](http://localhost:8788/apidoc) | hg/apidoc অটো-জেনারেটেড, ৫৪টি কন্ট্রোলার ১৩টি ফিচার গ্রুপে |
| [অ্যাডমিন প্যানেল ডিজাইন](docs/admin-design.md) | Admin প্যানেল আর্কিটেকচার, প্যাকেজ ইন্টিগ্রেশন, ACL পারমিশন, টেস্ট স্যুট |
| [সাপ্লায়ার API ডকুমেন্ট](docs/supplier-api.md) | সাপ্লায়ার API রেফারেন্স (ইন্টারনাল + এক্সটার্নাল), SDK উদাহরণ |
| [ডিপ্লয়মেন্ট চেকলিস্ট](docs/deployment.md) | সার্ভার কনফিগারেশন, এনভায়রনমেন্ট ভেরিয়েবল, Nginx, HTTPS, ক্রন টাস্ক |
| [রিভিউ রিপোর্ট](docs/review-report-2026-08-04.md) | ইকোসিস্টেম এক্সটেনশন রিভিউ রিপোর্ট, স্ট্যাটিস্টিকস, ইস্যু ট্র্যাকিং ও এক্সটেনশন সুপারিশ সহ |
| [ভার্সন তুলনা](docs/editions.md) | লাইট/স্ট্যান্ডার্ড/প্রো এডিশনের ফিচার, ডিজাইন ও আর্কিটেকচার তুলনা |

## ডিরেক্টরি স্ট্রাকচার

```
cloud-php/
├── .claude/                    # Claude Code কনফিগারেশন (settings / skills)
├── .github/workflows/          # CI/CD পাইপলাইন (সিনট্যাক্স চেক + ডুয়াল PHPUnit)
├── admin/                      # অ্যাডমিন প্যানেল (স্বতন্ত্র webman ইন্সট্যান্স)
│   ├── app/                    # প্লাগইন সোর্স (PSR-4: app\)
│   │   ├── bootstrap/          # প্রসেস বুটস্ট্র্যাপ (Snowflake / Encryptable / Encryption)
│   │   ├── command/            # কনসোল কমান্ড (Migrate / Rollback / Status)
│   │   ├── common/             # ইউটিলিটি ক্লাস (Auth / Tree / Layui / Util / ExcelExport / Migration)
│   │   ├── controller/         # ৫৪টি কন্ট্রোলার ফাইল (Base / Crud বেস ক্লাস + বিজনেস CRUD)
│   │   ├── exception/          # এক্সেপশন হ্যান্ডলিং
│   │   ├── middleware/          # অ্যাক্সেস কন্ট্রোল মিডলওয়্যার (WafMiddleware + AccessControl)
│   │   ├── model/              # ৪৬টি Eloquent মডেল (Base ক্লাসে Snowflake PK + Encryptable)
│   │   ├── view/               # ভিউ টেমপ্লেট (Layui অ্যাডমিন প্যানেল)
│   │   └── functions.php       # গ্লোবাল হেল্পার ফাংশন (hashids / encrypt / decrypt)
│   ├── api/                    # এক্সটার্নাল ইন্টারফেস (PSR-4: plugin\admin\api)
│   │   ├── Auth.php            # অথেনটিকেশন ইন্টারফেস
│   │   ├── Menu.php            # মেনু ইন্টারফেস
│   │   ├── Install.php         # ইনস্টলেশন ইন্টারফেস
│   │   └── Middleware.php      # মিডলওয়্যার ইন্টারফেস
│   ├── config/                 # অ্যাপ্লিকেশন কনফিগারেশন
│   │   ├── plugin/erikwang2013/ # ৬টি erikwang2013 প্যাকেজ কনফিগারেশন
│   │   │   ├── snowflake-php/  # স্নোফ্লেক আইডি জেনারেশন
│   │   │   ├── hashids/        # ID অবফাসকেশন
│   │   │   ├── encryptable/    # ফিল্ড-লেভেল এনক্রিপশন
│   │   │   ├── encryption/     # ট্রান্সমিশন এনক্রিপশন
│   │   │   ├── webman-scout/   # Elasticsearch সিঙ্ক
│   │   │   └── season/         # দেশের পতাকা
│   │   ├── route.php           # রাউট ডেফিনিশন
│   │   ├── middleware.php       # মিডলওয়্যার কনফিগারেশন
│   │   ├── database.php        # ডেটাবেস কানেকশন
│   │   └── ...                 # ১৮টি কনফিগারেশন ফাইল
│   ├── database/migrations/    # ডেটাবেস মাইগ্রেশন ফাইল
│   ├── tests/                  # ইউনিট টেস্ট (PHPUnit 11, 286 tests / 962 assertions)
│   │   ├── HashidsTest.php     # hashids এনকোড/ডিকোড (২১টি টেস্ট)
│   │   ├── BaseJsonTest.php    # Base::json() ID এনকোডিং (১৩টি টেস্ট)
│   │   ├── CrudHashidsTest.php # Crud ইনপুট ডিকোডিং (১৪টি টেস্ট)
│   │   ├── TreeTest.php        # ট্রি স্ট্রাকচার (১৯টি টেস্ট)
│   │   ├── AccessControlMiddlewareTest.php # RBAC অ্যাক্সেস কন্ট্রোল
│   │   ├── AdminControllersTest.php        # কন্ট্রোলার রিগ্রেশন
│   │   └── support/            # টেস্ট হেল্পার ক্লাস
│   ├── public/                 # ডকুমেন্ট রুট (স্ট্যাটিক রিসোর্স)
│   ├── vendor/                 # Composer ডিপেন্ডেন্সি
│   ├── .env.example            # এনভায়রনমেন্ট ভেরিয়েবল টেমপ্লেট
│   ├── composer.json           # ডিপেন্ডেন্সি ডিক্লারেশন
│   ├── generate.php            # কোড জেনারেটর
│   ├── phpunit.xml             # PHPUnit কনফিগারেশন
│   └── start.php               # স্টার্ট এন্ট্রি
├── service/                    # ব্যাকএন্ড সার্ভিস (স্বতন্ত্র webman ইন্সট্যান্স)
│   ├── app/                    # বিজনেস মডিউল (PSR-4: App\)，প্রতিটি মডিউলে Controller / Model / Service ইত্যাদি লেয়ারিং
│   │   ├── admin/controller/   # অ্যাডমিন প্যানেল API (১৫টি কন্ট্রোলার: Dashboard / User / Product / Order / Payment / Supplier / Coupon / Invoice / Domain / Webhook ইত্যাদি)
│   │   ├── affiliate/          # অ্যাফিলিয়েট কমিশন / প্রমোশন রেভিনিউ শেয়ার (Controller / Listener / Model / Service)
│   │   ├── billing/            # ইউসেজ বিলিং / বিল (Cron / Service)
│   │   ├── captcha/controller/ # ক্লিক ক্যাপচা
│   │   ├── cdn/                # CDN রিসোর্স হোস্টিং (Controller / Model / Provider / Service)
│   │   ├── command/            # কনসোল কমান্ড (Migrate / Rollback / Status / DbBackup)
│   │   ├── controller/         # কমন কন্ট্রোলার (Health / Status / Help / Upload)
│   │   ├── cron/               # ক্রন টাস্ক (CronRunner শিডিউলার + ExchangeRateSync / PaymentReconcile / SupplierSettlement / ExpirationCheck / SslCertificateCheck)
│   │   ├── domain/             # ডোমেইন রেজিস্ট্রেশন / DNS ম্যানেজমেন্ট (Controller / Model / Service)
│   │   ├── graphql/            # GraphQL API (Mutation / Query / Schema)
│   │   ├── grpc/               # kvm-server gRPC ক্লায়েন্ট + etcd রেজিস্ট্রেশন (KvmClient / EtcdRegistry)
│   │   ├── model/              # কমন মডেল (HelpArticle / Role / Permission)
│   │   ├── monitor/            # রিসোর্স মনিটরিং / অ্যালার্ট (Controller / Cron / Model / Service)
│   │   ├── notification/       # মেসেজ নোটিফিকেশন (Controller / Model / Queue / Service)
│   │   ├── order/              # কার্ট / অর্ডার / কুপন / ইনভয়েস (Controller / Model / Service)
│   │   ├── payment/            # পেমেন্ট রাউটিং / Stripe চ্যানেল (Controller / Event / Model / Service)
│   │   ├── product/            # পণ্য / SKU / রিজিওনাল প্রাইসিং / রিভিউ (Controller / Model / Service)
│   │   ├── provisioning/       # রিসোর্স ডেলিভারি ইঞ্জিন (Controller / Event / Listener / Model / Provider / Queue / Service)
│   │   ├── report/             # রেভিনিউ / সাপ্লায়ার / রিজিয়ন রিপোর্ট (Controller / Service)
│   │   ├── ssl/                # SSL সার্টিফিকেট ইস্যু / ম্যানেজমেন্ট (Controller / Model / Service)
│   │   ├── storage/            # অবজেক্ট স্টোরেজ রিসোর্স (Controller / Model / Provider / Service)
│   │   ├── supplier/           # সাপ্লায়ার অনবোর্ডিং / সেটেলমেন্ট / উইথড্রয়াল + এক্সটার্নাল API (Controller / Model / Service)
│   │   ├── ticket/             # টিকেট সিস্টেম (Controller / Event / Listener / Model / Service)
│   │   ├── user/               # ইউজার / অথেনটিকেশন / KYC / ব্যালেন্স / ঠিকানা (Controller / Model / Service)
│   │   ├── webhook/            # Webhook মেসেজ কিউ (Queue)
│   │   └── websocket/          # WebSocket সার্ভার + ইভেন্ট লিসেনার
│   ├── common/                 # কমন লাইব্রেরি (PSR-4: Common\)
│   │   ├── auth/middleware/     # AuthMiddleware / AdminRoleMiddleware / RbacMiddleware / RoleMiddleware / SupplierApiKeyMiddleware
│   │   ├── captcha/            # ক্লিক ক্যাপচা সার্ভিস
│   │   ├── confirmation/       # সেকেন্ডারি কনফার্মেশন মিডলওয়্যার (পাসওয়ার্ড ভেরিফিকেশন)
│   │   ├── encryption/middleware/ # AES-256-GCM ট্রান্সমিশন এনক্রিপশন মিডলওয়্যার
│   │   ├── hashid/middleware/   # Hashids রিকোয়েস্ট অটো-ডিকোড মিডলওয়্যার + এনকোড/ডিকোড সার্ভিস
│   │   ├── helper/             # Response ফরম্যাটিং (অটো hashid এনকোডিং)
│   │   ├── http/               # HTTP ক্লায়েন্ট টুল (ApiRequest)
│   │   ├── i18n/middleware/     # মাল্টিল্যাঙ্গুয়াল মিডলওয়্যার (Locale)
│   │   ├── security/           # CORS / WAF / রেট লিমিট / জিও ব্লক / মেইনটেন্যান্স মোড / অডিট লগ
│   │   ├── snowflake/          # স্নোফ্লেক আইডি জেনারেশন সার্ভিস / Eloquent HasSnowflakeId Trait
│   │   ├── version/middleware/  # API ভার্সন মিডলওয়্যার (URL পাথে ভার্সন ভ্যালিডেশন)
│   │   ├── clientplatform/middleware/  # ক্লায়েন্ট প্ল্যাটফর্ম মিডলওয়্যার (X-Client-Platform হেডার ডিটেকশন)
│   │   ├── feature/            # Feature Flags ফিচার সুইচ সার্ভিস
│   │   └── webhook/            # Webhook ইভেন্ট ডিসপ্যাচার
│   ├── config/                 # ১৭টি কনফিগারেশন ফাইল (route / middleware / database / redis / cron / auth / security / i18n / ...)
│   │   └── plugin/             # প্লাগইন কনফিগারেশন
│   │       ├── erikwang2013/   # encryptable / hashids / jwt / poster / season / webman-scout
│   │       └── webman/         # event / redis-queue
│   ├── database/migrations/    # ডেটাবেস মাইগ্রেশন ফাইল (৩৭টি মাইগ্রেশন)
│   ├── i18n/                   # মাল্টিল্যাঙ্গুয়াল রিসোর্স (en-US / zh-CN)
│   ├── support/                # Bootstrap গাইড (Eloquent / Redis / Event / এনক্রিপশন / স্নোফ্লেক ID / Hashids / Scout / MigrationRunner)
│   ├── tests/                  # ইউনিট টেস্ট (PHPUnit 10, 672 tests / 1632 assertions)
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
│   │   ├── bootstrap.php       # টেস্ট বুটস্ট্র্যাপ
│   │   └── TestCase.php        # টেস্ট বেস ক্লাস
│   ├── runtime/                # রানটাইম ফাইল (লগ / ক্যাশ)
│   ├── vendor/                 # Composer ডিপেন্ডেন্সি
│   ├── .env.example            # এনভায়রনমেন্ট ভেরিয়েবল টেমপ্লেট
│   ├── .env                    # লোকাল এনভায়রনমেন্ট ভেরিয়েবল (gitignore)
│   ├── composer.json           # ডিপেন্ডেন্সি ডিক্লারেশন
│   ├── phpunit.xml             # PHPUnit কনফিগারেশন
│   └── start.php               # স্টার্ট এন্ট্রি
├── apps/
│   ├── flutter/                # Flutter ক্লায়েন্ট (iOS / macOS / Windows / Linux / Web)
│   │   ├── lib/                # Dart সোর্স (core / features)
│   │   ├── ios/                # iOS প্রজেক্ট
│   │   ├── macos/              # macOS প্রজেক্ট
│   │   ├── windows/            # Windows প্রজেক্ট
│   │   ├── linux/              # Linux প্রজেক্ট
│   │   ├── web/                # Web প্রজেক্ট
│   │   ├── test/               # Flutter টেস্ট
│   │   ├── pubspec.yaml        # ডিপেন্ডেন্সি ডিক্লারেশন
│   │   └── analysis_options.yaml # Dart স্ট্যাটিক অ্যানালাইসিস কনফিগারেশন
│   └── harmonyos/              # HarmonyOS ক্লায়েন্ট স্কেলটন
│       └── entry/src/          # ArkTS সোর্স
├── docker/                     # Docker ডিপ্লয়মেন্ট
│   ├── Dockerfile              # PHP 8.2 ইমেজ
│   ├── docker-compose.yml      # সার্ভিস অর্কেস্ট্রেশন
│   ├── nginx.conf              # Nginx কনফিগারেশন
│   └── supervisor.conf         # Supervisor প্রসেস ডেমন
├── infrastructure/             # Rust ইনফ্রাস্ট্রাকচার (e-cat workspace)
│   ├── kvm-server/             # নিজস্ব ক্লাউড সার্ভিস: VM প্রোভিশনিং gRPC সার্ভিস (:50051, etcd রেজিস্ট্রেশন)
│   │   ├── src/                # main / grpc / driver (সিমুলেটেড ড্রাইভার, libvirt Phase 2-এ)
│   │   ├── tests/              # ইন্টিগ্রেশন টেস্ট
│   │   └── Cargo.toml          # e-cat workspace সদস্য ডিক্লারেশন
│   └── ecat-*/                 # e-cat ইনফ্রাস্ট্রাকচার crate (transport-grpc / registry-etcd / protos / config / data ইত্যাদি)
├── docs/                       # ডকুমেন্টেশন
│   ├── admin-design.md         # অ্যাডমিন প্যানেল ডিজাইন ডকুমেন্ট
│   ├── supplier-api.md         # সাপ্লায়ার API ডকুমেন্ট
│   ├── deployment.md           # ডিপ্লয়মেন্ট চেকলিস্ট
│   ├── api-test.sh             # API স্মোক টেস্ট স্ক্রিপ্ট
│   ├── database.sql            # ডেটাবেস DDL
│   ├── alipay.png / weixinpay.png  # ডোনেশন QR কোড
│   ├── diagrams/               # ১৮টি SVG আর্কিটেকচার ডায়াগ্রাম (সিস্টেম আর্কিটেকচার / সিকিউরিটি পাইপলাইন / ER ডায়াগ্রাম / বিজনেস ফ্লো / মাল্টি-কারেন্সি সেটেলমেন্ট ইত্যাদি)
│   ├── test-reports/           # টেস্ট রিপোর্ট (PHPUnit / Rust / API / UI + পেজ স্ক্রিনশট)
│   └── superpowers/            # ডিজাইন স্পেসিফিকেশন ও ইমপ্লিমেন্টেশন প্ল্যান
│       ├── specs/              # সিস্টেম ডিজাইন স্পেসিফিকেশন ডকুমেন্ট
│       └── plans/              # Phase 0~3 স্টেজড ইমপ্লিমেন্টেশন প্ল্যান
├── scripts/                     # অপারেশনাল স্ক্রিপ্ট (push-release.sh পুশ রিলিজ রুল: ভার্সন ইনক্রিমেন্ট + tag)
├── tests/k6/                    # k6 লোড টেস্ট স্ক্রিপ্ট (স্মোক / প্রোডাক্ট / কনকারেন্সি)
├── install.php                 # ওয়ান-ক্লিক ইনস্টলেশন উইজার্ড এন্ট্রি
├── install/                    # ইনস্টলেশন উইজার্ড পেজ
│   └── index.php               # উইজার্ড ওয়েব অ্যাপ্লিকেশন
├── install.sql                 # ইউনিফাইড ডেটাবেস DDL (৪৬টি টেবিল)
├── .gitignore
├── README.md                   # প্রজেক্ট ডকুমেন্টেশন (চীনা)
└── README_EN.md                # প্রজেক্ট ডকুমেন্টেশন (ইংরেজি)
```

## কুইক স্টার্ট

### এনভায়রনমেন্ট প্রয়োজনীয়তা

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### ওয়ান-ক্লিক ইনস্টলেশন (সুপারিশকৃত)

প্রজেক্ট একটি ওয়েব ইনস্টলেশন উইজার্ড প্রদান করে, যার মাধ্যমে ব্রাউজারে সম্পূর্ণ কনফিগারেশন সম্পন্ন করা যায়:

```bash
# 1. ডিপেন্ডেন্সি ইনস্টল করুন
cd service && composer install && cd ../admin && composer install && cd ..

# 2. ইনস্টলেশন উইজার্ড চালু করুন
php install.php
# ব্রাউজার খুলে http://localhost:8888 ভিজিট করুন

# 3. উইজার্ডের নির্দেশনা অনুযায়ী সম্পন্ন করুন:
#    - এনভায়রনমেন্ট চেক
#    - ডেটাবেস কনফিগারেশন (হোস্ট, পোর্ট, ডেটাবেস নাম, ইউজারনেম, পাসওয়ার্ড)
#    - অ্যাডমিন অ্যাকাউন্ট সেটআপ (ইউজারনেম, পাসওয়ার্ড, ইমেইল)
#    - ওয়ান-ক্লিক ইনস্টলেশন (টেবিল তৈরি + কনফিগারেশন লেখা)
```

ইনস্টলেশন শেষ হলে, উইজার্ড স্বয়ংক্রিয়ভাবে:
- সম্পূর্ণ ৪৬টি ডেটাবেস টেবিল তৈরি করে (wa_* অ্যাডমিন টেবিল + প্রিফিক্সবিহীন বিজনেস টেবিল)
- সুপার অ্যাডমিন রোল ও অ্যাকাউন্ট তৈরি করে
- `service/.env` এবং `admin/.env` কনফিগারেশন ফাইল জেনারেট করে (অটো-জেনারেটেড JWT/এনক্রিপশন কী সহ)

### ম্যানুয়াল ইনস্টলেশন

```bash
cd service

# 1. ডিপেন্ডেন্সি ইনস্টল করুন
composer install

# 2. এনভায়রনমেন্ট ভেরিয়েবল কনফিগার করুন
cp .env.example .env
# .env এডিট করে ডেটাবেস পাসওয়ার্ড, JWT কী, এনক্রিপশন কী ইত্যাদি পূরণ করুন
# ENCRYPTION_MASTER_KEY জেনারেট করুন: openssl rand -base64 32
# ENCRYPTION_KEY জেনারেট করুন: echo -n "$(openssl rand -base64 16)" | base64 -w0
# JWT_SECRET_KEY জেনারেট করুন: openssl rand -base64 32

# 3. ডেটাবেস তৈরি করে ইমপোর্ট করুন
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cloud_platform < ../install.sql

# 4. সার্ভিস চালু করুন (ডেভেলপমেন্ট মোড)
php start.php start
# http://localhost:8787 ভিজিট করুন
```

### Docker ডিপ্লয়মেন্ট

```bash
# প্রজেক্ট রুট থেকে
cp service/.env.example .env
# .env এডিট করে বিভিন্ন কী পূরণ করুন

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### অ্যাডমিন প্যানেল

```bash
cd admin

# 1. ডিপেন্ডেন্সি ইনস্টল করুন
composer install

# 2. এনভায়রনমেন্ট ভেরিয়েবল কনফিগার করুন
cp .env.example .env
# ওয়ান-ক্লিক ইনস্টলেশন উইজার্ড ব্যবহার করলে এই ফাইল স্বয়ংক্রিয়ভাবে তৈরি হয়েছে

# 3. সার্ভিস চালু করুন (ডেভেলপমেন্ট মোড)
php start.php start
# http://localhost:8787/app/admin ভিজিট করুন
```

### ডেমন মোড

```bash
php start.php start -d          # শুরু করুন
php start.php status            # স্ট্যাটাস দেখুন
php start.php restart           # রিস্টার্ট করুন
php start.php stop              # বন্ধ করুন
```

## ব্যবহার নির্দেশিকা

### লগইন

- **ইউজার পোর্টাল**: API সার্ভিস (ডিফল্ট `http://localhost:8787`) খুলে অ্যাকাউন্ট তৈরি করে লগইন করুন। Google / Apple OAuth এবং TOTP টু-স্টেপ যাচাইকরণ সমর্থিত
- **অ্যাডমিন প্যানেল**: ব্রাউজারে `http://localhost:8787/app/admin` খুলুন (প্যানেলটি আলাদা ইনস্ট্যান্স, পোর্ট 8788) এবং ইনস্টলার তৈরি করা অ্যাডমিন অ্যাকাউন্ট দিয়ে লগইন করুন

### অ্যাডমিনের সাধারণ বৈশিষ্ট্য

- **ড্যাশবোর্ড**: আজকের অর্ডার / রাজস্ব / নতুন ইউজার / সক্রিয় রিসোর্স পরিসংখ্যান, ৩০ দিনের রাজস্ব প্রবণতা চার্ট, PDF এক্সপোর্ট
- **রিপোর্ট সেন্টার**: অর্ডার রিপোর্ট, প্রোডাক্ট র্যাংকিং, চ্যানেল পরিসংখ্যান, ব্যবহারকারী বৃদ্ধি, Excel এক্সপোর্ট
- **দৈনন্দিন ব্যবস্থাপনা**: ইউজার / প্রোডাক্ট / অর্ডার / সাপ্লায়ার / টিকিট / ডোমেইন / CDN, KYC পর্যালোচনা, রিফান্ড, সেটেলমেন্ট ও উইথড্রয়াল অনুমোদন
- **সিস্টেম কনফিগারেশন**: পেমেন্ট চ্যানেল, CDN অ্যাকাউন্ট, ওয়েবহুক, নোটিফিকেশন টেমপ্লেট, হেল্প আর্টিকেল, অডিট লগ

### ক্লায়েন্ট বিল্ড

- **Flutter ক্লায়েন্ট** (`apps/flutter/`): iOS / Android / Web / Linux / macOS / Windows সমর্থন। `flutter pub get` ডিপেন্ডেন্সির জন্য, `flutter run` ডিবাগিংয়ের জন্য, `flutter build apk` / `flutter build ios` / `flutter build web` প্যাকেজিংয়ের জন্য
- **HarmonyOS ক্লায়েন্ট** (`apps/harmonyos/`): ArkTS নেটিভ অ্যাপ — DevEco Studio দিয়ে `entry` প্রজেক্ট খুলে বিল্ড ও রান করুন

## API ওভারভিউ

ইন্টারফেসগুলো মডিউল অনুযায়ী গ্রুপকৃত, রিকোয়েস্ট/রেসপন্স উদাহরণ ও এরর কোড সহ: [API ওভারভিউ](docs/api-overview.md) (নির্বাচিত) · [API ইন্টারফেস ডকুমেন্ট](docs/api-reference.md) (২০০+ এন্ডপয়েন্টের সম্পূর্ণ রেফারেন্স) · [অনলাইন ডিবাগিং](http://localhost:8787/apidoc)

## অ্যাডমিন প্যানেল আর্কিটেকচার

### টেকনিক্যাল ইন্টিগ্রেশন

অ্যাডমিন প্যানেল একটি স্বতন্ত্র webman ইন্সট্যান্স, যা ৭টি erikwang2013 প্যাকেজ ইন্টিগ্রেট করে:

| প্যাকেজ | ব্যবহার | বাস্তবায়ন পদ্ধতি |
|---|------|---------|
| snowflake-php | ৬৪-বিট ডিস্ট্রিবিউটেড প্রাইমারি কী | `Base::boot()` creating ইভেন্টে অটো-জেনারেশন |
| hashids | API ID অবফাসকেশন | `Base::json()` রেসপন্স এনকোডিং, `Crud::selectInput/updateInput/deleteInput` রিকোয়েস্ট ডিকোডিং |
| encryptable | ডেটাবেস ফিল্ড এনক্রিপশন | Eloquent `Encryptable` cast, Admin (password/email/mobile), User (৬টি ফিল্ড) ট্রান্সপারেন্ট এনক্রিপ্ট/ডিক্রিপ্ট |
| encryption | API ট্রান্সমিশন এনক্রিপশন | রিজার্ভড `encrypt_data()`/`decrypt_data()` হেল্পার ফাংশন |
| webman-scout | ES ফুল-টেক্সট সার্চ | User মডেল `Searchable` trait, অটো-সিঙ্ক ইনডেক্স |
| season | দেশের পতাকা emoji | `country_season_flag()` গ্লোবাল হেল্পার ফাংশন |
| poster-php | ক্লিক ক্যাপচা | `CaptchaPlugin` Bootstrap, `captcha_create()`/`captcha_verify()` গ্লোবাল ফাংশন |

### সিকিউরিটি লেয়ারিং

```
রিকোয়েস্ট → Hashids ডিকোড (Crud::selectInput/updateInput/deleteInput)
  → ACL অথোরাইজেশন (api/Auth.php, কন্ট্রোলার noNeedLogin/noNeedAuth)
  → বিজনেস প্রসেসিং (CRUD / মডেল ইভেন্ট)
  → Encryptable ফিল্ড এনক্রিপশন (Eloquent casts set)
  → ডেটাবেস রাইট
রেসপন্স ← Hashids এনকোড (Base::json → hashids_encode_ids)

লগইন/রেজিস্ট্রেশন: Captcha ভেরিফিকেশন → Auth → বিজনেস প্রসেসিং
```

### ডেটা ফ্লো

- **রাইট পাথ**: রিকোয়েস্ট ID (hashid) → int-এ ডিকোড → CRUD অপারেশন → Snowflake নতুন ID জেনারেশন → Encryptable সংবেদনশীল ফিল্ড এনক্রিপশন → DB
- **রিড পাথ**: DB → Encryptable ডিক্রিপশন → Hashids এনকোডিং ID → JSON রেসপন্স

### টেস্ট কভারেজ

```
phpunit.xml (PHPUnit 11, 286 tests / 962 assertions)
├── HashidsTest              (21 tests) encode/decode/encode_ids
├── BaseJsonTest             (13 tests) Base::json/success/fail এনকোডিং
├── CrudHashidsTest          (14 tests) Crud ইনপুট ডিকোডিং (select/update/delete)
├── TreeTest                 (19 tests) ট্রি স্ট্রাকচার / ডিসেন্ড্যান্ট / অ্যানসেস্টর / অরফান নোড
├── AccessControlMiddlewareTest (7 tests) আনঅথেনটিকেটেড 401 / 403 পেজ / পাস
├── AdminControllersTest     (data provider) ৪৮টি কন্ট্রোলার অ্যাসেম্বলি / CRUD সারফেস / GET ভিউ পাথ
├── UtilTest                 (17 tests) পাসওয়ার্ড / সময় / বাইট / ইনপুট ফিল্টার / কন্ট্রোল অ্যাট্রিবিউট
├── DictTest                 (5 tests) ডিকশনারি নাম↔option কনভার্সন / save/get/delete
├── ExcelExportTest          (4 tests) হেডার / JSON ফ্ল্যাটেনিং / সারি নম্বর / খালি সেল
└── LayuiTest                (5 tests) input / inputNumber / label এস্কেপিং / switch / html
```

## ডিজাইন ফিলোসফি

### 1. মডুলার মনোলিথ

মডিউলগুলো বিজনেস ডোমেইন অনুযায়ী ভার্টিক্যালি বিভক্ত (User / Product / Order / Payment / Provisioning / Ticket / Notification ইত্যাদি), প্রতিটি মডিউলের অভ্যন্তরে MVC লেয়ারিং অনুসরণ করে:

- **Controller** — HTTP লেয়ার, প্যারামিটার ভ্যালিডেশন, Service কল, Response রিটার্ন
- **Service** — বিজনেস লজিক, HTTP ডিপেন্ডেন্সি নেই, Controller এবং Queue Worker উভয়ে রিইউজ করতে পারে
- **Model** — Eloquent ডেটা মডেল, রিলেশনশিপ ও কুয়েরি স্কোপ ডিফাইন করে

মডিউলগুলোর মধ্যে **ইভেন্ট** এবং **ইন্টারফেস** দিয়ে ডিকাপলিং করা হয়, একে অপরের Service সরাসরি কল করে না। যেমন: পেমেন্ট সম্পন্ন → `OrderPaid` ইভেন্ট → `ProvisioningService` স্বয়ংক্রিয়ভাবে রিসোর্স চালু করে; টিকেট তৈরি → `TicketCreated` ইভেন্ট → স্বয়ংক্রিয়ভাবে কাস্টমার সার্ভিস অ্যাসাইন হয়।

### 2. ইভেন্ট-ড্রিভেন ডেলিভারি

```
ইউজার অর্ডার → পেমেন্ট সফল → OrderPaid ইভেন্ট
  → ProvisioningService.handleOrderPaid()
    → প্রতিটি OrderItem-এর জন্য ProvisionTask তৈরি (status=pending)
    → Redis Queue কনজিউমার ProvisionWorker
      → ProviderFactory.create(task) দিয়ে Provider পার্স
      → ProxmoxProvider.create()
        → HostSelector সবচেয়ে খালি ফিজিক্যাল মেশিন নির্বাচন
        → ProxmoxApi VM তৈরি / ডিস্ক মাউন্ট / IP অ্যালোকেশন
          (Rust kvm-server gRPC প্রোভিশনিং সার্ভিস রিপোজিটরিতে: e-cat/etcd রেজিস্ট্রেশন ডিসকভারি,
           PHP সাইডে KvmClient ওয়্যারিং; সিমুলেটেড ড্রাইভার, libvirt রিয়েল ড্রাইভার Phase 2-এ)
        → Resource / Disk রেকর্ড তৈরি
      → Order স্ট্যাটাস completed-এ আপডেট
```

ডেলিভারি ব্যর্থ হলে অটো-রিট্রাই, ব্যাকঅফ স্ট্র্যাটেজি: 1min → 5min → 15min → 1h → 6h → 24h, ৬ বারের বেশি ব্যর্থ হলে ফেইলড মার্ক করে অ্যালার্ট ট্রিগার হয়।

### 3. Provider প্লাগইন আর্কিটেকচার

রিসোর্স ডেলিভারি `ProviderInterface` দিয়ে অ্যাবস্ট্রাক্ট করা হয়, বিভিন্ন ইনফ্রাস্ট্রাকচার একই ইন্টারফেস ইমপ্লিমেন্ট করে:

```
ProviderInterface
  ├── ProxmoxProvider    (নিজস্ব Proxmox VE)
  ├── AliyunProvider     (ভবিষ্যতে: Aliyun)
  ├── AwsProvider        (ভবিষ্যতে: AWS EC2)
  └── DomainProvider     (ভবিষ্যতে: ডোমেইন রেজিস্ট্রার)
```

`ProviderFactory` `productType:provider` কী দিয়ে ফ্যাক্টরি ফাংশন রেজিস্টার করে, রানটাইমে ProvisionTask অনুযায়ী ডাইনামিকভাবে পার্স করা হয়।

### 4. মাল্টি-পেমেন্ট রাউটিং

`PaymentRouter` অর্ডারের অ্যামাউন্ট / কারেন্সি / রিজিয়ন অনুযায়ী ডাইনামিকভাবে উপলব্ধ পেমেন্ট চ্যানেল রিটার্ন করে; ফ্রন্টএন্ড চ্যানেল সুইচ করলেই পেমেন্ট চালু হয়। পেমেন্ট চ্যানেল `PaymentChannel` টেবিল দিয়ে কনফিগার করা হয় (ফি, মিন/ম্যাক্স অ্যামাউন্ট, দৃশ্যমান রিজিয়ন), কোড পরিবর্তন ছাড়াই অনলাইন/অফলাইন করা যায়।

### 5. সিকিউরিটি আর্কিটেকচার

গ্লোবাল মিডলওয়্যার চেইন: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [রাউট: Encryption → Captcha → Auth → Confirmation]`

![সিকিউরিটি মিডলওয়্যার পাইপলাইন](docs/diagrams/security-middleware-zh.svg)

- **CORS** — ক্রস-অরিজিন রিকোয়েস্ট হেডার হ্যান্ডলিং (হোয়াইটলিস্ট মোড, *.example.com ওয়াইল্ডকার্ড সাপোর্ট)
- **SecurityHeaders** — সিকিউরিটি রেসপন্স হেডার (HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy)
- **GeoBlock** — জিওগ্রাফিক ব্লকিং (GEO_BLOCKED_COUNTRIES অনুযায়ী নির্দিষ্ট দেশের অ্যাক্সেস ব্লক, GeoIP2 ভিত্তিক)
- **WAF** — ৮ ক্যাটাগরি ৪৫+ রুল (SQL ইনজেকশন/XSS/কমান্ড ইনজেকশন/ফাইল ইনক্লুশন/হেডার ইনজেকশন/SSRF/NoSQL ইনজেকশন/ওপেন রিডাইরেক্ট) + রিকোয়েস্ট সাইজ লিমিট + Content-Type ভ্যালিডেশন (ভ্যালু ইনজেকশন query/body/UA স্ক্যান করে, path শুধু পাথ ট্রাভার্সাল চেক করে)
- **Security Plugin** — ৩১ ধরনের আক্রমণ সনাক্তকরণ (XSS/SQL ইনজেকশন/কমান্ড ইনজেকশন/SSRF/ডিসিরিয়ালাইজেশন/JWT আক্রমণ/Host হেডার আক্রমণ/রিকোয়েস্ট স্মাগলিং/GraphQL ইনজেকশন/সংবেদনশীল ডেটা লিক ইত্যাদি), IP হোয়াইটলিস্ট + IP ব্ল্যাকলিস্ট অটো-ব্যান
- **Locale** — Accept-Language পার্স করে মাল্টিল্যাঙ্গুয়াল সেট করে
- **HashidRequest** — রিকোয়েস্টের hashid স্ট্রিং অটো-ডিকোড করে আসল ইন্টিজার ID-তে
- **Version** — URL পাথে ভার্সন সেগমেন্ট ভ্যালিডেট করে (যেমন `/api/v1/`), অসমর্থিত ভার্সনে `400` রিটার্ন
- **ClientPlatform** — `X-Client-Platform` রিকোয়েস্ট হেডার ভ্যালিডেট করে, ক্লায়েন্ট OS প্ল্যাটফর্ম শনাক্ত করে (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)
- **Encryption** — AES-256-GCM ট্রান্সমিশন এনক্রিপশন (অথেনটিকেটেড ইন্টারফেস ও অ্যাডমিন প্যানেল), ম্যান-ইন-দ্য-মিডল স্পাইং ও ট্যাম্পারিং প্রতিরোধ
- **Captcha** — ক্লিক ক্যাপচা, লগইন/রেজিস্ট্রেশনের আগে ভেরিফিকেশন (GD ড্রইং + Redis স্টোরেজ, ওয়ান-টাইম কী, 300s মেয়াদ, ৩টি চেষ্টার সীমা)
- **Auth** — JWT HS256 অথেনটিকেশন, Access Token ১৫ মিনিট, Refresh Token ৩০ দিন, Redis ব্ল্যাকলিস্ট
- **Confirmation** — সংবেদনশীল অপারেশনে (পেমেন্ট/ডিলিট/রিফান্ড/অ্যাপ্রুভাল ইত্যাদি) পাসওয়ার্ড পুনরায় ভেরিফাই করতে হয়, ৫ বার ব্যর্থ হলে ১৫ মিনিট লক
- **রেট লিমিট** — ডিফল্ট ৬০ বার/মিনিট, লগইন ৫ বার/মিনিট, রেজিস্ট্রেশন ৩ বার/মিনিট, পেমেন্ট ১০ বার/মিনিট
- **অডিট লগ** — সকল সংবেদনশীল অপারেশন আলাদা অডিট ডেটাবেসে লেখা হয়

### 6. ডেটা সিকিউরিটি

**লেয়ারড এনক্রিপশন স্ট্র্যাটেজি:**

| স্তর | প্রযুক্তি | বিবরণ |
|------|------|------|
| ট্রান্সমিশন লেয়ার | AES-256-GCM | API রিকোয়েস্ট/রেসপন্স বডি এনক্রিপশন, GCM অথেনটিকেটেড এনক্রিপশন ট্যাম্পারিং প্রতিরোধ |
| ফিল্ড লেয়ার | AES-256-CBC | মডেলের সংবেদনশীল ফিল্ড অটো এনক্রিপ্ট/ডিক্রিপ্ট, CBC র্যান্ডম IV ইকুয়ালিটি প্যাটার্ন লিক করে না |
| প্রাইমারি কী লেয়ার | Hashids | এক্সটার্নাল ID ১২ অক্ষরের স্ট্রিংয়ে অবফাসকেটেড, আসল ডেটা স্কেল লুকানো |

**সংবেদনশীল ফিল্ড এনক্রিপশন:** ৭টি মডেলের ১৪টি ফিল্ড `Encryptable::class` দিয়ে অটো এনক্রিপ্ট/ডিক্রিপ্ট হয় —— `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`।

**কী ম্যানেজমেন্ট:** ট্রান্সমিশন এনক্রিপশন ও ফিল্ড এনক্রিপশন আলাদা স্বতন্ত্র কী ব্যবহার করে (`ENCRYPTION_MASTER_KEY` বনাম `ENCRYPTION_KEY`), পুরনো কী লিস্ট (`ENCRYPTION_PREVIOUS_KEYS`) সাপোর্ট করে জিরো-ডাউনটাইম কী রোটেশন।

### 7. ডিস্ট্রিবিউটেড ID জেনারেশন

Twitter Snowflake অ্যালগরিদম দিয়ে ৬৪-বিট গ্লোবালি ইউনিক ID জেনারেট করা হয়: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`। সকল ৪৬টি Eloquent মডেল `creating` ইভেন্টে অটো স্নোফ্লেক ID জেনারেট করে, ডেটাবেস অটো-ইনক্রিমেন্ট ডিপেন্ডেন্সি নেই, ন্যাচারালি শার্ডিং সাপোর্ট করে।

### 8. মাল্টিল্যাঙ্গুয়াল (i18n)

**গ্লোবাল মিডলওয়্যার অটো-পার্সিং:**
- `LocaleMiddleware` `Accept-Language` রিকোয়েস্ট হেডার পড়ে বর্তমান ভাষা অটো-সেট করে
- ভাষা ফলব্যাক সাপোর্ট: অসমর্থিত ভাষা → `fallback_locale` (en-US)

**স্ট্যাটিক টেক্সট ট্রান্সলেশন:**
- `I18n::trans('auth.login_success')` → `লগইন সফল` / `Login successful`
- ট্রান্সলেশন ফাইল: `i18n/{locale}/messages.php`, ১২০টি এন্ট্রি সম্পূর্ণ ১৫টি মডিউল কভার করে
- প্যারামিটার রিপ্লেসমেন্ট সাপোর্ট: `I18n::trans('validation.required', ['field' => 'ইমেইল'])`

**JSON মাল্টিল্যাঙ্গুয়াল ফিল্ড:**
- পণ্যের নাম / বিবরণ স্টোর হয় `{"zh-CN":"云服务器","en-US":"Cloud Server"}` ফরম্যাটে
- `I18n::translateField($json)` বর্তমান ভাষা অনুযায়ী অটো মান নেয়
- নোটিফিকেশন টেমপ্লেটও মাল্টিল্যাঙ্গুয়াল সাপোর্ট করে, ব্যবহারকারীর পছন্দের ভাষায় পুশ হয়

### 9. ফুল-টেক্সট সার্চ

পণ্য, ইউজার, অর্ডার, টিকেট — ৪টি মডেল `Erikwang2013\WebmanScout\Searchable` Trait দিয়ে সার্চের সাথে যুক্ত। ড্রাইভার ডিফল্ট `database` (রাইট no-op, সার্চ SQL LIKE-এ ডিগ্রেড, ES ডিপেন্ডেন্সি নেই); Elasticsearch ড্রাইভার কনফিগার করলে অটো ইনডেক্স সিঙ্ক হয়, সাপোর্ট করে:

- **মাল্টিল্যাঙ্গুয়াল টোকেনাইজেশন** — IK Analyzer (ik_max_word / ik_smart)
- **চীনা ফুল-টেক্সট সার্চ** — পণ্যের নাম, বিবরণ, টিকেট টাইটেল
- **সুনির্দিষ্ট ফিল্টারিং** — স্ট্যাটাস, ক্যাটাগরি, প্রাইস রেঞ্জ, টাইম রেঞ্জ দিয়ে ফিল্টার
- **ব্যাচ সিঙ্ক** — `php webman scout:import "App\Product\Model\Product"`
- **সার্চ উদাহরণ** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. দেশের পতাকা

`erikwang2013/season` দিয়ে সব দেশের পতাকা emoji সাপোর্ট:

- `country_season_flag('CN')` → 🇨🇳, `country_season_flag('JP')` → 🇯🇵
- উত্তর/দক্ষিণ গোলার্ধ অটো শনাক্ত করে সংশ্লিষ্ট ঋতু রিটার্ন করে (চীনা ও ইংরেজি)
- ৩০+ ভাষার লোকালাইজড ঋতুর নাম সাপোর্ট
- ফ্রন্টএন্ড রিজিয়ন সিলেকশন, ইউজার ন্যাশনালিটি ডিসপ্লে ইত্যাদি সিনারিওতে সরাসরি কল করা যায়

## টোডো লিস্ট

- [x] ডেটাবেস DDL (`install.sql`, ৪৬টি টেবিল, wa_* অ্যাডমিন টেবিল + প্রিফিক্সবিহীন বিজনেস টেবিল, BigInt নন-অটো-ইনক্রিমেন্ট প্রাইমারি কী)
- [x] স্নোফ্লেক ID জেনারেশন (`erikwang2013/snowflake-php`)
- [x] JWT অথেনটিকেশন (`erikwang2013/jwt-webman`, HS256 + Redis ব্ল্যাকলিস্ট)
- [x] API ID অবফাসকেশন (`erikwang2013/hashids`, রিকোয়েস্ট অটো-ডিকোড + রেসপন্স অটো-এনকোড)
- [x] ট্রান্সমিশন এনক্রিপশন (`erikwang2013/encryption`, AES-256-GCM মিডলওয়্যার)
- [x] ফিল্ড-লেভেল এনক্রিপশন (`erikwang2013/encryptable`, সংবেদনশীল ফিল্ড অটো এনক্রিপ্ট/ডিক্রিপ্ট)
- [x] ফুল-টেক্সট সার্চ (`erikwang2013/webman-scout`, ডিফল্ট database ড্রাইভার SQL LIKE ডিগ্রেড, অপশনাল Elasticsearch + IK টোকেনাইজেশন)
- [x] দেশের পতাকা (`erikwang2013/season`, Unicode flag emoji)
- [x] অ্যাডমিন প্যানেল (`admin/`, webman-admin + ৭ প্যাকেজ ইন্টিগ্রেশন, ২৮৬ ইউনিট টেস্ট)
- [x] কোড রিভিউ (২টি ক্রিটিক্যাল ফিক্স + ৪টি গুরুত্বপূর্ণ ফিক্স প্রয়োগ করা হয়েছে)
- [x] Excel এক্সপোর্ট (PhpSpreadsheet ^2.0, অ্যাডমিন Crud/Table + সার্ভার ম্যানেজমেন্ট API)
- [x] ড্যাশবোর্ড ভিজ্যুয়ালাইজেশন (ECharts চার্ট + অ্যানিমেটেড স্ট্যাটিস্টিক কার্ড + সিস্টেম ইনফো প্যানেল)
- [x] PDF এক্সপোর্ট (html2canvas + jsPDF, ড্যাশবোর্ড স্ক্রিনশট এক্সপোর্ট)
- [x] ডেটাবেস মাইগ্রেশন স্ক্রিপ্ট (`install.sql` ইউনিফাইড DDL, `php webman migrate` কমান্ড-ভিত্তিক)
- [x] Stripe রিয়েল ইন্টিগ্রেশন (stripe-php SDK, PaymentIntent + Webhook সিগনেচার ভ্যালিডেশন)
- [x] Twilio এসএমএস রিয়েল ইন্টিগ্রেশন (twilio/sdk, সেন্ড-ফেইল হ্যান্ডলিং সহ)
- [x] FCM পুশ রিয়েল ইন্টিগ্রেশন (kreait/firebase-php, ইনভ্যালিড টোকেন ক্লিনআপ সহ)
- [x] ক্লিক ক্যাপচা (erikwang2013/poster-php, লগইন/রেজিস্ট্রেশন সংবেদনশীল অপারেশন ভেরিফিকেশন)
- [x] সেকেন্ডারি কনফার্মেশন (ConfirmationMiddleware, সংবেদনশীল অপারেশন পাসওয়ার্ড ভেরিফিকেশন, ৫ বার ব্যর্থ হলে ১৫ মিনিট লক)
- [x] সার্ভার-সাইড ইউনিট টেস্ট (672 tests / 1632 assertions, 15 skipped)
- [x] ক্লায়েন্ট প্ল্যাটফর্ম ডিটেকশন (ClientPlatformMiddleware, X-Client-Platform হেডার ৮টি প্ল্যাটফর্ম সাপোর্ট)
- [x] WAF সিকিউরিটি এনহ্যান্সমেন্ট (৮ ক্যাটাগরি ৪৫+ রুল: SQL ইনজেকশন/XSS/কমান্ড ইনজেকশন/ফাইল ইনক্লুশন/হেডার ইনজেকশন/SSRF/NoSQL ইনজেকশন/ওপেন রিডাইরেক্ট + রিকোয়েস্ট সাইজ লিমিট + Content-Type ভ্যালিডেশন)
- [x] Security Plugin (erikwang2013/security-php, ৩১ ধরনের আক্রমণ সনাক্তকরণ + IP ব্ল্যাকলিস্ট অটো-ব্যান + লগ রোটেশন)
- [x] Admin প্যানেল WAF মিডলওয়্যার
- [x] MySQL রিড/রাইট সেপারেশন (Eloquent read/write কানেকশন + sticky)
- [x] Redis মাল্টি-লেভেল ক্যাশ লেয়ার (CacheService: পণ্য/রিজিয়ন/এক্সচেঞ্জ রেট/TLD/ইউজার, TTL + অ্যাক্টিভ ইনভ্যালিডেশন + ওয়ার্মিং)
- [x] Nginx রেসপন্স কম্প্রেশন + কানেকশন অপ্টিমাইজেশন (gzip/proxy_buffering/keep-alive/limit_req+limit_conn)
- [x] ডেটাবেস ইনডেক্স সুপারিশ (১৩টি রিকমেন্ডেড কম্পোজিট/কভারিং ইনডেক্স)
- [x] Sentry এক্সেপশন মনিটরিং (SentryBootstrap + before_send ডিসেন্সিটাইজেশন কলব্যাক)
- [x] Feature Flags ফিচার সুইচ (Redis ডাইনামিক ওভাররাইড + অ্যাডমিন প্যানেল API)
- [x] সাপ্লায়ার এক্সটার্নাল API (API Key অথেনটিকেশন + অর্ডার/রিসোর্স/সেটেলমেন্ট/উইথড্রয়াল এন্ডপয়েন্ট)
- [x] WebSocket রিয়েল-টাইম পুশ (Workerman নেটিভ WebSocket + অর্ডার/টিকেট ইভেন্ট লিসেনার)
- [x] k6 লোড টেস্ট স্ক্রিপ্ট (স্মোক/প্রোডাক্ট/কনকারেন্সি লোড টেস্ট)
- [x] CI/CD পাইপলাইন (GitHub Actions, সিনট্যাক্স চেক + ডুয়াল PHPUnit + Composer ভ্যালিডেশন)
- [x] ওয়ান-ক্লিক ইনস্টলেশন উইজার্ড (Web UI, এনভায়রনমেন্ট চেক + ডেটাবেস কনফিগারেশন + অ্যাডমিন তৈরি + অটো .env জেনারেশন)

## ওপেন সোর্স কঠিন, সাপোর্ট করুন

| উইচ্যাট | আলিপে |
|:---:|:---:|
| ![উইচ্যাট](./docs/weixinpay.png "উইচ্যাট") | ![আলিপে](./docs/alipay.png "আলিপে") |

### গ্লোবাল ট্রান্সফার (ব্যাংক রেমিট্যান্স)

**প্রাপকের তথ্য**

- প্রাপকের নাম: WANG KEXUN
- অ্যাকাউন্ট নম্বর: 881015918251

**প্রাপক ব্যাংক (ZA Bank)**

- SWIFT Code: AABLHKHHXXX
- ব্যাংকের নাম: ZA Bank Limited
- ব্যাংক কোড: 387
- ব্যাংকের ঠিকানা: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**ক্রস-বর্ডার রেমিট্যান্স এজেন্ট ব্যাংক (যদি প্রয়োজন হয়)**

> অনুগ্রহ করে লক্ষ্য করুন, এটি ক্রস-বর্ডার রেমিট্যান্স এজেন্ট ব্যাংক (মধ্যবর্তী ব্যাংক) এর তথ্য, প্রাপক ব্যাংকের তথ্য নয়। রেমিট্যান্স ব্যাংককে জিজ্ঞাসা করুন ক্রস-বর্ডার রেমিট্যান্স এজেন্ট ব্যাংকের তথ্য প্রদানের প্রয়োজন আছে কিনা।

- হংকং ডলার, চীনা ইউয়ান ও মার্কিন ডলারে রেমিট্যান্সের এজেন্ট ব্যাংক **Citibank**:
  - ব্যাংকের নাম: Citibank N.A. Hong Kong
  - SWIFT Code: CITIHKHXXXX
  - ব্যাংক কোড: 006
  - শাখার নাম: Hong Kong Branch
  - শাখা কোড: 391
  - ব্যাংকের ঠিকানা: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- অন্যান্য কারেন্সিতে রেমিট্যান্সের এজেন্ট ব্যাংক **BNY Mellon**:
  - ব্যাংকের নাম: THE BANK OF NEW YORK MELLON
  - SWIFT Code: IRVTUS3NXXX
  - ব্যাংকের ঠিকানা: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### ক্রিপ্টো দান (Crypto Donation)

এই প্রকল্পটি আপনার কাজে লাগলে, দান করতে QR কোড স্ক্যান করুন, ধন্যবাদ!

| <img src="../../coin/1.jpg" width="200" alt="BNB Smart Chain (BEP20)"><br>**BNB Smart Chain (BEP20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/2.jpg" width="200" alt="Tron (TRC20)"><br>**Tron (TRC20)**<br>`TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| <img src="../../coin/3.jpg" width="200" alt="Ethereum (ERC20)"><br>**Ethereum (ERC20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/4.jpg" width="200" alt="Aptos"><br>**Aptos**<br>`0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| <img src="../../coin/5.jpg" width="200" alt="Plasma"><br>**Plasma**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/6.jpg" width="200" alt="Polygon POS"><br>**Polygon POS**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |
| <img src="../../coin/7.jpg" width="200" alt="Solana"><br>**Solana**<br>`2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` | <img src="../../coin/8.jpg" width="200" alt="The Open Network (TON)"><br>**The Open Network (TON)**<br>`UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| <img src="../../coin/9.jpg" width="200" alt="Arbitrum One"><br>**Arbitrum One**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/10.jpg" width="200" alt="AVAX C-Chain"><br>**AVAX C-Chain**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## License

লাইট এডিশন — MIT License | স্ট্যান্ডার্ড/প্রো এডিশন — Proprietary
