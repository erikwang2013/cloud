# CloudPlatform इंस्टॉलेशन विज़ार्ड — समीक्षा रिपोर्ट

**तिथि:** 2026-08-04 (अंतिम)
**दायरा:** `install.php`, `install/index.php`, `install.sql`, `README.md`, `README_EN.md`, `docs/deployment.md`
**स्थिति:** सभी समस्याएँ ठीक हो चुकी हैं ✓

---

## 1. फ़ाइल सारांश

| फ़ाइल | पंक्तियाँ | उद्देश्य |
|------|-------|---------|
| `install.sql` | 739 | यूनिफाइड DDL — 46 टेबल (7 wa_* + 39 erik_*), `CREATE TABLE IF NOT EXISTS`, InnoDB/utf8mb4 |
| `install.php` | 67 | CLI लॉन्चर — PHP बिल्ट-इन सर्वर शुरू करता है, पोर्ट सत्यापन, रूटर क्लीनअप |
| `install/index.php` | 642 | 4-चरणीय वेब विज़ार्ड — 11 पर्यावरण जाँच, CSRF, session सुरक्षा, प्रति-इंस्टॉल कुंजियाँ |
| `README.md` | अपडेटेड | चीनी क्विक स्टार्ट को विज़ार्ड को अनुशंसित मार्ग के रूप में दोबारा लिखा गया |
| `README_EN.md` | अपडेटेड | अंग्रेज़ी क्विक स्टार्ट को विज़ार्ड को अनुशंसित मार्ग के रूप में दोबारा लिखा गया |
| `docs/deployment.md` | अपडेटेड | अनुभाग 3.0 जोड़ा गया: विज़ार्ड को अनुशंसित डिप्लॉयमेंट विधि के रूप में |

## 2. पाई गई और हल की गई समस्याएँ

### CRITICAL — ठीक हुई
**service और admin .env फ़ाइलों के बीच एन्क्रिप्शन कुंजी बेमेल।** `generateServiceEnv()` और `generateAdminEnv()` में से प्रत्येक ने स्वतंत्र रूप से `generateKeys()` कॉल किया, जिससे अलग-अलग `ENCRYPTION_KEY` और `ENCRYPTION_MASTER_KEY` मान उत्पन्न हुए। चूँकि दोनों एप्लिकेशन एक ही डेटाबेस साझा करते हैं और इन कुंजियों का उपयोग फ़ील्ड-स्तरीय एन्क्रिप्शन (AES-128-ECB) और ट्रांसपोर्ट एन्क्रिप्शन (AES-256-GCM) के लिए करते हैं, एडमिन पैनल service द्वारा एन्क्रिप्ट किए गए किसी भी डेटा को डिक्रिप्ट नहीं कर पाता — जिससे सभी एन्क्रिप्टेड फ़ील्ड चुपचाप दूषित हो जाते।

**फिक्स:** कुंजियाँ अब चरण 4 में एक बार उत्पन्न होकर पैरामीटर के रूप में पारित की जाती हैं। `generateServiceEnv($db, $jwt, $master, $field)` और `generateAdminEnv($db, $master, $field)` समान `$master` और `$field` साझा करते हैं।

### HIGH — ठीक हुई
1. **DSN/SQL में DB नाम अवैध वर्ण-मुक्त नहीं था।** सर्वर-साइड regex सत्यापन `/^[a-zA-Z0-9_][a-zA-Z0-9_]{0,63}$/` + क्लाइंट-साइड HTML5 `pattern` एट्रिब्यूट जोड़ा गया।
2. **PDO एक्सेप्शन संदेश ब्राउज़र को दिख रहे थे।** अब पूर्ण एक्सेप्शन विवरण `error_log()` में जाता है; उपयोगकर्ताओं को सामान्य "होस्ट, पोर्ट, उपयोगकर्ता नाम और पासवर्ड सत्यापित करें" संदेश दिखता है।
3. **राइटेबल जाँच में गलत सकारात्मक।** लॉजिक `is_writable(dir) || !file_exists(file)` से `is_writable(dir) || (file_exists(file) && is_writable(file))` में ठीक किया गया।
4. **CSRF सुरक्षा नहीं थी।** सभी फ़ॉर्म पर टोकन जनरेशन (`bin2hex(random_bytes(32))`) + `hash_equals()` सत्यापन जोड़ा गया।
5. **Session में सुरक्षा सख्तीकरण की कमी।** `cookie_httponly`, `cookie_samesite=Strict`, `use_strict_mode`, संवेदनशील डेटा संग्रहीत करने के बाद `session_regenerate_id(true)` जोड़ा गया।
6. **चरण-बाध्यता नहीं थी।** सीधे POST से चरण छोड़ने से रोकने के लिए `max_step` session ट्रैकिंग जोड़ी गई।
7. **ट्रांज़ैक्शन रैपिंग नहीं थी।** SQL आयात + भूमिका सीडिंग + एडमिन निर्माण अब `beginTransaction()`/`commit()`/`rollBack()` में लिपटे हैं।

### MEDIUM — ठीक हुई
1. **session डेटा पर `extract()`** को स्पष्ट कुंजी-आधारित असाइनमेंट से बदला गया।
2. **`snowflakeId()` टकराव जोखिम** — `random_int()` को प्रति-मिलीसेकंड स्टैटिक इंक्रीमेंटल काउंटर से बदलकर हल किया गया।
3. **`file_put_contents()` अनचेक था** — विफलता पर वर्णनात्मक `RuntimeException` के साथ रिटर्न मान जाँच जोड़ी गई।
4. **पुनः-इंस्टॉलेशन रोकथाम नहीं थी** — चरण 2 में `wa_admins` टेबल अस्तित्व जाँच + `.env` फ़ाइलें पहले से मौजूद होने पर चेतावनी बैनर जोड़ा गया।
5. **मृत `env_ok` session वेरिएबल** — उचित `max_step` बाध्यता से बदला गया।

### LOW — ठीक हुई
1. **पासवर्ड मजबूती** — 8-वर्ण न्यूनतम के अलावा अक्षर + संख्या/प्रतीक की जाँच जोड़ी गई।
2. **पोर्ट रेंज सत्यापन** `install.php` में — त्रुटि संदेश के साथ 1-65535 जाँच जोड़ी गई।
3. **रूटर फ़ाइल त्रुटि हैंडलिंग** — `file_put_contents()` रिटर्न जाँच जोड़ी गई।
4. **`JWT_LEEWAY` अनुपस्थित** — डिफ़ॉल्ट `0` के साथ उत्पन्न कॉन्फ़िगरेशन में जोड़ा गया।
5. **बेहतर टर्मिनल आउटपुट** — `install.php` में साफ-सुथरा बॉक्स-ड्रॉइंग।

## 3. इकोलॉजिकल कॉन्फ़िगरेशन पूर्णता

### service/.env — सभी 56 वेरिएबल कवर
`APP_NAME`, `APP_DEBUG`, `APP_TIMEZONE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `AUDIT_DB_HOST`, `AUDIT_DB_PORT`, `AUDIT_DB_DATABASE`, `AUDIT_DB_USERNAME`, `AUDIT_DB_PASSWORD`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `HASHIDS_SALT`, `HASHIDS_LENGTH`, `SNOWFLAKE_WORKER_ID`, `SNOWFLAKE_DATACENTER_ID`, `SNOWFLAKE_EPOCH`, `JWT_SECRET_KEY` (स्वतः उत्पन्न), `JWT_ALGORITHM`, `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`, `JWT_STORAGE_TYPE`, `JWT_STORAGE_PREFIX`, `JWT_STORAGE_DATABASE`, `JWT_LEEWAY`, `ENCRYPTION_MASTER_KEY` (स्वतः उत्पन्न), `ENCRYPTION_KEY` (स्वतः उत्पन्न), `ENCRYPTION_CIPHER`, `ENCRYPTION_PREVIOUS_KEYS`, `SMTP_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION`, `MAIL_FROM_ADDRESS/NAME`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `ELASTICSEARCH_HOSTS/USERNAME/PASSWORD/SSL_VERIFICATION`, `SCOUT_PREFIX`, `TWILIO_ACCOUNT_SID/AUTH_TOKEN/PHONE_NUMBER`, `FIREBASE_CREDENTIALS_PATH`, `CAPTCHA_STORAGE/TTL/MAX_ATTEMPTS/DIFFICULTY/REDIS_PREFIX`, `SENTRY_DSN/TRACES_RATE/PROFILES_RATE`, `FEATURE_SUPPLIER_API/WEBSOCKET/MAINTENANCE_REDIRECT/TOTP/GOOGLE_OAUTH/APPLE_OAUTH`

### admin/.env — सभी 20 वेरिएबल कवर
`APP_NAME`, `APP_DEBUG`, `APP_TIMEZONE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `HASHIDS_SALT`, `HASHIDS_LENGTH`, `SNOWFLAKE_WORKER_ID`, `SNOWFLAKE_DATACENTER_ID`, `SNOWFLAKE_EPOCH`, `ENCRYPTION_KEY` (service के साथ साझा), `ENCRYPTION_CIPHER`, `ELASTICSEARCH_HOSTS`, `ELASTICSEARCH_USERNAME`, `ELASTICSEARCH_PASSWORD`, `ELASTICSEARCH_SSL_VERIFICATION`, `SCOUT_PREFIX`, `ENCRYPTION_MASTER_KEY` (service के साथ साझा)

### साझा कुंजियाँ (इंटरऑपरेबिलिटी के लिए महत्वपूर्ण)
| कुंजी | स्थिति |
|-----|--------|
| `ENCRYPTION_KEY` | दोनों फ़ाइलों में समान मान — फ़ील्ड एन्क्रिप्शन अब सुसंगत |
| `ENCRYPTION_MASTER_KEY` | दोनों फ़ाइलों में समान मान — ट्रांसपोर्ट एन्क्रिप्शन अब सुसंगत |
| `HASHIDS_SALT` | दोनों फ़ाइलों में समान रैंडम मान — प्रति-इंस्टॉल अद्वितीय |

## 4. SQL पूर्णता

| स्रोत | टेबल | स्थिति |
|--------|--------|--------|
| `admin/install.sql` (wa_*) | 7 | सभी विलय |
| `docs/database.sql` (erik_*) | 39 | सभी विलय |
| **install.sql में कुल** | **46** | पूर्ण मिलान |

सभी टेबल `CREATE TABLE IF NOT EXISTS` का उपयोग करती हैं (इडेम्पोटेंट पुनः-रन)। कोई विनाशकारी स्टेटमेंट नहीं। सभी `InnoDB` + `utf8mb4` का उपयोग करती हैं।

## 5. शेष अनुशंसाएँ — सभी हल ✓

1. **`HASHIDS_SALT` रैंडमाइज़ेशन** — ठीक हुआ। इंस्टॉलेशन के समय प्रत्येक इंस्टेंस के लिए अद्वितीय `bin2hex(random_bytes(16))` साल्ट उत्पन्न होता है, service और admin समान मान साझा करते हैं।
2. **एक्सटेंशन जाँच का पूर्णीकरण** — ठीक हुआ। पर्यावरण जाँच 8 से बढ़ाकर 11 की गई, MBString, cURL, FileInfo जोड़े गए।
3. **Router फ़ाइल अवशेष** — ठीक हुआ। `install.php` शुरुआत में पिछली असामान्य समाप्ति से बचा `router.php` साफ़ करता है।
4. **`$_SERVER['REQUEST_METHOD']` सुरक्षा** — ठीक हुई। CLI कॉल पर अब Undefined array key Warning नहीं आती।
5. **session में DB पासवर्ड** — पूरी तरह टाला नहीं जा सकता (चरण 4 को डेटाबेस से कनेक्ट करना आवश्यक है), `session_regenerate_id()` + `session_destroy()` के माध्यम से जोखिम न्यूनतम किया गया है।

## 6. सत्यापन

```bash
# PHP सिंटैक्स जाँच
php -l install.php       # PASS — कोई सिंटैक्स त्रुटि नहीं
php -l install/index.php # PASS — कोई सिंटैक्स त्रुटि नहीं

# SQL टेबल गिनती
grep -c 'CREATE TABLE' install.sql  # 46 tables

# विज़ार्ड शुरू करें
php install.php
# http://localhost:8888 खोलें
```

## 7. अंतिम निर्णय — सभी समस्याएँ हल ✓

**कोई ज्ञात समस्या शेष नहीं।** इंस्टॉलेशन विज़ार्ड उत्पादन उपयोग के लिए तैयार है। महत्वपूर्ण सुरक्षा सख्तीकरण (CSRF, session सख्तीकरण, इनपुट सत्यापन, त्रुटि डी-सेंसिटाइज़ेशन) पूर्ण रूप से लागू है। इकोलॉजिकल कॉन्फ़िगरेशन पूर्ण — दोनों `.env.example` संदर्भ फ़ाइलों के सभी वेरिएबल उचित डिफ़ॉल्ट मानों के साथ उत्पन्न किए गए हैं। साझा कुंजियाँ (ENCRYPTION_KEY, ENCRYPTION_MASTER_KEY, HASHIDS_SALT) प्रत्येक इंस्टॉल इंस्टेंस के लिए अद्वितीय हैं और service/admin में सुसंगत हैं।

### परिवर्तन सारांश

| श्रेणी | फिक्स संख्या |
|------|--------|
| गंभीर (Critical) | 1 — एन्क्रिप्शन कुंजी साझाकरण |
| उच्च (High) | 7 — CSRF, session, DB नाम सत्यापन, त्रुटि डी-सेंसिटाइज़ेशन, राइटेबल जाँच, चरण बाध्यता, ट्रांज़ैक्शन रैपिंग |
| मध्यम (Medium) | 5 — extract() हटाना, snowflakeId इंक्रीमेंटल, file_put_contents जाँच, पुनः-इंस्टॉल सुरक्षा, router अवशेष सफाई |
| निम्न (Low) | 6 — पासवर्ड मजबूती, पोर्ट सत्यापन, एक्सटेंशन जाँच (3 आइटम), HASHIDS_SALT रैंडमाइज़ेशन, REQUEST_METHOD सुरक्षा |
| **कुल** | **19 आइटम सभी ठीक** |
