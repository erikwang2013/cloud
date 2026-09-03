# CloudPlatform デプロイメントチェックリスト

## 1. サーバー要件
| 項目 | 最低構成 | 推奨構成 |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 コア | 4 コア以上 |
| メモリ | 4 GB | 8 GB 以上 |
| ディスク | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+（kvm-server プロビジョニングサービス） | 1.75+ |

**開放が必要なポート**: 80 (HTTP)、443 (HTTPS)、8787 (webman 内部、内網のみ)、8788 (admin、内網のみ)、50051 (kvm-server gRPC、内網のみ)、2379 (etcd、内網のみ、kvm-server 登録を有効化する場合)

---

## 2. 環境のインストール
### 2.1 基本依存パッケージ

```bash
# システム更新
sudo apt update && sudo apt upgrade -y

# 基本ツール
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server プロビジョニングサービス)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + 拡張機能 (ppa:ondrej/php 経由)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# 検証
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 MySQL 安全初期化

```bash
sudo mysql_secure_installation
# root パスワード設定、匿名ユーザー削除、リモート root ログイン禁止、test データベース削除
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # 応答は PONG となるはず
```

---

## 3. データベース設定
### 3.0 ワンクリックインストールウィザード（推奨）

プロジェクトルートに Web インストールウィザードが用意されており、データベースのテーブル作成、管理者作成、設定書き込みを自動で行います:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# ブラウザで http://<サーバーIP>:8888 にアクセス
```

ウィザードの手順:
1. 環境チェック（PHP バージョン、拡張機能、ディレクトリ権限）
2. データベース設定（ホスト、ポート、データベース名、ユーザー名、パスワード、自動データベース作成対応）
3. 管理者アカウント設定（ユーザー名、パスワード、メールアドレス）
4. ワンクリック実行（46 テーブル + スーパー管理者ロール + 管理者アカウント + .env ファイル自動生成）

インストール完了後、`service/.env` の任意設定（SMTP、Stripe、SMS など）を手動で補完してください。

### 3.1 データベースとユーザーの手動作成

```sql
-- root でログイン
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- アプリケーションユーザー (読み書き)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<強力なパスワード>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- マイグレーションユーザー (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<強力なパスワード>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- 読み取り専用ユーザー (レポート)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<強力なパスワード>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 接続の検証

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. コードのデプロイ
### 4.1 コードの取得

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <リポジトリURL> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 依存パッケージのインストール

```bash
# Service の依存パッケージ
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin の依存パッケージ
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. 環境設定
### 5.1 Service の .env ファイル

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

`.env` を編集し、実際の値を入力します:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# データベース
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<データベースパスワード>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT シークレット (生成: openssl rand -base64 32；未設定時はサービスが起動を拒否)
JWT_SECRET_KEY=<base64 ランダム文字列>

# 転送暗号化マスターキー (生成: openssl rand -base64 32；32 バイト base64 エンコード、admin と service で同一形式)
ENCRYPTION_MASTER_KEY=<base64エンコードされた32バイトキー>

# フィールド暗号化キー (base64 エンコード；config/encryptable.php が base64_decode 後に使用するため、プレーンな文字列を直接渡すと MissingEncryptionKeyException が発生)
ENCRYPTION_KEY=<base64エンコードされたキー>
# 暗号化アルゴリズム：決定的クエリモードは ECB のみ対応 (aes-128-ecb / aes-256-ecb)、CBC/GCM は起動時にエラー
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<カスタムランダム文字列>
HASHIDS_LENGTH=12

# Stripe 決済
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio SMS
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP メール（symfony/mailer；SMTP_HOST 未設定時は送信記録が dev-stub ステータスになる）
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(暗黙的TLS) / 空
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

# AWS (任意 — クラウドベンダー連携)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM プッシュ通知)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# メンテナンスモード
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (国コード、カンマ区切り、任意)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook 署名キー
WEBHOOK_SECRET=<ランダム文字列>

# 為替レート API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 バックアップ (任意)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 キーの生成

```bash
# 必要なランダムキーをすべて生成
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 ストレージディレクトリの作成

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. データベースマイグレーション
### 6.1 マイグレーション追跡テーブルの作成

```sql
-- マイグレーションユーザーでログイン
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 マイグレーションの実行

```bash
cd /home/wwwroot/cloud-php/service

# マイグレーションファイルを順番に実行（スクリプトは /tmp/run_migrations.php に用意済み）
php /tmp/run_migrations.php
```

または 1 本ずつ手動で実行します（本番環境での慎重な操作に適しています）:

```bash
cd /home/wwwroot/cloud-php/service
# まずマイグレーションファイルの一覧を確認
ls -la database/migrations/

# 1 本ずつ実行（webman-scout はデフォルトの database ドライバで、Elasticsearch は不要）
php -r "
require 'vendor/autoload.php';
// 設定を読み込み...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 マイグレーションの再実行と RBAC シード

マイグレーションは `php webman migrate` で実行され（`service/app/command/MigrateCommand.php`）、ファイル名の順序で未実行のマイグレーションを適用します。大半のマイグレーションは一度きりのテーブル作成で、再実行の必要はありません。**唯一の例外は RBAC シードです**:

- `2026_08_17_000001_seed_rbac_permissions.php` は **reset 式シード**です: 実行時に `role_permission` / `permissions` / `roles` の 3 テーブルの全行を先に `delete` し、ファイル内のマトリックスに従って再挿入します。そのためこのマイグレーションは安全に再実行でき、**再実行しても重複行は発生しません**（明示的な id で、自動インクリメントの影響を受けません）。
- 旧データベース（`2026_05_20_000006_create_rbac_permissions.php` 時代のデータで実行済み）からアップグレードする場合は、シードを再実行して収束後の権限マトリックスを取得する必要があります:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **注意**: 実行時権限の唯一の事実ソースは `service/common/auth/Rbac.php` の静的配列であり、**データベースには依存しません**——`RbacMiddleware` はこの配列を直接読んで権限を判定し、DB シードは管理画面の表示専用です。`Rbac.php` を変更する際は**必ず**シードファイルも同期更新し（`permissions()` の和集合 + `rolePerms()` のロール別マトリックス）、`service/tests/auth/RbacSeedTest.php` がテストで両者のズレを静的に検出します。
- シードをロールバックするとすべてのロールと権限の割り当てがクリアされます（`down()` も同様に 3 テーブルを delete）。本番環境で `php webman migrate:rollback` を実行する前に、管理画面でオンライン操作がないことを確認してください。

---

## 7. Nginx 設定
### 7.1 設定ファイルの作成

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API サービス
server {
    listen 80;
    server_name api.yourdomain.com;

    # HTTPS への強制リダイレクト (証明書設定後にコメント解除)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API リクエストを webman にプロキシ
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # 静的ファイル直接読み取り — uploads サブディレクトリのみ公開（backups/apple/firebase 等は機密データを含むため公開禁止）
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # ヘルスチェックはプロキシキャッシュ不要
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # リクエストボディサイズの制限
    client_max_body_size 10M;
}

# 管理バックエンド
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

# ステータスページ — 任意
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/v1/status;
#     }
# }
```

### 7.2 サイトの有効化

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # 設定構文のチェック
sudo systemctl reload nginx
```

---

## 8. HTTPS 証明書
### 8.1 Certbot の使用 (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# 証明書の取得
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# 自動更新の検証
sudo certbot renew --dry-run
```

### 8.2 Nginx の HTTPS リダイレクトコメント解除

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# return 301 https://... のコメントを解除
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. サービスの起動
### 9.1 webman の起動

```bash
# Service (ポート 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (ポート 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 起動の検証

```bash
# プロセスの確認
ps aux | grep webman

# ポートの確認
ss -tlnp | grep -E '8787|8788'

# ヘルスチェック
curl http://127.0.0.1:8787/health
# 応答: {"code":0,"message":"ok"} となるはず
```

### 9.3 systemd 自動起動の設定

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

### 9.4 kvm-server の起動（Rust プロビジョニングサービス）

kvm-server は独立した Rust gRPC プロビジョニングサービス（`infrastructure/kvm-server`、e-cat workspace）で、
VM の作成/ステータス照会を提供し、etcd 登録 + lease ハートビートで PHP 側（KvmClient/RegistryProcess）
からの発見を可能にします。**現在のドライバはシミュレーションドライバ（simulated）で、libvirt（virsh）の実ドライバは Phase 2 です**——デプロイ時に
`KVM_DRIVER=simulated` を明示的に設定する必要があります。設定しない場合、デフォルトの virsh ドライバは NotImplemented を返します。

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

環境変数（`infrastructure/kvm-server/.env`、`service/.env` の対応値を参照）:

| 変数 | 必須 | 説明 |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN（SQLx、service と同じデータベース） |
| `KVM_REDIS_URL` | ✅ | Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC 呼び出し側の認証 token（PHP 側の設定と一致） |
| `KVM_DRIVER` | ✅ | `simulated`（現在唯一利用可能；`virsh` は Phase 2） |
| `KVM_ADDR` | — | HTTP 管理インターフェースアドレス、デフォルト `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC アドレス、デフォルト `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd エンドポイント、設定すると登録/発見が有効化（例 `http://127.0.0.1:2379`） |

---

## 10. 定期タスク
システムには cron スケジューラプロセスが組み込まれており（`config/process.php` で `App\Cron\CronRunner` を登録）、毎分
`service/config/cron.php` の 5 フィールド cron 式を評価して対応タスクを実行します。サービス起動とともに自動で動作し、
**外部 crontab は不要です**。また `queue_consumer` プロセスが provisioning / notification_* などの
Redis キューメッセージを消費します。

```bash
# プロセスステータスの確認（http / websocket / metrics / queue_consumer / cron）
php start.php status
```

タスク一覧（`service/config/cron.php`）:

| cron 式 | タスク |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — 為替レート同期 |
| `37 2 * * *` | PaymentReconcile — 決済照合 |
| `17 4 * * 1` | SupplierSettlement — サプライヤー決済 |
| `23 6 * * *` | ExpirationCheck — リソース/ドメイン期限チェック |
| `43 7,19 * * *` | SslCertificateCheck — SSL 証明書チェック（データベース） |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — リソースメトリクス収集 |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — オンライン証明書プローブ |
| `7 * * * *` | UsageAggregator::aggregate — 使用量集計 |
| `41 3 * * *` | BillingEngine::runDaily — 日次課金 |
| `11,41 * * * *` | SuspendCheck — 未払いサスペンドチェック |

---

## 11. デプロイ検証チェックリスト
項目を 1 つずつ確認し、チェックを入れます:

### インフラストラクチャ
- [ ] MySQL 接続可: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis 接続可: `redis-cli ping` → `PONG`
- [ ] PHP バージョン >= 8.2: `php -v`
- [ ] Composer 依存パッケージのインストール成功
- [ ] Rust ツールチェーンインストール済み（kvm-server）: `rustc --version`
- [ ] kvm-server 稼働中: `ss -tlnp | grep 50051`；`curl http://127.0.0.1:8000/health` が成功

### アプリケーション設定
- [ ] `.env` ファイルの全必須項目が設定済み
- [ ] JWT キーが生成され `.env` に書き込み済み
- [ ] 暗号化マスターキーが生成され `.env` に書き込み済み
- [ ] Stripe キーが設定済み（決済機能が必要な場合）
- [ ] ストレージディレクトリの権限が正しい（`chmod 755 storage runtime`）

### データベース
- [ ] データベースとユーザーが作成済み
- [ ] マイグレーションがすべて成功: `SELECT COUNT(*) FROM migrations` → 19 になるはず
- [ ] コアテーブルが存在: `SHOW TABLES LIKE 'users'`

### サービス
- [ ] Nginx 設定構文が正しい: `nginx -t`
- [ ] webman プロセス稼働中: `ps aux | grep webman`
- [ ] ポート監視が正常: `ss -tlnp | grep 8787`
- [ ] ヘルスチェック成功: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS 証明書が有効: `curl -I https://api.yourdomain.com/health`

### API エンドポイントの抜き打ちチェック
- [ ] `GET /api/v1/status` → 200
- [ ] `GET /api/v1/products` → 200 (有効な JSON)
- [ ] `POST /api/v1/auth/login` (body なし) → 422 (パラメータ検証)
- [ ] `GET /api/v1/user/profile` (token なし) → 401 (認証)
- [ ] バージョン検証: `curl /api/v99/products` → 400

### 定期タスク
- [ ] crontab が設定済み
- [ ] ログディレクトリが存在し書き込み可能

### セキュリティ
- [ ] `.env` ファイルの権限が 600
- [ ] MySQL のリモートポートが開放されていない（または内網のみ許可）
- [ ] `APP_DEBUG=false`
- [ ] HTTPS が有効化済み
- [ ] メンテナンスモードが利用可能（MAINTENANCE_MODE のコメント解除で有効化）

---

## 12. よくある問題
### webman の起動失敗

```bash
# フォアグラウンド起動でエラーを確認
php start.php start
# よくある原因：ポート占有、.env 設定ミス、Redis/MySQL に到達不能
```

### マイグレーション実行失敗

```bash
# データベース接続の確認
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<パスワード>');"
# テーブルが既に存在するか確認
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman が停止している可能性
sudo systemctl status cloud-platform
# 再起動
sudo systemctl restart cloud-platform
```

### ログの場所

| ログ | パス |
|------|------|
| webman 本体 | `service/runtime/logs/workerman.log` |
| アプリケーションログ | `service/runtime/logs/` |
| Cron ログ | `service/runtime/logs/cron.log` |
| Nginx アクセス | `/var/log/nginx/access.log` |
| Nginx エラー | `/var/log/nginx/error.log` |
| MySQL スロークエリ | `/var/log/mysql/slow.log` |

### パフォーマンスチューニング

```bash
# webman worker 数 (config/server.php)
'count' => 4  # CPU コア数に設定することを推奨

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# 以下の設定を確認：
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL バッファプール
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # 利用可能メモリの 50-70% に設定
```
