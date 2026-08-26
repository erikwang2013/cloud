# وثيقة تصميم وظائف CloudPlatform

## 1. مصادقة المستخدم والتفويض

### 1.1 التسجيل

```
POST /api/auth/register
  → فحص WAF
  → تقييد التردد 3 طلبات/دقيقة
  → التحقق من كلمة المرور len≥8
  → فحص تفرد البريد الإلكتروني/رقم الهاتف
  → bcrypt(password, cost=12)
  → Snowflake::id() لتوليد user_id
  → Encryptable::set() لتشفير الحقول الحساسة
  → إنشاء User + UserProfile + UserBalance
  → NotificationDispatcher::send('email_verify') لإرسال بريد التحقق
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**تدفق البيانات:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 تسجيل الدخول

```
POST /api/auth/login
  → فحص WAF
  → تقييد التردد 5 طلبات/دقيقة
  → التحقق من الكابتشا (كابتشا النقر، حد 3 محاولات)
  → Hash::check(password, user->password_hash)
  → 5 محاولات فاشلة ← login_lock:{userId} Redis TTL 900s
  → التحقق من TOTP (إلزامي عند تفعيله لدى المستخدم، totp_code مطلوب؛
      تراكم 5 أخطاء ← totp_fail:{userId} ← login_lock TTL 900s)
  → كشف IP جديد ← تنبيه بريد إلكتروني
  → deviceFingerprint = sha256(UA + مقطع IP، IPv6 يأخذ البادئة)
  → clientPlatform = رأس X-Client-Platform
  → issueTokens(): Access(15د) + Refresh(30يوم)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. التحقق من ID Token الخاص بـ Google/Apple
  2. البحث عن المستخدم أو إنشائه (مطابقة البريد الإلكتروني)
  3. إصدار token (يشمل client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 التحقق الثنائي TOTP

```
1. POST /api/user/totp/setup
     → توليد secret + رابط QR (تخزين مؤقت في Redis لمدة 10 دقائق، لا يتم حفظه دائماً)
     ← {secret, qr_url, manual}
2. POST /api/user/totp/verify
     → التحقق من كود TOTP (المرة الأولى تفعيل setup، وبعدها تحقق)
     ← {verified: true}
3. GET /api/user/totp/recovery-codes
     → توليد 8 رموز استرداد لمرة واحدة (يتطلب تأكيد كلمة المرور)
     ← {recovery_codes: [8 رموز]}
4. عند تسجيل الدخول: إدخال كود TOTP أو استخدام رمز الاسترداد
     → POST /api/auth/login/recovery (login, password, recovery_code)
```

### 1.5 إدارة الجلسات

```
GET /api/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/user/account (حذف الحساب وفقاً لـ GDPR)
  → تأكيد ثانٍ بكلمة المرور
  → حذف ناعم لـ User
  → إبطال كل RefreshToken
```

---

## 2. إدارة المنتجات

### 2.1 نموذج المنتج

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (شهري/سنوي)    │ price
  │ status             │                      │ original_price
  │ name (JSON متعدد اللغات) │                │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 قائمة المنتجات (مع التخزين المؤقت)

```
GET /api/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5دقائق)
  → Product::published()
    → with(category, skus.regionPrices)
    → التصفية حسب category_id/region_id/keyword/supplier_id
    → count + ترقيم الصفحات skip/take
  ← النتيجة المقسّمة صفحات

إبطال التخزين المؤقت:
  تغيير منتج/SKU/سعر منطقة في admin
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 البحث في المنتجات (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (محلل IK لتقسيم الصينية)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 تقييمات المنتجات

```
GET /api/products/{id}/reviews
  → التقييمات المراجعة + متوسط الدرجة + توزيع الدرجات
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (يتطلب تسجيل الدخول)
  → rating (1-5) + content
  → status = pending (تُعرض بعد مراجعة المسؤول)
```

### 2.5 الاستيراد/التصدير بالجملة

```
GET /admin/api/products/export
  → تنزيل CSV (المنتج + SKU + تسعير المناطق)

POST /admin/api/products/import
  → رفع CSV مع upsert
  ← {imported: N, errors: [...]}
```

---

## 3. نظام الطلبات

### 3.1 سلة التسوق

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → قائمة السلة (تشمل تفاصيل SKU + الأسعار اللحظية)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 تدفق إنشاء الطلب

```
1. POST /api/orders                            إنشاء الطلب
     → التحقق من المخزون وحساب السعر وتطبيق القسيمة
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                  تطبيق القسيمة
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods        الحصول على قنوات الدفع المتاحة
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/orders/{id}/pay                   إطلاق الدفع
     → تأكيد ثانٍ بكلمة المرور (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 دورة حياة الطلب

```
                    ┌─────────┐
                    │ pending  │ في انتظار الدفع
                    └────┬─────┘
                         │ نجاح الدفع
                    ┌────┴─────┐
                    │  paid    │ مدفوع
                    └────┬─────┘
                         │ حدث OrderPaid
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ مكتمل
                    └────┬─────┘
                         │ طلب استرداد من المستخدم
                    ┌────┴─────┐
                    │ refunded │ مسترد
                    └──────────┘

شروط الاسترداد: الخادم خلال 72 ساعة | النطاق خلال 5 أيام | IP غير قابل للاسترداد | منتجات العروض غير قابلة للاسترداد (الأنواع الأخرى مثل disk بلا نافذة زمنية؛ الأنواع غير المعروفة تُسمح افتراضياً)
تدفق الاسترداد: طلب المستخدم ← إنشاء Ticket ← مراجعة خدمة العملاء ← تأكيد admin ← Provider.destroy() ← Payment.refund()
```

---

## 4. نظام الدفع

### 4.1 التوجيه متعدد القنوات

```
PaymentRouter::route(Order $order)
  → تصفية القنوات المتاحة (is_visible + visible_regions + min/max_amount)
  → المطابقة حسب العملة
  → حساب مبلغ الدفع الفعلي لكل قناة (يشمل رسوم المعالجة)
  → الترتيب تصاعدياً حسب fee
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 الدفع عبر Stripe

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. اختيار Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (سنتات)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. إنشاء transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. المستخدم يؤكد الدفع
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             التحقق من توقيع stripe-signature
                             فحص التطابق transaction_no
                          8. transaction=success
                          9. إطلاق حدث OrderPaid
                             → ProvisioningService
                             → دفع WebSocket
                             → إشعارات بريد/رسائل/دفع
```

### 4.3 التسوية

```
Cron: PaymentReconcile (يومياً 02:37)
  → سحب تقارير تسوية كل قناة
  → مطابقتها مع transactions النظام واحدة واحدة
  → اختلاف > $0.01 ← تنبيه
```

---

## 5. محرك توفير الموارد

### 5.1 بنية إضافات Provider

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → مثيل Provider
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (قابل للتوسيع)
  'server:aliyun_ecs'  → AliyunProvider (قابل للتوسيع)
  'domain:namecheap'   → DomainProvider (قابل للتوسيع)
```

### 5.2 سلسلة التوفير الكاملة

```
حدث OrderPaid يُطلق
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ إنشاء ProvisionTask لكل OrderItem
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (استهلاك Redis Queue)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     الترتيب حسب فائض cpu/ram/disk + موازنة الحمل
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     إنشاء VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     تركيب قرص النظام (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     تشغيل VM
  │
  ├→ إنشاء سجلات Resource + Disk + IpAllocation
  │
  ├→ تحديث حجم الموارد المخصصة في host_machine
  │
  └→ Order::status = completed
       → دفع WebSocket 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

استراتيجية إعادة المحاولة:
  1د ← 5د ← 15د ← 1س ← 6س ← 24س (بعد 6 محاولات يُعلَّم كفشل + تنبيه)
```

> **تطور قناة التوفير**: خادم Rust kvm-server (`infrastructure/kvm-server`، workspace e-cat) دخل المستودع —
> gRPC `ping/create_vm/vm_status` (:50051) + اكتشاف التسجيل عبر etcd، وجانب PHP KvmClient /
> RegistryProcess (`service/app/grpc/`) موصول. طبقة المحرك حالياً **محرك محاكى** (المحرك الفعلي
> libvirt في Phase 2)، ومسار التوفير ما يزال يمر عبر اتصال ProxmoxProvider المباشر؛ وعندما يتولى
> kvm-server إنشاء VM لا تتغير سلسلة هذا القسم، يتبدل المسار فقط.

### 5.3 ملخص عمليات Proxmox

| العملية | API | عملية ساخنة |
|------|-----|--------|
| إنشاء VM | POST /nodes/{node}/qemu | — |
| ترقية CPU | PUT /qemu/{vmid}/config cores | عبر الإنترنت |
| ترقية الذاكرة | PUT /qemu/{vmid}/config memory | عبر الإنترنت |
| توسيع قرص النظام | PUT /qemu/{vmid}/resize disk | عبر الإنترنت |
| إنشاء قرص بيانات | POST /qemu/{vmid}/config scsi{n} | عبر الإنترنت |
| إنشاء IP مستقل | POST /qemu/{vmid}/config net{n} | عبر الإنترنت |
| إتلاف VM | POST stop ← DELETE qemu | — |
| الاستعلام عن الحالة | GET /qemu/{vmid}/status/current | — |

---

## 6. نظام الموردين

### 6.1 تدفق التسجيل

```
POST /api/supplier/apply (يتطلب تسجيل دخول المستخدم)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → مراجعة المسؤول

موافقة المسؤول:
  POST /admin/api/suppliers/{id}/approve (تأكيد كلمة المرور)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → يكتسب المستخدم صلاحيات المورد

إدراج المنتجات:
  POST /api/supplier/products
    → {product_id, commission_rate}
    → ربط منتج المورد

التسوية:
  Cron: SupplierSettlement (الاثنين 04:17)
    → إحصاء الطلبات المكتملة في الدورة
    → total_sales - commission = payable
    → إنشاء SupplierSettlement

السحب:
  POST /api/supplier/withdraw (تأكيد كلمة المرور)
    → فحص الرصيد القابل للسحب
    → إنشاء SupplierWithdraw (status=pending)
    → موافقة المسؤول والتحويل
```

### 6.2 API الخارجي

```
POST /admin/api/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → تخزين hash('sha256', rawKey)
  ← {api_key: "sk_xxx..."} (يُعرض مرة واحدة فقط)

استخدام المورد:
  GET /api/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → التحقق من التوقيع عبر SupplierApiKeyMiddleware
    → تصفية البيانات حسب supplierId
```

---

## 7. النطاقات و DNS

```
GET /api/domain/check/{domain}/{tld}    # توفر النطاق
GET /api/domain/tlds                     # قائمة TLD القابلة للتسجيل (تخزين مؤقت ساعة)
GET /api/dns/{domain}                    # قائمة سجلات DNS
POST /api/dns/{domain}/records           # إضافة سجل DNS
DELETE /api/dns/{domain}/records/{id}    # حذف سجل DNS (تأكيد كلمة المرور)
```

---

## 8. نظام التذاكر

```
POST /api/tickets                    # إنشاء تذكرة
GET /api/tickets                     # تذاكري
GET /api/tickets/{id}                # تفاصيل التذكرة
POST /api/tickets/{id}/reply         # الرد على التذكرة

المسؤول:
  GET /admin/api/tickets              # قائمة انتظار التذاكر
  POST /admin/api/tickets/{id}/assign # توزيع خدمة العملاء
  POST /admin/api/tickets/{id}/close  # إغلاق التذكرة

القيادة بالأحداث:
  حدث TicketCreated
    → AutoAssignListener: التوزيع على أقل خدمة عملاء حملاً
    → دفع WebSocket 'ticket.created'
```

---

## 9. نظام الإشعارات

### 9.1 التوزيع عبر أربع قنوات

```
إطلاق الحدث ← NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    ← Redis Queue ← EmailSender (SMTP/SendGrid)
  ├─ sms      ← Redis Queue ← SmsSender (Twilio)
  ├─ push     ← Redis Queue ← PushSender (FCM)
  └─ in_app   ← كتابة مباشرة في جدول notifications
```

### 9.2 أنواع الإشعارات

| الحدث | القناة | لحظة الإطلاق |
|------|------|---------|
| تحقق التسجيل | email | بعد التسجيل بالبريد الإلكتروني |
| تنبيه تسجيل دخول غير طبيعي | email | تسجيل دخول من IP جديد |
| نجاح دفع الطلب | email/push | اكتمال الدفع |
| اكتمال توفير الموارد | email/push/in_app | اكتمال Provisioning |
| تذكير انتهاء الموارد | email/push | قبل 7أيام/3أيام/يوم واحد |
| الرد على التذكرة | email/push/in_app | رسالة جديدة في Ticket |
| اكتمال الاسترداد | email/push | انتهاء معالجة الاسترداد |
| انتهاء شهادة SSL | email | قبل 30 يوماً |
| انتهاء النطاق | email | قبل 30 يوماً |

---

## 10. المراقبة والتنبيه

### 10.1 مراقبة الموارد

```
Cron: CollectMetrics (كل 5 دقائق)
  → الاستقصاء على الموارد النشطة
  → ProxmoxApi::status() / واجهة Provider
  → تخزين المقاييس في Redis hash (TTL ساعة)

المسؤول:
  GET /admin/api/monitor/dashboard
    → إحصاءات عامة + آخر التنبيهات
  GET /admin/api/monitor/resources/{id}
    → المقاييس اللحظية (قراءة من Redis)
```

### 10.2 قواعد التنبيه

| القاعدة | الخطورة | شرط الإطلاق |
|------|--------|---------|
| server_down | خطيرة | 3 عمليات Ping متتالية غير قابلة للوصول |
| cpu_high | تحذير | CPU > 90% لمدة 10 دقائق |
| disk_high | تحذير | القرص > 90% لمدة 5 دقائق |
| ssl_expiring | تحذير | شهادة SSL تنتهي خلال < 30 يوماً |
| domain_expiring | تحذير | النطاق ينتهي خلال < 30 يوماً |
| provision_failed | خطيرة | فشل متتالٍ لمهام التوفير |

---

## 11. المهام المجدولة

| تعبير Cron | المهمة | الغرض |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | مزامنة أسعار الصرف كل 4 ساعات |
| `37 2 * * *` | PaymentReconcile | التسوية اليومية |
| `17 4 * * 1` | SupplierSettlement | تسوية الموردين الاثنين |
| `23 6 * * *` | ExpirationCheck | فحص الانتهاء + الإشعارات |
| `43 7 * * *` | SslCertificateCheck | فحص شهادات SSL |
| `*/5 * * * *` | CollectMetrics | جمع مقاييس الموارد |
| `*/30 * * * *` | CheckExpirations | فحص انتهاء الموارد |

---

## 12. الترجمة الدولية (i18n)

### 12.1 تدفق الطلب

```
العميل ← Accept-Language: zh-CN
  ← LocaleMiddleware (وسيط عام)
    ← I18n::setLocale('zh-CN')
    ← تحميل i18n/zh-CN/messages.php
```

### 12.2 طريقة الترجمة

**نص ثابت:** `I18n::trans('auth.login_success')` ← `تم تسجيل الدخول بنجاح`
**حقل JSON:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**استبدال المعاملات:** `I18n::trans('validation.required', ['field' => '邮箱'])` ← `邮箱 不能为空`

### 12.3 نطاق التغطية

120 مدخلاً، تغطي جميع الوحدات: المصادقة/المنتجات/الطلبات/الدفع/الموارد/KYC/التذاكر/الإشعارات/الموردين/Webhook/النظام. دعم الرجوع للغة (اللغة غير المدعومة ← en-US).

---

## 13. مفاتيح الميزات Feature Flags

```
config/features.php (القيم الافتراضية)
  ↓ يمكن تغطيتها
متغيرات البيئة .env FEATURE_*
  ↓ يمكن تغطيتها وقت التشغيل
Redis feature:{name} (TTL ساعة، تعديل ديناميكي عبر API الإدارة)

واجهة الإدارة:
  GET /admin/api/features ← سرد جميع الأعلام والحالة/المصدر
  PUT /admin/api/features/{name} ← enable/disable/toggle/reset

الأعلام الحالية:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. شهادات SSL

يدعم منتج شهادات SSL ثلاثة أنواع DV/OV/EV، مع الإصدار والتجديد التلقائي عبر بروتوكول ACME (Let's Encrypt) أو واجهة CA خارجية (ZeroSSL/GoGetSSL).

**التدفق الرئيسي:**

     اختيار المستخدم لخطة SSL ← إنشاء الطلب والدفع ← إنشاء ProvisionTask
      ← SslProvider::create() ← CertificateAuthority::issue()
      ← تحقق ACME HTTP-01/DNS-01 ← إصدار الشهادة
      ← فحص expires_at يومياً ← التجديد التلقائي قبل 14 يوماً من الانتهاء
      ← الانتهاء ← status=expired ← إشعار المستخدم

**نموذج البيانات:** `ssl_plans` (الخطط)، `resource_ssl_certs` (مثيلات الشهادات)

## 15. التخزين الكائني (S3)

تخزين كائني متوافق مع واجهة S3، يدعم AWS S3 وتخزين MinIO الذاتي. يرفع/ينزل المستخدم الملفات عبر روابط موقعة مسبقاً.

**نموذج البيانات:** `resource_storage_buckets`

## 16. تسريع CDN

يدعم منتج CDN تكامل Cloudflare، ويمكن ربط الخوادم أو الجرافات التخزينية كمصدر في CDN، مع دعم مسح ذاكرة التخزين المؤقت.

**الواجهة:** ProviderInterface + CachePurgeInterface (واجهة قدرة اختيارية)

**نموذج البيانات:** `resource_cdn`

## 17. الفوترة حسب الاستخدام

خط أنابيب كامل من جمع الاستخدام ← التجميع ← الفوترة ← الخصم:

    ResourceMonitor يجمع المقاييس كل 5 دقائق ← resource_metrics
      ← UsageAggregator يجمع كل ساعة ← usage_events
      ← BillingEngine يخصم الرصيد يومياً ← نقص الرصيد ← تعليق الموارد
      ← SuspendCheck يفحص كل 30 دقيقة ← استعادة الرصيد ← إلغاء التعليق

**نماذج البيانات:** `resource_metrics`، `usage_events`، `usage_rates`، `usage_invoice_items`

## 18. تقييم الموردين

يمكن للمستخدمين المشترين تقييم الموردين على أربعة أبعاد (الجودة/الدعم/سرعة التسليم/قيمة مقابل المال)، مرة واحدة لكل طلب. يمكن للإدارة المراجعة (approve/hide).

**نماذج البيانات:** `supplier_ratings`، `suppliers.rating_avg/rating_count`

## 19. توزيع الإحالة

ينشئ المستخدمون روابط إحالة (?ref=CODE)، ويُربط affiliate_code عند تسجيل المستخدم الجديد، وتُعزى العمولة تلقائياً بعد دفع الطلب.

**القيادة بالأحداث:** OrderPaid ← Affiliate OrderPaidListener ← attributeOrder()

**نماذج البيانات:** `affiliate_plans`، `affiliate_links`، `affiliate_earnings`، `affiliate_payouts`

## 20. واجهة GraphQL API

توفر نقطتي نهاية: POST /graphql (استعلام عام) و POST /api/graphql (استعلام مصادق). مبنية على webonyx/graphql-php، حد عمق الاستعلام 5 مستويات، وحد التعقيد 100.

**العمليات الحساسة تبقى REST فقط:** الدفع والسحب والاسترداد ومراجعة KYC.

## 21. قابلية المراقبة

نقطة نهاية مقاييس Prometheus في عملية مستقلة 127.0.0.1:9100، لا تتأثر بـ WAF/تقييد التردد. يسجل MetricsMiddleware عد وحمول طلبات HTTP. يوفر Docker Compose مسبقاً Prometheus + Grafana + قواعد التنبيه + لوحات المعلومات.

**فحوصات الصحة:** /health (عام)، /health/live، /health/ready (فحص 5 تبعيات)، /health/deps (تفاصيل التأخير)
