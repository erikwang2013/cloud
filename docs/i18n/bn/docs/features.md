# CloudPlatform ফিচার ডিজাইন ডকুমেন্ট

## 1. ইউজার অথেনটিকেশন ও অথরাইজেশন

### 1.1 রেজিস্ট্রেশন

```
POST /api/auth/register
  → WAF স্ক্যান
  → রেট লিমিট 3 req/min
  → পাসওয়ার্ড ভ্যালিডেশন len≥8
  → ইমেইল/ফোন ইউনিকনেস চেক
  → bcrypt(password, cost=12)
  → Snowflake::id() দিয়ে user_id জেনারেশন
  → Encryptable::set() সংবেদনশীল ফিল্ড এনক্রিপশন
  → User + UserProfile + UserBalance তৈরি
  → NotificationDispatcher::send('email_verify') ভেরিফিকেশন মেইল পাঠানো
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**ডেটা ফ্লো:**

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

### 1.2 লগইন

```
POST /api/auth/login
  → WAF স্ক্যান
  → রেট লিমিট 5 req/min
  → Captcha ভেরিফিকেশন (ক্লিক ক্যাপচা, ৩ বার চেষ্টার সীমা)
  → Hash::check(password, user->password_hash)
  → ৫ বার ব্যর্থ → login_lock:{userId} Redis TTL 900s
  → TOTP ভেরিফিকেশন (ইউজার এনাবল করলে বাধ্যতামূলক, totp_code আবশ্যক;
       মোট ৫ বার ভুল → totp_fail:{userId} → login_lock TTL 900s)
  → নতুন IP ডিটেকশন → ইমেইল অ্যালার্ট
  → deviceFingerprint = sha256(UA + IP সেগমেন্ট, IPv6-তে প্রিফিক্স নেওয়া হয়)
  → clientPlatform = X-Client-Platform হেডার
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. Google/Apple ID Token ভেরিফাই
  2. ইউজার খোঁজা বা তৈরি (email ম্যাচ)
  3. token ইস্যু (client_platform সহ)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP টু-স্টেপ ভেরিফিকেশন

```
1. POST /api/user/totp/setup
     → secret + QR URL জেনারেশন (Redis-এ ১০ মিনিট সাময়িক, পারসিস্টেন্ট নয়)
     ← {secret, qr_url, manual}
2. POST /api/user/totp/verify
     → TOTP code ভেরিফাই (প্রথমবার setup এনাবল, পরে ভেরিফিকেশন)
     ← {verified: true}
3. GET /api/user/totp/recovery-codes
     → ৮টি ওয়ান-টাইম রিকভারি কোড জেনারেশন (পাসওয়ার্ড কনফার্মেশন প্রয়োজন)
     ← {recovery_codes: [৮টি]}
4. লগইনের সময়: TOTP code বা রিকভারি কোড ইনপুট
     → POST /api/auth/login/recovery (login, password, recovery_code)
```

### 1.5 সেশন ম্যানেজমেন্ট

```
GET /api/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/user/account (GDPR ডিলিশন)
  → পাসওয়ার্ড সেকেন্ডারি কনফার্মেশন
  → User সফট-ডিলিট
  → সব RefreshToken revoked
```

---

## 2. প্রোডাক্ট ম্যানেজমেন্ট

### 2.1 প্রোডাক্ট মডেল

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (মাল্টিল্যাঙ্গুয়াল JSON) │        │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 প্রোডাক্ট লিস্ট (ক্যাশসহ)

```
GET /api/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → category_id/region_id/keyword/supplier_id দিয়ে ফিল্টার
    → count + skip/take পেজিনেশন
  ← পেজিনেটেড ফলাফল

ক্যাশ ইনভ্যালিডেশন:
  Admin product/SKU/region-price পরিবর্তন
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 প্রোডাক্ট সার্চ (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer চাইনিজ টোকেনাইজেশন)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 প্রোডাক্ট রিভিউ

```
GET /api/products/{id}/reviews
  → অ্যাপ্রুভড রিভিউ + গড় রেটিং + রেটিং ডিস্ট্রিবিউশন
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (লগইন প্রয়োজন)
  → rating (1-5) + content
  → status = pending (অ্যাডমিন রিভিউর পর ডিসপ্লে)
```

### 2.5 ব্যাচ ইমপোর্ট/এক্সপোর্ট

```
GET /admin/api/products/export
  → CSV ডাউনলোড (প্রোডাক্ট + SKU + রিজিয়ন প্রাইসিং)

POST /admin/api/products/import
  → CSV আপলোড upsert
  ← {imported: N, errors: [...]}
```

---

## 3. অর্ডার সিস্টেম

### 3.1 কার্ট

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → কার্ট লিস্ট (SKU ডিটেইল + রিয়েল-টাইম প্রাইসসহ)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 অর্ডার প্লেসমেন্ট ফ্লো

```
1. POST /api/orders                           অর্ডার তৈরি
     → ইনভেন্টরি ভ্যালিডেশন, প্রাইস ক্যালকুলেশন, কুপন প্রয়োগ
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                 কুপন প্রয়োগ
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods       উপলব্ধ পেমেন্ট চ্যানেল
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/orders/{id}/pay                  পেমেন্ট শুরু
     → পাসওয়ার্ড সেকেন্ডারি কনফার্মেশন (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 অর্ডার লাইফসাইকেল

```
                    ┌─────────┐
                    │ pending  │ পেমেন্ট অপেক্ষা
                    └────┬─────┘
                         │ পেমেন্ট সফল
                    ┌────┴─────┐
                    │  paid    │ পেমেন্ট সম্পন্ন
                    └────┬─────┘
                         │ OrderPaid ইভেন্ট
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ সম্পন্ন
                    └────┬─────┘
                         │ ইউজার রিফান্ড রিকোয়েস্ট
                    ┌────┴─────┐
                    │ refunded │ রিফান্ড সম্পন্ন
                    └──────────┘

রিফান্ড শর্ত: সার্ভার 72h-এর মধ্যে | ডোমেইন ৫ দিনের মধ্যে | IP অ-রিফান্ডেবল | প্রোমোশনাল প্রোডাক্ট অ-রিফান্ডেবল (অন্যান্য টাইপ যেমন disk-এর উইন্ডো সীমা নেই; অজানা ক্যাটাগরি টাইপ ডিফল্টভাবে পাস)
রিফান্ড ফ্লো: ইউজার রিকোয়েস্ট → Ticket তৈরি → কাস্টমার সার্ভিস রিভিউ → admin কনফার্ম → Provider.destroy() → Payment.refund()
```

---

## 4. পেমেন্ট সিস্টেম

### 4.1 মাল্টি-চ্যানেল রাউটিং

```
PaymentRouter::route(Order $order)
  → উপলব্ধ চ্যানেল ফিল্টার (is_visible + visible_regions + min/max_amount)
  → currency অনুযায়ী ম্যাচ
  → প্রতিটি চ্যানেলের প্রকৃত পেমেন্ট অ্যামাউন্ট ক্যালকুলেশন (ফিসহ)
  → fee আরোহী ক্রমে সাজানো
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe পেমেন্ট

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Stripe নির্বাচন
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. transaction তৈরি
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. ইউজার পেমেন্ট কনফার্ম
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             stripe-signature সিগনেচার ভেরিফিকেশন
                             transaction_no দিয়ে আইডেমপোটেন্সি চেক
                          8. transaction=success
                          9. OrderPaid ইভেন্ট ট্রিগার
                             → ProvisioningService
                             → WebSocket পুশ
                             → ইমেইল/SMS/Push নোটিফিকেশন
```

### 4.3 রিকনসিলিয়েশন

```
Cron: PaymentReconcile (প্রতিদিন 02:37)
  → প্রতিটি চ্যানেলের সেটেলমেন্ট রিপোর্ট ফেচ
  → সিস্টেম transaction-এর সাথে লেন-দেন ম্যাচ
  → পার্থক্য > $0.01 → অ্যালার্ট
```

---

## 5. রিসোর্স প্রোভিশনিং ইঞ্জিন

### 5.1 Provider প্লাগইন আর্কিটেকচার

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
  (productType, provider) → Provider ইন্সট্যান্স
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (এক্সটেনসিবল)
  'server:aliyun_ecs'  → AliyunProvider (এক্সটেনসিবল)
  'domain:namecheap'   → DomainProvider (এক্সটেনসিবল)
```

### 5.2 সম্পূর্ণ প্রোভিশনিং চেইন

```
OrderPaid ইভেন্ট ট্রিগার
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ প্রতিটি OrderItem-এর জন্য ProvisionTask তৈরি
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (Redis Queue কনজিউমার)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     cpu/ram/disk রিমেইনিং + লোড ব্যালেন্সিং অনুযায়ী সাজানো
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     VM তৈরি (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     সিস্টেম ডিস্ক মাউন্ট (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     VM স্টার্ট
  │
  ├→ Resource + Disk + IpAllocation রেকর্ড তৈরি
  │
  ├→ host_machine অ্যালোকেটেড রিসোর্স আপডেট
  │
  └→ Order::status = completed
       → WebSocket পুশ 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

রিট্রাই স্ট্র্যাটেজি:
  1min → 5min → 15min → 1h → 6h → 24h (৬ বার পর ফেল্ড মার্ক + অ্যালার্ট)
```

> **প্রোভিশনিং চ্যানেল বিবর্তন**: Rust kvm-server (`infrastructure/kvm-server`, e-cat workspace) রিপোতে যুক্ত হয়েছে——
> gRPC `ping/create_vm/vm_status` (:50051) + etcd রেজিস্ট্রেশন ডিসকভারি, PHP পাশে KvmClient /
> RegistryProcess (`service/app/grpc/`) সংযুক্ত। ড্রাইভ লেয়ার বর্তমানে **সিমুলেটেড ড্রাইভ** (libvirt রিয়েল
> ড্রাইভ Phase 2), প্রোভিশনিং চেইন এখনো ProxmoxProvider সরাসরি সংযোগ দিয়ে চলে; kvm-server VM তৈরি
> গ্রহণ করার পর এই সেকশনের ফ্লো অপরিবর্তিত থাকবে, শুধু চ্যানেল স্যুইচ হবে।

### 5.3 Proxmox অপারেশন সারাংশ

| অপারেশন | API | হট অপারেশন |
|------|-----|--------|
| VM তৈরি | POST /nodes/{node}/qemu | — |
| CPU আপগ্রেড | PUT /qemu/{vmid}/config cores | অনলাইন |
| মেমরি আপগ্রেড | PUT /qemu/{vmid}/config memory | অনলাইন |
| সিস্টেম ডিস্ক এক্সপ্যানশন | PUT /qemu/{vmid}/resize disk | অনলাইন |
| ডেটা ডিস্ক তৈরি | POST /qemu/{vmid}/config scsi{n} | অনলাইন |
| স্বাধীন IP তৈরি | POST /qemu/{vmid}/config net{n} | অনলাইন |
| VM ডেস্ট্রয় | POST stop → DELETE qemu | — |
| স্ট্যাটাস কোয়েরি | GET /qemu/{vmid}/status/current | — |

---

## 6. সাপ্লায়ার সিস্টেম

### 6.1 অনবোর্ডিং ফ্লো

```
POST /api/supplier/apply (ইউজার লগইন প্রয়োজন)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → অ্যাডমিন রিভিউ

অ্যাডমিন অ্যাপ্রুভাল:
  POST /admin/api/suppliers/{id}/approve (পাসওয়ার্ড কনফার্মেশন)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → ইউজার সাপ্লায়ার পারমিশন পায়

প্রোডাক্ট লিস্টিং:
  POST /api/supplier/products
    → {product_id, commission_rate}
    → সাপ্লায়ার প্রোডাক্টে অ্যাসোসিয়েট

সেটেলমেন্ট:
  Cron: SupplierSettlement (প্রতি সোমবার 04:17)
    → পিরিয়ডের মধ্যে সম্পন্ন অর্ডার হিসাব
    → total_sales - commission = payable
    → SupplierSettlement তৈরি

উইথড্রয়াল:
  POST /api/supplier/withdraw (পাসওয়ার্ড কনফার্মেশন)
    → উইথড্রেবল ব্যালেন্স চেক
    → SupplierWithdraw তৈরি (status=pending)
    → অ্যাডমিন অ্যাপ্রুভাল ও পেমেন্ট
```

### 6.2 এক্সটার্নাল API

```
POST /admin/api/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → hash('sha256', rawKey) স্টোর
  ← {api_key: "sk_xxx..."} (শুধু একবার দেখানো হয়)

সাপ্লায়ার ব্যবহার:
  GET /api/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware সিগনচার ভেরিফিকেশন
    → supplierId অনুযায়ী ডেটা ফিল্টার
```

---

## 7. ডোমেইন ও DNS

```
GET /api/domain/check/{domain}/{tld}    # ডোমেইন অ্যাভেইলেবিলিটি
GET /api/domain/tlds                     # রেজিস্ট্রেবল TLD লিস্ট (ক্যাশ 1h)
GET /api/dns/{domain}                    # DNS রেকর্ড লিস্ট
POST /api/dns/{domain}/records           # DNS রেকর্ড যোগ
DELETE /api/dns/{domain}/records/{id}    # DNS রেকর্ড ডিলিট (পাসওয়ার্ড কনফার্মেশন)
```

---

## 8. টিকেট সিস্টেম

```
POST /api/tickets                    # টিকেট তৈরি
GET /api/tickets                     # আমার টিকেট
GET /api/tickets/{id}                # টিকেট ডিটেইল
POST /api/tickets/{id}/reply         # টিকেট রিপ্লাই

অ্যাডমিন:
  GET /admin/api/tickets              # টিকেট কিউ
  POST /admin/api/tickets/{id}/assign # কাস্টমার সার্ভিস অ্যাসাইন
  POST /admin/api/tickets/{id}/close  # টিকেট ক্লোজ

ইভেন্ট-ড্রিভেন:
  TicketCreated ইভেন্ট
    → AutoAssignListener: সবচেয়ে কম লোডের কাস্টমার সার্ভিসে অ্যাসাইন
    → WebSocket পুশ 'ticket.created'
```

---

## 9. নোটিফিকেশন সিস্টেম

### 9.1 চার-চ্যানেল ডিসপ্যাচ

```
ইভেন্ট ট্রিগার → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → সরাসরি notifications টেবিলে লেখা
```

### 9.2 নোটিফিকেশন টাইপ

| ইভেন্ট | চ্যানেল | ট্রিগার সময় |
|------|------|---------|
| রেজিস্ট্রেশন ভেরিফিকেশন | email | ইমেইল রেজিস্ট্রেশনের পর |
| লগইন অস্বাভাবিকতা অ্যালার্ট | email | নতুন IP থেকে লগইন |
| অর্ডার পেমেন্ট সফল | email/push | পেমেন্ট সম্পন্ন |
| রিসোর্স প্রোভিশনিং সম্পন্ন | email/push/in_app | Provisioning শেষ |
| রিসোর্স এক্সপায়ারি রিমাইন্ডার | email/push | 7d/3d/1d আগে |
| টিকেট রিপ্লাই | email/push/in_app | Ticket নতুন মেসেজ |
| রিফান্ড সম্পন্ন | email/push | রিফান্ড প্রসেস শেষ |
| SSL সার্টিফিকেট এক্সপায়ারি | email | 30d আগে |
| ডোমেইন এক্সপায়ারি | email | 30d আগে |

---

## 10. মনিটরিং ও অ্যালার্ট

### 10.1 রিসোর্স মনিটরিং

```
Cron: CollectMetrics (প্রতি ৫ মিনিট)
  → অ্যাক্টিভ রিসোর্স পোল
  → ProxmoxApi::status() / Provider API
  → মেট্রিক্স Redis hash-এ স্টোর (TTL 1h)

অ্যাডমিন:
  GET /admin/api/monitor/dashboard
    → ওভারভিউ পরিসংখ্যান + সাম্প্রতিক অ্যালার্ট
  GET /admin/api/monitor/resources/{id}
    → রিয়েল-টাইম মেট্রিক্স (Redis থেকে রিড)
```

### 10.2 অ্যালার্ট রুল

| রুল | গুরুত্ব | ট্রিগার শর্ত |
|------|--------|---------|
| server_down | গুরুতর | টানা ৩ বার Ping অরিচেবল |
| cpu_high | সতর্কতা | CPU > 90% টানা 10min |
| disk_high | সতর্কতা | ডিস্ক > 90% টানা 5min |
| ssl_expiring | সতর্কতা | SSL সার্টিফিকেট < ৩০ দিনে এক্সপায়ার |
| domain_expiring | সতর্কতা | ডোমেইন < ৩০ দিনে এক্সপায়ার |
| provision_failed | গুরুতর | প্রোভিশনিং টাস্ক টানা ব্যর্থ |

---

## 11. ক্রন টাস্ক

| Cron এক্সপ্রেশন | টাস্ক | ব্যবহার |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | প্রতি ৪ ঘণ্টায় এক্সচেঞ্জ রেট সিঙ্ক |
| `37 2 * * *` | PaymentReconcile | দৈনিক রিকনসিলিয়েশন |
| `17 4 * * 1` | SupplierSettlement | প্রতি সোমবার সাপ্লায়ার সেটেলমেন্ট |
| `23 6 * * *` | ExpirationCheck | এক্সপায়ারি চেক + নোটিফিকেশন |
| `43 7 * * *` | SslCertificateCheck | SSL সার্টিফিকেট চেক |
| `*/5 * * * *` | CollectMetrics | রিসোর্স মেট্রিক্স কালেকশন |
| `*/30 * * * *` | CheckExpirations | রিসোর্স এক্সপায়ারি চেক |

---

## 12. ইন্টারন্যাশনালাইজেশন (i18n)

### 12.1 রিকোয়েস্ট ফ্লো

```
ক্লায়েন্ট → Accept-Language: zh-CN
  → LocaleMiddleware (গ্লোবাল মিডলওয়্যার)
    → I18n::setLocale('zh-CN')
    → i18n/zh-CN/messages.php লোড
```

### 12.2 ট্রান্সলেশন পদ্ধতি

**স্ট্যাটিক টেক্সট:** `I18n::trans('auth.login_success')` → `লগইন সফল`
**JSON ফিল্ড:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**প্যারামিটার রিপ্লেসমেন্ট:** `I18n::trans('validation.required', ['field' => 'ইমেইল'])` → `ইমেইল 不能为空`

### 12.3 কভারেজ রেঞ্জ

১২০ এন্ট্রি, অথেনটিকেশন/প্রোডাক্ট/অর্ডার/পেমেন্ট/রিসোর্স/KYC/টিকেট/নোটিফিকেশন/সাপ্লায়ার/Webhook/সিস্টেম সহ সব মডিউল কভার করে। ভাষা ফলব্যাক সাপোর্ট (সাপোর্টেড না হলে → en-US)।

---

## 13. Feature Flags ফিচার সুইচ

```
config/features.php (ডিফল্ট মান)
  ↓ ওভাররাইড করা যায়
.env FEATURE_* এনভায়রনমেন্ট ভেরিয়েবল
  ↓ রানটাইমে ওভাররাইড করা যায়
Redis feature:{name} (TTL 1h, অ্যাডমিন API দিয়ে ডাইনামিক অ্যাডজাস্ট)

অ্যাডমিন API:
  GET /admin/api/features → সব Flag ও স্ট্যাটাস/সোর্স লিস্ট
  PUT /admin/api/features/{name} → enable/disable/toggle/reset

বর্তমান Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL সার্টিফিকেট

SSL সার্টিফিকেট প্রোডাক্ট DV/OV/EV তিনটি টাইপ সাপোর্ট করে, ACME প্রোটোকল (Let's Encrypt) বা এক্সটার্নাল CA API (ZeroSSL/GoGetSSL) দিয়ে স্বয়ংক্রিয় ইস্যু ও রিনিউ হয়।

**কী ফ্লো:**

    用户选购 SSL 套餐 → অর্ডার পেমেন্ট → ProvisionTask তৈরি
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 ভেরিফিকেশন → সার্টিফিকেট ইস্যু
      → প্রতিদিন expires_at চেক → এক্সপায়ারির ১৪ দিন আগে অটো রিনিউ
      → এক্সপায়ার → status=expired → ইউজারকে নোটিফাই

**ডেটা মডেল:** `ssl_plans` (প্ল্যান), `resource_ssl_certs` (সার্টিফিকেট ইন্সট্যান্স)

## 15. অবজেক্ট স্টোরেজ (S3)

S3 API-কমপ্যাটিবল অবজেক্ট স্টোরেজ, AWS S3 ও MinIO সেলফ-হোস্টেড স্টোরেজ সাপোর্ট করে। ইউজাররা প্রিসাইনড URL দিয়ে ফাইল আপলোড/ডাউনলোড করে।

**ডেটা মডেল:** `resource_storage_buckets`

## 16. CDN অ্যাক্সিলারেশন

CDN প্রোডাক্ট Cloudflare ইন্টিগ্রেশন সাপোর্ট করে, সার্ভার বা স্টোরেজ বাকেট অরিজিন হিসেবে CDN-এ যুক্ত করা যায়, ক্যাশ পর্জ সাপোর্ট করে।

**ইন্টারফেস:** ProviderInterface + CachePurgeInterface (ঐচ্ছিক ক্ষমতা ইন্টারফেস)

**ডেটা মডেল:** `resource_cdn`

## 17. পে-অ্যাস-ইউ-গো বিলিং

রিসোর্স ইউসেজ কালেকশন → অ্যাগ্রিগেশন → বিলিং → ডেবিটের সম্পূর্ণ পাইপলাইন:

    ResourceMonitor প্রতি ৫ মিনিটে মেট্রিক্স কালেক্ট → resource_metrics
      → UsageAggregator প্রতি ঘণ্টায় অ্যাগ্রিগেট → usage_events
      → BillingEngine প্রতিদিন ব্যালেন্স ডেবিট → ব্যালেন্স অপর্যাপ্ত → রিসোর্স সাসপেন্ড
      → SuspendCheck প্রতি ৩০ মিনিটে চেক → ব্যালেন্স পুনরুদ্ধার → আনসাসপেন্ড

**ডেটা মডেল:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. সাপ্লায়ার রেটিং

কেনা-হয়েছে-এমন-ইউজাররা সাপ্লায়ারকে চার-ডাইমেনশন রেটিং দিতে পারেন (কোয়ালিটি/সাপোর্ট/ডেলিভারি স্পিড/ভ্যালু ফর মানি), প্রতি অর্ডারে একবার। অ্যাডমিন সাইড রিভিউ (approve/hide) করা যায়।

**ডেটা মডেল:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. রেফারেল ডিস্ট্রিবিউশন

ইউজাররা রেফারেল লিংক তৈরি করে (?ref=CODE), নতুন ইউজার রেজিস্ট্রেশনের সময় affiliate_code বাইন্ড হয়, অর্ডার পেমেন্টের পর অটোমেটিক অ্যাট্রিবিউশন ও কমিশন হয়।

**ইভেন্ট-ড্রিভেন:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**ডেটা মডেল:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. GraphQL API

POST /graphql (পাবলিক কোয়েরি) ও POST /api/graphql (অথেনটিকেটেড কোয়েরি) দুটি এন্ডপয়েন্ট প্রদান করে। webonyx/graphql-php ভিত্তিক, কোয়েরি ডেপথ লিমিট ৫ লেয়ার, কমপ্লেক্সিটি লিমিট ১০০।

**সংবেদনশীল অপারেশন REST-only থাকে:** পেমেন্ট, উইথড্রয়াল, রিফান্ড, KYC রিভিউ।

## 21. অবজারভেবিলিটি

Prometheus মেট্রিক্স এন্ডপয়েন্ট স্বাধীন প্রসেস 127.0.0.1:9100, WAF/রেট লিমিটের আওতাভুক্ত নয়। MetricsMiddleware HTTP রিকোয়েস্ট কাউন্ট ও লেটেন্সি রেকর্ড করে। Docker Compose-এ Prometheus + Grafana + অ্যালার্ট রুল + ড্যাশবোর্ড প্রিসেট আছে।

**হেলথ চেক:** /health (পাবলিক), /health/live, /health/ready (৫টি ডিপেন্ডেন্সি চেক), /health/deps (লেটেন্সি ডিটেইল)
