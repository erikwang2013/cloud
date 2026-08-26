# وثيقة واجهة المورد API v1

## نظرة عامة

توفر وظائف الموردين مجموعتين من الواجهات:

| النوع | طريقة المصادقة | البادئة | الحالة |
|------|---------|------|------|
| **الواجهة الداخلية** | رمز المستخدم Bearer Token | `/api/supplier/` | متاحة |
| **الواجهة الخارجية** | مفتاح API (`sk_xxx`) | `/api/supplier/external/` | متاحة |

**Base URL**: `https://api.example.com`

**إدارة الإصدارات**: تُحدد عبر رأس HTTP `X-Api-Version: v1`. عند غيابه يفترض `v1`، وتُعيد الإصدارات غير المدعومة `400`. تسري فقط على مسارات `/api/*` و`/admin/api/*`، ويعالجها `VersionMiddleware` بشكل موحد.

---

## الواجهة الداخلية (متاحة حاليًا)

تستخدم الواجهة الداخلية نفس مصادقة رمز المستخدم Bearer Token كبقية واجهات المنصة، وهي مناسبة لاستدعاءات الموردين المسجلين من العملاء/الواجهات الأمامية.

### المصادقة

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

يجب على المستخدم أولاً تسجيل الدخول عبر `/api/auth/login` للحصول على الرمز، ويجب أن يكون دور الحساب `supplier` (يُعيّنه المشرف بعد الموافقة على طلب المورد).

---

### تنسيق الاستجابات

#### استجابة ناجحة

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### استجابة مقسمة الصفحات

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

#### استجابة خطأ

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | الوصف |
|------|------|
| 0 | نجاح |
| 400 | خطأ في معاملات الطلب / إصدار API غير مدعوم |
| 401 | غير مسجل دخوله أو انتهت صلاحية الرمز |
| 403 | لا توجد صلاحية (دور غير مورد / فشل تأكيد كلمة المرور) |
| 404 | المورد غير موجود |
| 422 | فشل التحقق من المعاملات |
| 429 | تجاوز حد معدل الطلبات |

---

### نقاط النهاية

#### 1. انضمام المورد

```
POST /api/supplier/apply
```

طلب الانضمام كمورد. يمكن لكل مستخدم تقديم طلب واحد فقط.

**جسم الطلب**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| المعامل | النوع | إلزامي | الوصف |
|------|------|------|------|
| company_name | string | نعم | اسم الشركة |
| contact_name | string | نعم | اسم جهة الاتصال |
| contact_phone | string | نعم | رقم الهاتف |
| contact_email | string | نعم | البريد الإلكتروني |
| settlement_method | string | لا | طريقة التسوية، الافتراضي `bank` |

**الاستجابة**: كائن المورد، بالحالة `pending`

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

> تُخزَّن الحقول الحساسة (اسم جهة الاتصال، الهاتف، البريد) مشفرة في قاعدة البيانات، وتُعرض مع إخفاء جزئي في استجابات الـ API.

**الأخطاء**:

| code | السيناريو |
|------|------|
| 422 | سبق تقديم طلب مورد |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. إدارة المنتجات

##### الحصول على المنتجات المعينة

```
GET /api/supplier/products
```

**معاملات Query**:

| المعامل | النوع | إلزامي | الوصف |
|------|------|------|------|
| page | int | لا | رقم الصفحة، الافتراضي 1 |

**الاستجابة**: قائمة مقسمة الصفحات، كل عنصر يتضمن معلومات المنتج ونسبة العمولة

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

##### إضافة منتج

```
POST /api/supplier/products
```

ربط منتج موجود بالمورد الحالي.

**جسم الطلب**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| المعامل | النوع | إلزامي | الوصف |
|------|------|------|------|
| product_id | string | نعم | معرّف المنتج (Hashid) |
| commission_rate | float | لا | نسبة العمولة، الافتراضي 0.1 |

**الاستجابة**: كائن SupplierProduct المنشأ

**الأخطاء**:

| code | السيناريو |
|------|------|
| 422 | المنتج معين بالفعل لهذا المورد |

##### إزالة منتج

```
DELETE /api/supplier/products/{id}
```

إلغاء ربط المنتج بالمورد.

**الاستجابة**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. إدارة التسويات

##### الحصول على قائمة التسويات

```
GET /api/supplier/settlements
```

**الاستجابة**: جميع تسويات المورد الحالي، بترتيب إنشاء تنازلي

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

| الحقل | الوصف |
|------|------|
| total_sales | إجمالي مبيعات الطلبات المكتملة خلال الفترة |
| commission | إجمالي عمولة المنصة |
| payable | المبلغ المستحق للمورد (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. السحب

##### طلب سحب

```
POST /api/supplier/withdraw
```

> تتطلب هذه العملية تأكيدًا ثانيًا لكلمة المرور (حقل `confirm_password`)، يتحقق منه `ConfirmationMiddleware`.
> بعد 5 محاولات فاشلة يُقفل الحساب لمدة 15 دقيقة.

**جسم الطلب**:

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

| المعامل | النوع | إلزامي | الوصف |
|------|------|------|------|
| amount | string | نعم | مبلغ السحب (سلسلة لتجنب مشاكل دقة الفاصلة العائمة) |
| confirm_password | string | نعم | كلمة مرور تسجيل دخول المستخدم (تأكيد ثانٍ) |
| account_info | object | نعم | معلومات الحساب المستلم |
| account_info.method | string | نعم | طريقة السحب: `bank_transfer` / `alipay` / `wechat` |

**حساب الرصيد القابل للسحب**: مجموع `payable` لجميع التسويات المكتملة - مجموع `amount` لطلبات السحب قيد المعالجة

**الاستجابة**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**الأخطاء**:

| code | السيناريو |
|------|------|
| 422 | الرصيد القابل للسحب غير كافٍ |
| 403 | فشل تأكيد كلمة المرور |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### ملخص نقاط نهاية الواجهة الداخلية

| الطريقة | المسار | المصادقة | تأكيد كلمة المرور | الوصف |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | طلب الانضمام كمورد |
| GET | `/api/supplier/products` | Token | - | عرض المنتجات المعينة |
| POST | `/api/supplier/products` | Token | - | إضافة ربط منتج |
| DELETE | `/api/supplier/products/{id}` | Token | - | إزالة ربط منتج |
| GET | `/api/supplier/settlements` | Token | - | عرض التسويات |
| POST | `/api/supplier/withdraw` | Token | مطلوب | طلب سحب |

---

## الواجهة الخارجية (مواصفات التصميم، بانتظار التنفيذ)

تتيح الواجهة الخارجية للموردين إدارة الطلبات والموارد والتسويات برمجيًا. تتطلب جميع الطلبات مصادقة مفتاح API.

**Base URL**: `https://api.example.com/api`

### المصادقة

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

ينشئ مفتاح API مشرف المنصة من لوحة الإدارة في `إدارة الموردين → API Keys`.

**متطلبات الأمان**:
- الوصول عبر HTTPS فقط
- يُعرض مفتاح API مرة واحدة فقط عند الإنشاء، احفظه جيدًا
- يُنصح بإضافة عناوين IP الخاصة بالخادم إلى القائمة البيضاء

---

### تنسيق الاستجابات

مطابق للواجهة الداخلية، مع حقل إضافي `request_id` للتتبع:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### نقاط النهاية

#### 1. إدارة الطلبات

##### الحصول على قائمة الطلبات

```
GET /api/supplier/orders
```

**معاملات Query**:

| المعامل | النوع | إلزامي | الوصف |
|------|------|------|------|
| page | int | لا | رقم الصفحة، الافتراضي 1 |
| page_size | int | لا | عدد العناصر لكل صفحة، الافتراضي 20، الحد الأقصى 50 |
| status | string | لا | فلترة الحالة: pending/paid/completed/refunded |
| from | date | لا | تاريخ البداية YYYY-MM-DD |
| to | date | لا | تاريخ النهاية YYYY-MM-DD |

##### الحصول على تفاصيل الطلب

```
GET /api/supplier/orders/{id}
```

---

#### 2. إدارة الموارد

##### الحصول على قائمة الموارد

```
GET /api/supplier/resources
```

**معاملات Query**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### الحصول على حالة المورد

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. إدارة التسويات

##### الحصول على قائمة التسويات

```
GET /api/supplier/settlements
```

##### الحصول على تفاصيل التسوية

```
GET /api/supplier/settlements/{id}
```

---

#### 4. السحب

##### طلب سحب

```
POST /api/supplier/withdraw
```

##### سجل السحوبات

```
GET /api/supplier/withdraws
```

---

#### 5. إدارة المنتجات

##### الحصول على منتجاتي

```
GET /api/supplier/products
```

##### تقديم طلب إدراج منتج

```
POST /api/supplier/products
```

---

### ملخص نقاط نهاية الواجهة الخارجية

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/api/supplier/orders` | قائمة الطلبات |
| GET | `/api/supplier/orders/{id}` | تفاصيل الطلب |
| GET | `/api/supplier/resources` | قائمة الموارد |
| GET | `/api/supplier/resources/{id}/status` | حالة المورد |
| GET | `/api/supplier/settlements` | قائمة التسويات |
| GET | `/api/supplier/settlements/{id}` | تفاصيل التسوية |
| POST | `/api/supplier/withdraw` | طلب سحب |
| GET | `/api/supplier/withdraws` | سجل السحوبات |
| GET | `/api/supplier/products` | قائمة المنتجات |
| POST | `/api/supplier/products` | تقديم منتج |

---

## Webhook (استقبال أحداث المنصة)

يمكن للمورد تسجيل عنوان Webhook لاستقبال الأحداث الفورية. يُكوَّن من لوحة الإدارة.

### أنواع الأحداث

| الحدث | توقيت الإطلاق |
|------|----------|
| `order.paid` | أكمل المستخدم الدفع |
| `order.refunded` | استُرد الطلب |
| `resource.provisioned` | اكتمل توفير المورد |
| `resource.expiring` | المورد يقترب من الانتهاء (خلال 7 أيام) |
| `resource.destroyed` | أُتلف المورد |
| `settlement.created` | أُنشئت تسوية |
| `withdrawal.approved` | وُوفق على السحب |

### تنسيق طلب Webhook

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

**التحقق من التوقيع**: `HMAC-SHA256(payload, webhook_secret)`

---

## حد المعدل

| نقطة النهاية | الحد |
|------|------|
| الواجهة الداخلية | 60 طلب/دقيقة لكل مستخدم (الافتراضي) |
| تسجيل دخول الواجهة الداخلية | 5 طلبات/دقيقة |
| الواجهة الخارجية | 120 طلب/دقيقة لكل مفتاح API (قاعدة `supplier_api`، تُطبَّق عبر `RateLimitMiddleware`) |
| سحب الواجهة الخارجية | 10 طلبات/دقيقة (قيمة مقترحة، قابلة للتعديل في `config/security.php`) |

> تُعرَّف قواعد حد معدل الواجهة الخارجية في `rate_limits.supplier_api` داخل `config/security.php`،
> وينفذها `RateLimitMiddleware` بشكل موحد على مسارات `/api/supplier/external/*` (عداد INCR ذري،
> والسماح بالمرور عند تعذر توفر Redis).

ترويسات حد المعدل:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## أمثلة SDK

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// 申请成为供应商
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// 获取结算单
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// 申请提现
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
    'X-Api-Version': 'v1',
}

# 获取已分配商品
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# 申请提现
resp = requests.post('https://api.example.com/api/supplier/withdraw',
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

## توصيات معالجة الأخطاء

1. **429 تجاوز المعدل**: انتظر `Retry-After` ثوانٍ ثم أعد المحاولة
2. **401 غير مصرح**: تحقق من صلاحية الرمز وسواء انتهت مدته
3. **403 ممنوع**: تحقق من أن دور الحساب `supplier`؛ فشل تأكيد كلمة المرور يتطلب انتظار فك القفل
4. **422 فشل التحقق**: صحّح معاملات الطلب وفق حقل `message`
5. **5xx خطأ في الخادم**: أعد المحاولة بتراجع أسي (1s -> 5s -> 25s)

---

## مرجع نقاط نهاية لوحة الإدارة

فيما يلي نقاط النهاية ذات الصلة لإدارة المشرف للموردين (للاستخدام الخلفي فقط، وتتطلب دور Admin):

| الطريقة | المسار | الوصف |
|------|------|------|
| GET | `/admin/api/suppliers` | قائمة الموردين (تدعم فلترة status) |
| GET | `/admin/api/suppliers/export` | تصدير الموردين إلى Excel |
| POST | `/admin/api/suppliers/{id}/approve` | الموافقة على المورد |
| POST | `/admin/api/suppliers/{id}/settle` | إنشاء تسوية |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | الموافقة على السحب |
| GET | `/admin/api/suppliers/{id}/api-keys` | عرض قائمة مفاتيح API للمورد |
| POST | `/admin/api/suppliers/{id}/api-keys` | إنشاء مفتاح API (يُعرض المفتاح الأصلي مرة واحدة فقط) |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | إبطال مفتاح API |
