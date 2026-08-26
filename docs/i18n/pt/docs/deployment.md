# Checklist de Implantação CloudPlatform

## 1. Requisitos de Servidor

| Item | Configuração mínima | Configuração recomendada |
|------|---------|---------|
| SO | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| CPU | 2 núcleos | 4+ núcleos |
| Memória | 4 GB | 8 GB+ |
| Disco | 40 GB SSD | 100 GB SSD |
| PHP | 8.2+ | 8.3 |
| MySQL | 8.0 | 8.0 |
| Redis | 7.x | 7.x |
| Rust | 1.75+ (serviço de provisionamento kvm-server) | 1.75+ |

**Portas que precisam ser abertas**: 80 (HTTP), 443 (HTTPS), 8787 (webman interno, somente rede interna), 8788 (admin, somente rede interna), 50051 (gRPC do kvm-server, somente rede interna), 2379 (etcd, somente rede interna, se o registro do kvm-server estiver habilitado)

---

## 2. Instalação do Ambiente

### 2.1 Dependências Básicas

```bash
# Atualização do sistema
sudo apt update && sudo apt upgrade -y

# Ferramentas básicas
sudo apt install -y curl wget git unzip nginx mysql-server redis-server

# Rust (serviço de provisionamento kvm-server)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain 1.75
source "$HOME/.cargo/env"
rustc --version

# PHP 8.3 + extensões (via ppa:ondrej/php)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.3 php8.3-cli php8.3-fpm \
    php8.3-mysql php8.3-redis php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-gd php8.3-zip php8.3-intl

# Verificação
php -v
# PHP 8.3.x
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

### 2.3 Inicialização Segura do MySQL

```bash
sudo mysql_secure_installation
# Definir senha do root, remover usuários anônimos, proibir login remoto do root, remover banco de teste
```

### 2.4 Redis

```bash
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # deve retornar PONG
```

---

## 3. Configuração do Banco de Dados

### 3.0 Assistente de Instalação com Um Clique (recomendado)

O diretório raiz do projeto fornece um assistente de instalação web, que cria automaticamente as tabelas do banco, a conta do administrador e escreve a configuração:

```bash
cd /home/wwwroot/cloud-php
php install.php --host=0.0.0.0 --port=8888
# Acesse no navegador http://<IP do servidor>:8888
```

Etapas do assistente:
1. Verificação do ambiente (versão do PHP, extensões, permissões de diretório)
2. Configuração do banco de dados (host, porta, nome do banco, usuário, senha; suporta criação automática do banco)
3. Configuração da conta do administrador (nome de usuário, senha, email)
4. Execução da instalação com um clique (46 tabelas + papel de superadministrador + conta do administrador + geração automática do arquivo .env)

Após a instalação, complete manualmente as configurações opcionais em `service/.env` (SMTP, Stripe, SMS etc.).

### 3.1 Criação Manual do Banco e do Usuário

```sql
-- Login como root
sudo mysql

CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Usuário do aplicativo (leitura/escrita)
CREATE USER 'app_user'@'localhost' IDENTIFIED BY '<senha forte>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cloud_platform.* TO 'app_user'@'localhost';
GRANT SELECT, INSERT ON cloud_platform_audit.* TO 'app_user'@'localhost';

-- Usuário de migração (DDL)
CREATE USER 'migrate_user'@'localhost' IDENTIFIED BY '<senha forte>';
GRANT ALL ON cloud_platform.* TO 'migrate_user'@'localhost';
GRANT ALL ON cloud_platform_audit.* TO 'migrate_user'@'localhost';

-- Usuário somente leitura (relatórios)
CREATE USER 'read_user'@'localhost' IDENTIFIED BY '<senha forte>';
GRANT SELECT ON cloud_platform.* TO 'read_user'@'localhost';
GRANT SELECT ON cloud_platform_audit.* TO 'read_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Verificar a Conexão

```bash
mysql -u app_user -p -h localhost cloud_platform -e "SELECT 1;"
```

---

## 4. Implantação do Código

### 4.1 Clonar o Código

```bash
sudo mkdir -p /home/wwwroot
cd /home/wwwroot
sudo git clone <endereço do repositório> cloud-php
sudo chown -R www-data:www-data /home/wwwroot/cloud-php
```

### 4.2 Instalar Dependências

```bash
# Dependências do Service
cd /home/wwwroot/cloud-php/service
composer install --no-dev --optimize-autoloader

# Dependências do Admin
cd /home/wwwroot/cloud-php/admin
composer install --no-dev --optimize-autoloader
```

---

## 5. Configuração do Ambiente

### 5.1 Arquivo .env do Service

```bash
cp /home/wwwroot/cloud-php/service/.env.example /home/wwwroot/cloud-php/service/.env
```

Edite o `.env` e preencha os valores reais:

```ini
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
APP_TIMEZONE=Asia/Shanghai

# Banco de dados
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=<senha do banco>
DB_AUDIT_DATABASE=cloud_platform_audit

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Chave JWT (gerar: openssl rand -base64 32; o serviço recusa iniciar sem ela)
JWT_SECRET_KEY=<string aleatória base64>

# Chave mestra de criptografia de transporte (gerar: openssl rand -base64 32; 32 bytes codificados em base64, mesmo formato em admin e service)
ENCRYPTION_MASTER_KEY=<chave de 32 bytes codificada em base64>

# Chave de criptografia de campos (codificada em base64; o config/encryptable.php faz base64_decode antes de usar; passar texto puro lança MissingEncryptionKeyException)
ENCRYPTION_KEY=<chave codificada em base64>
# Algoritmo de criptografia: o modo de consulta determinística suporta apenas ECB (aes-128-ecb / aes-256-ecb); CBC/GCM lança erro na inicialização
ENCRYPTION_CIPHER=aes-128-ecb

# Hashids
HASHIDS_SALT=<string aleatória personalizada>
HASHIDS_LENGTH=12

# Pagamento Stripe
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# SMS Twilio
TWILIO_ACCOUNT_SID=ACxxx
TWILIO_AUTH_TOKEN=xxx
TWILIO_PHONE_NUMBER=+1234567890

# Email SMTP (symfony/mailer; sem SMTP_HOST configurado, o envio é registrado como status dev-stub)
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=xxx
SMTP_ENCRYPTION=tls            # tls(STARTTLS) / ssl(TLS implícito) / vazio
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

# AWS (opcional — integração com provedores de nuvem)
AWS_ACCESS_KEY_ID=AKIAxxx
AWS_SECRET_ACCESS_KEY=xxx

# Firebase (push FCM)
FIREBASE_CREDENTIALS_PATH=/home/wwwroot/cloud-php/service/storage/firebase/credentials.json

# Modo de manutenção
# MAINTENANCE_MODE=true
# MAINTENANCE_ALLOWED_IPS=1.2.3.4,5.6.7.8

# Geo-Blocking (códigos de país, separados por vírgula, opcional)
# GEO_BLOCKED_COUNTRIES=KP,IR,SY,CU
# GEOIP_DB_PATH=/home/wwwroot/cloud-php/service/storage/geoip/GeoLite2-Country.mmdb

# Chave de assinatura de Webhook
WEBHOOK_SECRET=<string aleatória>

# API de câmbio
EXCHANGE_RATE_API_URL=https://api.exchangerate-api.com/v4/latest/USD

# Backup S3 (opcional)
BACKUP_S3_BUCKET=cloudplatform-backups
BACKUP_S3_REGION=us-east-1
```

### 5.2 Gerar Chaves

```bash
# Gera todas as chaves aleatórias necessárias
echo "JWT Secret Key: $(openssl rand -base64 32)"
echo "Encryption Master Key: $(php -r 'echo bin2hex(random_bytes(32));')"
echo "Webhook Secret: $(php -r 'echo bin2hex(random_bytes(16));')"
```

### 5.3 Criar Diretórios de Armazenamento

```bash
mkdir -p /home/wwwroot/cloud-php/service/storage/{backups,geoip,uploads,apple,firebase}
mkdir -p /home/wwwroot/cloud-php/service/runtime/{logs,views}
chmod -R 755 /home/wwwroot/cloud-php/service/storage
chmod -R 755 /home/wwwroot/cloud-php/service/runtime
```

---

## 6. Migrações de Banco de Dados

### 6.1 Criar a Tabela de Controle de Migrações

```sql
-- Login como usuário de migração
mysql -u migrate_user -p cloud_platform

CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

### 6.2 Executar Migrações

```bash
cd /home/wwwroot/cloud-php/service

# Execute os arquivos de migração em ordem (o script já está preparado em /tmp/run_migrations.php)
php /tmp/run_migrations.php
```

Ou execute manualmente, um por um (indicado para operação cuidadosa em produção):

```bash
cd /home/wwwroot/cloud-php/service
# Primeiro veja a lista de arquivos de migração
ls -la database/migrations/

# Execute um por um (o webman-scout usa o driver database por padrão, sem Elasticsearch)
php -r "
require 'vendor/autoload.php';
// Carrega a configuração...
require 'database/migrations/0001_create_users_tables.php';
echo '0001 done\n';
"
```

### 6.3 Re-execução de Migrações e Seed RBAC

As migrações são executadas por `php webman migrate` (`service/app/command/MigrateCommand.php`), aplicando em ordem de nome de arquivo as migrações ainda não executadas. A grande maioria é de criação de tabela única, sem necessidade de re-execução; **a única exceção é o seed RBAC**:

- `2026_08_17_000001_seed_rbac_permissions.php` é um seed do tipo **reset**: ao executar, primeiro faz `delete` de todas as linhas das tabelas `role_permission` / `permissions` / `roles`, depois reinsere de acordo com a matriz do arquivo. Portanto, essa migração pode ser re-executada com segurança, e **re-executar não cria linhas duplicadas** (ids explícitos, sem influência de autoincremento).
- Ao atualizar bancos antigos (que rodaram os dados da época do `2026_05_20_000006_create_rbac_permissions.php`), é necessário re-executar o seed para obter a matriz de permissões consolidada:
  ```bash
  cd /home/wwwroot/cloud-php/service
  php webman migrate
  ```
- **Atenção**: a única fonte da verdade das permissões em tempo de execução é o array estático `service/common/auth/Rbac.php` — **não depende do banco** — o `RbacMiddleware` lê esse array diretamente para decidir permissões; o seed do banco serve apenas para exibição no painel administrativo. Ao modificar o `Rbac.php`, é **obrigatório** sincronizar o arquivo de seed (união de `permissions()` + matriz por papel de `rolePerms()`); o `service/tests/auth/RbacSeedTest.php` bloqueia estaticamente a divergência entre os dois nos testes.
- Reverter o seed limpa todos os papéis e atribuições de permissão (`down()` também faz delete das três tabelas); antes de executar `php webman migrate:rollback` em produção, confirme que não há operações em andamento no painel administrativo.

---

## 7. Configuração do Nginx

### 7.1 Criar o Arquivo de Configuração

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
```

```nginx
# Serviço da API
server {
    listen 80;
    server_name api.yourdomain.com;

    # Forçar HTTPS (descomente após configurar o certificado)
    # return 301 https://\$server_name\$request_uri;

    root /home/wwwroot/cloud-php/service/public;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # Proxy das requisições da API para o webman
    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 60s;
    }

    # Leitura direta de arquivos estáticos — expõe apenas o subdiretório uploads (backups/apple/firebase etc. contêm dados sensíveis, proibido expor publicamente)
    location ^~ /storage/uploads/ {
        alias /home/wwwroot/cloud-php/service/storage/uploads/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # Health check sem cache de proxy
    location /health {
        proxy_pass http://127.0.0.1:8787/health;
        proxy_cache off;
    }

    # Limite do tamanho do corpo da requisição
    client_max_body_size 10M;
}

# Painel administrativo
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

# Página de status — opcional
# server {
#     listen 80;
#     server_name status.yourdomain.com;
#     location / {
#         proxy_pass http://127.0.0.1:8787/api/status;
#     }
# }
```

### 7.2 Ativar o Site

```bash
sudo ln -sf /etc/nginx/sites-available/cloud-platform /etc/nginx/sites-enabled/
sudo nginx -t           # verifica a sintaxe da configuração
sudo systemctl reload nginx
```

---

## 8. Certificado HTTPS

### 8.1 Usar Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Obter o certificado
sudo certbot --nginx -d api.yourdomain.com -d admin.yourdomain.com

# Verificar a renovação automática
sudo certbot renew --dry-run
```

### 8.2 Descomentar o Redirecionamento HTTPS no Nginx

```bash
sudo nano /etc/nginx/sites-available/cloud-platform
# Descomente o return 301 https://...
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. Iniciar os Serviços

### 9.1 Iniciar o webman

```bash
# Service (porta 8787)
cd /home/wwwroot/cloud-php/service
php start.php start -d

# Admin (porta 8788)
cd /home/wwwroot/cloud-php/admin
php start.php start -d
```

### 9.2 Verificar a Inicialização

```bash
# Verificar processos
ps aux | grep webman

# Verificar portas
ss -tlnp | grep -E '8787|8788'

# Health check
curl http://127.0.0.1:8787/health
# Deve retornar: {"code":0,"message":"ok"}
```

### 9.3 Configurar Autoinício com systemd

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

### 9.4 Iniciar o kvm-server (serviço de provisionamento em Rust)

O kvm-server é um serviço gRPC de provisionamento independente em Rust (`infrastructure/kvm-server`, workspace e-cat),
que fornece criação/consulta de status de VMs e registra-se via etcd com heartbeat de lease para descoberta pelo
lado PHP (KvmClient/RegistryProcess). **O driver atual é o simulado (simulated); o driver real libvirt (virsh) é a Fase 2** —
na implantação, é obrigatório definir explicitamente `KVM_DRIVER=simulated`; caso contrário, o driver virsh padrão
retornará NotImplemented.

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

Variáveis de ambiente (`infrastructure/kvm-server/.env`, consulte os valores correspondentes em `service/.env`):

| Variável | Obrigatória | Descrição |
|------|:---:|------|
| `KVM_DB_URL` | ✅ | DSN MySQL (SQLx, mesmo banco do service) |
| `KVM_REDIS_URL` | ✅ | URL do Redis |
| `KVM_AUTH_TOKEN` | ✅ | Token de autenticação para chamadas gRPC (consistente com a configuração do lado PHP) |
| `KVM_DRIVER` | ✅ | `simulated` (único disponível atualmente; `virsh` é a Fase 2) |
| `KVM_ADDR` | — | Endereço da interface de gerenciamento HTTP, padrão `0.0.0.0:8000` |
| `KVM_GRPC_ADDR` | — | Endereço gRPC, padrão `0.0.0.0:50051` |
| `KVM_ETCD_URL` | — | Endpoint etcd; quando definido, habilita registro/descoberta (ex.: `http://127.0.0.1:2379`) |

---

## 10. Tarefas Agendadas

O sistema inclui um processo interno de agendamento cron (`config/process.php` registra `App\Cron\CronRunner`), que avalia
a cada minuto as expressões cron de 5 campos em `service/config/cron.php` e executa a tarefa correspondente, rodando
automaticamente junto com o serviço — **sem necessidade de crontab externo**. Também há o processo `queue_consumer`
consumindo mensagens das filas Redis provisioning / notification_* etc.

```bash
# Ver o status dos processos (http / websocket / metrics / queue_consumer / cron)
php start.php status
```

Lista de tarefas (`service/config/cron.php`):

| expressão cron | Tarefa |
|-------------|------|
| `13 */4 * * *` | ExchangeRateSync — sincronização de câmbio |
| `37 2 * * *` | PaymentReconcile — conciliação de pagamentos |
| `17 4 * * 1` | SupplierSettlement — liquidação de fornecedores |
| `23 6 * * *` | ExpirationCheck — verificação de expiração de recursos/domínios |
| `43 7,19 * * *` | SslCertificateCheck — verificação de certificados SSL (banco de dados) |
| `*/5 * * * *` | ResourceMonitor::collectAllMetrics — coleta de métricas de recursos |
| `*/30 * * * *` | ResourceMonitor::checkSslCertificates — sondagem online de certificados |
| `7 * * * *` | UsageAggregator::aggregate — agregação de uso |
| `41 3 * * *` | BillingEngine::runDaily — cobrança diária |
| `11,41 * * * *` | SuspendCheck — verificação de suspensão por inadimplência |

---

## 11. Checklist de Verificação da Implantação

Verifique item por item e confirme com um visto:

### Infraestrutura
- [ ] MySQL conectável: `mysql -u app_user -p -e "SELECT 1"`
- [ ] Redis conectável: `redis-cli ping` → `PONG`
- [ ] Versão do PHP >= 8.2: `php -v`
- [ ] Dependências do Composer instaladas com sucesso
- [ ] Toolchain Rust instalado (kvm-server): `rustc --version`
- [ ] kvm-server em execução: `ss -tlnp | grep 50051`; `curl http://127.0.0.1:8000/health` OK

### Configuração do Aplicativo
- [ ] Todos os campos obrigatórios do `.env` configurados
- [ ] Chave JWT gerada e gravada no `.env`
- [ ] Chave mestra de criptografia gerada e gravada no `.env`
- [ ] Chaves do Stripe configuradas (se precisar de pagamento)
- [ ] Permissões corretas dos diretórios de armazenamento (`chmod 755 storage runtime`)

### Banco de Dados
- [ ] Banco e usuários criados
- [ ] Todas as migrações executadas com sucesso: `SELECT COUNT(*) FROM migrations` → deve ser 19
- [ ] Tabelas centrais existem: `SHOW TABLES LIKE 'users'`

### Serviços
- [ ] Sintaxe do Nginx correta: `nginx -t`
- [ ] Processos webman em execução: `ps aux | grep webman`
- [ ] Portas ouvindo normalmente: `ss -tlnp | grep 8787`
- [ ] Health check OK: `curl http://127.0.0.1:8787/health`
- [ ] Certificado HTTPS válido: `curl -I https://api.yourdomain.com/health`

### Amostragem de Endpoints da API
- [ ] `GET /api/status` → 200
- [ ] `GET /api/products` → 200 (JSON válido)
- [ ] `POST /api/auth/login` (sem body) → 422 (validação de parâmetros)
- [ ] `GET /api/user/profile` (sem token) → 401 (autenticação)
- [ ] Cabeçalho de versão: `curl -H 'X-Api-Version: v99' /api/products` → 400

### Tarefas Agendadas
- [ ] crontab configurado
- [ ] Diretório de logs existe e é gravável

### Segurança
- [ ] Permissão do arquivo `.env` em 600
- [ ] MySQL sem porta remota aberta (ou apenas rede interna)
- [ ] `APP_DEBUG=false`
- [ ] HTTPS habilitado
- [ ] Modo de manutenção disponível (descomentar MAINTENANCE_MODE para ativar)

---

## 12. Perguntas Frequentes

### Falha ao iniciar o webman

```bash
# Inicie em primeiro plano para ver o erro
php start.php start
# Causas comuns: porta ocupada, configuração errada do .env, Redis/MySQL inacessíveis
```

### Falha na execução de migrações

```bash
# Verificar a conexão com o banco
php -r "new PDO('mysql:host=localhost;dbname=cloud_platform','app_user','<senha>');"
# Verificar se as tabelas já existem
mysql -u app_user -p cloud_platform -e "SHOW TABLES"
```

### 502 Bad Gateway

```bash
# O webman pode ter caído
sudo systemctl status cloud-platform
# Reiniciar
sudo systemctl restart cloud-platform
```

### Localização dos Logs

| Log | Caminho |
|------|------|
| webman em si | `service/runtime/logs/workerman.log` |
| Logs do aplicativo | `service/runtime/logs/` |
| Log do Cron | `service/runtime/logs/cron.log` |
| Acesso Nginx | `/var/log/nginx/access.log` |
| Erro Nginx | `/var/log/nginx/error.log` |
| Slow query MySQL | `/var/log/mysql/slow.log` |

### Ajuste de Desempenho

```bash
# Número de workers do webman (config/server.php)
'count' => 4  # recomendado definir como o número de núcleos da CPU

# OPcache do PHP
sudo nano /etc/php/8.3/cli/php.ini
# Confirme as seguintes configurações:
# opcache.enable=1
# opcache.memory_consumption=128
# opcache.max_accelerated_files=10000

# Buffer pool do MySQL
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
# innodb_buffer_pool_size = 512M  # defina como 50-70% da memória disponível
```
