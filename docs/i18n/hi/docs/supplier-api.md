# आपूर्तिकर्ता API दस्तावेज़ v1

## अवलोकन

आपूर्तिकर्ता सुविधा दो API प्रदान करती है:

| प्रकार | प्रमाणीकरण विधि | प्रीफ़िक्स | स्थिति |
|------|---------|------|------|
| **आंतरिक API** | उपयोगकर्ता Bearer Token | `/api/v1/supplier/` | उपलब्ध |
| **बाहरी API** | API Key (`sk_xxx`) | `/api/v1/supplier/external/` | उपलब्ध |

**Base URL**: `https://api.example.com`

**संस्करण नियंत्रण**: API संस्करण URL पथ में निर्दिष्ट है (जैसे `/api/v1/...`)। असमर्थित संस्करण `400` लौटाता है। केवल `/api/v1/*` और `/admin/api/v1/*` पथों पर प्रभावी, `VersionMiddleware` द्वारा एकीकृत रूप से संभाला जाता है।

---

## आंतरिक API (वर्तमान में उपलब्ध)

आंतरिक API प्लेटफ़ॉर्म के अन्य इंटरफ़ेस के समान उपयोगकर्ता Bearer Token प्रमाणीकरण का उपयोग करता है, लॉग इन किए हुए आपूर्तिकर्ता उपयोगकर्ताओं द्वारा क्लाइंट/फ्रंटएंड में कॉल के लिए उपयुक्त।

### प्रमाणीकरण

```
Authorization: Bearer <user_access_token>
```

उपयोगकर्ता को पहले `/api/v1/auth/login` के माध्यम से लॉगिन करके Token प्राप्त करना होता है, और खाता भूमिका `supplier` होनी चाहिए (प्रशासक द्वारा आपूर्तिकर्ता आवेदन स्वीकृत करने के बाद सेट की जाती है)।

---

### प्रतिक्रिया प्रारूप

#### सफल प्रतिक्रिया

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### पृष्ठांकित प्रतिक्रिया

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### त्रुटि प्रतिक्रिया

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | विवरण |
|------|------|
| 0 | सफल |
| 400 | अनुरोध पैरामीटर त्रुटि / असमर्थित API संस्करण |
| 401 | लॉगिन नहीं या Token समाप्त |
| 403 | एक्सेस अनुमति नहीं (गैर-आपूर्तिकर्ता भूमिका / पासवर्ड पुष्टि विफल) |
| 404 | संसाधन मौजूद नहीं |
| 422 | पैरामीटर सत्यापन विफल |
| 429 | अनुरोध आवृत्ति सीमा से अधिक |

---

### एंडपॉइंट

#### 1. आपूर्तिकर्ता पंजीकरण

```
POST /api/v1/supplier/apply
```

आपूर्तिकर्ता बनने के लिए आवेदन करें। प्रत्येक उपयोगकर्ता केवल एक बार आवेदन कर सकता है।

**अनुरोध बॉडी**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| पैरामीटर | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| company_name | string | हाँ | कंपनी का नाम |
| contact_name | string | हाँ | संपर्क व्यक्ति का नाम |
| contact_phone | string | हाँ | संपर्क फ़ोन |
| contact_email | string | हाँ | संपर्क ईमेल |
| settlement_method | string | नहीं | निपटान विधि, डिफ़ॉल्ट `bank` |

**प्रतिक्रिया**: आपूर्तिकर्ता ऑब्जेक्ट, स्थिति `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> संवेदनशील फ़ील्ड (संपर्क व्यक्ति का नाम, फ़ोन, ईमेल) डेटाबेस में एन्क्रिप्टेड संग्रहीत होते हैं, API लौटाते समय आंशिक रूप से मास्क किए जाते हैं।

**त्रुटियाँ**:

| code | परिदृश्य |
|------|------|
| 422 | आवेदन पहले ही जमा हो चुका |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. उत्पाद प्रबंधन

##### असाइन किए गए उत्पाद प्राप्त करें

```
GET /api/v1/supplier/products
```

**Query पैरामीटर**:

| पैरामीटर | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| page | int | नहीं | पृष्ठ संख्या, डिफ़ॉल्ट 1 |

**प्रतिक्रिया**: पृष्ठांकित सूची, प्रत्येक आइटम में उत्पाद जानकारी और कमीशन अनुपात

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### उत्पाद जोड़ें

```
POST /api/v1/supplier/products
```

मौजूदा उत्पाद को वर्तमान आपूर्तिकर्ता से संबद्ध करें।

**अनुरोध बॉडी**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| पैरामीटर | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| product_id | string | हाँ | उत्पाद ID (Hashid) |
| commission_rate | float | नहीं | कमीशन अनुपात, डिफ़ॉल्ट 0.1 |

**प्रतिक्रिया**: बनाया गया SupplierProduct ऑब्जेक्ट

**त्रुटियाँ**:

| code | परिदृश्य |
|------|------|
| 422 | उत्पाद पहले से इस आपूर्तिकर्ता को असाइन है |

##### उत्पाद हटाएं

```
DELETE /api/v1/supplier/products/{id}
```

उत्पाद और आपूर्तिकर्ता के बीच संबद्धता रद्द करें।

**प्रतिक्रिया**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. निपटान प्रबंधन

##### निपटान रिपोर्ट सूची प्राप्त करें

```
GET /api/v1/supplier/settlements
```

**प्रतिक्रिया**: वर्तमान आपूर्तिकर्ता की सभी निपटान रिपोर्ट, निर्माण समय के अनुसार उल्टे क्रम में

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| फ़ील्ड | विवरण |
|------|------|
| total_sales | अवधि में पूर्ण हुए ऑर्डरों की कुल बिक्री |
| commission | प्लेटफ़ॉर्म कमीशन की कुल राशि |
| payable | आपूर्तिकर्ता को देय राशि (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. निकासी

##### निकासी के लिए आवेदन करें

```
POST /api/v1/supplier/withdraw
```

> इस संचालन के लिए पासवर्ड द्वितीयक पुष्टि (`confirm_password` फ़ील्ड) आवश्यक है, `ConfirmationMiddleware` द्वारा सत्यापित।
> 5 बार विफलता के बाद 15 मिनट के लिए लॉक।

**अनुरोध बॉडी**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| पैरामीटर | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| amount | string | हाँ | निकासी राशि (फ्लोट परिशुद्धता समस्याओं से बचने के लिए स्ट्रिंग) |
| confirm_password | string | हाँ | उपयोगकर्ता लॉगिन पासवर्ड (द्वितीयक पुष्टि) |
| account_info | object | हाँ | प्राप्तकर्ता खाता जानकारी |
| account_info.method | string | हाँ | निकासी विधि: `bank_transfer` / `alipay` / `wechat` |

**निकासी योग्य शेष गणना**: सभी पूर्ण निपटान रिपोर्टों के `payable` का योग - सभी प्रक्रिया में निकासियों के `amount` का योग

**प्रतिक्रिया**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**त्रुटियाँ**:

| code | परिदृश्य |
|------|------|
| 422 | निकासी योग्य शेष अपर्याप्त |
| 403 | पासवर्ड पुष्टि विफल |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### आंतरिक API एंडपॉइंट सारांश

| विधि | पथ | प्रमाणीकरण | पासवर्ड पुष्टि | विवरण |
|------|------|------|---------|------|
| POST | `/api/v1/supplier/apply` | Token | - | आपूर्तिकर्ता बनने के लिए आवेदन |
| GET | `/api/v1/supplier/products` | Token | - | असाइन किए गए उत्पाद देखें |
| POST | `/api/v1/supplier/products` | Token | - | उत्पाद संबद्धता जोड़ें |
| DELETE | `/api/v1/supplier/products/{id}` | Token | - | उत्पाद संबद्धता हटाएं |
| GET | `/api/v1/supplier/settlements` | Token | - | निपटान रिपोर्ट देखें |
| POST | `/api/v1/supplier/withdraw` | Token | आवश्यक | निकासी के लिए आवेदन करें |

---

## बाहरी API (डिज़ाइन स्पेक, लागू होना बाकी)

बाहरी API आपूर्तिकर्ताओं को प्रोग्रामेटिक रूप से ऑर्डर, संसाधन और निपटान प्रबंधित करने की अनुमति देता है। सभी अनुरोधों के लिए API Key प्रमाणीकरण आवश्यक है।

**Base URL**: `https://api.example.com/api`

### प्रमाणीकरण

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API Key प्लेटफ़ॉर्म प्रशासक द्वारा प्रशासन पैनल के `供应商管理 → API Keys` में बनाई जाती है।

**सुरक्षा आवश्यकताएँ**:
- केवल HTTPS के माध्यम से एक्सेस करें
- API Key केवल निर्माण के समय एक बार दिखाई जाती है, कृपया सुरक्षित रखें
- सर्वर IP को व्हाइटलिस्ट में जोड़ने की अनुशंसा

---

### प्रतिक्रिया प्रारूप

आंतरिक API के समान, ट्रैकिंग के लिए अतिरिक्त `request_id` शामिल:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### एंडपॉइंट

#### 1. ऑर्डर प्रबंधन

##### ऑर्डर सूची प्राप्त करें

```
GET /api/v1/supplier/orders
```

**Query पैरामीटर**:

| पैरामीटर | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| page | int | नहीं | पृष्ठ संख्या, डिफ़ॉल्ट 1 |
| page_size | int | नहीं | प्रति पृष्ठ संख्या, डिफ़ॉल्ट 20, अधिकतम 50 |
| status | string | नहीं | फ़िल्टर स्थिति: pending/paid/completed/refunded |
| from | date | नहीं | आरंभ तिथि YYYY-MM-DD |
| to | date | नहीं | समाप्ति तिथि YYYY-MM-DD |

##### ऑर्डर विवरण प्राप्त करें

```
GET /api/v1/supplier/orders/{id}
```

---

#### 2. संसाधन प्रबंधन

##### संसाधन सूची प्राप्त करें

```
GET /api/v1/supplier/resources
```

**Query पैरामीटर**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### संसाधन स्थिति प्राप्त करें

```
GET /api/v1/supplier/resources/{id}/status
```

---

#### 3. निपटान प्रबंधन

##### निपटान रिपोर्ट सूची प्राप्त करें

```
GET /api/v1/supplier/settlements
```

##### निपटान रिपोर्ट विवरण प्राप्त करें

```
GET /api/v1/supplier/settlements/{id}
```

---

#### 4. निकासी

##### निकासी के लिए आवेदन करें

```
POST /api/v1/supplier/withdraw
```

##### निकासी रिकॉर्ड

```
GET /api/v1/supplier/withdraws
```

---

#### 5. उत्पाद प्रबंधन

##### मेरे उत्पाद प्राप्त करें

```
GET /api/v1/supplier/products
```

##### उत्पाद सूचीकरण आवेदन जमा करें

```
POST /api/v1/supplier/products
```

---

### बाहरी API एंडपॉइंट सारांश

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/api/v1/supplier/orders` | ऑर्डर सूची |
| GET | `/api/v1/supplier/orders/{id}` | ऑर्डर विवरण |
| GET | `/api/v1/supplier/resources` | संसाधन सूची |
| GET | `/api/v1/supplier/resources/{id}/status` | संसाधन स्थिति |
| GET | `/api/v1/supplier/settlements` | निपटान रिपोर्ट सूची |
| GET | `/api/v1/supplier/settlements/{id}` | निपटान रिपोर्ट विवरण |
| POST | `/api/v1/supplier/withdraw` | निकासी के लिए आवेदन करें |
| GET | `/api/v1/supplier/withdraws` | निकासी रिकॉर्ड |
| GET | `/api/v1/supplier/products` | उत्पाद सूची |
| POST | `/api/v1/supplier/products` | उत्पाद जमा करें |

---

## Webhook (प्लेटफ़ॉर्म इवेंट प्राप्त करना)

आपूर्तिकर्ता वास्तविक समय इवेंट प्राप्त करने के लिए Webhook URL पंजीकृत कर सकते हैं। प्रशासन पैनल में कॉन्फ़िगर करें।

### इवेंट प्रकार

| इवेंट | ट्रिगर समय |
|------|----------|
| `order.paid` | उपयोगकर्ता ने भुगतान पूरा किया |
| `order.refunded` | ऑर्डर रिफ़ंड किया गया |
| `resource.provisioned` | संसाधन प्रोविज़न पूर्ण |
| `resource.expiring` | संसाधन समाप्ति के निकट (7 दिन के भीतर) |
| `resource.destroyed` | संसाधन नष्ट कर दिया गया |
| `settlement.created` | निपटान रिपोर्ट बनाई गई |
| `withdrawal.approved` | निकासी स्वीकृत |

### Webhook अनुरोध प्रारूप

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**हस्ताक्षर सत्यापन**: `HMAC-SHA256(payload, webhook_secret)`

---

## दर सीमा

| एंडपॉइंट | सीमा |
|------|------|
| आंतरिक API | 60 req/min प्रति उपयोगकर्ता (डिफ़ॉल्ट) |
| आंतरिक API लॉगिन | 5 req/min |
| बाहरी API | 120 req/min प्रति API Key (`supplier_api` नियम, `RateLimitMiddleware` के माध्यम से प्रभावी) |
| बाहरी API निकासी | 10 req/min (अनुशंसित मान, `config/security.php` में समायोज्य) |

> बाहरी API दर सीमा नियम `config/security.php` के `rate_limits.supplier_api` में परिभाषित है,
> `RateLimitMiddleware` द्वारा `/api/v1/supplier/external/*` पथों पर एकीकृत रूप से लागू (परमाणु INCR गणना,
> Redis अनुपलब्ध होने पर अनुमति दी जाती है)।

दर सीमा हेडर:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK उदाहरण

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/v1/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
    ],
]);

// आपूर्तिकर्ता बनने के लिए आवेदन करें
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// निपटान रिपोर्ट प्राप्त करें
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// निकासी के लिए आवेदन करें
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
}

# असाइन किए गए उत्पाद प्राप्त करें
resp = requests.get('https://api.example.com/api/v1/supplier/products',
                     headers=headers)
products = resp.json()

# निकासी के लिए आवेदन करें
resp = requests.post('https://api.example.com/api/v1/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## त्रुटि प्रबंधन अनुशंसाएँ

1. **429 दर सीमा**: `Retry-After` सेकंड प्रतीक्षा करके पुनः प्रयास करें
2. **401 अनधिकृत**: जाँचें कि Token मान्य है या नहीं, समाप्त तो नहीं हुआ
3. **403 निषिद्ध**: जाँचें कि खाता भूमिका `supplier` है या नहीं; पासवर्ड पुष्टि विफलता पर लॉक समाप्ति की प्रतीक्षा करें
4. **422 सत्यापन विफल**: `message` फ़ील्ड के अनुसार अनुरोध पैरामीटर सुधारें
5. **5xx सर्वर त्रुटि**: घातीय बैकऑफ़ पुनः प्रयास (1s -> 5s -> 25s)

---

## प्रशासन पैनल एंडपॉइंट संदर्भ

निम्नलिखित प्रशासकों द्वारा आपूर्तिकर्ताओं के प्रबंधन से संबंधित एंडपॉइंट हैं (केवल बैकएंड उपयोग के लिए, Admin भूमिका आवश्यक):

| विधि | पथ | विवरण |
|------|------|------|
| GET | `/admin/api/v1/suppliers` | आपूर्तिकर्ता सूची (status फ़िल्टर समर्थित) |
| GET | `/admin/api/v1/suppliers/export` | आपूर्तिकर्ता Excel निर्यात |
| POST | `/admin/api/v1/suppliers/{id}/approve` | आपूर्तिकर्ता स्वीकृत करें |
| POST | `/admin/api/v1/suppliers/{id}/settle` | निपटान रिपोर्ट बनाएं |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | निकासी स्वीकृत करें |
| GET | `/admin/api/v1/suppliers/{id}/api-keys` | आपूर्तिकर्ता API Key सूची देखें |
| POST | `/admin/api/v1/suppliers/{id}/api-keys` | API Key बनाएं (कच्ची Key केवल एक बार लौटाई जाती है) |
| DELETE | `/admin/api/v1/suppliers/api-keys/{id}` | API Key रद्द करें |
