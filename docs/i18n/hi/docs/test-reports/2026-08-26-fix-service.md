# 2026-08-26 service दोष फिक्स रिपोर्ट (A/C/F)

## निष्कर्ष

- 3 दोष सभी फिक्स और एंड-टू-एंड दोबारा टेस्ट पास (9/9 PASS)
- PHPUnit पूर्ण रिग्रेशन：672 tests / 1632 assertions / 15 skipped / 0 failures
- .env、app/grpc/Generated、डेटाबेस schema को नहीं छुआ; कोई नया composer डिपेंडेंसी नहीं जोड़ी

## दोष A：encryptable कुंजी base64 डिकोड नहीं → रजिस्ट्रेशन/लॉगिन/रिफ्रेश/एड्रेस सभी 500

### मूल कारण (तीन परतें)

1. `config/encryptable.php` `ENCRYPTION_KEY` (base64, डिकोड के बाद 16 बाइट, cipher=aes-128-ecb) को मूल रूप में कुंजी के रूप में भेजता है, कुंजी लंबाई जांच `MissingEncryptionKeyException` फेंकती है।
2. रनटाइम वास्तव में `config/plugin/erikwang2013/encryptable/app.php` (केवल `enable`) पढ़ता है, उस प्लगइन कॉन्फ़िगरेशन में key ही नहीं है।
3. webman में ग्लोबल `app()` helper नहीं है, `Encryption::doResolve()` कंटेनर पाथ तक नहीं पहुँचता, `EnvEncryptableConfig` पर फॉलबैक करता है (मूल env base64 स्ट्रिंग पढ़ता है, डिकोड नहीं करता)——प्लगइन कॉन्फ़िगरेशन ठीक करने पर भी 500 रहेगा।

### फिक्स

| फ़ाइल | बदलाव |
|------|------|
| `service/config/encryptable.php` | `'key' => base64_decode(getenv('ENCRYPTION_KEY'), true) ?: ''` (legacy पाथ, साथ में सुधार) |
| `service/config/plugin/erikwang2013/encryptable/app.php` | `key` (base64 डिकोड) / `cipher` / `previous_keys` पूरे किए |
| `service/support/bootstrap.php` | `Encryption::setFallbackConfig(new WebmanPluginEncryptableConfig())`, रनटाइम को प्लगइन कॉन्फ़िगरेशन चलाने दें (कुंजी डिकोड हो चुकी) |

### चेन पर मिले समान-स्रोत बग (साथ में फिक्स)

एन्क्रिप्शन फिक्स प्रभावी होने के बाद, रजिस्ट्रेशन/लॉगिन/रिफ्रेश 500 के अलावा अन्य विफलताएँ देने लगे：

- **लॉगिन 401**：`User::where('email', $login)->orWhere('phone', $login)` सादा क्वेरी कभी एन्क्रिप्टेड कॉलम से मेल नहीं खाती। फिक्स：`where('email', Encryption::php()->encrypt($login))` (एन्क्रिप्शन नियतात्मक है, साइफरटेक्स्ट समान होने पर हिट होता है)।
- **रिफ्रेश 401 "Device mismatch"**：दो परत की समस्या——
  - `RefreshToken::where('token_hash', hash(...))` सादा क्वेरी भी हिट नहीं होती, `encrypt(hash(...))` में बदला;
  - रजिस्ट्रेशन पाथ कभी डिवाइस फिंगरप्रिंट रिकॉर्ड नहीं करता (`AuthService::register()` आंतरिक रूप से `issueTokens(..., '')`), जबकि रिफ्रेश पर फिंगरप्रिंट जांच होती है → रजिस्ट्रेशन के बाद रिफ्रेश हमेशा विफल। फिक्स：`AuthController::register` में `deviceFingerprint($request)` पास किया, `AuthService::register` में `$deviceFingerprint` पैरामीटर जोड़ा।
- **रजिस्ट्रेशन ईमेल/फोन यूनिकनेस जांच**：`User::where('email', ...)->exists()` भी वही बग, एन्क्रिप्टेड मान क्वेरी में बदला (`recordFailedLogin` भी साथ सुधारा)।

## दोष C：Searchable मॉडल में ES क्लाइंट नहीं → प्रोफ़ाइल बदलना/ऑर्डर बनाना 500

### निर्णय：webman-scout driver `database` में बदलें (न कि `null`)

`config/plugin/erikwang2013/webman-scout/app.php`：`'driver' => 'elasticsearch' → 'database'`।

कारण：elasticsearch/elasticsearch क्लाइंट इंस्टॉल नहीं है, elasticsearch ड्राइवर मॉडल सेव पर अपवाद फेंकता है; `database` इंजन की राइट no-op है, सर्च SQL LIKE से चलती है (उत्पाद सर्च उपलब्ध रहता है), `null` इंजन की `search()` चुपचाप खाली ऐरे लौटाती है, उत्पाद कीवर्ड सर्च परिणाम निगल जाएगा। सॉफ्ट डिलीट कॉन्फ़िगरेशन डिफ़ॉल्ट रहता है।

## दोष F：dns_rebinding डिटेक्टर Host=127.0.0.1 लोकल रिक्वेस्ट को 403 करता है

### निर्णय：dns_rebinding mode `log` में बदलें (न कि whitelist_ips)

`config/plugin/erikwang2013/security-php/app.php`：`dns_rebinding.mode = 'block' → 'log'`।

कारण：`whitelist_ips` क्लाइंट IP के अनुसार **सभी** डिटेक्टर छोड़ देता है——इस वातावरण में सारा ट्रैफ़िक nginx से फ़ॉरवर्ड होता है, क्लाइंट IP हमेशा लूपबैक रहता है, मतलब 31 डिटेक्टर सभी बंद हो जाते हैं। लोकल सीधा कनेक्शन (Host=127.0.0.1/localhost) विकास/टेस्ट की सामान्य स्थिति है, log में बदलने से केवल वह डिटेक्टर खुला, बाकी 30 block रहते हैं।

## अतिरिक्त खोज：user_addresses.phone VARCHAR(20) एन्क्रिप्टेड साइफरटेक्स्ट समा नहीं सकता

एन्क्रिप्शन प्रभावी होने के बाद एड्रेस जोड़ना 500 देता है (`SQLSTATE[22001] Data too long for column 'phone'`)। "डेटाबेस न बदलें" की बाधा के साथ, कोड-पक्ष फिक्स अपनाया：

- `service/app/user/model/UserAddress.php`：`phone` को Encryptable casts से बाहर किया (टेबल में 0 पंक्तियाँ, कोई पुराना डेटा माइग्रेशन जोखिम नहीं)। `address` एन्क्रिप्टेड रहता है (VARCHAR(500) में समा सकता है)।

**ट्रेडऑफ़ और आगे**：phone PII है, अब सादा पाठ डेटाबेस में। डिस्क एन्क्रिप्शन बहाल करने के लिए `user_addresses.phone` और `users.phone` (दोनों VARCHAR(20) + Encryptable, मोबाइल रजिस्ट्रेशन भी 500 देगा) को VARCHAR(255) तक बढ़ाना होगा——एक schema migration चाहिए, "इस बार डेटाबेस न बदलें" की बाधा से बाहर, अलग प्रोजेक्ट के रूप में आइटम करने का सुझाव।

## रिव्यू फॉलो-अप：cipher नियतात्मकता गार्ड (reviewer blocking हल हो गया)

reviewer ने बताया：साइफरटेक्स्ट समानता क्वेरी नियतात्मक एन्क्रिप्शन (ECB, कोई रैंडम IV नहीं) पर निर्भर करती है, जबकि `.env.example` aes-256-cbc (रैंडम IV) सुझाता है——नया वातावरण उदाहरण के अनुसार डिप्लॉय करने पर "स्टार्ट सफल लेकिन लॉगिन/रिफ्रेश/यूनिकनेस जांच कभी हिट नहीं होगी", चुपचाप लॉगिन असंभव।

फिक्स (fail-fast गार्ड, चुप विफलता रोकने के लिए)：

- `service/support/bootstrap.php`：encryptable कॉन्फ़िगरेशन वायरिंग के बाद गार्ड——`PHPEncrypter(WebmanPluginEncryptableConfig)->cipher()` `aes-128-ecb`/`aes-256-ecb` न होने पर स्टार्ट होते ही `RuntimeException` फेंकता है, स्पष्ट कहता है "नियतात्मक क्वेरी मोड केवल ECB सपोर्ट करता है, cipher बदलने पर पहले री-एन्क्रिप्शन माइग्रेशन करें"।
- `service/.env.example`：एन्क्रिप्शन सेक्शन टिप्पणी में चेतावनी जोड़ी (CBC/GCM स्टार्ट होते ही त्रुटि देंगे; नियतात्मक क्वेरी केवल ECB)।

सत्यापन：वर्तमान .env (aes-128-ecb) गार्ड पास; सेवा रीस्टार्ट के बाद E2E 9/9 PASS; phpunit 672/1632/15 skipped/0 failures।

## वातावरण घटना (कोड नहीं, वातावरण पक्ष में हल करना होगा)

सेशन के बीच `/usr/local/php/conf.d/002-imagick.ini` (root मालिक, mtime 2026-08-26 23:31) बनाया गया, इसका लोड किया हुआ imagick.so libgomp कंस्ट्रक्टर में क्रैश करता है → **सभी ini-सहित php CLI कॉल सेगमेंटेशन फॉल्ट** (phpunit、start.php、php -l सभी लटक गए; gdb ने पुष्टि की dlopen imagick.so ही SIGSEGV, OMP_NUM_THREADS=1 अप्रभावी)। root अनुमति के बिना वह फ़ाइल हटाई नहीं जा सकी, इस सेशन में `PHP_INI_SCAN_DIR=/tmp/confd` (स्कैन डायरेक्टरी कॉपी, imagick हटाकर) से बायपास किया, सेवा और phpunit दोनों इसी तरह चलाए गए।

वातावरण पक्ष सुझाव：`/usr/local/php/conf.d/002-imagick.ini` हटाएँ या टिप्पणी करें (imagick.so स्वयं खराब है), और जांचें कि सेशन के दौरान वह फ़ाइल किसने बनाई।

## बदली गई फ़ाइल सूची (सभी service/ में)

- `config/encryptable.php`
- `config/plugin/erikwang2013/encryptable/app.php`
- `config/plugin/erikwang2013/webman-scout/app.php`
- `config/plugin/erikwang2013/security-php/app.php`
- `support/bootstrap.php` (cipher नियतात्मकता गार्ड सहित)
- `.env.example` (केवल टिप्पणी, .env मान नहीं बदले)
- `app/user/service/AuthService.php`
- `app/user/controller/AuthController.php`
- `app/user/model/UserAddress.php`

## सत्यापन रिकॉर्ड

- E2E (`/tmp/verify_chain.php`, अस्थायी स्क्रिप्ट, रीपॉजिटरी में नहीं)：F (Host=127.0.0.1 403 नहीं)、रजिस्ट्रेशन→लॉगिन→रिफ्रेश→एड्रेस जोड़ना、प्रोफ़ाइल बदलना 9/9 PASS।
- `vendor/bin/phpunit`：672 tests / 1632 assertions / 15 skipped / 0 failures।
