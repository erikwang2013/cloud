# منصة تداول الموارد السحابية العالمية — تصميم النظام

## نظرة عامة على المشروع

منصة تداول موارد سحابية موجهة للمستخدمين حول العالم، تدعم النمط المختلط (التشغيل الذاتي + موردين من طرف ثالث). يمكن للمستخدمين شراء الخوادم وعناوين IP والأقراص السحابية والنطاقات وغيرها من المنتجات السحابية. توفير موارد تلقائي بالكامل، قنوات دفع متعددة، عملات متعددة، ولغات متعددة.

### المكدس التقني

| الطبقة | التقنية |
|------|------|
| تطبيق المستخدم | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| لوحة الإدارة | webman-admin |
| الخادم | PHP webman (متجانس معياري) |
| قاعدة البيانات | MySQL 8.0 (رئيسي/تابع) |
| التخزين المؤقت/قوائم الانتظار | Redis (تخزين مؤقت + جلسات + قوائم انتظار) |
| التخزين | S3/OSS + CDN |
| المراقبة | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. تقسيم الوحدات (12 وحدة أساسية)
| الوحدة | المسؤولية |
|------|------|
| **User** | التسجيل/تسجيل الدخول (OAuth+البريد+الهاتف)، توثيق KYC، مستوى العضوية، حساب الرصيد |
| **Product** | تعريف المنتج (SKU)، تسعير متعدد المناطق، إدارة المخزون، التصنيفات، البحث، التقييمات |
| **Order** | سلة التسوق، تقديم الطلب، دورة حياة الطلب (معلق الدفع←مدفوع←قيد التوفير←مكتمل←مسترد)، التجديد/الترقية |
| **Payment** | توجيه قنوات الدفع، عرض أسعار متعدد العملات، أسعار الصرف، الاسترداد، التسوية |
| **Provisioning** | ربط واجهات مزودي السحابة، إنشاء/تجديد/إتلاف الموارد تلقائيًا |
| **Domain** | استعلام النطاقات، التسجيل، النقل، التجديد، إدارة DNS |
| **Supplier** | انضمام الموردين، الموافقة، إدراج المنتجات، التسوية، تقاسم الأرباح |
| **Monitor** | فحص استجابة الموارد، جمع الاستخدام، قواعد التنبيه |
| **Ticket** | تقديم التذاكر، التوزيع، تتبع SLA |
| **Notification** | بريد/رسائل SMS/دفع التطبيق/رسائل داخلية، قوالب متعددة ولغات متعددة |
| **Report** | تقارير الإيرادات، تقارير تسوية الموردين، اتجاهات المبيعات |
| **I18n** | مصطلحات متعددة اللغات، أسعار صرف متعددة العملات، مناطق زمنية متعددة |

---

## 2. نماذج البيانات الأساسية
### مركز المستخدمين (User)

- **users** — جدول المستخدمين الرئيسي (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — ملفات المستخدمين (user_id, avatar, nickname, country)
- **user_kyc** — التوثيق الحقيقي للهوية (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — حساب الرصيد (user_id, currency, balance, frozen_balance)
- **user_balance_log** — سجل تغييرات الرصيد (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — عناوين المستخدمين (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### مركز المنتجات (Product)

- **product_categories** — تصنيفات المنتجات (id, parent_id, name, icon, sort)
- **products** — جدول المنتجات الرئيسي (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — وحدات SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — تسعير المناطق (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — صور المنتجات (product_id, url, sort)
- **product_attributes** — الخصائص المخصصة (product_id, key, value)
- **product_reviews** — تقييمات المنتجات (user_id, product_id, order_id, rating, content)
- **regions** — جدول المناطق (id, name, continent, country, city, data_center, status)

### مركز الطلبات (Order)

- **carts** — سلة التسوق (user_id, sku_id, region_id, quantity, cycle)
- **orders** — جدول الطلبات الرئيسي (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — تفاصيل الطلب (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — الخط الزمني للطلب (order_id, status, operator, remark, created_at)
- **order_invoices** — الفواتير (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — طلبات الاسترداد (order_id, user_id, amount, reason, status, handled_by)

### مركز الدفع (Payment)

- **payment_channels** — إعدادات قنوات الدفع (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — سجلات المعاملات (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — جدول التسوية (date, channel_id, channel_total, system_total, diff, status)

### توفير الموارد (Provisioning)

- **resources** — جدول الموارد الرئيسي (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — تفاصيل الخوادم (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — تفاصيل عناوين IP (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — تفاصيل الأقراص السحابية (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — تفاصيل النطاقات (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — مهام التوفير (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — إعدادات واجهات مزودي السحابة (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### إدارة الموارد المادية (Host & IP Pool)

تستخدم الخوادم الفعلية ذاتية التشغيل Proxmox VE (الإصدار المجتمعي، مجاني) لإدارة الأجهزة الافتراضية، عبر REST API لإنشاء/إدارة VM وتخصيص عناوين IP وتركيب الأقراص.

- **host_machines** — الخوادم المضيفة (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — مجمعات IP (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — سجلات تخصيص IP (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — تفاصيل أقراص VM (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — سجلات توسعة الأقراص (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### الموردون (Supplier)

- **suppliers** — جدول الموردين الرئيسي (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — ربط منتجات الموردين (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — قسائم التسوية (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — سجلات السحب (supplier_id, amount, method, account_info, status)

### خدمة النطاقات (Domain)

- **domain_tlds** — نهايات TLD المدعومة (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — نقل النطاقات (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — مناطق DNS (domain_name, user_id, zone_id)
- **dns_records** — سجلات DNS (zone_id, type, name, value, ttl, priority)

### التذاكر والإشعارات (Ticket & Notification)

- **tickets** — التذاكر (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — رسائل التذاكر (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — سجلات الإشعارات (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — قوالب الإشعارات (code, name, channels, title_template, body_template, variables)

---

## 3. مواصفات تصميم الـ API
### إدارة الإصدارات

يُحدَّد إصدار الـ API عبر رأس طلب HTTP `X-Api-Version` وليس في مسار الـ URL. يحقن الخادم رأس الإصدار في التوجيه الداخلي عبر وسيط.

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**الإصدارات المدعومة**: `v1` (الافتراضي، يُستخدم تلقائيًا عند غياب رأس الطلب)

**آلية التحكم بالإصدارات**: يتحقق `VersionMiddleware` من رأس `X-Api-Version` على جميع مسارات `/api/*` و`/admin/api/*`، ويفترض `v1` عند غيابه، ويعيد `400` للإصدارات غير المدعومة. لم يعد مسار الـ URL يتضمن رقم الإصدار.

**خطوات إضافة إصدار جديد**:
1. إلحاق رقم الإصدار بمصفوفة `VersionMiddleware::SUPPORTED`
2. تسجيل مجموعة مسارات الإصدار الجديد في `route.php`
3. يقرأ المتحكم رقم الإصدار عبر `$request->properties['api_version']` للمعالجة التفاضلية

### توجيه RESTful

```
统一前缀: /api
管理后台: /admin/api
```

**مجموعات المسارات ومصفوفة الوسائط:**

| مجموعة المسارات | الوسائط | أمثلة النقاط |
|--------|--------|---------|
| عامة (بدون بادئة) | سلسلة الوسائط العامة | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | عامة + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (مستخدم) | عامة + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (حساس) | عامة + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | عامة + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (حساس) | عامة + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### تنسيق الاستجابات الموحد

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### مخطط المصادقة

| الطرف | الطريقة |
|----|------|
| المستخدم | JWT (access_token 2h + refresh_token 30d) + تحقق TOTP بخطوتين + رموز استرداد |
| الإدارة | JWT (access_token 2h + refresh_token 7d) |
| واجهة المورد | API Key (بادئة sk_، مخزن بتجزئة SHA256، يُعرض مرة واحدة عند الإنشاء فقط) |
| معاودة مزودي السحابة | التحقق من التوقيع (HMAC-SHA256) |

**ميزات المصادقة المنفذة**:
- التسجيل بالبريد + رابط تحقق البريد
- التسجيل برقم الهاتف + رمز تحقق Twilio SMS (فترة تبريد 60s + حد IP 5 مرات/ساعة)
- تسجيل دخول Google OAuth / Apple Sign In
- نسيت كلمة المرور (رمز تحقق البريد + مدة صلاحية Redis 10 دقائق)
- تحقق TOTP بخطوتين (إعداد عبر مسح رمز QR، رموز استرداد احتياطية)
- إدارة الجلسات النشطة (عرض/إبطال أجهزة تسجيل الدخول، بما في ذلك معلومات client_platform)
- حذف الحساب وفق GDPR (تأكيد كلمة المرور + حذف ناعم + إبطال جميع الرموز)
- تنبيه تسجيل الدخول غير العادي (إشعار بريد عند تسجيل الدخول من IP جديد)
- قفل تسجيل الدخول (5 محاولات فاشلة تقفل 15 دقيقة)

**تدفقات مصادقة المستخدم:**

```
注册流程                             登录流程
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(点击位置)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 扫描]                         → [WAF 扫描]
   → [限流: 3 req/min]                  → [限流: 5 req/min]
   → [密码 bcrypt(cost=12)]             → [Hash::check()]
   → [设备指纹: sha256(UA+IP)]           → [设备指纹: sha256(UA+IP)]
   → [client_platform 记录]              → [client_platform 记录]
   → User::create()                    → [失败 5 次 → 锁 15min]
   → RefreshToken::create()            → [新 IP 检测 → 邮件告警]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (验证邮件)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 授权 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. 验证 Google token
                                      5. 新建或查找用户
                                      6. 签发 token（含 client_platform）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 两步验证                          会话管理
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 成功
   或 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 密码确认 + 软删除 + 全部 token 撤销
登录锁定机制
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### مخطط تعدد اللغات

- رأس الطلب: Accept-Language: zh-CN / en-US / ja-JP
- تخزين النصوص متعددة اللغات في أعمدة JSON: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- تدير ملفات i18n النصوص الثابتة، مجموعة للواجهة الأمامية وأخرى للخلفية

---

## 4. نظام الحماية الأمنية
### نموذج الحماية الطبقية

```
┌─────────────────────────────────────────────────────┐
│ 第一层: 网络边界防护                                    │
│   DDoS清洗 / WAF / IP黑白名单 / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ 第二层: 传输与应用防护                                  │
│   HTTPS+TLS1.3 / CSP / CORS / JWT鉴权 / 限流          │
├─────────────────────────────────────────────────────┤
│ 第三层: 数据与存储安全                                  │
│   加密存储 / 脱敏 / 审计日志 / 备份                     │
├─────────────────────────────────────────────────────┤
│ 第四层: 虚拟化与资源隔离                                 │
│   Proxmox安全加固 / VM间隔离 / 网络隔离                 │
├─────────────────────────────────────────────────────┤
│ 第五层: 运营与风控                                     │
│   操作审计 / 异常检测 / 告警 / 应急响应                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 حماية حدود الشبكة

#### حماية DDoS

```
用户请求 → CDN (Cloudflare / 阿里云CDN)
              │
              ├── JS质询 / 验证码 (可疑流量)
              ├── 速率限制 (每IP每秒请求数)
              ├── 区域封禁 (阻断指定国家/地区)
              │
              ▼
          源站 (Nginx + webman)
```

| الطبقة | الإجراء | الوصف |
|------|------|------|
| طبقة CDN | تنقية DDoS تلقائية | خطة Cloudflare المجانية تدعم حماية L3/L4 |
| طبقة CDN | إدارة الروبوتات | التعرف على برامج الزحف الخبيثة/سكربتات التلاعب واعتراضها |
| طبقة Nginx | limit_req_zone | 10 طلبات/ثانية لكل IP، تتجاوز الحد تعيد 429 |
| طبقة Nginx | limit_conn | حد أقصى 20 اتصالًا متزامنًا لكل IP |
| طبقة webman | وسيط حد معدل بدلو الرموز | حد معدل دقيق على مستوى المستخدم/الواجهة |

#### قواعد WAF (وسيط webman)

يفحص وسيط WAF الطلبات عبر 8 فئات من مجموعات القواعد المعتادة، والإعدادات في `config/security.php` تُحدَّث ساخنة دون إعادة تشغيل. يغطي الفحص جسم طلب JSON ومسار الـ URL + سلسلة الاستعلام وUser-Agent والجسم الخام (لمنع هروب ترميز JSON).

**8 فئات من قواعد الكشف (أكثر من 45 قاعدة):**

| الفئة | التغطية |
|------|---------|
| حقن SQL | علامات الاقتباس المفردة/رموز التعليق، كلمات SQL المفتاحية، الترميز السداسي، تشوهات الاستعلام الموحد، الشروط الصحيحة دائمًا(`' OR '1'='1`)، الحقن الزمني الأعمى(`sleep`/`benchmark`)، الاستعلامات المكدسة، تجاوز التعليقات متعددة الأسطر |
| XSS | وسوم HTML (بما في ذلك التشوهات المشفرة)، وسم Script ومتغيراته، 13 معالج أحداث JS، الكائنات العامة/الدوال الخطيرة في JS، البروتوكول الزائف `javascript:`، ترميز كيانات HTML، حقن Data URI، سمات الأحداث المضمّنة |
| حقن الأوامر | رمز الأنبوب متبوعًا بأمر(`\| cat`)، فاصلة منقوطة متبوعة بأمر(`; whoami`)، استبدال الأوامر `$(cmd)` وبعلامات الاقتباس الخلفية، كلمات أوامر مستقلة |
| تضمين الملفات | اجتياز المسار (ترميزات متعددة)، البروتوكولات الزائفة PHP(`php://`/`data://`/`phar://`)، استكشاف المسارات المطلقة(`/etc/`/`C:\`)، حقن Null byte |
| حقن ترويسات HTTP | حقن أسطر CRLF(`%0d%0a`/`\r\n`)، حقن ترويسات Host/Cookie/Set-Cookie |
| **SSRF** | عناوين IPv4 للشبكات الداخلية(127.x/10.x/172.16-31.x/192.168.x)، أسماء مستعارة لـ localhost، نقاط نهاية metadata السحابية(169.254.169.254)، بروتوكول file:// |
| **حقن NoSQL** | عوامل تشغيل MongoDB($where/$gt/$regex/$or وغيرها)، حقن JS عبر $where، أوامر Redis الخطيرة(FLUSHALL/CONFIG SET/SHUTDOWN) |
| **إعادة التوجيه المفتوحة** | كشف العناوين الخارجية في معاملات redirect_uri/return_url/next/callback وغيرها، تجاوز الترميز المزدوج |

**حماية على مستوى الطلب:**

| بند الحماية | الإجراء |
|--------|------|
| حد حجم جسم الطلب | 10MB كحد أقصى (تجاوز يعيد 413) |
| حد طول الـ URL | 2KB كحد أقصى (تجاوز يعيد 414، لمنع ReDoS) |
| قائمة Content-Type البيضاء | فقط application/json وmultipart/form-data وapplication/x-www-form-urlencoded |

**تدفق كشف WAF:**

```
请求进入
  │
  ▼
1. 获取待扫描文本
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 请求体
   │     └── false → serialize() 回退
   ├── mb_substr(path + queryString, 0, 2048)                # URL（防 ReDoS 截断）
   ├── User-Agent 头                                          # UA
   └── file_get_contents('php://input')                      # 原始体（防 JSON 编码逃逸）
  │
  ▼
2. 加载规则（从 config/security.php）
   ├── security.waf.sqli_patterns               (9 条)
   ├── security.waf.xss_patterns                (8 条)
   ├── security.waf.cmd_injection_patterns      (5 条)
   ├── security.waf.file_inclusion_patterns     (4 条)
   ├── security.waf.header_injection_patterns   (2 条)
   ├── security.waf.ssrf_patterns               (6 条)
   ├── security.waf.nosql_injection_patterns    (3 条)
   └── security.waf.open_redirect_patterns      (2 条)
   → array_merge() + array_unique()
  │
  ▼
3. 逐条匹配
   foreach patterns as pattern:
     match($pattern, $input) ───→ 命中 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 命中 → 返回 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ 命中 →
     match($pattern, $raw)   ───→ 命中 →
  │
  ▼
4. match() 严格检查
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 命中 ✓
   ├── $result === 0    → 未命中（安全放行）
   └── $result === false → 模式错误 → error_log() → 作为未命中处理
  │
  ▼
5. 全部未命中 → $next($request) 放行到下一中间件
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### القوائم البيضاء/السوداء لعناوين IP

```
黑名单:
- 已知恶意 IP 库 (定期同步 AbuseIPDB)
- 频繁触发 WAF 规则的 IP (自动加入，Redis TTL 24h)
- 暴力破解登录的 IP (5次失败 → 锁定 30min)

白名单:
- Proxmox 宿主机 IP
- 云厂商回调 IP 段
- 支付网关 webhook IP 段
- 管理员办公网络 IP (可选)
```

#### الحجب الجغرافي

```php
// GeoIP2 库 (MaxMind)
$country = geoip($request->getRealIp());

// 可配置的阻断列表
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 أمان النقل والتطبيق

#### سلسلة تنفيذ الوسائط العامة

تمر جميع طلبات HTTP عبر الوسائط بالترتيب التالي، وكل وسيط قابل للاختبار بشكل مستقل:

```
请求 → VersionMiddleware        # X-Api-Version 校验（缺失默认 v1，无效返回 400）
     → CorsMiddleware            # CORS 跨域响应头
     → ClientPlatformMiddleware  # X-Client-Platform 识别（8 种平台），注入 $request->properties
     → WafMiddleware             # 8 类 45+ 规则安全扫描（SQLi/XSS/命令注入/文件包含/头注入/SSRF/NoSQL/开放重定向）
     → LocaleMiddleware          # Accept-Language 解析，设置区域
     → HashidRequestMiddleware   # 请求参数 hashid → 真实 ID 解码
     → MaintenanceMiddleware     # 维护模式（IP 白名单放行）
     ↓
  [路由中间件—按路由组附加]
     → EncryptionMiddleware      # AES-256-GCM 请求/响应体加密
     → Captcha                   # 点击验证码校验（登录/注册前）
     → AuthMiddleware            # JWT Bearer Token 验证 + 角色注入
     → AdminRoleMiddleware       # 管理员 RBAC 权限检查
     → ConfirmationMiddleware    # 敏感操作二次密码确认（5 次失败锁 15min）
     ↓
     控制器
```

#### مسؤوليات كل وسيط

| الوسيط | طريقة التسجيل | المسؤولية |
|--------|---------|------|
| `VersionMiddleware` | عام | التحقق من رأس `X-Api-Version`، يفترض `v1` عند غيابه، ويعيد `400` للإصدارات غير المدعومة |
| `CorsMiddleware` | عام | معالجة فحص OPTIONS المسبق، يعكس Origin إلى `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | عام | التحقق من رأس `X-Client-Platform`، يتعرف على منصة نظام تشغيل العميل (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)، ويحقنه في `$request->properties['client_platform']` |
| `WafMiddleware` | عام (service) + نسخة admin | 8 فئات من أكثر من 45 قاعدة + حد حجم الطلب + التحقق من Content-Type، تسجيل سجل تدقيق عند الاعتراض |
| `LocaleMiddleware` | عام | تحليل رأس `Accept-Language`، تعيين منطقة تعدد اللغات |
| `HashidRequestMiddleware` | عام | فك تشفير سلاسل hashid في الطلبات تلقائيًا إلى معرّفات صحيحة حقيقية |
| `MaintenanceMiddleware` | عام | فحص متغير بيئة `MAINTENANCE_MODE`، وتمرير IPs القائمة البيضاء |
| `EncryptionMiddleware` | مجموعة مسارات (/api/auth, /api, /admin/api) | تشفير جسم الطلب/الاستجابة AES-256-GCM، يُفعَّل برأس `X-Encrypted: 1` |
| `AuthMiddleware` | مجموعة مسارات (/api, /admin/api) | التحقق من JWT HS256 access_token، حقن `$request->userId` و`$request->userRole` |
| `AdminRoleMiddleware` | مجموعة مسارات (/admin/api) | فحص صلاحيات RBAC للمشرف |
| `ConfirmationMiddleware` | مجموعة مسارات (العمليات الحساسة) | تأكيد كلمة المرور ثانيًا، عداد فشل Redis، 5 محاولات تقفل 15 دقيقة |

#### تفاصيل وسيط ClientPlatform

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**تدفق البيانات**: حقن الوسيط → تسجيل تلقائي في `AuditLogger` → كتابة `AuthService::issueTokens()` في `refresh_tokens` → إرجاع `GET /api/user/sessions` لمعلومات المنصة

#### فرض HTTPS

```nginx
# Nginx 配置
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### تقوية أمان JWT

```
- access_token 有效期 2h，refresh_token 有效期 30d
- 密钥使用 RSA256 (非对称)，定期轮换 (90天)
- jti (JWT ID) 存入 Redis 实现主动吊销
- refresh_token 绑定设备指纹 (User-Agent + IP 段)
- 换发 refresh_token 时旧 token 立即失效 (rotation)
- 敏感操作 (支付/销毁资源) 需二次验证

设备指纹:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 表记录此指纹，换发时校验
```

#### سياسة كلمات المرور

```
- bcrypt 加密，cost factor = 12
- 最小 8 字符，必须包含大小写字母 + 数字
- 注册/登录连续失败 5 次 → 账号锁定 15 分钟
- 密码修改后，所有已签发 token 立即失效
- 支持 TOTP 两步验证 (用户可选开启)
```

#### سياسة CORS

```php
// webman 中间件
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### أمان رفع الملفات

```
- 白名单校验扩展名 (仅允许: jpg, jpeg, png, pdf, gif)
- 校验文件 MIME 类型 (不允许伪造 Content-Type)
- 文件大小限制: 头像 2MB, KYC 证件 5MB, 附件 10MB
- 上传后重命名: {uuid}.{ext}, 不保留原始文件名
- 图片二次处理: GD/Imagick 去除 EXIF + 元数据
- 存储路径在 web 不可访问目录, 通过 PHP 代理读取
- 病毒扫描: ClamAV (KYC 证件/用户上传文件)
```

---

### 4.3 أمان البيانات والتخزين

#### تشفير البيانات الحساسة

```
加密算法: AES-256-GCM (带认证的加密，防篡改)
密钥管理: 主密钥存于环境变量，每个字段使用独立派生密钥

需要加密存储的字段:
| 数据类型 | 字段 | 加密方式 |
|----------|------|----------|
| 密码 | users.password_hash | bcrypt (单向) |
| 支付密钥 | payment_channels.api_key | AES-256-GCM |
| 云厂商密钥 | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 证件号 | user_kyc.id_number | AES-256-GCM |
| 支付账号 | 提现账号 | AES-256-GCM |
| 登录密码(VNC) | resource_servers.login_password | AES-256-GCM |

密钥派生:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### إخفاء بيانات السجلات

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### أمان قاعدة البيانات

```
- MySQL 使用 prepared statement (Eloquent 自动处理)
- 数据库访问账号最小权限原则:
  - app_user: SELECT, INSERT, UPDATE, DELETE (无 DDL)
  - migration_user: DDL 权限 (仅迁移时使用，IP 限制)
  - read_user: SELECT 只读 (报表/数据分析使用)
- 连接使用 SSL/TLS (PHP PDO SSL options)
- 数据库端口不对公网开放 (仅内网可访问)
- 定期备份: 全量备份 1天, binlog 实时同步
```

#### النسخ الاحتياطي والاستعادة

```
备份策略:
- MySQL: 每日全量 + binlog 实时增量
- Redis: RDB 每小时 + AOF 实时持久化
- 用户上传文件: S3/OSS 自动多副本 + 跨区域复制
- Proxmox VM 快照: 每周一次 (保留 4 周)
- 备份加密: AES-256 加密后存储

恢复演练:
- 每季度执行一次灾难恢复演练
- 恢复时间目标 (RTO): < 4 小时
- 恢复点目标 (RPO): < 1 小时
```

---

### 4.4 العزل الافتراضي والموارد

#### تقوية أمان Proxmox

```
1. API 访问控制:
   - Proxmox API 仅监听内网 IP (不绑定公网)
   - Token 权限最小化: 每个 role 仅授予必要权限
   - API 端口 (8006) 仅允许 PHP 应用服务器 IP 访问 (iptables)

2. SSH 加固:
   - 禁用密码登录，仅允许密钥认证
   - 禁用 root 登录，使用专用管理账户
   - SSH 端口改为非标准端口 (减少扫描)
   - Fail2ban: 5 次失败锁定 1 小时

3. 系统更新:
   - Proxmox 订阅安全更新邮件列表
   - 定期 apt update && apt upgrade
   - 内核 livepatch (Canonical Livepatch Service)

4. 防火墙 (iptables/nftables):
   - 默认拒绝所有入站
   - 仅开放: 8006 (仅应用服务器IP), SSH端口 (仅管理IP)
   - VM 网桥与宿主机管理网络的隔离
```

#### العزل بين أجهزة VM

```
- 每个 VM 使用独立的虚拟网桥 VLAN
- 禁止 VM 间通信 (Proxmox 防火墙规则 + VLAN 隔离)
- 用户仅能通过公网 IP 访问自己的 VM
- VM 资源限制 (cgroup): 防止单个 VM 耗尽宿主机资源
  - CPU limit: 购买的核数上限
  - RAM limit: 购买的容量上限
  - Disk IOPS limit: 防止磁盘争用
  - Network bandwidth limit: 购买的带宽上限
```

#### أمان تخصيص IP

```
- IP 分配记录完整审计 (谁、何时、分配了什么 IP)
- IP 释放后冷却期 24h (防止 IP 被立即分配给其他人导致的误用)
- IP 黑名单: 被投诉/滥用的 IP 标记为不可分配
- IP 使用监控: 定期检查分配的 IP 是否正常使用中
```

---

### 4.5 أمان الدفع

```
1. PCI DSS 合规:
   - 信用卡数据不经过自有服务器 (Stripe Elements / Checkout)
   - card_token 由 Stripe 前端直接生成，后端仅接收 token
   - 不在日志/数据库中存储任何 CVV/完整卡号

2. 加密货币:
   - 收款私钥冷存储 (离线签名)
   - 热钱包仅保留日常周转额度
   - 收款地址生成后验证校验和
   - 大额交易 ( > $10000) 人工审核后手动确认

3. 支付防欺诈:
   - 同一用户/IP 短时间内高频支付 → 风控冻结
   - 新注册用户大额支付 → 人工审核
   - 支付金额异常 (与商品价格不匹配) → 阻断
   - 退款率过高的用户 → 标记风控

4. 回调验签:
   - Stripe: 验证 webhook signature (stripe-signature header)
   - Coinbase: 验证 webhook signature (X-CC-Webhook-Signature header)
   - 支付宝: 验证 notify_id 回调支付宝服务器二次确认
   - 所有回调: 验证 IP 是否为已知支付网关 IP 段
```

#### أمان الاسترداد

```
- 退款必须经过二级审批 (客服发起 → 管理员确认)
- 退款前校验: 订单状态、退款时限、退款次数
- 退款金额不能超过原订单实付金额
- 原路退回: 支付通道退款接口 + 余额退回
- 退款互斥锁 (Redis): 防止并发重复退款
```

---

### 4.6 التحكم في الوصول والصلاحيات

#### نموذج RBAC

```
角色层级:
  super_admin    (超级管理员 — 全部权限)
  admin          (管理员 — 除系统配置外全部)
  finance        (财务 — 支付/对账/退款/结算)
  support        (客服 — 用户/订单/工单管理)
  supplier       (供应商 — 自己的商品/订单/结算)
  user           (普通用户 — 自己的资源/订单/工单)

权限定义:
  {module}.{action}
  例: order.view, order.create, order.refund, resource.destroy

权限检查中间件:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### حد معدل الـ API

```php
// webman 限流中间件 (Redis 令牌桶)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### عزل بيانات الموردين

```
数据隔离原则:
- 供应商只能查询和操作自己的资源
- 所有涉及 supplier_id 的查询自动追加 WHERE supplier_id = auth()->supplier_id

实现方式:
  // 全局 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // 在 Product/Order 等 Model 上注册
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 تدقيق العمليات

```
审计日志记录内容:
- 操作者 ID、IP、User-Agent
- 操作时间
- 操作模块 (哪个菜单/接口)
- 操作类型: 创建/修改/删除/导出/审批
- 操作对象: 哪个资源的哪个字段
- 操作前值 / 操作后值 (字段级变更)
- 操作结果: 成功/失败
- 请求 ID (全链路追踪)

记录范围:
- 所有管理端操作 (100% 记录)
- 用户端敏感操作: 支付/销毁资源/KYC提交/修改密码 (100% 记录)
- 登录/登出 (100% 记录)
- API Key 创建/撤销 (100% 记录)

存储与保留:
- 审计日志写入独立数据库 (audit_db)，与应用库分离
- 至少保留 1 年，金融相关保留 3 年
- 支持导出为 CSV/JSON 供合规审查

审计日志中间件:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 قواعد مراقبة المخاطر

```
实时风控引擎:

规则 1: 新账号异常行为
  条件: 注册时间 < 24h AND (支付总额 > $500 OR 创建工单 > 5)
  动作: 标记账号为"观察中"，通知风控管理员

规则 2: 批量注册检测
  条件: 同一 IP 24h 内注册 > 3 个账号
  动作: 拒绝新注册，冻结该 IP 下新账号

规则 3: 支付异常
  条件: 同一用户 1h 内支付失败 > 5 次
  动作: 冻结支付功能 2h，生成风控工单

规则 4: 退款滥用
  条件: 同一用户 30 天内退款 > 3 笔 OR 退款率 > 20%
  动作: 限制该账号退款权限，新订单标记风控审查

规则 5: API 滥用
  条件: 单 token 1h 内 API 调用 > 10000 次
  动作: 该 token 降级 (降低限流阈值)，通知管理员

规则 6: 资源滥用
  条件: VM 被投诉 spam/DDoS/挖矿 (接收 Abuse 通知)
  动作: 自动关机，冻结资源，生成高优先级工单

风控动作:
- 标记 (flag): 仅记录，不影响使用
- 降级 (throttle): 降低限流阈值
- 冻结 (freeze): 暂时禁用特定功能
- 封禁 (ban): 账号永久封禁
```

---

### 4.9 الاستجابة للطوارئ

```
安全事件分级:

P0 (紧急) — 数据泄露、资金损失、平台宕机
  → 立即通知 CTO + 安全团队
  → 30 分钟内启动应急响应
  → 下线上游受影响服务，保留证据
  → 修复后 24h 内发布事件报告

P1 (严重) — 单账号被盗、支付欺诈、WAF 触发异常上升
  → 通知安全负责人
  → 2h 内处理
  → 冻结受影响账号/资源

P2 (一般) — 漏洞扫描发现中低危漏洞、异常登录告警
  → 录入工单系统
  → 下一个迭代修复

应急联系:
- 触发 P0/P1 告警后自动通知 (邮件 + 短信 + 电话)
- webman 健康检查端点: GET /health (返回 200 或告警)
- 值班表: 7×24 轮值，至少 2 人备岗
```

---

## 5. محرك توفير الموارد
### بنية إضافات Provider

كل مجموعة من (نوع منتج السحابة × مزود السحابة) تنفذ واجهة موحدة:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

يوّجه ProviderFactory التنفيذ حسب (product_type, provider):
- ProxmoxProvider (الآلات الفعلية ذاتية التشغيل: الخوادم/الأقراص البيانات/IP)
- AwsServerProvider / AliyunServerProvider (خوادم سحابية من طرف ثالث)
- GcpIpProvider (IP من طرف ثالث)
- AzureDiskProvider (أقراص سحابية من طرف ثالث)
- NamecheapDomainProvider / GoDaddyDomainProvider (النطاقات)

### ضمان المهام غير المتزامنة

- يستقصي عامل Provisioning جدول provision_tasks
- التحكم في التزامن حسب provider (بحد أقصى 5 عمليات متزامنة لكل provider)
- استراتيجية إعادة المحاولة: 1min → 5min → 15min → 1h → 6h → 24h (بحد أقصى 6 مرات)
- الفشل غير القابل لإعادة المحاولة → تنبيه + إنشاء تذكرة تلقائيًا

### السلسلة الكاملة من الطلب إلى توفير الموارد

```
用户下单                               支付                             资源开通
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 事件
   → addToCart(sku, region, qty)          → 密码二次确认 (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          选择支付通道                   10. 每个 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                                                             status=pending
                                        6. StripeChannel::
                                           createPaymentIntent()            → Stripe API
3. 应用优惠券                               → Stripe API                   11. Redis Queue Worker
   POST /coupons/validate                   ← {client_secret}                → ProviderFactory
   → validate('CODE', order_total)                                              .create(task)
   ← {discount, coupon_id}                7. 前端 confirmCardPayment()
                                        8. Stripe webhook 回调            12. Provider->create()
4. GET /orders/{id}/payment-methods         → 验签 + 幂等检查                  ├→ HostSelector::select()
   → 获取可用支付通道                         → transaction=success              ├→ ProxmoxApi::create()
   ← [{channel, fee, total}]               → 触发 OrderPaid 事件               │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                        重试策略 (失败时)                      │  startVM()
                                        ────────────────                      ├→ 创建 Resource 记录
                                        1min → 5min → 15min                 └→ 更新 host_machine
                                        → 1h → 6h → 24h                          已分配资源量
                                        (6 次后标记失败 + 告警)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        退款流程                                ::send('resource_ready')
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### حل الآلات الفعلية ذاتية التشغيل: Proxmox VE (الإصدار المجتمعي)

تعتمد الخوادم ذاتية التشغيل على Proxmox VE (مفتوح المصدر ومجاني، AGPL v3)، ويستدعي PHP عبر HTTP واجهة Proxmox REST API لإدارة دورة حياة أجهزة KVM الافتراضية وتخصيص الموارد.

البنية:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### تغليف عميل ProxmoxApi

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### عمليات الموارد

**إنشاء VM (خادم):**
1. يختار HostSelector مضيفًا بموارد كافية (مرتب حسب رصيد cpu/ram/disk + موازنة الحمل)
2. تخصيص IP من ip_pool الخاص بذلك المضيف
3. ProxmoxApi.post("/nodes/{node}/qemu") لإنشاء VM (تعيين vmid وname وcores وmemory وnet0 وipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") لتركيب قرص النظام (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") لبدء تشغيل VM
6. تحديث الكميات المخصصة في host_machine.specs (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**ترقية CPU (أثناء التشغيل):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**ترقية الذاكرة (أثناء التشغيل):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**توسعة قرص النظام:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**إنشاء قرص بيانات منفصل:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**إنشاء IP منفصل:**
التخصيص من مجمع IP → إضافة بطاقة شبكة افتراضية عبر واجهة Proxmox + تكوين IP، أو الاحتفاظ به كمورد مستقل يُخصص كبطاقة إضافية لـ VM موجود.

**إتلاف VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### استراتيجية اختيار المضيف

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### ملخص عمليات تفكيك الموارد

| العملية | طريقة التنفيذ | عملية ساخنة |
|------|----------|--------|
| إنشاء VM (CPU+ذاكرة+قرص نظام+IP) | Proxmox create qemu | — |
| ترقية CPU منفصلة | PUT config cores | أثناء التشغيل |
| ترقية ذاكرة منفصلة | PUT config memory | أثناء التشغيل |
| توسعة قرص النظام | PUT resize disk | أثناء التشغيل (يتطلب دعم VM) |
| إنشاء قرص بيانات منفصل | POST config إضافة قرص | أثناء التشغيل |
| إنشاء IP منفصل | تخصيص من مجمع IP + إضافة بطاقة شبكة لـ VM | أثناء التشغيل |

### دورة حياة الموارد

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

التجديد: active → (renew) → active (تمديد expired_at)
الترقية: active → (upgrade) → upgrading → active

### مصادر الموارد

| المصدر | الافتراضية/الواجهة | أنواع المنتجات | الوصف |
|------|-----------|----------|------|
| آلات فعلية ذاتية التشغيل | Proxmox VE (الإصدار المجتمعي) | خوادم، أقراص بيانات، IP | استضافة في مركز بيانات خاص، PHP يستدعي واجهة Proxmox |
| مزودو سحابة من طرف ثالث | AWS/GCP/علي سحابة/Huawei Cloud/Azure SDK | خوادم، IP، أقراص سحابية | إعادة بيع موارد سحابية من طرف ثالث |
| مسجلات النطاقات | Namecheap/GoDaddy/واجهة علي سحابة Wanwang | تسجيل/نقل النطاقات | خدمة النطاقات |

### الربط في المرحلة الأولى

| المنطقة | الخوادم | IP | الأقراص السحابية | النطاقات |
|------|--------|----|------|------|
| آسيا والمحيط الهادئ | علي سحابة، Huawei Cloud، AWS | علي سحابة، GCP | علي سحابة، Huawei Cloud | علي سحابة Wanwang، Namecheap |
| أوروبا | AWS، GCP، Hetzner | GCP، OVH | AWS، GCP | Namecheap، Gandi |
| أمريكا الشمالية | AWS، GCP، Azure | AWS، GCP | AWS، Azure | GoDaddy، Namecheap |

---

## 6. نظام الدفع
### توجيه القنوات المتعددة

يستعلم PaymentRouter عن القنوات المتاحة حسب تفضيل عملة المستخدم، ويحسب المبلغ الفعلي المدفوع لكل قناة (شامل رسوم القناة)، ويعيد قائمة خيارات الدفع.

### تدفق الدفع (Stripe)

```
用户端 (Flutter)               服务端 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. 选择 Stripe 支付
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. 创建 payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 用户确认支付
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  验签 (stripe-signature)
                                  幂等检查 (transaction_no)
                               8. 更新 transaction=success
                               9. 触发 OrderPaid 事件
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 支付成功页面               ← 返回订单状态
```

### الدفع بالعملات المشفرة

1. يختار المستخدم العملة (مثل USDT-TRC20)
2. يولد الخادم عنوان استلام عبر واجهة Coinbase Commerce / BitPay
3. يستعلم Worker عن تأكيدات سلسلة الكتل كل 30 ثانية (أو عبر webhook)
4. تأكيد الوصول → تشغيل حدث OrderPaid

### أسعار الصرف وتعدد العملات

- يُسحب مصدر أسعار الصرف دوريًا من exchangerate-api ويُخزن في Redis
- تسعير المنتجات على أساس USD، والتحويل الفوري للعملات الأخرى
- تثبيت سعر الصرف عند تقديم الطلب، والاسترداد بنفس السعر الأصلي

### التحكم في ظهور قنوات الدفع

حقول جدول payment_channels:
- is_visible: هل تُعرض لطرف المستخدم
- visible_regions: تقييد المناطق الظاهرة، فارغة تعني الكل
- min_amount / max_amount: قيود نطاق مبلغ الطلب

### التسوية

كل فجر يُسحب تقرير التسوية لكل قناة، ويُطابق مع معاملات النظام معاملة بمعاملة، مع تنبيه عند اختلاف > $0.01.

### سياسة الاسترداد

- الخوادم/VPS: استرداد كامل خلال 72 ساعة من الشراء
- النطاقات: قابلة للاسترداد خلال 5 أيام من التسجيل (وفق معايير ICANN)
- IP: غير قابل للاسترداد بعد الشراء
- الأقراص السحابية: نفس سياسة الخوادم
- المنتجات الترويجية الخاصة: غير قابلة للاسترداد

تدفق الاسترداد: طلب المستخدم → إنشاء Ticket → مراجعة الدعم → تأكيد admin → provider.destroy() → payment.refund() → الإرجاع عبر نفس الطريق

---

## 7. بنية صفحات العملاء
### عميل Flutter / HarmonyOS

- **المصادقة**: تسجيل الدخول/التسجيل (بريد+كلمة مرور، Google OAuth، Apple ID، هاتف)، نسيت كلمة المرور، التحقق بخطوتين
- **الرئيسية**: محدد المنطقة، مداخل تصنيفات المنتجات، Banner/عروض، منتجات موصى بها
- **المنتجات**: قائمة (فلترة متعددة الشروط)، تفاصيل (الإعداد/المنطقة/حاسبة الأسعار)، التقييمات
- **التسوق والدفع**: سلة التسوق، تأكيد الطلب (طريقة الدفع/عنوان الفوترة/الرصيد/رمز الخصم)، الخروج، نتيجة الدفع
- **مواردي**: قائمة الموارد (فلترة حسب الحالة)، عمليات التفاصيل (إعادة تشغيل/إيقاف/تجديد/ترقية/إتلاف)، SSO لوحدة التحكم، رسوم بيانية للاستخدام
- **الطلبات**: قائمة (معلق/مدفوع/مكتمل/مسترد)، تفاصيل، فواتير
- **التذاكر**: قائمة، إنشاء، محادثة
- **المركز الشخصي**: الملف الشخصي/KYC، الرصيد والشحن، الإشعارات، إدارة العناوين، إعدادات اللغة/العملة/الأمان
- **العامة**: مركز المساعدة، شروط الخدمة، حول

### لوحة إدارة webman-admin

- **لوحة المعلومات**: نظرة عامة + رسوم بيانية للاتجاهات
- **إدارة المستخدمين**: قائمة/تفاصيل/مراجعة KYC
- **إدارة المنتجات**: تصنيفات/قائمة/تسعير(SKU×المنطقة)/مخزون/تقييمات
- **إدارة الطلبات**: قائمة/تفاصيل/مراجعة استرداد/فواتير
- **إدارة الدفع**: إعداد القنوات/سجلات المعاملات/تقارير التسوية
- **إدارة الموارد**: قائمة/مراقبة مهام التوفير/إعداد واجهات مزودي السحابة
- **إدارة الموردين**: مراجعة الانضمام/قائمة/تخصيص المنتجات/تسوية/سحب
- **إدارة التذاكر**: قائمة الانتظار/تذاكري/مراقبة SLA
- **إدارة النطاقات**: تسعير TLD/واجهات المسجلين/إدارة النقل
- **رسائل الإشعارات**: إدارة القوالب/سجلات الإرسال
- **إعدادات النظام**: المشرفون والأدوار/سجلات العمليات/تعدد اللغات/أسعار الصرف/المناطق/معاملات النظام
- **التقارير**: الإيرادات/تسوية الموردين/تحليل مبيعات المنتجات/تحليل المناطق

---

## 8. نظام الإشعارات
### أربع قنوات

Email (SMTP/SendGrid) / SMS (Twilio/رسائل علي) / Push (FCM/HMS) / رسائل داخلية

### التدفق

تشغيل الحدث → Notification Dispatcher → مطابقة القالب (رمز الحدث + تفضيل اللغة) → التوزيع على القنوات حسب تفضيل المستخدم → إرسال غير متزامن عبر قائمة انتظار Redis

### أنواع الإشعارات

رمز تحقق التسجيل، نجاح دفع الطلب، اكتمال توفير المورد، تذكير انتهاء المورد (7d/3d/1d)، رد التذكرة، اكتمال الاسترداد، تنبيه أمني، أنشطة ترويجية

### إعادة المحاولة عند الفشل

3 محاولات بتراجع أسي، تُدار عبر webman redis-queue.

---

## 9. نظام الموردين
### عملية الانضمام

التسجيل → تقديم معلومات الشركة + جهة الاتصال + طريقة التسوية → مراجعة المشرف → بعد الموافقة إدراج المنتجات → مراجعة المشرف للمنتج → شراء المستخدم → التقسيم التلقائي للأرباح → طلب المورد للسحب → دفع المشرف

### عزل الصلاحيات

يمكن للمورد رؤية منتجاته/طلباته/تسوياته/تذاكره/سجلات سحوبه فقط. لا يمكنه رؤية إيرادات المنصة، أو بيانات الموردين الآخرين، أو إعدادات قنوات الدفع.

### قواعد تقسيم الأرباح

- المنتجات ذاتية التشغيل: commission_rate = 100% (كله للمنصة)
- منتجات الطرف الثالث: commission_rate = 5%~20% (عمولة المنصة)
- صيغة التسوية: مبلغ منتج الطلب - عمولة المنصة - رسوم القناة = مستحق المورد
- دورة التسوية: أسبوعية / شهرية

### العملية التجارية الكاملة للمورد

```
供应商入驻                              管理员审批
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 审核供应商信息
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 确认密码
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 成功
商品上架
────────
POST /supplier/products               管理员审核
  → {product_id, commission_rate}        → 关联供应商商品 + 设置佣金比例
  ← {supplier_product}                    → 商品状态: published

用户下单 ──→ 支付完成 ──→ 资源开通 ──→ 订单完成

定时结算 (每周一 04:17)                   提现
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 统计周期内已完成订单                    → 密码二次确认 (ConfirmationMiddleware)
  → 计算 total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → 检查可提现余额
  → 创建 SupplierSettlement                 → 创建 SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← 成功

管理员打款                              管理员 API Key 管理
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → 生成 sk_xxx (SHA256 存储)
  → 确认密码                               ← {api_key} (仅显示一次)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. المراقبة والتشغيل
### مراقبة الموارد

- المقاييس المجمعة: استخدام CPU/الذاكرة/القرص/عرض النطاق، اتصال IP، IOPS الأقراص السحابية، تحليل DNS، انتهاء شهادات SSL
- طرق الجمع: إبلاغ Agent / SNMP (ذاتي) + واجهة مراقبة مزود السحابة (طرف ثالث) + استقصاء WHOIS/DNS (النطاقات)
- دورة الجمع: 5 دقائق، تخزين في Prometheus + VictoriaMetrics

### قواعد التنبيه

| حدث التنبيه | الخطورة | شرط التشغيل |
|----------|--------|----------|
| تعطل الخادم | شديد | 3 عمليات Ping متتالية غير قابلة للوصول |
| CPU/الذاكرة > 90% | تنبيه | مستمر 10 دقائق |
| القرص > 90% | تحذير | مستمر 5 دقائق |
| عرض النطاق > 80% | تنبيه | مستمر 30 دقيقة |
| شهادة SSL < 30 يومًا للانتهاء | تحذير | فحص يومي |
| نطاق < 30 يومًا للانتهاء | تحذير | فحص يومي |
| فشل مهمة التوفير | شديد | فشلان متتاليان |
| اختلاف تسوية الدفع | شديد | معاملة واحدة > $0.01 |

---

## 11. بنية النشر
### بيئة الإنتاج

- خادما تطبيق × 2: webman (متعدد العمليات) + Nginx + Supervisor
- قاعدة البيانات: MySQL 8.0 رئيسي/تابع (1 رئيسي 2 تابع) + Redis Cluster
- قوائم الانتظار: webman redis-queue (معاودة الدفع/الإشعارات/مهام التوفير)
- المهام المجدولة: Crontab (التسوية/التحقق/فحص النطاقات/تذكير التجديد)
- التخزين: S3/OSS + CDN
- مراقبة السجلات: ELK/Loki + Prometheus + Grafana + Sentry

### هيكل الدلائل

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 客户端
│   └── harmonyos/         # HarmonyOS 客户端 (ArkTS)
├── service/               # webman 服务端
│   ├── app/
│   │   ├── controller/    # 控制器 (按模块)
│   │   ├── service/       # 业务逻辑 (按模块)
│   │   ├── model/         # 数据模型
│   │   ├── middleware/     # 中间件
│   │   ├── event/         # 事件定义
│   │   ├── listener/      # 事件监听器
│   │   ├── queue/         # 队列任务
│   │   ├── provider/      # 云厂商适配器
│   │   └── cron/          # 定时任务
│   ├── common/            # 公共库 (auth/payment/i18n/notification/helper)
│   ├── config/            # 配置文件
│   ├── database/
│   │   └── migrations/    # 数据库迁移
│   └── storage/           # 日志/缓存/上传
├── admin/                 # webman-admin
├── docs/                  # 文档
└── docker/                # Docker 配置
```

### تبعيات Composer الرئيسية

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### تحسينات التزامن العالي

#### 1. فصل القراءة/الكتابة في MySQL

يوجه Eloquent تلقائيًا SELECT إلى اتصال القراءة، وINSERT/UPDATE/DELETE إلى اتصال الكتابة.

```
配置 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (主库)
  connections.mysql.read  → DB_READ_HOST  (从库，可配置多个实现负载均衡)
  sticky = true           → 同一请求周期内写后读走主库（防主从延迟）

环境变量:
  DB_HOST=10.0.1.1          # 主库（写）
  DB_READ_HOST=10.0.2.1     # 从库（读），可部署多个
```

**قواعد توجيه القراءة/الكتابة:**

| نوع العملية | وجهة التوجيه | مثال |
|---------|---------|------|
| SELECT | اتصال القراءة | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | اتصال الكتابة | `Order::create(...)` |
| جميع العمليات داخل المعاملة | اتصال الكتابة | `DB::transaction(...)` |
| القراءة بعد الكتابة (sticky) | اتصال الكتابة | خلال نفس دورة الطلب |

#### 2. استراتيجية التخزين المؤقت متعدد المستويات في Redis

يستخدم `CacheService` تخزينًا مؤقتًا لبيانات القراءة عالية التكرار، مع تراجع تلقائي إلى الاستعلام المباشر عند تعذر توفر Redis.

```
缓存分层:
  L1: Redis (进程间共享，毫秒级)
  L2: MySQL (持久化，兜底)

缓存策略:
  产品列表        TTL 5min    按 region_id + category_id + keyword 分键
  产品详情        TTL 10min   按 product_id 分键，内容变更时主动失效
  区域列表        TTL 1h      区域数据极少变动
  汇率            TTL 30min   定时任务刷新 + 主动更新
  TLD 定价        TTL 1h      TLD 价格变动频率低
  帮助文章        TTL 10min   发布/修改时主动失效
  商品分类        TTL 10min   分类树变更时主动失效

缓存预热 (部署后):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

主动失效 (数据变更时):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// 使用示例
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. ضغط استجابات Nginx + حد المعدل

```
gzip 压缩:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  效果: JSON 响应压缩率 70-85%，节省带宽

proxy 优化:
  proxy_buffering on           # 缓冲上游响应，慢客户端不占 worker
  proxy_http_version 1.1       # HTTP/1.1 长连接复用
  keep-alive 到上游             # 减少 TCP 握手

限流:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  /health 端点不限流（关闭 access_log 减 I/O）
```

#### 4. اقتراحات فهارس قاعدة البيانات

استنادًا إلى تحليل أنماط الاستعلام، تقلل الفهارس التالية بشكل ملحوظ صفوف الفحص في سيناريوهات التزامن العالي:

| الجدول | الفهرس المقترح | الاستعلامات المغطاة |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | قائمة طلبات المستخدم + فلترة الحالة |
| `orders` | `(order_no)` (فريد) | استعلام دقيق برقم الطلب |
| `products` | `(status, category_id, sort)` | قائمة المنتجات الأمامية + فلترة التصنيف + الترتيب |
| `product_skus` | `(product_id, status)` | قائمة SKU + فلترة الحالة |
| `product_regions` | `(sku_id, region_id)` (فريد) | البحث في تسعير المناطق |
| `resources` | `(user_id, status)` | قائمة مواردي |
| `resources` | `(expired_at, status)` | المهمة المجدولة لفحص الانتهاء |
| `provision_tasks` | `(status, next_retry_at)` | استقصاء العامل للمهام المعلقة |
| `refresh_tokens` | `(user_id, revoked)` | استعلام إدارة الجلسات |
| `payment_transactions` | `(order_id)` | البحث عن المعاملات حسب الطلب |
| `payment_transactions` | `(transaction_no)` (فريد) | فحص تكرار Webhook |
| `tickets` | `(user_id, status)` | قائمة تذاكر المستخدم |
| `notifications` | `(user_id, read_at, created_at)` | قائمة إشعارات المستخدم |

#### 5. تقدير الاتصالات المتزامنة

```
webman 多进程:
  CPU 核数 × 进程数 = worker 数
  例: 4核 × 8 worker = 32 worker 进程
  
MySQL 连接数:
  每个 worker 维持 1 个持久连接
  32 worker × 2 实例 (service + admin) = 64 连接
  主库 32 + 从库 32，保守建议 MySQL max_connections ≥ 200

Nginx 连接数:
  worker_connections 1024 × worker_processes auto
  峰值并发 ≈ worker_connections × worker_processes / 2
  4核服务器 ≈ 2048 并发连接
```

---

## 12. الجدول الشامل لحالة التنفيذ
### الوحدات الأساسية

| الوحدة | الحالة | الوصف |
|------|------|------|
| **User** | ✅ مكتملة | تسجيل/تسجيل دخول/تحقق بريد/OAuth/TOTP/إدارة الجلسات/حذف GDPR/CRUD عناوين |
| **Product** | ✅ مكتملة | تسعير SKU×المنطقة، تصنيفات، بحث(ES)، تقييمات، خصائص، استيراد/تصدير دفعات |
| **Order** | ✅ مكتملة | سلة التسوق، تقديم الطلب، دورة الحياة، الاسترداد، الفواتير(PDF)، القسائم |
| **Payment** | ✅ مكتملة | قناة Stripe، توجيه قنوات متعدد، التحقق من توقيع webhook، التسوية |
| **Provisioning** | ✅ مكتملة | Proxmox + AWS EC2 + بنية ProviderFactory قابلة للتوسع |
| **Domain** | ✅ مكتملة | تسعير TLD، سجلات DNS، الموافقة على نقل النطاقات |
| **Supplier** | ✅ مكتملة | مراجعة الانضمام، إدراج المنتجات، التسوية، السحب، إدارة مفاتيح API |
| **Monitor** | ✅ مكتملة | فحص استجابة الموارد، محرك التنبيه، مراقبة شهادات SSL |
| **Ticket** | ✅ مكتملة | إنشاء/رد/توزيع/إغلاق/تتبع SLA |
| **Notification** | ✅ مكتملة | أربع قنوات بريد/SMS/Push/رسائل داخلية + إدارة تفضيلات المستخدم |
| **Report** | ✅ مكتملة | تقارير الإيرادات/الموردين/المناطق |
| **I18n** | ✅ مكتملة | تعدد اللغات والعملات والمناطق الزمنية |

### نظام الأمان

| الوظيفة | الحالة |
|------|------|
| WAF (8 فئات من أكثر من 45 قاعدة: حقن SQL/XSS/حقن أوامر/تضمين ملفات/حقن ترويسات/SSRF/حقن NoSQL/إعادة توجيه مفتوحة) | ✅ |
| وسيط CORS | ✅ |
| وسيط التعرف على منصة ClientPlatform (8 منصات) | ✅ |
| حد معدل الـ API (دلو رموز Redis) | ✅ |
| الحجب الجغرافي (MaxMind GeoIP2) | ✅ |
| وضع الصيانة (مفتاح متغير البيئة + قائمة IP بيضاء) | ✅ |
| تشفير الطلبات/الاستجابات (AES-256-GCM) | ✅ |
| سجلات التدقيق (قاعدة مستقلة، تتضمن تتبع client_platform) | ✅ |
| إخفاء البيانات (معالجة تلقائية للسجلات/الاستجابات) | ✅ |
| ربط بصمة جهاز JWT + دوران الرمز + تسجيل client_platform | ✅ |
| كلمات مرور bcrypt (cost=12) + تشفير ثانٍ عبر Encryptable | ✅ |
| تأكيد كلمة المرور ثانيًا (ConfirmationMiddleware، 5 فاشلات تقفل 15 دقيقة) | ✅ |
| وسيط WAF للوحة الإدارة | ✅ |
| مراقبة الأخطاء Sentry (SentryBootstrap + إخفاء before_send) | ✅ |
| مفاتيح Feature Flags (تجاوز ديناميكي عبر Redis + واجهة لوحة الإدارة) | ✅ |

### الميزات الجديدة (2026-05-21)

| الوظيفة | الحالة |
|------|------|
| الواجهة الخارجية للموردين (مصادقة مفتاح API + نقاط نهاية الطلبات/الموارد/التسويات/السحب) | ✅ |
| دفع فوري WebSocket (Workerman WebSocket أصلي + مراقبة الأحداث) | ✅ |
| سكربتات اختبار الحمل k6 (تدخين/منتجات/تزامن) | ✅ |

### إحصائيات الخلفية

| المؤشر | العدد |
|------|------|
| نقاط نهاية الـ API | 135 |
| نماذج البيانات | 50+ |
| جداول قاعدة البيانات | 50+ |
| الوسائط | 15 (عامة 7 + مسارات 6 + واجهة خارجية 1 + WebSocket admin) |
| المهام المجدولة | 7 |
| ملفات الترحيل | 22 |
| الاختبارات | 362 tests / 579 assertions (Service 295 + Admin 67) |
| ملفات الاختبار | 22 |
| سكربتات اختبار الحمل k6 | 3 (smoke / products / concurrent) |

### التوثيق

| الوثيقة | المسار |
|------|------|
| مواصفة تصميم النظام | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| تصميم لوحة الإدارة | `docs/admin-design.md` |
| وثيقة واجهة المورد | `docs/supplier-api.md` |
| قائمة النشر | `docs/deployment.md` |
| سكربت اختبار الـ API الوهمي | `docs/api-test.sh` |

### حالة الواجهات الأمامية

| الطرف | الحالة | الوصف |
|----|------|------|
| Flutter | 🟡 قيد التنفيذ | ربط ApiClient لرقم الإصدار في الترويسة + طبقة بيانات موحدة ApiService؛ ربط الـ API لتسجيل الدخول/قائمة المنتجات/سلة التسوق/قائمة الموارد؛ سجل الطلبات/مركز الإشعارات بحاجة لتحقق بيئة البناء |
| HarmonyOS | 🔴 مبكرة | صفحة تسجيل الدخول وApiClient فقط |
| لوحة الإدارة | ✅ مكتملة | لوحة المعلومات/المستخدمون/المنتجات/الطلبات/الدفع/الموارد/الموردون/التذاكر/النطاقات/الإشعارات/النظام/التقارير/Webhook/استيراد وتصدير — جميع الوظائف |
