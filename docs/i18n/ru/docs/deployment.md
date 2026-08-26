# Чек-лист развёртывания CloudPlatform

## 1. Требования к серверу
| Пункт | Минимальная конфигурация | Рекомендуемая конфигурация |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 ядра | 4 ядра+ |
| Память | 4 GB | 8 GB+ |
| Диск | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (сервис поставки kvm-server) | 1.75+ |

**Порты, которые нужно открыть**: 80 (HTTP)、443 (HTTPS)、8787 (внутренний webman, только внутренняя сеть)、8788 (admin, только внутренняя сеть)、50051 (gRPC kvm-server, только внутренняя сеть)、2379 (etcd, только внутренняя сеть, если включена регистрация kvm-server)

---

## 2. Установка окружения
### 2.1 Базовые зависимости

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Базовые инструменты
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (сервис поставки kvm-server)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + расширения (через ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# Проверка
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 Безопасная инициализация MySQL

```bash
sudo mysql_secure_installation
# Задать root-пароль, удалить анонимных пользователей, запретить удалённый root-вход, удалить тестовую базу
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # Должен вернуть PONG
```

---

## 3. Конфигурация базы данных
### 3.0 Мастер установки в один клик (рекомендуется)

В корне проекта предоставлен Web-мастер установки, который автоматически выполняет создание таблиц, создание администратора и запись конфигурации:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# Открыть в браузере http://<IP сервера>:8888
```

Шаги мастера:
1. Проверка окружения (версия PHP, расширения, права на каталоги)
2. Конфигурация базы данных (хост, порт, имя базы, пользователь, пароль, поддержка автоматического создания базы)
3. Настройка учётной записи администратора (имя пользователя, пароль, почта)
4. Выполнение установки в один клик (46 таблиц + роль суперадминистратора + учётная запись администратора + автоматическая генерация файла .env)

После завершения установки достаточно вручную дополнить опциональную конфигурацию в `service/.env` (SMTP, Stripe, SMS и т.п.).

### 3.1 Создание базы данных и пользователей вручную

```sql
-- Вход под root
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Пользователь приложения (чтение/запись)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<надёжный пароль>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- Пользователь миграций (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<надёжный пароль>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- Пользователь только для чтения (отчёты)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<надёжный пароль>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Проверка подключения

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. Развёртывание кода
### 4.1 Получение кода

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <адрес репозитория> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Установка зависимостей

```bash
# Зависимости Service
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Зависимости Admin
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. Конфигурация окружения
### 5.1 Файл .env сервиса

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

Отредактируйте `.env`, впишите фактические значения:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# База данных
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<пароль базы данных>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Ключ JWT (генерация: openssl rand -base64 32；без него сервис откажется запускаться)
JWT_SECRET_KEY=<base64 случайная строка>

# Мастер-ключ транспортного шифрования (генерация: openssl rand -base64 32；32 байта в base64, формат одинаков для admin и service)
ENCRYPTION_MASTER_KEY=<32-байтный ключ в base64>

# Ключ шифрования полей (base64；config/encryptable.php делает base64_decode перед использованием, прямая передача текстовой строки вызовет MissingEncryptionKeyException)
ENCRYPTION_KEY=<ключ в base64>
# Алгоритм шифрования: режим детерминированных запросов поддерживает только ECB (aes-128-ecb / aes-256-ecb), при CBC/GCM запуск сразу падает с ошибкой
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<пользовательская случайная строка>
HASHIDS_LENGTH=12

# Оплата Stripe
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# SMS Twilio
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# Почта SMTP (symfony/mailer；без SMTP_HOST отправка фиксируется в состоянии dev-stub)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(неявный TLS) / пусто
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

# AWS (опционально — интеграция с облачными провайдерами)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (push FCM)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# Режим обслуживания
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Геоблокировка (коды стран через запятую, опционально)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Секрет подписи Webhook
WEBHOOK_SECRET=<случайная строка>

# API курсов валют
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3 резервное копирование (опционально)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Генерация ключей

```bash
# Сгенерировать все необходимые случайные ключи
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Создание каталогов хранилища

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. Миграции базы данных
### 6.1 Создание таблицы отслеживания миграций

```sql
-- Вход под пользователя миграций
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Выполнение миграций

```bash
cd /home/wwwroot/cloud-php/service

# Выполнить файлы миграций по порядку (скрипт уже подготовлен в /tmp/run_migrations.php)
php /tmp/run_migrations.php
```

Либо выполнять по одной вручную (подходит для осторожной работы в проде):

```bash
cd /home/wwwroot/cloud-php/service
# Сначала посмотреть список файлов миграций
ls -la database/migrations/

# Выполнять по одной (webman-scout по умолчанию использует database-драйвер, Elasticsearch не нужен)
php -r "
require 'vendor/autoload.php';
// Загрузить конфигурацию...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Повторный прогон миграций и сид RBAC

Миграции выполняются командой `php webman migrate` (`service/app/command/MigrateCommand.php`), применяя неприменённые миграции в порядке имён файлов. Подавляющее большинство миграций — разовое создание таблиц, повторный прогон не нужен; **единственное исключение — сид RBAC**:

- `2026_08_17_000001_seed_rbac_permissions.php` — сид в стиле **reset**: при выполнении сначала `delete` всех строк таблиц `role_permission` / `permissions` / `roles`, затем повторная вставка по матрице из файла. Поэтому миграцию можно безопасно перезапускать, и **повторный прогон не даст дублирующихся строк** (явные id, не зависят от автоинкремента).
- При обновлении старой базы (данные времён `2026_08_20_000006_create_rbac_permissions.php`) требуется перезапустить сид, чтобы получить сведённую матрицу прав:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Внимание**: единственный фактический источник прав времени выполнения — статический массив `service/common/auth/Rbac.php`, **от базы данных не зависит** — `RbacMiddleware` читает права напрямую из этого массива, сид БД используется только для отображения в панели управления. При изменении `Rbac.php` **обязательно** синхронно обновлять файл сида (объединение `permissions()` + матрица `rolePerms()` по каждой роли), а `service/tests/auth/RbacSeedTest.php` в тестах статически перехватывает их расхождение.
- Откат сида очистит все роли и назначения прав (`down()` тоже delete по трём таблицам); перед `php webman migrate:rollback` в проде нужно убедиться, что нет онлайн-операций в панели управления.

---

## 7. Конфигурация Nginx
### 7.1 Создание файла конфигурации

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API-сервис
server {
    listen 80;
    server_name api.yourdomain.com;

    # Принудительный HTTPS (раскомментировать после настройки сертификата)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # Проксирование API-запросов в webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # Прямое чтение статики — открыт только подкаталог uploads (backups/apple/firebase содержат чувствительные данные, публичный доступ запрещён)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Проверки здоровья не требуют прокси-кэша
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # Ограничение размера тела запроса
    client_max_body_size 10M;
}

# Панель управления
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

# Страница статуса — опционально
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 Активация сайта

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # Проверить синтаксис конфигурации
sudo systemctl reload nginx
```

---

## 8. HTTPS-сертификаты
### 8.1 Использование Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Получение сертификатов
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# Проверка автоматического продления
sudo certbot renew --dry-run
```

### 8.2 Раскомментировать HTTPS-редирект в Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# Раскомментировать return 301 https://...
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. Запуск сервисов
### 9.1 Запуск webman

```bash
# Service (порт 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (порт 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Проверка запуска

```bash
# Проверить процессы
ps aux | grep webman

# Проверить порты
ss -tlnp | grep -E '8787|8788'

# Проверка здоровья
curl http://127.0.0.1:8787/health
# Должен вернуться: {"code":0,"message":"ok"}
```

### 9.3 Настройка автозапуска systemd

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

### 9.4 Запуск kvm-server (Rust-сервис поставки)

kvm-server — это отдельный Rust gRPC-сервис поставки (`infrastructure/kvm-server`, workspace e-cat),
предоставляет создание VM/запрос статуса, регистрируется через etcd + lease-сердцебиение для обнаружения
со стороны PHP (KvmClient/RegistryProcess). **Сейчас используется имитационный драйвер (simulated),
реальный драйвер libvirt (virsh) — это Phase 2** — при развёртывании
обязательно явно задать `KVM_DRIVER=simulated`, иначе драйвер virsh по умолчанию вернёт NotImplemented.

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

Переменные окружения (`infrastructure/kvm-server/.env`, ориентироваться на соответствующие значения `service/.env`):

| Переменная | Обязательна | Описание |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL DSN (SQLx, та же база, что и service) |
| `KVM_REDIS_URL` | ✅ | URL Redis |
| `KVM_AUTH_TOKEN` | ✅ | Токен аутентификации вызывающей стороны gRPC (должен совпадать с конфигурацией PHP) |
| `KVM_DRIVER` | ✅ | `simulated` (единственный доступный сейчас; `virsh` — Phase 2) |
| `KVM_ADDR` | — | Адрес HTTP-интерфейса управления, по умолчанию `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | Адрес gRPC, по умолчанию `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | Эндпоинт etcd, при задании включает регистрацию/обнаружение (например `http://127.0.0.1:2379`) |

---

## 10. Планировщик задач
В системе встроен процесс планировщика cron (`App\Cron\CronRunner`, регистрируется в `config/process.php`), каждую минуту
оценивает 5-польные cron-выражения из `service/config/cron.php` и выполняет соответствующие задачи, запускается автоматически
вместе с сервисом, **внешний crontab не нужен**. Также имеется процесс `queue_consumer` для потребления сообщений
очередей Redis provisioning / notification_* и др.

```bash
# Просмотр статуса процессов (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

Список задач (`service/config/cron.php`):

| cron-выражение | Задача |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — синхронизация курсов валют |
| `37 2 * * *` | PaymentReconcile — сверка платежей |
| `17 4 * * 1` | SupplierSettlement — расчёты с поставщиками |
| `23 6 * * *` | ExpirationCheck — проверка истечения ресурсов/доменов |
| `43 7,19 * * *` | SslCertificateCheck — проверка SSL-сертификатов (база данных) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — сбор метрик ресурсов |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — онлайн-проверка сертификатов |
| `7 * * * *` | UsageAggregator::aggregate — агрегация использования |
| `41 3 * * *` | BillingEngine::runDaily — ежедневный расчёт |
| `11,41 * * * *` | SuspendCheck — проверка приостановки при задолженности |

---

## 11. Чек-лист проверки развёртывания
Проверьте по пунктам, отметьте галочками:

### Инфраструктура
- [ ] MySQL подключается: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis подключается: `redis-cli ping` → `PONG`
- [ ] Версия PHP >= 8.2: `php -v`
- [ ] Зависимости Composer установлены успешно
- [ ] Инструментарий Rust установлен (kvm-server): `rustc --version`
- [ ] kvm-server запущен: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` проходит

### Конфигурация приложения
- [ ] Все обязательные поля в файле `.env` заполнены
- [ ] Ключ JWT сгенерирован и записан в `.env`
- [ ] Мастер-ключ шифрования сгенерирован и записан в `.env`
- [ ] Ключи Stripe настроены (если нужна оплата)
- [ ] Права на каталоги хранилища корректны (`chmod 755 storage runtime`)

### База данных
- [ ] База данных и пользователи созданы
- [ ] Все миграции выполнены успешно: `SELECT COUNT(*) FROM migrations` → должно быть 19
- [ ] Ключевые таблицы существуют: `SHOW TABLES LIKE 'users'`

### Сервисы
- [ ] Синтаксис конфигурации Nginx корректен: `nginx -t`
- [ ] Процессы webman запущены: `ps aux | grep webman`
- [ ] Порты прослушиваются корректно: `ss -tlnp | grep 8787`
- [ ] Проверка здоровья проходит: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS-сертификат действителен: `curl -I https://api.yourdomain.com/health`

### Выборочная проверка эндпоинтов API
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (корректный JSON)
- [ ] `POST /api/auth/login` (без body) → 422 (проверка параметров)
- [ ] `GET /api/user/profile` (без токена) → 401 (аутентификация)
- [ ] Заголовок версии: `curl -H 'X-Api-Version: v99' /api/products` → 400

### Планировщик задач
- [ ] crontab настроен
- [ ] Каталог логов существует и доступен для записи

### Безопасность
- [ ] Права на файл `.env` — 600
- [ ] У MySQL не открыт удалённый порт (или только внутренняя сеть)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS включён
- [ ] Режим обслуживания доступен (включается раскомментированием MAINTENANCE_MODE)

---

## 12. Частые вопросы
### webman не запускается

```bash
# Запуск на переднем плане для просмотра ошибки
php start.php start
# Частые причины: порт занят, ошибка конфигурации .env, Redis/MySQL недоступны
```

### Ошибка выполнения миграций

```bash
# Проверить подключение к базе данных
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<пароль>');"
# Проверить, существует ли таблица
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman мог упасть
sudo systemctl status cloud-platform
# Перезапуск
sudo systemctl restart cloud-platform
```

### Расположение логов

| Лог | Путь |
|------|------|
| Сам webman | `service/runtime/logs/workerman.log` |
| Логи приложения | `service/runtime/logs/` |
| Лог Cron | `service/runtime/logs/cron.log` |
| Доступ Nginx | `/var/log/nginx/access.log` |
| Ошибки Nginx | `/var/log/nginx/error.log` |
| Медленные запросы MySQL | `/var/log/mysql/slow.log` |

### Тюнинг производительности

```bash
# Количество worker'ов webman (config/server.php)
'count' => 4  # Рекомендуется установить равным числу ядер CPU

# OPcache PHP
sudo nano /etc/php/8.3/cli/php.ini
# Убедиться в следующих настройках:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# Буферный пул MySQL
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # Установить 50-70% доступной памяти
```
