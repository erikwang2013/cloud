# Checklist de déploiement CloudPlatform

## I. Exigences serveur

| Élément | Configuration minimale | Configuration recommandée |
|------|---------|---------|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 cœurs | 4 cœurs+ |
| Mémoire | 4 Go | 8 Go+ |
| Disque | 40 Go SSD | 100 Go SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (service de livraison kvm-server) | 1.75+ |

**Ports à ouvrir** : 80 (HTTP), 443 (HTTPS), 8787 (webman interne, intranet uniquement), 8788 (admin, intranet uniquement), 50051 (gRPC kvm-server, intranet uniquement), 2379 (etcd, intranet uniquement, si l'enregistrement kvm-server est activé)

---

## II. Installation de l'environnement

### 2.1 Dépendances de base

```bash
# Mise à jour du système
sudo apt update && sudo apt upgrade -y

# Outils de base
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (service de livraison kvm-server)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + extensions (via ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# Vérification
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 Initialisation de sécurité MySQL

```bash
sudo mysql_secure_installation
# Définir le mot de passe root, supprimer les utilisateurs anonymes, interdire le
# root à distance, supprimer la base test
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # doit renvoyer PONG
```

---

## III. Configuration de la base de données

### 3.0 Assistant d'installation en une commande (recommandé)

La racine du projet fournit un assistant d'installation Web qui crée automatiquement les tables, l'administrateur et écrit la configuration :

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# Accéder dans le navigateur à http://<IP du serveur>:8888
```

Étapes de l'assistant :
1. Vérification de l'environnement (version PHP, extensions, permissions des répertoires)
2. Configuration de la base de données (hôte, port, nom de base, nom d'utilisateur, mot de passe, création automatique prise en charge)
3. Configuration du compte administrateur (nom d'utilisateur, mot de passe, e-mail)
4. Exécution de l'installation en une commande (46 tables + rôle super-admin + compte administrateur + génération automatique du fichier .env)

Après l'installation, compléter manuellement les configurations optionnelles de `service/.env` (SMTP, Stripe, SMS, etc.).

### 3.1 Création manuelle de la base et de l'utilisateur

```sql
-- Connexion en root
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Utilisateur applicatif (lecture/écriture)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<mot de passe fort>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- Utilisateur de migration (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<mot de passe fort>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- Utilisateur en lecture seule (rapports)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<mot de passe fort>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Vérification de la connexion

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## IV. Déploiement du code

### 4.1 Récupération du code

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <adresse du dépôt> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Installation des dépendances

```bash
# Dépendances du service
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Dépendances de l'admin
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## V. Configuration de l'environnement

### 5.1 Fichier .env du service

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

Éditer `.env` et remplir les valeurs réelles :

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# Base de données
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<mot de passe de la base>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Clé JWT (génération : openssl rand -base64 32 ; le service refuse de démarrer
# si elle n'est pas définie)
JWT_SECRET_KEY=<chaîne aléatoire base64>

# Clé maîtresse de chiffrement de transport (génération :
# openssl rand -base64 32 ; clé de 32 octets encodée en base64, même format
# pour admin et service)
ENCRYPTION_MASTER_KEY=<clé de 32 octets encodée en base64>

# Clé de chiffrement des champs (encodée en base64 ; config/encryptable.php
# fait un base64_decode avant usage ; transmettre une chaîne en clair lève
# MissingEncryptionKeyException)
ENCRYPTION_KEY=<clé encodée en base64>
# Algorithme de chiffrement : le mode de requête déterministe ne prend en
# charge que ECB (aes-128-ecb / aes-256-ecb), CBC/GCM lèvent une erreur au
# démarrage
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<chaîne aléatoire personnalisée>
HASHIDS_LENGTH=12

# Paiement Stripe
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# SMS Twilio
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# E-mail SMTP (symfony/mailer ; sans SMTP_HOST configuré, l'envoi est
# enregistré au statut dev-stub)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(TLS implicite) / vide
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

# AWS (optionnel — connexion aux fournisseurs cloud)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (push FCM)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# Mode maintenance
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (codes pays, séparés par des virgules, optionnel)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Clé de signature Webhook
WEBHOOK_SECRET=<chaîne aléatoire>

# API de taux de change
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# Sauvegarde S3 (optionnel)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Génération des clés

```bash
# Générer toutes les clés aléatoires nécessaires
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Création des répertoires de stockage

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## VI. Migrations de base de données

### 6.1 Création de la table de suivi des migrations

```sql
-- Connexion avec l'utilisateur de migration
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Exécution des migrations

```bash
cd /home/wwwroot/cloud-php/service

# Exécuter les fichiers de migration dans l'ordre (script déjà préparé dans
# /tmp/run_migrations.php)
php /tmp/run_migrations.php
```

Ou exécuter manuellement un par un (adapté à une manipulation prudente en production) :

```bash
cd /home/wwwroot/cloud-php/service
# D'abord consulter la liste des fichiers de migration
ls -la database/migrations/

# Exécuter un par un (le pilote database de webman-scout par défaut ne
# nécessite pas Elasticsearch)
php -r "
require 'vendor/autoload.php';
// Charger la configuration...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Re-exécution des migrations et seed RBAC

Les migrations sont exécutées par `php webman migrate` (`service/app/command/MigrateCommand.php`), qui applique dans l'ordre des noms de fichiers les migrations non encore exécutées. La grande majorité des migrations sont des créations de tables en une fois, sans besoin de re-exécution ; **la seule exception est le seed RBAC** :

- `2026_08_17_000001_seed_rbac_permissions.php` est un **seed de type reset** : à l'exécution, il supprime d'abord toutes les lignes des trois tables `role_permission` / `permissions` / `roles`, puis réinsère selon la matrice du fichier. Cette migration peut donc être re-exécutée sans risque, et **la re-exécution ne crée pas de lignes dupliquées** (id explicites, non affectés par l'auto-incrément).
- Pour les anciennes bases (ayant exécuté les données de l'époque `2026_05_20_000006_create_rbac_permissions.php`), la re-exécution du seed est nécessaire pour obtenir la matrice de permissions convergée :
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Attention** : la source de vérité unique des permissions à l'exécution est le tableau statique `service/common/auth/Rbac.php`, **indépendant de la base de données** — `RbacMiddleware` lit directement ce tableau pour juger des permissions, le seed DB ne sert qu'à l'affichage côté administration. Toute modification de `Rbac.php` **doit** être synchronisée dans le fichier de seed (`permissions()` en union + `rolePerms()` matrice par rôle), et `service/tests/auth/RbacSeedTest.php` intercepte statiquement toute dérive entre les deux lors des tests.
- La régression du seed vide toutes les attributions de rôles et permissions (`down()` supprime également les trois tables) ; avant d'exécuter `php webman migrate:rollback` en production, confirmer qu'aucune opération n'est en cours côté panneau d'administration.

---

## VII. Configuration Nginx

### 7.1 Création du fichier de configuration

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# Service API
server {
    listen 80;
    server_name api.yourdomain.com;

    # Forcer HTTPS (décommenter après configuration du certificat)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # Proxifier les requêtes API vers webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # Lecture directe des fichiers statiques — n'exposer que le sous-répertoire
    # uploads (backups/apple/firebase etc. contiennent des données sensibles,
    # interdits au public)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # La vérification de santé n'a pas besoin de cache proxy
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # Limiter la taille du corps de requête
    client_max_body_size 10M;
}

# Panneau d'administration
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

# Page de statut — optionnel
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 Activation du site

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # vérifier la syntaxe de la configuration
sudo systemctl reload nginx
```

---

## VIII. Certificats HTTPS

### 8.1 Utilisation de Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Obtention du certificat
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# Vérification du renouvellement automatique
sudo certbot renew --dry-run
```

### 8.2 Décommenter la redirection HTTPS dans Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# Décommenter return 301 https://...
sudo nginx -t
sudo systemctl reload nginx
```

---

## IX. Démarrage des services

### 9.1 Démarrage de webman

```bash
# Service (port 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (port 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Vérification du démarrage

```bash
# Vérifier les processus
ps aux | grep webman

# Vérifier les ports
ss -tlnp | grep -E '8787|8788'

# Vérification de santé
curl http://127.0.0.1:8787/health
# doit renvoyer : {"code":0,"message":"ok"}
```

### 9.3 Configuration du démarrage automatique systemd

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

### 9.4 Démarrage de kvm-server (service de livraison Rust)

kvm-server est un service de livraison gRPC Rust indépendant (`infrastructure/kvm-server`,
workspace e-cat), qui fournit la création/consultation d'état des VM, et s'enregistre
via etcd + heartbeat lease pour la découverte côté PHP (KvmClient/RegistryProcess).
**Le pilote actuel est un pilote simulé (simulated), le vrai pilote libvirt (virsh)
est en Phase 2** — lors du déploiement, définir explicitement `KVM_DRIVER=simulated`,
sinon le pilote virsh par défaut renverra NotImplemented.

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

Variables d'environnement (`infrastructure/kvm-server/.env`, se référer aux valeurs correspondantes de `service/.env`) :

| Variable | Requis | Description |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | DSN MySQL (SQLx, même base que le service) |
| `KVM_REDIS_URL` | ✅ | URL Redis |
| `KVM_AUTH_TOKEN` | ✅ | Token d'authentification des appelants gRPC (cohérent avec la configuration PHP) |
| `KVM_DRIVER` | ✅ | `simulated` (seul disponible actuellement ; `virsh` en Phase 2) |
| `KVM_ADDR` | — | Adresse de l'interface d'administration HTTP, défaut `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | Adresse gRPC, défaut `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | Point de terminaison etcd, active l'enregistrement/la découverte s'il est défini (ex. `http://127.0.0.1:2379`) |

---

## X. Tâches planifiées

Le système intègre un processus de planification cron (`config/process.php` enregistre
`App\Cron\CronRunner`), qui évalue chaque minute les expressions cron à 5 champs de
`service/config/cron.php` et exécute les tâches correspondantes ; il démarre
automatiquement avec le service, **sans crontab externe**. Un processus
`queue_consumer` consomme en parallèle les messages des files Redis provisioning /
notification_* etc.

```bash
# Consulter l'état des processus (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

Liste des tâches (`service/config/cron.php`) :

| Expression cron | Tâche |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — synchronisation des taux de change |
| `37 2 * * *` | PaymentReconcile — rapprochement des paiements |
| `17 4 * * 1` | SupplierSettlement — règlement des fournisseurs |
| `23 6 * * *` | ExpirationCheck — vérification des expirations de ressources/domaines |
| `43 7,19 * * *` | SslCertificateCheck — vérification des certificats SSL (base de données) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — collecte des métriques de ressources |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — sondage des certificats en ligne |
| `7 * * * *` | UsageAggregator::aggregate — agrégation de l'usage |
| `41 3 * * *` | BillingEngine::runDaily — facturation quotidienne |
| `11,41 * * * *` | SuspendCheck — vérification de suspension pour impayé |

---

## XI. Checklist de validation du déploiement

Cocher chaque point après vérification :

### Infrastructure
- [ ] MySQL joignable : `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis joignable : `redis-cli ping` → `PONG`
- [ ] Version PHP >= 8.2 : `php -v`
- [ ] Dépendances Composer installées avec succès
- [ ] Chaîne d'outils Rust installée (kvm-server) : `rustc --version`
- [ ] kvm-server en cours d'exécution : `ss -tlnp | grep 50051` ; `curl http://127.0.0.1:8000/health` OK

### Configuration de l'application
- [ ] Tous les champs obligatoires du fichier `.env` configurés
- [ ] Clé JWT générée et écrite dans `.env`
- [ ] Clé maîtresse de chiffrement générée et écrite dans `.env`
- [ ] Clés Stripe configurées (si la fonctionnalité de paiement est nécessaire)
- [ ] Permissions des répertoires de stockage correctes (`chmod 755 storage runtime`)

### Base de données
- [ ] Base de données et utilisateurs créés
- [ ] Toutes les migrations exécutées avec succès : `SELECT COUNT(*) FROM migrations` → doit être 19
- [ ] Tables principales présentes : `SHOW TABLES LIKE 'users'`

### Services
- [ ] Syntaxe de la configuration Nginx correcte : `nginx -t`
- [ ] Processus webman en cours : `ps aux | grep webman`
- [ ] Écoute des ports normale : `ss -tlnp | grep 8787`
- [ ] Vérification de santé OK : `curl http://127.0.0.1:8787/health`
- [ ] Certificat HTTPS valide : `curl -I https://api.yourdomain.com/health`

### Sondage des points de terminaison API
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (JSON valide)
- [ ] `POST /api/auth/login` (sans body) → 422 (validation des paramètres)
- [ ] `GET /api/user/profile` (sans token) → 401 (authentification)
- [ ] En-tête de version : `curl -H 'X-Api-Version: v99' /api/products` → 400

### Tâches planifiées
- [ ] crontab configuré
- [ ] Répertoire de journaux existant et inscriptible

### Sécurité
- [ ] Permissions du fichier `.env` à 600
- [ ] MySQL ne permet pas l'accès à distance (ou uniquement via l'intranet)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS activé
- [ ] Mode maintenance disponible (s'active en décommentant MAINTENANCE_MODE)

---

## XII. Questions fréquentes

### Échec de démarrage de webman

```bash
# Démarrage au premier plan pour voir l'erreur
php start.php start
# Causes fréquentes : port occupé, configuration .env erronée, Redis/MySQL
# injoignables
```

### Échec d'exécution de la migration

```bash
# Vérifier la connexion à la base
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<mot de passe>');"
# Vérifier si les tables existent déjà
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman est peut-être arrêté
sudo systemctl status cloud-platform
# Redémarrer
sudo systemctl restart cloud-platform
```

### Emplacement des journaux

| Journal | Chemin |
|------|------|
| webman lui-même | `service/runtime/logs/workerman.log` |
| Journal applicatif | `service/runtime/logs/` |
| Journal Cron | `service/runtime/logs/cron.log` |
| Accès Nginx | `/var/log/nginx/access.log` |
| Erreurs Nginx | `/var/log/nginx/error.log` |
| Requêtes lentes MySQL | `/var/log/mysql/slow.log` |

### Optimisation des performances

```bash
# Nombre de workers webman (config/server.php)
'count' => 4  # recommandé : nombre de cœurs CPU

# OPcache PHP
sudo nano /etc/php/8.3/cli/php.ini
# Vérifier les configurations suivantes :
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# Pool de buffers MySQL
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # à définir à 50-70 % de la mémoire disponible
```
