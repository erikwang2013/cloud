# CloudPlatform-Deployment-Checkliste

## I. Serveranforderungen

| Punkt | Mindestkonfiguration | Empfohlene Konfiguration |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 Kerne | 4+ Kerne |
| RAM | 4 GB | 8 GB+ |
| Disk | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (kvm-server-Bereitstellungsdienst) | 1.75+ |

**Freizugebende Ports**: 80 (HTTP), 443 (HTTPS), 8787 (webman intern, nur Intranet), 8788 (admin, nur Intranet), 50051 (kvm-server gRPC, nur Intranet), 2379 (etcd, nur Intranet, falls kvm-server-Registrierung aktiviert)

---

## II. Umgebungsinstallation

### 2.1 Basis-Abhängigkeiten

```bash
# Systemaktualisierung
sudo apt update && sudo apt upgrade -y

# Basis-Werkzeuge
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (kvm-server-Bereitstellungsdienst)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + Erweiterungen (über ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# Verifizieren
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 MySQL-Sicherheitsinitialisierung

```bash
sudo mysql_secure_installation
# root-Passwort setzen, anonyme Benutzer löschen, Remote-root-Login verbieten, test-DB löschen
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # sollte PONG zurückgeben
```

---

## III. Datenbankkonfiguration

### 3.0 Ein-Klick-Installationsassistent (empfohlen)

Im Projektstammverzeichnis gibt es einen Web-Installationsassistenten, der automatisch Datenbanktabellen, Admin-Konto und Konfiguration anlegt:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# Browser: http://<Server-IP>:8888
```

Schritte des Assistenten:
1. Umgebungsprüfung (PHP-Version, Erweiterungen, Verzeichnisberechtigungen)
2. Datenbankkonfiguration (Host, Port, DB-Name, Benutzername, Passwort, mit automatischer DB-Erstellung)
3. Admin-Konto-Einrichtung (Benutzername, Passwort, E-Mail)
4. Ein-Klick-Installation (46 Tabellen + Superadmin-Rolle + Admin-Konto + automatisch erzeugte .env-Datei)

Nach der Installation nur noch optionale Konfigurationen in `service/.env` ergänzen (SMTP, Stripe, SMS usw.).

### 3.1 Datenbank und Benutzer manuell erstellen

```sql
-- Als root anmelden
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Anwendungsbenutzer (Lesen/Schreiben)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<starkes Passwort>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- Migrationsbenutzer (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<starkes Passwort>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- Nur-Lese-Benutzer (Reports)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<starkes Passwort>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Verbindung verifizieren

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## IV. Codedeployment

### 4.1 Code klonen

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <Repository-URL> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Abhängigkeiten installieren

```bash
# Service-Abhängigkeiten
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Admin-Abhängigkeiten
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## V. Umgebungskonfiguration

### 5.1 Service-.env-Datei

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

`.env` bearbeiten und echte Werte eintragen:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# Datenbank
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<Datenbankpasswort>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# JWT-Schlüssel (erzeugen: openssl rand -base64 32; ohne Wert verweigert der Dienst den Start)
JWT_SECRET_KEY=<base64-Zufallsstring>

# Transportverschlüsselungs-Hauptschlüssel (erzeugen: openssl rand -base64 32; 32 Byte base64-kodiert, admin und service gleiches Format)
ENCRYPTION_MASTER_KEY=<base64-kodierter 32-Byte-Schlüssel>

# Feldverschlüsselungsschlüssel (base64-kodiert; config/encryptable.php dekodiert mit base64_decode — Klartextstring wirft MissingEncryptionKeyException)
ENCRYPTION_KEY=<base64-kodierter Schlüssel>
# Verschlüsselungsalgorithmus: Deterministisches Abfragen unterstützt nur ECB (aes-128-ecb / aes-256-ecb), CBC/GCM wirft beim Start einen Fehler
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<eigener Zufallsstring>
HASHIDS_LENGTH=12

# Stripe-Zahlung
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Twilio-SMS
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# SMTP-E-Mail (symfony/mailer; ohne SMTP_HOST wird der Versand als dev-stub protokolliert)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(implizites TLS) / leer
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

# AWS (optional — Cloud-Provider-Anbindung)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (FCM-Push)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# Wartungsmodus
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (Ländercodes, kommagetrennt, optional)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Webhook-Signaturschlüssel
WEBHOOK_SECRET=<Zufallsstring>

# Wechselkurs-API
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# S3-Backup (optional)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Schlüssel erzeugen

```bash
# Alle benötigten Zufallsschlüssel erzeugen
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Speicherverzeichnisse anlegen

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## VI. Datenbankmigrationen

### 6.1 Migrationstracking-Tabelle anlegen

```sql
-- Als Migrationsbenutzer anmelden
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Migrationen ausführen

```bash
cd /home/wwwroot/cloud-php/service

# Migrationsdateien der Reihe nach ausführen (Skript liegt unter /tmp/run_migrations.php bereit)
php /tmp/run_migrations.php
```

Oder einzeln manuell ausführen (für vorsichtiges Vorgehen in der Produktion):

```bash
cd /home/wwwroot/cloud-php/service
# Zuerst die Migrationsdateiliste ansehen
ls -la database/migrations/

# Einzeln ausführen (webman-scout nutzt standardmäßig den database-Treiber, kein Elasticsearch nötig)
php -r "
require 'vendor/autoload.php';
// Konfiguration laden...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Migrationen erneut ausführen und RBAC-Seed

Migrationen werden über `php webman migrate` ausgeführt (`service/app/command/MigrateCommand.php`), wobei nicht ausgeführte Migrationen in Dateinamensreihenfolge angewendet werden. Die allermeisten Migrationen sind einmalige Tabellenanlagen und brauchen kein Re-Run; **die einzige Ausnahme ist der RBAC-Seed**:

- `2026_08_17_000001_seed_rbac_permissions.php` ist ein **Reset-Seed**: Er löscht beim Ausführen zuerst alle Zeilen aus `role_permission` / `permissions` / `roles` und fügt dann die Matrix aus der Datei neu ein. Diese Migration kann daher gefahrlos erneut ausgeführt werden, und ein **Re-Run erzeugt keine Duplikate** (explizite ids, unabhängig vom Auto-Increment).
- Beim Upgrade von Altdatenbanken (aus der Ära von `2026_05_20_000006_create_rbac_permissions.php`) muss der Seed erneut ausgeführt werden, um die konsolidierte Berechtigungsmatrix zu erhalten:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Achtung**: Die einzige Wahrheitsquelle für Laufzeitberechtigungen ist das statische Array in `service/common/auth/Rbac.php`, **unabhängig von der Datenbank** — `RbacMiddleware` liest dieses Array direkt für Berechtigungsprüfungen, der DB-Seed dient nur der Admin-Anzeige. Bei Änderungen an `Rbac.php` **muss** der Seed synchron aktualisiert werden (`permissions()`-Vereinigung + `rolePerms()`-Matrix pro Rolle); `service/tests/auth/RbacSeedTest.php` fängt Drift zwischen beiden statisch im Test ab.
- Ein Seed-Rollback leert alle Rollen- und Berechtigungszuweisungen (`down()` löscht ebenfalls die drei Tabellen); vor `php webman migrate:rollback` in der Produktion sicherstellen, dass keine Admin-Operationen online sind.

---

## VII. Nginx-Konfiguration

### 7.1 Konfigurationsdatei erstellen

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# API-Service
server {
    listen 80;
    server_name api.yourdomain.com;

    # HTTPS erzwingen (nach Zertifikatskonfiguration auskommentieren)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # API-Anfragen an webman proxen
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # Statische Dateien direkt — nur das uploads-Unterverzeichnis exponieren (backups/apple/firebase enthalten sensible Daten, öffentlich verboten)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Health-Checks ohne Proxy-Cache
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # Request-Body-Größe begrenzen
    client_max_body_size 10M;
}

# Admin-Panel
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

# Statusseite — optional
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/v1/status;
#     }
# }
```

### 7.2 Site aktivieren

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # Konfigurationssyntax prüfen
sudo systemctl reload nginx
```

---

## VIII. HTTPS-Zertifikate

### 8.1 Certbot verwenden (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Zertifikate abrufen
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# Automatische Verlängerung verifizieren
sudo certbot renew --dry-run
```

### 8.2 HTTPS-Redirect in Nginx auskommentieren

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# return 301 https://... auskommentieren
sudo nginx -t
sudo systemctl reload nginx
```

---

## IX. Dienste starten

### 9.1 webman starten

```bash
# Service (Port 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (Port 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Start verifizieren

```bash
# Prozesse prüfen
ps aux | grep webman

# Ports prüfen
ss -tlnp | grep -E '8787|8788'

# Health-Check
curl http://127.0.0.1:8787/health
# Sollte liefern: {"code":0,"message":"ok"}
```

### 9.3 systemd-Autostart konfigurieren

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

### 9.4 kvm-server starten (Rust-Bereitstellungsdienst)

kvm-server ist ein eigenständiger Rust-gRPC-Bereitstellungsdienst (`infrastructure/kvm-server`, e-cat workspace),
der VM-Erstellung/Statusabfragen anbietet und sich über etcd-Registrierung + Lease-Heartbeat von der PHP-Seite
(KvmClient/RegistryProcess) finden lässt. **Der aktuelle Treiber ist ein Simulations-Treiber (simulated), der echte
libvirt- (virsh-) Treiber ist Phase 2** — beim Deployment muss explizit `KVM_DRIVER=simulated` gesetzt werden,
sonst liefert der standardmäßige virsh-Treiber NotImplemented.

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

Umgebungsvariablen (`infrastructure/kvm-server/.env`, Werte analog zu `service/.env`):

| Variable | Pflicht | Bedeutung |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | MySQL-DSN (SQLx, gleiche DB wie service) |
| `KVM_REDIS_URL` | ✅ | Redis-URL |
| `KVM_AUTH_TOKEN` | ✅ | gRPC-Aufrufer-Auth-Token (konsistent mit der PHP-Seite) |
| `KVM_DRIVER` | ✅ | `simulated` (aktuell einzig verfügbar; `virsh` ist Phase 2) |
| `KVM_ADDR` | — | HTTP-Verwaltungsadresse, Standard `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | gRPC-Adresse, Standard `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | etcd-Endpunkt, bei gesetzt aktiviert Registrierung/Discovery (z. B. `http://127.0.0.1:2379`) |

---

## X. Geplante Tasks

Das System enthält einen eingebauten Cron-Scheduling-Prozess (in `config/process.php` registriert als `App\Cron\CronRunner`), der jede Minute die 5-Feld-Cron-Ausdrücke in
`service/config/cron.php` auswertet und die zugehörigen Tasks ausführt; er startet automatisch mit dem Dienst,
**kein externer crontab nötig**. Zusätzlich konsumiert ein `queue_consumer`-Prozess provisioning / notification_*-Nachrichten aus Redis-Queues.

```bash
# Prozessstatus ansehen (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

Taskliste (`service/config/cron.php`):

| Cron-Ausdruck | Task |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — Wechselkurssynchronisation |
| `37 2 * * *` | PaymentReconcile — Zahlungsabgleich |
| `17 4 * * 1` | SupplierSettlement — Supplier-Abrechnung |
| `23 6 * * *` | ExpirationCheck — Ressourcen-/Domain-Ablaufprüfung |
| `43 7,19 * * *` | SslCertificateCheck — SSL-Zertifikatsprüfung (Datenbank) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — Ressourcen-Metrik-Erfassung |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — Online-Zertifikatsprobe |
| `7 * * * *` | UsageAggregator::aggregate — Nutzungsaggregation |
| `41 3 * * *` | BillingEngine::runDaily — Tägliche Abrechnung |
| `11,41 * * * *` | SuspendCheck — Suspension bei Zahlungsrückstand |

---

## XI. Deployment-Verifizierungscheckliste

Punkt für Punkt abhaken:

### Infrastruktur
- [ ] MySQL erreichbar: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis erreichbar: `redis-cli ping` → `PONG`
- [ ] PHP-Version >= 8.2: `php -v`
- [ ] Composer-Abhängigkeiten installiert
- [ ] Rust-Toolchain installiert (kvm-server): `rustc --version`
- [ ] kvm-server läuft: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` erfolgreich

### Anwendungskonfiguration
- [ ] Alle Pflichtfelder in `.env` konfiguriert
- [ ] JWT-Schlüssel erzeugt und in `.env` geschrieben
- [ ] Verschlüsselungs-Hauptschlüssel erzeugt und in `.env` geschrieben
- [ ] Stripe-Schlüssel konfiguriert (falls Zahlungsfunktion benötigt)
- [ ] Speicherverzeichnis-Berechtigungen korrekt (`chmod 755 storage runtime`)

### Datenbank
- [ ] Datenbank und Benutzer angelegt
- [ ] Migrationen alle erfolgreich: `SELECT COUNT(*) FROM migrations` → sollte 19 sein
- [ ] Kern-Tabellen vorhanden: `SHOW TABLES LIKE 'users'`

### Dienste
- [ ] Nginx-Konfigurationssyntax korrekt: `nginx -t`
- [ ] webman-Prozesse laufen: `ps aux | grep webman`
- [ ] Ports lauschen: `ss -tlnp | grep 8787`
- [ ] Health-Check bestanden: `curl http://127.0.0.1:8787/health`
- [ ] HTTPS-Zertifikat gültig: `curl -I https://api.yourdomain.com/health`

### API-Endpunkt-Stichproben
- [ ] `GET /api/v1/status` → 200
- [ ] `GET /api/v1/products` → 200 (gültiges JSON)
- [ ] `POST /api/v1/auth/login` (ohne body) → 422 (Parametervalidierung)
- [ ] `GET /api/v1/user/profile` (ohne token) → 401 (Authentifizierung)
- [ ] URL-Versionierung: `curl .../api/v1/products` → 200

### Geplante Tasks
- [ ] crontab konfiguriert
- [ ] Logverzeichnis vorhanden und beschreibbar

### Sicherheit
- [ ] `.env`-Dateiberechtigung 600
- [ ] MySQL-Remote-Port nicht offen (oder nur Intranet)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS aktiviert
- [ ] Wartungsmodus verfügbar (durch Auskommentieren von MAINTENANCE_MODE aktivierbar)

---

## XII. Häufige Probleme

### webman startet nicht

```bash
# Im Vordergrund starten, um Fehler zu sehen
php start.php start
# Häufige Ursachen: Port belegt, .env-Konfiguration falsch, Redis/MySQL nicht erreichbar
```

### Migration schlägt fehl

```bash
# Datenbankverbindung prüfen
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<Passwort>');"
# Prüfen, ob die Tabelle bereits existiert
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman könnte abgestürzt sein
sudo systemctl status cloud-platform
# Neustart
sudo systemctl restart cloud-platform
```

### Logpfade

| Log | Pfad |
|------|------|
| webman selbst | `service/runtime/logs/workerman.log` |
| Anwendungslog | `service/runtime/logs/` |
| Cron-Log | `service/runtime/logs/cron.log` |
| Nginx-Zugriff | `/var/log/nginx/access.log` |
| Nginx-Fehler | `/var/log/nginx/error.log` |
| MySQL-Slow-Query | `/var/log/mysql/slow.log` |

### Leistungsoptimierung

```bash
# webman-Worker-Anzahl (config/server.php)
'count' => 4  # Empfehlung: Anzahl der CPU-Kerne

# PHP OPcache
sudo nano /etc/php/8.3/cli/php.ini
# Folgende Einstellungen sicherstellen:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# MySQL-Buffer-Pool
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # 50-70 % des verfügbaren Arbeitsspeichers
```
