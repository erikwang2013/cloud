# CloudPlatform Deployment Checklist

## 1. Server Requirements

| Item | Minimum | Recommended |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 cores | 4+ cores |
| Memory | 4 GB | 8 GB+ |
| Disk | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (kvm-server provisioning service) | 1.75+ |

**Ports to open**: 80 (HTTP), 443 (HTTPS), 8787 (webman internal, private network only), 8788 (admin, private network only), 50051 (kvm-server gRPC, private network only), 2379 (etcd, private network only, if kvm-server registration is enabled)

---

## 2. Environment Installation

### 2.1 Base Dependencies

```bash
# System update
sudo apt update && sudo apt upgrade -y

# Base tools
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server provisioning service)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + extensions (via ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# Verify
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 MySQL Secure Initialization

```bash
sudo mysql_secure_installation
# Set root password, remove anonymous users, disallow remote root login, remove test database
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # Should return PONG
```

---

## 3. Database Configuration

### 3.0 One-Click Install Wizard (recommended)

The project root provides a Web install wizard that automatically handles database table creation, admin account creation, and config writing:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# Open http://<server-IP>:8888 in a browser
```

Wizard steps:
1. Environment check (PHP version, extensions, directory permissions)
2. Database configuration (host, port, database name, username, password, auto-create database supported)
3. Admin account setup (username, password, email)
4. One-click installation (46 tables + super admin role + admin account + auto-generated .env file)

After installation, manually fill in the optional configuration in `service/.env` (SMTP, Stripe, SMS, etc.).

### 3.1 Manually Create Database and Users

```sql
-- Log in as root
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Application user (read/write)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- Migration user (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- Read-only user (reports)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Verify Connection

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. Code Deployment

### 4.1 Clone the Code

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <repo-url> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Install Dependencies

```bash
# Service dependencies
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin dependencies
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. Environment Configuration

### 5.1 Service .env File

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

Edit `.env` and fill in the actual values:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<database-password>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT key (generate: openssl rand -base64 32; the service refuses to start if unset)
JWT_SECRET_KEY=<base64-random-string>

# Transport encryption master key (generate: openssl rand -base64 32; 32-byte base64 encoded, same format for admin and service)
ENCRYPTION_MASTER_KEY=<base64-encoded-32-byte-key>

# Field encryption key (base64 encoded; config/encryptable.php base64_decodes it before use, passing a plain string throws MissingEncryptionKeyException)
ENCRYPTION_KEY=<base64-encoded-key>
# Encryption cipher: deterministic query mode only supports ECB (aes-128-ecb / aes-256-ecb), CBC/GCM throws at startup
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<custom-random-string>
HASHIDS_LENGTH=12

# Stripe payments
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio SMS
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP email (symfony/mailer; sends are recorded with dev-stub status when SMTP_HOST is unset)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(implicit TLS) / empty
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

# AWS (optional — cloud provider integration)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM push)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# Maintenance mode
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (country codes, comma-separated, optional)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook signature secret
WEBHOOK_SECRET=<random-string>

# Exchange rate API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 backup (optional)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Generate Keys

```bash
# Generate all required random keys
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Create Storage Directories

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. Database Migrations

### 6.1 Create Migration Tracking Table

```sql
-- Log in as the migration user
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Run Migrations

```bash
cd /home/wwwroot/cloud-php/service

# Run migration files in order (script prepared at /tmp/run_migrations.php)
php /tmp/run_migrations.php
```

Or execute them one by one manually (suitable for careful production operations):

```bash
cd /home/wwwroot/cloud-php/service
# First list the migration files
ls -la database/migrations/

# Run each one (webman-scout defaults to the database driver, no Elasticsearch needed)
php -r "
require 'vendor/autoload.php';
// Load config...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Migration Re-runs & RBAC Seed

Migrations are executed by `php webman migrate` (`service/app/command/MigrateCommand.php`), applying not-yet-executed migrations in filename order. Most migrations create tables once and never need re-running; **the only exception is the RBAC seed**:

- `2026_08_17_000001_seed_rbac_permissions.php` is a **reset-style seed**: it first `delete`s all rows from the `role_permission` / `permissions` / `roles` tables, then re-inserts per the matrix in the file. This migration is therefore safe to re-run, and **re-running produces no duplicate rows** (explicit ids, unaffected by auto-increment).
- When upgrading old databases (those that ran the `2026_05_20_000006_create_rbac_permissions.php` era seed), re-running the seed is required to get the converged permission matrix:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Note**: the single source of truth for runtime permissions is the static array in `service/common/auth/Rbac.php`, **independent of the database** — `RbacMiddleware` reads that array directly to judge permissions; the DB seed is only for admin panel display. When modifying `Rbac.php` you **must** update the seed file in sync (`permissions()` union + per-role `rolePerms()` matrix); `service/tests/auth/RbacSeedTest.php` statically intercepts drift between the two in tests.
- Rolling back the seed clears all roles and permission assignments (`down()` also deletes the three tables); confirm no live admin operations before running `php webman migrate:rollback` in production.

---

## 7. Nginx Configuration

### 7.1 Create the Config File

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API service
server {
    listen 80;
    server_name api.yourdomain.com;

    # Force HTTPS (uncomment after cert is configured)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # Proxy API requests to webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # Static file direct read — only the uploads subdirectory is exposed (backups/apple/firebase etc. contain sensitive data, public access forbidden)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Health check needs no proxy cache
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # Limit request body size
    client_max_body_size 10M;
}

# Admin panel
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

# Status page — optional
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 Enable the Site

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # Check config syntax
sudo systemctl reload nginx
```

---

## 8. HTTPS Certificates

### 8.1 Use Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Get certificates
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# Verify auto-renewal
sudo certbot renew --dry-run
```

### 8.2 Uncomment the HTTPS Redirect in Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# Uncomment the return 301 https://... lines
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. Start Services

### 9.1 Start webman

```bash
# Service (port 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (port 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Verify Startup

```bash
# Check processes
ps aux | grep webman

# Check ports
ss -tlnp | grep -E '8787|8788'

# Health check
curl http://127.0.0.1:8787/health
# Should return: {"code":0,"message":"ok"}
```

### 9.3 Configure systemd Auto-Start

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

### 9.4 Start kvm-server (Rust Provisioning Service)

kvm-server is an independent Rust gRPC provisioning service (`infrastructure/kvm-server`, e-cat workspace),
providing VM creation/status queries, and registers with etcd + lease heartbeat for discovery by the
PHP side (KvmClient/RegistryProcess). **The current driver is simulated; the real libvirt (virsh)
driver is Phase 2** — deployment must explicitly set `KVM_DRIVER=simulated`, otherwise the default
virsh driver returns NotImplemented.

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

Environment variables (`infrastructure/kvm-server/.env`, refer to the corresponding values in `service/.env`):

| Variable | Required | Description |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN (SQLx, same database as service) |
| `KVM_REDIS_URL` | ✅ | Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC caller auth token (must match the PHP-side config) |
| `KVM_DRIVER` | ✅ | `simulated` (currently the only available option; `virsh` is Phase 2) |
| `KVM_ADDR` | — | HTTP admin interface address, default `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC address, default `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd endpoint, enables registration/discovery when set (e.g. `http://127.0.0.1:2379`) |

---

## 10. Scheduled Tasks

The system has a built-in cron scheduler process (`App\Cron\CronRunner` registered in `config/process.php`) that evaluates the 5-field cron expressions in
`service/config/cron.php` every minute and executes the corresponding tasks. It starts automatically with the service,
**no external crontab needed**. A `queue_consumer` process additionally consumes provisioning / notification_* and other
Redis queue messages.

```bash
# View process status (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

Task list (`service/config/cron.php`):

| cron expression | Task |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — exchange rate sync |
| `37 2 * * *` | PaymentReconcile — payment reconciliation |
| `17 4 * * 1` | SupplierSettlement — supplier settlement |
| `23 6 * * *` | ExpirationCheck — resource/domain expiration check |
| `43 7,19 * * *` | SslCertificateCheck — SSL certificate check (database) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — resource metrics collection |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — online certificate probing |
| `7 * * * *` | UsageAggregator::aggregate — usage aggregation |
| `41 3 * * *` | BillingEngine::runDaily — daily billing |
| `11,41 * * * *` | SuspendCheck — arrears suspension check |

---

## 11. Deployment Verification Checklist

Check each item and confirm:

### Infrastructure
- [ ] MySQL reachable: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis reachable: `redis-cli ping` → `PONG`
- [ ] PHP version >= 8.2: `php -v`
- [ ] Composer dependencies installed successfully
- [ ] Rust toolchain installed (kvm-server): `rustc --version`
- [ ] kvm-server running: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` passes

### Application Config
- [ ] All required fields in the `.env` file configured
- [ ] JWT key generated and written to `.env`
- [ ] Encryption master key generated and written to `.env`
- [ ] Stripe keys configured (if payment is needed)
- [ ] Storage directory permissions correct (`chmod 755 storage runtime`)

### Database
- [ ] Database and users created
- [ ] All migrations ran successfully: `SELECT COUNT(*) FROM migrations` → should be 19
- [ ] Core tables exist: `SHOW TABLES LIKE 'users'`

### Services
- [ ] Nginx config syntax correct: `nginx -t`
- [ ] webman processes running: `ps aux | grep webman`
- [ ] Port listening correctly: `ss -tlnp | grep 8787`
- [ ] Health check passes: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS certificate valid: `curl -I https://api.yourdomain.com/health`

### API Endpoint Spot Checks
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (valid JSON)
- [ ] `POST /api/auth/login` (no body) → 422 (parameter validation)
- [ ] `GET /api/user/profile` (no token) → 401 (authentication)
- [ ] Version header: `curl -H 'X-Api-Version: v99' /api/products` → 400

### Scheduled Tasks
- [ ] crontab configured
- [ ] Log directory exists and is writable

### Security
- [ ] `.env` file permissions 600
- [ ] MySQL remote port not exposed (or internal network only)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS enabled
- [ ] Maintenance mode available (uncomment MAINTENANCE_MODE to enable)

---

## 12. Common Issues

### webman Fails to Start

```bash
# Run in foreground to see the error
php start.php start
# Common causes: port occupied, .env misconfiguration, Redis/MySQL unreachable
```

### Migration Execution Fails

```bash
# Check database connection
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<password>');"
# Check whether the table already exists
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman may have crashed
sudo systemctl status cloud-platform
# Restart
sudo systemctl restart cloud-platform
```

### Log Locations

| Log | Path |
|------|------|
| webman itself | `service/runtime/logs/workerman.log` |
| Application logs | `service/runtime/logs/` |
| Cron logs | `service/runtime/logs/cron.log` |
| Nginx access | `/var/log/nginx/access.log` |
| Nginx errors | `/var/log/nginx/error.log` |
| MySQL slow queries | `/var/log/mysql/slow.log` |

### Performance Tuning

```bash
# webman worker count (config/server.php)
'count' => 4  # Recommended: number of CPU cores

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# Confirm the following config:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL buffer pool
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # Set to 50-70% of available memory
```
