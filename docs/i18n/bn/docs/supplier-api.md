# সাপ্লায়ার API ডকুমেন্টেশন v1

## ওভারভিউ

সাপ্লায়ার ফিচারে দুটি API সেট আছে:

| টাইপ | অথেনটিকেশন | প্রিফিক্স | স্ট্যাটাস |
|------|---------|------|------|
| **ইন্টারনাল API** | ইউজার Bearer Token | `/api/v1/supplier/` | উপলব্ধ |
| **এক্সটার্নাল API** | API Key (`sk_xxx`) | `/api/v1/supplier/external/` | উপলব্ধ |

**Base URL**: `https://api.example.com`

**ভার্সন কন্ট্রোল**: API ভার্সন URL পাথে থাকে (যেমন `/api/v1/...`), HTTP হেডারে নয়। অসমর্থিত ভার্সনে `400` রিটার্ন হয়। শুধু `/api/v1/*` ও `/admin/api/v1/*` পাথে কার্যকর, `VersionMiddleware`-এ ইউনিফাইড হ্যান্ডলিং হয়।

---

## ইন্টারনাল API (বর্তমানে উপলব্ধ)

ইন্টারনাল API প্ল্যাটফর্মের অন্যান্য ইন্টারফেসের মতো একই ইউজার Bearer Token অথেনটিকেশন ব্যবহার করে, লগইন করা সাপ্লায়ার ইউজারদের ক্লায়েন্ট/ফ্রন্টএন্ডে কল করার জন্য উপযুক্ত।

### অথেনটিকেশন

```
Authorization: Bearer <user_access_token>
```

ইউজারকে আগে `/api/v1/auth/login` দিয়ে লগইন করে Token পেতে হবে, এবং অ্যাকাউন্ট রোল `supplier` হতে হবে (অ্যাডমিন সাপ্লায়ার আবেদন অ্যাপ্রুভ করার পর সেট হয়)।

---

### রেসপন্স ফরম্যাট

#### সফল রেসপন্স

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### পেজিনেটেড রেসপন্স

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

#### এরর রেসপন্স

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | বিবরণ |
|------|------|
| 0 | সফল |
| 400 | রিকোয়েস্ট প্যারামিটার এরর / অসমর্থিত API ভার্সন |
| 401 | লগইন নেই বা Token এক্সপায়ার্ড |
| 403 | অ্যাক্সেস নেই (নন-সাপ্লায়ার রোল / পাসওয়ার্ড কনফার্মেশন ব্যর্থ) |
| 404 | রিসোর্স নেই |
| 422 | প্যারামিটার ভ্যালিডেশন ব্যর্থ |
| 429 | রিকোয়েস্ট রেট সীমা অতিক্রম |

---

### এন্ডপয়েন্ট

#### 1. সাপ্লায়ার অনবোর্ডিং

```
POST /api/v1/supplier/apply
```

সাপ্লায়ার হওয়ার আবেদন। প্রতিটি ইউজার শুধু একবার আবেদন করতে পারে।

**রিকোয়েস্ট বডি**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| প্যারামিটার | টাইপ | বাধ্যতামূলক | বিবরণ |
|------|------|------|------|
| company_name | string | হ্যাঁ | কোম্পানির নাম |
| contact_name | string | হ্যাঁ | যোগাযোগের ব্যক্তির নাম |
| contact_phone | string | হ্যাঁ | যোগাযোগের ফোন |
| contact_email | string | হ্যাঁ | যোগাযোগের ইমেইল |
| settlement_method | string | না | সেটেলমেন্ট পদ্ধতি, ডিফল্ট `bank` |

**রেসপন্স**: সাপ্লায়ার অবজেক্ট, স্ট্যাটাস `pending`

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

> সংবেদনশীল ফিল্ড (যোগাযোগ ব্যক্তির নাম, ফোন, ইমেইল) ডেটাবেসে এনক্রিপ্টেড স্টোর হয়, API রিটার্নে আংশিক ডিসেনসিটাইজড।

**এরর**:

| code | পরিস্থিতি |
|------|------|
| 422 | সাপ্লায়ার আবেদন ইতিমধ্যে জমা হয়েছে |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. প্রোডাক্ট ম্যানেজমেন্ট

##### অ্যাসাইন করা প্রোডাক্ট পাওয়া

```
GET /api/v1/supplier/products
```

**Query প্যারামিটার**:

| প্যারামিটার | টাইপ | বাধ্যতামূলক | বিবরণ |
|------|------|------|------|
| page | int | না | পেজ নম্বর, ডিফল্ট 1 |

**রেসপন্স**: পেজিনেটেড লিস্ট, প্রতিটি আইটেমে প্রোডাক্ট তথ্য ও কমিশন রেট

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

##### প্রোডাক্ট যোগ

```
POST /api/v1/supplier/products
```

বিদ্যমান প্রোডাক্ট বর্তমান সাপ্লায়ারের সাথে অ্যাসোসিয়েট করে।

**রিকোয়েস্ট বডি**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| প্যারামিটার | টাইপ | বাধ্যতামূলক | বিবরণ |
|------|------|------|------|
| product_id | string | হ্যাঁ | প্রোডাক্ট ID (Hashid) |
| commission_rate | float | না | কমিশন রেট, ডিফল্ট 0.1 |

**রেসপন্স**: তৈরি হওয়া SupplierProduct অবজেক্ট

**এরর**:

| code | পরিস্থিতি |
|------|------|
| 422 | প্রোডাক্ট ইতিমধ্যে এই সাপ্লায়ারকে অ্যাসাইন করা |

##### প্রোডাক্ট সরানো

```
DELETE /api/v1/supplier/products/{id}
```

প্রোডাক্ট ও সাপ্লায়ারের অ্যাসোসিয়েশন বাতিল করে।

**রেসপন্স**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. সেটেলমেন্ট ম্যানেজমেন্ট

##### সেটেলমেন্ট লিস্ট পাওয়া

```
GET /api/v1/supplier/settlements
```

**রেসপন্স**: বর্তমান সাপ্লায়ারের সব সেটেলমেন্ট, তৈরি সময়ের উল্টো ক্রমে

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

| ফিল্ড | বিবরণ |
|------|------|
| total_sales | পিরিয়ডে সম্পন্ন হওয়া অর্ডারের মোট বিক্রি |
| commission | প্ল্যাটফর্ম কমিশনের মোট |
| payable | সাপ্লায়ারকে প্রদেয় (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. উইথড্রয়াল

##### উইথড্রয়াল আবেদন

```
POST /api/v1/supplier/withdraw
```

> এই অপারেশনে পাসওয়ার্ড সেকেন্ডারি কনফার্মেশন প্রয়োজন (`confirm_password` ফিল্ড), `ConfirmationMiddleware` ভেরিফাই করে।
> ৫ বার ব্যর্থ হলে ১৫ মিনিট লক।

**রিকোয়েস্ট বডি**:

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

| প্যারামিটার | টাইপ | বাধ্যতামূলক | বিবরণ |
|------|------|------|------|
| amount | string | হ্যাঁ | উইথড্রয়াল অ্যামাউন্ট (ফ্লোট প্রিসিশন সমস্যা এড়াতে স্ট্রিং) |
| confirm_password | string | হ্যাঁ | ইউজার লগইন পাসওয়ার্ড (সেকেন্ডারি কনফার্মেশন) |
| account_info | object | হ্যাঁ | পেমেন্ট অ্যাকাউন্ট তথ্য |
| account_info.method | string | হ্যাঁ | উইথড্রয়াল পদ্ধতি: `bank_transfer` / `alipay` / `wechat` |

**উইথড্রেবল ব্যালেন্স ক্যালকুলেশন**: সব সম্পন্ন সেটেলমেন্টের `payable`-এর যোগফল - সব প্রসেসিং-এ থাকা উইথড্রয়ালের `amount`-এর যোগফল

**রেসপন্স**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**এরর**:

| code | পরিস্থিতি |
|------|------|
| 422 | উইথড্রেবল ব্যালেন্স অপর্যাপ্ত |
| 403 | পাসওয়ার্ড কনফার্মেশন ব্যর্থ |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### ইন্টারনাল API এন্ডপয়েন্ট সারাংশ

| মেথড | পাথ | অথেনটিকেশন | পাসওয়ার্ড কনফার্মেশন | বিবরণ |
|------|------|------|---------|------|
| POST | `/api/v1/supplier/apply` | Token | - | সাপ্লায়ার হওয়ার আবেদন |
| GET | `/api/v1/supplier/products` | Token | - | অ্যাসাইন করা প্রোডাক্ট দেখা |
| POST | `/api/v1/supplier/products` | Token | - | প্রোডাক্ট অ্যাসোসিয়েশন যোগ |
| DELETE | `/api/v1/supplier/products/{id}` | Token | - | প্রোডাক্ট অ্যাসোসিয়েশন সরানো |
| GET | `/api/v1/supplier/settlements` | Token | - | সেটেলমেন্ট দেখা |
| POST | `/api/v1/supplier/withdraw` | Token | প্রয়োজন | উইথড্রয়াল আবেদন |

---

## এক্সটার্নাল API (ডিজাইন স্পেক, বাস্তবায়ন বাকি)

এক্সটার্নাল API সাপ্লায়ারদের প্রোগ্রাম্যাটিকভাবে অর্ডার, রিসোর্স ও সেটেলমেন্ট ম্যানেজ করার সুযোগ দেয়। সব রিকোয়েস্টে API Key অথেনটিকেশন প্রয়োজন।

**Base URL**: `https://api.example.com/api/v1`

### অথেনটিকেশন

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API Key প্ল্যাটফর্ম অ্যাডমিন অ্যাডমিন প্যানেলের `সাপ্লায়ার ম্যানেজমেন্ট → API Keys`-এ তৈরি করে।

**সিকিউরিটি প্রয়োজনীয়তা**:
- শুধু HTTPS দিয়ে অ্যাক্সেস
- API Key শুধু তৈরির সময় একবার দেখানো হয়, নিরাপদে রাখুন
- সার্ভার IP হোয়াইটলিস্টে যোগ করার পরামর্শ

---

### রেসপন্স ফরম্যাট

ইন্টারনাল API-এর মতোই, অতিরিক্ত `request_id` ট্র্যাকিংয়ের জন্য:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### এন্ডপয়েন্ট

#### 1. অর্ডার ম্যানেজমেন্ট

##### অর্ডার লিস্ট পাওয়া

```
GET /api/v1/supplier/orders
```

**Query প্যারামিটার**:

| প্যারামিটার | টাইপ | বাধ্যতামূলক | বিবরণ |
|------|------|------|------|
| page | int | না | পেজ নম্বর, ডিফল্ট 1 |
| page_size | int | না | প্রতি পেজ সংখ্যা, ডিফল্ট 20, সর্বোচ্চ 50 |
| status | string | না | স্ট্যাটাস ফিল্টার: pending/paid/completed/refunded |
| from | date | না | শুরু তারিখ YYYY-MM-DD |
| to | date | না | শেষ তারিখ YYYY-MM-DD |

##### অর্ডার ডিটেইল পাওয়া

```
GET /api/v1/supplier/orders/{id}
```

---

#### 2. রিসোর্স ম্যানেজমেন্ট

##### রিসোর্স লিস্ট পাওয়া

```
GET /api/v1/supplier/resources
```

**Query প্যারামিটার**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### রিসোর্স স্ট্যাটাস পাওয়া

```
GET /api/v1/supplier/resources/{id}/status
```

---

#### 3. সেটেলমেন্ট ম্যানেজমেন্ট

##### সেটেলমেন্ট লিস্ট পাওয়া

```
GET /api/v1/supplier/settlements
```

##### সেটেলমেন্ট ডিটেইল পাওয়া

```
GET /api/v1/supplier/settlements/{id}
```

---

#### 4. উইথড্রয়াল

##### উইথড্রয়াল আবেদন

```
POST /api/v1/supplier/withdraw
```

##### উইথড্রয়াল রেকর্ড

```
GET /api/v1/supplier/withdraws
```

---

#### 5. প্রোডাক্ট ম্যানেজমেন্ট

##### আমার প্রোডাক্ট পাওয়া

```
GET /api/v1/supplier/products
```

##### প্রোডাক্ট লিস্টিং আবেদন জমা

```
POST /api/v1/supplier/products
```

---

### এক্সটার্নাল API এন্ডপয়েন্ট সারাংশ

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/api/v1/supplier/orders` | অর্ডার লিস্ট |
| GET | `/api/v1/supplier/orders/{id}` | অর্ডার ডিটেইল |
| GET | `/api/v1/supplier/resources` | রিসোর্স লিস্ট |
| GET | `/api/v1/supplier/resources/{id}/status` | রিসোর্স স্ট্যাটাস |
| GET | `/api/v1/supplier/settlements` | সেটেলমেন্ট লিস্ট |
| GET | `/api/v1/supplier/settlements/{id}` | সেটেলমেন্ট ডিটেইল |
| POST | `/api/v1/supplier/withdraw` | উইথড্রয়াল আবেদন |
| GET | `/api/v1/supplier/withdraws` | উইথড্রয়াল রেকর্ড |
| GET | `/api/v1/supplier/products` | প্রোডাক্ট লিস্ট |
| POST | `/api/v1/supplier/products` | প্রোডাক্ট জমা |

---

## Webhook (প্ল্যাটফর্ম ইভেন্ট গ্রহণ)

সাপ্লায়াররা রিয়েল-টাইম ইভেন্ট পেতে Webhook URL রেজিস্টার করতে পারে। অ্যাডমিন প্যানেলে কনফিগ করা হয়।

### ইভেন্ট টাইপ

| ইভেন্ট | ট্রিগার সময় |
|------|----------|
| `order.paid` | ইউজার পেমেন্ট সম্পন্ন |
| `order.refunded` | অর্ডার রিফান্ড হয়েছে |
| `resource.provisioned` | রিসোর্স প্রোভিশনিং সম্পন্ন |
| `resource.expiring` | রিসোর্স এক্সপায়ার হতে চলেছে (৭ দিনের মধ্যে) |
| `resource.destroyed` | রিসোর্স ডেস্ট্রয় হয়েছে |
| `settlement.created` | সেটেলমেন্ট জেনারেট হয়েছে |
| `withdrawal.approved` | উইথড্রয়াল অ্যাপ্রুভ হয়েছে |

### Webhook রিকোয়েস্ট ফরম্যাট

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

**সিগনেচার ভেরিফিকেশন**: `HMAC-SHA256(payload, webhook_secret)`

---

## রেট লিমিটিং

| এন্ডপয়েন্ট | সীমা |
|------|------|
| ইন্টারনাল API | প্রতি ইউজার 60 req/min (ডিফল্ট) |
| ইন্টারনাল API লগইন | 5 req/min |
| এক্সটার্নাল API | প্রতি API Key 120 req/min (`supplier_api` রুল, `RateLimitMiddleware` দিয়ে কার্যকর) |
| এক্সটার্নাল API উইথড্রয়াল | 10 req/min (সুপারিশকৃত মান, `config/security.php`-এ অ্যাডজাস্ট করা যায়) |

> এক্সটার্নাল API রেট লিমিট রুল `config/security.php`-এর `rate_limits.supplier_api`-এ ডিফাইন করা,
> `RateLimitMiddleware` `/api/v1/supplier/external/*` পাথে ইউনিফাইড প্রয়োগ করে (অ্যাটমিক INCR কাউন্টিং,
> Redis অনুপলব্ধ থাকলে পাস)।

রেট লিমিট হেডার:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK উদাহরণ

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

// সাপ্লায়ার হওয়ার আবেদন
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// সেটেলমেন্ট পাওয়া
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// উইথড্রয়াল আবেদন
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

# অ্যাসাইন করা প্রোডাক্ট পাওয়া
resp = requests.get('https://api.example.com/api/v1/supplier/products',
                     headers=headers)
products = resp.json()

# উইথড্রয়াল আবেদন
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

## এরর হ্যান্ডলিং পরামর্শ

1. **429 রেট লিমিট**: `Retry-After` সেকেন্ড অপেক্ষা করে রিট্রাই করুন
2. **401 আনঅথরাইজড**: Token বৈধ কিনা, এক্সপায়ার্ড কিনা চেক করুন
3. **403 ফরবিডেন**: অ্যাকাউন্ট রোল `supplier` কিনা চেক করুন; পাসওয়ার্ড কনফার্মেশন ব্যর্থ হলে লক রিলিজের অপেক্ষা করুন
4. **422 ভ্যালিডেশন ব্যর্থ**: `message` ফিল্ড অনুযায়ী রিকোয়েস্ট প্যারামিটার সংশোধন করুন
5. **5xx সার্ভার এরর**: এক্সপোনেনশিয়াল ব্যাকঅফ রিট্রাই (1s -> 5s -> 25s)

---

## অ্যাডমিন প্যানেল এন্ডপয়েন্ট রেফারেন্স

সাপ্লায়ার ম্যানেজ করার অ্যাডমিন-সম্পর্কিত এন্ডপয়েন্ট (শুধু ব্যাকএন্ড ব্যবহারের জন্য, Admin রোল প্রয়োজন):

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/admin/api/v1/suppliers` | সাপ্লায়ার লিস্ট (status ফিল্টার সাপোর্ট) |
| GET | `/admin/api/v1/suppliers/export` | সাপ্লায়ার Excel এক্সপোর্ট |
| POST | `/admin/api/v1/suppliers/{id}/approve` | সাপ্লায়ার অ্যাপ্রুভ |
| POST | `/admin/api/v1/suppliers/{id}/settle` | সেটেলমেন্ট জেনারেট |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | উইথড্রয়াল অ্যাপ্রুভ |
| GET | `/admin/api/v1/suppliers/{id}/api-keys` | সাপ্লায়ার API Key লিস্ট দেখা |
| POST | `/admin/api/v1/suppliers/{id}/api-keys` | API Key তৈরি (শুধু একবার র ক Key রিটার্ন করে) |
| DELETE | `/admin/api/v1/suppliers/api-keys/{id}` | API Key রিভোক |
