# PHP यूनिट टेस्ट कवरेज पूर्णता रिपोर्ट (2026-08-26)

## वातावरण

- PHP 8.3.7 (service सूट PHPUnit 10.5.64 / admin सूट PHPUnit 11.5.56)
- service/：व्यावसायिक API; admin/：प्रबंधन बैकएंड
- टेस्ट डेटा：SQLite `:memory:` (Capsule इनिशियलाइज़ेशन, मौजूदा ReportServiceTest / OrderIdentityTest पैटर्न के अनुसार); बाहरी सेवाएँ (Redis/MySQL/Stripe) सभी डिग्रेड या mock

## पुनरीक्षण निष्कर्ष：मॉड्यूल vs कवरेज

### service/app (27 मॉड्यूल)

| मॉड्यूल | पुनरीक्षण से पहले टेस्ट | कवरेज स्थिति |
|------|-----------|----------|
| order / payment / user / product / provisioning / supplier / affiliate / notification / webhook / websocket / graphql / grpc / monitor / billing / captcha / domain / ssl / storage / report / ticket / admin / security / confirmation / version / clientplatform | हर एक में 1-12 टेस्ट फ़ाइलें | कवर हो चुका |
| **command** (6 कमांड) | **नहीं** | **0 कवरेज → इस राउंड में ReconcileCommandTest जोड़ा** |
| **cron** (6 कार्य) | केवल SupplierSettlementTest | आंशिक कवरेज → इस राउंड में PaymentReconcileTest + ExchangeRateSyncTest जोड़े |
| controller (Health/Help/Status/Upload) | नहीं | पतले कंट्रोलर (स्टैटिक स्टेटस/हेल्थ चेक), कोई व्यावसायिक लॉजिक नहीं |
| model (payment/order आदि 20+ मॉडल) | सेवा परत से अप्रत्यक्ष कवरेज | कवर हो चुका |

### admin/app (controller/common/model/middleware)

| मॉड्यूल | पुनरीक्षण से पहले टेस्ट | कवरेज स्थिति |
|------|-----------|----------|
| controller (48 कंट्रोलर) | AdminControllersTest (सभी कंट्रोलर रिफ्लेक्शन：मॉडल असेंबली/CRUD सतह/GET व्यू पाथ) + CrudHashidsTest | कवर हो चुका |
| middleware | AccessControlMiddlewareTest | कवर हो चुका |
| common | TreeTest / HashidsTest / BaseJsonTest | आंशिक कवरेज → इस राउंड में UtilTest + LayuiTest + ExcelExportTest जोड़े |
| model | कोई सीधा टेस्ट नहीं | इस राउंड में DictTest जोड़ा; बाकी मॉडल पतली मैपिंग हैं |

## इस राउंड के नए टेस्ट

| मॉड्यूल | नई फ़ाइल | केस | एसर्शन | कवरेज बिंदु |
|------|----------|------|------|--------|
| Cron (फंड रिकंसिलिएशन) | `service/tests/cron/PaymentReconcileTest.php` | 7 | 24 | compare मुद्रा न्यूनतम इकाई सटीकता half-up राउंडिंग：सब-सेंट अवशेष verified और diff शून्य; वास्तविक अंतर mismatch; शून्य-दशमलव मुद्रा (JPY) पूर्णांक करी; मुद्रा केवल एक तरफ मौजूद; खाली तरफ verified; अवैध तिथि InvalidArgumentException फेंकता है; run() बिना रिपोर्ट चैनल वाले को upsert unverified पंक्ति (केवल success स्थानीय योग में गिना, failed बाहर, यूनिक इंडेक्स प्रोडक्शन को मिरर करता है) |
| Cron (विनिमय दर सिंक) | `service/tests/cron/ExchangeRateSyncTest.php` | 2 | 2 | API पहुँच से बाहर शांति से समाप्त (शेड्यूलर को नहीं फेंकता); वैध payload + Redis अनुपलब्ध होने पर क्रैश नहीं |
| Command (रिकंसिलिएशन कमांड) | `service/tests/command/ReconcileCommandTest.php` | 2 | 3 | अवैध तिथि → FAILURE + त्रुटि संदेश; वैध तिथि → SUCCESS (खाली चैनल टेबल) |
| Admin Common | `admin/tests/UtilTest.php` | 17 | 47 | पासवर्ड hash/verify राउंडट्रिप; humanDate पांच स्तर सापेक्ष समय; formatBytes; checkTableName/filterAlphaNum/filterNum/filterUrlPath/filterPath सत्यापन (BusinessException सहित); controllerToUrlPath (@action और अवैध इनपुट सहित); camel/smCamel; getCommentFirstLine; typeToControl/typeToMethod; getLengthValue (decimal/enum/varchar); getControlProps (select डेटा value/name सूची में बदलना vs सामान्य key=>value) |
| Admin Model | `admin/tests/DictTest.php` | 5 | 10 | डिक्शनरी नाम↔option नाम रूपांतरण; filterValue फ़ॉर्मेट सत्यापन; नाम में अक्षर अनिवार्य; save/get/delete पूर्ण चेन (SQLite इन-मेमोरी DB, समान नाम ओवरराइड सेमेंटिक्स); गायब होने पर null लौटता है |
| Admin Common | `admin/tests/ExcelExportTest.php` | 4 | 9 | हेडर लेखन + बोल्ड; ऐरे फ़ील्ड JSON फ़्लैटनिंग; पंक्ति-दर-पंक्ति लाइन नंबर जोड़ना; गायब कॉलम खाली सेल (PhpSpreadsheet इन-मेमोरी एसर्शन, डिस्क पर नहीं लिखता) |
| Admin Common | `admin/tests/LayuiTest.php` | 5 | 9 | input रेंडर name/value; inputNumber फोर्स number प्रकार; label HTML एस्केपिंग (एट्रिब्यूट इंजेक्शन रोकना); switch रेंडर lay-skin; html() इंडेंटेशन रीफॉर्मेट |

इस राउंड में नए 42 केस / 104 एसर्शन। राशि संबंधी एसर्शन सभी `assertSame` स्ट्रिंग सटीक तुलना (bcmath), कोई फ्लोट नहीं।

## टेस्ट वातावरण फिक्स (गैर-व्यावसायिक कोड)

1. **service/vendor खराब**：`composer.lock` अपग्रेड हो चुका था (encryptable v2.0.2→v2.0.3 आदि कई पैकेज) लेकिन vendor सिंक नहीं था, guzzle गायब होने से सूट शुरू नहीं हो सका → `composer install` से बहाल, दोनों सूट चल सकते हैं।
2. **UserModelTest एन्क्रिप्शन फिक्स्चर अमान्य**：encryptable v2.0.3 32-बाइट कुंजी फोर्स करता है (डिफ़ॉल्ट aes-256-gcm), पुराना फिक्स्चर 16 बाइट → फेल। फिक्स：`service/tests/user/UserModelTest.php` setUp में 32-बाइट कुंजी + aes-256-gcm पिन किया, और `Encryption::setFallbackConfig(null)` कॉल करके पैकेज की प्रोसेस-स्तरीय स्टैटिक कैश रीसेट किया —— `tests/user/AuthFullChainTest.php` `service/.env` (cipher=aes-128-ecb、24 कैरेक्टर गैर-base64 कुंजी) को `$_ENV/$_SERVER` में इंजेक्ट करता है, स्टैटिक `$resolved` कैश क्रॉस-टेस्ट संदूषण पैदा करता है, अकेले चलाने पर पास, पूर्ण चलाने पर फेल। यह फिक्स आगे Encryptable पर निर्भर टेस्टों को भी समान वातावरण देता है।

## व्यावसायिक कोड समस्याएँ

इस राउंड में कोई व्यावसायिक बग नहीं मिला। `PaymentReconcile::compare` के दो आसानी से गलत समझे जाने वाले सेमेंटिक्स वास्तविक इम्प्लीमेंटेशन के अनुसार एसर्टेड और टिप्पणीबद्ध：diff मूल कुल अंतर है (इकाई राउंडिंग अंतर नहीं); शून्य-दशमलव मुद्रा में करी के बाद mismatch का diff मूल अंतर है (जैसे JPY 1234 vs 1234.5000 → diff -0.5000)।

## पूर्ण परिणाम

| सूट | केस | एसर्शन | फेल | त्रुटि | स्किप |
|------|------|------|------|------|------|
| service | 672 | 1632 | 0 | 0 | 15 |
| admin | 286 | 962 | 0 | 0 | 1 |

- बेसलाइन तुलना：service 661→672 (+11)，admin 255→286 (+31); दोनों सूट 0 failure / 0 error।
- सिंटैक्स जांच：नई और बदली गई फ़ाइलें सभी `php -l` पास।

## अवशिष्ट अंतराल और कारण

| अंतराल | कारण |
|------|------|
| cron/CronRunner、cron/SslCertificateCheck | शेड्यूलिंग संदर्भ + वास्तविक TLS सर्टिफिकेट प्रोब, यूनिट टेस्ट लागत अधिक |
| command/Migrate*、DbBackupCommand、I18nSyncCommand | वास्तविक MySQL माइग्रेशन/फ़ाइल सिस्टम पर निर्भर, इंटीग्रेशन वातावरण चाहिए |
| admin/common/Auth (getScopeRoleIds/isSuperAdmin) | सेशन और DB अनुमति डेटा पर निर्भर |
| admin/common/Migration*、Layui::buildTable/buildForm | DB information_schema / पूर्ण टेबल संरचना पर निर्भर |
| service/controller पतले कंट्रोलर (Health/Help/Status/Upload) | कोई व्यावसायिक लॉजिक नहीं, रिटर्न मान webman रनटाइम देता है |
| graphql/GraphqlController | webman `json()`/`config()` हेल्पर और FeatureFlags रनटाइम पर निर्भर, Schema पहले SchemaTest से कवर है |
| monitor/ResourceMonitor | Redis + वास्तविक provider कॉल पर निर्भर, mock परत या इंटीग्रेशन वातावरण चाहिए |
