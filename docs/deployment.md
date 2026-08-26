# CloudPlatform 部署清单

## 一、服务器要求

| 项目 | 最低配置 | 推荐配置 |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 核 | 4 核+ |
| 内存 | 4 GB | 8 GB+ |
| 磁盘 | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+（kvm-server 供应服务） | 1.75+ |

**需开放端口**：80 (HTTP)、443 (HTTPS)、8787 (webman 内部，仅内网)、8788 (admin，仅内网)、50051 (kvm-server gRPC，仅内网)、2379 (etcd，仅内网，若启用 kvm-server 注册)

---

## 二、环境安装

### 2.1 基础依赖

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

### 2.3 MySQL 安全初始化

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

## 三、数据库配置

### 3.0 一键安装向导（推荐）

项目根目录提供了 Web 安装向导，可自动完成数据库建表、管理员创建和配置写入：

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# 浏览器访问 http://<服务器IP>:8888
```

向导步骤：
1. 环境检查（PHP 版本、扩展、目录权限）
2. 数据库配置（主机、端口、库名、用户名、密码，支持自动建库）
3. 管理员账号设置（用户名、密码、邮箱）
4. 一键执行安装（46 张表 + 超级管理员角色 + 管理员账号 + 自动生成 .env 文件）

安装完成后手动补充 `service/.env` 中的可选配置（SMTP、Stripe、短信等）即可。

### 3.1 手动创建数据库和用户

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

### 3.2 验证连接

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 四、代码部署

### 4.1 拉取代码

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <仓库地址> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 安装依赖

```bash
# Service 依赖
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin 依赖
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 五、环境配置

### 5.1 Service .env 文件

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

编辑 `.env`，填入实际值：

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

### 5.2 生成密钥

```bash
# 生成所有需要的随机密钥
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 创建存储目录

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 六、数据库迁移

### 6.1 创建迁移追踪表

```sql
-- 以迁移用户登录
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 执行迁移

```bash
cd /home/wwwroot/cloud-php/service

# 按顺序执行迁移文件（已在 /tmp/run_migrations.php 中准备好脚本）
php /tmp/run_migrations.php
```

或者一条一条手动执行（适合生产环境谨慎操作）：

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

### 6.3 迁移重跑与 RBAC 种子

迁移由 `php webman migrate` 执行（`service/app/command/MigrateCommand.php`），按文件名顺序应用未执行过的迁移。绝大多数迁移为一次性建表，无需重跑；**唯一例外是 RBAC 种子**：

- `2026_08_17_000001_seed_rbac_permissions.php` 为 **reset 式种子**：执行时先 `delete` 掉 `role_permission` / `permissions` / `roles` 三表全部行，再按文件内矩阵重新插入。因此该迁移可安全重跑，且**重跑不会造成重复行**（显式 id，不受自增影响）。
- 旧库（跑过 `2026_05_20_000006_create_rbac_permissions.php` 时代数据）升级时，需重跑种子才能得到收敛后的权限矩阵：
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **注意**：运行时权限的唯一事实源是 `service/common/auth/Rbac.php` 静态数组，**不依赖数据库**——`RbacMiddleware` 直接读该数组判断权限，DB 种子仅用于管理端展示。修改 `Rbac.php` 时**必须**同步更新种子文件（`permissions()` 并集 + `rolePerms()` 逐角色矩阵），`service/tests/auth/RbacSeedTest.php` 会在测试中静态拦截两者漂移。
- 回滚种子会清空全部角色与权限分配（`down()` 同样 delete 三表），生产环境执行 `php webman migrate:rollback` 前需确认无管理端在线操作。

---

## 七、Nginx 配置

### 7.1 创建配置文件

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

### 7.2 启用站点

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # 检查配置语法
sudo systemctl reload nginx
```

---

## 八、HTTPS 证书

### 8.1 使用 Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# 获取证书
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# 自动续期验证
sudo certbot renew --dry-run
```

### 8.2 取消 Nginx 中 HTTPS 重定向注释

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# 取消 return 301 https://... 的注释
sudo nginx -t
sudo systemctl reload nginx
```

---

## 九、启动服务

### 9.1 启动 webman

```bash
# Service (端口 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (端口 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 验证启动

```bash
# 检查进程
ps aux | grep webman

# 检查端口
ss -tlnp | grep -E '8787|8788'

# 健康检查
curl http://127.0.0.1:8787/health
# 应返回: {"code":0,"message":"ok"}
```

### 9.3 配置 systemd 自启动

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

### 9.4 启动 kvm-server（Rust 供应服务）

kvm-server 是独立的 Rust gRPC 供应服务（`infrastructure/kvm-server`，e-cat workspace），
提供 VM 创建/状态查询，并通过 etcd 注册 + lease 心跳供 PHP 侧（KvmClient/RegistryProcess）
发现。**当前驱动为模拟驱动（simulated），libvirt（virsh）真实驱动为 Phase 2**——部署时
必须显式设 `KVM_DRIVER=simulated`，否则默认 virsh 驱动将返回 NotImplemented。

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

环境变量（`infrastructure/kvm-server/.env`，参考 `service/.env` 对应值）：

| 变量 | 必填 | 说明 |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN（SQLx，与 service 同库） |
| `KVM_REDIS_URL` | ✅ | Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC 调用方鉴权 token（与 PHP 侧配置一致） |
| `KVM_DRIVER` | ✅ | `simulated`（当前唯一可用；`virsh` 为 Phase 2） |
| `KVM_ADDR` | — | HTTP 管理接口地址，默认 `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC 地址，默认 `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd 端点，设置后启用注册/发现（如 `http://127.0.0.1:2379`） |

---

## 十、定时任务

系统内置 cron 调度进程（`config/process.php` 注册 `App\Cron\CronRunner`），每分钟评估
`service/config/cron.php` 中的 5 字段 cron 表达式并执行对应任务，随服务启动自动运行，
**无需外部 crontab**。另设 `queue_consumer` 进程消费 provisioning / notification_* 等
Redis 队列消息。

```bash
# 查看进程状态（http / websocket / metrics / queue_consumer / cron）
php start.php status
```

任务清单（`service/config/cron.php`）：

| cron 表达式 | 任务 |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — 汇率同步 |
| `37 2 * * *` | PaymentReconcile — 支付对账 |
| `17 4 * * 1` | SupplierSettlement — 供应商结算 |
| `23 6 * * *` | ExpirationCheck — 资源/域名到期检查 |
| `43 7,19 * * *` | SslCertificateCheck — SSL 证书检查（数据库） |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — 资源指标采集 |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — 在线证书探测 |
| `7 * * * *` | UsageAggregator::aggregate — 用量聚合 |
| `41 3 * * *` | BillingEngine::runDaily — 每日计费 |
| `11,41 * * * *` | SuspendCheck — 欠费挂起检查 |

---

## 十一、部署验证清单

逐项检查，确认打勾：

### 基础设施
- [ ] MySQL 可连接：`mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis 可连接：`redis-cli ping` → `PONG`
- [ ] PHP 版本 >= 8.2：`php -v`
- [ ] Composer 依赖安装成功
- [ ] Rust 工具链已装（kvm-server）：`rustc --version`
- [ ] kvm-server 运行中：`ss -tlnp | grep 50051`；`curl http://127.0.0.1:8000/health` 通过

### 应用配置
- [ ] `.env` 文件所有必填项已配置
- [ ] JWT 密钥已生成并写入 `.env`
- [ ] 加密主密钥已生成并写入 `.env`
- [ ] Stripe 密钥已配置（如需支付功能）
- [ ] 存储目录权限正确（`chmod 755 storage runtime`）

### 数据库
- [ ] 数据库和用户已创建
- [ ] 迁移全部执行成功：`SELECT COUNT(*) FROM migrations` → 应为 19
- [ ] 核心表存在：`SHOW TABLES LIKE 'users'`

### 服务
- [ ] Nginx 配置语法正确：`nginx -t`
- [ ] webman 进程运行中：`ps aux | grep webman`
- [ ] 端口监听正常：`ss -tlnp | grep 8787`
- [ ] 健康检查通过：`curl http://127.0.0.1:8787/health`
- [ ] HTTPS 证书有效：`curl -I https://api.yourdomain.com/health`

### API 端点抽查
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (有效 JSON)
- [ ] `POST /api/auth/login` (无 body) → 422 (参数校验)
- [ ] `GET /api/user/profile` (无 token) → 401 (鉴权)
- [ ] 版本头：`curl -H 'X-Api-Version: v99' /api/products` → 400

### 定时任务
- [ ] crontab 已配置
- [ ] 日志目录存在且可写

### 安全
- [ ] `.env` 文件权限 600
- [ ] MySQL 未开放远程端口（或仅允许内网）
- [ ] `APP_DEBUG=false`
- [ ] HTTPS 已启用
- [ ] 维护模式可用（取消 MAINTENANCE_MODE 注释可开启）

---

## 十二、常见问题

### webman 启动失败

```bash
# 前台启动看错误
php start.php start
# 常见原因：端口被占用、.env 配置错误、Redis/MySQL 不可达
```

### 迁移执行失败

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

### 日志位置

| 日志 | 路径 |
|------|------|
| webman 自身 | `service/runtime/logs/workerman.log` |
| 应用日志 | `service/runtime/logs/` |
| Cron 日志 | `service/runtime/logs/cron.log` |
| Nginx 访问 | `/var/log/nginx/access.log` |
| Nginx 错误 | `/var/log/nginx/error.log` |
| MySQL 慢查询 | `/var/log/mysql/slow.log` |

### 性能调优

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
