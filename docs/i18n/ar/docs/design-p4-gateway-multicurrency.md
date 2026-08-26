# تصميم P4.1 + P4.2: بوابة API مستقلة/تقييد تردد موحد + اتساق كامل المسار متعدد العملات

> الإصدار: 2026-08-17 v1｜من إنتاج المهندس المعماري، للتنفيذ في gateway-impl / multicurrency-impl، ومراجعة عبر reviewer-gate
> المرجع: docs/team-plan.md v2 المرحلة 4، docs/architecture.md، قراءة فعلية للكود الحالي

---

## P4.1 بوابة API مستقلة + تقييد تردد موحد

### الوضع الحالي (مؤكد بالقراءة الفعلية)

| الطبقة | الوضع الحالي |
|----|------|
| بوابة الحافة | docker/nginx.conf يتولى بوابة L7 لخدمة service: `limit_req_zone api 10r/s` (تقييد تردد عام)، proxy_pass 8787 (service)، 8282 (ws). **admin حاوية مستقلة** (Dockerfile هدف admin، nginx-admin.conf يستمع على 8788 ويعيد التوجيه إلى 8788)، **بلا limit_req** |
| تقييد التردد في التطبيق | `service/common/security/RateLimitMiddleware.php` موجود: Redis INCR+expire نافذة ثابتة، **لكل IP فقط**، يختار القاعدة حسب `ROUTE_MAP`، مُثبَّت على **المسارات الصريحة** (route.php نحو ~12 موضعاً) |
| إعداد القواعد | `config/security.php rate_limits`: default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql، جميعها تتضمن rate/burst/per، لكن **حقل burst غير مستخدم حالياً** |
| الوسائط العامة | مفتاح `''` في `config/middleware.php` يدعم سريان مفعول على جميع المسارات (WAF/GeoBlock/Security وغيرها 10 بنود هنا) |
| الفجوة | `/graphql` (مساران عام + مصادقة) **بلا أي تقييد تردد**؛ لا يوجد تقييد per-token؛ استجابة 429 بلا رأس `Retry-After`؛ webhook بلا استثناء/قاعدة مخصصة |

### القرارات

**D1: لا إنشاء عملية بوابة مستقلة جديدة.** nginx هو البوابة (حافة الشبكة + تقييد التردد + تقسيم التوجيه)، والتقييد الموحد داخل webman.
- السبب: حاوية gateway مستقلة تتطلب اعتماداً جديداً/طوبولوجيا نشر جديدة/مصادقة مزدوجة، وهذا إفراط في التصميم بمقاس المثيل الواحد الحالي؛
- المقايضة: لا يمكن عند طبقة البوابة القيام بتقييد تردد متمايز حسب token/حسب المسار (nginx فقط مقاطع per-IP). يُكمل التمايز عند طبقة التطبيق، وتكتفي nginx بتقييد IP خشن كشبكة أمان (رفع 10r/s الحالية إلى 100r/s لتجنب التأثير على الأعمال، وإعادتها لقيم العرض عند تحقق k6).
- مسار التطور: إذا تعددت المثيلات/الخدمات مستقبلاً، يُنقل مُقيّد التردد العام من `config/middleware.php` كما هو إلى خدمة gateway مستقلة، فالوسيط لا يستشعر شكل النشر.

**D2: تقييد التردد الموحد = وسيط عام + دلوان بُعدان (per-IP + per-token).**
- إزالة `RateLimitMiddleware` من المسارات الصريحة (فعلياً نحو 12 موضعاً في route.php، بالاعتماد على grep)، وتثبيته في القائمة العامة `''` في `config/middleware.php` (بعد WAF وقبل وسائط الأعمال)، **فيغطي بطبيعة الحال جميع المسارات داخل التطبيق (بما فيها مسارا /graphql)**.
- **دلالة الدلو (واضحة، تمنع الالتفاف)**: `ratelimit:ip:{realIp}:{rule}` و`ratelimit:tok:{sha256(token)}:{rule}` دلوّان مستقلان العد، **تجاوز أي منهما يؤدي 429 (OR)**. يُمنع التنفيذ بنمط AND — فمع AND يمكن تغيير IP للالتفاف على دلو per-IP، وتغيير token للالتفاف على دلو per-token.
- **قائمة الاستثناء**: `/health*` (مسبارات المراقبة) و`/api/payments/webhook/stripe` (التحقق من التوقيع هو خط الدفاع الحقيقي + إعادة المحاولة التلقائية عند 429 من Stripe + شبكة أمان nginx الخشنة 100r/s ما تزال مفعلة؛ لا مكسب أمني من التقييد، بل خطر فقدان أحداث/تأخير الإيداع). جميع المسارات الأخرى إلزامية التقييد.
- الاستجابة: `HTTP 429` + رأس `Retry-After` (أقصى ما تبقى من نوافذ الدلوين، والنافذة الثابتة تستخدم Redis `PTTL` للبقاء الدقيق) + body `{code:429, message, retry_after}` (بالتوافق مع `Response::error` الحالي).
- الاندفاع: تفعيل حقل burst — `rate` هو الحصة الثابتة داخل النافذة، و`burst` هو الائتمان القابل للسحب. التنفيذ بحد عدّاد مفتاح Redis `rate + burst` (سحب داخل النافذة الثابتة)، بلا حاجة لنافذة منزلقة (ponytail: النافذة الثابتة عند الحدود تعطي تضخم نافذة بعامل 2، وper-IP كافٍ لإساءة الاستخدام أحادية الآلة؛ عند الحاجة لصرامة أكبر نبدل لنافذة منزلقة).
- تعيين المسار←القاعدة: الإبقاء على `ROUTE_MAP` الحالي وإضافة `'/graphql' => 'graphql'` (config/security.php:46 يتضمن بالفعل `{rate:30, burst:5, per:60}`)؛ المسارات غير المعروفة تذهب إلى `default` (60/60s).
- تعذر Redis: الإبقاء على fail-open الحالي (catch Exception ثم السماح) — شبكة أمان nginx 100r/s ما تزال مفعلة.
- **النطاق**: حاوية service فقط. admin حاوية مستقلة (nginx-admin.conf بلا limit_req، بلا تقييد حالياً)، وتغييرات service/config ووسائط service لا تمس admin — تقييد تردد admin خارج نطاق P4.1، يُتخذ قراره بشكل منفصل.

**D3: تقييد التردد قبل المصادقة.** الوسيط العام يقع قبل AuthMiddleware (ترتيب middleware.php هو ترتيب التنفيذ)، لذا يتحول دلو per-token للطلبات غير الحاملة لـ token إلى دلو per-IP؛ والطلبات الحاملة لـ token تُحتسب في دلو token حتى لو كان المسار مجهولاً (مثل /api/products) — لمنع إساءة استخدام token مشترك.

### نطاق التأثير

| البند | التغيير |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | إعادة البناء: دلو per-token وburst وRetry-After وقاعدة graphql |
| `service/config/middleware.php` | إضافة RateLimitMiddleware إلى قائمة `''`؛ إزالته من جميع نقاط التثبيت الصريحة في route.php |
| `service/config/security.php` | إبقاء `default` {60,10,60} دون تغيير (عتبة القبول = rate+burst = 70)؛ `graphql` {30,5,60} موجود أصلاً، لا حاجة لإضافته؛ استمرار استخدام حقل burst |
| `service/config/route.php` | حذف نحو 12 موضع تثبيت صريح لـ `RateLimitMiddleware::class` (بالاعتماد على grep الفعلي، مجموعات auth/supplier/admin) |
| `docker/nginx.conf` | رفع limit_req rate من 10r/s إلى 100r/s (شبكة أمان خشنة، لتجنب تقييد الأعمال فوق الوسيط العام) |
| الاختبارات | اختبارات مجموعة service المعتمدة على التثبيت الصريح لوسيط التقييد تحتاج مزامنة؛ إضافة اختبارات وحدة للوسيط |

### القبول (k6)

```
# اختر أي مسار مجهول (مثل GET /api/products) و /graphql، أرسل 200 طلب/10s لكل منهما:
# كل ما فوق عتبة التقييد 429، مع استجابة تحمل Retry-After؛ دون العتبة الكل 200.
# التأكيد: عدد 429 == إجمالي الطلبات − العتبة؛ /graphql يخضع أيضاً (الفجوة الأصلية).
```

---

## P4.2 اتساق كامل المسار متعدد العملات (بما فيها استراتيجية تقريب fee)

### الوضع الحالي (مؤكد بالقراءة الفعلية)

- **التخزين**: جميع المبالغ في `install.sql` من نوع DECIMAL — الرصيد/التجميد `(16,4)`، وsubtotal/discount/tax/total في الطلب وunit_price/total_price في البنود `(12,4)`، و`exchange_rate DECIMAL(12,6)` موجود على `orders` و`payment_transactions`؛ و`user_balances` مفروزة بصفوف حسب العملة (محاسبة تفصل العملات).
- **مصدر سعر الصرف**: `service/app/cron/ExchangeRateSync.php` منفذ — API خارجي مجاني (`EXCHANGE_RATE_API_URL` قابل للتكوين عبر env، الافتراضي exchangerate-api.com) يُزامن كل ساعة إلى Redis `exchange_rate:{CURRENCY}`؛ و`OrderService::getExchangeRate` يقرأ لقطة Redis عند إنشاء الطلب (USD ثابت 1.0) ويكتبها في حقل `exchange_rate` للطلب. **يوجد اعتماد خارجي أصلاً والمصدر قابل للتبديل عبر env، لا حاجة لإضافة جديد.**
- **مشكلة اقتطاع fee**: `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath بالـ scale **يقص** (لا يدوّر)، والاتجاه **أقل تحصيلاً** <0.0001/طلب؛ و`total_amount = amount + fee` مع مبالغ بأكثر من 5 منازل (مثل 10.12345) قد لا يتطابق بعد القص مع total الطلب.
- **فحص التعليق** يحكم على أرصدة حسب العملة (متعدد العملات)، والفوترة عبر Billing حسب meter (سعر الوحدة usage_rates DECIMAL(12,4)).

### القرارات

**D4: ثباتة مبلغ موحدة — لكل عملة دقة داخلية واحدة، والتقريب يحدث في نقطة واحدة فقط.**
- الحساب الداخلي موحد `DECIMAL(12,4)` (بمقاس الطلب) و`DECIMAL(16,4)` (بمقاس الرصيد)، وكل عملية ضرب يجب أن تمر عبر `bcround(x, 4, PHP_ROUND_HALF_UP)`، و`bcadd/bcsub` فقط لجمع/طرح بنفس الدقة (دقيق بطبيعته).
- إضافة المساعد الوحيد للأموال `service/common/money/Money.php` (نحو 40 سطراً):
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` — عديم الأثر التكراري؛ `round()` ينطوي على خطر دقة مع الفاصلة العائمة، يجب المسار النصي: `bcadd($v, '0', $scale+1)` ثم الحكم HALF-UP حسب الرقم $scale+1 (انتبه لمعالجة الأعداد السالبة في التنفيذ، يكفي bccomp على القيمة المطلقة).
  - أي حقل مبلغ قبل الكتابة لقاعدة البيانات يجب أن يمر عبر `bcround(…, 4)`؛ **يُمنع** استخدام `(float)`/`round()` في منتصف سلسلة الحساب (الموجود `round((float) bcmul(...))` في StripeChannel خطر كامن).
- تعديل `calculateFee` الحالي إلى: `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — أولاً محاذاة amount إلى 4 منازل، ثم الضرب بالمعدل، ثم HALF_UP إلى 4 منازل. **تصحيح الاتجاه: أقل تحصيلاً → نصف تقريب قياسي** (الفرق لكل طلب ≤0.00005، والقيمة المتوقعة تتجه إلى 0). **إبقاء حماية تثبيت fee السالبة عند 0** (سلوك السطر 44 الحالي في PaymentRouter.php دون تغيير).

**D5: مطابقة هوية الطلب وفصل رسوم القناة (تسوية بلا انحراف).** حقيقتان مستقلتان:
- **مطابقة هوية بنود الطلب** `total − subtotal − tax + discount == 0` (دقيقة حتى 0.0000): سلسلة إنشاء الطلب (OrderService::createFromCart) بنودها `bcround(bcmul(price, qty, 8), 4)` (ضرب عالي الدقة أولاً ثم تقريب، لتجنب القص المزدوج) ← subtotal = مجموع البنود (دقيق) ← total = subtotal + tax − discount (جمع/طرح بنفس الدقة، دقيق). **tax حالياً ثابت 0** (createFromCart لا يضبط tax، install.sql:345 DEFAULT 0.0000) — لا إضافة حساب ضريبة (خارج نطاق P4.2 وله أثر امتثالي)، والتأكيد ينفذ حسب القيمة الحالية tax=0 لكن الصيغة تُبقي حد tax.
- **رسوم القناة**: channel_fee مستقل `bcround(…,4)`، ومبلغ قناة الدفع = total + channel_fee متساوٍ بدقة 4 منازل.
- التحقق: `PaymentController::reconcile*` والتقارير (Report) تستند إلى total المخزَّن في الطلب، بلا إعادة حساب.

**D6: لقطة سعر الصرف ونقاط التحويل.**
- مصدر سعر الصرف يبقى cron ExchangeRateSync + Redis (موجود، لا مساس). عمود `exchange_rate` يُلقط مع الطلب/المعاملة (DECIMAL(12,6))، **نقطة التحويل = عند التسوية (الكتابة لقاعدة البيانات)**، دون تحويل لحظي عند العرض (السعر اللحظي في العرض مجرد ضرب في معدل Redis الحالي بطبقة UI، لا يؤثر على الدفاتر).
- القاعدة: **كل ما يمس الدفاتر/الأرصدة يجب أن يستخدم معدل لقطة الطلب؛ وكل ما يخص التسعير/العرض يمكن أن يستخدم المعدل الحالي**. يُمنع خلط المعدلين في سلسلة التسوية.
- طبقة الأرصدة أصلاً دفتر يفصل العملات (user_balances صفوف حسب currency)، بلا تحويل لعملة أساسية موحدة؛ وعندما تتطلب التقارير عملة أساسية (مثل USD) تُجمَّع بمعدل لقطة الطلب، ونتيجة التجميع تمر أيضاً عبر `bcround(…,4)` (ponytail: خطأ تقريب التجميع عبر العملات في خانة الإجمالي، وعندما يطلب التدقيق لاحقاً إجماليات منفصلة لكل عملة نُقسّم).

**D7: قائمة التغييرات (بما فيها نقاط مراجعة الكود متعدد العملات القائم).**
- تعديل: `PaymentRouter::calculateFee`، `StripeChannel` (محاذاة معامل المبلغ + إزالة round الفاصلة العائمة، بما فيها convertToSmallest إلى bcround($total,2))، `OrderService::createFromCart` (ترتيب تقريب البنود/subtotal/total)، **`Order/Model/Coupon.php::calculateDiscount` (:31-44 حالياً float+round، تحويله لمسار bcround النصي)**، `PaymentController::reconcile*` (تأكيد مطابقة D5)، `Report/*` (التجميع الموحد عبر bcround).
- مراجعة دون تعديل: عدادات Billing (سعر الوحدة أصلاً DECIMAL(12,4)، والفوترة بمحاذاة bcround تكفي)، فحص التعليق (حكم أرصدة حسب العملة، صحيح أصلاً)، `Cron/ExchangeRateSync.php` (الكتابة إلى Redis تُبقي 6 منازل نصاً، لا مساس).
- إضافة: `service/common/money/Money.php` + اختبارات وحدة (حدود HALF_UP: 0.00005 ← 0.0001، 0.00004 ← 0.0000، **-0.00005 ← -0.0001 (السالبة تبتعد عن الصفر)**، عديمة الأثر التكراري).
- الترحيل: `install.sql` بلا تغييرات بنيوية (عمود exchange_rate موجود)؛ إذا أنتج اقتطاع fee التاريخي في الطلبات القديمة فروق ذيل <0.0001، فهي فروق دفاتر غير قابلة للعكس، **تُسجل فقط ولا تُصحح** (التصحيح يغير التسوية التاريخية)، وإضافة استعلام تدقيق `fee_drift` يسرد الطلبات ذات |total−subtotal−tax+discount|>0 للمراجعة اليدوية.

### القبول

```
# k6 (P4.1): IP واحد ثابت. GET /api/products و /graphql كل منها 200 طلب/10s:
#   عتبة قاعدة default = rate+burst = 70/نافذة 60s ← المتوقع 429 ≈ 200−70 = 130 (هامش حدود النافذة 1-2)
#   عتبة قاعدة graphql = 35 ← المتوقع 429 ≈ 165؛ كلها تحمل رأس Retry-After؛ حركة منخفضة كلها 200
# اختبارات وحدة (P4.2): حدود Money::bcround (0.00005→0.0001، 0.00004→0.0000، -0.00005→-0.0001، عديمة الأثر)
# اختبار المطابقة: إنشاء طلب متعدد البنود (يشمل سعر وحدة بخمسة منازل + قسيمة)، تأكيد total−subtotal−tax+discount == 0 ثابت
# انحدار: اختبارات service الحالية البالغة 491 كلها خضراء (بما فيها تأكيدات المبالغ)
```

---

## المخاطر والمراجعة

- **خطر المُقيّد العام D2 (متوسط)**: التثبيت العام يؤثر على جميع نقاط نهاية service (**لا admin** — حاوية مستقلة، تغييرات service/config لا تمسه)، والـ webhook معفى؛ العتبة غير المناسبة قد تُلحق أذى بالأعمال، يحتاج security-auditor لمراجعة العتبات الافتراضية وسياسة fail-open. **حاوية admin حالياً بلا تقييد تردد** (nginx-admin.conf بلا limit_req)، خارج P4.1، يُتخذ قراره بشكل منفصل.
- **سلسلة الأموال D4/D5 (عالية)**: تغيير اتجاه التقريب يؤثر على مبلغ كل طلب (أقل تحصيلاً ← نصف تقريب قياسي)، يحتاج تقييم security-auditor + مراجعة ثنائية؛ البيانات التاريخية تُسجل فقط ولا تُصحح.
- **التبعيات**: لا تبعيات composer جديدة؛ لا جداول جديدة؛ تغيير إعداد nginx يحتاج إعادة تحميل.

```yaml
design:
  objective: "P4.1 تقييد التردد الموحد ساري على كل المسارات (بما فيها graphql) + P4.2 محاذاة استراتيجية التقريب متعدد العملات، مطابقة الحسابات بلا انحراف"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + مطابقة الهوية)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # بلا تغييرات بنيوية؛ عمود exchange_rate موجود؛ tax يبقى 0 دون إضافة
  client_impact: ["flutter", "harmonyos"]  # 429 يحتاج معالجة رشيقة من العميل؛ حاوية admin غير متأثرة
  risk: "high"       # سلسلة الأموال D4/D5
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After على كل المسارات (k6 IP واحد، 429≈130/165)", "إغلاق فجوة تقييد graphql", "webhook المعفى لا يعطي 429", "دلالة OR للدلوين (تغيير token/تغيير IP لا يلتف)", "حدود fee HALF_UP تشمل السالبة", "Coupon bcround نصي", "مطابقة total−subtotal−tax+discount==0", "استعلام تدقيق fee_drift للطلبات التاريخية"]
  dependencies: []
```
