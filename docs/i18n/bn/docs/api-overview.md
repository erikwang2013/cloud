# API ওভারভিউ

> সম্পূর্ণ ইন্টারফেস রেফারেন্স (২০০+ এন্ডপয়েন্ট, রিকোয়েস্ট/রেসপন্স উদাহরণ ও এরর কোড সহ): [API ইন্টারফেস ডকুমেন্ট](api-reference.md)
> অনলাইন ডিবাগিং: [service API ডকুমেন্ট](http://localhost:8787/apidoc) · [admin API ডকুমেন্ট](http://localhost:8788/apidoc)

## পাবলিক এন্ডপয়েন্ট

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/health` | হেলথ চেক |
| POST | `/api/auth/register` | ইউজার রেজিস্ট্রেশন (রিকোয়েস্ট বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/auth/login` | ইউজার লগইন (রিকোয়েস্ট বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/auth/refresh` | টোকেন রিফ্রেশ (রিকোয়েস্ট বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/captcha/create` | ক্লিক ক্যাপচা জেনারেট করুন (লগইন/রেজিস্ট্রেশনের আগে) |
| GET | `/api/products` | প্রোডাক্ট লিস্ট (ক্যাটাগরি/রিজিয়ন/কীওয়ার্ড ফিল্টার সাপোর্ট) |
| GET | `/api/products/{id}` | প্রোডাক্ট ডিটেইল (id একটি hashid স্ট্রিং) |
| GET | `/api/regions` | উপলব্ধ রিজিয়ন |
| GET | `/api/domain/check/{domain}/{tld}` | ডোমেইন অ্যাভেইলেবিলিটি চেক |
| GET | `/api/domain/tlds` | রেজিস্ট্রেবল টিএলডি লিস্ট |
| POST | `/api/payments/webhook/stripe` | Stripe কলব্যাক (সিগনেচার ভ্যালিডেশন, এনক্রিপশন প্রয়োজন নেই) |

## অথেনটিকেটেড এন্ডপয়েন্ট (Bearer Token প্রয়োজন)

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/api/user/profile` | ব্যক্তিগত তথ্য |
| PUT | `/api/user/profile` | তথ্য আপডেট |
| POST | `/api/user/kyc` | রিয়েল-নেম ভেরিফিকেশন জমা দিন |
| GET | `/api/user/balance` | অ্যাকাউন্ট ব্যালেন্স |
| GET/POST | `/api/cart` | শপিং কার্ট |
| POST/GET | `/api/orders` | অর্ডার |
| GET | `/api/orders/{id}/payment-methods` | উপলব্ধ পেমেন্ট পদ্ধতি |
| POST | `/api/orders/{id}/pay` | পেমেন্ট শুরু করুন |
| GET/POST | `/api/resources` | আমার রিসোর্স |
| GET | `/api/resources/{id}/status` | রিসোর্স স্ট্যাটাস |
| GET | `/api/resources/{id}/console` | VNC কনসোল লিংক |
| GET/POST | `/api/tickets` | টিকেট |
| POST | `/api/tickets/{id}/reply` | টিকেট রিপ্লাই |
| GET/POST | `/api/dns/{domain}` | DNS ম্যানেজমেন্ট |
| POST | `/api/supplier/apply` | সাপ্লায়ার আবেদন |
| GET | `/api/supplier/settlements` | সাপ্লায়ার সেটেলমেন্ট রেকর্ড |
| POST | `/api/supplier/withdraw` | সাপ্লায়ার উইথড্রয়াল |

> **বিবরণ:** সকল API রিকোয়েস্টে `X-Api-Version: v1` হেডার বহন করতে হবে (অনুপস্থিত থাকলে ডিফল্ট `v1`, `VersionMiddleware` দিয়ে ভ্যালিডেট হয়)। অথেনটিকেটেড ও অ্যাডমিন ইন্টারফেসের রিকোয়েস্ট/রেসপন্স `EncryptionMiddleware` দিয়ে প্রসেস হয়। ক্লায়েন্ট `X-Encrypted: 1` হেডার সেট করে, রিকোয়েস্ট বডি ফরম্যাট `{"payload": "<base64(AES-256-GCM)>"}`; রেসপন্স বডিও এনক্রিপ্ট করে `payload` ফিল্ডে মোড়ানো হয়। সকল ইন্টিজার ID API রেসপন্সে অটোমেটিক ১২ অক্ষরের Hashid স্ট্রিংয়ে রূপান্তরিত হয়; রিকোয়েস্টের Hashid স্ট্রিং `HashidRequestMiddleware` দিয়ে অটো-ডিকোড হয়ে ইন্টিজার ID-তে ফিরে আসে।

## অ্যাডমিন এন্ডপয়েন্ট

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/admin/api/dashboard` | অপারেশনাল ড্যাশবোর্ড |
| GET/PUT | `/admin/api/users` | ইউজার ম্যানেজমেন্ট |
| GET/POST | `/admin/api/kyc` | KYC রিভিউ |
| GET/POST/PUT/DELETE | `/admin/api/products` | প্রোডাক্ট ম্যানেজমেন্ট |
| POST | `/admin/api/products/{productId}/skus` | SKU তৈরি |
| POST | `/admin/api/skus/{skuId}/region-price` | রিজিয়নাল প্রাইস সেট করুন |
| GET/POST | `/admin/api/orders` | অর্ডার ম্যানেজমেন্ট (রিফান্ড সহ) |
| GET | `/admin/api/orders/export` | অর্ডার এক্সপোর্ট (.xlsx) |
| GET | `/admin/api/users/export` | ইউজার এক্সপোর্ট (.xlsx) |
| GET | `/admin/api/suppliers/export` | সাপ্লায়ার এক্সপোর্ট (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | পেমেন্ট চ্যানেল / ট্রানজেকশন / রিকনসিলিয়েশন |
| GET/POST | `/admin/api/provisioning/*` | ডেলিভারি টাস্ক / হোস্ট ম্যানেজমেন্ট |
| GET/POST | `/admin/api/suppliers/*` | সাপ্লায়ার অ্যাপ্রুভাল / সেটেলমেন্ট / উইথড্রয়াল |
| GET/POST | `/admin/api/tickets` | টিকেট অ্যাসাইনমেন্ট / ক্লোজার |
| GET | `/admin/api/reports/*` | রেভিনিউ / রিজিয়ন / সাপ্লায়ার রিপোর্ট |
| GET | `/admin/api/monitor/*` | মনিটরিং প্যানেল / রিসোর্স মেট্রিক্স |
| GET | `/admin/api/audit-logs` | অডিট লগ |
| PUT | `/admin/api/system/config` | সিস্টেম কনফিগারেশন |
