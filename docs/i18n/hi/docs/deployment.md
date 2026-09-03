# CloudPlatform डिप्लॉयमेंट चेकलिस्ट

## एक, सर्वर आवश्यकताएँ

| आइटम | न्यूनतम कॉन्फ़िगरेशन | अनुशंसित कॉन्फ़िगरेशन |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 कोर | 4 कोर+ |
| मेमोरी | 4 GB | 8 GB+ |
| डिस्क | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (kvm-server सप्लाई सेवा) | 1.75+ |

**खोले जाने वाले पोर्ट**: 80 (HTTP), 443 (HTTPS), 8787 (webman, केवल इंट्रानेट), 8788 (admin, केवल इंट्रानेट), 50051 (kvm-server gRPC, केवल इंट्रानेट), 2379 (etcd, केवल इंट्रानेट, kvm-server रजिस्ट्री सक्षम होने पर)

---

## दो, पर्यावरण इंस्टॉलेशन

### 2.1 बेसिक निर्भरताएँ

```bash
# सिस्टम अपडेट
sudo apt update && sudo apt upgrade -y

# बेसिक टूल्स
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server सप्लाई सेवा)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + एक्सटेंशन (ppa:ondrej/php के माध्यम से)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# सत्यापन
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 MySQL सुरक्षित इनिशियलाइज़ेशन

```bash
sudo mysql_secure_installation
# root पासवर्ड सेट करें, अनाम उपयोगकर्ता हटाएँ, रिमोट root लॉगिन रोकें, test डेटाबेस हटाएँ
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # PONG लौटना चाहिए
```

---

## तीन, डेटाबेस कॉन्फ़िगरेशन

### 3.0 वन-क्लिक इंस्टॉलेशन विज़ार्ड (अनुशंसित)

प्रोजेक्ट रूट में Web इंस्टॉलेशन विज़ार्ड उपलब्ध है, जो डेटाबेस टेबल निर्माण, एडमिन निर्माण और कॉन्फ़िगरेशन लेखन स्वचालित रूप से पूरा कर सकता है:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# ब्राउज़र में http://<सर्वरIP>:8888 खोलें
```

विज़ार्ड चरण:
1. पर्यावरण जाँच (PHP संस्करण, एक्सटेंशन, निर्देशिका अनुमतियाँ)
2. डेटाबेस कॉन्फ़िगरेशन (होस्ट, पोर्ट, डेटाबेस नाम, उपयोगकर्ता नाम, पासवर्ड, स्वचालित डेटाबेस निर्माण समर्थित)
3. एडमिन खाता सेटअप (उपयोगकर्ता नाम, पासवर्ड, ईमेल)
4. वन-क्लिक इंस्टॉलेशन निष्पादन (46 टेबलें + सुपर एडमिन रोल + एडमिन खाता + स्वचालित .env फ़ाइल जनरेशन)

इंस्टॉलेशन पूर्ण होने के बाद `service/.env` में वैकल्पिक कॉन्फ़िगरेशन (SMTP, Stripe, SMS आदि) मैन्युअल रूप से पूरक करें।

### 3.1 मैन्युअल डेटाबेस और उपयोगकर्ता निर्माण

```sql
-- root के रूप में लॉगिन करें
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- एप्लिकेशन उपयोगकर्ता (रीड/राइट)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<मजबूत पासवर्ड>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- माइग्रेशन उपयोगकर्ता (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<मजबूत पासवर्ड>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- केवल-पढ़ने वाला उपयोगकर्ता (रिपोर्ट)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<मजबूत पासवर्ड>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 कनेक्शन सत्यापित करें

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## चार, कोड डिप्लॉयमेंट

### 4.1 कोड क्लोन करें

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <रिपोज़िटरी पता> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 निर्भरताएँ इंस्टॉल करें

```bash
# Service निर्भरताएँ
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin निर्भरताएँ
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## पांच, पर्यावरण कॉन्फ़िगरेशन

### 5.1 Service .env फ़ाइल

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

`.env` संपादित करें और वास्तविक मान भरें:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# डेटाबेस
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<डेटाबेस पासवर्ड>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT कुंजी (जनरेट करें: openssl rand -base64 32; सेट न होने पर सेवा शुरू होने से इनकार करती है)
JWT_SECRET_KEY=<base64 रैंडम स्ट्रिंग>

# ट्रांसमिशन एन्क्रिप्शन मास्टर कुंजी (जनरेट करें: openssl rand -base64 32; 32 बाइट base64 एन्कोडेड, admin और service समान फॉर्मेट)
ENCRYPTION_MASTER_KEY=<base64 एन्कोडेड 32 बाइट कुंजी>

# फ़ील्ड एन्क्रिप्शन कुंजी (base64 एन्कोडेड; config/encryptable.php base64_decode करके उपयोग करता है, सीधे प्लेन स्ट्रिंग देने पर MissingEncryptionKeyException आएगा)
ENCRYPTION_KEY=<base64 एन्कोडेड कुंजी>
# एन्क्रिप्शन एल्गोरिदम: डिटरमिनिस्टिक क्वेरी मोड केवल ECB समर्थित (aes-128-ecb / aes-256-ecb), CBC/GCM स्टार्टअप पर त्रुटि देगा
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<कस्टम रैंडम स्ट्रिंग>
HASHIDS_LENGTH=12

# Stripe पेमेंट
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio SMS
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP ईमेल (symfony/mailer; SMTP_HOST कॉन्फ़िगर न होने पर भेजे गए रिकॉर्ड dev-stub स्थिति में)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(इम्प्लिसिट TLS) / खाली
MAIL_FROM_ADDRESS=noreply@yourdomain.com

# Google OAuth
GOOGLE_OAUTH_CLIENT_ID=xxx.apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=GOCSPX-xxx
GOOGLE_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/v1/auth/google/callback

# Apple Sign In
APPLE_OAUTH_CLIENT_ID=com.yourdomain.service
APPLE_TEAM_ID=xxx
APPLE_KEY_ID=xxx
APPLE_PRIVATE_KEY_PATH=/home/wwwroot/cloud-php/service/storage/apple/AuthKey_xxx.p8

# Facebook OAuth
FACEBOOK_OAUTH_CLIENT_ID=xxx
FACEBOOK_OAUTH_CLIENT_SECRET=xxx
FACEBOOK_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/v1/auth/facebook/callback

# X (Twitter) OAuth
X_OAUTH_CLIENT_ID=xxx
X_OAUTH_CLIENT_SECRET=xxx
X_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/v1/auth/x/callback

# Microsoft OAuth
MICROSOFT_OAUTH_CLIENT_ID=xxx
MICROSOFT_OAUTH_CLIENT_SECRET=xxx
MICROSOFT_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/v1/auth/microsoft/callback

# LinkedIn OAuth
LINKEDIN_OAUTH_CLIENT_ID=xxx
LINKEDIN_OAUTH_CLIENT_SECRET=xxx
LINKEDIN_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/v1/auth/linkedin/callback

# GitHub OAuth
GITHUB_OAUTH_CLIENT_ID=xxx
GITHUB_OAUTH_CLIENT_SECRET=xxx
GITHUB_OAUTH_REDIRECT_URI=https://api.yourdomain.com/api/v1/auth/github/callback

# AWS (वैकल्पिक — क्लाउड विक्रेता संपर्क)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM पुश)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# मेंटेनेंस मोड
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (देश कोड, कॉमा-पृथक, वैकल्पिक)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook हस्ताक्षर कुंजी
WEBHOOK_SECRET=<रैंडम स्ट्रिंग>

# विनिमय दर API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 बैकअप (वैकल्पिक)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 कुंजियाँ जनरेट करें

```bash
# सभी आवश्यक रैंडम कुंजियाँ जनरेट करें
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 स्टोरेज निर्देशिकाएँ बनाएँ

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## छह, डेटाबेस माइग्रेशन

### 6.1 माइग्रेशन ट्रैकिंग टेबल बनाएँ

```sql
-- माइग्रेशन उपयोगकर्ता के रूप में लॉगिन करें
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 माइग्रेशन निष्पादित करें

```bash
cd /home/wwwroot/cloud-php/service

# माइग्रेशन फ़ाइलें क्रम से निष्पादित करें (स्क्रिप्ट /tmp/run_migrations.php में तैयार है)
php /tmp/run_migrations.php
```

या एक-एक करके मैन्युअल रूप से निष्पादित करें (प्रोडक्शन पर्यावरण में सावधानीपूर्वक संचालन के लिए उपयुक्त):

```bash
cd /home/wwwroot/cloud-php/service
# पहले माइग्रेशन फ़ाइल सूची देखें
ls -la database/migrations/

# एक-एक करके निष्पादित करें (webman-scout डिफ़ॉल्ट database ड्राइवर, Elasticsearch आवश्यक नहीं)
php -r "
require 'vendor/autoload.php';
// कॉन्फ़िगरेशन लोड करें...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 माइग्रेशन री-रन और RBAC सीड

माइग्रेशन `php webman migrate` द्वारा निष्पादित होते हैं (`service/app/command/MigrateCommand.php`), फ़ाइल नाम क्रम में बिना निष्पादित माइग्रेशन लागू करता है। अधिकांश माइग्रेशन एक-बार टेबल निर्माण हैं, री-रन आवश्यक नहीं; **एकमात्र अपवाद RBAC सीड** है:

- `2026_08_17_000001_seed_rbac_permissions.php` **reset-प्रकार का सीड** है: निष्पादन पर पहले `role_permission` / `permissions` / `roles` तीन टेबलों की सभी पंक्तियाँ `delete` करता है, फिर फ़ाइल में मैट्रिक्स के अनुसार पुनः इंसर्ट करता है। इसलिए यह माइग्रेशन सुरक्षित रूप से री-रन हो सकता है, और **री-रन से डुप्लीकेट पंक्तियाँ नहीं बनेंगी** (स्पष्ट id, ऑटो-इन्क्रीमेंट से अप्रभावित)।
- पुराने डेटाबेस (जिन पर `2026_05_20_000006_create_rbac_permissions.php` युग का डेटा चल चुका है) अपग्रेड करते समय, संगठित अनुमति मैट्रिक्स पाने के लिए सीड री-रन करना आवश्यक है:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **ध्यान दें**: रनटाइम अनुमतियों का एकमात्र सत्य स्रोत `service/common/auth/Rbac.php` स्थैतिक सरणी है, **डेटाबेस पर निर्भर नहीं** — `RbacMiddleware` सीधे उस सरणी को पढ़कर अनुमति तय करता है, DB सीड केवल एडमिन पक्ष प्रदर्शन के लिए। `Rbac.php` संशोधित करते समय **अनिवार्य रूप से** सीड फ़ाइल भी अपडेट करें (`permissions()` यूनियन + `rolePerms()` प्रति-रोल मैट्रिक्स), `service/tests/auth/RbacSeedTest.php` टेस्ट में दोनों के बीच बहाव को स्थैतिक रूप से पकड़ता है।
- सीड रोलबैक सभी रोल और अनुमति असाइनमेंट साफ़ कर देगा (`down()` भी तीन टेबल delete करता है), प्रोडक्शन में `php webman migrate:rollback` चलाने से पहले सुनिश्चित करें कि कोई एडमिन पक्ष ऑनलाइन ऑपरेशन न हो।

---

## सात, Nginx कॉन्फ़िगरेशन

### 7.1 कॉन्फ़िगरेशन फ़ाइल बनाएँ

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API सेवा
server {
    listen 80;
    server_name api.yourdomain.com;

    # HTTPS के लिए बाध्य करें (प्रमाणपत्र कॉन्फ़िगरेशन के बाद अनकमेंट करें)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API अनुरोध webman को प्रॉक्सी करें
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # स्टैटिक फ़ाइलें सीधे पढ़ें — केवल uploads उपनिर्देशिका उजागर करें (backups/apple/firebase में संवेदनशील डेटा है, सार्वजनिक करना प्रतिबंधित)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # हेल्थ चेक को प्रॉक्सी कैश की आवश्यकता नहीं
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # अनुरोध बॉडी आकार सीमित करें
    client_max_body_size 10M;
}

# एडमिन पैनल
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

# स्थिति पृष्ठ — वैकल्पिक
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/v1/status;
#     }
# }
```

### 7.2 साइट सक्षम करें

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # कॉन्फ़िगरेशन सिंटैक्स जाँचें
sudo systemctl reload nginx
```

---

## आठ, HTTPS प्रमाणपत्र

### 8.1 Certbot (Let's Encrypt) उपयोग करें

```bash
sudo apt install -y certbot python3-certbot-nginx

# प्रमाणपत्र प्राप्त करें
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# स्वचालित नवीनीकरण सत्यापन
sudo certbot renew --dry-run
```

### 8.2 Nginx में HTTPS रीडायरेक्ट अनकमेंट करें

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# return 301 https://... की टिप्पणी हटाएँ
sudo nginx -t
sudo systemctl reload nginx
```

---

## नौ, सेवा शुरू करें

### 9.1 webman शुरू करें

```bash
# Service (पोर्ट 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (पोर्ट 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 स्टार्टअप सत्यापित करें

```bash
# प्रोसेस जाँचें
ps aux | grep webman

# पोर्ट जाँचें
ss -tlnp | grep -E '8787|8788'

# हेल्थ चेक
curl http://127.0.0.1:8787/health
# लौटना चाहिए: {"code":0,"message":"ok"}
```

### 9.3 systemd ऑटो-स्टार्ट कॉन्फ़िगर करें

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

### 9.4 kvm-server शुरू करें (Rust सप्लाई सेवा)

kvm-server एक स्वतंत्र Rust gRPC सप्लाई सेवा है (`infrastructure/kvm-server`, e-cat workspace),
जो VM निर्माण/स्थिति क्वेरी प्रदान करती है, और etcd रजिस्ट्री + lease हार्टबीट के माध्यम से PHP पक्ष
(KvmClient/RegistryProcess) को डिस्कवरी देती है। **वर्तमान ड्राइवर सिम्युलेटेड है (simulated), libvirt (virsh)
वास्तविक ड्राइवर Phase 2 है** — डिप्लॉयमेंट पर `KVM_DRIVER=simulated` स्पष्ट रूप से सेट करना
अनिवार्य है, अन्यथा डिफ़ॉल्ट virsh ड्राइवर NotImplemented लौटाएगा।

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

पर्यावरण वेरिएबल (`infrastructure/kvm-server/.env`, `service/.env` के संगत मान देखें):

| वेरिएबल | आवश्यक | विवरण |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN (SQLx, service के समान डेटाबेस) |
| `KVM_REDIS_URL` | ✅ | Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC कॉलर प्रमाणीकरण token (PHP पक्ष कॉन्फ़िगरेशन के समान) |
| `KVM_DRIVER` | ✅ | `simulated` (वर्तमान में केवल उपलब्ध; `virsh` Phase 2 है) |
| `KVM_ADDR` | — | HTTP प्रबंधन इंटरफ़ेस पता, डिफ़ॉल्ट `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC पता, डिफ़ॉल्ट `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd एंडपॉइंट, सेट करने पर रजिस्ट्री/डिस्कवरी सक्षम (जैसे `http://127.0.0.1:2379`) |

---

## दस, शेड्यूल्ड टास्क

सिस्टम में बिल्ट-इन cron शेड्यूलिंग प्रोसेस है (`config/process.php` में `App\Cron\CronRunner` पंजीकृत),
हर मिनट `service/config/cron.php` में 5-फ़ील्ड cron एक्सप्रेशन का मूल्यांकन कर संबंधित टास्क निष्पादित करता है,
सेवा शुरू होने के साथ स्वचालित रूप से चलता है, **बाहरी crontab की आवश्यकता नहीं**। साथ ही
`queue_consumer` प्रोसेस provisioning / notification_* आदि Redis क्यू संदेश खपत करता है।

```bash
# प्रोसेस स्थिति देखें (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

टास्क सूची (`service/config/cron.php`):

| cron एक्सप्रेशन | टास्क |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — विनिमय दर सिंक |
| `37 2 * * *` | PaymentReconcile — पेमेंट रिकॉन्सिलिएशन |
| `17 4 * * 1` | SupplierSettlement — सप्लायर सेटलमेंट |
| `23 6 * * *` | ExpirationCheck — संसाधन/डोमेन समाप्ति जाँच |
| `43 7,19 * * *` | SslCertificateCheck — SSL प्रमाणपत्र जाँच (डेटाबेस) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — संसाधन मीट्रिक संग्रह |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — ऑनलाइन प्रमाणपत्र जाँच |
| `7 * * * *` | UsageAggregator::aggregate — उपयोग समुच्चयन |
| `41 3 * * *` | BillingEngine::runDaily — दैनिक बिलिंग |
| `11,41 * * * *` | SuspendCheck — बकाया सस्पेंड जाँच |

---

## ग्यारह, डिप्लॉयमेंट सत्यापन चेकलिस्ट

आइटम-दर-आइटम जाँच करें, पूर्ण होने पर टिक करें:

### इन्फ्रास्ट्रक्चर
- [ ] MySQL कनेक्ट हो सकता है: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis कनेक्ट हो सकता है: `redis-cli ping` → `PONG`
- [ ] PHP संस्करण >= 8.2: `php -v`
- [ ] Composer निर्भरताएँ सफलतापूर्वक इंस्टॉल
- [ ] Rust टूलचेन इंस्टॉल (kvm-server): `rustc --version`
- [ ] kvm-server चल रहा है: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` पास

### एप्लिकेशन कॉन्फ़िगरेशन
- [ ] `.env` फ़ाइल के सभी आवश्यक आइटम कॉन्फ़िगर
- [ ] JWT कुंजी जनरेट और `.env` में लिखी गई
- [ ] एन्क्रिप्शन मास्टर कुंजी जनरेट और `.env` में लिखी गई
- [ ] Stripe कुंजियाँ कॉन्फ़िगर (यदि पेमेंट फ़ंक्शन चाहिए)
- [ ] स्टोरेज निर्देशिका अनुमतियाँ सही (`chmod 755 storage runtime`)

### डेटाबेस
- [ ] डेटाबेस और उपयोगकर्ता बनाए गए
- [ ] सभी माइग्रेशन सफलतापूर्वक निष्पादित: `SELECT COUNT(*) FROM migrations` → 19 होना चाहिए
- [ ] मुख्य टेबलें मौजूद: `SHOW TABLES LIKE 'users'`

### सेवाएँ
- [ ] Nginx कॉन्फ़िगरेशन सिंटैक्स सही: `nginx -t`
- [ ] webman प्रोसेस चल रहे हैं: `ps aux | grep webman`
- [ ] पोर्ट सुनना सामान्य: `ss -tlnp | grep 8787`
- [ ] हेल्थ चेक पास: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS प्रमाणपत्र मान्य: `curl -I https://api.yourdomain.com/health`

### API एंडपॉइंट नमूना जाँच
- [ ] `GET /api/v1/status` → 200
- [ ] `GET /api/v1/products` → 200 (मान्य JSON)
- [ ] `POST /api/v1/auth/login` (बिना body) → 422 (पैरामीटर सत्यापन)
- [ ] `GET /api/v1/user/profile` (बिना token) → 401 (प्रमाणीकरण)
- [ ] अमान्य संस्करण: `curl /api/v99/products` → 400

### शेड्यूल्ड टास्क
- [ ] crontab कॉन्फ़िगर किया गया
- [ ] लॉग निर्देशिका मौजूद और लिखने योग्य

### सुरक्षा
- [ ] `.env` फ़ाइल अनुमति 600
- [ ] MySQL रिमोट पोर्ट खुला नहीं (या केवल इंट्रानेट अनुमत)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS सक्षम
- [ ] मेंटेनेंस मोड उपलब्ध (MAINTENANCE_MODE अनकमेंट करके चालू किया जा सकता है)

---

## बारह, सामान्य समस्याएँ

### webman स्टार्टअप विफल

```bash
# फोरग्राउंड शुरू करके त्रुटि देखें
php start.php start
# सामान्य कारण: पोर्ट व्यस्त, .env कॉन्फ़िगरेशन गलत, Redis/MySQL अप्राप्य
```

### माइग्रेशन निष्पादन विफल

```bash
# डेटाबेस कनेक्शन जाँचें
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<पासवर्ड>');"
# टेबल पहले से मौजूद है या नहीं जाँचें
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman शायद क्रैश हो गया है
sudo systemctl status cloud-platform
# पुनः शुरू करें
sudo systemctl restart cloud-platform
```

### लॉग स्थान

| लॉग | पथ |
|------|------|
| webman स्वयं | `service/runtime/logs/workerman.log` |
| एप्लिकेशन लॉग | `service/runtime/logs/` |
| Cron लॉग | `service/runtime/logs/cron.log` |
| Nginx एक्सेस | `/var/log/nginx/access.log` |
| Nginx त्रुटि | `/var/log/nginx/error.log` |
| MySQL स्लो क्वेरी | `/var/log/mysql/slow.log` |

### प्रदर्शन ट्यूनिंग

```bash
# webman worker संख्या (config/server.php)
'count' => 4  # CPU कोर संख्या के बराबर रखने की सलाह

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# निम्न कॉन्फ़िगरेशन की पुष्टि करें:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL बफ़र पूल
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # उपलब्ध मेमोरी का 50-70% रखें
```
