# CloudPlatform API इंटरफ़ेस दस्तावेज़

## अवलोकन

**बेस URL:** `https://api.example.com`

**संस्करण नियंत्रण:** HTTP अनुरोध हेडर `X-Api-Version: v1` द्वारा निर्दिष्ट। अनुपस्थित होने पर डिफ़ॉल्ट `v1`, असमर्थित संस्करण `400` लौटाता है। संस्करण URL पथ में नहीं है।

**प्रमाणीकरण विधियाँ:**

| अंत | विधि | अनुरोध हेडर |
|----|------|--------|
| उपयोगकर्ता अंत | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| प्रशासन अंत | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| आपूर्तिकर्ता बाहरी API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | हस्ताक्षर सत्यापन | `Stripe-Signature: ...` |

**क्लाइंट प्लेटफ़ॉर्म:** सभी API अनुरोधों में `X-Client-Platform` हेडर भेजने की अनुशंसा की जाती है, समर्थित: `ios/android/macos/windows/linux/web/harmonyos/ipados`।

**बहुभाषा:** सभी API अनुरोधों में `Accept-Language` हेडर (`zh-CN` / `en-US`) भेजने की अनुशंसा की जाती है, यह अनुवादित टेक्स्ट और JSON बहुभाषा फ़ील्ड के लौटाए गए मानों को प्रभावित करता है। अनुपस्थित होने पर डिफ़ॉल्ट `en-US`।

---

## एकीकृत प्रतिक्रिया प्रारूप

### सफलता

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### पृष्ठांकन (Pagination)

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### त्रुटि

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP स्थिति कोड

| code | विवरण |
|------|------|
| 0 | सफल |
| 400 | अनुरोध पैरामीटर त्रुटि / असमर्थित API संस्करण / असमर्थित क्लाइंट प्लेटफ़ॉर्म |
| 401 | प्रमाणीकरण नहीं |
| 403 | अनुमति नहीं / WAF द्वारा अवरोधित |
| 404 | संसाधन मौजूद नहीं (firstOrFail/findOrFail के न मिलने पर एकीकृत रूप से 404 मैप) |
| 413 | अनुरोध बॉडी बहुत बड़ी (>10MB) |
| 414 | URL बहुत लंबा (>2KB) |
| 415 | असमर्थित Content-Type |
| 422 | पैरामीटर सत्यापन विफल |
| 429 | अनुरोध आवृत्ति सीमा से अधिक |

---

## रूट समूह और मिडलवेयर मैट्रिक्स

| रूट समूह | मिडलवेयर | प्रीफ़िक्स |
|--------|--------|------|
| सार्वजनिक | वैश्विक मिडलवेयर श्रृंखला | `/health`, `/api/*` |
| `/health` (आंतरिक) | वैश्विक + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | वैश्विक + Encryption | `/api/auth/*` |
| `/api` (उपयोगकर्ता) | वैश्विक + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (संवेदनशील) | वैश्विक + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | आपूर्तिकर्ता बाहरी API |
| `/admin/api` | वैश्विक + Encryption + Auth + AdminRole | प्रशासन पैनल API |
| `/admin/api` (संवेदनशील) | वैश्विक + Encryption + Auth + AdminRole + Confirmation | संवेदनशील प्रशासन संचालन |

---

## एक, सार्वजनिक एंडपॉइंट

### स्वास्थ्य जाँच

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### सेवा स्थिति

```
GET /api/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### उत्पाद

```
GET /api/products
  पैरामीटर: category_id, region_id, keyword, supplier_id, page (डिफ़ॉल्ट 1), page_size (डिफ़ॉल्ट 20, अधिकतम 50)
  → पृष्ठांकित उत्पाद सूची (category, skus.regionPrices सहित)

GET /api/products/search
  पैरामीटर: q (आवश्यक), page
  → Elasticsearch पूर्ण-पाठ खोज

GET /api/products/{id}
  → उत्पाद विवरण (category, skus, images, reviews सहित)

GET /api/products/{productId}/reviews
  → समीक्षा सूची + avg_rating + total + distribution
  स्थिति एनम: pending(लंबित)/approved(स्वीकृत)/rejected(अस्वीकृत), केवल approved लौटाया जाता है
```

### डोमेन

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → उपलब्ध TLD सूची (Redis कैश 1h)
```

### सहायता केंद्र

```
GET /api/help
  पैरामीटर: category, page
  हेडर: Accept-Language (en-US / zh-CN)
  → पृष्ठांकित सहायता लेख

GET /api/help/categories
  → लेख श्रेणी सूची

GET /api/help/{slug}
  → एकल लेख विवरण
```

---

## दो, प्रमाणीकरण एंडपॉइंट

### कैप्चा

```
POST /api/captcha/create
  हेडर: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### पंजीकरण

```
POST /api/auth/register
  हेडर: X-Encrypted: 1
  बॉडी (एन्क्रिप्टेड): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

दर सीमा: 3 req/min
```

- `deviceFingerprint` (वैकल्पिक): पंजीकरण के समय डिवाइस फ़िंगरप्रिंट दर्ज किया जाता है, लॉगिन/रिफ़्रेश के समय सत्यापित किया जाता है; न ले जाने पर फ़िंगरप्रिंट बाइंडिंग छोड़ दी जाती है
- email/phone संग्रहण से पहले Encryptable निर्धारणात्मक एन्क्रिप्शन (ECB, सिफ़रटेक्स्ट समानता क्वेरी) से गुजरते हैं, अद्वितीयता सत्यापन और लॉगिन क्वेरी दोनों सिफ़रटेक्स्ट के अनुसार होती हैं

### लॉगिन

```
POST /api/auth/login
  हेडर: X-Encrypted: 1
  बॉडी (एन्क्रिप्टेड): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

दर सीमा: 5 req/min, 5 बार विफलता पर 15min लॉक
```

- `login` सिफ़रटेक्स्ट समानता क्वेरी द्वारा (Encryptable निर्धारणात्मक एन्क्रिप्शन), प्लेनटेक्स्ट क्वेरी एन्क्रिप्टेड कॉलम को हिट नहीं करती

### Token रिफ़्रेश

```
POST /api/auth/refresh
  हेडर: X-Encrypted: 1
  बॉडी (एन्क्रिप्टेड): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` पंजीकरण के समय दर्ज किए गए से मेल न खाने पर → 401 `Device mismatch`; रिफ़्रेश token सिफ़रटेक्स्ट हैश द्वारा क्वेरी किया जाता है

### OAuth

समर्थित प्रदाता: google, apple, facebook, x, microsoft, linkedin, github
(.env में `{PROVIDER}_OAUTH_CLIENT_ID` आदि कॉन्फ़िगरेशन द्वारा तय होता है कि सक्षम है या नहीं)

```
GET /api/auth/{provider}            → { url }        # प्राधिकरण पृष्ठ पर रीडायरेक्ट (PKCE/nonce रीप्ले-रोधक)
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  बॉडी: { code, state }
```

- Apple/Microsoft `id_token` लौटाते हैं, सर्वर JWKS द्वारा हस्ताक्षर, iss/aud/exp/nonce सत्यापित करता है
- सभी प्रदाताओं को लॉगिन की अनुमति के लिए `email_verified=true` चाहिए, अन्यथा 422
- `state` अनुपस्थित या अमेल → 422 (CSRF रोधन, 5 मिनट समाप्ति)
- OAuth प्रवाह दर सीमा: प्रति 60 सेकंड 10 बार (redirect + callback)

### पासवर्ड रीसेट

```
POST /api/auth/forgot-password
  बॉडी: { email }
  → सत्यापन कोड ईमेल भेजा जाता है

POST /api/auth/reset-password
  बॉडी: { email, code, password }
  → रीसेट सफल
  → त्रुटि संचय 5 बार → 429 दर सीमा 10 मिनट
```

### ईमेल सत्यापन

```
GET /api/auth/verify-email?token=xxx
  → सत्यापन सफल
```

### SMS सत्यापन

```
POST /api/auth/send-sms
  बॉडी: { phone }
  → SMS सत्यापन कोड भेजा जाता है (60s कूलडाउन)
```

### TOTP दो-चरणीय सत्यापन

```
POST /api/user/totp/setup        → { secret, qr_url }        # पर्सिस्ट नहीं, 10 मिनट के भीतर verify करने पर प्रभावी
POST /api/user/totp/verify       बॉडी: { code } → { verified: true }   # पहली बार सक्षम करने पर सफलता संदेश लौटता है
POST /api/user/totp/disable      बॉडी: { password }             # पासवर्ड पुष्टि आवश्यक, अन्यथा 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # हर बार 8 एक-बार कोड बनते हैं, पासवर्ड पुष्टि आवश्यक, अन्यथा 403
POST /api/auth/login/recovery    बॉडी: { login, password, recovery_code }
```

- उपयोगकर्ता TOTP सक्षम करने के बाद लॉगिन में `totp_code` ले जाना अनिवार्य है, अन्यथा 401
- TOTP लगातार 5 बार गलत → उपयोगकर्ता 15 मिनट के लिए लॉक (login_lock)

---

## तीन, उपयोगकर्ता एंडपॉइंट (प्रमाणीकरण आवश्यक)

### व्यक्तिगत प्रोफ़ाइल

```
GET /api/user/profile
PUT /api/user/profile
  बॉडी: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC सत्यापन

```
POST /api/user/kyc
  बॉडी: { id_type, id_number, real_name, front_image, back_image }
```

### शेष राशि

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
  पैरामीटर: page
  → शेष राशि बदलाव के रिकॉर्ड
```

### पता प्रबंधन

```
GET /api/user/addresses
POST /api/user/addresses
  बॉडी: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### सत्र प्रबंधन

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → निर्दिष्ट सत्र रद्द करें

DELETE /api/user/account
  बॉडी: { confirm_password }
  → GDPR खाता विलोपन
```

### सूचनाएँ

```
GET /api/user/notifications
  पैरामीटर: page
  → पृष्ठांकित सूचना सूची

POST /api/user/notifications/{id}/read
  → पढ़ा हुआ चिह्नित करें

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
  बॉडी: { email: {order_paid: true, ...}, push: {...} }
```

### ईमेल

```
POST /api/user/resend-verify-email
  → सत्यापन ईमेल पुनः भेजें
```

### फ़ाइल अपलोड

```
POST /api/upload
  बॉडी: multipart/form-data { file, type: avatar/kyc/attach }
  सीमाएँ: avatar 2MB, kyc 5MB, attach 10MB
  अनुमत: jpg, jpeg, png, gif, pdf
  विवरण: प्रकार व्हाइटलिस्ट सत्यापन + finfo सामग्री स्निफ़िंग (एक्सटेंशन और MIME अमेल → 422)
```

---

## चार, कार्ट और ऑर्डर

### कार्ट

```
POST /api/cart
  बॉडी: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
  बॉडी: { quantity }
```

> राशि फ़ील्ड सम्मेलन (D4/P4.2 तय): सभी राशियाँ string, 4 दशमलव स्थान (जैसे "9.9900"), number/float निषिद्ध —
> MySQL DECIMAL कॉलम के PDO माध्यम से कच्चे आउटपुट के अनुरूप, परिशुद्धता 4dp स्ट्रिंग द्वारा ही वहन की जाती है। ऑर्डर/शेष/रिपोर्ट के सभी एंडपॉइंट पर लागू।

### ऑर्डर

```
POST /api/orders
  → कार्ट से ऑर्डर बनाएं
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
  पैरामीटर: page, status (pending/paid/provisioning/completed/refunded, अवैध मान पर 400)
  → मेरे ऑर्डर की सूची

GET /api/orders/{id}
  → ऑर्डर विवरण (items, timeline सहित)

GET /api/orders/{id}/payment-methods
  → उपलब्ध भुगतान चैनल + प्रत्येक चैनल की वास्तविक भुगतान राशि

POST /api/orders/{id}/pay    🔒 पासवर्ड पुष्टि
  बॉडी: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### कूपन

```
POST /api/coupons/validate
  बॉडी: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (जैसे "2.0000")

422: अमान्य/समाप्त/शर्तें पूरी न होना
```

### चालान

```
GET /api/invoices
  पैरामीटर: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → PDF डाउनलोड
```

---

## पाँच, संसाधन प्रबंधन

```
GET /api/resources
  पैरामीटर: page, status
  → मेरे संसाधनों की सूची

GET /api/resources/{id}
  → संसाधन विवरण

GET /api/resources/{id}/status
  → संसाधन की वर्तमान स्थिति + मीट्रिक

GET /api/resources/{id}/console
  → VNC/कंसोल URL

POST /api/resources/batch
  बॉडी: { action: start/stop/restart, resource_ids: [...] }
```

---

## छह, DNS प्रबंधन

```
GET /api/dns/{domain}
  → DNS रिकॉर्ड सूची

POST /api/dns/{domain}/records
  बॉडी: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 पासवर्ड पुष्टि
```

---

## सात, टिकट

```
POST /api/tickets
  बॉडी: { resource_id?, category, priority?, title, content }

GET /api/tickets
  पैरामीटर: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
  बॉडी: { content }
```

---

## आठ, आपूर्तिकर्ता (आंतरिक API)

```
POST /api/supplier/apply
  बॉडी: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → निपटान रिपोर्ट सूची

POST /api/supplier/withdraw    🔒 पासवर्ड पुष्टि
  बॉडी: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
  बॉडी: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## नौ, आपूर्तिकर्ता बाहरी API

**प्रमाणीकरण:** `Authorization: Bearer sk_xxx...` (SHA256 हस्ताक्षर सत्यापन)

**दर सीमा:** 120 req/min (निकासी 10 req/min)

```
GET /api/supplier/external/orders
  पैरामीटर: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → ऑर्डर विवरण (केवल इस आपूर्तिकर्ता से संबद्ध)

GET /api/supplier/external/resources
  पैरामीटर: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
  पैरामीटर: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
  बॉडी: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
  पैरामीटर: page
```

---

## दस, प्रशासन पैनल API

**प्रमाणीकरण:** JWT Bearer Token + Admin भूमिका

### डैशबोर्ड

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### उपयोगकर्ता प्रबंधन

```
GET /admin/api/users              पैरामीटर: page, status, keyword
GET /admin/api/users/export       → Excel डाउनलोड
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status  बॉडी: { status }
```

### KYC समीक्षा

```
GET /admin/api/kyc                पैरामीटर: page, status

POST /admin/api/kyc/{id}/approve  🔒 पासवर्ड पुष्टि
  बॉडी: { confirm_password }

POST /admin/api/kyc/{id}/reject   🔒 पासवर्ड पुष्टि
  बॉडी: { confirm_password, reason }
```

### उत्पाद प्रबंधन

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 पासवर्ड पुष्टि
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → CSV डाउनलोड
POST /admin/api/products/import        → CSV अपलोड upsert
```

### ऑर्डर प्रबंधन

```
GET /admin/api/orders              पैरामीटर: page, status, keyword
GET /admin/api/orders/export       → Excel डाउनलोड
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 पासवर्ड पुष्टि
  बॉडी: { confirm_password, amount?, reason }
```

### भुगतान प्रबंधन

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions  पैरामीटर: page, channel, status
GET /admin/api/payments/reconcile     पैरामीटर: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run  पैरामीटर: date; दैनिक निपटान ट्रिगर
```

### संसाधन और प्रोविज़निंग

```
GET /admin/api/provisioning/tasks              पैरामीटर: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
  बॉडी: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 पासवर्ड पुष्टि
GET /admin/api/provisioning/hosts
```

### आपूर्तिकर्ता प्रबंधन

```
GET /admin/api/suppliers                 पैरामीटर: page, status
GET /admin/api/suppliers/export          → Excel डाउनलोड

POST /admin/api/suppliers/{id}/approve   🔒 पासवर्ड पुष्टि
POST /admin/api/suppliers/{id}/settle    🔒 पासवर्ड पुष्टि
  बॉडी: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 पासवर्ड पुष्टि
```

### आपूर्तिकर्ता API Key

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
  बॉडी: { name }
  ← { api_key: "sk_xxx...", prefix } (केवल एक बार दिखाया जाता है)

DELETE /admin/api/suppliers/api-keys/{id}
```

### टिकट प्रबंधन

```
GET /admin/api/tickets                  पैरामीटर: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign     बॉडी: { user_id }
POST /admin/api/tickets/{id}/close
```

### डोमेन प्रबंधन

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
  बॉडी: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones              पैरामीटर: page
GET /admin/api/domains/transfers          पैरामीटर: page
POST /admin/api/domains/transfers/{id}/approve
```

### सूचना प्रबंधन

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
  बॉडी: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log          पैरामीटर: page
```

### कूपन

```
GET /admin/api/coupons
POST /admin/api/coupons
  बॉडी: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### सहायता लेख

```
GET /admin/api/help
POST /admin/api/help
  बॉडी: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → सॉफ्ट डिलीट (status=archived)
```

### क्लाउड प्रदाता API

```
GET /admin/api/providers
POST /admin/api/providers
  बॉडी: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → निष्क्रिय (status=disabled)
```

### Webhook प्रबंधन

```
GET /admin/api/webhooks
POST /admin/api/webhooks
  बॉडी: { url }
DELETE /admin/api/webhooks               बॉडी: { id }
POST /admin/api/webhooks/test            बॉडी: { url }
```

### रिपोर्ट

```
GET /admin/api/reports/revenue            पैरामीटर: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (SUM(DECIMAL) और bcmath सारांश के अनुरूप)
GET /admin/api/reports/supplier           पैरामीटर: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region             पैरामीटर: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### मॉनिटरिंग

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### ऑडिट लॉग

```
GET /admin/api/audit-logs                 पैरामीटर: page, user_id, action, from, to
  → पृष्ठांकित ऑडिट लॉग (client_platform सहित)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
  बॉडी: { action: enable/disable/toggle/reset }
```

### सिस्टम कॉन्फ़िगरेशन

```
PUT /admin/api/system/config              🔒 पासवर्ड पुष्टि
```

### उत्पाद आयात/निर्यात

```
GET /admin/api/products/export           → CSV डाउनलोड
POST /admin/api/products/import          → CSV अपलोड upsert
```

### आपूर्तिकर्ता + उपयोगकर्ता निर्यात

```
GET /admin/api/suppliers/export          → Excel डाउनलोड
GET /admin/api/users/export              → Excel डाउनलोड
GET /admin/api/orders/export             → Excel डाउनलोड
```

---

## ग्यारह, SSL प्रमाणपत्र

### उपयोगकर्ता अंत

```
GET /api/ssl/plans
  → SSL पैकेज सूची (DV/OV/EV, मूल्य register/renew/transfer सहित)

GET /api/ssl-certs
  → मेरे प्रमाणपत्रों की सूची (status: pending/active/expired/revoked सहित)

GET /api/ssl-certs/{id}
  → प्रमाणपत्र विवरण (डोमेन, जारी करने वाला प्राधिकरण, वैधता अवधि, नवीनीकरण स्थिति)

GET /api/ssl-certs/{id}/download
  → प्रमाणपत्र फ़ाइलें डाउनलोड करें (प्रमाणपत्र श्रृंखला + निजी कुंजी)

POST /api/ssl-certs/{id}/auto-renew
  बॉडी: { auto_renew: true/false }
  → स्वचालित नवीनीकरण टॉगल करें
```

### प्रशासन अंत

```
GET /admin/api/ssl/plans              → पैकेज सूची
POST /admin/api/ssl/plans             → पैकेज बनाएं
PUT /admin/api/ssl/plans/{id}         → पैकेज अपडेट करें
DELETE /admin/api/ssl/plans/{id}      → पैकेज हटाएं
GET /admin/api/ssl/certs              → सभी प्रमाणपत्र
POST /admin/api/ssl/certs/{id}/revoke → प्रमाणपत्र रद्द करें
```

---

## बारह, ऑब्जेक्ट स्टोरेज

S3 संगत ऑब्जेक्ट स्टोरेज, प्री-साइन्ड URL द्वारा अपलोड/डाउनलोड, कुंजियाँ बाहर नहीं जातीं।

```
GET /api/storage/buckets
  → मेरे स्टोरेज बकेट की सूची (उपयोग, स्थिति)

GET /api/storage/buckets/{id}
  → बकेट विवरण

POST /api/storage/buckets/{id}/presign-upload
  बॉडी: { filename, content_type, size }
  → { upload_url, object_key } प्री-साइन्ड अपलोड URL (समय सीमित)

POST /api/storage/buckets/{id}/presign-download
  बॉडी: { object_key }
  → प्री-साइन्ड डाउनलोड URL (समय सीमित)

GET /api/storage/buckets/{id}/credentials
  → अस्थायी एक्सेस क्रेडेंशियल (अल्पकालिक, SDK सीधे अपलोड के लिए)
```

---

## तेरह, CDN त्वरण

### उपयोगकर्ता अंत

```
GET /api/cdn/domains
  → मेरे CDN डोमेन की सूची (मूल सर्वर, स्थिति, पैकेज)

POST /api/cdn/domains
  बॉडी: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
          origin_type (server|storage), origin_value, cert_config? }
  → CDN डोमेन बनाएं (सेवाप्रदाता पक्ष पर बनाकर ओरिजिन बाइंड करता है)
  → provider_type=aliyun|tencent होने पर डोमेन को ICP पंजीकरण पूर्ण करना आवश्यक (पंजीकरण न होने पर 4002)
  → प्रतिक्रिया में requires_icp_registration संकेत फ़ील्ड होता है
  → क्रेडेंशियल रिज़ॉल्यूशन: पहले डोमेन का बाउंड खाता (provider_account_id), अन्यथा code=cdn-{provider_type}
    वाला सक्रिय provider_apis खाता, दोनों अनुपस्थित होने पर env कॉन्फ़िगरेशन फ़ॉलबैक

GET /api/cdn/domains/{id}
  → CDN डोमेन विवरण

DELETE /api/cdn/domains/{id}
  → CDN डोमेन हटाएं (सेवाप्रदाता पक्ष का डोमेन निष्क्रिय करता है, आइडेम्पोटेंट)

POST /api/cdn/domains/{id}/purge
  बॉडी: { urls: ["https://cdn.example.com/path"] }
  → कैश साफ़ करें (डुप्लिकेट URL स्वचालित रूप से हटाए जाते हैं, आइडेम्पोटेंट; अधिकतम 100)

GET /api/cdn/domains/{id}/stats
  → डोमेन अवलोकन (cdn_domain / provider_type / plan / status / purged_at)
```

### प्रशासन अंत

```
GET /admin/api/cdn/domains            → सभी CDN डोमेन (संबंधित उपयोगकर्ता सहित)
PUT /admin/api/cdn/domains/{id}       → डोमेन पैकेज अपडेट करें (plan व्हाइटलिस्ट: standard | pro | enterprise)
```

एडमिन CDN रूट `RbacMiddleware('cdn.manage')` से जुड़े हैं, पैकेज परिवर्तन ऑडिट लॉग में लिखे जाते हैं (`admin_cdn_update_plan`)। सेवाप्रदाता खाता क्रेडेंशियल `/admin/api/providers` CRUD के माध्यम से बनाए रखे जाते हैं (RbacMiddleware `provider.config`, `code` कन्वेंशन `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, क्रेडेंशियल Encryptable एन्क्रिप्शन के साथ संग्रहीत)।

### CDN त्रुटि कोड

| code | विवरण |
|------|-------|
| 4001 | CDN पैरामीटर अनुपस्थित/अमान्य (urls खाली, provider_type अमान्य, डोमेन प्रारूप त्रुटि) |
| 4002 | डोमेन ने ICP पंजीकरण पूर्ण नहीं किया (Aliyun/Tencent API अस्वीकार करने पर मैप) |
| 4003 | CDN सेवाप्रदाता क्रेडेंशियल कॉन्फ़िगर नहीं (खाता अनुपस्थित/अक्षम, सख्त स्नैपशॉट चुपचाप स्विच नहीं करता) |
| 4005 | CDN कैश पर्ज विफल |
| 5001 | CDN सेवाप्रदाता API कॉल विफल |

> गैर-स्वामित्व वाला CDN संसाधन (दूसरों का/अस्तित्वहीन संसाधन) समान रूप से **404** लौटाता है (findOrFail मैपिंग, संसाधन अस्तित्व का खुलासा नहीं), कोई अलग व्यावसायिक कोड नहीं।

---

## चौदह, उपयोग-आधारित बिलिंग

```
GET /admin/api/billing/rates          → बिलिंग दर सूची (संसाधन प्रकार/स्पेक के अनुसार)
POST /admin/api/billing/rates         → दर बनाएं
PUT /admin/api/billing/rates/{id}     → दर अपडेट करें
DELETE /admin/api/billing/rates/{id}  → दर हटाएं
GET /admin/api/billing/usage          → उपयोग सारांश (उपयोगकर्ता/संसाधन द्वारा समूहीकृत)
```

बिलिंग पाइपलाइन: ResourceMonitor हर 5 मिनट में संग्रह → UsageAggregator हर घंटे एग्रीगेट → BillingEngine प्रतिदिन डेबिट, अपर्याप्त शेष राशि पर संसाधन निलंबित।

---

## पंद्रह, Affiliate कमीशन

### उपयोगकर्ता अंत

```
GET /api/affiliate/summary
  → कमीशन सारांश (संचयी/लंबित/निकासी योग्य, लिंक संख्या, रूपांतरण दर)

POST /api/affiliate/links
  बॉडी: { source? }
  → प्रचार लिंक बनाएं (?ref=CODE)

GET /api/affiliate/earnings
  पैरामीटर: status, page
  → कमीशन विवरण (ऑर्डर संबद्धता, अनुपात, स्थिति: pending/approved/paid)

POST /api/affiliate/payout
  बॉडी: { amount, method }
  → निकासी अनुरोध शुरू करें
```

### प्रशासन अंत

```
GET /admin/api/affiliate/plans                → कमीशन योजना सूची
POST /admin/api/affiliate/plans               → कमीशन योजना बनाएं
GET /admin/api/affiliate/earnings             → सभी कमीशन रिकॉर्ड
POST /admin/api/affiliate/earnings/{id}/approve → कमीशन समीक्षा करें
GET /admin/api/affiliate/payouts              → निकासी अनुरोध सूची
POST /admin/api/affiliate/payouts/{id}/approve → निकासी समीक्षा/भुगतान करें
```

---

## सोलह, GraphQL

```
POST /graphql
  → सार्वजनिक क्वेरी (उत्पाद, डोमेन, सहायता आदि केवल-पठनीय डेटा)
  सीमाएँ: क्वेरी गहराई 5 स्तर, जटिलता 100

POST /api/graphql                          🔒 प्रमाणीकरण आवश्यक
  → पूर्ण क्वेरी (उपयोगकर्ता डेटा सहित)
```

**संवेदनशील संचालन केवल REST:** भुगतान, निकासी, रिफ़ंड, KYC समीक्षा GraphQL से नहीं जाती।

---

## सत्रह, आपूर्तिकर्ता रेटिंग और उत्पाद समीक्षा

### सार्वजनिक

```
GET /api/regions
  → उपलब्ध क्षेत्र सूची (मुद्रा/समय क्षेत्र सहित)

GET /api/suppliers/{supplierId}/ratings
  → आपूर्तिकर्ता रेटिंग सूची (चार आयाम: गुणवत्ता/सहायता/डिलीवरी गति/मूल्य-प्रदर्शन, केवल approved लौटाया जाता है)
```

### उपयोगकर्ता अंत (प्रमाणीकरण आवश्यक)

```
POST /api/products/{productId}/reviews
  बॉडी: { rating, content, images? }
  → उत्पाद समीक्षा जमा करें (प्रति ऑर्डर एक बार, समीक्षा के बाद प्रदर्शित)

POST /api/supplier/ratings
  बॉडी: { supplier_id, quality, support, delivery_speed, value, comment? }
  → आपूर्तिकर्ता रेटिंग जमा करें (प्रति ऑर्डर एक बार)

GET /api/supplier/ratings/me
  → मेरे रेटिंग रिकॉर्ड
```

### प्रशासन अंत

```
GET /admin/api/suppliers/{id}/ratings          → सभी रेटिंग (pending सहित)
POST /admin/api/suppliers/ratings/{id}/approve → समीक्षा स्वीकृत करें
POST /admin/api/suppliers/ratings/{id}/hide    → छुपाएं
```

---

## अठारह, भुगतान Webhook

```
POST /api/payments/webhook/stripe
  हेडर: Stripe-Signature: ...
  → Stripe कॉलबैक (भुगतान सफल/रिफ़ंड/विवाद), हस्ताक्षर सत्यापन विफल होने पर 400
```

---

## उन्नीस, WebSocket इवेंट

**कनेक्शन:** `ws://host:8282` (docker डिप्लॉयमेंट में WS nginx रिवर्स प्रॉक्सी से गुजरता है, कनेक्शन पता `ws://host/ws/` है, 8282 केवल कंटेनर के भीतर खुला)

प्रमाणीकरण कनेक्शन के बाद पहले संदेश से होता है (token URL/एक्सेस लॉग में नहीं जाता): कनेक्शन स्थापित होने के बाद पहले `auth` संदेश भेजना अनिवार्य है, 30 सेकंड के भीतर प्रमाणीकरण न होने पर डिस्कनेक्ट; प्रमाणीकरण विफलता पर `error` लौटकर डिस्कनेक्ट।

### क्लाइंट → सर्वर

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### सर्वर → क्लाइंट

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### पुश इवेंट

| इवेंट | डेटा | ट्रिगर समय |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | भुगतान सफल |
| `resource.provisioned` | `{resource_id, type, ip_address}` | संसाधन प्रोविज़न पूर्ण |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | संसाधन समाप्ति के निकट |
| `ticket.updated` | `{ticket_id, title, status}` | टिकट स्थिति बदलाव |
| `notification.new` | `{notification_id, title, body}` | नई सूचना |

---

## बीस, त्रुटि कोड संदर्भ

| code | विवरण |
|------|------|
| 400 | पैरामीटर त्रुटि / असमर्थित API संस्करण / असमर्थित क्लाइंट प्लेटफ़ॉर्म |
| 401 | प्रमाणीकरण नहीं / Token समाप्त / अमान्य API Key / डिवाइस फ़िंगरप्रिंट अमेल (Device mismatch) |
| 403 | अनुमति नहीं / गैर-आपूर्तिकर्ता भूमिका / WAF द्वारा अवरोधित / पासवर्ड पुष्टि विफल |
| 404 | संसाधन मौजूद नहीं (firstOrFail/findOrFail के न मिलने पर एकीकृत रूप से 404 मैप) |
| 413 | अनुरोध बॉडी 10MB से अधिक |
| 414 | URL 2KB से अधिक |
| 415 | Content-Type व्हाइटलिस्ट में नहीं (केवल application/json, multipart/form-data, x-www-form-urlencoded अनुमत) |
| 422 | पैरामीटर सत्यापन विफल (ईमेल पहले से पंजीकृत / स्टॉक अपर्याप्त / निकासी योग्य शेष अपर्याप्त / आवेदन पहले जमा हो चुका) |
| 429 | अनुरोध आवृत्ति सीमा से अधिक |
| 500 | सर्वर त्रुटि |

### सामान्य 422 संदेश

| संदेश | एंडपॉइंट |
|------|------|
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
