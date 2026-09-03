# CloudPlatform आर्किटेक्चर डिज़ाइन दस्तावेज़

## 1. सिस्टम अवलोकन

CloudPlatform एक वैश्विक क्लाउड संसाधन ट्रेडिंग प्लेटफ़ॉर्म है, जो सेल्फ-ऑपरेटेड फिजिकल मशीन + तृतीय-पक्ष सप्लायर हाइब्रिड मोड का समर्थन करता है। उपयोगकर्ता Web/मोबाइल के माध्यम से सर्वर (VM), IP पता, क्लाउड डिस्क, डोमेन आदि उत्पाद खरीद सकते हैं, सिस्टम स्वचालित रूप से पेमेंट प्रोसेसिंग और संसाधन डिलीवरी पूरी करता है।

### 1.1 मुख्य आर्किटेक्चर निर्णय

| निर्णय | चयन | कारण |
|------|------|------|
| बैकएंड फ्रेमवर्क | PHP webman (Workerman) | रेज़िडेंट मेमोरी, इवेंट-ड्रिवन, मल्टी-प्रोसेस, मिलीसेकंड-स्तरीय प्रतिक्रिया |
| आर्किटेक्चर पैटर्न | मॉड्यूलर मोनोलिथ | मॉड्यूल व्यावसायिक रूप से ऊर्ध्वाधर रूप से विभाजित, आंतरिक MVC परतें, मॉड्यूलों के बीच इवेंट डिकपलिंग |
| एडमिन पैनल | स्वतंत्र webman इंस्टेंस (webman-admin + Layui) | एडमिन ट्रैफ़िक और उपयोगकर्ता ट्रैफ़िक को अलग करता है, फॉल्ट डोमेन पृथक्करण |
| ORM | Illuminate/Eloquent | Laravel इकोसिस्टम परिपक्व, रिलेशन क्वेरी, Scope, इवेंट्स, माइग्रेशन |
| डिस्ट्रिब्यूटेड प्राइमरी की | Snowflake 64-bit | कोई ऑटो-इन्क्रीमेंट निर्भरता नहीं, स्वाभाविक रूप से शार्डिंग का समर्थन |
| ID ऑब्स्क्यूरेशन | Hashids | बाहरी रूप से वास्तविक ID आकार छिपाता है, क्रॉलर ट्रैवर्सल रोकता है |
| प्रमाणीकरण | JWT HS256 | स्टेटलेस प्रमाणीकरण, Access 15min + Refresh 30d |
| ट्रांसमिशन एन्क्रिप्शन | AES-256-GCM | मिडलवेयर पारदर्शी एन्क्रिप्ट/डिक्रिप्ट, GCM प्रमाणित एन्क्रिप्शन छेड़छाड़ रोकता है |
| फ़ील्ड एन्क्रिप्शन | AES-128-ECB | Eloquent Cast स्वचालित एन्क्रिप्ट/डिक्रिप्ट, डिटरमिनिस्टिक एन्क्रिप्शन (सिफरटेक्स्ट पर समानता क्वेरी, लॉगिन/यूनीकनेस जाँच निर्भरता); केवल ECB समर्थित |
| मैसेज क्यू | Redis Queue | पेमेंट कॉलबैक, नोटिफिकेशन डिस्ट्रीब्यूशन, संसाधन प्रोविज़निंग का एसिंक्रोनस प्रोसेसिंग |
| सर्च इंजन | database (डिफ़ॉल्ट) / Elasticsearch 8.x | webman-scout डिफ़ॉल्ट database ड्राइवर (SQL LIKE फ़ॉलबैक); ES कॉन्फ़िगर करने पर IK टोकनाइज़र इंडेक्स सक्षम |
| वर्चुअलाइज़ेशन | Proxmox VE + kvm-server | सेल्फ-ऑपरेटेड VM Rust kvm-server (gRPC :50051, e-cat/etcd रजिस्ट्री डिस्कवरी) से आपूर्ति; ड्राइवर परत वर्तमान में सिम्युलेटेड ड्राइवर, libvirt वास्तविक ड्राइवर Phase 2 |
| क्लाइंट | Flutter | एक कोडबेस iOS/macOS/Windows/Linux/Web पाँच प्लेटफ़ॉर्म + HarmonyOS |

### 1.2 सिस्टम सीमाएँ

```
┌──────────────────────────────────────────────────────────────────┐
│                          उपयोगकर्ता पक्ष                          │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx रिवर्स प्रॉक्सी                          │
│  SSL टर्मिनेशन / gzip कंप्रेशन / रेट लिमिट / WebSocket Upgrade  │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman सर्वर पक्ष (मल्टी-प्रोसेस)                    │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ ग्लोबल मिडलवेयर चेन: Version→CORS→SecurityHeaders→ClientPlatform │
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[रूट मिडलवेयर]      │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket Server (:8282) — रीयल-टाइम पुश                  │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (मास्टर-स्लेव)│ │(कैश/क्यू) │ │    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  e-cat / etcd रजिस्ट्री डिस्कवरी │
    │  सिम्युलेटेड ड्राइवर (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  KVM/QEMU वर्चुअलाइज़ेशन     │
    │  IP पूल / डिस्क पूल / होस्ट   │
    └─────────────────────────────┘
```

---

## 2. घटक आर्किटेक्चर

### 2.1 दो-इंस्टेंस डिज़ाइन

प्रोजेक्ट में दो स्वतंत्र webman इंस्टेंस हैं, जो MySQL डेटाबेस साझा करते हैं:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   मिडलवेयर: WAF→ACL  │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 ग्लोबल+6 रूट मिडलवेयर │
                    └─────────────────────┘
```

| इंस्टेंस | पोर्ट | जिम्मेदारी | मिडलवेयर |
|------|------|------|--------|
| **service** | 8787 | उपयोगकर्ता API + एडमिन API + WebSocket | ग्लोबल 12 + रूट 6 + SupplierApiKey |
| **admin** | 8788 | एडमिन पैनल HTML पैनल (Layui) | WafMiddleware + AccessControl |

### 2.2 मॉड्यूल परत संरचना

प्रत्येक व्यावसायिक मॉड्यूल के आंतरिक रूप से समान परत संरचना का पालन करता है:

```
app/{Module}/
├── controller/     # HTTP परत: पैरामीटर सत्यापन, Service कॉल, Response रिटर्न
│   └── external/   # बाह्य API कंट्रोलर (सप्लायर API Key प्रमाणीकरण)
├── service/        # व्यावसायिक लॉजिक: कोई HTTP निर्भरता नहीं, Controller/Queue Worker द्वारा पुनः उपयोग योग्य
├── model/          # Eloquent डेटा मॉडल: रिलेशन परिभाषाएँ, क्वेरी स्कोप्स, Casts
├── event/          # डोमेन इवेंट परिभाषाएँ (OrderPaid, TicketCreated आदि)
├── listener/       # इवेंट लिसनर (Provisioning, WebSocket पुश)
├── provider/       # क्लाउड विक्रेता एडेप्टर (ProxmoxProvider आदि)
├── queue/          # क्यू कंज़्यूमर (ProvisionWorker, EmailSender आदि)
└── cron/           # शेड्यूल्ड टास्क (ExchangeRateSync, ExpirationCheck आदि)
```

### 2.3 साझा लाइब्रेरी परत

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # क्लिक कैप्चा सेवा
├── clientplatform/      # ClientPlatformMiddleware (X-Client-Platform हेडर)
├── confirmation/        # द्वितीयक पासवर्ड पुष्टिकरण मिडलवेयर
├── encryption/          # AES-256-GCM ट्रांसमिशन एन्क्रिप्शन मिडलवेयर
├── feature/             # Feature Flags फ़ंक्शन स्विच
├── hashid/              # Hashids अनुरोध डिकोड मिडलवेयर + एन्कोड/डिकोड सेवा
├── helper/              # Response फ़ॉर्मेटिंग + CacheService
├── http/                # HTTP क्लाइंट उपकरण
├── i18n/middleware/     # बहुभाषी LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # स्नोफ्लेक ID सेवा + Eloquent Trait
├── metrics/             # Prometheus मीट्रिक कलेक्टर + रेंडरर + HTTP अनुरोध काउंट मिडलवेयर
├── version/             # VersionMiddleware (URL पथ से API संस्करण)
└── webhook/             # Webhook इवेंट डिस्पैचर
```

### 2.4 CDN मॉड्यूल

प्रोडक्ट-स्तरीय CDN मॉड्यूल (`service/app/cdn/`) एडेप्टर पैटर्न के माध्यम से चार सेवाप्रदाताओं से जुड़ता है, सर्वर या स्टोरेज बकेट को CDN के लिए ओरिजिन के रूप में जोड़ा जाता है:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (SSL SaaS स्वचालित प्रमाणपत्र), ICP पंजीकरण आवश्यक नहीं
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), ICP पंजीकरण आवश्यक नहीं
  ├── AliyunCdnAdapter    RPC सिग्नेचर, ICP पंजीकरण आवश्यक
  └── TencentCdnAdapter   TC3 सिग्नेचर, ICP पंजीकरण आवश्यक
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① बाउंड खाता (provider_account_id) → ② code=cdn-{type} सक्रिय खाता → ③ env फ़ॉलबैक
  strict=true (डिलीट/purge): केवल बाउंड खाता, अनुपस्थित होने पर 4003, चुपचाप स्विच नहीं
```

**खाता प्रबंधन:** `provider_apis` मॉडल का पुन: उपयोग (क्रेडेंशियल Encryptable एन्क्रिप्शन के साथ संग्रहीत), एडमिन `/admin/providers` CRUD (RbacMiddleware), `code` कन्वेंशन `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, env क्रेडेंशियल फ़ॉलबैक के रूप में।

**डेटा मॉडल:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; cert_config संग्रहीत करने से पहले निजी कुंजी हटाई जाती है)। अनुमति अलगाव: CDN संसाधन `resource.user_id` स्वामित्व जाँच से गुजरते हैं, गैर-स्वामित्व वाले के लिए समान रूप से 404।

---

## 3. मिडलवेयर निष्पादन पाइपलाइन

### 3.1 ग्लोबल मिडलवेयर चेन (सभी अनुरोध)

```
HTTP अनुरोध
  │
  ▼
1. VersionMiddleware         ← URL पथ से API संस्करण जाँच (जैसे /api/v1/), अमान्य पर 400
  │                            केवल /api/v1/ और /admin/api/v1/ पर प्रभावी
  ▼
2. CorsMiddleware            ← OPTIONS प्रीफ्लाइट CORS हेडर रिटर्न, Origin रिफ्लेक्ट
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy सुरक्षा प्रतिक्रिया हेडर
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform हेडर पहचान (8 प्लेटफ़ॉर्म), properties इंजेक्ट
  │                            केवल /api/v1/ और /admin/api/v1/ पर प्रभावी
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES देश ब्लॉक (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← 8 श्रेणियाँ 45+ नियम स्कैन (JSON body + URL + UA + रॉ बॉडी)
  │                          ← Content-Type व्हाइटलिस्ट + बॉडी 10MB सीमा + URL 2KB सीमा
  │                            हिट → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 प्रकार की अटैक डिटेक्शन (XSS/SQL इंजेक्शन/SSRF/डी-सीरियलाइज़ेशन आदि), IP ब्लैक/व्हाइटलिस्ट
  ▼
8. RateLimitMiddleware       ← सभी रूट्स पर रेट लिमिट (per-IP + per-token दोहरी बाल्टी)
  ▼
9. LocaleMiddleware          ← Accept-Language पार्सिंग, लोकेल सेट करना
  ▼
10. MetricsMiddleware        ← Prometheus HTTP अनुरोध काउंट और लेटेंसी रिकॉर्ड
  ▼
11. HashidRequestMiddleware  ← अनुरोध पैरामीटर hashid स्ट्रिंग → वास्तविक पूर्णांक ID डिकोड
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE जाँच, व्हाइटलिस्ट IP पास
  │
  ▼
[रूट मिडलवेयर — रूट ग्रुप के अनुसार जुड़ा]
  │
  ├─ /health (आंतरिक मॉनिटरिंग) ────────────
  │   InternalTokenMiddleware      ← आंतरिक टोकन जाँच /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM अनुरोध/प्रतिक्रिया बॉडी एन्क्रिप्ट/डिक्रिप्ट
  │
  ├─ /api (उपयोगकर्ता प्रमाणीकरण) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token सत्यापन → $request->userId/role
  │
  ├─ /api (संवेदनशील ऑपरेशन) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← पासवर्ड द्वितीयक पुष्टि, Redis काउंटर, 5 बार लॉक 15min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 सत्यापन → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC अनुमति जाँच
  │
  └─ /admin/api (संवेदनशील ऑपरेशन) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
कंट्रोलर → Service → Model → DB
```

### 3.2 प्रत्येक मिडलवेयर का विवरण

| मिडलवेयर | स्थान | पंजीकरण | जिम्मेदारी |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | ग्लोबल | URL पथ से API संस्करण जाँच, अमान्य पर 400 |
| `CorsMiddleware` | common/Security | ग्लोबल | OPTIONS प्रीफ्लाइट, Origin रिफ्लेक्ट |
| `SecurityHeadersMiddleware` | common/Security | ग्लोबल | HSTS / X-Frame-Options / CSP / Referrer-Policy सुरक्षा हेडर |
| `ClientPlatformMiddleware` | common/ClientPlatform | ग्लोबल | `X-Client-Platform` 8 प्लेटफ़ॉर्म पहचान |
| `GeoBlockMiddleware` | common/Security | ग्लोबल | GEO_BLOCKED_COUNTRIES क्षेत्र ब्लॉक (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | ग्लोबल(service)+admin | 8 श्रेणियाँ 45+ नियम + अनुरोध सीमाएँ |
| `SecurityPlugin` | Erikwang2013\Security | ग्लोबल | 31 प्रकार की अटैक डिटेक्शन, IP व्हाइट/ब्लैकलिस्ट |
| `RateLimitMiddleware` | common/Security | ग्लोबल | Redis टोकन बकेट रेट लिमिट (per-IP + per-token दोहरी बाल्टी) |
| `LocaleMiddleware` | common/I18n | ग्लोबल | Accept-Language पार्सिंग |
| `MetricsMiddleware` | common/Metrics | ग्लोबल | Prometheus HTTP अनुरोध काउंट और लेटेंसी |
| `HashidRequestMiddleware` | common/Hashid | ग्लोबल | hashid अनुरोध डिकोडिंग |
| `MaintenanceMiddleware` | common/Security | ग्लोबल | मेंटेनेंस मोड + IP व्हाइटलिस्ट |
| `InternalTokenMiddleware` | common/Security | रूट ग्रुप | `/health/live|ready|deps` आंतरिक टोकन जाँच |
| `EncryptionMiddleware` | common/Encryption | रूट ग्रुप | AES-256-GCM एन्क्रिप्ट/डिक्रिप्ट |
| `AuthMiddleware` | common/Auth | रूट ग्रुप | JWT Bearer Token सत्यापन |
| `AdminRoleMiddleware` | common/Auth | रूट ग्रुप | एडमिन RBAC |
| `ConfirmationMiddleware` | common/Confirmation | रूट ग्रुप | पासवर्ड द्वितीयक पुष्टि |
| `SupplierApiKeyMiddleware` | common/Auth | रूट ग्रुप | sk_xxx API Key SHA256 हस्ताक्षर सत्यापन |

---

## 4. डेटा आर्किटेक्चर

### 4.1 डिस्ट्रिब्यूटेड प्राइमरी की: Snowflake

```
64-bit Snowflake ID संरचना:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  मिलीसेकंड टाइमस्टैम्प  डेटासेंटर  वर्कर नोड  सीक्वेंस
  युग: 2024-01-01
  अधिकतम आयु: ~69 वर्ष
```

सभी Eloquent Model `creating` इवेंट में `HasSnowflakeId` Trait के माध्यम से स्वचालित रूप से जनरेट करते हैं। डेटाबेस कॉलम प्रकार `bigint unsigned` है।

### 4.2 ID ऑब्स्क्यूरेशन: Hashids

```
अनुरोध प्रवाह:
  Client: GET /api/v1/products/aB3xK7mQ9w
    → HashidRequestMiddleware डिकोड → int(1234567890)
      → Controller/Service पूर्णांक ID के साथ ऑपरेशन
        → Response::success() / Response::paginated()
          → hashids_encode_ids() सभी id/*_id फ़ील्ड को रिकर्सिवली एन्कोड
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 डेटाबेस कनेक्शन

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL मास्टर (लिखें) │     │  MySQL स्लेव (पढ़ें) │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  स्थायी कनेक्शन (PDO) │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  audit DB (स्वतंत्र कनेक्शन) │
         │  ऑडिट लॉग पृथक भंडारण   │
         └──────────────────────┘
```

### 4.4 एन्क्रिप्शन परतें

| परत | एल्गोरिदम | कार्यान्वयन | उपयोग |
|------|------|------|------|
| ट्रांसमिशन परत | AES-256-GCM | EncryptionMiddleware | API अनुरोध/प्रतिक्रिया बॉडी एन्क्रिप्शन, GCM प्रमाणीकरण |
| फ़ील्ड परत | AES-128-ECB | Encryptable Cast | संवेदनशील फ़ील्ड स्वचालित एन्क्रिप्ट/डिक्रिप्ट (डिटरमिनिस्टिक एन्क्रिप्शन: समान प्लेनटेक्स्ट→समान सिफरटेक्स्ट, लॉगिन/यूनीकनेस सिफरटेक्स्ट पर समानता क्वेरी; केवल ECB, cipher बदलने के लिए पुनः-एन्क्रिप्शन माइग्रेशन आवश्यक) |
| हैश परत | bcrypt + SHA256 | JWT / API Key | पासवर्ड/टोकन अपरिवर्तनीय भंडारण |
| प्राइमरी की परत | Hashids | Response + Middleware | ID बाहरी रूप से ऑब्स्क्यूर |

### 4.5 कैश परतें

```
L1: Redis कैश परत (CacheService)
    प्रोडक्ट सूची TTL 5min | प्रोडक्ट विवरण TTL 10min
    क्षेत्र TTL 1h | विनिमय दर TTL 30min | TLD TTL 1h
    अमान्यकरण नीति: डेटा परिवर्तन पर सक्रिय forget / forgetPattern

L2: MySQL क्वेरी परत (Eloquent + इंडेक्स ऑप्टिमाइज़ेशन)
    13 कम्पोज़िट/कवरिंग इंडेक्स उच्च-आवृत्ति क्वेरी कवर करते हैं

L3: Nginx प्रतिक्रिया कंप्रेशन (gzip level 6)
    JSON प्रतिक्रिया कंप्रेशन दर 70-85%
```

### 4.6 अंतर्राष्ट्रीयकरण (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (ग्लोबल मिडलवेयर)
         │  प्राथमिक भाषा पार्स → zh-CN
         │  I18n::setLocale('zh-CN')
         │  i18n/zh-CN/messages.php लोड
         ▼
  कंट्रोलर / Service
         │
         ├── I18n::trans('auth.login_success')  →  'लॉगिन सफल'
         ├── I18n::translateField($jsonField)   →  भाषा के अनुसार मान
         └── I18n::getLocale()                  →  'zh-CN'
```

| क्षमता | विवरण |
|------|------|
| हेडर पार्सिंग | `LocaleMiddleware` स्वचालित रूप से `Accept-Language` हेडर पार्स करता है |
| भाषा फ़ॉलबैक | असमर्थित भाषा → `fallback_locale` |
| स्थैतिक अनुवाद | 120 एंट्रीज़, 15 मॉड्यूल कवर (`i18n/{locale}/messages.php`) |
| पैरामीटर प्रतिस्थापन | `I18n::trans('key', ['field' => 'value'])` |
| JSON फ़ील्ड | `translateField()` बहुभाषी JSON कॉलम संभालता है |

---

## 5. सुरक्षा आर्किटेक्चर

### 5.1 WAF नियम प्रणाली (8 श्रेणियाँ 45+ नियम)

| श्रेणी | नियम संख्या | डिटेक्शन दायरा |
|------|--------|---------|
| SQL इंजेक्शन | 9 | टिप्पणी वर्ण, कीवर्ड, हेक्साडेसिमल एन्कोडिंग, यूनियन क्वेरी, सदैव-सत्य स्थिति, टाइम ब्लाइंड, स्टैक्ड क्वेरी |
| XSS | 8 | HTML टैग, Script वेरिएंट, 13 इवेंट हैंडलर, JS स्यूडो-प्रोटोकॉल, एंटिटी एन्कोडिंग, Data URI |
| कमांड इंजेक्शन | 5 | पाइप के बाद कमांड, सेमीकोलन के बाद कमांड, $(cmd), बैकटिक, स्टैंडअलोन कमांड कीवर्ड |
| फ़ाइल इंक्लूज़न | 4 | पाथ ट्रैवर्सल, PHP स्यूडो-प्रोटोकॉल, एब्सोल्यूट पाथ, Null byte |
| HTTP हेडर इंजेक्शन | 2 | CRLF न्यूलाइन, Host/Cookie/Set-Cookie इंजेक्शन |
| SSRF | 6 | इंट्रानेट IP, localhost, क्लाउड मेटाडेटा, file:// प्रोटोकॉल |
| NoSQL इंजेक्शन | 3 | MongoDB ऑपरेटर, Redis खतरनाक कमांड |
| ओपन रीडायरेक्ट | 2 | redirect_uri बाह्य URL, डबल-एन्कोडिंग बाइपास |

**स्कैन दायरा:** वैल्यू-इंजेक्शन नियम (SQLi/XSS/कमांड इंजेक्शन/हेडर इंजेक्शन/SSRF/NoSQL/ओपन रीडायरेक्ट) क्वेरी स्ट्रिंग, अनुरोध बॉडी, User-Agent स्कैन करते हैं; URL path केवल फ़ाइल इंक्लूज़न (पाथ ट्रैवर्सल) पैटर्न से संरचनात्मक जाँच के लिए। व्यावसायिक पथ में select/insert/alert जैसे सामान्य शब्द होते हैं (जैसे `/order_item/select`), यदि पूरे पथ को स्कैन किया जाए तो सभी CRUD एंडपॉइंट गलत तरीके से ब्लॉक हो जाएँगे, इसलिए path वैल्यू-इंजेक्शन मिलान में भाग नहीं लेता।

**अनुरोध-स्तरीय सुरक्षा:** Content-Type व्हाइटलिस्ट, बॉडी 10MB सीमा, URL 2KB सीमा

### 5.2 प्रमाणीकरण प्रणाली

```
┌─────────────────────────────────────────────┐
│               प्रमाणीकरण विधि               │
├──────────────┬──────────────┬───────────────┤
│  उपयोगकर्ता पक्ष │  एडमिन पक्ष  │  सप्लायर API  │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min │  Access 2h   │  sk_xxx प्रीफिक्स│
│  Refresh 30d  │  Refresh 7d  │  SHA256 स्टोरेज│
│  TOTP वैकल्पिक │              │  केवल एक बार दिखे │
│  OAuth वैकल्पिक │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. डिप्लॉयमेंट आर्किटेक्चर

### 6.1 प्रोडक्शन टोपोलॉजी

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← प्लेटफ़ॉर्म का अपना एज सुरक्षा (DDoS/Bot),
               │  DDoS / Bot     │    प्रोडक्ट-स्तरीय CDN मॉड्यूल (चार सेवाप्रदाता,
               └────────┬────────┘    §2.4 देखें) से असंबंधित
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
         │ MySQL मास्टर-स्लेव │ Redis Cluster │
         │ 1 मास्टर 2 स्लेव│ कैश+क्यू      │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  e-cat / etcd रजिस्ट्री     │
         │  सिम्युलेटेड ड्राइवर (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Proxmox VE क्लस्टर        │
         │  फिजिकल मशीन × N           │
         │  KVM/QEMU वर्चुअलाइज़ेशन    │
         └────────────────────────────┘
```

### 6.2 प्रोसेस मॉडल

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (डिफ़ॉल्ट 8)
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

## 7. तकनीकी निर्भरताएँ

### 7.1 मुख्य फ्रेमवर्क

| पैकेज | संस्करण | उपयोग |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web फ्रेमवर्क (रेज़िडेंट मेमोरी मल्टी-प्रोसेस) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | इवेंट सिस्टम |
| illuminate/redis | ^10.0 | Redis क्लाइंट |
| webman/redis-queue | ^1.0 | Redis मैसेज क्यू |

### 7.2 erikwang2013 इकोसिस्टम पैकेज

| पैकेज | उपयोग |
|----|------|
| snowflake-php | 64-बिट डिस्ट्रिब्यूटेड प्राइमरी की |
| hashids | API ID ऑब्स्क्यूरेशन |
| encryptable | डेटाबेस फ़ील्ड एन्क्रिप्शन |
| encryption | ट्रांसमिशन एन्क्रिप्शन AES-256-GCM |
| jwt-webman | JWT प्रमाणीकरण |
| webman-scout | Elasticsearch फुल-टेक्स्ट सर्च |
| season | देश ध्वज emoji |
| poster-php | क्लिक कैप्चा |

### 7.3 तृतीय-पक्ष एकीकरण

| पैकेज | उपयोग |
|----|------|
| stripe/stripe-php | Stripe पेमेंट |
| twilio/sdk | SMS |
| kreait/firebase-php | FCM पुश |
| guzzlehttp/guzzle | HTTP क्लाइंट (Proxmox API आदि) |
| sentry/sentry | एक्सेप्शन मॉनिटरिंग |
| phpoffice/phpspreadsheet | Excel एक्सपोर्ट |
