# सुरक्षा ऑडिट रिपोर्ट — cloud-php

**दिनांक**: 2026-08-04
**दायरा**: पूरा प्रोजेक्ट (service + admin)
**विधि**: कॉन्फ़िगरेशन समीक्षा, मिडलवेयर ऑडिट, कोड निरीक्षण

---

## समग्र मूल्यांकन: **B+ (अच्छा, 4 अंतराल ठीक करने हैं)**

प्रोजेक्ट में ठोस बहु-परत सुरक्षा आर्किटेक्चर है। 31 डिटेक्टरों वाला erikwang2013/security-php प्लगइन सबसे उल्लेखनीय फ़ीचर है। नीचे विस्तृत विवरण दिया गया है।

---

## 1. मौजूदा सुरक्षा उपाय (सत्यापित)

### ट्रांसमिशन और एन्क्रिप्शन
| तंत्र | कार्यान्वयन | स्थिति |
|-----------|---------------|--------|
| API ट्रांसमिशन एन्क्रिप्शन | AES-256-GCM (erikwang2013/encryption के माध्यम से) | OK |
| DB फ़ील्ड एन्क्रिप्शन | AES-128-ECB (erikwang2013/encryptable, डिटरमिनिस्टिक, क्वेरी करने योग्य) | OK |
| कुंजी रोटेशन | ENCRYPTION_PREVIOUS_KEYS कॉमा-पृथक पुरानी कुंजियाँ | OK |
| ID ऑब्स्क्यूरेशन | कॉन्फ़िगर करने योग्य साल्ट और न्यूनतम लंबाई 12 के साथ Hashids | OK |
| पासवर्ड हैशिंग | bcrypt cost=12, न्यूनतम लंबाई 8 | OK |

### प्रमाणीकरण और एक्सेस नियंत्रण
| तंत्र | कार्यान्वयन | स्थिति |
|-----------|---------------|--------|
| JWT प्रमाणीकरण | erikwang2013/jwt-webman, HS256, एक्सेस TTL 900s + रिफ्रेश 30d | OK |
| JWT ब्लैकलिस्ट | Redis-आधारित टोकन रद्दीकरण | OK |
| MFA/TOTP | 6-अंक, 30s अवधि, Google/MS Authenticator संगत | OK |
| RBAC | एडमिन AccessControl मिडलवेयर + plugin\admin\api\Auth::canAccess() | OK |
| Session स्टोरेज | Redis (db2) | OK |
| कैप्चा | लॉगिन/रजिस्टर के लिए erikwang2013/poster-php क्लिक-टेक्स्ट कैप्चा | OK |

### अटैक डिटेक्शन (WAF — दोहरी परत)
| परत | कवरेज | स्थिति |
|-------|----------|--------|
| कस्टम WafMiddleware | SQLi, XSS, CMDi, path traversal, header injection, SSRF, NoSQLi, open redirect | OK |
| सुरक्षा प्लगइन (31 डिटेक्टर) | उपरोक्त सभी + XXE, deserialization, LDAP, mail header, SSTI, JWT attack, Host header, request smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, CSV injection, data leak, prototype pollution, WebSocket, CORS bypass, DNS rebinding | OK |

### रेट लिमिटिंग (केवल service)
| रूट | दर | बर्स्ट | प्रति | स्थिति |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### अन्य सुरक्षा उपाय
| तंत्र | कार्यान्वयन | स्थिति |
|-----------|---------------|--------|
| अनुरोध आकार सीमा | 10MB body, 2KB URL | OK |
| Content-Type सत्यापन | व्हाइटलिस्ट: JSON, multipart, form-urlencoded | OK |
| डेटाबेस prepared statements | PDO::ATTR_EMULATE_PREPARES = false | OK |
| DB रीड/राइट पृथक्करण | मास्टर को लिखना, रीप्लिका से पढ़ना, sticky sessions | OK |
| ऑडिट लॉगिंग | अलग ऑडिट DB, LogSanitizer संवेदनशील फ़ील्ड लाल करता है | OK |
| मेंटेनेंस मोड | व्हाइटलिस्ट IP बाइपास, बाकियों को 503 + Retry-After | OK |
| IP ऑटो-बैन | 60s में 5 उल्लंघन फिर 15min बैन | OK |
| SQL strict mode | डेटा ट्रंकेशन और इम्प्लिसिट टाइप कन्वर्ज़न रोकता है | OK |

---

## 2. अंतराल और सुझाव

### अंतराल 1 (मध्यम): CORS किसी भी ओरिजिन को मिरर करता है
**फ़ाइल**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

यह क्लाइंट द्वारा भेजे गए किसी भी Origin को वापस इको करता है, जिससे किसी भी वेबसाइट को प्रमाणित क्रॉस-ओरिजिन अनुरोध करने की प्रभावी रूप से अनुमति मिलती है। सुरक्षा प्लगइन का cors डिटेक्टर कुछ हेडर इंजेक्शन पकड़ सकता है, लेकिन मिडलवेयर स्वयं कोई ओरिजिन व्हाइटलिस्ट प्रदान नहीं करता।

**फिक्स**: व्हाइटलिस्ट जाँच जोड़ें। यदि ओरिजिन अनुमत सूची में नहीं है, तो `Access-Control-Allow-Origin: null` के साथ उत्तर दें या हेडर को पूरी तरह हटा दें।

### अंतराल 2 (मध्यम): महत्वपूर्ण सुरक्षा प्रतिक्रिया हेडर अनुपस्थित
न तो service और न ही admin महत्वपूर्ण HTTP सुरक्षा हेडर सेट करते हैं:

| हेडर | अनुशंसित | वर्तमान |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | अनुपस्थित |
| X-Content-Type-Options | nosniff | अनुपस्थित |
| X-Frame-Options | DENY या SAMEORIGIN | अनुपस्थित |
| Content-Security-Policy | nonce/hash के साथ नीति | अनुपस्थित |
| X-XSS-Protection | 1; mode=block | अनुपस्थित |
| Referrer-Policy | strict-origin-when-cross-origin | अनुपस्थित |
| Permissions-Policy | कैमरा/माइक/जियोलोकेशन प्रतिबंधित | अनुपस्थित |

**सिफारिश**: service और admin दोनों मिडलवेयर स्टैक में SecurityHeadersMiddleware जोड़ें। उच्च-प्रभाव, कम-प्रयास वाला फिक्स।

### अंतराल 3 (कम): admin/config/security.php में रेट लिमिटिंग अनुपस्थित
**फ़ाइल**: `admin/config/security.php`

एडमिन पैनल में कोई rate_limits कॉन्फ़िगरेशन नहीं है। एडमिन WAF मिडलवेयर केवल अनुरोध आकार/Content-Type सीमाएँ जाँचता है। एडमिन लॉगिन पर ब्रूट-फोर्स अटैक एप्लिकेशन परत पर रेट-लिमिटेड नहीं है।

**सिफारिश**: या तो admin/config/security.php में rate_limits जोड़ें या एडमिन रूट्स पर RateLimitMiddleware लागू करें।

### अंतराल 4 (कम): GeoBlockMiddleware परिभाषित है लेकिन सक्रिय नहीं
**फ़ाइल**: `service/common/security/GeoBlockMiddleware.php`

मिडलवेयर मौजूद है और कार्यात्मक है, लेकिन `service/config/middleware.php` में पंजीकृत नहीं है। यदि जियो-ब्लॉकिंग आवश्यक है, तो इसे स्टैक में जोड़ें।

### अंतराल 5 (जानकारी): दोहरी WAF ओवरहेड
WafMiddleware (कस्टम, 40+ regex पैटर्न) और SecurityMiddleware (प्लगइन, 31 डिटेक्टर) दोनों हर अनुरोध पर चलते हैं। SQLi, XSS, command injection, path traversal, header injection, SSRF, NoSQLi, और open redirect के लिए उनकी पैटर्न कवरेज काफी हद तक ओवरलैप होती है।

**सिफारिश**: सुरक्षा प्लगइन अधिक व्यापक है (8 श्रेणियों बनाम 31 डिटेक्टर) और इसमें IP ब्लैकलिस्टिंग, फ़ील्ड व्हाइटलिस्टिंग, और लॉग डिडुप्लिकेशन है। कस्टम WafMiddleware हटाने और पूरी तरह प्लगइन पर निर्भर रहने पर विचार करें, या कम से कम WafMiddleware से ओवरलैपिंग पैटर्न हटाएँ।

### अंतराल 6 (जानकारी): Validator क्लास न्यूनतम है
**फ़ाइल**: `service/common/helper/Validator.php`

इसमें केवल required(), email(), minLength() हैं। अनुपस्थित: अधिकतम लंबाई, संख्यात्मक सत्यापन, स्ट्रिंग सैनिटाइज़ेशन, URL सत्यापन, पैटर्न मिलान। जो कंट्रोलर फ्रेमवर्क-स्तरीय सत्यापन उपयोग नहीं करते, वे गलत इनपुट स्वीकार करने के जोखिम में हैं।

---

## 3. सुरक्षा प्लगइन — 31 डिटेक्टर स्थिति

| # | डिटेक्टर | मोड | नोट्स |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF textarea सामग्री से मेल खाता है, log रहना चाहिए |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} Vue/Angular टेम्पलेट्स से मेल खाता है |
| 13 | nosql_injection | **log** | $ne/$gt शेल वेरिएबल्स/LaTeX से मेल खाता है |
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
| 27 | dns_rebinding | **log** | loopback Host (127.0.0.1/localhost) अब 403 नहीं (डेव/टेस्ट सामान्य, केवल रिकॉर्ड) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

सभी 31 डिटेक्टर सक्षम। 4 केवल-लॉग मोड में (दस्तावेजित फाल्स-पॉज़िटिव जोखिम)। कॉन्फ़िगरेशन सही है।

---

## 4. मिडलवेयर निष्पादन क्रम (service)

```
1. VersionMiddleware          — API संस्करण हेडर पार्सिंग
2. CorsMiddleware              — CORS हेडर (बहुत अनुमतिपूर्ण, अंतराल 1 देखें)
3. ClientPlatformMiddleware    — OS/प्लेटफ़ॉर्म पहचान
4. WafMiddleware               — कस्टम WAF (40+ regex पैटर्न)
5. SecurityMiddleware           — प्लगइन WAF (31 डिटेक्टर)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — ID डिकोडिंग
8. MaintenanceMiddleware       — मेंटेनेंस मोड जाँच
```

---

## 5. सारांश

| श्रेणी | ग्रेड | मुख्य मुद्दे |
|----------|-------|------------|
| अटैक डिटेक्शन | **A** | 31 डिटेक्टर, दोहरी WAF परत (अतिरेक पर संपूर्ण) |
| प्रमाणीकरण | **A-** | bcrypt+MFA+JWT ब्लैकलिस्ट, एडमिन रेट लिमिट अनुपस्थित |
| ट्रांसमिशन सुरक्षा | **B+** | AES-256-GCM ठीक, HSTS/CSP हेडर अनुपस्थित |
| इनपुट सत्यापन | **B** | WAF अटैक पकड़ता है, एप्लिकेशन-स्तरीय सत्यापन पतला है |
| एक्सेस नियंत्रण | **A-** | RBAC + session जाँच, CORS बहुत अनुमतिपूर्ण |
| ऑडिट/लॉगिंग | **A** | अलग ऑडिट DB, संवेदनशील फ़ील्ड रिडक्शन |
| रेट लिमिटिंग | **B+** | service के लिए अच्छी तरह कॉन्फ़िगर्ड, admin के लिए अनुपस्थित |

**प्राथमिकता फिक्स क्रम:**
1. सुरक्षा प्रतिक्रिया हेडर जोड़ें (HSTS, CSP, X-Frame-Options आदि)
2. CORS को किसी भी ओरिजिन को मिरर करने के बजाय व्हाइटलिस्ट तक सीमित करें
3. एडमिन पैनल में रेट लिमिटिंग जोड़ें
4. जियो-ब्लॉकिंग आवश्यक होने पर GeoBlockMiddleware सक्रिय करें
5. प्रति-अनुरोध regex ओवरहेड कम करने के लिए WAF परतों के समेकन पर विचार करें

---

## 6. लागू किए गए सुधार (2026-08-04)

### ठीक किए गए
| अंतराल | फिक्स | परिवर्तित फ़ाइलें |
|-----|-----|---------------|
| CORS किसी भी ओरिजिन को मिरर करता है | `CORS_ALLOWED_ORIGINS` env var के साथ व्हाइटलिस्ट मोड, `*.example.com` वाइल्डकार्ड और सभी के लिए `*` समर्थन | `service/common/security/CorsMiddleware.php` |
| सुरक्षा हेडर अनुपस्थित | नया `SecurityHeadersMiddleware` service और admin दोनों स्टैक में जोड़ा गया: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (env के माध्यम से ऑप्ट-इन) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| एडमिन में रेट लिमिटिंग नहीं | `rate_limits` कॉन्फ़िग + `RateLimitMiddleware` एडमिन पैनल में जोड़ा गया (डिफ़ॉल्ट 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock सक्रिय नहीं | service मिडलवेयर स्टैक में `GeoBlockMiddleware` पंजीकृत | `service/config/middleware.php` |

### नए Env वेरिएबल
| वेरिएबल | उद्देश्य | डिफ़ॉल्ट |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | कॉमा-पृथक अनुमत ओरिजिन | (खाली = सभी अस्वीकृत) |
| `SECURITY_HSTS_ENABLE` | HSTS हेडर सक्षम करें | false |
| `SECURITY_HSTS_VALUE` | HSTS हेडर मान | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | X-Frame-Options मान | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | ब्लॉक किए गए देश कोड (ISO 3166-1) | (खाली = अक्षम) |
| `GEOIP_DB_PATH` | GeoLite2 .mmdb पथ | storage_path('geoip/GeoLite2-Country.mmdb') |

### अद्यतन मिडलवेयर पाइपलाइन

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
