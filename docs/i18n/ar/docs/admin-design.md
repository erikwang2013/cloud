# وثيقة تصميم لوحة الإدارة

## نظرة عامة

`admin/` هي نسخة مستقلة من webman v2.1 توفر لوحة إدارة مبنية على Layui. تعمل بشكل مستقل عن الخلفية `service/`، وتتشارك معها فقط قاعدة بيانات MySQL وحزم erikwang2013 السبع.

## البنية

```
┌─────────────────────────────────────────────────┐
│                  Admin Panel                     │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │ Controller│  │  Model   │  │   Bootstrap   │ │
│  │ (Layui)  │  │(Eloquent)│  │(worker start) │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │             │               │          │
│  ┌────┴─────────────┴───────────────┴─────────┐ │
│  │         7 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season │ Poster     │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

### خريطة تبعيات الوحدات

![خريطة تبعيات الوحدات](diagrams/module-dependency.svg)

## هيكل الدلائل

```
admin/
├── app/
│   ├── bootstrap/       # بدء التشغيل لكل عملية
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 54 ملف تحكم (Base/Crud + CRUD لكل كيان)
│   │   ├── Base.php     # json() مع hashids_encode_ids
│   │   ├── Crud.php     # Select/Insert/Update/Delete/Export مع فك تشفير hashids
│   │   ├── DashboardController.php  # واجهة بيانات لوحة المعلومات (إحصائيات المستخدمين + الاتجاهات)
│   │   ├── AccountController.php    # تسجيل الدخول/الخروج/الملف الشخصي/كلمة المرور
│   │   ├── AdminController.php      # CRUD للمشرفين + الأدوار
│   │   ├── RoleController.php       # CRUD للأدوار + شجرة القواعد
│   │   └── ...
│   ├── model/            # 44 نموذج Eloquent (36 نموذجًا يربطون جداول الأعمال بدون بادئة في service + alerts (معرّفة في install.sql) + 7 جداول إدارة wa_*)
│   │   ├── Base.php     # مفتاح أساسي Snowflake + دعم Encryptable
│   │   ├── Admin.php    # Encryptable: password, email, mobile
│   │   ├── User.php     # Encryptable: 6 حقول + سمة Searchable
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # WafMiddleware + AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # الواجهة العامة (plugin\admin\api)
│   └── Auth.php          # canAccess() للـ ACL
├── config/
│   ├── plugin/erikwang2013/  # 7 إعدادات للمكونات الإضافية
│   ├── hashids.php       # اتصالات Hashids (رئيسي + بديل)
│   └── encryption.php    # إعدادات التشفير (المفتاح الرئيسي، الخوارزمية)
├── tests/                # مجموعة اختبارات PHPUnit 11 (286 اختبارًا، 962 تأكيدًا)
│   ├── HashidsTest.php   # 21 اختبارًا
│   ├── BaseJsonTest.php  # 13 اختبارًا
│   ├── CrudHashidsTest.php # 14 اختبارًا
│   ├── TreeTest.php      # 19 اختبارًا
│   ├── AccessControlMiddlewareTest.php # 7 اختبارات (401/403/تمرير)
│   ├── AdminControllersTest.php        # 48 اختبار انعكاس للمتحكمات
│   ├── UtilTest.php      # 17 اختبارًا
│   ├── DictTest.php      # 5 اختبارات
│   ├── ExcelExportTest.php # 4 اختبارات
│   ├── LayuiTest.php     # 5 اختبارات
│   └── support/          # RequestMock, TestableCrud
├── install.sql           # DDL (مفاتيح أساسية bigint unsigned بدون auto-increment)
└── phpunit.xml
```

## تفاصيل تكامل الحزم

### 1. Snowflake (المفاتيح الأساسية الموزعة)

**الإعداد**: `config/plugin/erikwang2013/snowflake-php/app.php`
**Bootstrap**: `app/bootstrap/SnowflakeBootstrap.php`

```php
// Model Base::boot() — event of creating
static::creating(function (Model $model) {
    if (empty($model->getKey())) {
        $snowflake = Container::instance()->get(Snowflake::class);
        $model->setAttribute($model->getKeyName(), $snowflake->id());
    }
});
```

- معرّفات 64-بت: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`
- نقطة البداية (Epoch): 2024-01-01 (أقصى عمر ~69 عامًا)
- `$incrementing = false`، `$keyType = 'int'` في النموذج Base
- جميع أعمدة المفاتيح الأساسية والأجنبية: `bigint unsigned NOT NULL`

### 2. Hashids (إخفاء المعرّفات)

**الإعداد**: `config/hashids.php` + `config/plugin/erikwang2013/hashids/app.php`

**مسار التشفير** (الاستجابة):
- `Base::json()` يستدعي `hashids_encode_ids($data)` بشكل متكرر
- الحقول المسماة `id`، `*_id`، `*_ids` بقيم صحيحة موجبة → تُحوَّل إلى سلاسل hashid
- `Crud::formatNormal()` يطبّق التشفير أيضًا (أُصلح في مراجعة الكود)

**مسار فك التشفير** (الطلب):
- `Crud::selectInput()`: يفك تشفير سلاسل hashid في حقل `id`/`*_id` ضمن جملة WHERE
- `Crud::updateInput()`: يفك تشفير المفتاح الأساسي من `$request->post()`
- `Crud::deleteInput()`: يفك تشفير مصفوفة المفاتيح الأساسية من `$request->post()`
- `AdminController::update()`: يستخدم القيمة المرجعة من `updateInput()` مباشرة (بدون تكرار)
- `RoleController::select()`/`rules()`: يفك تشفير `$request->get('id')`

**الدوال المساعدة** (في `app/functions.php`):
- `hashids_encode(int $id): string`
- `hashids_decode(string $hash): int` — تُرجع 0 عند الفشل
- `hashids_encode_ids(array $data): array` — متكررة، تتعامل مع سلاسل `is_numeric()`

### 3. Encryptable (تشفير حقول قاعدة البيانات)

**الإعداد**: `config/plugin/erikwang2013/encryptable/app.php`
**Bootstrap**: `app/bootstrap/EncryptableBootstrap.php`

تستخدم واجهة Eloquent `CastsAttributes`:
- `get()`: فك تشفير AES عند القراءة من قاعدة البيانات
- `set()`: تشفير AES عند الكتابة إلى قاعدة البيانات

**الحقول المشفرة**:
| النموذج | الحقول |
|-------|--------|
| Admin | password, email, mobile |
| User | password, email, mobile, token, last_ip, join_ip |

**قاعدة حرجة**: استخدم دائمًا `save()` على نسخة النموذج، ولا تستخدم أبدًا `update()` الخاص بـ Query Builder. استخدام `Admin::where(...)->update(...)` يتجاوز تحويلات Eloquent ويخزّن القيم الخام. أُصلح ذلك في `AccountController` أثناء مراجعة الكود.

**طبقات كلمة المرور**: تُجزَّأ كلمات المرور بـ bcrypt أولاً (في `insertInput`/`updateInput`)، ثم يُشفَّر التجزئة بـ AES عبر تحويل Encryptable عند `save()`. عند القراءة: فك تشفير AES → تجزئة bcrypt → `password_verify()`.

### 4. Encryption (نقل الـ API)

**الإعداد**: `config/encryption.php`
**Bootstrap**: `app/bootstrap/EncryptionBootstrap.php`

مخصص لتشفير الطلبات/الاستجابات على مستوى الـ API (AES-256-GCM). يوفر:
- `encrypt_data(string $plaintext): string`
- `decrypt_data(string $ciphertext): string`

يرمي `RuntimeException` برسالة واضحة إذا لم يتم تعيين `ENCRYPTION_MASTER_KEY`.

### 5. Webman-Scout (Elasticsearch)

**الإعداد**: `config/plugin/erikwang2013/webman-scout/app.php` + `command.php`

يستخدم نموذج المستخدم سمة `Searchable`:
```php
class User extends Base
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
```

### 6. Season (أعلام الدول)

**الإعداد**: `config/plugin/erikwang2013/season/app.php`

دالة مساعدة عامة: `country_season_flag(string $code): string`
- `country_season_flag('CN')` → 🇨🇳
- `country_season_flag('US')` → 🇺🇸

توفر أيضًا أسماء المواسم المترجمة عبر فئة `CountrySeason`.

### 7. Poster-PHP (CAPTCHA بالنقر)

**الإعداد**: `config/poster.php` + `config/plugin/erikwang2013/poster/app.php`
**Bootstrap**: `config/plugin/erikwang2013/poster/bootstrap.php` → `CaptchaPlugin`

يوفر التحقق من CAPTCHA بالنقر لتسجيل الدخول والتسجيل:

```
Client                         Server
──────                         ──────
POST /api/captcha/create
  Header: X-Api-Version: v1
  → CaptchaService::create()
    → captcha_create('click')
      → ClickCaptcha::generate()
        → GD renders image with n randomly-placed Chinese words
        → Stores targets + key in Redis/File storage
      ← {key, image (base64), target_count, expires_in}

POST /api/auth/login
  Header: X-Api-Version: v1
  (with captcha_key + captcha_points)
  → AuthController::verifyCaptcha()
    → CaptchaService::verify(key, [[x1,y1], [x2,y2], ...])
      → captcha_verify(key, 'click', points)
        → CaptchaManager checks Euclidean distance ≤ 18px tolerance
      ← true/false
```

**ميزات الأمان**:
- مفاتيح للاستخدام مرة واحدة: تُحذف بعد التحقق الناجح
- حماية من القوة الغاشمة: حد أقصى 3 محاولات فاشلة لكل مفتاح ثم يُحذف
- مدة صلاحية 300 ثانية (قابلة للتكوين عبر `CAPTCHA_TTL`)
- تفاوت النقر: نصف قطر 18 بكسل (قابل للتكوين)
- مستويات الصعوبة: سهل (هدفان)، متوسط (3)، صعب (4)
- التخزين: اكتشاف تلقائي لـ Redis → الرجوع إلى الملف، قابل للتكوين عبر `CAPTCHA_STORAGE`

**الغلاف**: `Common\Captcha\CaptchaService` يحمّل الإعدادات المخصصة من `config/poster.php`، ويوفر دالتي `create()` (تزيل الأهداف من الاستجابة لأسباب أمنية) و`verify()`. تُستخدم من `AuthController::register()` و`AuthController::login()`.

### 8. ConfirmationMiddleware (إعادة التحقق من كلمة المرور)

**الإعداد**: وسيط على مجموعة المسارات في `config/route.php`

يحمي العمليات التدميرية والحساسة بمطالبة المستخدم بإعادة إدخال كلمة المرور. يُطبَّق كوسيط على 12 نقطة نهاية حساسة:

```
Client                              Server
──────                              ──────
POST /api/orders/{id}/pay
  Header: X-Api-Version: v1
  (with confirm_password field)
    → ConfirmationMiddleware::process()
      → Checks userId present (401 if missing)
      → Checks Redis lock key (429 if locked out)
      → Validates password non-empty (422 if missing)
      → User::find() + Hash::check() verifies bcrypt
      → On failure:
        → Redis INCR confirm_failed:{userId} counter
        → If count ≥ 5, SETEX confirm_lock:{userId} for 900s
        → AuditLogger::record('confirm_failed', ...)
        → Returns 403
      → On success:
        → DEL confirm_failed:{userId} counter
        → AuditLogger::record('confirm_success', ...)
        → Calls $next($request)
```

**نقاط نهاية المستخدم الحساسة** (Auth + Confirmation):
| الطريقة | المسار | العملية |
|--------|------|-----------|
| POST | `/api/orders/{id}/pay` | بدء الدفع |
| POST | `/api/supplier/withdraw` | طلب السحب |
| DELETE | `/api/dns/{domain}/records/{id}` | حذف سجل DNS |

**نقاط نهاية المشرف الحساسة** (Auth + AdminRole + Confirmation):
| الطريقة | المسار | العملية |
|--------|------|-----------|
| DELETE | `/admin/api/products/{id}` | حذف المنتج |
| POST | `/admin/api/orders/{id}/refund` | استرداد الطلب |
| POST | `/admin/api/provisioning/resources/{id}/destroy` | إتلاف المورد |
| POST | `/admin/api/kyc/{id}/approve` | الموافقة على KYC |
| POST | `/admin/api/kyc/{id}/reject` | رفض KYC |
| POST | `/admin/api/suppliers/{id}/approve` | الموافقة على المورد |
| POST | `/admin/api/suppliers/{id}/settle` | إنشاء التسوية |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | الموافقة على السحب |
| PUT | `/admin/api/system/config` | تحديث إعدادات النظام |

يُحمَل إصدار الـ API في رأس `X-Api-Version` (الافتراضي: `v1`)، وليس في مسار الـ URL.

**ميزات الأمان**:
- التحقق من كلمة المرور بـ bcrypt عبر `Hash::check()`
- الحد من المعدل: 5 محاولات فاشلة تؤدي إلى قفل لمدة 15 دقيقة (مدة صلاحية 900 ثانية)
- القفل لكل مستخدم عبر مفاتيح Redis (`confirm_lock:{userId}`، `confirm_failed:{userId}`)
- النجاح يعيد تعيين عداد الفشل
- تُسجَّل جميع المحاولات في قاعدة بيانات التدقيق (نجاح، فشل، قفل)
- `verifyPassword()` هي طريقة محمية، ما يتيح اختبارها عبر تجاوزها في فئة فرعية مجهولة

**قابلية الاختبار**: `ConfirmationMiddlewareTest` (11 اختبارًا) يستخدم فئة فرعية مجهولة تتجاوز `verifyPassword()` لإرجاع قيمة منطقية ثابتة، متجنبًا الاعتماد على Eloquent/قاعدة البيانات. تغطي الاختبارات: 401 غير مصادق، 422 كلمة مرور مفقودة/فارغة، 403 كلمة مرور خاطئة، التمرير الناجح، تنسيق مفتاح حد المعدل، تنسيق مفتاح القفل، وحدود عتبة الفشل الأقصى (4→بدون قفل، 5→قفل، 6→قفل).

## نظام ACL

### على مستوى المتحكم

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // تخطي تسجيل الدخول
protected $noNeedAuth = ['select'];                         // تخطي المصادقة
```

يُفحص عبر `api/Auth::canAccess()` باستخدام `ReflectionClass`.

**استجابة AccessControlMiddleware** (`middleware/AccessControl.php`):
- غير مسجل دخوله (خارج `noNeedLogin`) → **HTTP 401**، الجسم عبارة عن سكربت إعادة توجيه إلى صفحة تسجيل الدخول
- مسجل دخوله لكن بصلاحيات غير كافية → **HTTP 403** صفحة خطأ (رمز 403، لم يعد 500)
- في قائمة السماح (صفحة تسجيل الدخول/التحقق إلخ) → تمرير طبيعي

### على أساس الأدوار

- تمتلك الأدوار `rules` (معرّفات قواعد مفصولة بفواصل أو `*` للمشرف الفائق)
- تُخزَّن القواعد في `wa_rules` كمفاتيح `{Controller}@{action}`
- `api/Auth::canAccess()` يطابق مفتاح `$controller@$action` مع قواعد الدور
- المشرف الفائق (`rules = '*'`) يتجاوز جميع الفحوصات

### حدود البيانات

```php
protected $dataLimit = null;     // بدون حد
protected $dataLimit = 'auth';   // يرى المشرف بياناته وبيانات من ينتمون إليه
protected $dataLimit = 'personal'; // يرى المشرف بياناته فقط
protected $dataLimitField = 'admin_id';
```

## نتائج مراجعة الكود (أُصلحت)

أثناء مراجعة الالتزام الأولي، عُثر على ما يلي وأُصلح:

### حرجة
1. **تجاوز AccountController لـ Encryptable**: استخدمت `password()` و`update()` جملة `Admin::where()->update()` التي تتجاوز تحويلات Eloquent → خُزنت قيم خام في الأعمدة المشفرة. أُصلح باستخدام `Admin::find()->save()`.
2. **Crud::formatNormal() لا يشفّر المعرّفات**: استدعت الدالة العامة `json()` بدلاً من تطبيق `hashids_encode_ids()`. أُصلح.

### مهمة
3. **صرامة `is_int` في hashids_encode_ids**: تصل قيم bigint الكبيرة من PDO كسلاسل PHP. غُيّرت إلى `is_numeric()` مع فحص الأعداد الصحيحة.
4. **فك تشفير معرّف مكرر في AdminController**: فكّكت `update()` نفس المفتاح الأساسي مرتين. أُزيل التكرار، وأُصلح تظليل متغير الحلقة في `insert()`.
5. **كود كلمة مرور ميت في AccountController::update()**: حقل كلمة المرور غير مدرج في قائمة السماح. أُزيل.
6. **محرك MySQL مكتوب برمجيًا**: غُيّر إلى `config('database.default')`.

## تصدير Excel

### البنية

يستخدم تصدير Excel مكتبة PhpSpreadsheet ^2.0 لتوليد ملفات .xlsx من جانب الخادم. تمتلك لوحة الإدارة مسارين منفصلين للتصدير لوجود آليتين للـ CRUD:

```
Export request (with current table filters)
  ├── Crud-based controllers (User, Admin, Role, etc.)
  │     → Crud::export()
  │       → selectInput() reuses query parsing (hashids decode, WHERE, ORDER)
  │       → doSelect() builds Eloquent query
  │       → 10,000 row cap
  │       → hashids_encode_ids() applied to result data
  │       → ExcelExport::export() generates .xlsx
  │
  └── TableController (generic tables like wa_dict, wa_rules)
        → TableController::export()
          → Builds query from table schema + request params
          → hashids_encode_ids() applied
          → ExcelExport::export() generates .xlsx
```

### أداة ExcelExport (`app/common/ExcelExport.php`)

غلاف سلس حول PhpSpreadsheet:

- `setColumns(array $columns)` — تحديد ترتيب الأعمدة
- `setLabels(array $labels)` — تعيين ترويسات الأعمدة القابلة للقراءة
- `addRow(array $row)` / `addRows(array $rows)` — ملء البيانات
- `save(string $title): string` — كتابة ملف .xlsx إلى `runtime/exports/` وإرجاع مسار الملف
- مساعدة ثابتة: `ExcelExport::export($title, $columns, $data, $labels)` — تصدير لمرة واحدة
- تحجيم الأعمدة تلقائيًا عبر `Worksheet::getColumnDimension()`

### Crud::export()

```php
public function export(Request $request): Response
{
    [$where, $format, $limit, $field, $order] = $this->selectInput($request);
    $query = $this->doSelect($where, $field, $order);
    $maxRows = 10000;
    $total = min($query->count(), $maxRows);
    $items = $query->limit($maxRows)->get();
    if (method_exists($this, 'afterQuery')) {
        $items = call_user_func([$this, 'afterQuery'], $items);
    }
    $data = array_map(fn($item) => ...toArray(), $items->toArray());
    $data = hashids_encode_ids($data);
    // Derive column labels from table schema comments
    $path = ExcelExport::export($table, $columns, $data, $labels);
    return response()->download($path, $table . '_' . date('YmdHis') . '.xlsx');
}
```

جميع المتحكمات المبنية على Crud (Admin, User, Role، إلخ) ترث `export()` تلقائيًا.

### الربط في الواجهة الأمامية

- يُستبدل عنصر شريط الأدوات المدمج في Layui `"exports"` (CSV من جانب العميل) بزر مخصص `{title: "تصدير", layEvent: "export"}`
- يستدعي معالج حدث التصدير `window.exportExcel()` الذي يجمع معاملات فلتر الجدول الحالية ويفتح رابط التنزيل
- `Layui::buildTable()` يولد شريط الأدوات مع زر التصدير المخصص لجميع صفحات الـ CRUD

### تصدير واجهة Admin API للخدمة

تمتلك الخلفية `service/` أيضًا تصدير Excel عبر غلافها الخاص `Common\ExcelExport`:

| نقطة النهاية | المتحكم | البيانات المُصدَّرة |
|----------|-----------|---------------|
| `GET /admin/api/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

تتطلب جميع نقاط نهاية الـ API رأس `X-Api-Version` (الافتراضي: `v1`).

تُوضع مسارات التصدير قبل مسارات المعاملات `/{id}` لتجنب التعارضات.

## Service Admin API — الميزات الموسعة

### نقاط نهاية Admin API (طبقة الخدمة)

جميع نقاط نهاية REST للإدارة مسبوقة بـ `/admin/api` وتتطلب `AdminRoleMiddleware`.

| المجموعة | نقاط النهاية | المتحكم |
|-------|-----------|------------|
| لوحة المعلومات | `GET /dashboard`، `/kyc`، `POST /kyc/{id}/approve`، `/reject` | `Admin\DashboardController` |
| المستخدمون | `GET /users`، `/users/export`، `/users/{id}`، `PUT /users/{id}/status` | `Admin\UserController` |
| المنتجات | `POST /products`، `PUT /products/{id}`، `DELETE /products/{id}`، `POST /products/{id}/skus`، `PUT /skus/{id}`، `POST /skus/{id}/region-price` | `Admin\ProductController` |
| استيراد/تصدير المنتجات | `GET /products/export` (CSV)، `POST /products/import` (upsert عبر CSV) | `Admin\ImportExportController` |
| الطلبات | `GET /orders`، `/orders/export`، `/orders/{id}`، `POST /orders/{id}/refund` | `Admin\OrderController` |
| الفواتير | `GET /invoices`، `POST /invoices/{orderId}/generate` | `Admin\InvoiceController` |
| المدفوعات | `GET /payments/channels`، `PUT /payments/channels/{id}`، `GET /payments/transactions`، `/reconcile` | `Admin\PaymentController` |
| التوفير (Provisioning) | `GET /provisioning/tasks`، `POST /tasks/{id}/retry`، `POST /resources/{id}/upgrade`، `/destroy`، `GET /hosts` | `Provisioning\TaskController` |
| واجهات المزود | `GET /providers`، `POST /providers`، `PUT /providers/{id}`، `DELETE /providers/{id}` | `Admin\ProviderApiController` |
| CDN | `GET /cdn/domains`، `PUT /cdn/domains/{id}` | `Admin\CdnController` |
| الموردون | `GET /suppliers`، `/suppliers/export`، `POST /suppliers/{id}/approve`، `/settle`، `/withdraws/{id}/approve` | `Admin\SupplierController` |
| مفاتيح API للموردين | `GET /suppliers/{id}/api-keys`، `POST /suppliers/{id}/api-keys`، `DELETE /suppliers/api-keys/{id}` | `Admin\SupplierController` |
| التذاكر | `GET /tickets`، `POST /tickets/{id}/assign`، `/close` | `Ticket\TicketController` |
| القسائم | `GET /coupons`، `POST /coupons`، `DELETE /coupons/{id}` | `Admin\CouponController` |
| الويب هوكس | `GET /webhooks`، `POST /webhooks`، `DELETE /webhooks`، `POST /webhooks/test` | `Admin\WebhookController` |
| النطاقات | `GET /domains/tlds`، `POST /domains/tlds`، `PUT /domains/tlds/{id}`، `DELETE /domains/tlds/{id}`، `GET /domains/zones`، `/transfers`، `POST /transfers/{id}/approve` | `Admin\DomainController` |
| الإشعارات | `GET /notifications/templates`، `PUT /notifications/templates/{id}`، `GET /notifications/log` | `Admin\NotificationController` |
| مقالات المساعدة | `GET /help`، `POST /help`، `PUT /help/{id}`، `DELETE /help/{id}` | `Admin\HelpController` |
| التقارير | `GET /reports/revenue`، `/supplier`، `/region` | `Report\ReportController` |
| المراقبة | `GET /monitor/dashboard`، `/resources/{id}` | `Monitor\MonitorController` |
| التدقيق | `GET /audit-logs` | `Admin\SystemController` |
| إعدادات النظام | `PUT /system/config` | `Admin\SystemController` |

### إدارة موارد CDN

يدعم منتج CDN أربعة مزودين (Cloudflare / CloudFront / Alibaba Cloud / Tencent Cloud)، وينقسم طرف الإدارة إلى قسمين:

**إعداد حسابات المزودين** (يعيد استخدام نموذج ProviderApi، `Admin\ProviderApiController`):

- `GET/POST /admin/api/providers`، `PUT/DELETE /admin/api/providers/{id}`، مسبوقة بـ `RbacMiddleware('provider.config')`
- `code` بميثاق `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`؛ حقول الاعتمادات مشفّرة عند التخزين عبر Encryptable، وعمود `config` JSON يحفظ البيانات الوصفية غير الحساسة
- أولوية تحليل الاعتمادات في طرف المستخدم: الحساب المربوط ← حساب نشط مطابق للـ code ← env كبديل أخير؛ الحذف/purge عبر لقطة صارمة (الحساب المربوط فقط، 4003 عند الغياب/التعطيل)

**إدارة نطاقات CDN** (`Admin\CdnController`):

```
GET /admin/api/cdn/domains        → جميع النطاقات (بما فيها user_id المالك)، مسبوقة بـ RbacMiddleware('cdn.manage')
PUT /admin/api/cdn/domains/{id}   → تحديث الباقة، قائمة بيضاء للـ plan: standard | pro | enterprise،
                                    القيم غير الصالحة تُعيد 400؛ تُكتب التغييرات في سجل التدقيق admin_cdn_update_plan
```

### بيانات لوحة المعلومات (طبقة الخدمة)

`Admin\DashboardController::index()` يوفر مقاييس تشغيلية حقيقية:

```php
[
    'today_stats' => [todayOrders, todayRevenue, newUsers, activeResources],
    'revenue_trend_30d' => [...],   // Daily revenue for last 30 days
    'region_distribution' => [...],  // Active resources grouped by region
    'pending_orders' => ...,         // Orders awaiting payment
    'pending_kyc' => ...,            // KYC submissions awaiting review
    'open_tickets' => ...,           // Open or in-progress tickets
]
```

### عرض لوحة معلومات لوحة الإدارة (`app/view/index/dashboard.html`)

- **8 بطاقات إحصائية متحركة**: مستخدمو اليوم/الأسبوع/الشهر/الإجمالي + طلبات اليوم + إيرادات اليوم + الطلبات المعلقة + الموارد النشطة — لكل بطاقة رسم عدّاد تصاعدي عبر وحدة Layui `count`
- **3 مخططات ECharts**:
  1. اتجاه تسجيل المستخدمين لـ 7 أيام — مخطط خطي للمنطقة
  2. اتجاه تسجيل المستخدمين لـ 30 يومًا — مخطط أعمدة
  3. ملخص المستخدمين — مخطط دونات/دائري (اليوم / الأسبوع / الشهر)
- **جدول معلومات النظام**: يُملأ ديناميكيًا بإصدارات PHP/Workerman/Webman/Admin/MySQL/OS
- **شريط الأدوات**: أزرار تصدير PDF وتحديث
- تُجلب جميع البيانات عبر AJAX من `/app/admin/dashboard/data`

### المسار

```
Route::any('/app/admin/dashboard/data', [DashboardController::class, 'index']);
```

بخلاف المسارات المسجلة صراحةً، يسجل `admin/config/route.php` تلقائيًا مسار `/app/admin/{snake_case_controller}/{action}` لكل دالة عامة في كل متحكم ضمن `app/controller/` (مثل `/app/admin/order_item/index`)، ويتوافق اسم المتحكم بنمط snake_case مع القائمة والـ URL؛ `/app/admin` و`/app/admin/index` هما مدخلان للصفحة الرئيسية/صفحة تسجيل الدخول للوحة الإدارة (يتم عرض عرض تسجيل الدخول عند عدم تسجيل الدخول)؛ الطلبات غير المطابقة تُرجع 404.

## تصدير PDF

توليد PDF من جانب العميل في صفحة لوحة المعلومات:

- يستخدم **html2canvas 1.4.1** (CDN) لالتقاط DOM لوحة المعلومات كـ canvas
- يستخدم **jsPDF 2.5.1** (CDN) لإنشاء ملف PDF A4 قابل للتنزيل
- يلتقط بطاقات الإحصائيات ومخططات ECharts (المُصيَّرة كعناصر canvas)
- يتضمن العنوان والطابع الزمني والعلامة التجارية في ملف PDF
- يُشغَّل بواسطة زر "تصدير PDF" في شريط أدوات لوحة المعلومات

```
Dashboard DOM → html2canvas screenshot → jsPDF document → browser download
```

### التنفيذ

```javascript
// In dashboard.html
html2canvas(document.querySelector('.layui-body'), {scale: 2}).then(canvas => {
    const pdf = new jsPDF('p', 'mm', 'a4');
    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 10, 10, 190, 0);
    pdf.save('dashboard_' + new Date().toISOString().slice(0, 10) + '.pdf');
});
```

## مجموعة الاختبارات

```
PHPUnit 11.5 | 67 tests | 124 assertions
```

### HashidsTest (21 اختبارًا)
- دورة تشفير/فك تشفير (0 إلى PHP_INT_MAX)
- تشفير حتمي
- معالجة السلاسل غير الصالحة/الفارغة
- أنماط حقول `hashids_encode_ids` (`id`، `*_id`، `*_ids`)
- تخطي الصفر/السالبة، دعم السلاسل الرقمية
- تكرار المصفوفات المتداخلة، الحفاظ على الحقول غير المعرّفة

### BaseJsonTest (13 اختبارًا)
- `json()`/`success()`/`fail()` تطبق تشفير hashids
- تشفير الكائنات المتداخلة
- معالجة معرّفات بحجم Snowflake
- الحفاظ على الحقول غير المعرّفة
- معالجة الصفر
- التحقق من بنية الاستجابة

### CrudHashidsTest (14 اختبارًا)
- `selectInput`: فك تشفير hashid في حقول WHERE `id`/`*_id`
- `selectInput`: تمرير السلاسل الرقمية/الأعداد الصحيحة الخام
- `updateInput`: فك تشفير المفتاح الأساسي hashid
- `updateInput`: تحويل السلسلة الرقمية للمفتاح الأساسي إلى int
- `deleteInput`: فك تشفير معرّفات الدفعات، الأنواع المختلطة
- `deleteInput`: مصفوفة فارغة، معالجة معرّف واحد

## نظام ترحيل قاعدة البيانات

### البنية

تمتلك كل من نسختي `service/` و`admin/` نظامي ترحيل مستقلين مبنيين على Schema Builder من `illuminate/database`. تسجل كل نسخة أوامر Symfony Console عبر `config/command.php` التي يكتشفها مشغّل console الخاص بـ webman.

```
php webman migrate          # Run pending migrations
php webman migrate:rollback # Rollback last batch
php webman migrate:status   # Show migration status
```

### MigrationRunner (`service/support/MigrationRunner.php`، `admin/app/common/MigrationRunner.php`)

المحرك الأساسي المشترك بين النسختين:

- **`ensureTable()`** — ينشئ جدول التتبع `migrations` (id، اسم الترحيل، رقم الدفعة) عند أول تشغيل
- **`migrate()`** — يفحص ملفات الترحيل من `database/migrations/`، وينفذ دوال `up()` المعلقة، ويسجل الدفعة
- **`rollback()`** — يعكس آخر دفعة باستدعاء `down()` لكل ترحيل بترتيب عكسي
- **`status()`** — يسرد جميع الترحيلات مع أرقام دفعاتها
- **`resolve()`** — ينشئ نسخًا من فئات الترحيل من الملفات

### فئة أساسية للترحيل (`service/support/Migration.php`، `admin/app/common/Migration.php`)

```php
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
```

كل ملف ترحيل يرجع فئة تمدد `Migration` بأسماء ملفات مسبوقة بطابع زمني (مثل `2024_01_01_000001_create_initial_schema.php`).

### ترحيلات الخدمة

**الدليل**: `service/database/migrations/` — 38 ملف ترحيل (أسماء الجداول بدون بادئة erik_، وتُربط مباشرة بنماذج admin)

| الترحيل | الجداول |
|-----------|--------|
| `0001_create_users_tables` | users, user_profiles, user_kyc, user_balance, user_balance_log, user_addresses, refresh_tokens |
| `0002_create_product_tables` | product_categories, regions, products, product_skus, product_regions, product_images, product_attributes, product_reviews |
| `0003_create_order_tables` | carts, orders, order_items, order_timeline, order_invoices, refunds |
| `0004_create_payment_tables` | payment_channels, payment_transactions, payment_reconcile |
| `0005_create_provisioning_tables` | resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis |
| `0006_create_host_tables` | host_machines, ip_pools, ip_allocations, disks, disk_resizes |
| `0007_create_supplier_tables` | suppliers, supplier_products, supplier_settlements, supplier_withdraws |
| `0008_create_domain_tables` | domain_tlds, domain_transfers, dns_zones, dns_records |
| `0009_create_ticket_notification_tables` | tickets, ticket_messages, notifications, notification_templates |
| `0010_create_audit_table` | audit_logs |
| `0011_create_kvm_service_tables` | network_services, firewall_services, switch_services |
| `2024_01_01_000001_create_initial_schema` | ينفذ `docs/database.sql` عبر `Capsule::unprepared()`، ويحذف كل شيء في `down()` |
| `2025_05_16_000002_add_fcm_token_to_users` | يضيف عمودي `fcm_token`، `fcm_platform` + فهرس إلى users |
| `2026_08_26_000003_widen_encrypted_columns` | users.phone / user_addresses.phone / suppliers.contact_phone VARCHAR(20)→VARCHAR(255) (طول النص المشفر لـ Encryptable) |

### ترحيلات الإدارة

**الدليل**: `admin/database/migrations/` — ملف ترحيل واحد

| الترحيل | الوصف |
|-----------|-------------|
| `2024_01_01_000001_create_admin_schema` | ينفذ `admin/install.sql` عبر `Capsule::unprepared()` — ينشئ جداول wa_* مع بيانات أولية |

### تسجيل أوامر Console

**`service/config/command.php`**:
```php
return [
    \App\Command\MigrateCommand::class,
    \App\Command\MigrateRollbackCommand::class,
    \App\Command\MigrateStatusCommand::class,
];
```

**`admin/config/command.php`** — نفس النمط ضمن فضاء الأسماء `app\command`.

## تكامل Stripe للإنتاج

### البنية

استُبدلت معرّفات الدفع الوهمية `random_bytes()` بتكامل حقيقي مع واجهة Stripe API عبر `stripe/stripe-php` ^15.0.

**الملف**: `service/app/payment/service/channels/StripeChannel.php`

```
Client-side                    Server-side                    Stripe API
───────────                    ───────────                    ──────────
Select Stripe at checkout
  → POST /orders/{id}/pay
    → StripeChannel::createPaymentIntent()
      → StripeClient->paymentIntents->create(amount, currency)
        ← {id, client_secret}
      → Save pi_xxx as transaction_no
      ← Return client_secret
  → Stripe.js confirmCardPayment(client_secret)
    ← Payment confirmed by Stripe
      → POST /payments/webhook/stripe
        → StripeChannel::handleWebhook()
          → Webhook::constructEvent(payload, signature, secret)
          → Verify idempotency (skip non-pending transactions)
          → Update order status, create transaction record
```

### إنشاء PaymentIntent

```php
public function createPaymentIntent(Order $order): array
{
    $intent = $this->stripe()->paymentIntents->create([
        'amount'   => (int) round($order->total * 100),  // cents
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id, 'order_no' => $order->order_no],
    ]);
    return [
        'transaction_no' => $intent->id,          // pi_xxxxxxxxxxxxx
        'client_secret'  => $intent->client_secret, // pi_xxx_secret_yyy
    ];
}
```

- `$this->stripe()` يهيئ `\Stripe\StripeClient` بشكل كسول مع `STRIPE_SECRET_KEY` من البيئة
- يتراجع إلى `$this->channel->api_key_encrypted` (مفكوك التشفير عبر Encryptable) إذا لم يُعيّن متغير البيئة
- يحوَّل المبلغ إلى سنتات: `(int) round($order->total * 100)`

### التحقق من توقيع Webhook

```php
public function handleWebhook(string $payload, string $signature): void
{
    $event = \Stripe\Webhook::constructEvent(
        $payload, $signature, $this->channel->webhook_secret_encrypted
    );
    // Idempotency: skip if transaction already processed
    $existing = Transaction::where('transaction_no', $event->id)->first();
    if ($existing && $existing->status !== 'pending') return;
    
    match ($event->type) {
        'payment_intent.succeeded' => $this->confirmPayment($event),
        'payment_intent.payment_failed' => $this->failPayment($event),
        default => null,
    };
}
```

- يستخدم `Webhook::constructEvent()` للتحقق من ترويسة توقيع Stripe
- **حارس التكرار (Idempotency)**: يفحص عمليات تسليم الويب هوك المكررة عبر `transaction_no`
- يدعم نوعي أحداث النجاح والفشل

## تكامل رسائل Twilio SMS

### البنية

استُبدلت الدالة الوهمية `error_log()` بتسليم SMS حقيقي عبر `twilio/sdk` ^8.0.

**الملف**: `service/app/notification/queue/SmsSender.php`

### إرسال الرسائل

```php
public function consume(): void
{
    $client = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );
    $message = $client->messages->create(
        $this->notification->recipient_phone,
        ['from' => getenv('TWILIO_PHONE_NUMBER'), 'body' => $this->notification->body]
    );
    $this->notification->provider_message_id = $message->sid;
}
```

### معالجة الأخطاء

- تلتقط `Twilio\Exceptions\RestException` — تلتقط رمز خطأ Twilio ورسالته
- تنشئ سجل إشعار فاشل مع `send_status = 'failed'`
- تسجل `provider_message_id` (Twilio SID) لتتبع التسليم
- تتراجع إلى `error_log()` عندما تكون بيانات اعتماد Twilio غير معينة (وضع التطوير)

### الإعداد

متغيرات البيئة: `TWILIO_ACCOUNT_SID`، `TWILIO_AUTH_TOKEN`، `TWILIO_PHONE_NUMBER`

## تكامل دفع FCM

### البنية

استُبدلت الدالة الوهمية `error_log()` بتسليم دفع حقيقي عبر `kreait/firebase-php` ^7.0.

**الملف**: `service/app/notification/queue/PushSender.php`

### تخزين رمز الجهاز

أُضيف إلى جدول `users` عبر الترحيل:
- `fcm_token VARCHAR(512) DEFAULT NULL` — رمز تسجيل الجهاز
- `fcm_platform VARCHAR(16) DEFAULT NULL` — `ios` / `android` / `web`
- `INDEX idx_fcm_token (fcm_token)` — البحث بالرمز

نموذج المستخدم: أُضيف `fcm_token` و`fcm_platform` إلى `$fillable`.

### إرسال الدفع

```php
public function consume(): void
{
    $factory = new \Kreait\Firebase\Factory();
    if ($credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH')) {
        $factory = $factory->withServiceAccount($credentialsPath);
    }
    $messaging = $factory->createMessaging();
    
    $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget(
        'token', $this->user->fcm_token
    )->withNotification([
        'title' => $this->notification->title,
        'body'  => $this->notification->body,
    ]);
    
    $result = $messaging->send($message);
}
```

### تنظيف الرموز

- تلتقط `Kreait\Firebase\Exception\Messaging\InvalidToken` — تُلغِي `fcm_token` الخاص بالمستخدم
- تلتقط `Kreait\Firebase\Exception\Messaging\NotFound` — تزيل الرمز غير المسجل
- تتراجع إلى `error_log()` عندما تكون بيانات اعتماد Firebase غير معينة (وضع التطوير)

### الإعداد

متغيرات البيئة: `FIREBASE_CREDENTIALS_PATH` (JSON حساب الخدمة)، `FCM_SERVER_KEY` (قديم)

## مخططات تدفق الأعمال

### الطلب → الدفع → التوفير (تدفق الأعمال الأساسي)

![مخطط تدفق الطلب والدفع والتوفير](diagrams/order-payment-provisioning.svg)

### تفاصيل التوفير القائم على الأحداث

![التوفير القائم على الأحداث](diagrams/provisioning-detail.svg)

### توزيع الإشعارات

![توزيع الإشعارات](diagrams/notification-dispatch.svg)

### دورة حياة المورد

![دورة حياة المورد](diagrams/supplier-lifecycle.svg)

### دورة حياة التذكرة

![دورة حياة التذكرة](diagrams/ticket-lifecycle.svg)

## مجموعة اختبارات طبقة الخدمة

### نظرة عامة

```
PHPUnit 10.5 | 295 tests | 455 assertions
```

**الدليل**: `service/tests/` — 12 ملف اختبار عبر 7 وحدات

**الإعداد**: `service/phpunit.xml` — مجموعة اختبارات `unit` واحدة تغطي شيفرة `app/` و`common/`

### إقلاع الاختبارات

`service/tests/bootstrap.php` يحمّل autoload الخاص بـ Composer ويعرّف دالتين عامتين تحتاجهما الشيفرة قيد الاختبار:

- `request_id()` — تُرجع سلسلة معرّف طلب فريدة
- `now()` — تُرجع كائن `DateTime` حاليًا

درس مهم: لا يمكن تحميل `Webman\Config` في سياق الاختبار لأن `loadFromDir()` يشغّل `route.php` الذي يستدعي `Route::addRoute()` على null. تتجاوز الاختبارات Config بالكامل — يستخدم `HashidServiceTest` فئة `new Hashids()` مباشرة، ويستخدم `ResponseTest` دوال مساعدة محلية.

### ملفات الاختبار

| الملف | الاختبارات | التغطية |
|------|-------|----------|
| `Captcha/CaptchaServiceTest.php` | 9 | بنية الإنشاء، مستويات الصعوبة، نجاح/فشل التحقق، الاستخدام لمرة واحدة، المفاتيح الفريدة |
| `Confirmation/ConfirmationMiddlewareTest.php` | 11 | مطلوب مصادقة، كلمة مرور مفقودة، كلمة مرور خاطئة، التمرير الناجح، تنسيق مفتاح حد المعدل، تنسيق مفتاح القفل، عتبات الفشل القصوى |
| `Common/HashidServiceTest.php` | 17 | دورة تشفير/فك تشفير، الحتمية، عزل الملح، تجوال المعرّفات التكراري |
| `Common/ResponseTest.php` | 16 | بنية النجاح/الخطأ/الترقيم، اتساق request_id، رموز خطأ HTTP |
| `Common/SnowflakeTest.php` | 6 | ترتيب الطوابع الزمنية، التفرد، نطاق bigint، نمط التهيئة |
| `Common/ValidatorTest.php` | 22 | قواعد التحقق required()، email()، minLength() |
| `Common/LogSanitizerTest.php` | 34 | إخفاء PII، المصفوفات المتداخلة، المطابقة غير الحساسة لحالة الأحرف، 20 نوع حقل حساس |
| `Payment/StripeChannelTest.php` | 19 | إعداد القناة، حساب المبلغ، تواقيع الويب هوك، التكرار |
| `Payment/PaymentRouterTest.php` | 10 | فلترة القنوات، قيود المبالغ، دعم العملة/المنطقة، حساب الرسوم |
| `Notification/NotificationDispatcherTest.php` | 8 | عرض القوالب، توجيه القنوات، تخطي المستخدم غير النشط |
| `Provisioning/ProviderFactoryTest.php` | 12 | التسجيل، الإنشاء، createFromResource، حالات الخطأ |
| `Provisioning/RetryLogicTest.php` | 12 | التراجع الأسي، الحد الأقصى للمحاولات، انتقالات الحالة، اختيار المضيف |
| `ClientPlatform/ClientPlatformMiddlewareTest.php` | 13 | المنصات الصالحة، رأس مفقود/افتراضي، منصة غير مدعومة، عدم الحساسية لحالة الأحرف، تخطي غير الـ API، مسارات الإدارة، الوصول المصب |
| `Security/WafMiddlewareTest.php` | 37 | SQLi (4)، XSS (6)، CMDi (4)، تضمين الملفات (3)، حقن الترويسات/CRLF (2)، SSRF (5)، حقن NoSQL (4)، إعادة توجيه مفتوحة (2)، تمرير آمن (5)، فحص URL، فحص UA |
| `Version/VersionMiddlewareTest.php` | 6 | إصدار صالح، إصدار مفقود افتراضي، إصدار غير مدعوم 400، تخطي غير الـ API، التحقق من واجهة الإدارة، ترويسات استجابة الخطأ |

### البنية التحتية للاختبار

- `tests/TestCase.php` — فئة أساسية تمدد PHPUnit TestCase
- `tests/Support/RequestMock.php` — طلب وهمي مع معاملات محقونة عبر المُنشئ

## خط أنابيب CI/CD

### البنية

سير عمل GitHub Actions في `.github/workflows/ci.yml`.

**المشغلات**: الدفع إلى `main`، طلبات السحب إلى `main`

### الوظائف

| الوظيفة | الاستراتيجية | الوصف |
|-----|----------|-------------|
| `syntax` | PHP 8.2 | `php -l` يفحص جميع ملفات `.php` في admin/ وservice/ |
| `admin-tests` | PHP 8.2، 8.3 | `composer install -d admin` → `admin/vendor/bin/phpunit` |
| `service-tests` | PHP 8.2، 8.3 | `composer install -d service` → `service/vendor/bin/phpunit` |
| `composer-validate` | PHP 8.2 | `composer validate --strict` على ملفي composer.json |

### مصفوفة إصدارات PHP

تعمل وظيفتا الاختبار على PHP 8.2 و8.3 عبر `shivammathur/setup-php@v2`.

### الحالة الحالية

تجتاز جميع الوظائف الأربع: 243 اختبارًا إجمالاً (67 للإدارة + 176 للخدمة)، 400 تأكيد، وكلاهما أخضر على إصداري PHP.

## علاقة الكيانات في قاعدة البيانات

![علاقة الكيانات في قاعدة البيانات](diagrams/database-er.svg)

## قرارات التصميم الرئيسية

1. **نسخة مستقلة**: تعمل admin/ كنسخة webman خاصة بها، وليس كمكوّن إضافي داخل service/. يعزل ذلك حركة مرور الإدارة وأعطالها عن الـ API الموجه للعملاء.

2. **Encryptable + تجزئة كلمة المرور**: تُجزَّأ كلمات المرور بـ bcrypt أولاً، ثم تُشفَّر بـ AES. يعمل تحويل Encryptable على مستوى Eloquent (فوق التجزئة)، فالطبقات: `input → bcrypt hash → model attribute set → Encryptable::set() encrypts → DB`. عند القراءة: `DB → Encryptable::get() decrypts → bcrypt hash → password_verify()`.

3. **Hashids عند حدود المتحكم**: يحدث التشفير/فك التشفير عند حدود HTTP (المتحكمات)، وليس على مستوى النموذج أو ORM. يبقي ذلك النماذج مستقلة عن قاعدة البيانات ويجعل hashids مسألة عرض خالصة.

4. **حل الخدمات القائم على الحاوية**: تُسجَّل الخدمات (Snowflake, HashidsManager, EncryptionManager) كنسخ فردية عبر فئات Bootstrap عند بدء العامل. يستخدم حل الحاوية عبر `\support\Container::instance()` التهيئة الكسولة — لا تُنشأ الخدمات إلا عند أول وصول.

## الميزات الموسعة (2026-05-20)

### Service Admin API — نقاط نهاية جديدة

| المجموعة | نقاط النهاية | المتحكم |
|-------|-----------|------------|
| الفواتير | `GET /admin/api/invoices`، `POST .../invoices/{orderId}/generate` | `Admin\InvoiceController` |
| واجهات المزود | `GET/POST /admin/api/providers`، `PUT/DELETE .../providers/{id}` | `Admin\ProviderApiController` |
| مفاتيح API للموردين | `GET/POST /admin/api/suppliers/{id}/api-keys`، `DELETE .../api-keys/{id}` | `Admin\SupplierController` |
| القسائم | `GET/POST /admin/api/coupons`، `DELETE .../coupons/{id}` | `Admin\CouponController` |
| الويب هوكس | `GET/POST/DELETE /admin/api/webhooks`، `POST .../webhooks/test` | `Admin\WebhookController` |
| استيراد/تصدير المنتجات | `GET /admin/api/products/export`، `POST .../products/import` | `Admin\ImportExportController` |
| إدارة النطاقات | `GET/POST/PUT/DELETE /admin/api/domains/tlds`، `GET .../zones`، `GET .../transfers`، `POST .../transfers/{id}/approve` | `Admin\DomainController` |
| قوالب الإشعارات | `GET /admin/api/notifications/templates`، `PUT .../templates/{id}`، `GET .../log` | `Admin\NotificationController` |
| مقالات المساعدة | `GET/POST /admin/api/help`، `PUT/DELETE .../help/{id}` | `Admin\HelpController` |

### وسائط جديدة

| الوسيط | الغرض |
|------------|---------|
| `VersionMiddleware` | قراءة إصدار الـ API من رأس X-Api-Version والتحقق منه |
| `RateLimitMiddleware` | حد معدل Redis بدلو الرموز (الافتراضي 60 طلب/دقيقة، تسجيل الدخول 5 طلبات/دقيقة) |
| `GeoBlockMiddleware` | حظر جغرافي عبر MaxMind GeoIP2 |
| `MaintenanceMiddleware` | وضع الصيانة (مفتاح متغير البيئة + قائمة IP البيضاء) |
| `ClientPlatformMiddleware` | التعرف على منصة العميل (رأس X-Client-Platform)، يدعم 8 منصات |
| `SupplierApiKeyMiddleware` | مصادقة الـ API الخارجي للموردين (التحقق من SHA256 لمفتاح sk_xxx) |
| `WafMiddleware` (admin) | وسيط WAF للوحة الإدارة، 8 فئات بأكثر من 45 قاعدة + حد حجم الطلب + التحقق من Content-Type |

### المهام المجدولة

| الجدولة | المهمة | الغرض |
|----------|------|---------|
| `13 */4 * * *` | ExchangeRateSync | تحديث أسعار الصرف |
| `37 2 * * *` | PaymentReconcile | تسوية المدفوعات اليومية |
| `17 4 * * 1` | SupplierSettlement | تسوية الموردين الأسبوعية |
| `23 6 * * *` | ExpirationCheck | فحص انتهاء صلاحية الموارد/النطاقات + الإشعارات |
| `43 7 * * *` | SslCertificateCheck | فحص انتهاء صلاحية شهادات SSL + الإشعارات |
| `*/5 * * * *` | CollectMetrics | جمع مقاييس الموارد |
| `*/30 * * * *` | CheckExpirations | فحص انتهاء صلاحية الموارد |

### أوامر CLI

| الأمر | الغرض |
|---------|---------|
| `php webman migrate` | تشغيل الترحيلات المعلقة |
| `php webman migrate:rollback` | تراجع الدفعة السابقة |
| `php webman migrate:status` | عرض حالة الترحيل |
| `php webman db:backup` | نسخ قاعدة البيانات احتياطيًا إلى ملف SQL (رفع اختياري إلى --s3) |

### ترحيلات قاعدة البيانات المضافة (2026-05-20)

| الترحيل | الجداول/الأعمدة |
|-----------|---------------|
| 000003 | users + totp_secret, totp_enabled |
| 000004 | coupons, user_coupons |
| 000005 | help_articles, supplier_api_keys |
| 000006 | roles, permissions, role_permission + بيانات أولية |
| 000007 | users + email_verified_at, email_verify_token |
| 000008 | users + last_login_ip, last_login_at |

## فهرس التوثيق

### الوثائق الأساسية

| الوثيقة | المسار | الوصف |
|----------|------|-------------|
| وثيقة التصميم المعماري | `docs/architecture.md` | بنية النظام، علاقات المكونات، خط وسائط الطلبات، طبقات الأمان، بنية البيانات، طوبولوجيا النشر |
| وثيقة التصميم الوظيفي | `docs/features.md` | تصميم وظيفي مفصل لـ 21 وحدة، يتضمن مخططات التدفق ونماذج البيانات وتعليمات التفاعل |
| وثيقة واجهة الـ API | `docs/api-reference.md` | مرجع كامل لأكثر من 200 نقطة نهاية، مجمعة حسب الوحدة، مع أمثلة الطلبات/الاستجابات ورموز الخطأ |
| وثائق الـ API عبر الإنترنت (service) | `http://localhost:8787/apidoc` | مولدة تلقائيًا عبر hg/apidoc، مجمعة حسب الوظيفة، تدعم التصحيح عبر الإنترنت |
| وثائق الـ API عبر الإنترنت (admin) | `http://localhost:8788/apidoc` | مولدة تلقائيًا عبر hg/apidoc، 54 متحكمًا في 13 مجموعة وظيفية |
| مواصفات تصميم النظام | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` | البنية الكاملة، نماذج البيانات، تصميم الـ API، استراتيجيات الأمان |
| تصميم لوحة الإدارة | `docs/admin-design.md` | بنية لوحة الإدارة، تكامل الحزم، صلاحيات ACL، مجموعة الاختبارات |
| وثائق واجهة المورد | `docs/supplier-api.md` | مرجع واجهة المورد (واجهة داخلية + واجهة خارجية)، أمثلة SDK |
| قائمة النشر | `docs/deployment.md` | إعداد الخادم، متغيرات البيئة، ترحيلات قاعدة البيانات، Nginx، HTTPS، المهام المجدولة |

### خطط التنفيذ

| الوثيقة | المسار | الوصف |
|----------|------|-------------|
| المرحلة 0 — البنية الأساسية | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase0.md` | هيكل المشروع، بنية الدلائل، البنية التحتية الأساسية |
| المرحلة 1 — المستخدمون والمتجر | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase1.md` | مصادقة المستخدمين، إدارة المنتجات، سلة التسوق، الطلبات |
| المرحلة 2 — الموارد والموردون | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase2.md` | توفير الموارد، DNS، انضمام الموردين |
| المرحلة 3 — العملاء والتسليم | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase3.md` | عميل Flutter، التكيف متعدد المنصات، CI/CD |

### الأدوات والموارد

| الوثيقة | المسار | الوصف |
|----------|------|-------------|
| اختبار الـ API الوهمي | `docs/api-test.sh` | سكربت اختبار آلي لنقاط نهاية الـ API مبني على curl |
| DDL قاعدة البيانات | `docs/database.sql` | عبارات إنشاء جداول قاعدة البيانات |

## إحصائيات الاختبارات النهائية

```
OK (362 tests, 579 assertions)
Test files: 22
```
- Admin: 67 اختبارًا، 124 تأكيدًا
- Service: 295 اختبارًا، 455 تأكيدًا
