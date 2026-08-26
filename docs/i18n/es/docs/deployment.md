# Lista de verificación de despliegue de CloudPlatform

## 1. Requisitos del servidor

| Elemento | Configuración mínima | Configuración recomendada |
|------|---------|---------|
| SO | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 núcleos | 4 núcleos o más |
| Memoria | 4 GB | 8 GB o más |
| Disco | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (servicio de aprovisionamiento kvm-server) | 1.75+ |

**Puertos que deben abrirse**: 80 (HTTP), 443 (HTTPS), 8787 (webman interno, solo red interna), 8788 (admin, solo red interna), 50051 (gRPC de kvm-server, solo red interna), 2379 (etcd, solo red interna, si se habilita el registro de kvm-server)

---

## 2. Instalación del entorno

### 2.1 Dependencias básicas

```bash
# Actualización del sistema
sudo apt update && sudo apt upgrade -y

# Herramientas básicas
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (servicio de aprovisionamiento kvm-server)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + extensiones (mediante ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# Verificación
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 Inicialización segura de MySQL

```bash
sudo mysql_secure_installation
# Establecer la contraseña de root, eliminar usuarios anónimos, prohibir el root remoto, eliminar la base test
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # debe devolver PONG
```

---

## 3. Configuración de la base de datos

### 3.0 Asistente de instalación con un clic (recomendado)

La raíz del proyecto incluye un asistente de instalación web que crea automáticamente las tablas, el administrador y escribe la configuración:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# Acceder desde el navegador a http://<IP del servidor>:8888
```

Pasos del asistente:
1. Comprobación del entorno (versión de PHP, extensiones, permisos de directorios)
2. Configuración de la base de datos (host, puerto, nombre de base, usuario, contraseña; soporta creación automática de la base)
3. Configuración de la cuenta de administrador (usuario, contraseña, correo)
4. Ejecución de la instalación con un clic (46 tablas + rol de superadministrador + cuenta de administrador + generación automática del archivo .env)

Tras la instalación, basta con completar manualmente la configuración opcional de `service/.env` (SMTP, Stripe, SMS, etc.).

### 3.1 Creación manual de la base de datos y el usuario

```sql
-- Iniciar sesión como root
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Usuario de la aplicación (lectura/escritura)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<contraseña_fuerte>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- Usuario de migraciones (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<contraseña_fuerte>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- Usuario de solo lectura (reportes)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<contraseña_fuerte>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Verificar la conexión

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. Despliegue del código

### 4.1 Clonar el repositorio

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <dirección_del_repositorio> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Instalar dependencias

```bash
# Dependencias de Service
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Dependencias de Admin
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. Configuración del entorno

### 5.1 Archivo .env de Service

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

Editar `.env` y completar los valores reales:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# Base de datos
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<contraseña_de_la_base_de_datos>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Clave JWT (generar: openssl rand -base64 32; el servicio se niega a arrancar si no está definida)
JWT_SECRET_KEY=<cadena aleatoria base64>

# Clave maestra de cifrado de transporte (generar: openssl rand -base64 32; clave de 32 bytes en base64, mismo formato en admin y service)
ENCRYPTION_MASTER_KEY=<clave de 32 bytes codificada en base64>

# Clave de cifrado de campos (codificada en base64; config/encryptable.php hace base64_decode antes de usarla; pasar una cadena en claro lanza MissingEncryptionKeyException)
ENCRYPTION_KEY=<clave codificada en base64>
# Algoritmo de cifrado: el modo de consulta determinista solo soporta ECB (aes-128-ecb / aes-256-ecb); CBC/GCM lanzan error al arrancar
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<cadena aleatoria personalizada>
HASHIDS_LENGTH=12

# Pago con Stripe
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# SMS con Twilio
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# Correo SMTP (symfony/mailer; si no se configura SMTP_HOST, el envío se registra como estado dev-stub)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(TLS implícito) / vacío
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

# AWS (opcional — integración con proveedores de nube)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (push FCM)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# Modo mantenimiento
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Bloqueo geográfico (códigos de país, separados por comas, opcional)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Clave de firma de Webhooks
WEBHOOK_SECRET=<cadena aleatoria>

# API de tipos de cambio
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# Copia de seguridad S3 (opcional)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Generar claves

```bash
# Generar todas las claves aleatorias necesarias
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Crear los directorios de almacenamiento

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. Migraciones de base de datos

### 6.1 Crear la tabla de seguimiento de migraciones

```sql
-- Iniciar sesión con el usuario de migraciones
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Ejecutar las migraciones

```bash
cd /home/wwwroot/cloud-php/service

# Ejecutar los archivos de migración en orden (el script ya está preparado en /tmp/run_migrations.php)
php /tmp/run_migrations.php
```

O ejecutarlas una a una manualmente (adecuado para operar con cautela en producción):

```bash
cd /home/wwwroot/cloud-php/service
# Primero ver la lista de archivos de migración
ls -la database/migrations/

# Ejecutar una a una (webman-scout usa el driver database por defecto, no necesita Elasticsearch)
php -r "
require 'vendor/autoload.php';
// Cargar configuración...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Reejecutar migraciones y semilla RBAC

Las migraciones se ejecutan con `php webman migrate` (`service/app/command/MigrateCommand.php`), que aplica en orden de nombre de archivo las migraciones no ejecutadas. La gran mayoría son de creación única de tablas y no requieren reejecución; **la única excepción es la semilla RBAC**:

- `2026_08_17_000001_seed_rbac_permissions.php` es una semilla **de tipo reset**: al ejecutarse, primero hace `delete` de todas las filas de las tres tablas `role_permission` / `permissions` / `roles`, y después reinserta la matriz del archivo. Por tanto, esta migración se puede reejecutar con seguridad, y **la reejecución no genera filas duplicadas** (ids explícitos, no afectados por el autoincremento).
- Al actualizar bases antiguas (que ya ejecutaron los datos de la era de `2026_05_20_000006_create_rbac_permissions.php`), hay que reejecutar la semilla para obtener la matriz de permisos consolidada:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Nota**: la fuente de verdad única de los permisos en runtime es el array estático de `service/common/auth/Rbac.php`, que **no depende de la base de datos** — `RbacMiddleware` lee directamente ese array para decidir permisos; la semilla de la DB solo se usa para la visualización en el panel. Al modificar `Rbac.php` **hay que** actualizar también el archivo de semilla (unión de `permissions()` + matriz por rol de `rolePerms()`), y `service/tests/auth/RbacSeedTest.php` bloquea estáticamente cualquier deriva entre ambos en los tests.
- Revertir la semilla vacía todos los roles y asignaciones de permisos (`down()` también hace delete de las tres tablas); antes de ejecutar `php webman migrate:rollback` en producción, hay que confirmar que no hay operaciones en línea desde el panel.

---

## 7. Configuración de Nginx

### 7.1 Crear el archivo de configuración

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# Servicio API
server {
    listen 80;
    server_name api.yourdomain.com;

    # Forzar HTTPS (descomentar tras configurar el certificado)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # Proxy de las solicitudes API a webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # Lectura directa de archivos estáticos — solo se expone el subdirectorio uploads
    # (backups/apple/firebase, etc., contienen datos sensibles; prohibido hacerlos públicos)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Las comprobaciones de salud no necesitan caché de proxy
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # Limitar el tamaño del cuerpo de solicitud
    client_max_body_size 10M;
}

# Panel de administración
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

# Página de estado — opcional
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 Activar el sitio

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # Comprobar la sintaxis de la configuración
sudo systemctl reload nginx
```

---

## 8. Certificados HTTPS

### 8.1 Usar Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Obtener el certificado
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# Verificar la renovación automática
sudo certbot renew --dry-run
```

### 8.2 Descomentar la redirección HTTPS en Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# Descomentar return 301 https://...
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. Iniciar los servicios

### 9.1 Iniciar webman

```bash
# Service (puerto 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (puerto 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Verificar el arranque

```bash
# Comprobar procesos
ps aux | grep webman

# Comprobar puertos
ss -tlnp | grep -E '8787|8788'

# Comprobación de salud
curl http://127.0.0.1:8787/health
# Debe devolver: {"code":0,"message":"ok"}
```

### 9.3 Configurar el arranque automático con systemd

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

### 9.4 Iniciar kvm-server (servicio de aprovisionamiento en Rust)

kvm-server es un servicio de aprovisionamiento gRPC en Rust independiente (`infrastructure/kvm-server`, workspace e-cat)
que proporciona creación de VM y consulta de estado, y que el lado PHP (KvmClient/RegistryProcess) descubre
mediante registro en etcd + heartbeat de lease. **El driver actual es simulado (simulated); el driver real libvirt
(virsh) es Phase 2** — al desplegar hay que definir explícitamente `KVM_DRIVER=simulated`; si no, el driver virsh
por defecto devolverá NotImplemented.

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

Variables de entorno (`infrastructure/kvm-server/.env`, consultar los valores correspondientes de `service/.env`):

| Variable | Obligatoria | Descripción |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | DSN de MySQL (SQLx, misma base que service) |
| `KVM_REDIS_URL` | ✅ | URL de Redis |
| `KVM_AUTH_TOKEN` | ✅ | Token de autenticación de los llamadores gRPC (debe coincidir con la configuración del lado PHP) |
| `KVM_DRIVER` | ✅ | `simulated` (actualmente el único disponible; `virsh` es Phase 2) |
| `KVM_ADDR` | — | Dirección de la interfaz de gestión HTTP, por defecto `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | Dirección gRPC, por defecto `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | Endpoint etcd; al definirlo se activa el registro/descubrimiento (p. ej. `http://127.0.0.1:2379`) |

---

## 10. Tareas programadas

El sistema incluye un proceso interno de planificación cron (registrado como `App\Cron\CronRunner` en `config/process.php`)
que evalúa cada minuto las expresiones cron de 5 campos de `service/config/cron.php` y ejecuta la tarea correspondiente;
arranca automáticamente con el servicio y **no requiere crontab externo**. Además, el proceso `queue_consumer`
consume los mensajes de las colas de Redis provisioning / notification_*, etc.

```bash
# Ver el estado de los procesos (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

Lista de tareas (`service/config/cron.php`):

| Expresión cron | Tarea |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — sincronización de tipos de cambio |
| `37 2 * * *` | PaymentReconcile — conciliación de pagos |
| `17 4 * * 1` | SupplierSettlement — liquidación de proveedores |
| `23 6 * * *` | ExpirationCheck — comprobación de vencimientos de recursos/dominios |
| `43 7,19 * * *` | SslCertificateCheck — comprobación de certificados SSL (base de datos) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — recopilación de métricas de recursos |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — sondeo de certificados en línea |
| `7 * * * *` | UsageAggregator::aggregate — agregación de uso |
| `41 3 * * *` | BillingEngine::runDaily — facturación diaria |
| `11,41 * * * *` | SuspendCheck — comprobación de suspensión por impago |

---

## 11. Lista de verificación de validación del despliegue

Revisar cada punto y marcar la casilla:

### Infraestructura
- [ ] MySQL accesible: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis accesible: `redis-cli ping` → `PONG`
- [ ] Versión de PHP >= 8.2: `php -v`
- [ ] Dependencias de Composer instaladas correctamente
- [ ] Toolchain de Rust instalado (kvm-server): `rustc --version`
- [ ] kvm-server en ejecución: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` pasa

### Configuración de la aplicación
- [ ] Todos los campos obligatorios del archivo `.env` están configurados
- [ ] Clave JWT generada y escrita en `.env`
- [ ] Clave maestra de cifrado generada y escrita en `.env`
- [ ] Claves de Stripe configuradas (si se necesitan pagos)
- [ ] Permisos correctos de los directorios de almacenamiento (`chmod 755 storage runtime`)

### Base de datos
- [ ] Base de datos y usuarios creados
- [ ] Migraciones ejecutadas correctamente: `SELECT COUNT(*) FROM migrations` → debe ser 19
- [ ] Las tablas principales existen: `SHOW TABLES LIKE 'users'`

### Servicios
- [ ] Sintaxis de la configuración de Nginx correcta: `nginx -t`
- [ ] Procesos webman en ejecución: `ps aux | grep webman`
- [ ] Puertos en escucha correctamente: `ss -tlnp | grep 8787`
- [ ] Comprobación de salud superada: `curl http://127.0.0.1:8787/health`
- [ ] Certificado HTTPS válido: `curl -I https://api.yourdomain.com/health`

### Muestreo de endpoints de la API
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (JSON válido)
- [ ] `POST /api/auth/login` (sin body) → 422 (validación de parámetros)
- [ ] `GET /api/user/profile` (sin token) → 401 (autenticación)
- [ ] Encabezado de versión: `curl -H 'X-Api-Version: v99' /api/products` → 400

### Tareas programadas
- [ ] Crontab configurado
- [ ] El directorio de logs existe y es escribible

### Seguridad
- [ ] Permisos del archivo `.env` en 600
- [ ] MySQL sin puerto remoto abierto (o solo red interna)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS habilitado
- [ ] Modo mantenimiento disponible (se activa descomentando MAINTENANCE_MODE)

---

## 12. Problemas frecuentes

### El arranque de webman falla

```bash
# Iniciar en primer plano para ver el error
php start.php start
# Causas habituales: puerto ocupado, configuración de .env incorrecta, Redis/MySQL inaccesibles
```

### La ejecución de migraciones falla

```bash
# Comprobar la conexión a la base de datos
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<contraseña>');"
# Comprobar si la tabla ya existe
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# webman puede haberse caído
sudo systemctl status cloud-platform
# Reiniciar
sudo systemctl restart cloud-platform
```

### Ubicación de los logs

| Log | Ruta |
|------|------|
| webman en sí | `service/runtime/logs/workerman.log` |
| Logs de la aplicación | `service/runtime/logs/` |
| Logs de Cron | `service/runtime/logs/cron.log` |
| Accesos de Nginx | `/var/log/nginx/access.log` |
| Errores de Nginx | `/var/log/nginx/error.log` |
| Consultas lentas de MySQL | `/var/log/mysql/slow.log` |

### Ajuste de rendimiento

```bash
# Número de workers de webman (config/server.php)
'count' => 4  # se recomienda fijarlo al número de núcleos de CPU

# OPcache de PHP
sudo nano /etc/php/8.3/cli/php.ini
# Confirmar la siguiente configuración:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# Buffer pool de MySQL
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # fijar al 50-70% de la memoria disponible
```
