# API ওভারভিউ

> সম্পূর্ণ ইন্টারফেস রেফারেন্স (২০০+ এন্ডপয়েন্ট, রিকোয়েস্ট/রেসপন্স উদাহরণ ও এরর কোড সহ): [API ইন্টারফেস ডকুমেন্ট](api-reference.md)
> অনলাইন ডিবাগিং: [service API ডকুমেন্ট](http://localhost:8787/apidoc) · [admin API ডকুমেন্ট](http://localhost:8788/apidoc)

## পাবলিক এন্ডপয়েন্ট

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/health` | হেলথ চেক |
| POST | `/api/v1/auth/register` | ইউজার রেজিস্ট্রেশন (রিকোয়েস্ট বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/v1/auth/login` | ইউজার লগইন (রিকোয়েস্ট বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/v1/auth/refresh` | টোকেন রিফ্রেশ (রিকোয়েস্ট বডি AES-256-GCM এনক্রিপ্টেড) |
| POST | `/api/v1/captcha/create` | ক্লিক ক্যাপচা জেনারেট করুন (লগইন/রেজিস্ট্রেশনের আগে) |
| GET | `/api/v1/products` | প্রোডাক্ট লিস্ট (ক্যাটাগরি/রিজিয়ন/কীওয়ার্ড ফিল্টার সাপোর্ট) |
| GET | `/api/v1/products/{id}` | প্রোডাক্ট ডিটেইল (id একটি hashid স্ট্রিং) |
| GET | `/api/v1/regions` | উপলব্ধ রিজিয়ন |
| GET | `/api/v1/domain/check/{domain}/{tld}` | ডোমেইন অ্যাভেইলেবিলিটি চেক |
| GET | `/api/v1/domain/tlds` | রেজিস্ট্রেবল টিএলডি লিস্ট |
| POST | `/api/v1/payments/webhook/stripe` | Stripe কলব্যাক (সিগনেচার ভ্যালিডেশন, এনক্রিপশন প্রয়োজন নেই) |

## অথেনটিকেটেড এন্ডপয়েন্ট (Bearer Token প্রয়োজন)

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/api/v1/user/profile` | ব্যক্তিগত তথ্য |
| PUT | `/api/v1/user/profile` | তথ্য আপডেট |
| POST | `/api/v1/user/kyc` | রিয়েল-নেম ভেরিফিকেশন জমা দিন |
| GET | `/api/v1/user/balance` | অ্যাকাউন্ট ব্যালেন্স |
| GET/POST | `/api/v1/cart` | শপিং কার্ট |
| POST/GET | `/api/v1/orders` | অর্ডার |
| GET | `/api/v1/orders/{id}/payment-methods` | উপলব্ধ পেমেন্ট পদ্ধতি |
| POST | `/api/v1/orders/{id}/pay` | পেমেন্ট শুরু করুন |
| GET/POST | `/api/v1/resources` | আমার রিসোর্স |
| GET | `/api/v1/resources/{id}/status` | রিসোর্স স্ট্যাটাস |
| GET | `/api/v1/resources/{id}/console` | VNC কনসোল লিংক |
| GET/POST | `/api/v1/cdn/domains` | CDN ডোমেইন লিস্ট / তৈরি (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | CDN ডোমেইন ডিটেইল / ডিলিট |
| POST | `/api/v1/cdn/domains/{id}/purge` | ক্যাশ ক্লিয়ার (ইডেম্পোটেন্ট, সর্বোচ্চ ১০০টি URL) |
| GET/POST | `/api/v1/tickets` | টিকেট |
| POST | `/api/v1/tickets/{id}/reply` | টিকেট রিপ্লাই |
| GET/POST | `/api/v1/dns/{domain}` | DNS ম্যানেজমেন্ট |
| POST | `/api/v1/supplier/apply` | সাপ্লায়ার আবেদন |
| GET | `/api/v1/supplier/settlements` | সাপ্লায়ার সেটেলমেন্ট রেকর্ড |
| POST | `/api/v1/supplier/withdraw` | সাপ্লায়ার উইথড্রয়াল |

> **বিবরণ:** API ভার্সন URL পাথে থাকে, যেমন `/api/v1/...`, `VersionMiddleware` দিয়ে ভ্যালিডেট হয়। অথেনটিকেটেড ও অ্যাডমিন ইন্টারফেসের রিকোয়েস্ট/রেসপন্স `EncryptionMiddleware` দিয়ে প্রসেস হয়। ক্লায়েন্ট `X-Encrypted: 1` হেডার সেট করে, রিকোয়েস্ট বডি ফরম্যাট `{"payload": "<base64(AES-256-GCM)>"}`; রেসপন্স বডিও এনক্রিপ্ট করে `payload` ফিল্ডে মোড়ানো হয়। সকল ইন্টিজার ID API রেসপন্সে অটোমেটিক ১২ অক্ষরের Hashid স্ট্রিংয়ে রূপান্তরিত হয়; রিকোয়েস্টের Hashid স্ট্রিং `HashidRequestMiddleware` দিয়ে অটো-ডিকোড হয়ে ইন্টিজার ID-তে ফিরে আসে।

## অ্যাডমিন এন্ডপয়েন্ট

| মেথড | পাথ | বিবরণ |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | অপারেশনাল ড্যাশবোর্ড |
| GET/PUT | `/admin/api/v1/users` | ইউজার ম্যানেজমেন্ট |
| GET/POST | `/admin/api/v1/kyc` | KYC রিভিউ |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | প্রোডাক্ট ম্যানেজমেন্ট |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU তৈরি |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | রিজিয়নাল প্রাইস সেট করুন |
| GET/POST | `/admin/api/v1/orders` | অর্ডার ম্যানেজমেন্ট (রিফান্ড সহ) |
| GET | `/admin/api/v1/orders/export` | অর্ডার এক্সপোর্ট (.xlsx) |
| GET | `/admin/api/v1/users/export` | ইউজার এক্সপোর্ট (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | সাপ্লায়ার এক্সপোর্ট (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | পেমেন্ট চ্যানেল / ট্রানজেকশন / রিকনসিলিয়েশন |
| GET/POST | `/admin/api/v1/provisioning/*` | ডেলিভারি টাস্ক / হোস্ট ম্যানেজমেন্ট |
| GET/PUT | `/admin/api/v1/cdn/domains` | CDN ডোমেইন ম্যানেজমেন্ট (প্ল্যান পরিবর্তন) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | প্রোভাইডার অ্যাকাউন্ট ক্রেডেনশিয়াল ম্যানেজমেন্ট (CDN/ডেলিভারি শেয়ার্ড, Encryptable এনক্রিপশন) |
| GET/POST | `/admin/api/v1/suppliers/*` | সাপ্লায়ার অ্যাপ্রুভাল / সেটেলমেন্ট / উইথড্রয়াল |
| GET/POST | `/admin/api/v1/tickets` | টিকেট অ্যাসাইনমেন্ট / ক্লোজার |
| GET | `/admin/api/v1/reports/*` | রেভিনিউ / রিজিয়ন / সাপ্লায়ার রিপোর্ট |
| GET | `/admin/api/v1/monitor/*` | মনিটরিং প্যানেল / রিসোর্স মেট্রিক্স |
| GET | `/admin/api/v1/audit-logs` | অডিট লগ |
| PUT | `/admin/api/v1/system/config` | সিস্টেম কনফিগারেশন |
