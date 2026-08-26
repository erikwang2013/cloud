# CloudPlatform ডিপ্লয়মেন্ট চেকলিস্ট

## ১. সার্ভার প্রয়োজনীয়তা

| আইটেম | ন্যূনতম কনফিগ | প্রস্তাবিত কনফিগ |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | ২ কোর | ৪ কোর+ |
| মেমরি | 4 GB | 8 GB+ |
| ডিস্ক | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (kvm-server প্রোভিশনিং সার্ভিস) | 1.75+ |

**খুলতে হবে এমন পোর্ট**: 80 (HTTP), 443 (HTTPS), 8787 (webman ইন্টারনাল, শুধু ইন্টারনাল নেটওয়ার্ক), 8788 (admin, শুধু ইন্টারনাল নেটওয়ার্ক), 50051 (kvm-server gRPC, শুধু ইন্টারনাল নেটওয়ার্ক), 2379 (etcd, শুধু ইন্টারনাল নেটওয়ার্ক, kvm-server রেজিস্ট্রেশন এনাবল থাকলে)

---

## ২. এনভায়রনমেন্ট ইনস্টলেশন

### 2.1 বেসিক ডিপেন্ডেন্সি

```bash
# সিস্টেম আপডেট
sudo apt update && sudo apt upgrade -y

# বেসিক টুল
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server প্রোভিশনিং সার্ভিস)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + এক্সটেনশন (ppa:ondrej/php দিয়ে)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# ভেরিফিকেশন
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 MySQL সিকিউর ইনিশিয়ালাইজেশন

```bash
sudo mysql_secure_installation
# root পাসওয়ার্ড সেট করুন, অ্যানোনিমাস ইউজার ডিলিট করুন, রিমোট root লগইন নিষিদ্ধ করুন, test ডেটাবেস ডিলিট করুন
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # PONG রিটার্ন হওয়া উচিত
```

---

## ৩. ডেটাবেস কনফিগারেশন

### 3.0 ওয়ান-ক্লিক ইনস্টলেশন উইজার্ড (সুপারিশকৃত)

প্রজেক্ট রুটে Web ইনস্টলেশন উইজার্ড আছে, স্বয়ংক্রিয়ভাবে ডেটাবেস টেবিল তৈরি, অ্যাডমিন তৈরি ও কনফিগ লেখা সম্পন্ন করে:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# ব্রাউজারে http://<সার্ভারIP>:8888 খুলুন
```

উইজার্ড স্টেপ:
1. এনভায়রনমেন্ট চেক (PHP ভার্সন, এক্সটেনশন, ডিরেক্টরি পারমিশন)
2. ডেটাবেস কনফিগ (হোস্ট, পোর্ট, ডেটাবেস নাম, ইউজারনেম, পাসওয়ার্ড, অটো ডেটাবেস তৈরি সাপোর্ট)
3. অ্যাডমিন অ্যাকাউন্ট সেটআপ (ইউজারনেম, পাসওয়ার্ড, ইমেইল)
4. ওয়ান-ক্লিক ইনস্টলেশন এক্সিকিউট (৪৬ টেবিল + সুপার অ্যাডমিন রোল + অ্যাডমিন অ্যাকাউন্ট + অটো .env জেনারেশন)

ইনস্টলেশনের পর ম্যানুয়ালি `service/.env`-এর ঐচ্ছিক কনফিগ (SMTP, Stripe, SMS ইত্যাদি) পূরণ করুন।

### 3.1 ম্যানুয়ালি ডেটাবেস ও ইউজার তৈরি

```sql
-- root দিয়ে লগইন করুন
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- অ্যাপ্লিকেশন ইউজার (রিড/রাইট)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- মাইগ্রেশন ইউজার (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- রিড-অনলি ইউজার (রিপোর্ট)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 সংযোগ ভেরিফিকেশন

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## ৪. কোড ডিপ্লয়মেন্ট

### 4.1 কোড পুল করা

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <repo_url> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 ডিপেন্ডেন্সি ইনস্টলেশন

```bash
# Service ডিপেন্ডেন্সি
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin ডিপেন্ডেন্সি
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## ৫. এনভায়রনমেন্ট কনফিগারেশন

### 5.1 Service .env ফাইল

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

`.env` এডিট করুন, প্রকৃত মান পূরণ করুন:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# ডেটাবেস
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<database_password>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT কী (জেনারেট: openssl rand -base64 32; সেট না করলে সার্ভিস স্টার্ট করতে অস্বীকৃত)
JWT_SECRET_KEY=<base64 random string>

# ট্রান্সমিশন এনক্রিপশন মাস্টার কী (জেনারেট: openssl rand -base64 32; 32 বাইট base64 এনকোডেড, admin ও service একই ফরম্যাট)
ENCRYPTION_MASTER_KEY=<base64-encoded 32-byte key>

# ফিল্ড এনক্রিপশন কী (base64 এনকোডেড; config/encryptable.php base64_decode করার পর ব্যবহার করে, প্লেইন স্ট্রিং দিলে MissingEncryptionKeyException থ্রো হবে)
ENCRYPTION_KEY=<base64-encoded key>
# এনক্রিপশন অ্যালগরিদম: ডিটারমিনিস্টিক কোয়েরি মোড শুধু ECB সাপোর্ট করে (aes-128-ecb / aes-256-ecb), CBC/GCM স্টার্টেই এরর থ্রো করে
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<custom random string>
HASHIDS_LENGTH=12

# Stripe পেমেন্ট
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio SMS
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP মেইল (symfony/mailer; SMTP_HOST কনফিগ না করলে পাঠানো রেকর্ড dev-stub স্ট্যাটাসে হয়)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(ইমপ্লিসিট TLS) / খালি
MAIL_FROM_ADDRESS=noreply@yourdomain.com

# Google OAuth
GOOGLE_OAUTH_CLIENT_ID=xxx.apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=GOCSPX-xxx
GOOGLE_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/auth/google/callback

# Apple Sign In
APPLE_OAUTH_CLIENT_ID=com.yourdomain.service
APPLE_TEAM_ID=xxx
APPLE_KEY_ID=xxx
APPLE_PRIVATE_KEY_PATH=/home/wwwroot/cloud-php/service/storage/apple/AuthKey_xxx.p8

# Facebook OAuth
FACEBOOK_OAUTH_CLIENT_ID=xxx
FACEBOOK_OAUTH_CLIENT_SECRET=xxx
FACEBOOK_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/auth/facebook/callback

# X (Twitter) OAuth
X_OAUTH_CLIENT_ID=xxx
X_OAUTH_CLIENT_SECRET=xxx
X_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/auth/x/callback

# Microsoft OAuth
MICROSOFT_OAUTH_CLIENT_ID=xxx
MICROSOFT_OAUTH_CLIENT_SECRET=xxx
MICROSOFT_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/auth/microsoft/callback

# LinkedIn OAuth
LINKEDIN_OAUTH_CLIENT_ID=xxx
LINKEDIN_OAUTH_CLIENT_SECRET=xxx
LINKEDIN_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/auth/linkedin/callback

# GitHub OAuth
GITHUB_OAUTH_CLIENT_ID=xxx
GITHUB_OAUTH_CLIENT_SECRET=xxx
GITHUB_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/auth/github/callback

# AWS (ঐচ্ছিক — ক্লাউড প্রোভাইডার ইন্টিগ্রেশন)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM পুশ)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# মেইনটেন্যান্স মোড
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (কান্ট্রি কোড, কমা-বিচ্ছিন্ন, ঐচ্ছিক)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook সিগনেচার কী
WEBHOOK_SECRET=<random string>

# এক্সচেঞ্জ রেট API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 ব্যাকআপ (ঐচ্ছিক)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 কী জেনারেশন

```bash
# প্রয়োজনীয় সব র্যান্ডম কী জেনারেট করুন
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 স্টোরেজ ডিরেক্টরি তৈরি

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## ৬. ডেটাবেস মাইগ্রেশন

### 6.1 মাইগ্রেশন ট্র্যাকিং টেবিল তৈরি

```sql
-- মাইগ্রেশন ইউজার দিয়ে লগইন করুন
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 মাইগ্রেশন এক্সিকিউট

```bash
cd /home/wwwroot/cloud-php/service

# মাইগ্রেশন ফাইলগুলো ক্রমানুসারে এক্সিকিউট করুন (স্ক্রিপ্ট /tmp/run_migrations.php-এ প্রস্তুত আছে)
php /tmp/run_migrations.php
```

অথবা এক-এক করে ম্যানুয়ালি এক্সিকিউট করুন (প্রোডাকশনে সাবধানী অপারেশনের জন্য উপযুক্ত):

```bash
cd /home/wwwroot/cloud-php/service
# আগে মাইগ্রেশন ফাইল লিস্ট দেখুন
ls -la database/migrations/

# এক-এক করে এক্সিকিউট (webman-scout ডিফল্ট database ড্রাইভ, Elasticsearch প্রয়োজন নেই)
php -r "
require 'vendor/autoload.php';
// কনফিগ লোড...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 মাইগ্রেশন রি-রান ও RBAC সিড

মাইগ্রেশন `php webman migrate` দিয়ে এক্সিকিউট হয় (`service/app/command/MigrateCommand.php`), ফাইল নামের ক্রম অনুযায়ী এক্সিকিউট হয়নি এমন মাইগ্রেশন প্রয়োগ করে। বেশিরভাগ মাইগ্রেশন এক-বারের টেবিল তৈরি, রি-রানের প্রয়োজন নেই; **একমাত্র ব্যতিক্রম RBAC সিড**:

- `2026_08_17_000001_seed_rbac_permissions.php` হলো **reset-টাইপ সিড**: এক্সিকিউটের সময় আগে `role_permission` / `permissions` / `roles` তিনটি টেবিলের সব রো `delete` করে, তারপর ফাইলের ম্যাট্রিক্স অনুযায়ী পুনরায় ইনসার্ট করে। তাই এই মাইগ্রেশন নিরাপদে রি-রান করা যায়, এবং **রি-রান করলে ডুপ্লিকেট রো তৈরি হয় না** (এক্সপ্লিসিট id, অটো-ইনক্রিমেন্টের প্রভাব নেই)।
- পুরনো ডেটাবেস (`2026_05_20_000006_create_rbac_permissions.php` যুগের ডেটা) আপগ্রেড করার সময়, কনভারজড পারমিশন ম্যাট্রিক্স পেতে সিড রি-রান করতে হবে:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **দ্রষ্টব্য**: রানটাইম পারমিশনের একমাত্র সত্যের উৎস `service/common/auth/Rbac.php` স্ট্যাটিক অ্যারে, **ডেটাবেসের ওপর নির্ভর করে না** — `RbacMiddleware` সরাসরি সেই অ্যারে পড়ে পারমিশন বিচার করে, DB সিড শুধু অ্যাডমিন সাইড ডিসপ্লের জন্য। `Rbac.php` পরিবর্তন করলে **অবশ্যই** সিড ফাইল আপডেট করতে হবে (`permissions()` ইউনিয়ন + `rolePerms()` প্রতি-রোল ম্যাট্রিক্স), `service/tests/auth/RbacSeedTest.php` টেস্টে দুটির ড্রিফট স্ট্যাটিকভাবে ইন্টারসেপ্ট হয়।
- সিড রোলব্যাক করলে সব রোল ও পারমিশন অ্যাসাইনমেন্ট খালি হয়ে যায় (`down()`-ও তিনটি টেবিল delete করে), প্রোডাকশনে `php webman migrate:rollback` চালানোর আগে কোনো অ্যাডমিন সাইড অনলাইন অপারেশন নেই তা নিশ্চিত করুন।

---

## ৭. Nginx কনফিগারেশন

### 7.1 কনফিগ ফাইল তৈরি

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API সার্ভিস
server {
    listen 80;
    server_name api.yourdomain.com;

    # ফোর্স HTTPS (সার্টিফিকেট কনফিগের পর কমেন্ট খুলুন)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API রিকোয়েস্ট webman-এ প্রক্সি
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # স্ট্যাটিক ফাইল সরাসরি রিড — শুধু uploads সাবডিরেক্টরি এক্সপোজ (backups/apple/firebase ইত্যাদিতে সংবেদনশীল ডেটা আছে, পাবলিক অ্যাক্সেস নিষিদ্ধ)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # হেলথ চেকে প্রক্সি ক্যাশ লাগবে না
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # রিকোয়েস্ট বডি সাইজ লিমিট
    client_max_body_size 10M;
}

# অ্যাডমিন প্যানেল
server {
    listen 80;
    server_name admin.yourdomain.com;

    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/admin/public;

    location / {
        proxy_pass http://127.0.0.1:8788;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    client_max_body_size 10M;
}

# স্ট্যাটাস পেজ — ঐচ্ছিক
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 সাইট এনাবল

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # কনফিগ সিনট্যাক্স চেক
sudo systemctl reload nginx
```

---

## ৮. HTTPS সার্টিফিকেট

### 8.1 Certbot (Let's Encrypt) ব্যবহার

```bash
sudo apt install -y certbot python3-certbot-nginx

# সার্টিফিকেট নেওয়া
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# অটো রিনিউ ভেরিফিকেশন
sudo certbot renew --dry-run
```

### 8.2 Nginx-এ HTTPS রিডাইরেক্ট কমেন্ট খোলা

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# return 301 https://... এর কমেন্ট খুলুন
sudo nginx -t
sudo systemctl reload nginx
```

---

## ৯. সার্ভিস স্টার্ট

### 9.1 webman স্টার্ট

```bash
# Service (পোর্ট 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (পোর্ট 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 স্টার্ট ভেরিফিকেশন

```bash
# প্রসেস চেক
ps aux | grep webman

# পোর্ট চেক
ss -tlnp | grep -E '8787|8788'

# হেলথ চেক
curl http://127.0.0.1:8787/health
# রিটার্ন হওয়া উচিত: {"code":0,"message":"ok"}
```

### 9.3 systemd অটো-স্টার্ট কনফিগ

```bash
sudo nano /etc/systemd/system/cloud-platform.service
```

```ini
[Unit]
Description=CloudPlatform Webman Service
After=network.target mysql.service redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/home/wwwroot/cloud-php/service
ExecStart=/usr/bin/php /home/wwwroot/cloud-php/service/start.php start
ExecStop=/usr/bin/php /home/wwwroot/cloud-php/service/start.php stop
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo nano /etc/systemd/system/cloud-platform-admin.service
```

```ini
[Unit]
Description=CloudPlatform Admin Panel
After=network.target mysql.service redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/home/wwwroot/cloud-php/admin
ExecStart=/usr/bin/php /home/wwwroot/cloud-php/admin/start.php start
ExecStop=/usr/bin/php /home/wwwroot/cloud-php/admin/start.php stop
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable cloud-platform cloud-platform-admin
sudo systemctl start cloud-platform cloud-platform-admin
sudo systemctl status cloud-platform cloud-platform-admin
```

### 9.4 kvm-server স্টার্ট (Rust প্রোভিশনিং সার্ভিস)

kvm-server হলো স্বাধীন Rust gRPC প্রোভিশনিং সার্ভিস (`infrastructure/kvm-server`, e-cat workspace),
VM তৈরি/স্ট্যাটাস কোয়েরি প্রদান করে, এবং etcd রেজিস্ট্রেশন + lease হার্টবিট দিয়ে PHP সাইড (KvmClient/RegistryProcess)
ডিসকভারি করে। **বর্তমান ড্রাইভ সিমুলেটেড (simulated), libvirt (virsh) রিয়েল ড্রাইভ Phase 2** — ডিপ্লয়মেন্টে
অবশ্যই এক্সপ্লিসিটলি `KVM_DRIVER=simulated` সেট করতে হবে, অন্যথায় ডিফল্ট virsh ড্রাইভ NotImplemented রিটার্ন করবে।

```bash
cd /home/wwwroot/cloud-php/infrastructure
cargo build --release -p kvm-server

cat > /etc/systemd/system/kvm-server.service <<'EOF'
[Unit]
Description=CloudPlatform KVM Server (Rust gRPC provisioning)
After=network.target mysql.service redis-server.service etcd.service

[Service]
Type=simple
WorkingDirectory=/home/wwwroot/cloud-php/infrastructure/kvm-server
ExecStart=/home/wwwroot/cloud-php/infrastructure/target/release/kvm-server
EnvironmentFile=/home/wwwroot/cloud-php/infrastructure/kvm-server/.env
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable kvm-server
sudo systemctl start kvm-server
```

এনভায়রনমেন্ট ভেরিয়েবল (`infrastructure/kvm-server/.env`, `service/.env`-এর সংশ্লিষ্ট মান দেখুন):

| ভেরিয়েবল | বাধ্যতামূলক | বিবরণ |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN (SQLx, service-এর সাথে একই ডেটাবেস) |
| `KVM_REDIS_URL` | ✅ | Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC কলকারী অথেনটিকেশন token (PHP সাইড কনফিগের সাথে মিলতে হবে) |
| `KVM_DRIVER` | ✅ | `simulated` (বর্তমানে একমাত্র উপলব্ধ; `virsh` Phase 2) |
| `KVM_ADDR` | — | HTTP ম্যানেজমেন্ট ইন্টারফেস ঠিকানা, ডিফল্ট `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC ঠিকানা, ডিফল্ট `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd এন্ডপয়েন্ট, সেট করলে রেজিস্ট্রেশন/ডিসকভারি এনাবল (যেমন `http://127.0.0.1:2379`) |

---

## ১০. ক্রন টাস্ক

সিস্টেমে বিল্ট-ইন cron শিডিউলার প্রসেস আছে (`config/process.php`-এ `App\Cron\CronRunner` রেজিস্টার্ড), প্রতি মিনিটে
`service/config/cron.php`-এর ৫-ফিল্ড cron এক্সপ্রেশন মূল্যায়ন করে সংশ্লিষ্ট টাস্ক এক্সিকিউট করে, সার্ভিস স্টার্টের সাথে স্বয়ংক্রিয়ভাবে চলে,
**বাহ্যিক crontab প্রয়োজন নেই**। সাথে `queue_consumer` প্রসেস provisioning / notification_* ইত্যাদি
Redis কিউ মেসেজ কনজিউম করে।

```bash
# প্রসেস স্ট্যাটাস দেখা (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

টাস্ক তালিকা (`service/config/cron.php`):

| cron এক্সপ্রেশন | টাস্ক |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — এক্সচেঞ্জ রেট সিঙ্ক |
| `37 2 * * *` | PaymentReconcile — পেমেন্ট রিকনসিলিয়েশন |
| `17 4 * * 1` | SupplierSettlement — সাপ্লায়ার সেটেলমেন্ট |
| `23 6 * * *` | ExpirationCheck — রিসোর্স/ডোমেইন এক্সপায়ারি চেক |
| `43 7,19 * * *` | SslCertificateCheck — SSL সার্টিফিকেট চেক (ডেটাবেস) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — রিসোর্স মেট্রিক্স কালেকশন |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — অনলাইন সার্টিফিকেট প্রোব |
| `7 * * * *` | UsageAggregator::aggregate — ইউসেজ অ্যাগ্রিগেশন |
| `41 3 * * *` | BillingEngine::runDaily — দৈনিক বিলিং |
| `11,41 * * * *` | SuspendCheck — বকেয়া সাসপেন্ড চেক |

---

## ১১. ডিপ্লয়মেন্ট ভেরিফিকেশন চেকলিস্ট

আইটেম অনুযায়ী চেক করুন, চেকমার্ক দিন:

### ইনফ্রাস্ট্রাকচার
- [ ] MySQL সংযোগযোগ্য: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis সংযোগযোগ্য: `redis-cli ping` → `PONG`
- [ ] PHP ভার্সন >= 8.2: `php -v`
- [ ] Composer ডিপেন্ডেন্সি ইনস্টল সফল
- [ ] Rust টুলচেইন ইনস্টল (kvm-server): `rustc --version`
- [ ] kvm-server চলছে: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` পাস

### অ্যাপ্লিকেশন কনফিগ
- [ ] `.env` ফাইলের সব বাধ্যতামূলক আইটেম কনফিগ করা
- [ ] JWT কী জেনারেট করে `.env`-এ লেখা
- [ ] এনক্রিপশন মাস্টার কী জেনারেট করে `.env`-এ লেখা
- [ ] Stripe কী কনফিগ করা (পেমেন্ট ফিচার লাগলে)
- [ ] স্টোরেজ ডিরেক্টরি পারমিশন সঠিক (`chmod 755 storage runtime`)

### ডেটাবেস
- [ ] ডেটাবেস ও ইউজার তৈরি
- [ ] মাইগ্রেশন সব সফল: `SELECT COUNT(*) FROM migrations` → ১৯ হওয়া উচিত
- [ ] কোর টেবিল আছে: `SHOW TABLES LIKE 'users'`

### সার্ভিস
- [ ] Nginx কনফিগ সিনট্যাক্স সঠিক: `nginx -t`
- [ ] webman প্রসেস চলছে: `ps aux | grep webman`
- [ ] পোর্ট লিসেনিং সঠিক: `ss -tlnp | grep 8787`
- [ ] হেলথ চেক পাস: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS সার্টিফিকেট বৈধ: `curl -I https://api.yourdomain.com/health`

### API এন্ডপয়েন্ট নমুনা চেক
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (বৈধ JSON)
- [ ] `POST /api/auth/login` (বডি ছাড়া) → 422 (প্যারামিটার ভ্যালিডেশন)
- [ ] `GET /api/user/profile` (token ছাড়া) → 401 (অথেনটিকেশন)
- [ ] ভার্সন হেডার: `curl -H 'X-Api-Version: v99' /api/products` → 400

### ক্রন টাস্ক
- [ ] crontab কনফিগ করা
- [ ] লগ ডিরেক্টরি আছে ও রাইটযোগ্য

### সিকিউরিটি
- [ ] `.env` ফাইল পারমিশন 600
- [ ] MySQL রিমোট পোর্ট খোলা নেই (বা শুধু ইন্টারনাল নেটওয়ার্ক)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS এনাবল
- [ ] মেইনটেন্যান্স মোড উপলব্ধ (MAINTENANCE_MODE কমেন্ট খুলে চালু করা যায়)

---

## ১২. সাধারণ সমস্যা

### webman স্টার্ট ব্যর্থ

```bash
# ফোরগ্রাউন্ডে স্টার্ট করে এরর দেখা
php start.php start
# সাধারণ কারণ: পোর্ট দখল, .env কনফিগ ভুল, Redis/MySQL অরিচেবল
```

### মাইগ্রেশন এক্সিকিউট ব্যর্থ

```bash
# ডেটাবেস সংযোগ চেক
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<password>');"
# টেবিল আগে থেকে আছে কিনা চেক
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman মারা যেতে পারে
sudo systemctl status cloud-platform
# রিস্টার্ট
sudo systemctl restart cloud-platform
```

### লগ অবস্থান

| লগ | পাথ |
|------|------|
| webman নিজে | `service/runtime/logs/workerman.log` |
| অ্যাপ্লিকেশন লগ | `service/runtime/logs/` |
| Cron লগ | `service/runtime/logs/cron.log` |
| Nginx অ্যাক্সেস | `/var/log/nginx/access.log` |
| Nginx এরর | `/var/log/nginx/error.log` |
| MySQL স্লো কোয়েরি | `/var/log/mysql/slow.log` |

### পারফরম্যান্স টিউনিং

```bash
# webman worker সংখ্যা (config/server.php)
'count' => 4  # CPU কোর সংখ্যা সেট করার পরামর্শ

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# নিচের কনফিগ নিশ্চিত করুন:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL বাফার পুল
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # উপলব্ধ মেমরির 50-70% সেট করুন
```
