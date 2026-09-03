# قائمة نشر CloudPlatform

## 1. متطلبات الخادم
| البند | الحد الأدنى | الموصى به |
|------|---------|---------|
| نظام التشغيل | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | نواتان | 4 نوى+ |
| الذاكرة | 4 GB | 8 GB+ |
| القرص | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (خدمة توفير kvm-server) | 1.75+ |

**المنافذ الواجب فتحها**: 80 (HTTP)، 443 (HTTPS)، 8787 (webman داخلي، للشبكة الداخلية فقط)، 8788 (admin، للشبكة الداخلية فقط)، 50051 (kvm-server gRPC، للشبكة الداخلية فقط)، 2379 (etcd، للشبكة الداخلية فقط، عند تفعيل تسجيل kvm-server)

---

## 2. تثبيت البيئة
### 2.1 التبعيات الأساسية

```bash
# تحديث النظام
sudo apt update && sudo apt upgrade -y

# الأدوات الأساسية
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (خدمة توفير kvm-server)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + الإضافات (عبر ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# التحقق
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 تهيئة أمان MySQL

```bash
sudo mysql_secure_installation
# تعيين كلمة مرور root، حذف المستخدمين المجهولين، منع تسجيل دخول root عن بعد، حذف قاعدة test
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # يجب أن يعيد PONG
```

---

## 3. إعداد قاعدة البيانات
### 3.0 معالج التثبيت بنقرة واحدة (موصى به)

يوفر جذر المشروع معالج تثبيت ويب يكمل تلقائياً إنشاء جداول قاعدة البيانات وإنشاء المسؤول وكتابة الإعدادات:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# تصفح http://<IP الخادم>:8888
```

خطوات المعالج:
1. فحص البيئة (إصدار PHP والإضافات وصلاحيات الدلائل)
2. إعداد قاعدة البيانات (المضيف والمنفذ واسم القاعدة واسم المستخدم وكلمة المرور، مع دعم الإنشاء التلقائي للقاعدة)
3. ضبط حساب المسؤول (اسم المستخدم وكلمة المرور والبريد الإلكتروني)
4. تنفيذ التثبيت بنقرة واحدة (46 جدولاً + دور مدير النظام + حساب المسؤول + توليد ملف .env تلقائياً)

بعد اكتمال التثبيت يكفي إضافة الإعدادات الاختيارية في `service/.env` يدوياً (SMTP وStripe والرسائل القصيرة وغيرها).

### 3.1 إنشاء قاعدة البيانات والمستخدم يدوياً

```sql
-- تسجيل الدخول كـ root
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- مستخدم التطبيق (قراءة/كتابة)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<كلمة مرور قوية>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- مستخدم الترحيل (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<كلمة مرور قوية>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- مستخدم القراءة فقط (التقارير)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<كلمة مرور قوية>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 التحقق من الاتصال

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. نشر الكود
### 4.1 سحب الكود

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <عنوان المستودع> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 تثبيت التبعيات

```bash
# تبعيات Service
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# تبعيات Admin
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. إعداد البيئة
### 5.1 ملف .env لخدمة Service

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

عدّل `.env` واملأ القيم الفعلية:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# قاعدة البيانات
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<كلمة مرور قاعدة البيانات>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# مفتاح JWT (التوليد: openssl rand -base64 32؛ الخدمة ترفض الإقلاع عند عدم تعيينه)
JWT_SECRET_KEY=<سلسلة عشوائية base64>

# المفتاح الرئيسي لتشفير النقل (التوليد: openssl rand -base64 32؛ 32 بايت مشفر base64، بنفس الصيغة في admin وservice)
ENCRYPTION_MASTER_KEY=<مفتاح 32 بايت مشفر base64>

# مفتاح تشفير الحقول (مشفر base64؛ config/encryptable.php يفك base64_decode قبل الاستخدام، والمرور بسلسلة نصية صريحة يرمي MissingEncryptionKeyException)
ENCRYPTION_KEY=<مفتاح مشفر base64>
# خوارزمية التشفير: نمط الاستعلام الحتمي يدعم ECB فقط (aes-128-ecb / aes-256-ecb)، وCBC/GCM يرمي خطأً عند الإقلاع
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<سلسلة عشوائية مخصصة>
HASHIDS_LENGTH=12

# الدفع عبر Stripe
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# رسائل Twilio القصيرة
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# البريد عبر SMTP (symfony/mailer؛ عند عدم إعداد SMTP_HOST تُسجل الرسائل بحالة dev-stub)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(ضمني TLS) / فارغ
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

# AWS (اختياري — الربط مع مزودي السحابة)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (دفع FCM)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# وضع الصيانة
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# الحجب الجغرافي (رموز الدول، مفصولة بفواصل، اختياري)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# مفتاح توقيع Webhook
WEBHOOK_SECRET=<سلسلة عشوائية>

# واجهة أسعار الصرف
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# النسخ الاحتياطي عبر S3 (اختياري)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 توليد المفاتيح

```bash
# توليد كل المفاتيح العشوائية المطلوبة
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 إنشاء أدلة التخزين

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. ترحيل قاعدة البيانات
### 6.1 إنشاء جدول تتبع الترحيلات

```sql
-- تسجيل الدخول كمستخدم الترحيل
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 تنفيذ الترحيلات

```bash
cd /home/wwwroot/cloud-php/service

# تنفيذ ملفات الترحيل بالترتيب (السكربت جاهز في /tmp/run_migrations.php)
php /tmp/run_migrations.php
```

أو التنفيذ اليدوي ملفاً ملفاً (مناسب للعمليات الحذرة في الإنتاج):

```bash
cd /home/wwwroot/cloud-php/service
# أولاً عرض قائمة ملفات الترحيل
ls -la database/migrations/

# التنفيذ واحداً واحداً (webman-scout بمحرك database الافتراضي، لا حاجة لـ Elasticsearch)
php -r "
require 'vendor/autoload.php';
// تحميل الإعدادات...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 إعادة تشغيل الترحيلات وبذرة RBAC

تُنفَّذ الترحيلات عبر `php webman migrate` (`service/app/command/MigrateCommand.php`)، وتُطبَّق الترحيلات غير المنفذة حسب ترتيب أسماء الملفات. الغالبية العظمى من الترحيلات إنشاء جداول لمرة واحدة ولا تحتاج إعادة تشغيل؛ **الاستثناء الوحيد هو بذرة RBAC**:

- `2026_08_17_000001_seed_rbac_permissions.php` بذرة من نوع **reset**: عند التنفيذ تحذف أولاً كل صفوف الجداول الثلاثة `role_permission` / `permissions` / `roles` ثم تعيد الإدراج حسب المصفوفة داخل الملف. لذلك يمكن إعادة تشغيل هذه البذرة بأمان، و**إعادة التشغيل لا تنتج صفوفاً مكررة** (معرّفات صريحة، لا تتأثر بالزيادة الذاتية).
- عند ترقية قاعدة قديمة (مرت بعصر بيانات `2026_05_20_000006_create_rbac_permissions.php`)، يجب إعادة تشغيل البذرة للحصول على مصفوفة الصلاحيات المكثفة:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **ملاحظة**: المصدر الوحيد للحقيقة لصلاحيات وقت التشغيل هو مصفوفة `service/common/auth/Rbac.php` الثابتة، **لا يعتمد على قاعدة البيانات** — يقرأ `RbacMiddleware` هذه المصفوفة مباشرة للحكم على الصلاحيات، وبذرة DB مخصصة للعرض في لوحة الإدارة فقط. عند تعديل `Rbac.php` **يجب** مزامنة ملف البذرة (اتحاد `permissions()` + مصفوفة `rolePerms()` لكل دور)، و`service/tests/auth/RbacSeedTest.php` يعترض انحراف الاثنين بشكل ثابت في الاختبارات.
- تراجع البذرة يفرغ كل الأدوار وتعيينات الصلاحيات (`down()` تحذف الجداول الثلاثة أيضاً)، وقبل تنفيذ `php webman migrate:rollback` في الإنتاج يجب التأكد من عدم وجود عمليات عبر الإنترنت في لوحة الإدارة.

---

## 7. إعداد Nginx
### 7.1 إنشاء ملف الإعداد

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# خدمة API
server {
    listen 80;
    server_name api.yourdomain.com;

    # فرض HTTPS (أزل التعليق بعد إعداد الشهادة)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # توجيه طلبات API إلى webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # القراءة المباشرة للملفات الثابتة — يكشف دليل uploads الفرعي فقط (backups/apple/firebase وغيرها تحوي بيانات حساسة، يُمنع تعريضها)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # فحص الصحة لا يحتاج تخزين وكيل مؤقت
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # تقييد حجم جسم الطلب
    client_max_body_size 10M;
}

# لوحة الإدارة
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

# صفحة الحالة — اختيارية
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/v1/status;
#     }
# }
```

### 7.2 تفعيل الموقع

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # فحص صيغة الإعداد
sudo systemctl reload nginx
```

---

## 8. شهادة HTTPS
### 8.1 استخدام Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# الحصول على الشهادة
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# التحقق من التجديد التلقائي
sudo certbot renew --dry-run
```

### 8.2 إزالة التعليق عن إعادة توجيه HTTPS في Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# أزل التعليق عن return 301 https://...
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. تشغيل الخدمات
### 9.1 تشغيل webman

```bash
# Service (المنفذ 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (المنفذ 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 التحقق من الإقلاع

```bash
# فحص العمليات
ps aux | grep webman

# فحص المنافذ
ss -tlnp | grep -E '8787|8788'

# فحص الصحة
curl http://127.0.0.1:8787/health
# يجب أن يعيد: {"code":0,"message":"ok"}
```

### 9.3 إعداد الإقلاع التلقائي عبر systemd

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

### 9.4 تشغيل kvm-server (خدمة التوفير بلغة Rust)

kvm-server خدمة توفير gRPC مستقلة بلغة Rust (`infrastructure/kvm-server`، workspace e-cat)،
توفر إنشاء VM والاستعلام عن الحالة، وتُسجل عبر etcd + نبض lease ليكتشفها جانب PHP
(KvmClient/RegistryProcess). **المحرك الحالي هو المحرك المحاكى (simulated)، والمحرك الفعلي
libvirt (virsh) في المرحلة Phase 2** — عند النشر يجب ضبط `KVM_DRIVER=simulated` صراحةً،
وإلا سيعيد محرك virsh الافتراضي NotImplemented.

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

متغيرات البيئة (`infrastructure/kvm-server/.env`، راجع القيم المقابلة في `service/.env`):

| المتغير | إلزامي | الوصف |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | DSN الخاص بـ MySQL (SQLx، نفس قاعدة service) |
| `KVM_REDIS_URL` | ✅ | عنوان Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | token مصادقة متصل gRPC (مطابق لإعداد جانب PHP) |
| `KVM_DRIVER` | ✅ | `simulated` (الوحيد المتاح حالياً؛ `virsh` في Phase 2) |
| `KVM_ADDR` | — | عنوان واجهة إدارة HTTP، الافتراضي `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | عنوان gRPC، الافتراضي `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | نقطة نهاية etcd، عند ضبطها يُفعَّل التسجيل/الاكتشاف (مثل `http://127.0.0.1:2379`) |

---

## 10. المهام المجدولة
يضم النظام عملية جدولة مدمجة (`config/process.php` تسجل `App\Cron\CronRunner`)، تقيّم كل دقيقة
تعبيرات cron ذات الخمسة حقول في `service/config/cron.php` وتنفذ المهمة المقابلة، وتعمل تلقائياً
مع إقلاع الخدمة، **بلا حاجة إلى crontab خارجي**. كما توجد عملية `queue_consumer` تستهلك رسائل
قوائم Redis مثل provisioning / notification_* وغيرها.

```bash
# عرض حالة العمليات (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

قائمة المهام (`service/config/cron.php`):

| تعبير cron | المهمة |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — مزامنة أسعار الصرف |
| `37 2 * * *` | PaymentReconcile — تسوية الدفع |
| `17 4 * * 1` | SupplierSettlement — تسوية الموردين |
| `23 6 * * *` | ExpirationCheck — فحص انتهاء الموارد/النطاقات |
| `43 7,19 * * *` | SslCertificateCheck — فحص شهادات SSL (قاعدة البيانات) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — جمع مقاييس الموارد |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — استشعار الشهادات عبر الإنترنت |
| `7 * * * *` | UsageAggregator::aggregate — تجميع الاستخدام |
| `41 3 * * *` | BillingEngine::runDaily — الفوترة اليومية |
| `11,41 * * * *` | SuspendCheck — فحص تعليق المتأخرين |

---

## 11. قائمة التحقق من النشر
افحص البنود واحداً واحداً وعلّم بالصح:

### البنية التحتية
- [ ] MySQL قابل للاتصال: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis قابل للاتصال: `redis-cli ping` ← `PONG`
- [ ] إصدار PHP >= 8.2: `php -v`
- [ ] تبعيات Composer مثبتة بنجاح
- [ ] سلسلة أدوات Rust مثبتة (kvm-server): `rustc --version`
- [ ] kvm-server يعمل: `ss -tlnp | grep 50051`؛ `curl http://127.0.0.1:8000/health` ناجح

### إعداد التطبيق
- [ ] جميع الحقول الإلزامية في ملف `.env` مضبوطة
- [ ] مفتاح JWT مولَّد ومكتوب في `.env`
- [ ] المفتاح الرئيسي للتشفير مولَّد ومكتوب في `.env`
- [ ] مفاتيح Stripe مضبوطة (عند الحاجة لميزة الدفع)
- [ ] صلاحيات أدلة التخزين صحيحة (`chmod 755 storage runtime`)

### قاعدة البيانات
- [ ] قاعدة البيانات والمستخدمان أُنشئا
- [ ] كل الترحيلات نجحت: `SELECT COUNT(*) FROM migrations` ← يجب أن تكون 19
- [ ] الجداول الأساسية موجودة: `SHOW TABLES LIKE 'users'`

### الخدمات
- [ ] صيغة إعداد Nginx صحيحة: `nginx -t`
- [ ] عمليات webman تعمل: `ps aux | grep webman`
- [ ] الاستماع على المنافذ طبيعي: `ss -tlnp | grep 8787`
- [ ] فحص الصحة ناجح: `curl http://127.0.0.1:8787/health`
- [ ] شهادة HTTPS سارية: `curl -I https://api.yourdomain.com/health`

### فحص عينات نقاط النهاية
- [ ] `GET /api/v1/status` ← 200
- [ ] `GET /api/v1/products` ← 200 (JSON صالح)
- [ ] `POST /api/v1/auth/login` (بلا body) ← 422 (التحقق من المعاملات)
- [ ] `GET /api/v1/user/profile` (بلا token) ← 401 (المصادقة)

### المهام المجدولة
- [ ] crontab مضبوط
- [ ] دليل السجلات موجود وقابل للكتابة

### الأمان
- [ ] صلاحيات ملف `.env` هي 600
- [ ] منفذ MySQL غير مفتوح عن بعد (أو الشبكة الداخلية فقط)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS مفعّل
- [ ] وضع الصيانة متاح (تفعيله بإزالة تعليق MAINTENANCE_MODE)

---

## 12. المشكلات الشائعة
### فشل إقلاع webman

```bash
# الإقلاع في المقدمة لرؤية الخطأ
php start.php start
# الأسباب الشائعة: المنفذ مشغول، خطأ في إعداد .env، عدم الوصول إلى Redis/MySQL
```

### فشل تنفيذ الترحيل

```bash
# فحص اتصال قاعدة البيانات
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<كلمة المرور>');"
# فحص ما إذا كان الجدول موجوداً
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman قد يكون متعطلاً
sudo systemctl status cloud-platform
# إعادة التشغيل
sudo systemctl restart cloud-platform
```

### مواقع السجلات

| السجل | المسار |
|------|------|
| webman نفسه | `service/runtime/logs/workerman.log` |
| سجلات التطبيق | `service/runtime/logs/` |
| سجلات Cron | `service/runtime/logs/cron.log` |
| وصول Nginx | `/var/log/nginx/access.log` |
| أخطاء Nginx | `/var/log/nginx/error.log` |
| الاستعلامات البطيئة في MySQL | `/var/log/mysql/slow.log` |

### ضبط الأداء

```bash
# عدد عمال webman (config/server.php)
'count' => 4  # يُنصح بضبطه على عدد نوى CPU

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# تأكد من الإعدادات التالية:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# تجمع التخزين المؤقت في MySQL
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # عيّنه على 50-70% من الذاكرة المتاحة
```
