# CloudPlatform 배포 체크리스트

## 1. 서버 요구사항
| 항목 | 최소 구성 | 권장 구성 |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2코어 | 4코어+ |
| 메모리 | 4 GB | 8 GB+ |
| 디스크 | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+（kvm-server 공급 서비스） | 1.75+ |

**개방해야 할 포트**: 80 (HTTP), 443 (HTTPS), 8787 (webman 내부, 내부망만), 8788 (admin, 내부망만), 50051 (kvm-server gRPC, 내부망만), 2379 (etcd, 내부망만, kvm-server 등록 활성화 시)

---

## 2. 환경 설치
### 2.1 기본 의존성

```bash
# 시스템 업데이트
sudo apt update && sudo apt upgrade -y

# 기본 도구
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server 공급 서비스)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + 확장 (ppa:ondrej/php 경유)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# 확인
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 MySQL 보안 초기화

```bash
sudo mysql_secure_installation
# root 비밀번호 설정, 익명 사용자 삭제, 원격 root 로그인 금지, test DB 삭제
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # PONG 반환해야 함
```

---

## 3. 데이터베이스 구성
### 3.0 원클릭 설치 마법사 (권장)

프로젝트 루트에 웹 설치 마법사가 있으며, DB 테이블 생성, 관리자 생성, 구성 쓰기를 자동으로 완료합니다:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# 브라우저로 http://<서버IP>:8888 접속
```

마법사 단계:
1. 환경 검사 (PHP 버전, 확장, 디렉터리 권한)
2. DB 구성 (호스트, 포트, DB명, 사용자명, 비밀번호, 자동 DB 생성 지원)
3. 관리자 계정 설정 (사용자명, 비밀번호, 이메일)
4. 원클릭 설치 실행 (테이블 46장 + 슈퍼 관리자 역할 + 관리자 계정 + .env 파일 자동 생성)

설치 완료 후 `service/.env`의 선택 구성 (SMTP, Stripe, SMS 등)을 수동으로 보완하면 됩니다.

### 3.1 수동 DB 및 사용자 생성

```sql
-- root로 로그인
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 애플리케이션 사용자 (읽기/쓰기)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<강력한 비밀번호>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- 마이그레이션 사용자 (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<강력한 비밀번호>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- 읽기 전용 사용자 (리포트)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<강력한 비밀번호>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 연결 확인

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. 코드 배포
### 4.1 코드 가져오기

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <저장소 주소> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 의존성 설치

```bash
# Service 의존성
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin 의존성
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. 환경 구성
### 5.1 Service .env 파일

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

`.env`를 편집해 실제 값을 입력:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# 데이터베이스
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<데이터베이스 비밀번호>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT 키 (생성: openssl rand -base64 32；미설정 시 서비스가 시작 거부)
JWT_SECRET_KEY=<base64 랜덤 문자열>

# 전송 암호화 마스터 키 (생성: openssl rand -base64 32；32바이트 base64 인코딩, admin과 service 동일 형식)
ENCRYPTION_MASTER_KEY=<base64 인코딩된 32바이트 키>

# 필드 암호화 키 (base64 인코딩；config/encryptable.php가 base64_decode 후 사용, 평문 문자열 직접 전달 시 MissingEncryptionKeyException 발생)
ENCRYPTION_KEY=<base64 인코딩된 키>
# 암호화 알고리즘: 결정적 조회 모드는 ECB만 지원 (aes-128-ecb / aes-256-ecb), CBC/GCM은 시작 시 오류 발생
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<커스텀 랜덤 문자열>
HASHIDS_LENGTH=12

# Stripe 결제
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio SMS
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP 메일 (symfony/mailer；SMTP_HOST 미설정 시 전송 기록이 dev-stub 상태로 남음)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(암시적 TLS) / 빈 값
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

# AWS (선택 — 클라우드 벤더 연동)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM 푸시)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# 유지보수 모드
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (국가 코드, 쉼표 구분, 선택)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook 서명 키
WEBHOOK_SECRET=<랜덤 문자열>

# 환율 API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 백업 (선택)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 키 생성

```bash
# 필요한 모든 랜덤 키 생성
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 스토리지 디렉터리 생성

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. 데이터베이스 마이그레이션
### 6.1 마이그레이션 추적 테이블 생성

```sql
-- 마이그레이션 사용자로 로그인
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 마이그레이션 실행

```bash
cd /home/wwwroot/cloud-php/service

# 마이그레이션 파일을 순서대로 실행 (스크립트가 /tmp/run_migrations.php에 준비되어 있음)
php /tmp/run_migrations.php
```

또는 하나씩 수동 실행 (프로덕션 환경에서 신중한 작업에 적합):

```bash
cd /home/wwwroot/cloud-php/service
# 먼저 마이그레이션 파일 목록 확인
ls -la database/migrations/

# 개별 실행 (webman-scout 기본 database 드라이버, Elasticsearch 불필요)
php -r "
require 'vendor/autoload.php';
// 구성 로드...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 마이그레이션 재실행과 RBAC 시드

마이그레이션은 `php webman migrate`로 실행（`service/app/command/MigrateCommand.php`）, 파일명 순서대로 미실행 마이그레이션을 적용합니다. 대부분의 마이그레이션은 일회성 테이블 생성이라 재실행 불필요；**유일한 예외는 RBAC 시드**:

- `2026_08_17_000001_seed_rbac_permissions.php`는 **reset 방식 시드**: 실행 시 `role_permission` / `permissions` / `roles` 3개 테이블의 모든 행을 `delete`한 후, 파일 내 매트릭스대로 재삽입. 따라서 이 마이그레이션은 안전하게 재실행 가능하며, **재실행해도 중복 행이 생기지 않음**（명시적 id, 자동 증가 영향 없음）.
- 구 DB（`2026_05_20_000006_create_rbac_permissions.php` 시절 데이터로 실행됨）업그레이드 시, 수렴된 권한 매트릭스를 얻으려면 시드를 재실행해야 함:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **주의**: 런타임 권한의 유일한 사실 소스는 `service/common/auth/Rbac.php` 정적 배열이며, **DB에 의존하지 않음**——`RbacMiddleware`가 이 배열을 직접 읽어 권한 판정, DB 시드는 관리 단말 표시용일 뿐. `Rbac.php` 수정 시 **반드시** 시드 파일도 동기화해야 함（`permissions()` 합집합 + `rolePerms()` 역할별 매트릭스）, `service/tests/auth/RbacSeedTest.php`가 테스트에서 두 항목의 드리프트를 정적으로 차단.
- 시드 롤백 시 모든 역할과 권한 할당이 비워짐（`down()`도 3개 테이블 delete）, 프로덕션에서 `php webman migrate:rollback` 실행 전에 관리 단말 온라인 작업이 없는지 확인 필요.

---

## 7. Nginx 구성
### 7.1 구성 파일 생성

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API 서비스
server {
    listen 80;
    server_name api.yourdomain.com;

    # 강제 HTTPS (인증서 구성 후 주석 해제)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API 요청을 webman으로 프록시
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # 정적 파일 직접 읽기 — uploads 하위 디렉터리만 노출 (backups/apple/firebase 등은 민감 데이터 포함, 공개 금지)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 헬스 체크는 프록시 캐시 불필요
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # 요청 본문 크기 제한
    client_max_body_size 10M;
}

# 관리 백오피스
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

# 상태 페이지 — 선택
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/v1/status;
#     }
# }
```

### 7.2 사이트 활성화

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # 구성 문법 검사
sudo systemctl reload nginx
```

---

## 8. HTTPS 인증서
### 8.1 Certbot 사용 (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# 인증서 발급
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# 자동 갱신 검증
sudo certbot renew --dry-run
```

### 8.2 Nginx의 HTTPS 리다이렉트 주석 해제

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# return 301 https://... 주석 해제
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. 서비스 시작
### 9.1 webman 시작

```bash
# Service (포트 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (포트 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 시작 확인

```bash
# 프로세스 확인
ps aux | grep webman

# 포트 확인
ss -tlnp | grep -E '8787|8788'

# 헬스 체크
curl http://127.0.0.1:8787/health
# 반환 예상: {"code":0,"message":"ok"}
```

### 9.3 systemd 자동 시작 구성

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

### 9.4 kvm-server 시작 (Rust 공급 서비스)

kvm-server는 독립 Rust gRPC 공급 서비스（`infrastructure/kvm-server`, e-cat workspace）로,
VM 생성/상태 조회를 제공하고 etcd 등록 + lease 하트비트로 PHP 측（KvmClient/RegistryProcess）
이 발견합니다. **현재 드라이버는 시뮬레이션 드라이버（simulated）, libvirt（virsh）실제 드라이버는 Phase 2**——배포 시
반드시 명시적으로 `KVM_DRIVER=simulated`를 설정해야 하며, 아니면 기본 virsh 드라이버가 NotImplemented를 반환합니다.

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

환경 변수（`infrastructure/kvm-server/.env`, `service/.env`의 해당 값을 참고）:

| 변수 | 필수 | 설명 |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN（SQLx, service와 같은 DB） |
| `KVM_REDIS_URL` | ✅ | Redis URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC 호출자 인증 토큰（PHP 측 구성과 일치） |
| `KVM_DRIVER` | ✅ | `simulated`（현재 유일 사용 가능；`virsh`는 Phase 2） |
| `KVM_ADDR` | — | HTTP 관리 인터페이스 주소, 기본 `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC 주소, 기본 `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd 엔드포인트, 설정 시 등록/발견 활성화（예: `http://127.0.0.1:2379`） |

---

## 10. 예약 작업
시스템에 내장된 cron 스케줄 프로세스（`config/process.php`에 `App\Cron\CronRunner` 등록）가 매분
`service/config/cron.php`의 5필드 cron 표현식을 평가해 해당 작업을 실행하며, 서비스 시작과 함께 자동 실행되고,
**외부 crontab 불필요**. 별도로 `queue_consumer` 프로세스가 provisioning / notification_* 등
Redis 큐 메시지를 소비합니다.

```bash
# 프로세스 상태 확인 (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

작업 목록（`service/config/cron.php`）:

| cron 표현식 | 작업 |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — 환율 동기화 |
| `37 2 * * *` | PaymentReconcile — 결제 대조 |
| `17 4 * * 1` | SupplierSettlement — 공급업체 정산 |
| `23 6 * * *` | ExpirationCheck — 리소스/도메인 만료 검사 |
| `43 7,19 * * *` | SslCertificateCheck — SSL 인증서 검사（데이터베이스） |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — 리소스 지표 수집 |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — 온라인 인증서 탐지 |
| `7 * * * *` | UsageAggregator::aggregate — 사용량 집계 |
| `41 3 * * *` | BillingEngine::runDaily — 일일 과금 |
| `11,41 * * * *` | SuspendCheck — 미납 중지 검사 |

---

## 11. 배포 검증 체크리스트
항목별로 확인하고 체크 표시:

### 인프라
- [ ] MySQL 연결 가능: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis 연결 가능: `redis-cli ping` → `PONG`
- [ ] PHP 버전 >= 8.2: `php -v`
- [ ] Composer 의존성 설치 성공
- [ ] Rust 툴체인 설치됨 (kvm-server): `rustc --version`
- [ ] kvm-server 실행 중: `ss -tlnp | grep 50051`；`curl http://127.0.0.1:8000/health` 통과

### 애플리케이션 구성
- [ ] `.env` 파일의 모든 필수 항목 구성됨
- [ ] JWT 키 생성 후 `.env`에 기록됨
- [ ] 암호화 마스터 키 생성 후 `.env`에 기록됨
- [ ] Stripe 키 구성됨 (결제 기능 필요 시)
- [ ] 스토리지 디렉터리 권한 올바름（`chmod 755 storage runtime`）

### 데이터베이스
- [ ] DB와 사용자 생성됨
- [ ] 마이그레이션 모두 실행 성공: `SELECT COUNT(*) FROM migrations` → 19여야 함
- [ ] 핵심 테이블 존재: `SHOW TABLES LIKE 'users'`

### 서비스
- [ ] Nginx 구성 문법 올바름: `nginx -t`
- [ ] webman 프로세스 실행 중: `ps aux | grep webman`
- [ ] 포트 리슨 정상: `ss -tlnp | grep 8787`
- [ ] 헬스 체크 통과: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS 인증서 유효: `curl -I https://api.yourdomain.com/health`

### API 엔드포인트 점검
- [ ] `GET /api/v1/status` → 200
- [ ] `GET /api/v1/products` → 200 (유효 JSON)
- [ ] `POST /api/v1/auth/login` (body 없음) → 422 (파라미터 검증)
- [ ] `GET /api/v1/user/profile` (token 없음) → 401 (인증)
- [ ] 버전 검증: `curl /api/v99/products` → 400

### 예약 작업
- [ ] crontab 구성됨
- [ ] 로그 디렉터리 존재하고 쓰기 가능

### 보안
- [ ] `.env` 파일 권한 600
- [ ] MySQL 원격 포트 미개방 (또는 내부망만 허용)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS 활성화됨
- [ ] 유지보수 모드 사용 가능 (MAINTENANCE_MODE 주석 해제로 활성화)

---

## 12. 자주 묻는 질문
### webman 시작 실패

```bash
# 포그라운드 시작으로 오류 확인
php start.php start
# 흔한 원인: 포트 점유, .env 구성 오류, Redis/MySQL 도달 불가
```

### 마이그레이션 실행 실패

```bash
# DB 연결 확인
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<비밀번호>');"
# 테이블 존재 확인
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman이 죽었을 수 있음
sudo systemctl status cloud-platform
# 재시작
sudo systemctl restart cloud-platform
```

### 로그 위치

| 로그 | 경로 |
|------|------|
| webman 자체 | `service/runtime/logs/workerman.log` |
| 애플리케이션 로그 | `service/runtime/logs/` |
| Cron 로그 | `service/runtime/logs/cron.log` |
| Nginx 접근 | `/var/log/nginx/access.log` |
| Nginx 오류 | `/var/log/nginx/error.log` |
| MySQL 슬로우 쿼리 | `/var/log/mysql/slow.log` |

### 성능 튜닝

```bash
# webman worker 수 (config/server.php)
'count' => 4  # CPU 코어 수로 설정 권장

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# 다음 구성 확인:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL 버퍼 풀
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # 가용 메모리의 50-70%로 설정
```
