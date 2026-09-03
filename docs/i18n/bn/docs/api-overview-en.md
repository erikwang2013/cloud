# API ওভারভিউ

> সম্পূর্ণ API রেফারেন্স (২০০+ এন্ডপয়েন্ট, রিকোয়েস্ট/রেসপন্স উদাহরণ ও এরর কোড সহ): [API রেফারেন্স](api-reference.md)
> অনলাইন ডিবাগিং: [service API ডক্স](http://localhost:8787/apidoc) · [admin API ডক্স](http://localhost:8788/apidoc)

## পাবলিক এন্ডপয়েন্ট

| মেথড | পাথ | বিবরণ |
|--------|------|-------------|
| GET | `/health` | হেলথ চেক |
| POST | `/api/v1/auth/register` | রেজিস্ট্রেশন (বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/v1/auth/login` | লগইন (বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/v1/auth/refresh` | টোকেন রিফ্রেশ (বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/v1/captcha/create` | ক্লিক ক্যাপচা জেনারেট করুন (লগইন/রেজিস্ট্রেশনের আগে প্রয়োজন) |
| GET | `/api/v1/products` | প্রোডাক্ট লিস্ট (ক্যাটাগরি/রিজিয়ন/কীওয়ার্ড দিয়ে ফিল্টারযোগ্য) |
| GET | `/api/v1/products/{id}` | প্রোডাক্ট ডিটেইল (id একটি hashid স্ট্রিং) |
| GET | `/api/v1/regions` | উপলব্ধ রিজিয়ন |
| GET | `/api/v1/domain/check/{domain}/{tld}` | ডোমেইন অ্যাভেইলেবিলিটি চেক |
| GET | `/api/v1/domain/tlds` | উপলব্ধ টিএলডি |
| POST | `/api/v1/payments/webhook/stripe` | Stripe ওয়েবহুক (সিগনেচার ভেরিফাইড, এনক্রিপশন নেই) |

## অথেনটিকেটেড এন্ডপয়েন্ট (Bearer Token)

| মেথড | পাথ | বিবরণ |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | প্রোফাইল দেখুন |
| PUT | `/api/v1/user/profile` | প্রোফাইল আপডেট |
| POST | `/api/v1/user/kyc` | KYC জমা দিন |
| GET | `/api/v1/user/balance` | অ্যাকাউন্ট ব্যালেন্স |
| GET/POST | `/api/v1/cart` | শপিং কার্ট |
| POST/GET | `/api/v1/orders` | অর্ডার |
| GET | `/api/v1/orders/{id}/payment-methods` | উপলব্ধ পেমেন্ট পদ্ধতি |
| POST | `/api/v1/orders/{id}/pay` | পেমেন্ট শুরু করুন |
| GET/POST | `/api/v1/resources` | আমার রিসোর্স |
| GET | `/api/v1/resources/{id}/status` | রিসোর্স স্ট্যাটাস |
| GET | `/api/v1/resources/{id}/console` | VNC কনসোল URL |
| GET/POST | `/api/v1/tickets` | সাপোর্ট টিকেট |
| POST | `/api/v1/tickets/{id}/reply` | টিকেটে রিপ্লাই |
| GET/POST | `/api/v1/dns/{domain}` | DNS ম্যানেজমেন্ট |
| POST | `/api/v1/supplier/apply` | সাপ্লায়ার হিসেবে আবেদন করুন |
| GET | `/api/v1/supplier/settlements` | সেটেলমেন্ট হিস্টোরি |
| POST | `/api/v1/supplier/withdraw` | উইথড্রয়ালের অনুরোধ করুন |

> **বিবরণ:** API ভার্সন URL পাথে থাকে, যেমন `/api/v1/...`, `VersionMiddleware` দিয়ে ভ্যালিডেট হয়। অথেনটিকেটেড ও অ্যাডমিন এন্ডপয়েন্ট `EncryptionMiddleware` দিয়ে প্রসেস হয়। ক্লায়েন্টরা `X-Encrypted: 1` হেডার সেট করে এবং বডি `{"payload": "<base64(AES-256-GCM)>"}` ফরম্যাটে মোড়ায়। রেসপন্সগুলোও এনক্রিপ্ট হয়ে `payload` ফিল্ডে মোড়ানো হয়। API রেসপন্সে ইন্টিজার ID অটোমেটিক ১২ অক্ষরের Hashid স্ট্রিংয়ে রূপান্তরিত হয়; রিকোয়েস্টের Hashid স্ট্রিং `HashidRequestMiddleware` দিয়ে ডিকোড হয়ে ইন্টিজার ID-তে ফিরে আসে।

## অ্যাডমিন এন্ডপয়েন্ট

| মেথড | পাথ | বিবরণ |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | অপারেশনস ড্যাশবোর্ড |
| GET/PUT | `/admin/api/v1/users` | ইউজার ম্যানেজমেন্ট |
| GET/POST | `/admin/api/v1/kyc` | KYC রিভিউ |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | প্রোডাক্ট ম্যানেজমেন্ট |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU তৈরি করুন |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | রিজিয়নাল প্রাইস সেট করুন |
| GET/POST | `/admin/api/v1/orders` | অর্ডার ম্যানেজমেন্ট (রিফান্ড সহ) |
| GET | `/admin/api/v1/orders/export` | অর্ডার এক্সপোর্ট (.xlsx) |
| GET | `/admin/api/v1/users/export` | ইউজার এক্সপোর্ট (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | সাপ্লায়ার এক্সপোর্ট (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | চ্যানেল / ট্রানজেকশন / রিকনসিলিয়েশন |
| GET/POST | `/admin/api/v1/provisioning/*` | প্রোভিশনিং টাস্ক / হোস্ট ম্যানেজমেন্ট |
| GET/POST | `/admin/api/v1/suppliers/*` | সাপ্লায়ার অ্যাপ্রুভাল / সেটেলমেন্ট / উইথড্রয়াল |
| GET/POST | `/admin/api/v1/tickets` | টিকেট অ্যাসাইনমেন্ট / ক্লোজার |
| GET | `/admin/api/v1/reports/*` | রেভিনিউ / রিজিয়নাল / সাপ্লায়ার রিপোর্ট |
| GET | `/admin/api/v1/monitor/*` | মনিটরিং ড্যাশবোর্ড / রিসোর্স মেট্রিক্স |
| GET | `/admin/api/v1/audit-logs` | অডিট লগ |
| PUT | `/admin/api/v1/system/config` | সিস্টেম কনফিগ আপডেট |
