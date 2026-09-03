# API अवलोकन

> पूर्ण इंटरफ़ेस संदर्भ (200+ एंडपॉइंट्स, अनुरोध/प्रतिक्रिया उदाहरण और त्रुटि कोड सहित): [API इंटरफ़ेस दस्तावेज़](api-reference.md)
> ऑनलाइन डीबगिंग: [service API दस्तावेज़](http://localhost:8787/apidoc) · [admin API दस्तावेज़](http://localhost:8788/apidoc)

## सार्वजनिक इंटरफ़ेस

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/health` | हेल्थ चेक |
| POST | `/api/v1/auth/register` | उपयोगकर्ता पंजीकरण (अनुरोध बॉडी AES-256-GCM एन्क्रिप्टेड होनी चाहिए) |
| POST | `/api/v1/auth/login` | उपयोगकर्ता लॉगिन (अनुरोध बॉडी AES-256-GCM एन्क्रिप्टेड होनी चाहिए) |
| POST | `/api/v1/auth/refresh` | टोकन रीफ़्रेश (अनुरोध बॉडी AES-256-GCM एन्क्रिप्टेड होनी चाहिए) |
| POST | `/api/v1/captcha/create` | क्लिक कैप्चा उत्पन्न करें (लॉगिन/रजिस्ट्रेशन से पहले प्राप्त करें) |
| GET | `/api/v1/products` | उत्पाद सूची (श्रेणी/क्षेत्र/कीवर्ड फ़िल्टर समर्थित) |
| GET | `/api/v1/products/{id}` | उत्पाद विवरण (id एक hashid स्ट्रिंग है) |
| GET | `/api/v1/regions` | उपलब्ध क्षेत्र |
| GET | `/api/v1/domain/check/{domain}/{tld}` | डोमेन उपलब्धता जाँच |
| GET | `/api/v1/domain/tlds` | पंजीकरण योग्य एक्सटेंशन सूची |
| POST | `/api/v1/payments/webhook/stripe` | Stripe कॉलबैक (हस्ताक्षर सत्यापन, एन्क्रिप्शन आवश्यक नहीं) |

## प्रमाणित इंटरफ़ेस (Bearer Token आवश्यक)

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/api/v1/user/profile` | व्यक्तिगत जानकारी |
| PUT | `/api/v1/user/profile` | जानकारी अपडेट करें |
| POST | `/api/v1/user/kyc` | वास्तविक नाम सत्यापन (KYC) सबमिट करें |
| GET | `/api/v1/user/balance` | खाता शेष |
| GET/POST | `/api/v1/cart` | शॉपिंग कार्ट |
| POST/GET | `/api/v1/orders` | ऑर्डर |
| GET | `/api/v1/orders/{id}/payment-methods` | उपलब्ध भुगतान विधियाँ |
| POST | `/api/v1/orders/{id}/pay` | भुगतान शुरू करें |
| GET/POST | `/api/v1/resources` | मेरे संसाधन |
| GET | `/api/v1/resources/{id}/status` | संसाधन स्थिति |
| GET | `/api/v1/resources/{id}/console` | VNC कंसोल लिंक |
| GET/POST | `/api/v1/cdn/domains` | CDN डोमेन सूची / निर्माण (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | CDN डोमेन विवरण / डिलीट |
| POST | `/api/v1/cdn/domains/{id}/purge` | कैश साफ़ करें (आइडेम्पोटेंट, अधिकतम 100 URL) |
| GET/POST | `/api/v1/tickets` | टिकट |
| POST | `/api/v1/tickets/{id}/reply` | टिकट उत्तर |
| GET/POST | `/api/v1/dns/{domain}` | DNS प्रबंधन |
| POST | `/api/v1/supplier/apply` | सप्लायर आवेदन |
| GET | `/api/v1/supplier/settlements` | सप्लायर सेटलमेंट रिकॉर्ड |
| POST | `/api/v1/supplier/withdraw` | सप्लायर विदड्रॉल |

> **टिप्पणी:** API संस्करण URL पथ में होता है (जैसे `/api/v1/...`)। प्रमाणित इंटरफ़ेस और एडमिन इंटरफ़ेस के अनुरोध/प्रतिक्रिया `EncryptionMiddleware` से गुजरते हैं। क्लाइंट `X-Encrypted: 1` हेडर सेट करता है, अनुरोध बॉडी का प्रारूप `{"payload": "<base64(AES-256-GCM)>"}` है, प्रतिक्रिया बॉडी भी एन्क्रिप्ट होकर `payload` फ़ील्ड में लिपटी होती है। सभी पूर्णांक ID API प्रतिक्रियाओं में स्वचालित रूप से 12-अक्षर Hashid स्ट्रिंग में बदल जाती हैं, और अनुरोधों में Hashid स्ट्रिंग `HashidRequestMiddleware` द्वारा वापस पूर्णांक ID में डिकोड होती हैं।

## एडमिन इंटरफ़ेस

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | ऑपरेशन डैशबोर्ड |
| GET/PUT | `/admin/api/v1/users` | उपयोगकर्ता प्रबंधन |
| GET/POST | `/admin/api/v1/kyc` | KYC समीक्षा |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | उत्पाद प्रबंधन |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU बनाएँ |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | क्षेत्रीय मूल्य सेट करें |
| GET/POST | `/admin/api/v1/orders` | ऑर्डर प्रबंधन (रिफंड सहित) |
| GET | `/admin/api/v1/orders/export` | ऑर्डर निर्यात (.xlsx) |
| GET | `/admin/api/v1/users/export` | उपयोगकर्ता निर्यात (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | सप्लायर निर्यात (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | पेमेंट चैनल / ट्रांज़ैक्शन / रिकॉन्सिलिएशन |
| GET/POST | `/admin/api/v1/provisioning/*` | डिलीवरी कार्य / होस्ट प्रबंधन |
| GET/PUT | `/admin/api/v1/cdn/domains` | CDN डोमेन प्रबंधन (पैकेज परिवर्तन) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | सेवाप्रदाता खाता क्रेडेंशियल प्रबंधन (CDN/डिलीवरी साझा, Encryptable एन्क्रिप्शन) |
| GET/POST | `/admin/api/v1/suppliers/*` | सप्लायर अनुमोदन / सेटलमेंट / विदड्रॉल |
| GET/POST | `/admin/api/v1/tickets` | टिकट असाइनमेंट / बंद करना |
| GET | `/admin/api/v1/reports/*` | रेवेन्यू / क्षेत्रीय / सप्लायर रिपोर्ट |
| GET | `/admin/api/v1/monitor/*` | मॉनिटरिंग पैनल / संसाधन मेट्रिक्स |
| GET | `/admin/api/v1/audit-logs` | ऑडिट लॉग |
| PUT | `/admin/api/v1/system/config` | सिस्टम कॉन्फ़िगरेशन |
