# API अवलोकन

> पूर्ण API संदर्भ (200+ एंडपॉइंट्स, अनुरोध/प्रतिक्रिया उदाहरण और त्रुटि कोड): [API संदर्भ](api-reference.md)
> ऑनलाइन डीबगिंग: [service API दस्तावेज़](http://localhost:8787/apidoc) · [admin API दस्तावेज़](http://localhost:8788/apidoc)

## सार्वजनिक एंडपॉइंट्स

| विधि | पथ | विवरण |
|--------|------|-------------|
| GET | `/health` | हेल्थ चेक |
| POST | `/api/v1/auth/register` | पंजीकरण (बॉडी AES-256-GCM एन्क्रिप्टेड) |
| POST | `/api/v1/auth/login` | लॉगिन (बॉडी AES-256-GCM एन्क्रिप्टेड) |
| POST | `/api/v1/auth/refresh` | टोकन रीफ़्रेश (बॉडी AES-256-GCM एन्क्रिप्टेड) |
| POST | `/api/v1/captcha/create` | क्लिक कैप्चा उत्पन्न करें (लॉगिन/रजिस्ट्रेशन से पहले आवश्यक) |
| GET | `/api/v1/products` | उत्पाद सूची (श्रेणी/क्षेत्र/कीवर्ड द्वारा फ़िल्टर योग्य) |
| GET | `/api/v1/products/{id}` | उत्पाद विवरण (id एक hashid स्ट्रिंग है) |
| GET | `/api/v1/regions` | उपलब्ध क्षेत्र |
| GET | `/api/v1/domain/check/{domain}/{tld}` | डोमेन उपलब्धता जाँच |
| GET | `/api/v1/domain/tlds` | उपलब्ध TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe webhook (हस्ताक्षर सत्यापित, कोई एन्क्रिप्शन नहीं) |

## प्रमाणित एंडपॉइंट्स (Bearer Token)

| विधि | पथ | विवरण |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | प्रोफ़ाइल प्राप्त करें |
| PUT | `/api/v1/user/profile` | प्रोफ़ाइल अपडेट करें |
| POST | `/api/v1/user/kyc` | KYC सबमिट करें |
| GET | `/api/v1/user/balance` | खाता शेष |
| GET/POST | `/api/v1/cart` | शॉपिंग कार्ट |
| POST/GET | `/api/v1/orders` | ऑर्डर |
| GET | `/api/v1/orders/{id}/payment-methods` | उपलब्ध भुगतान विधियाँ |
| POST | `/api/v1/orders/{id}/pay` | भुगतान आरंभ करें |
| GET/POST | `/api/v1/resources` | मेरे संसाधन |
| GET | `/api/v1/resources/{id}/status` | संसाधन स्थिति |
| GET | `/api/v1/resources/{id}/console` | VNC कंसोल URL |
| GET/POST | `/api/v1/tickets` | समर्थन टिकट |
| POST | `/api/v1/tickets/{id}/reply` | टिकट का उत्तर दें |
| GET/POST | `/api/v1/dns/{domain}` | DNS प्रबंधन |
| POST | `/api/v1/supplier/apply` | सप्लायर के रूप में आवेदन करें |
| GET | `/api/v1/supplier/settlements` | सेटलमेंट इतिहास |
| POST | `/api/v1/supplier/withdraw` | विदड्रॉल अनुरोध |

> **टिप्पणी:** API संस्करण URL पथ में होता है (जैसे `/api/v1/...`)। प्रमाणित और एडमिन एंडपॉइंट्स `EncryptionMiddleware` से प्रोसेस होते हैं। क्लाइंट `X-Encrypted: 1` हेडर सेट करते हैं और बॉडी को `{"payload": "<base64(AES-256-GCM)>"}` के रूप में लपेटते हैं। प्रतिक्रियाएँ भी एन्क्रिप्ट होकर `payload` फ़ील्ड में लिपटी होती हैं। API प्रतिक्रियाओं में पूर्णांक ID स्वचालित रूप से 12-अक्षर Hashid स्ट्रिंग में बदल जाती हैं; अनुरोधों में Hashid स्ट्रिंग `HashidRequestMiddleware` द्वारा वापस पूर्णांक ID में डिकोड होती हैं।

## एडमिन एंडपॉइंट्स

| विधि | पथ | विवरण |
|--------|------|-------------|
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
| GET/PUT | `/admin/api/v1/payments/*` | चैनल / ट्रांज़ैक्शन / रिकॉन्सिलिएशन |
| GET/POST | `/admin/api/v1/provisioning/*` | डिलीवरी कार्य / होस्ट प्रबंधन |
| GET/POST | `/admin/api/v1/suppliers/*` | सप्लायर अनुमोदन / सेटलमेंट / विदड्रॉल |
| GET/POST | `/admin/api/v1/tickets` | टिकट असाइनमेंट / समापन |
| GET | `/admin/api/v1/reports/*` | रेवेन्यू / क्षेत्रीय / सप्लायर रिपोर्ट |
| GET | `/admin/api/v1/monitor/*` | मॉनिटरिंग डैशबोर्ड / संसाधन मेट्रिक्स |
| GET | `/admin/api/v1/audit-logs` | ऑडिट लॉग |
| PUT | `/admin/api/v1/system/config` | सिस्टम कॉन्फ़िगरेशन अपडेट |
