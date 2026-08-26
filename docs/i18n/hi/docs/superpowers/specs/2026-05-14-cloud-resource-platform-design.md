# वैश्विक क्लाउड संसाधन ट्रेडिंग प्लेटफ़ॉर्म — सिस्टम डिज़ाइन

## परियोजना अवलोकन

वैश्विक उपयोगकर्ताओं के लिए क्लाउड संसाधन ट्रेडिंग प्लेटफ़ॉर्म, स्वयं-संचालित + तृतीय-पक्ष आपूर्तिकर्ता हाइब्रिड मोड का समर्थन करता है। उपयोगकर्ता सर्वर, IP, क्लाउड डिस्क, डोमेन आदि क्लाउड उत्पाद खरीद सकते हैं। पूर्ण-स्वचालित संसाधन प्रोविज़निंग, बहु-भुगतान चैनल, बहु-मुद्रा, बहुभाषा।

### प्रौद्योगिकी स्टैक

| स्तर | प्रौद्योगिकी |
|------|------|
| उपयोगकर्ता App | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| प्रशासन पैनल | webman-admin |
| सर्वर | PHP webman (मॉड्यूलर मोनोलिथ) |
| डेटाबेस | MySQL 8.0 (मास्टर-स्लेव) |
| कैश/कतार | Redis (कैश + Session + कतार) |
| स्टोरेज | S3/OSS + CDN |
| मॉनिटरिंग | Prometheus + Grafana + Sentry + ELK/Loki |

---

## एक, मॉड्यूल विभाजन (12 कोर मॉड्यूल)

| मॉड्यूल | ज़िम्मेदारी |
|------|------|
| **User** | पंजीकरण/लॉगिन (OAuth+ईमेल+फ़ोन), KYC सत्यापन, सदस्य स्तर, शेष राशि खाता |
| **Product** | उत्पाद परिभाषा (SKU), बहु-क्षेत्र मूल्य निर्धारण, स्टॉक प्रबंधन, श्रेणियाँ, खोज, समीक्षाएँ |
| **Order** | कार्ट, ऑर्डर, ऑर्डर जीवनचक्र (लंबित→भुगतान→प्रोविज़निंग→पूर्ण→रिफ़ंड), नवीनीकरण/अपग्रेड |
| **Payment** | भुगतान चैनल रूटिंग, बहु-मुद्रा कोटेशन, विनिमय दर, रिफ़ंड, निपटान |
| **Provisioning** | विभिन्न क्लाउड प्रदाता API से जुड़ना, स्वचालित निर्माण/नवीनीकरण/विनाश |
| **Domain** | डोमेन क्वेरी, पंजीकरण, स्थानांतरण, नवीनीकरण, DNS प्रबंधन |
| **Supplier** | आपूर्तिकर्ता पंजीकरण, स्वीकृति, उत्पाद सूचीकरण, निपटान, बंटवारा |
| **Monitor** | संसाधन स्थिति जाँच, उपयोग संग्रह, अलर्ट नियम |
| **Ticket** | टिकट सबमिशन, असाइनमेंट, SLA ट्रैकिंग |
| **Notification** | ईमेल/SMS/App Push/स्टेशन संदेश, बहु-टेम्पलेट बहुभाषा |
| **Report** | राजस्व रिपोर्ट, आपूर्तिकर्ता निपटान रिपोर्ट, बिक्री रुझान |
| **I18n** | बहुभाषा शब्द, बहु-मुद्रा विनिमय दरें, बहु-समय क्षेत्र |

---

## दो, कोर डेटा मॉडल

### उपयोगकर्ता केंद्र (User)

- **users** — उपयोगकर्ता मुख्य तालिका (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — उपयोगकर्ता प्रोफ़ाइल (user_id, avatar, nickname, country)
- **user_kyc** — सत्यापन (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — शेष राशि खाता (user_id, currency, balance, frozen_balance)
- **user_balance_log** — शेष बदलाव रिकॉर्ड (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — उपयोगकर्ता पते (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### उत्पाद केंद्र (Product)

- **product_categories** — उत्पाद श्रेणियाँ (id, parent_id, name, icon, sort)
- **products** — उत्पाद मुख्य तालिका (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — क्षेत्रीय मूल्य निर्धारण (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — उत्पाद चित्र (product_id, url, sort)
- **product_attributes** — कस्टम गुण (product_id, key, value)
- **product_reviews** — उत्पाद समीक्षाएँ (user_id, product_id, order_id, rating, content)
- **regions** — क्षेत्र तालिका (id, name, continent, country, city, data_center, status)

### ऑर्डर केंद्र (Order)

- **carts** — कार्ट (user_id, sku_id, region_id, quantity, cycle)
- **orders** — ऑर्डर मुख्य तालिका (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — ऑर्डर विवरण (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — ऑर्डर समयरेखा (order_id, status, operator, remark, created_at)
- **order_invoices** — चालान (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — रिफ़ंड रिकॉर्ड (order_id, user_id, amount, reason, status, handled_by)

### भुगतान केंद्र (Payment)

- **payment_channels** — भुगतान चैनल कॉन्फ़िगरेशन (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — लेनदेन रिकॉर्ड (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — निपटान तालिका (date, channel_id, channel_total, system_total, diff, status)

### संसाधन प्रोविज़निंग (Provisioning)

- **resources** — संसाधन मुख्य तालिका (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — सर्वर विवरण (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP विवरण (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — क्लाउड डिस्क विवरण (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — डोमेन विवरण (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — प्रोविज़निंग कार्य (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — क्लाउड प्रदाता API कॉन्फ़िगरेशन (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### भौतिक मशीन संसाधन प्रबंधन (Host & IP Pool)

स्वयं-संचालित भौतिक सर्वर Proxmox VE (सामुदायिक संस्करण, मुफ़्त) के साथ VM प्रबंधित करते हैं, REST API के माध्यम से VM बनाना/प्रबंधित करना, IP आवंटित करना, डिस्क माउंट करना।

- **host_machines** — होस्ट मशीनें (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP पूल (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP आवंटन रिकॉर्ड (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM डिस्क विवरण (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — डिस्क विस्तार रिकॉर्ड (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### आपूर्तिकर्ता (Supplier)

- **suppliers** — आपूर्तिकर्ता मुख्य तालिका (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — आपूर्तिकर्ता उत्पाद संबद्धता (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — निपटान रिपोर्ट (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — निकासी रिकॉर्ड (supplier_id, amount, method, account_info, status)

### डोमेन सेवा (Domain)

- **domain_tlds** — समर्थित TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — डोमेन स्थानांतरण (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS क्षेत्र (domain_name, user_id, zone_id)
- **dns_records** — DNS रिकॉर्ड (zone_id, type, name, value, ttl, priority)

### टिकट और सूचना (Ticket & Notification)

- **tickets** — टिकट (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — टिकट संदेश (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — सूचना रिकॉर्ड (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — सूचना टेम्पलेट (code, name, channels, title_template, body_template, variables)

---

## तीन, API डिज़ाइन मानक

### संस्करण प्रबंधन

API संस्करण HTTP अनुरोध हेडर `X-Api-Version` द्वारा निर्दिष्ट होता है, URL पथ में नहीं। सर्वर मिडलवेयर के माध्यम से संस्करण हेडर को आंतरिक रूट में इंजेक्ट करता है।

```
अनुरोध:  GET /api/auth/login
अनुरोध हेडर: X-Api-Version: v1

आंतरिक रूट → /api/auth/login → कंट्रोलर
प्रतिक्रिया हेडर: X-Api-Version: v1
```

**समर्थित संस्करण**: `v1` (डिफ़ॉल्ट, अनुरोध हेडर अनुपस्थित होने पर स्वचालित रूप से उपयोग)

**संस्करण नियंत्रण तंत्र**: `VersionMiddleware` सभी `/api/*` और `/admin/api/*` पथों पर `X-Api-Version` अनुरोध हेडर सत्यापित करता है, अनुपस्थित होने पर डिफ़ॉल्ट `v1`, असमर्थित संस्करण `400` लौटाता है। URL पथ में संस्करण संख्या शामिल नहीं रहती।

**नया संस्करण जोड़ने के चरण**:
1. `VersionMiddleware::SUPPORTED` सरणी में संस्करण संख्या जोड़ें
2. `route.php` में नए संस्करण का रूट समूह पंजीकृत करें
3. कंट्रोलर `$request->properties['api_version']` के माध्यम से संस्करण प्राप्त कर विभेदित प्रसंस्करण करें

### RESTful रूटिंग

```
एकीकृत प्रीफ़िक्स: /api
प्रशासन पैनल: /admin/api
```

**रूट समूह और मिडलवेयर मैट्रिक्स:**

| रूट समूह | मिडलवेयर | एंडपॉइंट उदाहरण |
|--------|--------|---------|
| सार्वजनिक (बिना प्रीफ़िक्स) | वैश्विक मिडलवेयर श्रृंखला | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | वैश्विक + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (उपयोगकर्ता) | वैश्विक + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (संवेदनशील) | वैश्विक + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | वैश्विक + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (संवेदनशील) | वैश्विक + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### एकीकृत प्रतिक्रिया प्रारूप

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

### प्रमाणीकरण योजना

| अंत | विधि |
|----|------|
| उपयोगकर्ता अंत | JWT (access_token 2h + refresh_token 30d) + TOTP दो-चरणीय सत्यापन + पुनर्प्राप्ति कोड |
| प्रशासन अंत | JWT (access_token 2h + refresh_token 7d) |
| आपूर्तिकर्ता API | API Key (sk_ प्रीफ़िक्स, SHA256 हैश संग्रहीत, केवल निर्माण के समय एक बार दिखाई जाती है) |
| क्लाउड प्रदाता कॉलबैक | हस्ताक्षर सत्यापन (HMAC-SHA256) |

**लागू किए गए प्रमाणीकरण फ़ीचर**:
- ईमेल पंजीकरण + ईमेल सत्यापन लिंक
- फ़ोन नंबर पंजीकरण + Twilio SMS सत्यापन कोड (60s कूलडाउन + IP दर सीमा 5 बार/घंटा)
- Google OAuth लॉगिन / Apple Sign In
- पासवर्ड भूल गए (ईमेल सत्यापन कोड + Redis 10min TTL)
- TOTP दो-चरणीय सत्यापन (QR कोड स्कैन सेटअप, पुनर्प्राप्ति कोड बैकअप)
- सक्रिय सत्र प्रबंधन (लॉगिन डिवाइस देखना/रद्द करना, client_platform जानकारी सहित)
- खाता विलोपन GDPR (पासवर्ड पुष्टि + सॉफ्ट डिलीट + सभी token रद्द)
- लॉगिन असामान्यता अलर्ट (नए IP लॉगिन पर ईमेल सूचना)
- लॉगिन लॉक (5 बार विफलता पर 15 मिनट लॉक)

**उपयोगकर्ता प्रमाणीकरण प्रवाह:**

```
पंजीकरण प्रवाह                         लॉगिन प्रवाह
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(क्लिक स्थिति)}          ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF स्कैन]                         → [WAF स्कैन]
   → [दर सीमा: 3 req/min]                  → [दर सीमा: 5 req/min]
   → [पासवर्ड bcrypt(cost=12)]             → [Hash::check()]
   → [डिवाइस फ़िंगरप्रिंट: sha256(UA+IP)]           → [डिवाइस फ़िंगरप्रिंट: sha256(UA+IP)]
   → [client_platform रिकॉर्ड]              → [client_platform रिकॉर्ड]
   → User::create()                    → [5 बार विफल → 15min लॉक]
   → RefreshToken::create()            → [नया IP पता → ईमेल अलर्ट]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (सत्यापन ईमेल)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google प्राधिकरण → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. Google token सत्यापित करें
                                      5. नया उपयोगकर्ता बनाएं या खोजें
                                      6. token जारी करें (client_platform सहित)
                                      7. AuditLogger::record('user_oauth_login')

TOTP दो-चरणीय सत्यापन                  सत्र प्रबंधन
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← सफल
    या → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → पासवर्ड पुष्टि + सॉफ्ट डिलीट + सभी token रद्द
लॉगिन लॉक तंत्र
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### बहुभाषा योजना

- अनुरोध हेडर: Accept-Language: zh-CN / en-US / ja-JP
- JSON कॉलम बहुभाषा टेक्स्ट संग्रहीत करते हैं: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n फ़ाइलें स्थिर टेक्स्ट प्रबंधित करती हैं, फ्रंटएंड और बैकएंड प्रत्येक की अपनी एक सेट

---

## चार, सुरक्षा सुरक्षा प्रणाली

### स्तरित सुरक्षा मॉडल

```
┌─────────────────────────────────────────────────────┐
│ पहली परत: नेटवर्क सीमा सुरक्षा                            │
│   DDoS शमन / WAF / IP ब्लैक-व्हाइटलिस्ट / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ दूसरी परत: परिवहन और अनुप्रयोग सुरक्षा                          │
│   HTTPS+TLS1.3 / CSP / CORS / JWT प्रमाणीकरण / दर सीमा          │
├─────────────────────────────────────────────────────┤
│ तीसरी परत: डेटा और स्टोरेज सुरक्षा                          │
│   एन्क्रिप्टेड स्टोरेज / मास्किंग / ऑडिट लॉग / बैकअप                     │
├─────────────────────────────────────────────────────┤
│ चौथी परत: वर्चुअलाइज़ेशन और संसाधन अलगाव                          │
│   Proxmox सुरक्षा सुदृढ़ीकरण / VM के बीच अलगाव / नेटवर्क अलगाव          │
├─────────────────────────────────────────────────────┤
│ पाँचवीं परत: संचालन और जोखिम नियंत्रण                          │
│   संचालन ऑडिट / असामान्यता पहचान / अलर्ट / आपातकालीन प्रतिक्रिया                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 नेटवर्क सीमा सुरक्षा

#### DDoS सुरक्षा

```
उपयोगकर्ता अनुरोध → CDN (Cloudflare / Aliyun CDN)
              │
              ├── JS चुनौती / कैप्चा (संदिग्ध ट्रैफ़िक)
              ├── दर सीमा (प्रति IP प्रति सेकंड अनुरोध संख्या)
              ├── क्षेत्रीय अवरोधन (निर्दिष्ट देश/क्षेत्र ब्लॉक)
              │
              ▼
           मूल सर्वर (Nginx + webman)
```

| स्तर | उपाय | विवरण |
|------|------|------|
| CDN स्तर | स्वचालित DDoS शमन | Cloudflare मुफ़्त योजना L3/L4 सुरक्षा का समर्थन करती है |
| CDN स्तर | Bot Management | दुर्भावनापूर्ण क्रॉलर/ऑर्डर-स्पैम स्क्रिप्ट पहचान और अवरोधन |
| Nginx स्तर | limit_req_zone | प्रति IP 10 req/s, सीमा से अधिक पर 429 |
| Nginx स्तर | limit_conn | प्रति IP अधिकतम 20 समवर्ती कनेक्शन |
| webman स्तर | टोकन बकेट दर सीमा मिडलवेयर | उपयोगकर्ता/इंटरफ़ेस ग्रैन्युलैरिटी पर सटीक दर सीमा |

#### WAF नियम (webman मिडलवेयर)

WAF मिडलवेयर 8 श्रेणियों के regex नियम समूहों द्वारा अनुरोध स्कैन करता है, नियम `config/security.php` में कॉन्फ़िगर होते हैं, बिना रीस्टार्ट गर्म-अपडेट होते हैं। स्कैन क्षेत्र अनुरोध बॉडी JSON, URL पथ+क्वेरी स्ट्रिंग, User-Agent, मूल अनुरोध बॉडी (JSON एन्कोडिंग बाइपास रोधन) को कवर करता है।

**8 श्रेणी के पहचान नियम (45+):**

| श्रेणी | कवरेज |
|------|---------|
| SQL इंजेक्शन | एकल उद्धरण/टिप्पणी चिह्न, SQL कीवर्ड, हेक्साडेसिमल एन्कोडिंग, UNION क्वेरी विविधताएँ, हमेशा-सत्य शर्तें (`' OR '1'='1`), समय-आधारित ब्लाइंड (`sleep`/`benchmark`), स्टैक्ड क्वेरी, मल्टीलाइन टिप्पणी बाइपास |
| XSS | HTML टैग (एन्कोडिंग विविधताओं सहित), Script टैग और विविधताएँ, 13 JS इवेंट हैंडलर, JS वैश्विक ऑब्जेक्ट/खतरनाक फ़ंक्शन, `javascript:` स्यूडो-प्रोटोकॉल, HTML एंटिटी एन्कोडिंग, Data URI इंजेक्शन, इनलाइन इवेंट गुण |
| कमांड इंजेक्शन | पाइप के बाद कमांड (`\| cat`), सेमीकोलन के बाद कमांड (`; whoami`), `$(cmd)` और बैकटिक कमांड प्रतिस्थापन, पृथक कमांड कीवर्ड |
| फ़ाइल इन्क्लूज़न | पथ ट्रैवर्सल (बहु-एन्कोडिंग), PHP स्यूडो-प्रोटोकॉल (`php://`/`data://`/`phar://`), निरपेक्ष पथ जाँच (`/etc/`/`C:\`), Null byte इंजेक्शन |
| HTTP हेडर इंजेक्शन | CRLF न्यूलाइन इंजेक्शन (`%0d%0a`/`\r\n`), Host/Cookie/Set-Cookie हेडर इंजेक्शन |
| **SSRF** | आंतरिक नेटवर्क IPv4 पते (127.x/10.x/172.16-31.x/192.168.x), localhost उपनाम, क्लाउड metadata एंडपॉइंट (169.254.169.254), file:// प्रोटोकॉल |
| **NoSQL इंजेक्शन** | MongoDB ऑपरेटर ($where/$gt/$regex/$or आदि), $where JS इंजेक्शन, Redis खतरनाक कमांड (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **खुला रीडायरेक्ट** | redirect_uri/return_url/next/callback आदि पैरामीटर की बाहरी URL जाँच, डबल एन्कोडिंग बाइपास |

**अनुरोध-स्तरीय सुरक्षा:**

| सुरक्षा आइटम | उपाय |
|--------|------|
| अनुरोध बॉडी आकार सीमा | अधिकतम 10MB (से अधिक पर 413) |
| URL लंबाई सीमा | अधिकतम 2KB (से अधिक पर 414, ReDoS रोधन) |
| Content-Type व्हाइटलिस्ट | केवल application/json, multipart/form-data, application/x-www-form-urlencoded |

**WAF पहचान प्रवाह:**

```
अनुरोध प्रवेश
  │
  ▼
1. स्कैन किए जाने वाले टेक्स्ट प्राप्त करें
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # अनुरोध बॉडी
   │     └── false → serialize() फॉलबैक
   ├── mb_substr(path + queryString, 0, 2048)                # URL (ReDoS रोधन ट्रंकेशन)
   ├── User-Agent हेडर                                          # UA
   └── file_get_contents('php://input')                      # मूल बॉडी (JSON एन्कोडिंग बाइपास रोधन)
  │
  ▼
2. नियम लोड करें (config/security.php से)
   ├── security.waf.sqli_patterns               (9 नियम)
   ├── security.waf.xss_patterns                (8 नियम)
   ├── security.waf.cmd_injection_patterns      (5 नियम)
   ├── security.waf.file_inclusion_patterns     (4 नियम)
   ├── security.waf.header_injection_patterns   (2 नियम)
   ├── security.waf.ssrf_patterns               (6 नियम)
   ├── security.waf.nosql_injection_patterns    (3 नियम)
   └── security.waf.open_redirect_patterns      (2 नियम)
   → array_merge() + array_unique()
  │
  ▼
3. एक-एक करके मिलान करें
   foreach patterns as pattern:
     match($pattern, $input) ───→ हिट → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ हिट → 403 "Request blocked by WAF" लौटाएं
     match($pattern, $ua)    ───→ हिट →
     match($pattern, $raw)   ───→ हिट →
  │
  ▼
4. match() सख्त जाँच
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → हिट ✓
   ├── $result === 0    → हिट नहीं (सुरक्षित अनुमति)
   └── $result === false → पैटर्न त्रुटि → error_log() → हिट नहीं मानें
  │
  ▼
5. सभी हिट नहीं → $next($request) अगले मिडलवेयर तक अनुमति
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

        // config/security.php से 8 श्रेणी के नियम लोड करें
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

#### IP ब्लैक-व्हाइटलिस्ट

```
ब्लैकलिस्ट:
- ज्ञात दुर्भावनापूर्ण IP डेटाबेस (AbuseIPDB नियमित सिंक)
- बार-बार WAF नियम ट्रिगर करने वाले IP (स्वचालित जोड़, Redis TTL 24h)
- ब्रूट-फोर्स लॉगिन IP (5 बार विफल → 30min लॉक)

व्हाइटलिस्ट:
- Proxmox होस्ट मशीन IP
- क्लाउड प्रदाता कॉलबैक IP रेंज
- भुगतान गेटवे webhook IP रेंज
- प्रशासक कार्यालय नेटवर्क IP (वैकल्पिक)
```

#### Geo-Blocking

```php
// GeoIP2 लाइब्रेरी (MaxMind)
$country = geoip($request->getRealIp());

// कॉन्फ़िगर करने योग्य ब्लॉक सूची
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 परिवहन और अनुप्रयोग सुरक्षा

#### वैश्विक मिडलवेयर निष्पादन श्रृंखला

सभी HTTP अनुरोध निम्नलिखित क्रम में मिडलवेयर से गुजरते हैं, प्रत्येक मिडलवेयर स्वतंत्र रूप से परीक्षण योग्य है:

```
अनुरोध → VersionMiddleware        # X-Api-Version सत्यापन (अनुपस्थित पर डिफ़ॉल्ट v1, अमान्य पर 400)
     → CorsMiddleware            # CORS क्रॉस-ओरिजिन प्रतिक्रिया हेडर
     → ClientPlatformMiddleware  # X-Client-Platform पहचान (8 प्लेटफ़ॉर्म), $request->properties में इंजेक्ट
     → WafMiddleware             # 8 श्रेणी 45+ नियम सुरक्षा स्कैन (SQLi/XSS/कमांड इंजेक्शन/फ़ाइल इन्क्लूज़न/हेडर इंजेक्शन/SSRF/NoSQL/खुला रीडायरेक्ट)
     → LocaleMiddleware          # Accept-Language पार्सिंग, क्षेत्र सेट करें
     → HashidRequestMiddleware   # अनुरोध पैरामीटर hashid → वास्तविक ID डिकोड
     → MaintenanceMiddleware     # रखरखाव मोड (IP व्हाइटलिस्ट अनुमति)
     ↓
  [रूट मिडलवेयर — रूट समूह के अनुसार जुड़ते हैं]
     → EncryptionMiddleware      # AES-256-GCM अनुरोध/प्रतिक्रिया बॉडी एन्क्रिप्शन
     → Captcha                   # क्लिक कैप्चा सत्यापन (लॉगिन/पंजीकरण से पहले)
     → AuthMiddleware            # JWT Bearer Token सत्यापन + भूमिका इंजेक्शन
     → AdminRoleMiddleware       # प्रशासक RBAC अनुमति जाँच
     → ConfirmationMiddleware    # संवेदनशील संचालन द्वितीयक पासवर्ड पुष्टि (5 बार विफल 15min लॉक)
     ↓
     कंट्रोलर
```

#### प्रत्येक मिडलवेयर की ज़िम्मेदारी

| मिडलवेयर | पंजीकरण विधि | ज़िम्मेदारी |
|--------|---------|------|
| `VersionMiddleware` | वैश्विक | `X-Api-Version` अनुरोध हेडर सत्यापित करें, अनुपस्थित पर डिफ़ॉल्ट `v1`, असमर्थित संस्करण पर `400` |
| `CorsMiddleware` | वैश्विक | OPTIONS प्रीफ़्लाइट संभालना, Origin को `Access-Control-Allow-Origin` में परावर्तित करना |
| `ClientPlatformMiddleware` | वैश्विक | `X-Client-Platform` अनुरोध हेडर सत्यापित करें, क्लाइंट OS प्लेटफ़ॉर्म पहचानें (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), `$request->properties['client_platform']` में इंजेक्ट करें |
| `WafMiddleware` | वैश्विक(service) + admin इंस्टेंस | 8 श्रेणी 45+ नियम + अनुरोध आकार सीमा + Content-Type सत्यापन, अवरोधन के बाद ऑडिट लॉग रिकॉर्ड |
| `LocaleMiddleware` | वैश्विक | `Accept-Language` हेडर पार्स करें, बहुभाषा क्षेत्र सेट करें |
| `HashidRequestMiddleware` | वैश्विक | अनुरोध में hashid स्ट्रिंग को वास्तविक पूर्णांक ID में स्वचालित डिकोड |
| `MaintenanceMiddleware` | वैश्विक | `MAINTENANCE_MODE` पर्यावरण चर जाँचें, व्हाइटलिस्ट IP अनुमति |
| `EncryptionMiddleware` | रूट समूह (/api/auth, /api, /admin/api) | AES-256-GCM अनुरोध/प्रतिक्रिया बॉडी एन्क्रिप्शन, `X-Encrypted: 1` हेडर द्वारा ट्रिगर |
| `AuthMiddleware` | रूट समूह (/api, /admin/api) | JWT HS256 access_token सत्यापन, `$request->userId` और `$request->userRole` इंजेक्ट |
| `AdminRoleMiddleware` | रूट समूह (/admin/api) | प्रशासक RBAC अनुमति जाँच |
| `ConfirmationMiddleware` | रूट समूह (संवेदनशील संचालन) | द्वितीयक पासवर्ड पुष्टि, Redis विफलता काउंटर, 5 बार 15 मिनट लॉक |

#### ClientPlatform मिडलवेयर विवरण

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // केवल API रूट पर प्रभावी
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

        // नीचे के उपयोग के लिए अनुरोध गुण इंजेक्ट करें (ऑडिट लॉग, सत्र रिकॉर्ड)
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**डेटा प्रवाह**: मिडलवेयर इंजेक्शन → `AuditLogger` स्वचालित रिकॉर्ड → `AuthService::issueTokens()` `refresh_tokens` में लिखता है → `GET /api/user/sessions` प्लेटफ़ॉर्म जानकारी लौटाता है

#### HTTPS अनिवार्यता

```nginx
# Nginx कॉन्फ़िगरेशन
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

#### JWT सुरक्षा सुदृढ़ीकरण

```
- access_token वैधता 2h, refresh_token वैधता 30d
- कुंजी RSA256 (असममित), नियमित रोटेशन (90 दिन)
- jti (JWT ID) सक्रिय रद्दीकरण के लिए Redis में संग्रहीत
- refresh_token डिवाइस फ़िंगरप्रिंट से बंधा (User-Agent + IP रेंज)
- refresh_token फिर से जारी करने पर पुराना token तुरंत अमान्य (rotation)
- संवेदनशील संचालन (भुगतान/संसाधन विनाश) के लिए द्वितीयक सत्यापन आवश्यक

डिवाइस फ़िंगरप्रिंट:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token तालिका इस फ़िंगरप्रिंट को रिकॉर्ड करती है, फिर से जारी करते समय सत्यापित किया जाता है
```

#### पासवर्ड नीति

```
- bcrypt एन्क्रिप्शन, cost factor = 12
- न्यूनतम 8 अक्षर, अपर और लोअर केस अक्षर + अंक अनिवार्य
- पंजीकरण/लॉगिन लगातार 5 बार विफल → खाता 15 मिनट लॉक
- पासवर्ड बदलने के बाद, सभी जारी किए गए token तुरंत अमान्य
- TOTP दो-चरणीय सत्यापन समर्थित (उपयोगकर्ता वैकल्पिक रूप से सक्षम कर सकता है)
```

#### CORS नीति

```php
// webman मिडलवेयर
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

#### फ़ाइल अपलोड सुरक्षा

```
- एक्सटेंशन व्हाइटलिस्ट सत्यापन (केवल अनुमत: jpg, jpeg, png, pdf, gif)
- फ़ाइल MIME प्रकार सत्यापन (नकली Content-Type की अनुमति नहीं)
- फ़ाइल आकार सीमा: अवतार 2MB, KYC दस्तावेज़ 5MB, अनुलग्नक 10MB
- अपलोड के बाद पुनः नामकरण: {uuid}.{ext}, मूल फ़ाइल नाम नहीं रखा जाता
- चित्र द्वितीयक प्रसंस्करण: GD/Imagick EXIF + मेटाडेटा हटाते हैं
- स्टोरेज पथ वेब से अप्राप्य निर्देशिका में, PHP प्रॉक्सी के माध्यम से पढ़ा जाता है
- वायरस स्कैन: ClamAV (KYC दस्तावेज़/उपयोगकर्ता अपलोड फ़ाइलें)
```

---

### 4.3 डेटा और स्टोरेज सुरक्षा

#### संवेदनशील डेटा एन्क्रिप्शन

```
एन्क्रिप्शन एल्गोरिथ्म: AES-256-GCM (प्रमाणीकरण सहित एन्क्रिप्शन, छेड़छाड़ रोधक)
कुंजी प्रबंधन: मास्टर कुंजी पर्यावरण चर में, प्रत्येक फ़ील्ड के लिए स्वतंत्र व्युत्पन्न कुंजी

एन्क्रिप्टेड संग्रहीत करने वाले फ़ील्ड:
| डेटा प्रकार | फ़ील्ड | एन्क्रिप्शन विधि |
|----------|------|----------|
| पासवर्ड | users.password_hash | bcrypt (एकतरफ़ा) |
| भुगतान कुंजी | payment_channels.api_key | AES-256-GCM |
| क्लाउड प्रदाता कुंजी | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC दस्तावेज़ संख्या | user_kyc.id_number | AES-256-GCM |
| भुगतान खाता | निकासी खाता | AES-256-GCM |
| लॉगिन पासवर्ड (VNC) | resource_servers.login_password | AES-256-GCM |

कुंजी व्युत्पत्ति:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### लॉग मास्किंग

```php
class LogSanitizer
{
    // स्वचालित मास्किंग वाले फ़ील्ड नाम पैटर्न
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

// Monolog Processor लॉग लिखने से पहले स्वचालित रूप से कॉल करता है
```

#### डेटाबेस सुरक्षा

```
- MySQL prepared statement उपयोग करता है (Eloquent स्वचालित)
- डेटाबेस एक्सेस खाते न्यूनतम विशेषाधिकार सिद्धांत:
  - app_user: SELECT, INSERT, UPDATE, DELETE (कोई DDL नहीं)
  - migration_user: DDL अनुमति (केवल माइग्रेशन के समय, IP सीमित)
  - read_user: SELECT केवल-पठनीय (रिपोर्ट/डेटा विश्लेषण के लिए)
- कनेक्शन SSL/TLS उपयोग करता है (PHP PDO SSL options)
- डेटाबेस पोर्ट सार्वजनिक नेटवर्क पर नहीं खुलता (केवल आंतरिक नेटवर्क)
- नियमित बैकअप: पूर्ण बैकअप 1 दिन, binlog वास्तविक समय सिंक
```

#### डेटा बैकअप और पुनर्प्राप्ति

```
बैकअप नीति:
- MySQL: दैनिक पूर्ण + binlog वास्तविक समय वृद्धिशील
- Redis: RDB हर घंटे + AOF वास्तविक समय पर्सिस्टेंस
- उपयोगकर्ता अपलोड फ़ाइलें: S3/OSS स्वचालित बहु-प्रतिलिपि + क्रॉस-क्षेत्र प्रतिकृति
- Proxmox VM स्नैपशॉट: साप्ताहिक (4 सप्ताह रखना)
- बैकअप एन्क्रिप्शन: AES-256 एन्क्रिप्शन के बाद संग्रहीत

पुनर्प्राप्ति अभ्यास:
- हर तिमाही आपदा पुनर्प्राप्ति अभ्यास
- पुनर्प्राप्ति समय लक्ष्य (RTO): < 4 घंटे
- पुनर्प्राप्ति बिंदु लक्ष्य (RPO): < 1 घंटा
```

---

### 4.4 वर्चुअलाइज़ेशन और संसाधन अलगाव

#### Proxmox सुरक्षा सुदृढ़ीकरण

```
1. API एक्सेस नियंत्रण:
   - Proxmox API केवल आंतरिक नेटवर्क IP पर सुनता है (सार्वजनिक से बंधा नहीं)
   - Token विशेषाधिकार न्यूनतमकरण: प्रत्येक role को केवल आवश्यक अनुमति
   - API पोर्ट (8006) केवल PHP एप्लिकेशन सर्वर IP को अनुमति देता है (iptables)

2. SSH सुदृढ़ीकरण:
   - पासवर्ड लॉगिन अक्षम, केवल कुंजी प्रमाणीकरण
   - root लॉगिन अक्षम, समर्पित प्रबंधन खाता उपयोग करें
   - SSH पोर्ट गैर-मानक पोर्ट में बदलें (स्कैनिंग कम करें)
   - Fail2ban: 5 बार विफलता पर 1 घंटा लॉक

3. सिस्टम अपडेट:
   - Proxmox सुरक्षा अपडेट ईमेल सूची सब्सक्राइब करें
   - नियमित apt update && apt upgrade
   - कर्नेल livepatch (Canonical Livepatch Service)

4. फ़ायरवॉल (iptables/nftables):
   - डिफ़ॉल्ट रूप से सभी इनबाउंड अस्वीकृत
   - केवल खुला: 8006 (केवल एप्लिकेशन सर्वर IP), SSH पोर्ट (केवल प्रबंधन IP)
   - VM ब्रिज और होस्ट प्रबंधन नेटवर्क का अलगाव
```

#### VM के बीच अलगाव

```
- प्रत्येक VM स्वतंत्र वर्चुअल ब्रिज VLAN उपयोग करता है
- VM के बीच संचार निषिद्ध (Proxmox फ़ायरवॉल नियम + VLAN अलगाव)
- उपयोगकर्ता केवल सार्वजनिक IP के माध्यम से अपने VM तक पहुँच सकते हैं
- VM संसाधन सीमाएँ (cgroup): एकल VM को होस्ट संसाधन समाप्त करने से रोकें
  - CPU limit: खरीदे गए कोर की ऊपरी सीमा
  - RAM limit: खरीदी गई क्षमता की ऊपरी सीमा
  - Disk IOPS limit: डिस्क प्रतिस्पर्धा रोकें
  - Network bandwidth limit: खरीदी गई बैंडविड्थ की ऊपरी सीमा
```

#### IP आवंटन सुरक्षा

```
- IP आवंटन रिकॉर्ड पूर्ण ऑडिट (किसने, कब, कौन सा IP आवंटित किया)
- IP रिलीज़ के बाद 24h कूलडाउन (IP को तुरंत किसी और को आवंटित करने से दुरुपयोग रोकें)
- IP ब्लैकलिस्ट: शिकायत/दुरुपयोग वाले IP को आवंटन योग्य नहीं चिह्नित करें
- IP उपयोग निगरानी: नियमित जाँच कि आवंटित IP सामान्य रूप से उपयोग में है
```

---

### 4.5 भुगतान सुरक्षा

```
1. PCI DSS अनुपालन:
   - क्रेडिट कार्ड डेटा स्वयं के सर्वर से नहीं गुजरता (Stripe Elements / Checkout)
   - card_token Stripe फ्रंटएंड द्वारा सीधे बनता है, बैकएंड केवल token प्राप्त करता है
   - लॉग/डेटाबेस में कोई CVV/पूरा कार्ड नंबर संग्रहीत नहीं

2. क्रिप्टोकरेंसी:
   - प्राप्ति निजी कुंजी कोल्ड स्टोरेज (ऑफ़लाइन हस्ताक्षर)
   - हॉट वॉलेट में केवल दैनिक कार्यशील राशि
   - प्राप्ति पता बनने के बाद चेकसम सत्यापन
   - बड़े लेनदेन (> $10000) मानव समीक्षा के बाद मैनुअल पुष्टि

3. भुगतान धोखाधड़ी रोधन:
   - समान उपयोगकर्ता/IP द्वारा थोड़े समय में उच्च-आवृत्ति भुगतान → जोखिम फ़्रीज़
   - नए पंजीकृत उपयोगकर्ता द्वारा बड़ी राशि का भुगतान → मानव समीक्षा
   - असामान्य भुगतान राशि (उत्पाद मूल्य से मेल नहीं खाती) → अवरोधन
   - अत्यधिक रिफ़ंड दर वाले उपयोगकर्ता → जोखिम चिह्नित

4. कॉलबैक हस्ताक्षर सत्यापन:
   - Stripe: webhook signature सत्यापन (stripe-signature header)
   - Coinbase: webhook signature सत्यापन (X-CC-Webhook-Signature header)
   - Alipay: notify_id कॉलबैक Alipay सर्वर द्वितीयक पुष्टि
   - सभी कॉलबैक: IP ज्ञात भुगतान गेटवे IP रेंज का है या नहीं सत्यापन
```

#### रिफ़ंड सुरक्षा

```
- रिफ़ंड के लिए दो-स्तरीय अनुमोदन आवश्यक (ग्राहक सेवा शुरू करें → प्रशासक पुष्टि)
- रिफ़ंड से पहले सत्यापन: ऑर्डर स्थिति, रिफ़ंड समय सीमा, रिफ़ंड संख्या
- रिफ़ंड राशि मूल ऑर्डर की वास्तविक भुगतान राशि से अधिक नहीं हो सकती
- मूल मार्ग वापसी: भुगतान चैनल रिफ़ंड इंटरफ़ेस + शेष राशि वापसी
- रिफ़ंड म्यूटेक्स लॉक (Redis): समवर्ती डुप्लिकेट रिफ़ंड रोकें
```

---

### 4.6 एक्सेस नियंत्रण और अनुमतियाँ

#### RBAC मॉडल

```
भूमिका पदानुक्रम:
  super_admin    (सुपर प्रशासक — सभी अनुमतियाँ)
  admin          (प्रशासक — सिस्टम कॉन्फ़िगरेशन को छोड़कर सब कुछ)
  finance        (वित्त — भुगतान/निपटान/रिफ़ंड/सेटलमेंट)
  support        (ग्राहक सेवा — उपयोगकर्ता/ऑर्डर/टिकट प्रबंधन)
  supplier       (आपूर्तिकर्ता — अपने उत्पाद/ऑर्डर/निपटान)
  user           (सामान्य उपयोगकर्ता — अपने संसाधन/ऑर्डर/टिकट)

अनुमति परिभाषा:
  {module}.{action}
  उदा: order.view, order.create, order.refund, resource.destroy

अनुमति जाँच मिडलवेयर:
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

#### API दर सीमा

```php
// webman दर सीमा मिडलवेयर (Redis टोकन बकेट)
class RateLimitMiddleware
{
    // डिफ़ॉल्ट: 60 req/min प्रति उपयोगकर्ता
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // ब्रूट-फोर्स रोधन
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // थोक पंजीकरण रोधन
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // भुगतान दर सीमा
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API कॉल
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // अपलोड दर सीमा
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

#### आपूर्तिकर्ता डेटा अलगाव

```
डेटा अलगाव सिद्धांत:
- आपूर्तिकर्ता केवल अपने संसाधनों की क्वेरी और संचालन कर सकते हैं
- supplier_id से जुड़ी सभी क्वेरी में स्वचालित रूप से WHERE supplier_id = auth()->supplier_id जोड़ा जाता है

कार्यान्वयन विधि:
  // वैश्विक Scope
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
  
  // Product/Order आदि Model पर पंजीकरण
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 संचालन ऑडिट

```
ऑडिट लॉग रिकॉर्ड सामग्री:
- संचालक ID, IP, User-Agent
- संचालन समय
- संचालन मॉड्यूल (कौन सा मेनू/इंटरफ़ेस)
- संचालन प्रकार: निर्माण/संशोधन/विलोपन/निर्यात/अनुमोदन
- संचालन वस्तु: किस संसाधन का कौन सा फ़ील्ड
- संचालन से पहले मान / संचालन के बाद मान (फ़ील्ड-स्तरीय परिवर्तन)
- संचालन परिणाम: सफल/विफल
- अनुरोध ID (एंड-टू-एंड ट्रैकिंग)

रिकॉर्ड क्षेत्र:
- सभी प्रशासन अंत संचालन (100% रिकॉर्ड)
- उपयोगकर्ता अंत संवेदनशील संचालन: भुगतान/संसाधन विनाश/KYC सबमिशन/पासवर्ड परिवर्तन (100% रिकॉर्ड)
- लॉगिन/लॉगआउट (100% रिकॉर्ड)
- API Key निर्माण/रद्दीकरण (100% रिकॉर्ड)

स्टोरेज और प्रतिधारण:
- ऑडिट लॉग स्वतंत्र डेटाबेस (audit_db) में, एप्लिकेशन डेटाबेस से अलग
- कम से कम 1 वर्ष, वित्तीय संबंधित 3 वर्ष रखना
- अनुपालन समीक्षा के लिए CSV/JSON निर्यात समर्थित

ऑडिट लॉग मिडलवेयर:
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

### 4.8 जोखिम नियंत्रण नियम

```
वास्तविक समय जोखिम इंजन:

नियम 1: नए खाते का असामान्य व्यवहार
  शर्त: पंजीकरण समय < 24h AND (कुल भुगतान > $500 OR बनाए गए टिकट > 5)
  क्रिया: खाते को "निरीक्षण में" चिह्नित करें, जोखिम प्रशासक को सूचित करें

नियम 2: थोक पंजीकरण पहचान
  शर्त: समान IP द्वारा 24h में > 3 खाते पंजीकृत
  क्रिया: नए पंजीकरण अस्वीकृत, उस IP के नए खाते फ़्रीज़

नियम 3: भुगतान असामान्यता
  शर्त: समान उपयोगकर्ता द्वारा 1h में > 5 बार भुगतान विफल
  क्रिया: भुगतान फ़ंक्शन 2h फ़्रीज़, जोखिम टिकट बनाएं

नियम 4: रिफ़ंड दुरुपयोग
  शर्त: समान उपयोगकर्ता द्वारा 30 दिनों में > 3 रिफ़ंड OR रिफ़ंड दर > 20%
  क्रिया: उस खाते की रिफ़ंड अनुमति सीमित करें, नए ऑर्डर जोखिम समीक्षा चिह्नित

नियम 5: API दुरुपयोग
  शर्त: एकल token द्वारा 1h में > 10000 API कॉल
  क्रिया: उस token को डाउनग्रेड (दर सीमा कम), प्रशासक को सूचित करें

नियम 6: संसाधन दुरुपयोग
  शर्त: VM स्पैम/DDoS/माइनिंग की शिकायत (Abuse सूचना प्राप्त)
  क्रिया: स्वचालित शटडाउन, संसाधन फ़्रीज़, उच्च-प्राथमिकता टिकट बनाएं

जोखिम क्रियाएँ:
- फ़्लैग (flag): केवल रिकॉर्ड, उपयोग प्रभावित नहीं
- थ्रॉटल (throttle): दर सीमा सीमा कम करें
- फ़्रीज़ (freeze): विशिष्ट फ़ंक्शन अस्थायी रूप से अक्षम
- बैन (ban): खाता स्थायी रूप से प्रतिबंधित
```

---

### 4.9 आपातकालीन प्रतिक्रिया

```
सुरक्षा घटना वर्गीकरण:

P0 (आपातकाल) — डेटा लीक, धन हानि, प्लेटफ़ॉर्म डाउन
  → तुरंत CTO + सुरक्षा टीम को सूचित करें
  → 30 मिनट के भीतर आपातकालीन प्रतिक्रिया शुरू
  → प्रभावित ऊपरी-स्ट्रीम सेवाएँ ऑफ़लाइन, सबूत संरक्षित
  → मरम्मत के बाद 24h के भीतर घटना रिपोर्ट जारी

P1 (गंभीर) — एकल खाता चोरी, भुगतान धोखाधड़ी, WAF असामान्य वृद्धि
  → सुरक्षा प्रमुख को सूचित करें
  → 2h के भीतर संभालें
  → प्रभावित खाते/संसाधन फ़्रीज़

P2 (सामान्य) — भेद्यता स्कैन में मध्यम/निम्न जोखिम भेद्यताएँ, असामान्य लॉगिन अलर्ट
  → टिकट सिस्टम में दर्ज करें
  → अगले इटरेशन में ठीक करें

आपातकालीन संपर्क:
- P0/P1 अलर्ट ट्रिगर के बाद स्वचालित सूचना (ईमेल + SMS + फ़ोन)
- webman स्वास्थ्य जाँच एंडपॉइंट: GET /health (200 लौटाता है या अलर्ट)
- ड्यूटी तालिका: 7×24 रोटेशन, कम से कम 2 लोग बैकअप पर
```

---

## पाँच, संसाधन प्रोविज़निंग इंजन

### Provider प्लगइन आर्किटेक्चर

प्रत्येक क्लाउड उत्पाद प्रकार × क्लाउड प्रदाता का संयोजन, एकीकृत इंटरफ़ेस लागू करता है:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // भौतिक मशीन स्वयं-संचालन विशेष
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory (product_type, provider) के अनुसार विशिष्ट कार्यान्वयन तक रूट करता है:
- ProxmoxProvider (स्वयं-संचालित भौतिक मशीन: सर्वर/डेटा डिस्क/IP)
- AwsServerProvider / AliyunServerProvider (तृतीय-पक्ष क्लाउड सर्वर)
- GcpIpProvider (तृतीय-पक्ष IP)
- AzureDiskProvider (तृतीय-पक्ष क्लाउड डिस्क)
- NamecheapDomainProvider / GoDaddyDomainProvider (डोमेन)

### अतुल्यकालिक कार्य गारंटी

- Provisioning Worker provision_tasks तालिका पोल करता है
- provider के अनुसार समूहीकृत समवर्ती नियंत्रण (प्रत्येक provider अधिकतम 5 समवर्ती)
- पुनः प्रयास नीति: 1min → 5min → 15min → 1h → 6h → 24h (अधिकतम 6 बार)
- पुनः प्रयास योग्य न विफलता → अलर्ट + स्वचालित टिकट निर्माण

### ऑर्डर से संसाधन प्रोविज़निंग पूर्ण श्रृंखला

```
उपयोगकर्ता ऑर्डर                        भुगतान                             संसाधन प्रोविज़निंग
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid इवेंट
   → addToCart(sku, region, qty)          → पासवर्ड द्वितीयक पुष्टि (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                           भुगतान चैनल चुनें                   10. प्रत्येक OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. कूपन लागू करें                          createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. फ्रंटएंड confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe webhook कॉलबैक            12. Provider->create()
   → उपलब्ध भुगतान चैनल प्राप्त करें            → हस्ताक्षर सत्यापन + आइडेम्पोटेंसी जाँच          ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → OrderPaid इवेंट ट्रिगर               │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                        पुनः प्रयास नीति (विफलता पर)              │  startVM()
                                        ────────────────                      ├→ Resource रिकॉर्ड बनाएं
                                        1min → 5min → 15min                     └→ host_machine अपडेट करें
                                        → 1h → 6h → 24h                            आवंटित संसाधन मात्रा
                                        (6 बार के बाद विफल चिह्नित + अलर्ट)  13. Order::status = completed
                                                                           → NotificationDispatcher
                                        रिफ़ंड प्रवाह                                ::send('resource_ready')
                                        ────────
                                        उपयोगकर्ता आवेदन → ग्राहक सेवा समीक्षा → admin पुष्टि
                                        → provider.destroy()
                                        → payment.refund()
                                        → मूल मार्ग वापसी
```

### स्वयं-संचालित भौतिक मशीन योजना: Proxmox VE (सामुदायिक संस्करण)

स्वयं-संचालित सर्वर Proxmox VE (ओपन सोर्स मुफ़्त, AGPL v3) अपनाते हैं, PHP HTTP के माध्यम से Proxmox REST API कॉल करके KVM वर्चुअल मशीन जीवनचक्र और संसाधन आवंटन प्रबंधित करता है।

आर्किटेक्चर:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (उपयोगकर्ता को आवंटित)
```

#### ProxmoxApi क्लाइंट एनकैप्सुलेशन

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

#### संसाधन संचालन

**VM बनाएं (सर्वर):**
1. HostSelector पर्याप्त संसाधनों वाली होस्ट मशीन चुनता है (cpu/ram/disk शेष + लोड संतुलन के अनुसार क्रमबद्ध)
2. उस होस्ट मशीन के ip_pool से एक IP आवंटित करें
3. ProxmoxApi.post("/nodes/{node}/qemu") VM बनाएं (vmid, name, cores, memory, net0, ipconfig0 सेट करें)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") सिस्टम डिस्क माउंट करें (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") VM शुरू करें
6. host_machine.specs आवंटित मात्रा अपडेट करें (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**CPU अपग्रेड (ऑनलाइन):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // होस्ट मशीन संसाधन आँकड़े अपडेट करें
```

**मेमोरी अपग्रेड (ऑनलाइन):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**सिस्टम डिस्क विस्तार:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**अलग डेटा डिस्क बनाएं:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**अलग IP बनाएं:**
IP पूल से आवंटित करें → Proxmox API के माध्यम से वर्चुअल नेटवर्क कार्ड + IP कॉन्फ़िगर करें, या मौजूदा VM के अतिरिक्त नेटवर्क कार्ड के रूप में स्वतंत्र संसाधन बनाए रखें।

**VM नष्ट करें:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // शटडाउन
$api->delete("/nodes/{node}/qemu/{vmid}");             // VM हटाएं
releaseIp($resourceId);                                // IP पूल में वापस करें
$host->deallocate($specs);                             // होस्ट संसाधन पुनर्प्राप्त करें
```

#### होस्ट चयन रणनीति

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

#### संसाधन विभाजन संचालन सारांश

| संचालन | कार्यान्वयन विधि | गर्म संचालन |
|------|----------|--------|
| VM बनाएं (CPU+मेमोरी+सिस्टम डिस्क+IP) | Proxmox create qemu | — |
| अलग से CPU अपग्रेड | PUT config cores | ऑनलाइन |
| अलग से मेमोरी अपग्रेड | PUT config memory | ऑनलाइन |
| सिस्टम डिस्क विस्तार | PUT resize disk | ऑनलाइन (VM समर्थन आवश्यक) |
| अलग डेटा डिस्क बनाएं | POST config डिस्क जोड़ें | ऑनलाइन |
| अलग IP बनाएं | IP पूल से आवंटन + VM नेटवर्क कार्ड जोड़ें | ऑनलाइन |

### संसाधन जीवनचक्र

```
pending → active → destroyed (30 दिन रखना) → purged (अपरिवर्तनीय)
```

नवीनीकरण: active → (renew) → active (expired_at विस्तारित)
अपग्रेड: active → (upgrade) → upgrading → active

### संसाधन स्रोत

| स्रोत | वर्चुअलाइज़ेशन/API | उत्पाद प्रकार | विवरण |
|------|-----------|----------|------|
| स्वयं-संचालित भौतिक मशीन | Proxmox VE (सामुदायिक संस्करण) | सर्वर, डेटा डिस्क, IP | स्वयं के डेटा केंद्र होस्टिंग, PHP Proxmox API कॉल करता है |
| तृतीय-पक्ष क्लाउड प्रदाता | AWS/GCP/Aliyun/Huawei Cloud/Azure SDK | सर्वर, IP, क्लाउड डिस्क | तृतीय-पक्ष क्लाउड संसाधनों की पुनर्विक्रय |
| डोमेन रजिस्ट्रार | Namecheap/GoDaddy/Aliyun Wanwang API | डोमेन पंजीकरण/स्थानांतरण | डोमेन सेवा |

### पहले चरण का एकीकरण

| क्षेत्र | सर्वर | IP | क्लाउड डिस्क | डोमेन |
|------|--------|----|------|------|
| एशिया-प्रशांत | Aliyun, Huawei Cloud, AWS | Aliyun, GCP | Aliyun, Huawei Cloud | Aliyun Wanwang, Namecheap |
| यूरोप | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| उत्तरी अमेरिका | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## छह, भुगतान प्रणाली

### बहु-चैनल रूटिंग

PaymentRouter उपयोगकर्ता की मुद्रा वरीयता के अनुसार उपलब्ध चैनल क्वेरी करता है, प्रत्येक चैनल की वास्तविक भुगतान राशि (चैनल शुल्क सहित) की गणना करता है, भुगतान विकल्प सूची लौटाता है।

### भुगतान प्रवाह (Stripe)

```
उपयोगकर्ता अंत (Flutter)               सर्वर (webman)                Stripe API
───────────────               ──────────────                ──────────
1. Stripe भुगतान चुनें
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. payment_transaction बनाएं
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. उपयोगकर्ता भुगतान पुष्टि
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  हस्ताक्षर सत्यापन (stripe-signature)
                                  आइडेम्पोटेंसी जाँच (transaction_no)
                               8. transaction=success अपडेट करें
                               9. OrderPaid इवेंट ट्रिगर करें
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← भुगतान सफल पृष्ठ               ← ऑर्डर स्थिति लौटाएं
```

### क्रिप्टोकरेंसी भुगतान

1. उपयोगकर्ता मुद्रा चुनता है (जैसे USDT-TRC20)
2. बैकएंड Coinbase Commerce / BitPay API के माध्यम से प्राप्ति पता बनाता है
3. Worker हर 30s ब्लॉकचेन पुष्टि जाँचता है (या webhook)
4. प्राप्ति पुष्टि → OrderPaid इवेंट ट्रिगर

### विनिमय दर और बहु-मुद्रा

- विनिमय दर स्रोत exchangerate-api से नियमित रूप से खींचकर Redis में संग्रहीत
- उत्पाद मूल्य USD आधारित, अन्य मुद्राएँ वास्तविक समय में परिवर्तित
- ऑर्डर के समय विनिमय दर लॉक, रिफ़ंड के समय मूल दर पर वापसी

### भुगतान चैनल दृश्यता नियंत्रण

payment_channels तालिका फ़ील्ड:
- is_visible: उपयोगकर्ता अंत पर दिखाना है या नहीं
- visible_regions: दृश्यता सीमित क्षेत्र, खाली का अर्थ सभी
- min_amount / max_amount: ऑर्डर राशि सीमा

### निपटान

प्रतिदिन भोर में प्रत्येक चैनल की निपटान रिपोर्ट खींचें, सिस्टम transaction से लेन-देन-दर-लेन-देन मिलान करें, अंतर > $0.01 पर अलर्ट।

### रिफ़ंड नीति

- सर्वर/VPS: खरीद के 72h के भीतर पूर्ण रिफ़ंड
- डोमेन: पंजीकरण के 5 दिनों के भीतर रिफ़ंड योग्य (ICANN मानक)
- IP: खरीद के बाद रिफ़ंड योग्य नहीं
- क्लाउड डिस्क: सर्वर नीति के समान
- विशेष प्रचार उत्पाद: रिफ़ंड योग्य नहीं

रिफ़ंड प्रवाह: उपयोगकर्ता आवेदन → टिकट निर्माण → ग्राहक सेवा समीक्षा → admin पुष्टि → provider.destroy() → payment.refund() → मूल मार्ग वापसी

---

## सात, क्लाइंट पेज संरचना

### Flutter / HarmonyOS उपयोगकर्ता अंत

- **प्रमाणीकरण**: लॉगिन/पंजीकरण (ईमेल+पासवर्ड, Google OAuth, Apple ID, फ़ोन), पासवर्ड भूल गए, दो-चरणीय सत्यापन
- **होम**: क्षेत्र चयनकर्ता, उत्पाद श्रेणी प्रवेश, Banner/प्रचार, अनुशंसित उत्पाद
- **उत्पाद**: सूची (बहु-शर्त फ़िल्टर), विवरण (कॉन्फ़िगरेशन/क्षेत्र/मूल्य कैलकुलेटर), समीक्षाएँ
- **शॉपिंग और भुगतान**: कार्ट, ऑर्डर पुष्टि (भुगतान विधि/बिलिंग पता/शेष/कूपन कोड), चेकआउट, भुगतान परिणाम
- **मेरे संसाधन**: संसाधन सूची (स्थिति के अनुसार फ़िल्टर), विवरण संचालन (रीस्टार्ट/शटडाउन/नवीनीकरण/अपग्रेड/विनाश), कंसोल SSO, उपयोग चार्ट
- **ऑर्डर**: सूची (लंबित/भुगतान/पूर्ण/रिफ़ंड), विवरण, चालान
- **टिकट**: सूची, नया, वार्तालाप
- **व्यक्तिगत केंद्र**: प्रोफ़ाइल/KYC, शेष राशि और रिचार्ज, सूचनाएँ, पता प्रबंधन, भाषा/मुद्रा/सुरक्षा सेटिंग्स
- **सामान्य**: सहायता केंद्र, सेवा शर्तें, हमारे बारे में

### webman-admin प्रशासन पैनल

- **डैशबोर्ड**: सारांश + रुझान चार्ट
- **उपयोगकर्ता प्रबंधन**: सूची/विवरण/KYC समीक्षा
- **उत्पाद प्रबंधन**: श्रेणियाँ/सूची/मूल्य निर्धारण (SKU×क्षेत्र)/स्टॉक/समीक्षाएँ
- **ऑर्डर प्रबंधन**: सूची/विवरण/रिफ़ंड समीक्षा/चालान
- **भुगतान प्रबंधन**: चैनल कॉन्फ़िगरेशन/लेनदेन रिकॉर्ड/निपटान रिपोर्ट
- **संसाधन प्रबंधन**: सूची/प्रोविज़निंग कार्य निगरानी/क्लाउड प्रदाता API कॉन्फ़िगरेशन
- **आपूर्तिकर्ता प्रबंधन**: पंजीकरण समीक्षा/सूची/उत्पाद आवंटन/निपटान/निकासी
- **टिकट प्रबंधन**: कतार/मेरे टिकट/SLA निगरानी
- **डोमेन प्रबंधन**: TLD मूल्य निर्धारण/रजिस्ट्रार API/स्थानांतरण प्रबंधन
- **संदेश सूचनाएँ**: टेम्पलेट प्रबंधन/भेजने का रिकॉर्ड
- **सिस्टम सेटिंग्स**: प्रशासक और भूमिकाएँ/संचालन लॉग/बहुभाषा/विनिमय दर/क्षेत्र/सिस्टम पैरामीटर
- **रिपोर्ट**: राजस्व/आपूर्तिकर्ता निपटान/उत्पाद बिक्री विश्लेषण/क्षेत्र विश्लेषण

---

## आठ, संदेश सूचना प्रणाली

### चार चैनल

Email (SMTP/SendGrid) / SMS (Twilio/Aliyun SMS) / Push (FCM/HMS) / स्टेशन संदेश

### प्रवाह

इवेंट ट्रिगर → Notification Dispatcher → टेम्पलेट मिलान (इवेंट कोड+भाषा वरीयता) → उपयोगकर्ता वरीयता के अनुसार चैनलों में वितरण → Redis Queue अतुल्यकालिक भेजना

### सूचना प्रकार

पंजीकरण सत्यापन कोड, ऑर्डर भुगतान सफल, संसाधन प्रोविज़न पूर्ण, संसाधन समाप्ति अनुस्मारक (7d/3d/1d), टिकट उत्तर, रिफ़ंड पूर्ण, सुरक्षा अलर्ट, प्रचार गतिविधियाँ

### विफलता पुनः प्रयास

3 बार बैकऑफ़, webman redis-queue के माध्यम से प्रबंधित।

---

## नौ, आपूर्तिकर्ता प्रणाली

### पंजीकरण प्रवाह

पंजीकरण → कंपनी जानकारी+संपर्क+निपटान विधि जमा करें → प्रशासक समीक्षा → स्वीकृति के बाद उत्पाद सूचीबद्ध करें → admin उत्पाद समीक्षा → उपयोगकर्ता खरीद → स्वचालित बंटवारा → आपूर्तिकर्ता निकासी आवेदन → admin भुगतान

### अनुमति अलगाव

आपूर्तिकर्ता केवल अपने उत्पाद/ऑर्डर/निपटान रिपोर्ट/टिकट/निकासी रिकॉर्ड देख सकते हैं। प्लेटफ़ॉर्म राजस्व, अन्य आपूर्तिकर्ता डेटा, भुगतान चैनल कॉन्फ़िगरेशन नहीं देख सकते।

### बंटवारा नियम

- स्वयं-संचालित उत्पाद: commission_rate = 100% (सब प्लेटफ़ॉर्म को)
- तृतीय-पक्ष उत्पाद: commission_rate = 5%~20% (प्लेटफ़ॉर्म कटौती)
- निपटान सूत्र: ऑर्डर उत्पाद राशि - प्लेटफ़ॉर्म कटौती - चैनल शुल्क = आपूर्तिकर्ता को देय
- निपटान चक्र: साप्ताहिक / मासिक

### आपूर्तिकर्ता पूर्ण व्यावसायिक प्रवाह

```
आपूर्तिकर्ता पंजीकरण                        प्रशासक अनुमोदन
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → आपूर्तिकर्ता जानकारी समीक्षा करें
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → पासवर्ड पुष्टि करें
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← सफल
उत्पाद सूचीकरण
────────
POST /supplier/products                प्रशासक समीक्षा
  → {product_id, commission_rate}        → आपूर्तिकर्ता उत्पाद संबद्ध करें + कमीशन अनुपात सेट करें
  ← {supplier_product}                    → उत्पाद स्थिति: published

उपयोगकर्ता ऑर्डर ──→ भुगतान पूर्ण ──→ संसाधन प्रोविज़न ──→ ऑर्डर पूर्ण

नियमित निपटान (हर सोमवार 04:17)               निकासी
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → अवधि में पूर्ण ऑर्डरों का आँकड़ा            → पासवर्ड द्वितीयक पुष्टि (ConfirmationMiddleware)
  → total_sales - commission गणना        → SupplierService::requestWithdraw()
  → = payable                              → निकासी योग्य शेष जाँचें
  → SupplierSettlement बनाएं                 → SupplierWithdraw बनाएं (status:pending)
  → Webhook: settlement.created          ← सफल

प्रशासक भुगतान                             प्रशासक API Key प्रबंधन
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → sk_xxx बनाएं (SHA256 संग्रहीत)
  → पासवर्ड पुष्टि करें                      ← {api_key} (केवल एक बार दिखाया जाता है)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## दस, मॉनिटरिंग और संचालन

### संसाधन मॉनिटरिंग

- संग्रह मीट्रिक: CPU/मेमोरी/डिस्क/बैंडविड्थ उपयोग दर, IP कनेक्टिविटी, क्लाउड डिस्क IOPS, DNS रिज़ॉल्यूशन, SSL प्रमाणपत्र समाप्ति
- संग्रह विधि: Agent रिपोर्टिंग / SNMP (स्वयं) + क्लाउड प्रदाता मॉनिटरिंग API (तृतीय-पक्ष) + WHOIS/DNS पोलिंग (डोमेन)
- संग्रह चक्र: 5 मिनट, Prometheus + VictoriaMetrics स्टोरेज

### अलर्ट नियम

| अलर्ट इवेंट | गंभीरता | ट्रिगर शर्त |
|----------|--------|----------|
| सर्वर डाउन | गंभीर | लगातार 3 बार Ping अप्राप्य |
| CPU/मेमोरी > 90% | सूचना | 10 मिनट तक निरंतर |
| डिस्क > 90% | चेतावनी | 5 मिनट तक निरंतर |
| बैंडविड्थ > 80% | सूचना | 30 मिनट तक निरंतर |
| SSL प्रमाणपत्र < 30 दिन | चेतावनी | दैनिक जाँच |
| डोमेन < 30 दिन | चेतावनी | दैनिक जाँच |
| प्रोविज़निंग कार्य विफल | गंभीर | लगातार 2 बार विफल |
| भुगतान निपटान अंतर | गंभीर | एकल लेनदेन > $0.01 |

---

## ग्यारह, डिप्लॉयमेंट आर्किटेक्चर

### उत्पादन वातावरण

- एप्लिकेशन सर्वर × 2: webman (बहु-प्रक्रिया) + Nginx + Supervisor
- डेटाबेस: MySQL 8.0 मास्टर-स्लेव (1 मास्टर 2 स्लेव) + Redis Cluster
- कतार: webman redis-queue (भुगतान कॉलबैक/सूचना/प्रोविज़निंग कार्य)
- क्रॉन कार्य: Crontab (निपटान/सेटलमेंट/डोमेन जाँच/नवीनीकरण अनुस्मारक)
- स्टोरेज: S3/OSS + CDN
- लॉग मॉनिटरिंग: ELK/Loki + Prometheus + Grafana + Sentry

### निर्देशिका संरचना

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter क्लाइंट
│   └── harmonyos/         # HarmonyOS क्लाइंट (ArkTS)
├── service/               # webman सर्वर
│   ├── app/
│   │   ├── controller/    # कंट्रोलर (मॉड्यूल के अनुसार)
│   │   ├── service/       # व्यावसायिक तर्क (मॉड्यूल के अनुसार)
│   │   ├── model/         # डेटा मॉडल
│   │   ├── middleware/     # मिडलवेयर
│   │   ├── event/         # इवेंट परिभाषाएँ
│   │   ├── listener/      # इवेंट श्रोता
│   │   ├── queue/         # कतार कार्य
│   │   ├── provider/      # क्लाउड प्रदाता एडेप्टर
│   │   └── cron/          # क्रॉन कार्य
│   ├── common/            # साझा लाइब्रेरी (auth/payment/i18n/notification/helper)
│   ├── config/            # कॉन्फ़िगरेशन फ़ाइलें
│   ├── database/
│   │   └── migrations/    # डेटाबेस माइग्रेशन
│   └── storage/           # लॉग/कैश/अपलोड
├── admin/                 # webman-admin
├── docs/                  # दस्तावेज़
└── docker/                # Docker कॉन्फ़िगरेशन
```

### प्रमुख Composer निर्भरताएँ

workerman/webman-framework, webman/admin, webman/redis-queue, illuminate/database, firebase/php-jwt, stripe/stripe-php, phpseclib/phpseclib, monolog/monolog

### उच्च समवर्ती अनुकूलन

#### 1. MySQL रीड-राइट विभाजन

Eloquent स्वचालित रूप से SELECT को read कनेक्शन, INSERT/UPDATE/DELETE को write कनेक्शन तक रूट करता है।

```
कॉन्फ़िगरेशन (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (मास्टर)
  connections.mysql.read  → DB_READ_HOST  (स्लेव, लोड संतुलन के लिए एकाधिक कॉन्फ़िगर कर सकते हैं)
  sticky = true           → समान अनुरोध चक्र में लिखने के बाद पढ़ना मास्टर से जाता है (मास्टर-स्लेव विलंब रोधन)

पर्यावरण चर:
  DB_HOST=10.0.1.1          # मास्टर (लिखना)
  DB_READ_HOST=10.0.2.1     # स्लेव (पढ़ना), एकाधिक डिप्लॉय कर सकते हैं
```

**रीड-राइट रूटिंग नियम:**

| संचालन प्रकार | रूट लक्ष्य | उदाहरण |
|---------|---------|------|
| SELECT | read कनेक्शन | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | write कनेक्शन | `Order::create(...)` |
| लेनदेन में सभी संचालन | write कनेक्शन | `DB::transaction(...)` |
| लिखने के बाद पढ़ना (sticky) | write कनेक्शन | समान अनुरोध चक्र में |

#### 2. Redis बहु-स्तरीय कैश रणनीति

उच्च-आवृत्ति पठन डेटा के लिए `CacheService` का उपयोग करें, Redis अनुपलब्ध होने पर स्वचालित रूप से सीधे डेटाबेस क्वेरी में डाउनग्रेड।

```
कैश स्तरीकरण:
  L1: Redis (प्रक्रियाओं के बीच साझा, मिलीसेकंड स्तर)
  L2: MySQL (स्थायी, बैकअप)

कैश रणनीति:
  उत्पाद सूची        TTL 5min    region_id + category_id + keyword के अनुसार कुंजी विभाजन
  उत्पाद विवरण        TTL 10min   product_id के अनुसार कुंजी, सामग्री परिवर्तन पर सक्रिय अमान्यकरण
  क्षेत्र सूची        TTL 1h      क्षेत्र डेटा बहुत कम बदलता है
  विनिमय दर            TTL 30min   क्रॉन कार्य रिफ़्रेश + सक्रिय अपडेट
  TLD मूल्य निर्धारण   TTL 1h      TLD मूल्य परिवर्तन आवृत्ति कम
  सहायता लेख        TTL 10min   प्रकाशन/संशोधन पर सक्रिय अमान्यकरण
  उत्पाद श्रेणियाँ        TTL 10min   श्रेणी वृक्ष परिवर्तन पर सक्रिय अमान्यकरण

कैश वार्म-अप (डिप्लॉयमेंट के बाद):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

सक्रिय अमान्यकरण (डेटा परिवर्तन पर):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// उपयोग उदाहरण
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Nginx प्रतिक्रिया संपीड़न + दर सीमा

```
gzip संपीड़न:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  प्रभाव: JSON प्रतिक्रिया संपीड़न दर 70-85%, बैंडविड्थ बचत

proxy अनुकूलन:
  proxy_buffering on           # ऊपरी-स्ट्रीम प्रतिक्रिया बफ़र, धीमे क्लाइंट worker नहीं घेरते
  proxy_http_version 1.1       # HTTP/1.1 लंबे कनेक्शन पुन: उपयोग
  ऊपरी-स्ट्रीम तक keep-alive    # TCP हैंडशेक कम करें

दर सीमा:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  /health एंडपॉइंट पर कोई दर सीमा नहीं (access_log बंद करके I/O कम करें)
```

#### 4. डेटाबेस इंडेक्स अनुशंसाएँ

क्वेरी पैटर्न विश्लेषण पर आधारित, निम्नलिखित इंडेक्स उच्च समवर्ती परिदृश्यों में स्कैन की गई पंक्तियों को महत्वपूर्ण रूप से कम करते हैं:

| तालिका | अनुशंसित इंडेक्स | कवर क्वेरी |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | उपयोगकर्ता ऑर्डर सूची + स्थिति फ़िल्टर |
| `orders` | `(order_no)` (अद्वितीय) | ऑर्डर संख्या सटीक क्वेरी |
| `products` | `(status, category_id, sort)` | फ्रंट-एंड उत्पाद सूची + श्रेणी फ़िल्टर + क्रम |
| `product_skus` | `(product_id, status)` | SKU सूची + स्थिति फ़िल्टर |
| `product_regions` | `(sku_id, region_id)` (अद्वितीय) | क्षेत्रीय मूल्य खोज |
| `resources` | `(user_id, status)` | मेरे संसाधन सूची |
| `resources` | `(expired_at, status)` | समाप्ति जाँच क्रॉन कार्य |
| `provision_tasks` | `(status, next_retry_at)` | Worker पोलिंग लंबित कार्य |
| `refresh_tokens` | `(user_id, revoked)` | सत्र प्रबंधन क्वेरी |
| `payment_transactions` | `(order_id)` | ऑर्डर द्वारा लेनदेन खोज |
| `payment_transactions` | `(transaction_no)` (अद्वितीय) | Webhook आइडेम्पोटेंसी जाँच |
| `tickets` | `(user_id, status)` | उपयोगकर्ता टिकट सूची |
| `notifications` | `(user_id, read_at, created_at)` | उपयोगकर्ता सूचना सूची |

#### 5. समवर्ती कनेक्शन अनुमान

```
webman बहु-प्रक्रिया:
  CPU कोर × प्रक्रिया संख्या = worker संख्या
  उदा: 4 कोर × 8 worker = 32 worker प्रक्रियाएँ
  
MySQL कनेक्शन संख्या:
  प्रत्येक worker 1 स्थायी कनेक्शन बनाए रखता है
  32 worker × 2 इंस्टेंस (service + admin) = 64 कनेक्शन
  मास्टर 32 + स्लेव 32, रूढ़िवादी अनुशंसा MySQL max_connections ≥ 200

Nginx कनेक्शन संख्या:
  worker_connections 1024 × worker_processes auto
  पीक समवर्ती ≈ worker_connections × worker_processes / 2
  4 कोर सर्वर ≈ 2048 समवर्ती कनेक्शन
```

---

## बारह, कार्यान्वयन स्थिति समग्र तालिका

### कोर मॉड्यूल

| मॉड्यूल | स्थिति | विवरण |
|------|------|------|
| **User** | ✅ पूर्ण | पंजीकरण/लॉगिन/ईमेल सत्यापन/OAuth/TOTP/सत्र प्रबंधन/GDPR विलोपन/पता CRUD |
| **Product** | ✅ पूर्ण | SKU×क्षेत्र मूल्य निर्धारण, श्रेणियाँ, खोज (ES), समीक्षाएँ, गुण, थोक आयात/निर्यात |
| **Order** | ✅ पूर्ण | कार्ट, ऑर्डर, जीवनचक्र, रिफ़ंड, चालान (PDF), कूपन |
| **Payment** | ✅ पूर्ण | Stripe चैनल, बहु-चैनल रूटिंग, webhook हस्ताक्षर सत्यापन, निपटान |
| **Provisioning** | ✅ पूर्ण | Proxmox + AWS EC2 + ProviderFactory विस्तार योग्य आर्किटेक्चर |
| **Domain** | ✅ पूर्ण | TLD मूल्य निर्धारण, DNS रिकॉर्ड, डोमेन स्थानांतरण अनुमोदन |
| **Supplier** | ✅ पूर्ण | पंजीकरण अनुमोदन, उत्पाद सूचीकरण, निपटान, निकासी, API Key प्रबंधन |
| **Monitor** | ✅ पूर्ण | संसाधन जाँच, अलर्ट इंजन, SSL प्रमाणपत्र मॉनिटरिंग |
| **Ticket** | ✅ पूर्ण | निर्माण/उत्तर/असाइनमेंट/बंद करना/SLA ट्रैकिंग |
| **Notification** | ✅ पूर्ण | ईमेल/SMS/Push/स्टेशन संदेश चार चैनल + उपयोगकर्ता वरीयता प्रबंधन |
| **Report** | ✅ पूर्ण | राजस्व/आपूर्तिकर्ता/क्षेत्र रिपोर्ट |
| **I18n** | ✅ पूर्ण | बहुभाषा, बहु-मुद्रा, बहु-समय क्षेत्र |

### सुरक्षा प्रणाली

| फ़ीचर | स्थिति |
|------|------|
| WAF (8 श्रेणी 45+ नियम: SQL इंजेक्शन/XSS/कमांड इंजेक्शन/फ़ाइल इन्क्लूज़न/हेडर इंजेक्शन/SSRF/NoSQL इंजेक्शन/खुला रीडायरेक्ट) | ✅ |
| CORS मिडलवेयर | ✅ |
| ClientPlatform प्लेटफ़ॉर्म पहचान मिडलवेयर (8 प्लेटफ़ॉर्म) | ✅ |
| API दर सीमा (Redis टोकन बकेट) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| रखरखाव मोड (पर्यावरण चर स्विच + IP व्हाइटलिस्ट) | ✅ |
| अनुरोध/प्रतिक्रिया एन्क्रिप्शन (AES-256-GCM) | ✅ |
| ऑडिट लॉग (स्वतंत्र डेटाबेस, client_platform ट्रैकिंग सहित) | ✅ |
| डेटा मास्किंग (लॉग/प्रतिक्रिया स्वचालित प्रसंस्करण) | ✅ |
| JWT डिवाइस फ़िंगरप्रिंट बाइंडिंग + token रोटेशन + client_platform रिकॉर्ड | ✅ |
| bcrypt पासवर्ड (cost=12) + Encryptable द्वितीयक एन्क्रिप्शन | ✅ |
| द्वितीयक पासवर्ड पुष्टि (ConfirmationMiddleware, 5 बार विफल 15min लॉक) | ✅ |
| Admin पैनल WAF मिडलवेयर | ✅ |
| Sentry असामान्यता मॉनिटरिंग (SentryBootstrap + before_send मास्किंग) | ✅ |
| Feature Flags फ़ंक्शन स्विच (Redis डायनामिक ओवरराइड + प्रशासन पैनल API) | ✅ |

### नए फ़ीचर (2026-05-21)

| फ़ीचर | स्थिति |
|------|------|
| आपूर्तिकर्ता बाहरी API (API Key प्रमाणीकरण + ऑर्डर/संसाधन/निपटान/निकासी एंडपॉइंट) | ✅ |
| WebSocket वास्तविक समय पुश (Workerman मूल WebSocket + इवेंट श्रोता) | ✅ |
| k6 लोड परीक्षण स्क्रिप्ट (स्मोक/उत्पाद/समवर्ती) | ✅ |

### बैकएंड आँकड़े

| मीट्रिक | संख्या |
|------|------|
| API एंडपॉइंट | 135 |
| डेटा मॉडल | 50+ |
| डेटाबेस तालिकाएँ | 50+ |
| मिडलवेयर | 15 (वैश्विक 7 + रूट 6 + बाहरी API 1 + admin WebSocket) |
| क्रॉन कार्य | 7 |
| माइग्रेशन फ़ाइलें | 22 |
| परीक्षण | 362 tests / 579 assertions (Service 295 + Admin 67) |
| परीक्षण फ़ाइलें | 22 |
| k6 लोड परीक्षण स्क्रिप्ट | 3 (smoke / products / concurrent) |

### दस्तावेज़

| दस्तावेज़ | पथ |
|------|------|
| सिस्टम डिज़ाइन मानक | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| प्रशासन पैनल डिज़ाइन | `docs/admin-design.md` |
| आपूर्तिकर्ता API दस्तावेज़ | `docs/supplier-api.md` |
| डिप्लॉयमेंट चेकलिस्ट | `docs/deployment.md` |
| API स्मोक टेस्ट स्क्रिप्ट | `docs/api-test.sh` |

### फ्रंटएंड स्थिति

| अंत | स्थिति | विवरण |
|----|------|------|
| Flutter | 🟡 प्रगति में | ApiClient header संस्करण संख्या + ApiService एकीकृत डेटा परत से जुड़ा; लॉगिन/उत्पाद सूची/कार्ट/संसाधन सूची API से जुड़े; ऑर्डर इतिहास/सूचना केंद्र को कंपाइल वातावरण सत्यापन की आवश्यकता |
| HarmonyOS | 🔴 प्रारंभिक | केवल लॉगिन पेज और ApiClient |
| Admin Panel | ✅ पूर्ण | डैशबोर्ड/उपयोगकर्ता/उत्पाद/ऑर्डर/भुगतान/संसाधन/आपूर्तिकर्ता/टिकट/डोमेन/सूचना/सिस्टम/रिपोर्ट/Webhook/आयात-निर्यात पूर्ण फ़ीचर |
