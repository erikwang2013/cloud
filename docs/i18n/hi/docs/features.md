# CloudPlatform फ़ंक्शन डिज़ाइन दस्तावेज़

## 1. उपयोगकर्ता प्रमाणीकरण और प्राधिकरण

### 1.1 रजिस्टर

```
POST /api/v1/auth/register
  → WAF स्कैन
  → रेट लिमिट 3 req/min
  → पासवर्ड जाँच len≥8
  → ईमेल/फोन यूनीकनेस जाँच
  → bcrypt(password, cost=12)
  → Snowflake::id() से user_id जनरेट
  → Encryptable::set() से संवेदनशील फ़ील्ड एन्क्रिप्ट
  → User + UserProfile + UserBalance बनाएँ
  → NotificationDispatcher::send('email_verify') वेरिफिकेशन ईमेल भेजें
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**डेटा प्रवाह:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/v1/auth/register   │                        │                     │
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

### 1.2 लॉगिन

```
POST /api/v1/auth/login
  → WAF स्कैन
  → रेट लिमिट 5 req/min
  → Captcha सत्यापन (क्लिक कैप्चा, 3 प्रयास सीमा)
  → Hash::check(password, user->password_hash)
  → 5 बार विफल → login_lock:{userId} Redis TTL 900s
  → TOTP सत्यापन (उपयोगकर्ता द्वारा सक्षम किए जाने पर अनिवार्य, totp_code आवश्यक;
      5 बार गलत → totp_fail:{userId} → login_lock TTL 900s)
  → नया IP पहचान → ईमेल अलर्ट
  → deviceFingerprint = sha256(UA + IP सेगमेंट, IPv6 प्रीफिक्स लेता है)
  → clientPlatform = X-Client-Platform हेडर
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/v1/auth/google → Google OAuth → callback?code=xxx
  1. Google/Apple ID Token सत्यापित करें
  2. उपयोगकर्ता खोजें या बनाएँ (email मिलान)
  3. टोकन जारी करें (client_platform सहित)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP दो-चरणीय सत्यापन

```
1. POST /api/v1/user/totp/setup
     → secret + QR URL जनरेट (Redis में 10 मिनट अस्थायी, पर्सिस्ट नहीं)
     ← {secret, qr_url, manual}
2. POST /api/v1/user/totp/verify
     → TOTP code सत्यापित (पहली बार setup सक्षम करना, बाद में जाँच)
     ← {verified: true}
3. GET /api/v1/user/totp/recovery-codes
     → 8 एक-बार रिकवरी कोड जनरेट (पासवर्ड पुष्टि आवश्यक)
     ← {recovery_codes: [8 कोड]}
4. लॉगिन पर: TOTP code या रिकवरी कोड दर्ज करें
     → POST /api/v1/auth/login/recovery (login, password, recovery_code)
```

### 1.5 सत्र प्रबंधन

```
GET /api/v1/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/v1/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/v1/user/account (GDPR विलोपन)
  → पासवर्ड द्वितीयक पुष्टि
  → User सॉफ्ट-डिलीट
  → सभी RefreshToken revoked
```

---

## 2. उत्पाद प्रबंधन

### 2.1 उत्पाद मॉडल

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (बहुभाषी JSON) │                      │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 उत्पाद सूची (कैश के साथ)

```
GET /api/v1/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → category_id/region_id/keyword/supplier_id के अनुसार फ़िल्टर
    → count + skip/take पेजिनेशन
  ← पेजिनेटेड परिणाम

कैश अमान्यकरण:
  Admin product/SKU/region-price परिवर्तन
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 उत्पाद खोज (Elasticsearch)

```
GET /api/v1/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer चीनी टोकनाइज़ेशन)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 उत्पाद समीक्षा

```
GET /api/v1/products/{id}/reviews
  → स्वीकृत समीक्षाएँ + औसत रेटिंग + रेटिंग वितरण
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/v1/products/{id}/reviews (लॉगिन आवश्यक)
  → rating (1-5) + content
  → status = pending (एडमिन अनुमोदन के बाद दिखे)
```

### 2.5 बैच इम्पोर्ट/एक्सपोर्ट

```
GET /admin/api/v1/products/export
  → CSV डाउनलोड (उत्पाद + SKU + क्षेत्र मूल्य निर्धारण)

POST /admin/api/v1/products/import
  → CSV अपलोड upsert
  ← {imported: N, errors: [...]}
```

---

## 3. ऑर्डर सिस्टम

### 3.1 कार्ट

```
POST /api/v1/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/v1/cart           → कार्ट सूची (SKU विवरण + रीयल-टाइम मूल्य सहित)
DELETE /api/v1/cart/{id}   → removeFromCart
PUT /api/v1/cart/{id}      → updateCartQuantity
```

### 3.2 ऑर्डर प्रवाह

```
1. POST /api/v1/orders                           ऑर्डर बनाएँ
     → स्टॉक जाँच, मूल्य गणना, कूपन लागू
     ← {order_id, order_no, items, total}

2. POST /api/v1/coupons/validate                 कूपन लागू करें
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/v1/orders/{id}/payment-methods       उपलब्ध पेमेंट चैनल प्राप्त करें
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/v1/orders/{id}/pay                  पेमेंट आरंभ करें
     → पासवर्ड द्वितीयक पुष्टि (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 ऑर्डर जीवनचक्र

```
                    ┌─────────┐
                    │ pending  │ प्रतीक्षारत पेमेंट
                    └────┬─────┘
                         │ पेमेंट सफल
                    ┌────┴─────┐
                    │  paid    │ भुगतान हुआ
                    └────┬─────┘
                         │ OrderPaid इवेंट
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ पूर्ण
                    └────┬─────┘
                         │ उपयोगकर्ता रिफंड अनुरोध
                    ┌────┴─────┐
                    │ refunded │ रिफंड हो गया
                    └──────────┘

रिफंड शर्तें: सर्वर 72h के भीतर | डोमेन 5 दिनों के भीतर | IP गैर-रिफंडेबल | प्रमोशनल आइटम गैर-रिफंडेबल (अन्य प्रकार जैसे disk पर कोई विंडो सीमा नहीं; अज्ञात श्रेणी डिफ़ॉल्ट रूप से स्वीकृत)
रिफंड प्रवाह: उपयोगकर्ता अनुरोध → Ticket बनाएँ → ग्राहक सेवा समीक्षा → admin पुष्टि → Provider.destroy() → Payment.refund()
```

---

## 4. पेमेंट सिस्टम

### 4.1 मल्टी-चैनल रूटिंग

```
PaymentRouter::route(Order $order)
  → उपलब्ध चैनल फ़िल्टर (is_visible + visible_regions + min/max_amount)
  → currency के अनुसार मिलान
  → प्रत्येक चैनल की वास्तविक भुगतान राशि गणना (शुल्क सहित)
  → fee आरोही क्रम में क्रमबद्ध
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe पेमेंट

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Stripe चुनें
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. transaction बनाएँ
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. उपयोगकर्ता पेमेंट की पुष्टि करता है
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             stripe-signature हस्ताक्षर सत्यापन
                             transaction_no आइडेम्पोटेंसी जाँच
                          8. transaction=success
                          9. OrderPaid इवेंट ट्रिगर
                             → ProvisioningService
                             → WebSocket पुश
                             → ईमेल/SMS/Push नोटिफिकेशन
```

### 4.3 रिकॉन्सिलिएशन

```
Cron: PaymentReconcile (दैनिक 02:37)
  → प्रत्येक चैनल की सेटलमेंट रिपोर्ट खींचें
  → सिस्टम transaction के साथ आइटम-दर-आइटम मिलान
  → अंतर > $0.01 → अलर्ट
```

---

## 5. संसाधन प्रोविज़निंग इंजन

### 5.1 Provider प्लगइन आर्किटेक्चर

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
  (productType, provider) → Provider इंस्टेंस
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (विस्तार योग्य)
  'server:aliyun_ecs'  → AliyunProvider (विस्तार योग्य)
  'domain:namecheap'   → DomainProvider (विस्तार योग्य)
```

### 5.2 पूर्ण प्रोविज़निंग श्रृंखला

```
OrderPaid इवेंट ट्रिगर
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ प्रत्येक OrderItem के लिए ProvisionTask बनाएँ
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (Redis Queue कंज़्यूम)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     cpu/ram/disk शेष + लोड बैलेंसिंग के अनुसार क्रमबद्ध
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     VM बनाएँ (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     सिस्टम डिस्क माउंट (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     VM स्टार्ट
  │
  ├→ Resource + Disk + IpAllocation रिकॉर्ड बनाएँ
  │
  ├→ host_machine आवंटित संसाधन मात्रा अपडेट
  │
  └→ Order::status = completed
       → WebSocket पुश 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

रीट्राय नीति:
  1min → 5min → 15min → 1h → 6h → 24h (6 बार के बाद विफल चिह्नित + अलर्ट)
```

> **सप्लाई चैनल विकास**: Rust kvm-server (`infrastructure/kvm-server`, e-cat workspace) रेपो में है —
> gRPC `ping/create_vm/vm_status` (:50051) + etcd रजिस्ट्री डिस्कवरी, PHP पक्ष KvmClient /
> RegistryProcess (`service/app/grpc/`) जुड़ चुके हैं। ड्राइवर परत वर्तमान में **सिम्युलेटेड ड्राइवर** है (libvirt वास्तविक
> ड्राइवर Phase 2), प्रोविज़निंग श्रृंखला अभी ProxmoxProvider डायरेक्ट कनेक्शन से चलती है; kvm-server द्वारा VM
> निर्माण संभालने के बाद इस अनुभाग का प्रवाह अपरिवर्तित रहता है, केवल चैनल बदलता है।

### 5.3 Proxmox ऑपरेशन सारांश

| ऑपरेशन | API | हॉट ऑपरेशन |
|------|-----|--------|
| VM बनाएँ | POST /nodes/{node}/qemu | — |
| CPU अपग्रेड | PUT /qemu/{vmid}/config cores | ऑनलाइन |
| मेमोरी अपग्रेड | PUT /qemu/{vmid}/config memory | ऑनलाइन |
| सिस्टम डिस्क बढ़ाएँ | PUT /qemu/{vmid}/resize disk | ऑनलाइन |
| डेटा डिस्क बनाएँ | POST /qemu/{vmid}/config scsi{n} | ऑनलाइन |
| स्वतंत्र IP बनाएँ | POST /qemu/{vmid}/config net{n} | ऑनलाइन |
| VM नष्ट करें | POST stop → DELETE qemu | — |
| स्थिति क्वेरी | GET /qemu/{vmid}/status/current | — |

---

## 6. सप्लायर सिस्टम

### 6.1 आवेदन प्रवाह

```
POST /api/v1/supplier/apply (उपयोगकर्ता लॉगिन आवश्यक)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → एडमिन समीक्षा

एडमिन अनुमोदन:
  POST /admin/api/v1/suppliers/{id}/approve (पासवर्ड पुष्टि)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → उपयोगकर्ता को सप्लायर अनुमति मिलती है

उत्पाद लिस्टिंग:
  POST /api/v1/supplier/products
    → {product_id, commission_rate}
    → सप्लायर उत्पाद संबद्ध

सेटलमेंट:
  Cron: SupplierSettlement (हर सोमवार 04:17)
    → अवधि में पूर्ण ऑर्डर की गणना
    → total_sales - commission = payable
    → SupplierSettlement बनाएँ

निकासी:
  POST /api/v1/supplier/withdraw (पासवर्ड पुष्टि)
    → निकासी योग्य शेष राशि जाँच
    → SupplierWithdraw बनाएँ (status=pending)
    → एडमिन अनुमोदन और भुगतान
```

### 6.2 बाह्य API

```
POST /admin/api/v1/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → hash('sha256', rawKey) स्टोर
  ← {api_key: "sk_xxx..."} (केवल एक बार दिखे)

सप्लायर उपयोग:
  GET /api/v1/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware हस्ताक्षर सत्यापन
    → supplierId के अनुसार डेटा फ़िल्टर
```

---

## 7. डोमेन और DNS

```
GET /api/v1/domain/check/{domain}/{tld}    # डोमेन उपलब्धता
GET /api/v1/domain/tlds                     # रजिस्ट्रेबल TLD सूची (कैश 1h)
GET /api/v1/dns/{domain}                    # DNS रिकॉर्ड सूची
POST /api/v1/dns/{domain}/records           # DNS रिकॉर्ड जोड़ें
DELETE /api/v1/dns/{domain}/records/{id}    # DNS रिकॉर्ड हटाएँ (पासवर्ड पुष्टि)
```

---

## 8. टिकट सिस्टम

```
POST /api/v1/tickets                    # टिकट बनाएँ
GET /api/v1/tickets                     # मेरे टिकट
GET /api/v1/tickets/{id}                # टिकट विवरण
POST /api/v1/tickets/{id}/reply         # टिकट उत्तर दें

एडमिन:
  GET /admin/api/v1/tickets              # टिकट क्यू
  POST /admin/api/v1/tickets/{id}/assign # ग्राहक सेवा आवंटित करें
  POST /admin/api/v1/tickets/{id}/close  # टिकट बंद करें

इवेंट-ड्रिवन:
  TicketCreated इवेंट
    → AutoAssignListener: न्यूनतम लोड वाले ग्राहक सेवा को आवंटित
    → WebSocket पुश 'ticket.created'
```

---

## 9. नोटिफिकेशन सिस्टम

### 9.1 चार-चैनल डिस्पैच

```
इवेंट ट्रिगर → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → सीधे notifications टेबल में लिखना
```

### 9.2 नोटिफिकेशन प्रकार

| इवेंट | चैनल | ट्रिगर समय |
|------|------|---------|
| रजिस्टर सत्यापन | email | ईमेल रजिस्टर के बाद |
| लॉगिन असामान्य अलर्ट | email | नए IP से लॉगिन |
| ऑर्डर पेमेंट सफल | email/push | पेमेंट पूर्ण |
| संसाधन प्रोविज़निंग पूर्ण | email/push/in_app | Provisioning पूर्ण |
| संसाधन समाप्ति अनुस्मारक | email/push | 7d/3d/1d पहले |
| टिकट उत्तर | email/push/in_app | Ticket नया संदेश |
| रिफंड पूर्ण | email/push | रिफंड प्रोसेसिंग पूर्ण |
| SSL प्रमाणपत्र समाप्ति | email | 30d पहले |
| डोमेन समाप्ति | email | 30d पहले |

---

## 10. मॉनिटरिंग और अलर्टिंग

### 10.1 संसाधन मॉनिटरिंग

```
Cron: CollectMetrics (हर 5 मिनट)
  → सक्रिय संसाधनों को पोल करें
  → ProxmoxApi::status() / Provider API
  → मीट्रिक Redis hash में स्टोर (TTL 1h)

एडमिन:
  GET /admin/api/v1/monitor/dashboard
    → अवलोकन सांख्यिकी + हाल के अलर्ट
  GET /admin/api/v1/monitor/resources/{id}
    → रीयल-टाइम मीट्रिक (Redis से पढ़ें)
```

### 10.2 अलर्ट नियम

| नियम | गंभीरता | ट्रिगर शर्त |
|------|--------|---------|
| server_down | गंभीर | लगातार 3 बार Ping अप्राप्य |
| cpu_high | चेतावनी | CPU > 90% 10min तक |
| disk_high | चेतावनी | डिस्क > 90% 5min तक |
| ssl_expiring | चेतावनी | SSL प्रमाणपत्र < 30 दिन में समाप्ति |
| domain_expiring | चेतावनी | डोमेन < 30 दिन में समाप्ति |
| provision_failed | गंभीर | प्रोविज़निंग टास्क लगातार विफल |

---

## 11. शेड्यूल्ड टास्क

| Cron एक्सप्रेशन | टास्क | उपयोग |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | हर 4 घंटे में विनिमय दर सिंक |
| `37 2 * * *` | PaymentReconcile | दैनिक रिकॉन्सिलिएशन |
| `17 4 * * 1` | SupplierSettlement | हर सोमवार सप्लायर सेटलमेंट |
| `23 6 * * *` | ExpirationCheck | समाप्ति जाँच + नोटिफिकेशन |
| `43 7 * * *` | SslCertificateCheck | SSL प्रमाणपत्र जाँच |
| `*/5 * * * *` | CollectMetrics | संसाधन मीट्रिक संग्रह |
| `*/30 * * * *` | CheckExpirations | संसाधन समाप्ति जाँच |

---

## 12. अंतर्राष्ट्रीयकरण (i18n)

### 12.1 अनुरोध प्रवाह

```
क्लाइंट → Accept-Language: zh-CN
  → LocaleMiddleware (ग्लोबल मिडलवेयर)
    → I18n::setLocale('zh-CN')
    → i18n/zh-CN/messages.php लोड
```

### 12.2 अनुवाद विधि

**स्थैतिक टेक्स्ट:** `I18n::trans('auth.login_success')` → `लॉगिन सफल`
**JSON फ़ील्ड:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**पैरामीटर प्रतिस्थापन:** `I18n::trans('validation.required', ['field' => 'ईमेल'])` → `ईमेल खाली नहीं हो सकता`

### 12.3 कवरेज दायरा

120 एंट्रीज़, प्रमाणीकरण/उत्पाद/ऑर्डर/पेमेंट/संसाधन/KYC/टिकट/नोटिफिकेशन/सप्लायर/Webhook/सिस्टम सहित सभी मॉड्यूल कवर। भाषा फ़ॉलबैक समर्थित (असमर्थित भाषा → en-US)।

---

## 13. Feature Flags फ़ंक्शन स्विच

```
config/features.php (डिफ़ॉल्ट मान)
  ↓ ओवरराइड योग्य
.env FEATURE_* पर्यावरण वेरिएबल
  ↓ रनटाइम पर ओवरराइड योग्य
Redis feature:{name} (TTL 1h, एडमिन API से गतिशील समायोजन)

एडमिन API:
  GET /admin/api/v1/features → सभी Flags और स्थिति/स्रोत सूचीबद्ध करें
  PUT /admin/api/v1/features/{name} → enable/disable/toggle/reset

वर्तमान Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL प्रमाणपत्र

SSL प्रमाणपत्र उत्पाद DV/OV/EV तीन प्रकारों का समर्थन करता है, ACME प्रोटोकॉल (Let's Encrypt) या बाह्य CA API (ZeroSSL/GoGetSSL) के माध्यम से स्वचालित जारी और नवीनीकरण।

**मुख्य प्रवाह:**

    उपयोगकर्ता SSL पैकेज चुनें → ऑर्डर करें भुगतान करें → ProvisionTask बनाएँ
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 सत्यापन → प्रमाणपत्र जारी
      → हर दिन expires_at जाँच → समाप्ति से 14 दिन पहले स्वचालित नवीनीकरण
      → समाप्त → status=expired → उपयोगकर्ता को सूचित करें

**डेटा मॉडल:** `ssl_plans` (पैकेज), `resource_ssl_certs` (प्रमाणपत्र इंस्टेंस)

## 15. ऑब्जेक्ट स्टोरेज (S3)

S3 API संगत ऑब्जेक्ट स्टोरेज, AWS S3 और MinIO सेल्फ-होस्टेड स्टोरेज का समर्थन। उपयोगकर्ता प्री-साइन्ड URL के माध्यम से फ़ाइलें अपलोड/डाउनलोड करते हैं।

**डेटा मॉडल:** `resource_storage_buckets`

## 16. CDN एक्सेलेरेशन

CDN उत्पाद चार सेवाप्रदाताओं (Cloudflare / AWS CloudFront / Aliyun CDN / Tencent Cloud CDN) का समर्थन करता है, सर्वर या स्टोरेज बकेट को CDN के लिए ओरिजिन के रूप में जोड़ा जा सकता है, कैश पर्ज और वैकल्पिक HTTPS प्रमाणपत्र कॉन्फ़िगरेशन का समर्थन।

**एडेप्टर आर्किटेक्चर:** `service/app/cdn/provider/` के अंतर्गत प्रत्येक सेवाप्रदाता के लिए एक एडेप्टर, सभी `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration) लागू करते हैं, `CdnAdapterFactory` `provider_type` के अनुसार डिस्पैच करता है:

| provider_type | एडेप्टर | एकीकरण प्रोटोकॉल | ICP पंजीकरण आवश्यक |
|---------------|---------|------------------|--------------------|
| `cloudflare` | CloudflareAdapter | REST v4 API (SSL SaaS स्वचालित प्रमाणपत्र सहित) | नहीं |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | नहीं |
| `aliyun` | AliyunCdnAdapter | RPC सिग्नेचर | हाँ |
| `tencent` | TencentCdnAdapter | TC3 सिग्नेचर | हाँ |

**सेवाप्रदाता खाता कॉन्फ़िगरेशन:** एडमिन `/admin/providers` CRUD के माध्यम से `provider_apis` खाते बनाए रखता है (क्रेडेंशियल Encryptable एन्क्रिप्शन के साथ संग्रहीत, `code` कन्वेंशन `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`)। उपयोगकर्ता-पक्ष क्रेडेंशियल रिज़ॉल्यूशन प्राथमिकता: बाउंड खाता (provider_account_id) → code से मेल खाने वाला सक्रिय खाता → env कॉन्फ़िगरेशन फ़ॉलबैक।

**सख्त स्नैपशॉट बाइंडिंग:** डोमेन निर्माण के समय `provider_account_id` तय होता है, बाद में डिलीट/कैश पर्ज केवल उस बाउंड खाते का उपयोग करता है; खाता अनुपस्थित या अक्षम होने पर 4003 लौटाया जाता है, खाता चुपचाप स्विच नहीं किया जाता। Aliyun/Tencent डोमेन के लिए ICP पंजीकरण आवश्यक है, पंजीकरण न होने पर 4002 लौटाया जाता है (`requires_icp_registration` संकेत सहित)।

**कैश पर्ज:** `POST /api/v1/cdn/domains/{id}/purge`, URL स्वचालित रूप से डीडुप्लिकेट और ट्रिम होते हैं (अधिकतम 100), केवल इस डोमेन या सबडोमेन की अनुमति, वाइल्डकार्ड और बाहरी URL अस्वीकार, आइडेम्पोटेंट।

**इंटरफ़ेस:** CdnAdapterInterface + CdnProvider (ProvisionProvider अपग्रेड चैनल का पुन: उपयोग, plan अपग्रेड समर्थित)

**डेटा मॉडल:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; cert_config संग्रहीत करने से पहले निजी कुंजी हटाई जाती है, केवल गैर-संवेदनशील प्रमाणपत्र जानकारी रखी जाती है)

## 17. उपयोग-आधारित बिलिंग

संसाधन उपयोग संग्रह → समुच्चयन → बिलिंग → कटौती की पूर्ण पाइपलाइन:

    ResourceMonitor हर 5 मिनट मीट्रिक संग्रह → resource_metrics
      → UsageAggregator हर घंटे समुच्चयन → usage_events
      → BillingEngine दैनिक शेष कटौती → शेष अपर्याप्त → संसाधन सस्पेंड
      → SuspendCheck हर 30 मिनट जाँच → शेष बहाल → अनसस्पेंड

**डेटा मॉडल:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. सप्लायर रेटिंग

खरीदे गए उपयोगकर्ता सप्लायर को चार आयामों (गुणवत्ता/सपोर्ट/डिलीवरी गति/मूल्य-प्रभावशीलता) पर रेटिंग दे सकते हैं, प्रति ऑर्डर एक बार। एडमिन पक्ष समीक्षा कर सकता है (approve/hide)।

**डेटा मॉडल:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. रेफरल डिस्ट्रीब्यूशन

उपयोगकर्ता रेफरल लिंक (?ref=CODE) जनरेट करते हैं, नया उपयोगकर्ता रजिस्टर करते समय affiliate_code बाइंड होता है, ऑर्डर भुगतान के बाद कमीशन स्वचालित रूप से एट्रिब्यूट होता है।

**इवेंट-ड्रिवन:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**डेटा मॉडल:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. GraphQL API

POST /graphql (पब्लिक क्वेरी) और POST /api/v1/graphql (प्रमाणित क्वेरी) दो एंडपॉइंट प्रदान करता है। webonyx/graphql-php पर आधारित, क्वेरी डेप्थ सीमा 5 परतें, कॉम्प्लेक्सिटी सीमा 100।

**संवेदनशील ऑपरेशन REST-only रहते हैं:** पेमेंट, निकासी, रिफंड, KYC समीक्षा।

## 21. ऑब्ज़र्वेबिलिटी

Prometheus मीट्रिक एंडपॉइंट स्वतंत्र प्रोसेस 127.0.0.1:9100, WAF/रेट लिमिट से अप्रभावित। MetricsMiddleware HTTP अनुरोध काउंट और लेटेंसी रिकॉर्ड करता है। Docker Compose में Prometheus + Grafana + अलर्ट नियम + डैशबोर्ड प्री-इंस्टॉल।

**हेल्थ चेक:** /health (पब्लिक), /health/live, /health/ready (5 निर्भरता जाँच), /health/deps (लेटेंसी विवरण)
