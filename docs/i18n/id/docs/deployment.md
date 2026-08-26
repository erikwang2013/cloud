# Daftar Deployment CloudPlatform

## 1. Persyaratan Server
| Item | Konfigurasi minimum | Konfigurasi rekomendasi |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 core | 4 core+ |
| Memori | 4 GB | 8 GB+ |
| Disk | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (layanan penyediaan kvm-server) | 1.75+ |

**Port yang perlu dibuka**: 80 (HTTP)、443 (HTTPS)、8787 (webman internal, hanya intranet)、8788 (admin, hanya intranet)、50051 (kvm-server gRPC, hanya intranet)、2379 (etcd, hanya intranet, jika registrasi kvm-server diaktifkan)

---

## 2. Instalasi Lingkungan
### 2.1 Dependensi Dasar

```bash
# 系统更新
sudo apt update && sudo apt upgrade -y

# 基础工具
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server 供应服务)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + 扩展 (通过 ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# 验证
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 Inisialisasi Keamanan MySQL

```bash
sudo mysql_secure_installation
# 设置 root 密码、删除匿名用户、禁止远程 root 登录、删除 test 库
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # 应返回 PONG
```

---

## 3. Konfigurasi Database
### 3.0 Panduan Instalasi Satu Klik (rekomendasi)

Direktori root proyek menyediakan panduan instalasi Web, yang dapat menyelesaikan pembuatan tabel database, pembuatan admin, dan penulisan konfigurasi secara otomatis:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# 浏览器访问 http://<服务器IP>:8888
```

Langkah panduan:
1. Pemeriksaan lingkungan (versi PHP, ekstensi, izin direktori)
2. Konfigurasi database (host, port, nama database, nama pengguna, kata sandi, mendukung pembuatan database otomatis)
3. Pengaturan akun admin (nama pengguna, kata sandi, email)
4. Eksekusi instalasi satu klik (46 tabel + peran super admin + akun admin + pembuatan file .env otomatis)

Setelah instalasi selesai, lengkapi manual konfigurasi opsional di `service/.env` (SMTP, Stripe, SMS, dll.).

### 3.1 Membuat Database dan Pengguna Secara Manual

```sql
-- 以 root 登录
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 应用用户 (读写)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<强密码>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- 迁移用户 (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<强密码>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- 只读用户 (报表)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<强密码>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Memverifikasi Koneksi

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. Deployment Kode
### 4.1 Mengambil Kode

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <仓库地址> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Menginstal Dependensi

```bash
# Service 依赖
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin 依赖
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. Konfigurasi Lingkungan
### 5.1 File .env Service

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

Edit `.env`, isi nilai sebenarnya:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# 数据库
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<数据库密码>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT 密钥 (生成: openssl rand -base64 32；未设置时服务拒绝启动)
JWT_SECRET_KEY=<base64 随机字符串>

# 传输加密主密钥 (生成: openssl rand -base64 32；32 字节 base64 编码，admin 与 service 同格式)
ENCRYPTION_MASTER_KEY=<base64编码的32字节密钥>

# 字段加密密钥 (base64 编码；config/encryptable.php 会 base64_decode 后使用，直接传明文串会抛 MissingEncryptionKeyException)
ENCRYPTION_KEY=<base64编码的密钥>
# 加密算法：确定性查询模式仅支持 ECB（aes-128-ecb / aes-256-ecb），CBC/GCM 启动即抛错
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<自定义随机字符串>
HASHIDS_LENGTH=12

# Stripe 支付
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio 短信
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP 邮件（symfony/mailer；未配置 SMTP_HOST 时发送记录为 dev-stub 状态）
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(隐式TLS) / 空
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

# AWS (可选 — 云厂商对接)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM推送)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# 维护模式
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (国家代码，逗号分隔，可选)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook 签名密钥
WEBHOOK_SECRET=<随机字符串>

# 汇率 API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 备份 (可选)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Membuat Kunci

```bash
# 生成所有需要的随机密钥
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Membuat Direktori Penyimpanan

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. Migrasi Database
### 6.1 Membuat Tabel Pelacakan Migrasi

```sql
-- 以迁移用户登录
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Menjalankan Migrasi

```bash
cd /home/wwwroot/cloud-php/service

# 按顺序执行迁移文件（已在 /tmp/run_migrations.php 中准备好脚本）
php /tmp/run_migrations.php
```

Atau jalankan satu per satu secara manual (cocok untuk operasi produksi yang hati-hati):

```bash
cd /home/wwwroot/cloud-php/service
# 先查看迁移文件列表
ls -la database/migrations/

# 逐个执行（webman-scout 默认 database 驱动，无需 Elasticsearch）
php -r "
require 'vendor/autoload.php';
// 加载配置...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Menjalankan Ulang Migrasi dan Seed RBAC

Migrasi dijalankan oleh `php webman migrate` (`service/app/command/MigrateCommand.php`), menerapkan migrasi yang belum dijalankan secara berurutan berdasarkan urutan nama file. Sebagian besar migrasi adalah pembuatan tabel sekali jalan, tidak perlu dijalankan ulang; **satu-satunya pengecualian adalah seed RBAC**:

- `2026_08_17_000001_seed_rbac_permissions.php` adalah seed **tipe reset**: saat dijalankan, `delete` semua baris dari tiga tabel `role_permission` / `permissions` / `roles`, lalu menyisipkan ulang sesuai matriks dalam file. Karena itu migrasi ini aman dijalankan ulang, dan **menjalankan ulang tidak membuat baris duplikat** (id eksplisit, tidak terpengaruh auto-increment).
- Saat upgrade basis data lama (yang pernah menjalankan data era `2026_05_20_000006_create_rbac_permissions.php`), perlu menjalankan ulang seed untuk mendapatkan matriks izin yang sudah terkonvergensi:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Perhatian**: sumber kebenaran tunggal izin runtime adalah array statis `service/common/auth/Rbac.php`, **tidak bergantung pada database** — `RbacMiddleware` langsung membaca array tersebut untuk menilai izin, seed DB hanya untuk tampilan panel admin. Saat mengubah `Rbac.php` **harus** sinkron mengubah file seed (gabungan `permissions()` + matriks per peran `rolePerms()`), `service/tests/auth/RbacSeedTest.php` akan secara statis mencegat penyimpangan keduanya saat pengujian.
- Rollback seed akan mengosongkan semua peran dan penugasan izin (`down()` juga delete tiga tabel), sebelum menjalankan `php webman migrate:rollback` di produksi pastikan tidak ada operasi online di panel admin.

---

## 7. Konfigurasi Nginx
### 7.1 Membuat File Konfigurasi

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API 服务
server {
    listen 80;
    server_name api.yourdomain.com;

    # 强制 HTTPS (证书配置后取消注释)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API 请求代理到 webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # 静态文件直读 — 仅暴露 uploads 子目录（backups/apple/firebase 等含敏感数据，禁止公开）
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 健康检查不需要代理缓存
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # 限制请求体大小
    client_max_body_size 10M;
}

# 管理后台
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

# 状态页 — 可选
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 Mengaktifkan Situs

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # 检查配置语法
sudo systemctl reload nginx
```

---

## 8. Sertifikat HTTPS
### 8.1 Menggunakan Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# 获取证书
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# 自动续期验证
sudo certbot renew --dry-run
```

### 8.2 Menghapus Komentar Redirect HTTPS di Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# 取消 return 301 https://... 的注释
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. Menjalankan Layanan
### 9.1 Menjalankan webman

```bash
# Service (端口 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (端口 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Memverifikasi Startup

```bash
# 检查进程
ps aux | grep webman

# 检查端口
ss -tlnp | grep -E '8787|8788'

# 健康检查
curl http://127.0.0.1:8787/health
# 应返回: {"code":0,"message":"ok"}
```

### 9.3 Mengonfigurasi Auto-start systemd

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

### 9.4 Menjalankan kvm-server (layanan penyediaan Rust)

kvm-server adalah layanan penyediaan gRPC Rust independen (`infrastructure/kvm-server`, e-cat workspace),
menyediakan pembuatan/kueri status VM, dan melalui registrasi etcd + lease heartbeat agar dapat
ditemukan oleh sisi PHP (KvmClient/RegistryProcess). **Driver saat ini adalah simulated (simulasi),
driver nyata libvirt (virsh) adalah Phase 2** — saat deployment
harus eksplisit set `KVM_DRIVER=simulated`, jika tidak, driver virsh default akan mengembalikan NotImplemented.

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

Variabel lingkungan (`infrastructure/kvm-server/.env`, referensi nilai terkait `service/.env`):

| Variabel | Wajib | Keterangan |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | DSN MySQL (SQLx, database yang sama dengan service) |
| `KVM_REDIS_URL` | ✅ | URL Redis |
| `KVM_AUTH_TOKEN` | ✅ | Token autentikasi pemanggil gRPC (konsisten dengan konfigurasi sisi PHP) |
| `KVM_DRIVER` | ✅ | `simulated` (satu-satunya yang tersedia saat ini; `virsh` adalah Phase 2) |
| `KVM_ADDR` | — | Alamat antarmuka manajemen HTTP, default `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | Alamat gRPC, default `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | Endpoint etcd, jika diatur mengaktifkan registrasi/discovery (mis. `http://127.0.0.1:2379`) |

---

## 10. Tugas Terjadwal
Sistem memiliki proses penjadwal cron bawaan (`config/process.php` mendaftarkan `App\Cron\CronRunner`), mengevaluasi
ekspresi cron 5 kolom di `service/config/cron.php` setiap menit dan mengeksekusi tugas terkait, berjalan otomatis saat layanan dimulai,
**tidak perlu crontab eksternal**. Selain itu terdapat proses `queue_consumer` yang mengonsumsi pesan
antrean Redis seperti provisioning / notification_*.

```bash
# 查看进程状态（http / websocket / metrics / queue_consumer / cron）
php start.php status
```

Daftar tugas (`service/config/cron.php`):

| Ekspresi cron | Tugas |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — sinkronisasi nilai tukar |
| `37 2 * * *` | PaymentReconcile — rekonsiliasi pembayaran |
| `17 4 * * 1` | SupplierSettlement — settlement pemasok |
| `23 6 * * *` | ExpirationCheck — pemeriksaan kedaluwarsa sumber daya/domain |
| `43 7,19 * * *` | SslCertificateCheck — pemeriksaan sertifikat SSL (database) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — pengumpulan metrik sumber daya |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — probing sertifikat online |
| `7 * * * *` | UsageAggregator::aggregate — agregasi pemakaian |
| `41 3 * * *` | BillingEngine::runDaily — penagihan harian |
| `11,41 * * * *` | SuspendCheck — pemeriksaan suspend tunggakan |

---

## 11. Daftar Verifikasi Deployment
Periksa satu per satu, konfirmasi dengan mencentang:

### Infrastruktur
- [ ] MySQL dapat terhubung: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis dapat terhubung: `redis-cli ping` → `PONG`
- [ ] Versi PHP >= 8.2: `php -v`
- [ ] Dependensi Composer terinstal sukses
- [ ] Toolchain Rust terpasang (kvm-server): `rustc --version`
- [ ] kvm-server berjalan: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` lolos

### Konfigurasi Aplikasi
- [ ] Semua item wajib di file `.env` sudah dikonfigurasi
- [ ] Kunci JWT sudah dibuat dan ditulis ke `.env`
- [ ] Kunci master enkripsi sudah dibuat dan ditulis ke `.env`
- [ ] Kunci Stripe sudah dikonfigurasi (jika memerlukan fungsi pembayaran)
- [ ] Izin direktori penyimpanan benar (`chmod 755 storage runtime`)

### Database
- [ ] Database dan pengguna sudah dibuat
- [ ] Semua migrasi berhasil: `SELECT COUNT(*) FROM migrations` → harus 19
- [ ] Tabel inti ada: `SHOW TABLES LIKE 'users'`

### Layanan
- [ ] Sintaks konfigurasi Nginx benar: `nginx -t`
- [ ] Proses webman berjalan: `ps aux | grep webman`
- [ ] Port mendengarkan normal: `ss -tlnp | grep 8787`
- [ ] Pemeriksaan kesehatan lolos: `curl http://127.0.0.1:8787/health`
- [ ] Sertifikat HTTPS valid: `curl -I https://api.yourdomain.com/health`

### Sampel Endpoint API
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (JSON valid)
- [ ] `POST /api/auth/login` (tanpa body) → 422 (validasi parameter)
- [ ] `GET /api/user/profile` (tanpa token) → 401 (autentikasi)
- [ ] Header versi: `curl -H 'X-Api-Version: v99' /api/products` → 400

### Tugas Terjadwal
- [ ] crontab sudah dikonfigurasi
- [ ] Direktori log ada dan dapat ditulis

### Keamanan
- [ ] Izin file `.env` 600
- [ ] MySQL tidak membuka port remote (atau hanya mengizinkan intranet)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS sudah diaktifkan
- [ ] Mode pemeliharaan tersedia (hapus komentar MAINTENANCE_MODE untuk mengaktifkan)

---

## 12. Masalah Umum
### webman gagal start

```bash
# 前台启动看错误
php start.php start
# 常见原因：端口被占用、.env 配置错误、Redis/MySQL 不可达
```

### Migrasi gagal

```bash
# 检查数据库连接
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<密码>');"
# 检查表是否已存在
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman 可能挂了
sudo systemctl status cloud-platform
# 重启
sudo systemctl restart cloud-platform
```

### Lokasi Log

| Log | Path |
|------|------|
| webman sendiri | `service/runtime/logs/workerman.log` |
| Log aplikasi | `service/runtime/logs/` |
| Log Cron | `service/runtime/logs/cron.log` |
| Akses Nginx | `/var/log/nginx/access.log` |
| Kesalahan Nginx | `/var/log/nginx/error.log` |
| Slow query MySQL | `/var/log/mysql/slow.log` |

### Penyesuaian Kinerja

```bash
# webman worker 数量 (config/server.php)
'count' => 4  # 建议设为 CPU 核心数

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# 确认以下配置：
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL 缓冲池
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # 设为可用内存的 50-70%
```
