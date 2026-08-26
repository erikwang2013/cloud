# API अवलोकन

> पूर्ण API संदर्भ (200+ एंडपॉइंट्स, अनुरोध/प्रतिक्रिया उदाहरण और त्रुटि कोड): [API संदर्भ](api-reference.md)
> ऑनलाइन डीबगिंग: [service API दस्तावेज़](http://localhost:8787/apidoc) · [admin API दस्तावेज़](http://localhost:8788/apidoc)

## सार्वजनिक एंडपॉइंट्स

| विधि | पथ | विवरण |
|--------|------|-------------|
| GET | `/health` | हेल्थ चेक |
| POST | `/api/auth/register` | पंजीकरण (बॉडी AES-256-GCM एन्क्रिप्टेड) |
| POST | `/api/auth/login` | लॉगिन (बॉडी AES-256-GCM एन्क्रिप्टेड) |
| POST | `/api/auth/refresh` | टोकन रीफ़्रेश (बॉडी AES-256-GCM एन्क्रिप्टेड) |
| POST | `/api/captcha/create` | क्लिक कैप्चा उत्पन्न करें (लॉगिन/रजिस्ट्रेशन से पहले आवश्यक) |
| GET | `/api/products` | उत्पाद सूची (श्रेणी/क्षेत्र/कीवर्ड द्वारा फ़िल्टर योग्य) |
| GET | `/api/products/{id}` | उत्पाद विवरण (id एक hashid स्ट्रिंग है) |
| GET | `/api/regions` | उपलब्ध क्षेत्र |
| GET | `/api/domain/check/{domain}/{tld}` | डोमेन उपलब्धता जाँच |
| GET | `/api/domain/tlds` | उपलब्ध TLDs |
| POST | `/api/payments/webhook/stripe` | Stripe webhook (हस्ताक्षर सत्यापित, कोई एन्क्रिप्शन नहीं) |

## प्रमाणित एंडपॉइंट्स (Bearer Token)

| विधि | पथ | विवरण |
|--------|------|-------------|
| GET | `/api/user/profile` | प्रोफ़ाइल प्राप्त करें |
| PUT | `/api/user/profile` | प्रोफ़ाइल अपडेट करें |
| POST | `/api/user/kyc` | KYC सबमिट करें |
| GET | `/api/user/balance` | खाता शेष |
| GET/POST | `/api/cart` | शॉपिंग कार्ट |
| POST/GET | `/api/orders` | ऑर्डर |
| GET | `/api/orders/{id}/payment-methods` | उपलब्ध भुगतान विधियाँ |
| POST | `/api/orders/{id}/pay` | भुगतान आरंभ करें |
| GET/POST | `/api/resources` | मेरे संसाधन |
| GET | `/api/resources/{id}/status` | संसाधन स्थिति |
| GET | `/api/resources/{id}/console` | VNC कंसोल URL |
| GET/POST | `/api/tickets` | समर्थन टिकट |
| POST | `/api/tickets/{id}/reply` | टिकट का उत्तर दें |
| GET/POST | `/api/dns/{domain}` | DNS प्रबंधन |
| POST | `/api/supplier/apply` | सप्लायर के रूप में आवेदन करें |
| GET | `/api/supplier/settlements` | सेटलमेंट इतिहास |
| POST | `/api/supplier/withdraw` | विदड्रॉल अनुरोध |

> **टिप्पणी:** सभी API अनुरोधों में `X-Api-Version: v1` हेडर शामिल होना चाहिए (छोड़े जाने पर डिफ़ॉल्ट `v1`, `VersionMiddleware` द्वारा सत्यापित)। प्रमाणित और एडमिन एंडपॉइंट्स `EncryptionMiddleware` से प्रोसेस होते हैं। क्लाइंट `X-Encrypted: 1` हेडर सेट करते हैं और बॉडी को `{"payload": "<base64(AES-256-GCM)>"}` के रूप में लपेटते हैं। प्रतिक्रियाएँ भी एन्क्रिप्ट होकर `payload` फ़ील्ड में लिपटी होती हैं। API प्रतिक्रियाओं में पूर्णांक ID स्वचालित रूप से 12-अक्षर Hashid स्ट्रिंग में बदल जाती हैं; अनुरोधों में Hashid स्ट्रिंग `HashidRequestMiddleware` द्वारा वापस पूर्णांक ID में डिकोड होती हैं।

## एडमिन एंडपॉइंट्स

| विधि | पथ | विवरण |
|--------|------|-------------|
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
| GET/PUT | `/admin/api/payments/*` | चैनल / ट्रांज़ैक्शन / रिकॉन्सिलिएशन |
| GET/POST | `/admin/api/provisioning/*` | डिलीवरी कार्य / होस्ट प्रबंधन |
| GET/POST | `/admin/api/suppliers/*` | सप्लायर अनुमोदन / सेटलमेंट / विदड्रॉल |
| GET/POST | `/admin/api/tickets` | टिकट असाइनमेंट / समापन |
| GET | `/admin/api/reports/*` | रेवेन्यू / क्षेत्रीय / सप्लायर रिपोर्ट |
| GET | `/admin/api/monitor/*` | मॉनिटरिंग डैशबोर्ड / संसाधन मेट्रिक्स |
| GET | `/admin/api/audit-logs` | ऑडिट लॉग |
| PUT | `/admin/api/system/config` | सिस्टम कॉन्फ़िगरेशन अपडेट |
