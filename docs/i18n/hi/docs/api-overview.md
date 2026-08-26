# API अवलोकन

> पूर्ण इंटरफ़ेस संदर्भ (200+ एंडपॉइंट्स, अनुरोध/प्रतिक्रिया उदाहरण और त्रुटि कोड सहित): [API इंटरफ़ेस दस्तावेज़](api-reference.md)
> ऑनलाइन डीबगिंग: [service API दस्तावेज़](http://localhost:8787/apidoc) · [admin API दस्तावेज़](http://localhost:8788/apidoc)

## सार्वजनिक इंटरफ़ेस

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/health` | हेल्थ चेक |
| POST | `/api/auth/register` | उपयोगकर्ता पंजीकरण (अनुरोध बॉडी AES-256-GCM एन्क्रिप्टेड होनी चाहिए) |
| POST | `/api/auth/login` | उपयोगकर्ता लॉगिन (अनुरोध बॉडी AES-256-GCM एन्क्रिप्टेड होनी चाहिए) |
| POST | `/api/auth/refresh` | टोकन रीफ़्रेश (अनुरोध बॉडी AES-256-GCM एन्क्रिप्टेड होनी चाहिए) |
| POST | `/api/captcha/create` | क्लिक कैप्चा उत्पन्न करें (लॉगिन/रजिस्ट्रेशन से पहले प्राप्त करें) |
| GET | `/api/products` | उत्पाद सूची (श्रेणी/क्षेत्र/कीवर्ड फ़िल्टर समर्थित) |
| GET | `/api/products/{id}` | उत्पाद विवरण (id एक hashid स्ट्रिंग है) |
| GET | `/api/regions` | उपलब्ध क्षेत्र |
| GET | `/api/domain/check/{domain}/{tld}` | डोमेन उपलब्धता जाँच |
| GET | `/api/domain/tlds` | पंजीकरण योग्य एक्सटेंशन सूची |
| POST | `/api/payments/webhook/stripe` | Stripe कॉलबैक (हस्ताक्षर सत्यापन, एन्क्रिप्शन आवश्यक नहीं) |

## प्रमाणित इंटरफ़ेस (Bearer Token आवश्यक)

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/api/user/profile` | व्यक्तिगत जानकारी |
| PUT | `/api/user/profile` | जानकारी अपडेट करें |
| POST | `/api/user/kyc` | वास्तविक नाम सत्यापन (KYC) सबमिट करें |
| GET | `/api/user/balance` | खाता शेष |
| GET/POST | `/api/cart` | शॉपिंग कार्ट |
| POST/GET | `/api/orders` | ऑर्डर |
| GET | `/api/orders/{id}/payment-methods` | उपलब्ध भुगतान विधियाँ |
| POST | `/api/orders/{id}/pay` | भुगतान शुरू करें |
| GET/POST | `/api/resources` | मेरे संसाधन |
| GET | `/api/resources/{id}/status` | संसाधन स्थिति |
| GET | `/api/resources/{id}/console` | VNC कंसोल लिंक |
| GET/POST | `/api/tickets` | टिकट |
| POST | `/api/tickets/{id}/reply` | टिकट उत्तर |
| GET/POST | `/api/dns/{domain}` | DNS प्रबंधन |
| POST | `/api/supplier/apply` | सप्लायर आवेदन |
| GET | `/api/supplier/settlements` | सप्लायर सेटलमेंट रिकॉर्ड |
| POST | `/api/supplier/withdraw` | सप्लायर विदड्रॉल |

> **टिप्पणी:** सभी API अनुरोधों में `X-Api-Version: v1` हेडर होना आवश्यक है (अनुपलब्ध होने पर डिफ़ॉल्ट `v1`, `VersionMiddleware` द्वारा सत्यापित)। प्रमाणित इंटरफ़ेस और एडमिन इंटरफ़ेस के अनुरोध/प्रतिक्रिया `EncryptionMiddleware` से गुजरते हैं। क्लाइंट `X-Encrypted: 1` हेडर सेट करता है, अनुरोध बॉडी का प्रारूप `{"payload": "<base64(AES-256-GCM)>"}` है, प्रतिक्रिया बॉडी भी एन्क्रिप्ट होकर `payload` फ़ील्ड में लिपटी होती है। सभी पूर्णांक ID API प्रतिक्रियाओं में स्वचालित रूप से 12-अक्षर Hashid स्ट्रिंग में बदल जाती हैं, और अनुरोधों में Hashid स्ट्रिंग `HashidRequestMiddleware` द्वारा वापस पूर्णांक ID में डिकोड होती हैं।

## एडमिन इंटरफ़ेस

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/admin/api/dashboard` | ऑपरेशन डैशबोर्ड |
| GET/PUT | `/admin/api/users` | उपयोगकर्ता प्रबंधन |
| GET/POST | `/admin/api/kyc` | KYC समीक्षा |
| GET/POST/PUT/DELETE | `/admin/api/products` | उत्पाद प्रबंधन |
| POST | `/admin/api/products/{productId}/skus` | SKU बनाएँ |
| POST | `/admin/api/skus/{skuId}/region-price` | क्षेत्रीय मूल्य सेट करें |
| GET/POST | `/admin/api/orders` | ऑर्डर प्रबंधन (रिफंड सहित) |
| GET | `/admin/api/orders/export` | ऑर्डर निर्यात (.xlsx) |
| GET | `/admin/api/users/export` | उपयोगकर्ता निर्यात (.xlsx) |
| GET | `/admin/api/suppliers/export` | सप्लायर निर्यात (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | पेमेंट चैनल / ट्रांज़ैक्शन / रिकॉन्सिलिएशन |
| GET/POST | `/admin/api/provisioning/*` | डिलीवरी कार्य / होस्ट प्रबंधन |
| GET/POST | `/admin/api/suppliers/*` | सप्लायर अनुमोदन / सेटलमेंट / विदड्रॉल |
| GET/POST | `/admin/api/tickets` | टिकट असाइनमेंट / बंद करना |
| GET | `/admin/api/reports/*` | रेवेन्यू / क्षेत्रीय / सप्लायर रिपोर्ट |
| GET | `/admin/api/monitor/*` | मॉनिटरिंग पैनल / संसाधन मेट्रिक्स |
| GET | `/admin/api/audit-logs` | ऑडिट लॉग |
| PUT | `/admin/api/system/config` | सिस्टम कॉन्फ़िगरेशन |
