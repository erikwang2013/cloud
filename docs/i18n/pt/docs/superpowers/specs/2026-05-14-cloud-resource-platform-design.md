# Plataforma Global de Negociação de Recursos em Nuvem — Design de Sistema

## Visão Geral do Projeto

Plataforma global de negociação de recursos em nuvem, com modelo híbrido de operação própria + fornecedores terceirizados. Os usuários podem comprar servidores, IPs, discos em nuvem, domínios e outros produtos de nuvem. Provisionamento de recursos totalmente automático, múltiplos canais de pagamento, múltiplas moedas e múltiplos idiomas.

### Stack Tecnológico

| Camada | Tecnologia |
|------|------|
| App do usuário | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Painel administrativo | webman-admin |
| Servidor | PHP webman (monólito modular) |
| Banco de dados | MySQL 8.0 (mestre-escravo) |
| Cache/Fila | Redis (cache + Session + fila) |
| Armazenamento | S3/OSS + CDN |
| Monitoramento | Prometheus + Grafana + Sentry + ELK/Loki |

---

## I. Divisão de Módulos (12 módulos principais)

| Módulo | Responsabilidade |
|------|------|
| **User** | Registro/login (OAuth + e-mail + celular), verificação de identidade KYC, nível de membro, conta de saldo |
| **Product** | Definição de produto (SKU), precificação por região, gestão de estoque, categorias, busca, avaliações |
| **Order** | Carrinho de compras, pedido, ciclo de vida do pedido (aguardando pagamento → pago → provisionando → concluído → reembolso), renovação/upgrade |
| **Payment** | Roteamento de canais de pagamento, cotação em múltiplas moedas, câmbio, reembolso, conciliação |
| **Provisioning** | Integração com APIs de provedores de nuvem, criação/renovação/destruição automática de recursos |
| **Domain** | Consulta, registro, transferência, renovação de domínios, gestão de DNS |
| **Supplier** | Onboarding de fornecedores, aprovação, publicação de produtos, liquidação, comissão |
| **Monitor** | Verificação de disponibilidade de recursos, coleta de uso, regras de alerta |
| **Ticket** | Abertura de chamados, atribuição, acompanhamento de SLA |
| **Notification** | E-mail/SMS/Push no App/Mensagem no site, múltiplos modelos e idiomas |
| **Report** | Relatórios de receita, relatórios de liquidação de fornecedores, tendências de vendas |
| **I18n** | Termos em múltiplos idiomas, câmbio de múltiplas moedas, múltiplos fusos horários |

---

## II. Modelos de Dados Principais

### Centro de Usuários (User)

- **users** — tabela principal de usuários (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — perfil do usuário (user_id, avatar, nickname, country)
- **user_kyc** — verificação de identidade (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — conta de saldo (user_id, currency, balance, frozen_balance)
- **user_balance_log** — registro de variação de saldo (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — endereços do usuário (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Centro de Produtos (Product)

- **product_categories** — categorias de produto (id, parent_id, name, icon, sort)
- **products** — tabela principal de produtos (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — precificação por região (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — imagens do produto (product_id, url, sort)
- **product_attributes** — atributos personalizados (product_id, key, value)
- **product_reviews** — avaliações de produto (user_id, product_id, order_id, rating, content)
- **regions** — tabela de regiões (id, name, continent, country, city, data_center, status)

### Centro de Pedidos (Order)

- **carts** — carrinho de compras (user_id, sku_id, region_id, quantity, cycle)
- **orders** — tabela principal de pedidos (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — itens do pedido (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — linha do tempo do pedido (order_id, status, operator, remark, created_at)
- **order_invoices** — faturas (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — solicitações de reembolso (order_id, user_id, amount, reason, status, handled_by)

### Centro de Pagamentos (Payment)

- **payment_channels** — configuração de canais de pagamento (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — registros de transação (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — tabela de conciliação (date, channel_id, channel_total, system_total, diff, status)

### Provisionamento de Recursos (Provisioning)

- **resources** — tabela principal de recursos (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — detalhes do servidor (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — detalhes do IP (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — detalhes do disco em nuvem (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — detalhes do domínio (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — tarefas de provisionamento (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — configuração de APIs de provedores de nuvem (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Gestão de Máquinas Físicas (Host & IP Pool)

Os servidores físicos de operação própria usam Proxmox VE (edição comunitária, gratuita) para gerenciar máquinas virtuais, criando/gerenciando VMs, alocando IPs e montando discos via REST API.

- **host_machines** — hosts (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — pools de IP (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — registros de alocação de IP (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — detalhes do disco da VM (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — registros de expansão de disco (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Fornecedores (Supplier)

- **suppliers** — tabela principal de fornecedores (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — associação de produtos do fornecedor (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — boletos de liquidação (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — registros de saque (supplier_id, amount, method, account_info, status)

### Serviço de Domínios (Domain)

- **domain_tlds** — TLDs suportados (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — transferência de domínios (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — zonas DNS (domain_name, user_id, zone_id)
- **dns_records** — registros DNS (zone_id, type, name, value, ttl, priority)

### Chamados e Notificações (Ticket & Notification)

- **tickets** — chamados (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — mensagens do chamado (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — registros de notificação (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — modelos de notificação (code, name, channels, title_template, body_template, variables)

---

## III. Padrões de Design de API

### Gestão de Versões

A versão da API é especificada pelo cabeçalho HTTP `X-Api-Version`, não na URL. O servidor injeta o cabeçalho de versão na rota interna por meio de middleware.

```
Requisição:  GET /api/auth/login
Cabeçalho: X-Api-Version: v1

Rota interna → /api/auth/login → Controlador
Cabeçalho de resposta: X-Api-Version: v1
```

**Versões suportadas**: `v1` (padrão, usada automaticamente quando o cabeçalho está ausente)

**Mecanismo de controle de versão**: `VersionMiddleware` valida o cabeçalho `X-Api-Version` em todos os caminhos `/api/*` e `/admin/api/*`, usando `v1` como padrão quando ausente, e retornando `400` para versões não suportadas. O número de versão não é mais incluído no caminho da URL.

**Etapas para adicionar uma versão**:
1. Adicionar o número de versão ao array `VersionMiddleware::SUPPORTED`
2. Registrar o novo grupo de rotas da versão em `route.php`
3. O controlador obtém a versão via `$request->properties['api_version']` para tratamento diferenciado

### Rotas RESTful

```
Prefixo unificado: /api
Painel administrativo: /admin/api
```

**Matriz de grupos de rotas e middlewares:**

| Grupo de rotas | Middlewares | Exemplos de endpoints |
|--------|--------|---------|
| Público (sem prefixo) | Cadeia de middlewares global | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Global + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (usuário) | Global + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (sensível) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Global + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (sensível) | Global + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Formato de Resposta Unificado

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### Esquema de Autenticação

| Cliente | Método |
|----|------|
| Usuário | JWT (access_token 2h + refresh_token 30d) + verificação em duas etapas TOTP + códigos de recuperação |
| Administração | JWT (access_token 2h + refresh_token 7d) |
| API de fornecedor | API Key (prefixo sk_, hash SHA256 armazenado, exibida apenas uma vez na criação) |
| Callback de provedor de nuvem | Verificação de assinatura (HMAC-SHA256) |

**Funcionalidades de autenticação implementadas**:
- Registro por e-mail + link de verificação de e-mail
- Registro por número de celular + código SMS Twilio (cooldown de 60s + limite de IP 5 vezes/hora)
- Login Google OAuth / Apple Sign In
- Esqueci a senha (código de verificação por e-mail + TTL Redis de 10min)
- Verificação em duas etapas TOTP (configuração por QR code, códigos de recuperação como reserva)
- Gestão de sessões ativas (visualizar/revogar dispositivos logados, incluindo informações de client_platform)
- Encerramento de conta GDPR (confirmação de senha + exclusão suave + revogação de todos os tokens)
- Alerta de login anômalo (notificação por e-mail para novos IPs de login)
- Bloqueio de login (5 falhas bloqueiam por 15 minutos)

**Fluxo de autenticação do usuário:**

```
Fluxo de registro                     Fluxo de login
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(posição do clique)}    ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [Varredura WAF]                    → [Varredura WAF]
   → [Limite: 3 req/min]                → [Limite: 5 req/min]
   → [Senha bcrypt(cost=12)]            → [Hash::check()]
   → [Impressão digital do dispositivo: → [Impressão digital do dispositivo:
      sha256(UA+IP)]                       sha256(UA+IP)]
   → [Registro de client_platform]      → [Registro de client_platform]
   → User::create()                    → [5 falhas → bloqueio 15min]
   → RefreshToken::create()            → [Detecção de novo IP → alerta por e-mail]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (e-mail de verificação)           → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Autorização do Google → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. Verificar token do Google
                                      5. Criar ou localizar usuário
                                      6. Emitir token (inclui client_platform)
                                      7. AuditLogger::record('user_oauth_login')

Verificação TOTP em duas etapas        Gestão de sessões
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← Sucesso
   ou → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → confirmação de senha + exclusão
Mecanismo de bloqueio de login            suave + revogação de todos os tokens
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### Solução de Múltiplos Idiomas

- Cabeçalho de requisição: Accept-Language: zh-CN / en-US / ja-JP
- Colunas JSON armazenam textos em vários idiomas: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- Arquivos i18n gerenciam textos estáticos, um conjunto para frontend e outro para backend

---

## IV. Sistema de Defesa de Segurança

### Modelo de Defesa em Camadas

```
┌─────────────────────────────────────────────────────┐
│ Camada 1: Defesa de borda de rede                    │
│   Mitigação DDoS / WAF / Lista de IPs / Geo-Blocking │
├─────────────────────────────────────────────────────┤
│ Camada 2: Segurança de transporte e aplicação        │
│   HTTPS+TLS1.3 / CSP / CORS / autenticação JWT /     │
│   Limitação de taxa                                   │
├─────────────────────────────────────────────────────┤
│ Camada 3: Segurança de dados e armazenamento         │
│   Criptografia de armazenamento / Mascaramento /     │
│   Logs de auditoria / Backup                          │
├─────────────────────────────────────────────────────┤
│ Camada 4: Virtualização e isolamento de recursos     │
│   Reforço de segurança Proxmox / Isolamento entre    │
│   VMs / Isolamento de rede                            │
├─────────────────────────────────────────────────────┤
│ Camada 5: Operações e controle de risco              │
│   Auditoria de operações / Detecção de anomalias /   │
│   Alertas / Resposta a incidentes                    │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 Defesa de Borda de Rede

#### Proteção DDoS

```
Requisição do usuário → CDN (Cloudflare / Aliyun CDN)
              │
              ├── Desafio JS / CAPTCHA (tráfego suspeito)
              ├── Limite de taxa (requisições por segundo por IP)
              ├── Bloqueio geográfico (bloquear países/regiões específicos)
              │
              ▼
          Origem (Nginx + webman)
```

| Camada | Medida | Descrição |
|------|------|------|
| Camada CDN | Mitigação automática de DDoS | O plano gratuito do Cloudflare já oferece proteção L3/L4 |
| Camada CDN | Bot Management | Identifica e bloqueia crawlers maliciosos/scripts de fraude |
| Camada Nginx | limit_req_zone | 10 req/s por IP, retorna 429 acima do limite |
| Camada Nginx | limit_conn | Máximo de 20 conexões concorrentes por IP |
| Camada webman | Middleware de limite de taxa com token bucket | Limitação precisa por usuário/endpoint |

#### Regras WAF (middleware webman)

O middleware WAF varre requisições usando 8 grupos de regras de regex, com configuração em `config/security.php` que pode ser atualizada a quente sem reiniciar. A varredura cobre o corpo JSON da requisição, caminho da URL + query string, User-Agent e corpo bruto da requisição (proteção contra escape de codificação JSON).

**8 categorias de regras de detecção (45+ regras):**

| Categoria | Cobertura |
|------|---------|
| Injeção SQL | Aspas simples/símbolos de comentário, palavras-chave SQL, codificação hexadecimal, variações de union query, condições sempre verdadeiras (`' OR '1'='1`), injeção cega por tempo (`sleep`/`benchmark`), queries empilhadas, bypass por comentários multilinha |
| XSS | Tags HTML (incluindo variações codificadas), tag Script e variantes, 13 handlers de eventos JS, objetos globais/funções perigosas do JS, pseudo-protocolo `javascript:`, codificação de entidades HTML, injeção de Data URI, atributos de evento inline |
| Injeção de comandos | Comando após pipe (`\| cat`), comando após ponto e vírgula (`; whoami`), substituição de comando `$(cmd)` e com backtick, palavras-chave de comandos isolados |
| Inclusão de arquivos | Path traversal (múltiplas codificações), pseudo-protocolos PHP (`php://`/`data://`/`phar://`), sondagem de caminhos absolutos (`/etc/`/`C:\`), injeção de Null byte |
| Injeção em cabeçalhos HTTP | Injeção de quebra de linha CRLF (`%0d%0a`/`\r\n`), injeção nos cabeçalhos Host/Cookie/Set-Cookie |
| **SSRF** | Endereços IPv4 internos (127.x/10.x/172.16-31.x/192.168.x), aliases de localhost, endpoints de metadata de nuvem (169.254.169.254), protocolo file:// |
| **Injeção NoSQL** | Operadores MongoDB ($where/$gt/$regex/$or etc.), injeção JS em $where, comandos perigosos do Redis (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Redirecionamento aberto** | Detecção de URLs externas nos parâmetros redirect_uri/return_url/next/callback etc., bypass por dupla codificação |

**Proteção no nível de requisição:**

| Item de proteção | Medida |
|--------|------|
| Limite de tamanho do corpo da requisição | Máximo de 10MB (retorna 413 acima disso) |
| Limite de comprimento da URL | Máximo de 2KB (retorna 414 acima disso, previne ReDoS) |
| Lista de permissões de Content-Type | Somente application/json, multipart/form-data, application/x-www-form-urlencoded |

**Fluxo de detecção WAF:**

```
Requisição recebida
  │
  ▼
1. Obter textos a serem varridos
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # corpo da requisição
   │     └── false → fallback serialize()
   ├── mb_substr(path + queryString, 0, 2048)                # URL (truncamento anti-ReDoS)
   ├── cabeçalho User-Agent                                    # UA
   └── file_get_contents('php://input')                      # corpo bruto (anti-escape JSON)
  │
  ▼
2. Carregar regras (de config/security.php)
   ├── security.waf.sqli_patterns               (9 regras)
   ├── security.waf.xss_patterns                (8 regras)
   ├── security.waf.cmd_injection_patterns      (5 regras)
   ├── security.waf.file_inclusion_patterns     (4 regras)
   ├── security.waf.header_injection_patterns   (2 regras)
   ├── security.waf.ssrf_patterns               (6 regras)
   ├── security.waf.nosql_injection_patterns    (3 regras)
   └── security.waf.open_redirect_patterns      (2 regras)
   → array_merge() + array_unique()
  │
  ▼
3. Correspondência regra a regra
   foreach patterns as pattern:
     match($pattern, $input) ───→ correspondência → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ correspondência → retornar 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ correspondência →
     match($pattern, $raw)   ───→ correspondência →
  │
  ▼
4. Verificação estrita do match()
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → correspondência ✓
   ├── $result === 0    → sem correspondência (liberado com segurança)
   └── $result === false → erro de padrão → error_log() → tratado como sem correspondência
  │
  ▼
5. Nenhuma correspondência → $next($request) passa para o próximo middleware
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### Lista de Permissões e Bloqueios de IP

```
Lista de bloqueio:
- Base de dados de IPs maliciosos conhecidos (sincronização periódica com AbuseIPDB)
- IPs que disparam regras WAF com frequência (adicionados automaticamente, TTL Redis de 24h)
- IPs com tentativas de força bruta de login (5 falhas → bloqueio de 30min)

Lista de permissões:
- IPs dos hosts Proxmox
- Faixas de IP de callback de provedores de nuvem
- Faixas de IP de webhook de gateways de pagamento
- IPs da rede de escritório dos administradores (opcional)
```

#### Geo-Blocking

```php
// Biblioteca GeoIP2 (MaxMind)
$country = geoip($request->getRealIp());

// Lista de bloqueio configurável
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 Segurança de Transporte e Aplicação

#### Cadeia de Execução de Middlewares Globais

Todas as requisições HTTP passam pelos middlewares na ordem a seguir, cada um testável de forma independente:

```
Requisição → VersionMiddleware        # validação X-Api-Version (ausente → padrão v1, inválida → 400)
          → CorsMiddleware            # cabeçalhos de resposta CORS
          → ClientPlatformMiddleware  # reconhecimento X-Client-Platform (8 plataformas), injeta em $request->properties
          → WafMiddleware             # varredura de segurança com 8 categorias, 45+ regras (SQLi/XSS/injeção de comando/inclusão de arquivo/injeção de cabeçalho/SSRF/NoSQL/redirecionamento aberto)
          → LocaleMiddleware          # parse de Accept-Language, define a localidade
          → HashidRequestMiddleware   # decodifica hashids dos parâmetros de requisição → IDs reais
          → MaintenanceMiddleware     # modo de manutenção (lista de permissões de IP)
          ↓
  [Middlewares de rota — anexados por grupo de rotas]
          → EncryptionMiddleware      # criptografia AES-256-GCM do corpo de requisição/resposta
          → Captcha                   # validação de CAPTCHA de clique (antes de login/registro)
          → AuthMiddleware            # validação do Bearer Token JWT + injeção de papel
          → AdminRoleMiddleware       # verificação de permissão RBAC do administrador
          → ConfirmationMiddleware    # confirmação de senha em operações sensíveis (5 falhas bloqueiam 15min)
          ↓
          Controlador
```

#### Responsabilidades de Cada Middleware

| Middleware | Registro | Responsabilidade |
|--------|---------|------|
| `VersionMiddleware` | Global | Valida o cabeçalho `X-Api-Version`, padrão `v1` quando ausente, retorna `400` para versões não suportadas |
| `CorsMiddleware` | Global | Trata preflight OPTIONS, reflete o Origin em `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Global | Valida o cabeçalho `X-Client-Platform`, identifica a plataforma do sistema operacional do cliente (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), injeta em `$request->properties['client_platform']` |
| `WafMiddleware` | Global (service) + instância admin | 8 categorias, 45+ regras + limite de tamanho de requisição + validação de Content-Type, registra log de auditoria ao bloquear |
| `LocaleMiddleware` | Global | Faz parse do cabeçalho `Accept-Language`, define a localidade de múltiplos idiomas |
| `HashidRequestMiddleware` | Global | Decodifica automaticamente strings hashid da requisição para IDs inteiros reais |
| `MaintenanceMiddleware` | Global | Verifica a variável de ambiente `MAINTENANCE_MODE`, libera IPs da lista de permissões |
| `EncryptionMiddleware` | Grupo de rotas (/api/auth, /api, /admin/api) | Criptografia AES-256-GCM do corpo de requisição/resposta, acionada pelo cabeçalho `X-Encrypted: 1` |
| `AuthMiddleware` | Grupo de rotas (/api, /admin/api) | Validação do access_token JWT HS256, injeta `$request->userId` e `$request->userRole` |
| `AdminRoleMiddleware` | Grupo de rotas (/admin/api) | Verificação de permissão RBAC do administrador |
| `ConfirmationMiddleware` | Grupo de rotas (operações sensíveis) | Confirmação de senha, contador de falhas no Redis, 5 falhas bloqueiam por 15 minutos |

#### Detalhes do Middleware ClientPlatform

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**Fluxo de dados**: injeção pelo middleware → `AuditLogger` registra automaticamente → `AuthService::issueTokens()` grava em `refresh_tokens` → `GET /api/user/sessions` retorna as informações da plataforma

#### Imposição de HTTPS

```nginx
# Nginx 配置
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### Reforço de Segurança JWT

```
- access_token com validade de 2h, refresh_token com validade de 30d
- Chave RSA256 (assimétrica), rotação periódica (90 dias)
- jti (JWT ID) armazenado no Redis para revogação ativa
- refresh_token vinculado à impressão digital do dispositivo (User-Agent + faixa de IP)
- Na reemissão do refresh_token, o token antigo é invalidado imediatamente (rotation)
- Operações sensíveis (pagamento/destruição de recursos) exigem verificação secundária

Impressão digital do dispositivo:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  a tabela refresh_token registra essa impressão digital, verificada na reemissão
```

#### Política de Senhas

```
- Criptografia bcrypt, cost factor = 12
- Mínimo de 8 caracteres, deve conter letras maiúsculas e minúsculas + números
- 5 falhas consecutivas no registro/login → bloqueio da conta por 15 minutos
- Após alteração de senha, todos os tokens emitidos são invalidados imediatamente
- Suporte a verificação em duas etapas TOTP (ativação opcional pelo usuário)
```

#### Política CORS

```php
// middleware webman
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### Segurança de Upload de Arquivos

```
- Validação de extensões por lista de permissões (somente: jpg, jpeg, png, pdf, gif)
- Validação do tipo MIME do arquivo (Content-Type falsificado não é permitido)
- Limites de tamanho: avatar 2MB, documentos KYC 5MB, anexos 10MB
- Renomeação após upload: {uuid}.{ext}, nome original não é preservado
- Processamento secundário de imagens: GD/Imagick remove EXIF + metadados
- Caminho de armazenamento em diretório inacessível pela web, leitura via proxy PHP
- Varredura de vírus: ClamAV (documentos KYC/arquivos enviados por usuários)
```

---

### 4.3 Segurança de Dados e Armazenamento

#### Criptografia de Dados Sensíveis

```
Algoritmo de criptografia: AES-256-GCM (criptografia autenticada, resistente a adulteração)
Gestão de chaves: chave mestra nas variáveis de ambiente, cada campo usa chave derivada independente

Campos que exigem criptografia de armazenamento:
| Tipo de dado | Campo | Método de criptografia |
|----------|------|----------|
| Senha | users.password_hash | bcrypt (unidirecional) |
| Chave de pagamento | payment_channels.api_key | AES-256-GCM |
| Chave de provedor de nuvem | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Token Proxmox | host_machines.api_token_encrypted | AES-256-GCM |
| Número do documento KYC | user_kyc.id_number | AES-256-GCM |
| Conta de pagamento | conta de saque | AES-256-GCM |
| Senha de login (VNC) | resource_servers.login_password | AES-256-GCM |

Derivação de chave:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### Mascaramento de Logs

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### Segurança do Banco de Dados

```
- MySQL usa prepared statement (tratado automaticamente pelo Eloquent)
- Princípio do menor privilégio para contas de acesso ao banco:
  - app_user: SELECT, INSERT, UPDATE, DELETE (sem DDL)
  - migration_user: permissões DDL (usado apenas em migrações, com restrição de IP)
  - read_user: SELECT somente leitura (relatórios/análise de dados)
- Conexões usam SSL/TLS (opções SSL do PDO PHP)
- Porta do banco não exposta à internet (acessível apenas na rede interna)
- Backup periódico: backup completo a cada 1 dia, binlog sincronizado em tempo real
```

#### Backup e Recuperação de Dados

```
Estratégia de backup:
- MySQL: backup completo diário + incremento binlog em tempo real
- Redis: RDB por hora + persistência AOF em tempo real
- Arquivos enviados por usuários: S3/OSS com múltiplas réplicas automáticas + replicação entre regiões
- Snapshot de VM Proxmox: semanal (retenção de 4 semanas)
- Criptografia de backup: armazenamento criptografado com AES-256

Exercícios de recuperação:
- Exercício de recuperação de desastres a cada trimestre
- Objetivo de tempo de recuperação (RTO): < 4 horas
- Objetivo de ponto de recuperação (RPO): < 1 hora
```

---

### 4.4 Virtualização e Isolamento de Recursos

#### Reforço de Segurança Proxmox

```
1. Controle de acesso à API:
   - A API do Proxmox escuta apenas IPs da rede interna (não vinculada à internet)
   - Permissões mínimas de token: cada role recebe apenas as permissões necessárias
   - Porta da API (8006) acessível apenas pelo IP do servidor de aplicação PHP (iptables)

2. Reforço de SSH:
   - Desabilitar login por senha, apenas autenticação por chave
   - Desabilitar login root, usar conta de administração dedicada
   - Porta SSH em porta não padrão (reduz varredura)
   - Fail2ban: 5 falhas bloqueiam por 1 hora

3. Atualizações do sistema:
   - Assinar a lista de e-mails de atualizações de segurança do Proxmox
   - apt update && apt upgrade periódicos
   - Livepatch do kernel (Canonical Livepatch Service)

4. Firewall (iptables/nftables):
   - Negar todo o tráfego de entrada por padrão
   - Abrir apenas: 8006 (somente IP do servidor de aplicação), porta SSH (somente IPs de administração)
   - Isolamento entre bridge das VMs e rede de gerenciamento do host
```

#### Isolamento entre VMs

```
- Cada VM usa VLAN de bridge de rede virtual independente
- Comunicação entre VMs proibida (regras de firewall Proxmox + isolamento VLAN)
- O usuário acessa apenas a própria VM pelo IP público
- Limites de recursos da VM (cgroup): impede que uma VM esgote os recursos do host
  - CPU limit: limite superior das cores adquiridas
  - RAM limit: limite superior da capacidade adquirida
  - Disk IOPS limit: evita disputa por disco
  - Network bandwidth limit: limite superior da banda adquirida
```

#### Segurança na Alocação de IP

```
- Registro de auditoria completo da alocação de IP (quem, quando, qual IP foi alocado)
- Período de cooldown de 24h após liberação do IP (evita uso indevido por alocação imediata a terceiros)
- Lista de bloqueio de IP: IPs denunciados/abusados são marcados como não alocáveis
- Monitoramento de uso de IP: verificação periódica se os IPs alocados estão em uso normal
```

---

### 4.5 Segurança de Pagamento

```
1. Conformidade PCI DSS:
   - Dados de cartão de crédito não passam por nossos servidores (Stripe Elements / Checkout)
   - card_token gerado diretamente pelo frontend do Stripe, o backend recebe apenas o token
   - Nenhum CVV/número completo de cartão armazenado em logs ou bancos de dados

2. Criptomoedas:
   - Chave privada de recebimento em cold storage (assinatura offline)
   - Hot wallet mantém apenas o limite de giro diário
   - Verificação da checksum após gerar o endereço de recebimento
   - Transações grandes ( > $10000) exigem revisão manual antes da confirmação

3. Prevenção de fraude de pagamento:
   - Pagamentos de alta frequência pelo mesmo usuário/IP em curto período → congelamento por controle de risco
   - Pagamento de alto valor por usuário recém-registrado → revisão manual
   - Valor de pagamento anômalo (incompatível com o preço do produto) → bloqueio
   - Usuários com taxa de reembolso muito alta → marcação de risco

4. Verificação de assinatura de callback:
   - Stripe: verifica assinatura do webhook (cabeçalho stripe-signature)
   - Coinbase: verifica assinatura do webhook (cabeçalho X-CC-Webhook-Signature)
   - Alipay: verifica notify_id com segunda confirmação no servidor do Alipay
   - Todos os callbacks: verificação de que o IP está nas faixas conhecidas de gateways de pagamento
```

#### Segurança de Reembolso

```
- Reembolso exige aprovação em dois níveis (solicitação pelo suporte → confirmação do administrador)
- Validações antes do reembolso: status do pedido, prazo de reembolso, número de reembolsos
- Valor do reembolso não pode exceder o valor efetivamente pago no pedido original
- Devolução pela via original: API de reembolso do canal de pagamento + devolução de saldo
- Mutex de reembolso (Redis): previne reembolsos duplicados concorrentes
```

---

### 4.6 Controle de Acesso e Permissões

#### Modelo RBAC

```
Hierarquia de papéis:
  super_admin    (super administrador — todas as permissões)
  admin          (administrador — tudo exceto configurações do sistema)
  finance        (financeiro — pagamento/conciliação/reembolso/liquidação)
  support        (suporte — gestão de usuários/pedidos/chamados)
  supplier       (fornecedor — próprios produtos/pedidos/liquidações)
  user           (usuário comum — próprios recursos/pedidos/chamados)

Definição de permissões:
  {module}.{action}
  ex.: order.view, order.create, order.refund, resource.destroy

Middleware de verificação de permissão:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### Limitação de Taxa de API

```php
// middleware de limitação de taxa webman (token bucket no Redis)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### Isolamento de Dados do Fornecedor

```
Princípios de isolamento de dados:
- O fornecedor só pode consultar e operar seus próprios recursos
- Toda consulta que envolva supplier_id anexa automaticamente WHERE supplier_id = auth()->supplier_id

Implementação:
  // Scope global
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // Registrado nos Models Product/Order etc.
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 Auditoria de Operações

```
Conteúdo registrado no log de auditoria:
- ID do operador, IP, User-Agent
- Horário da operação
- Módulo da operação (qual menu/endpoint)
- Tipo de operação: criar/modificar/excluir/exportar/aprovar
- Objeto da operação: qual campo de qual recurso
- Valor antes / valor depois (alteração em nível de campo)
- Resultado da operação: sucesso/falha
- ID da requisição (rastreabilidade de ponta a ponta)

Escopo do registro:
- Todas as operações do painel administrativo (100% registradas)
- Operações sensíveis do usuário: pagamento/destruição de recurso/envio de KYC/alteração de senha (100% registradas)
- Login/logout (100% registrados)
- Criação/revogação de API Key (100% registradas)

Armazenamento e retenção:
- Logs de auditoria gravados em banco de dados separado (audit_db), isolado do banco da aplicação
- Retenção mínima de 1 ano, itens financeiros retidos por 3 anos
- Suporte a exportação CSV/JSON para revisões de conformidade

Middleware de log de auditoria:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 Regras de Controle de Risco

```
Mecanismo de controle de risco em tempo real:

Regra 1: Comportamento anômalo de conta nova
  Condição: tempo de registro < 24h AND (total de pagamentos > $500 OU mais de 5 chamados criados)
  Ação: marcar a conta como "em observação", notificar o administrador de risco

Regra 2: Detecção de registro em massa
  Condição: mais de 3 contas registradas pelo mesmo IP em 24h
  Ação: recusar novos registros, congelar novas contas desse IP

Regra 3: Pagamento anômalo
  Condição: mais de 5 falhas de pagamento do mesmo usuário em 1h
  Ação: congelar a função de pagamento por 2h, gerar chamado de risco

Regra 4: Abuso de reembolso
  Condição: mais de 3 reembolsos do mesmo usuário em 30 dias OU taxa de reembolso > 20%
  Ação: restringir a permissão de reembolso da conta, novos pedidos marcados para revisão de risco

Regra 5: Abuso de API
  Condição: mais de 10000 chamadas de API com um único token em 1h
  Ação: rebaixar esse token (reduzir limite), notificar o administrador

Regra 6: Abuso de recursos
  Condição: VM denunciada por spam/DDoS/mineração (recebimento de notificação de Abuso)
  Ação: desligamento automático, congelamento do recurso, geração de chamado de alta prioridade

Ações de controle de risco:
- Marcar (flag): apenas registro, sem impacto no uso
- Rebaixar (throttle): reduzir o limite
- Congelar (freeze): desabilitar temporariamente funções específicas
- Banir (ban): banimento permanente da conta
```

---

### 4.9 Resposta a Incidentes

```
Classificação de eventos de segurança:

P0 (emergência) — vazamento de dados, perda financeira, indisponibilidade da plataforma
  → Notificar imediatamente CTO + equipe de segurança
  → Iniciar resposta a incidentes em 30 minutos
  → Colocar offline os serviços a montante afetados, preservar evidências
  → Publicar relatório do incidente em até 24h após o reparo

P1 (grave) — roubo de conta individual, fraude de pagamento, aumento anômalo de disparos WAF
  → Notificar o responsável de segurança
  → Tratar em até 2h
  → Congelar contas/recursos afetados

P2 (normal) — vulnerabilidades de baixa/média gravidade encontradas em varredura, alertas de login anômalo
  → Registrar no sistema de chamados
  → Corrigir na próxima iteração

Contato de emergência:
- Notificação automática após alertas P0/P1 (e-mail + SMS + telefone)
- Endpoint de health check do webman: GET /health (retorna 200 ou alerta)
- Escala: rodízio 7×24, mínimo de 2 pessoas de reserva
```

---

## V. Mecanismo de Provisionamento de Recursos

### Arquitetura de Plugins Provider

Cada combinação de tipo de produto de nuvem × provedor de nuvem implementa uma interface unificada:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

O ProviderFactory roteia para a implementação específica conforme (product_type, provider):
- ProxmoxProvider (máquina física de operação própria: servidores/disco de dados/IP)
- AwsServerProvider / AliyunServerProvider (servidores de nuvem de terceiros)
- GcpIpProvider (IP de terceiros)
- AzureDiskProvider (disco em nuvem de terceiros)
- NamecheapDomainProvider / GoDaddyDomainProvider (domínios)

### Garantia de Tarefas Assíncronas

- O worker de provisionamento consulta a tabela provision_tasks em polling
- Concorrência controlada por grupo de provider (máximo de 5 concorrentes por provider)
- Estratégia de retry: 1min → 5min → 15min → 1h → 6h → 24h (máximo de 6 tentativas)
- Falha não retentável → alerta + geração automática de chamado

### Cadeia Completa de Pedido ao Provisionamento de Recursos

```
Usuário faz o pedido                     Pagamento                        Provisionamento de recursos
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. Evento OrderPaid
   → addToCart(sku, region, qty)          → Confirmação de senha             → ProvisioningService
                                            (Confirmation)                    .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                           seleciona o canal de pagamento 10. Para cada OrderItem:
   ← {order, order_items}                                                      → ProvisionTask::create()
                                        6. StripeChannel::                       status=pending
3. Aplicar cupom                           createPaymentIntent()
   POST /coupons/validate                   → API Stripe                    11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                    → ProviderFactory
   ← {discount, coupon_id}                                                         .create(task)
                                        7. confirmCardPayment() no frontend
4. GET /orders/{id}/payment-methods     8. Callback webhook Stripe         12. Provider->create()
   → obtém canais de pagamento              → verificação de assinatura          ├→ HostSelector::select()
     disponíveis                             + verificação de idempotência        ├→ ProxmoxApi::create()
   ← [{channel, fee, total}]                → transaction=success                │  createVM(CPU,RAM,Disk)
                                            → dispara evento OrderPaid            │  allocateIP()
                                                                                  │  startVM()
                                        Estratégia de retry (em falha)           ├→ Criar registro Resource
                                        ────────────────                         └→ Atualizar host_machine
                                        1min → 5min → 15min                         recursos alocados
                                        → 1h → 6h → 24h
                                        (após 6 tentativas:                   13. Order::status = completed
                                        marca falha + alerta)                     → NotificationDispatcher
                                                                                    ::send('resource_ready')
                                        Fluxo de reembolso
                                        ────────
                                        usuário solicita → suporte analisa
                                        → admin confirma
                                        → provider.destroy()
                                        → payment.refund()
                                        → devolução pela via original
```

### Solução de Máquina Física Própria: Proxmox VE (Edição Comunitária)

Servidores próprios usam Proxmox VE (código aberto gratuito, AGPL v3); o PHP gerencia o ciclo de vida de máquinas virtuais KVM e a alocação de recursos chamando a REST API do Proxmox via HTTP.

Arquitetura:
```
PHP (webman) ──HTTPS──> Proxmox VE API (porta 8006)
                               │
                               └──> KVM/QEMU ──> VM (atribuída ao usuário)
```

#### Encapsulamento do Cliente ProxmoxApi

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### Operações de Recursos

**Criar VM (servidor):**
1. O HostSelector escolhe um host com recursos suficientes (ordenado por folga de cpu/ram/disk + balanceamento de carga)
2. Aloca um IP do ip_pool desse host
3. ProxmoxApi.post("/nodes/{node}/qemu") cria a VM (definindo vmid, name, cores, memory, net0, ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") monta o disco do sistema (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") inicia a VM
6. Atualiza as quantidades alocadas em host_machine.specs (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**Upgrade de CPU (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // atualiza as estatísticas de recursos do host
```

**Upgrade de memória (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Expandir disco do sistema:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Criar disco de dados separadamente:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Criar IP separadamente:**
Alocar do pool de IPs → adicionar interface de rede virtual + configurar o IP via API Proxmox, ou manter como recurso independente para placas de rede adicionais de uma VM existente.

**Destruir VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // desligar
$api->delete("/nodes/{node}/qemu/{vmid}");             // excluir VM
releaseIp($resourceId);                                // devolver o IP ao pool
$host->deallocate($specs);                             // recuperar recursos do host
```

#### Estratégia de Seleção de Host

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### Resumo das Operações de Divisão de Recursos

| Operação | Implementação | Operação a quente |
|------|----------|--------|
| Criar VM (CPU+memória+disco do sistema+IP) | Proxmox create qemu | — |
| Upgrade de CPU isolado | PUT config cores | Online |
| Upgrade de memória isolado | PUT config memory | Online |
| Expandir disco do sistema | PUT resize disk | Online (exige suporte da VM) |
| Criar disco de dados isolado | POST config adiciona disco | Online |
| Criar IP isolado | Alocação do pool de IPs + placa de rede na VM | Online |

### Ciclo de Vida dos Recursos

```
pending → active → destroyed (retenção de 30 dias) → purged (irrecuperável)
```

Renovação: active → (renew) → active (estende expired_at)
Upgrade: active → (upgrade) → upgrading → active

### Origem dos Recursos

| Origem | Virtualização/API | Tipos de produto | Descrição |
|------|-----------|----------|------|
| Máquina física própria | Proxmox VE (edição comunitária) | Servidores, discos de dados, IPs | Hospedagem em datacenter próprio, PHP chama a API Proxmox |
| Provedores de nuvem terceiros | AWS/GCP/Aliyun/Huawei Cloud/Azure SDK | Servidores, IPs, discos em nuvem | Revenda de recursos de nuvem de terceiros |
| Registradores de domínio | API Namecheap/GoDaddy/Aliyun Wanwang | Registro/transferência de domínios | Serviço de domínios |

### Integração Inicial

| Região | Servidores | IP | Discos em nuvem | Domínios |
|------|--------|----|------|------|
| Ásia-Pacífico | Aliyun, Huawei Cloud, AWS | Aliyun, GCP | Aliyun, Huawei Cloud | Aliyun Wanwang, Namecheap |
| Europa | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| América do Norte | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## VI. Sistema de Pagamento

### Roteamento de Múltiplos Canais

O PaymentRouter consulta os canais disponíveis conforme a preferência de moeda do usuário, calcula o valor efetivo de cada canal (incluindo a taxa do canal) e retorna a lista de opções de pagamento.

### Fluxo de Pagamento (Stripe)

```
Cliente (Flutter)                  Servidor (webman)                  API Stripe
───────────────               ──────────────                ──────────
1. Seleciona pagamento Stripe
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. Cria payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. Usuário confirma pagamento
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  verificação de assinatura (stripe-signature)
                                  verificação de idempotência (transaction_no)
                               8. Atualiza transaction=success
                               9. Dispara evento OrderPaid
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← página de pagamento concluído  ← retorna status do pedido
```

### Pagamento com Criptomoedas

1. O usuário seleciona a moeda (ex.: USDT-TRC20)
2. O backend gera o endereço de recebimento via API Coinbase Commerce / BitPay
3. Um worker consulta a confirmação na blockchain a cada 30s (ou via webhook)
4. Confirmação de recebimento → dispara o evento OrderPaid

### Câmbio e Múltiplas Moedas

- As fontes de câmbio são buscadas periodicamente da exchangerate-api e armazenadas no Redis
- A precificação de produtos usa USD como referência; as outras moedas são convertidas em tempo real
- O câmbio é travado no momento do pedido; reembolsos usam o câmbio original

### Controle de Visibilidade dos Canais de Pagamento

Campos da tabela payment_channels:
- is_visible: se é exibido ao usuário
- visible_regions: restringe regiões visíveis, vazio significa todas
- min_amount / max_amount: limites de faixa de valor do pedido

### Conciliação

Todos os dias de madrugada, os relatórios de liquidação de cada canal são buscados e conferidos transação a transação com o sistema; diferenças > $0.01 geram alerta.

### Política de Reembolso

- Servidores/VPS: reembolso integral dentro de 72h após a compra
- Domínios: reembolso possível em até 5 dias após o registro (normas ICANN)
- IPs: não reembolsáveis após a compra
- Discos em nuvem: mesma política dos servidores
- Produtos promocionais especiais: não reembolsáveis

Fluxo de reembolso: usuário solicita → gera Ticket → suporte analisa → admin confirma → provider.destroy() → payment.refund() → devolução pela via original

---

## VII. Estrutura das Páginas do Cliente

### Flutter / HarmonyOS (usuário)

- **Autenticação**: login/registro (e-mail+senha, Google OAuth, Apple ID, celular), esqueci a senha, verificação em duas etapas
- **Página inicial**: seletor de região, entradas de categorias de produto, Banner/promoções, produtos recomendados
- **Produtos**: lista (filtros de múltiplos critérios), detalhes (calculadora de configuração/região/preço), avaliações
- **Carrinho e pagamento**: carrinho, confirmação do pedido (forma de pagamento/endereço de cobrança/saldo/cupom), caixa, resultado do pagamento
- **Meus recursos**: lista de recursos (filtro por status), operações de detalhes (reiniciar/desligar/renovar/upgrade/destruir), SSO do console, gráficos de uso
- **Pedidos**: lista (aguardando pagamento/pago/concluído/reembolsado), detalhes, faturas
- **Chamados**: lista, novo, conversa
- **Central pessoal**: perfil/KYC, saldo e recarga, notificações, gestão de endereços, configurações de idioma/moeda/segurança
- **Público**: central de ajuda, termos de serviço, sobre

### Painel Administrativo webman-admin

- **Dashboard**: visão geral + gráficos de tendência
- **Gestão de usuários**: lista/detalhes/aprovação de KYC
- **Gestão de produtos**: categorias/lista/precificação (SKU×região)/estoque/avaliações
- **Gestão de pedidos**: lista/detalhes/aprovação de reembolso/faturas
- **Gestão de pagamentos**: configuração de canais/registros de transação/relatórios de conciliação
- **Gestão de recursos**: lista/monitoramento de tarefas de provisionamento/configuração de API de provedores de nuvem
- **Gestão de fornecedores**: aprovação de onboarding/lista/atribuição de produtos/liquidação/saques
- **Gestão de chamados**: fila/meus chamados/monitoramento de SLA
- **Gestão de domínios**: precificação de TLD/API de registradores/gestão de transferências
- **Notificações**: gestão de modelos/registros de envio
- **Configurações do sistema**: administradores e papéis/logs de operação/múltiplos idiomas/câmbio/regiões/parâmetros do sistema
- **Relatórios**: receita/liquidação de fornecedores/análise de vendas de produtos/análise por região

---

## VIII. Sistema de Notificações

### Quatro Canais

E-mail (SMTP/SendGrid) / SMS (Twilio/Aliyun SMS) / Push (FCM/HMS) / Mensagem no site

### Fluxo

Evento disparado → Notification Dispatcher → correspondência de modelo (código do evento + preferência de idioma) → distribuição pelos canais conforme preferência do usuário → envio assíncrono via Redis Queue

### Tipos de Notificação

Código de verificação de registro, pagamento de pedido concluído, provisionamento de recurso concluído, lembrete de expiração de recurso (7d/3d/1d), resposta de chamado, reembolso concluído, alerta de segurança, campanha promocional

### Retry em Falhas

3 tentativas com backoff, gerenciado via webman redis-queue.

---

## IX. Sistema de Fornecedores

### Fluxo de Onboarding

Registro → envio de informações da empresa + contato + método de liquidação → aprovação do administrador → publicação de produtos após aprovação → aprovação de produtos pelo admin → usuário compra → divisão automática → fornecedor solicita saque → admin efetua o pagamento

### Isolamento de Permissões

O fornecedor só pode ver seus próprios produtos/pedidos/boletos de liquidação/chamados/registros de saque. Não pode ver a receita da plataforma, dados de outros fornecedores ou configurações de canais de pagamento.

### Regras de Divisão

- Produtos próprios: commission_rate = 100% (tudo para a plataforma)
- Produtos de terceiros: commission_rate = 5%~20% (comissão da plataforma)
- Fórmula de liquidação: valor dos produtos do pedido − comissão da plataforma − taxa do canal = valor a receber do fornecedor
- Ciclo de liquidação: semanal / mensal

### Fluxo Completo de Negócio do Fornecedor

```
Onboarding do fornecedor                    Aprovação do administrador
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → analisar informações do fornecedor
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → confirmar senha
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← sucesso
Publicação de produtos
────────
POST /supplier/products                Aprovação do administrador
  → {product_id, commission_rate}        → associar produto ao fornecedor
  ← {supplier_product}                     + definir taxa de comissão
                                          → status do produto: published

Usuário faz o pedido ──→ pagamento concluído ──→ provisionamento ──→ pedido concluído

Liquidação agendada (segundas 04:17)     Saques
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → contabilizar pedidos concluídos       → confirmação de senha
    no período                              (ConfirmationMiddleware)
  → calcular total_sales - commission     → SupplierService::requestWithdraw()
  → = payable                             → verificar saldo disponível para saque
  → criar SupplierSettlement              → criar SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← sucesso

Pagamento pelo administrador            Gestão de API Keys pelo administrador
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → gerar sk_xxx (armazenado com SHA256)
  → confirmar senha                       ← {api_key} (exibido apenas uma vez)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## X. Monitoramento e Operações

### Monitoramento de Recursos

- Métricas coletadas: uso de CPU/memória/disco/banda, conectividade de IP, IOPS de disco em nuvem, resolução DNS, expiração de certificado SSL
- Métodos de coleta: envio via Agent / SNMP (próprios) + API de monitoramento de provedores de nuvem (terceiros) + polling WHOIS/DNS (domínios)
- Ciclo de coleta: 5 minutos, armazenamento com Prometheus + VictoriaMetrics

### Regras de Alerta

| Evento de alerta | Gravidade | Condição de disparo |
|----------|--------|----------|
| Servidor fora do ar | Grave | 3 pings consecutivos sem resposta |
| CPU/memória > 90% | Informativo | Sustentado por 10 minutos |
| Disco > 90% | Aviso | Sustentado por 5 minutos |
| Banda > 80% | Informativo | Sustentado por 30 minutos |
| Certificado SSL < 30 dias | Aviso | Verificação diária |
| Domínio < 30 dias para expirar | Aviso | Verificação diária |
| Falha em tarefa de provisionamento | Grave | 2 falhas consecutivas |
| Diferença na conciliação de pagamento | Grave | Acima de $0.01 por item |

---

## XI. Arquitetura de Implantação

### Ambiente de Produção

- Servidores de aplicação × 2: webman (multiprocesso) + Nginx + Supervisor
- Banco de dados: MySQL 8.0 mestre-escravo (1 mestre, 2 escravos) + Redis Cluster
- Filas: webman redis-queue (callback de pagamento/notificações/tarefas de provisionamento)
- Tarefas agendadas: Crontab (conciliação/liquidação/verificação de domínios/lembretes de renovação)
- Armazenamento: S3/OSS + CDN
- Monitoramento de logs: ELK/Loki + Prometheus + Grafana + Sentry

### Estrutura de Diretórios

```
cloud-php/
├── apps/
│   ├── flutter/           # Cliente Flutter
│   └── harmonyos/         # Cliente HarmonyOS (ArkTS)
├── service/               # Servidor webman
│   ├── app/
│   │   ├── controller/    # Controladores (por módulo)
│   │   ├── service/       # Lógica de negócio (por módulo)
│   │   ├── model/         # Modelos de dados
│   │   ├── middleware/     # Middlewares
│   │   ├── event/         # Definições de eventos
│   │   ├── listener/      # Listeners de eventos
│   │   ├── queue/         # Tarefas de fila
│   │   ├── provider/      # Adaptadores de provedores de nuvem
│   │   └── cron/          # Tarefas agendadas
│   ├── common/            # Biblioteca comum (auth/payment/i18n/notification/helper)
│   ├── config/            # Arquivos de configuração
│   ├── database/
│   │   └── migrations/    # Migrações de banco de dados
│   └── storage/           # Logs/cache/uploads
├── admin/                 # webman-admin
├── docs/                  # Documentação
└── docker/                # Configuração Docker
```

### Dependências Composer Principais

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### Otimização de Alta Concorrência

#### 1. Separação de Leitura e Escrita no MySQL

O Eloquent roteia automaticamente SELECT para a conexão read e INSERT/UPDATE/DELETE para a conexão write.

```
Configuração (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (banco mestre)
  connections.mysql.read  → DB_READ_HOST  (escravo, múltiplos podem ser configurados para load balancing)
  sticky = true           → leitura após escrita dentro do mesmo ciclo de requisição usa o mestre (evita atraso mestre-escravo)

Variáveis de ambiente:
  DB_HOST=10.0.1.1          # mestre (escrita)
  DB_READ_HOST=10.0.2.1     # escravo (leitura), é possível implantar vários
```

**Regras de roteamento de leitura/escrita:**

| Tipo de operação | Destino da rota | Exemplo |
|---------|---------|------|
| SELECT | conexão read | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | conexão write | `Order::create(...)` |
| Todas as operações em transação | conexão write | `DB::transaction(...)` |
| Leitura após escrita (sticky) | conexão write | dentro do mesmo ciclo de requisição |

#### 2. Estratégia de Cache em Múltiplos Níveis no Redis

O `CacheService` é usado para cachear dados de leitura frequente; quando o Redis está indisponível, degrada automaticamente para consulta direta ao banco.

```
Camadas de cache:
  L1: Redis (compartilhado entre processos, nível de milissegundos)
  L2: MySQL (persistente, reserva)

Estratégia de cache:
  Lista de produtos     TTL 5min    chave por region_id + category_id + keyword
  Detalhes do produto   TTL 10min   chave por product_id, invalidação ativa em mudanças
  Lista de regiões      TTL 1h      dados regionais mudam raramente
  Câmbio               TTL 30min    atualizado por tarefa agendada + atualização ativa
  Precificação de TLD  TTL 1h       preços de TLD mudam com baixa frequência
  Artigos de ajuda     TTL 10min    invalidação ativa em publicação/modificação
  Categorias de produto TTL 10min   invalidação ativa em mudanças na árvore de categorias

Preaquecimento de cache (após implantação):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

Invalidação ativa (em mudanças de dados):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// exemplo de uso
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Compressão de Resposta e Limitação no Nginx

```
Compressão gzip:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  Efeito: taxa de compressão de 70-85% em respostas JSON, economia de banda

Otimização de proxy:
  proxy_buffering on           # bufferiza a resposta upstream, clientes lentos não ocupam workers
  proxy_http_version 1.1       # reutilização de conexões persistentes HTTP/1.1
  keep-alive para upstream      # reduz handshakes TCP

Limitação:
  limit_req: 10 req/s por IP (burst 20)
  limit_conn: 20 conexões concorrentes por IP
  endpoint /health sem limite (access_log desativado para reduzir I/O)
```

#### 4. Recomendações de Índices de Banco de Dados

Com base na análise dos padrões de consulta, os índices a seguir reduzem significativamente as linhas varridas em cenários de alta concorrência:

| Tabela | Índice recomendado | Consultas cobertas |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | lista de pedidos do usuário + filtro por status |
| `orders` | `(order_no)` (único) | consulta exata por número de pedido |
| `products` | `(status, category_id, sort)` | lista de produtos do site + filtro por categoria + ordenação |
| `product_skus` | `(product_id, status)` | lista de SKUs + filtro de status |
| `product_regions` | `(sku_id, region_id)` (único) | busca de precificação por região |
| `resources` | `(user_id, status)` | lista de meus recursos |
| `resources` | `(expired_at, status)` | tarefa agendada de verificação de expiração |
| `provision_tasks` | `(status, next_retry_at)` | worker consulta tarefas pendentes |
| `refresh_tokens` | `(user_id, revoked)` | consultas de gestão de sessões |
| `payment_transactions` | `(order_id)` | consulta de transações por pedido |
| `payment_transactions` | `(transaction_no)` (único) | verificação de idempotência de webhook |
| `tickets` | `(user_id, status)` | lista de chamados do usuário |
| `notifications` | `(user_id, read_at, created_at)` | lista de notificações do usuário |

#### 5. Estimativa de Conexões Concorrentes

```
webman multiprocesso:
  núcleos de CPU × processos = número de workers
  ex.: 4 núcleos × 8 workers = 32 processos worker
  
Conexões MySQL:
  cada worker mantém 1 conexão persistente
  32 workers × 2 instâncias (service + admin) = 64 conexões
  mestre 32 + escravo 32, recomendação conservadora de max_connections ≥ 200

Conexões Nginx:
  worker_connections 1024 × worker_processes auto
  pico de concorrência ≈ worker_connections × worker_processes / 2
  servidor de 4 núcleos ≈ 2048 conexões concorrentes
```

---

## XII. Tabela Geral de Estado de Implementação

### Módulos Principais

| Módulo | Estado | Descrição |
|------|------|------|
| **User** | ✅ Concluído | Registro/login/verificação de e-mail/OAuth/TOTP/gestão de sessões/encerramento GDPR/CRUD de endereços |
| **Product** | ✅ Concluído | Precificação SKU×região, categorias, busca (ES), avaliações, atributos, importação/exportação em lote |
| **Order** | ✅ Concluído | Carrinho, pedido, ciclo de vida, reembolso, faturas (PDF), cupons |
| **Payment** | ✅ Concluído | Canal Stripe, roteamento de múltiplos canais, verificação de assinatura de webhook, conciliação |
| **Provisioning** | ✅ Concluído | Proxmox + AWS EC2 + arquitetura extensível ProviderFactory |
| **Domain** | ✅ Concluído | Precificação de TLD, registros DNS, aprovação de transferência de domínio |
| **Supplier** | ✅ Concluído | Aprovação de onboarding, publicação de produtos, liquidação, saques, gestão de API Keys |
| **Monitor** | ✅ Concluído | Verificação de disponibilidade de recursos, mecanismo de alerta, monitoramento de certificados SSL |
| **Ticket** | ✅ Concluído | Criação/resposta/atribuição/fechamento/rastreamento de SLA |
| **Notification** | ✅ Concluído | Quatro canais e-mail/SMS/Push/mensagem no site + gestão de preferências do usuário |
| **Report** | ✅ Concluído | Relatórios de receita/fornecedores/regiões |
| **I18n** | ✅ Concluído | Múltiplos idiomas, múltiplas moedas, múltiplos fusos horários |

### Sistema de Segurança

| Funcionalidade | Estado |
|------|------|
| WAF (8 categorias, 45+ regras: injeção SQL/XSS/injeção de comando/inclusão de arquivo/injeção de cabeçalho/SSRF/injeção NoSQL/redirecionamento aberto) | ✅ |
| Middleware CORS | ✅ |
| Middleware de identificação de plataforma ClientPlatform (8 plataformas) | ✅ |
| Limitação de taxa de API (token bucket no Redis) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| Modo de manutenção (chave de variável de ambiente + lista de permissões de IP) | ✅ |
| Criptografia de requisição/resposta (AES-256-GCM) | ✅ |
| Logs de auditoria (banco separado, inclui rastreamento de client_platform) | ✅ |
| Mascaramento de dados (processamento automático em logs/respostas) | ✅ |
| Vinculação de impressão digital JWT + rotação de tokens + registro de client_platform | ✅ |
| Senhas bcrypt (cost=12) + criptografia secundária Encryptable | ✅ |
| Confirmação de senha secundária (ConfirmationMiddleware, 5 falhas bloqueiam 15min) | ✅ |
| Middleware WAF do painel admin | ✅ |
| Monitoramento de exceções Sentry (SentryBootstrap + mascaramento em before_send) | ✅ |
| Feature Flags (sobreposição dinâmica no Redis + API do painel administrativo) | ✅ |

### Novas Funcionalidades (2026-05-21)

| Funcionalidade | Estado |
|------|------|
| API externa do fornecedor (autenticação por API Key + endpoints de pedidos/recursos/liquidação/saques) | ✅ |
| Push em tempo real via WebSocket (WebSocket nativo Workerman + listeners de eventos) | ✅ |
| Scripts de teste de carga k6 (smoke/produtos/concorrência) | ✅ |

### Estatísticas do Backend

| Métrica | Quantidade |
|------|------|
| Endpoints de API | 135 |
| Modelos de dados | 50+ |
| Tabelas de banco de dados | 50+ |
| Middlewares | 15 (globais 7 + rota 6 + API externa 1 + WebSocket admin) |
| Tarefas agendadas | 7 |
| Arquivos de migração | 22 |
| Testes | 362 tests / 579 assertions (Service 295 + Admin 67) |
| Arquivos de teste | 22 |
| Scripts de teste de carga k6 | 3 (smoke / products / concurrent) |

### Documentação

| Documento | Caminho |
|------|------|
| Especificação de design do sistema | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Design do painel administrativo | `docs/admin-design.md` |
| Documentação da API do fornecedor | `docs/supplier-api.md` |
| Lista de verificação de implantação | `docs/deployment.md` |
| Script de smoke test de API | `docs/api-test.sh` |

### Estado do Frontend

| Cliente | Estado | Descrição |
|----|------|------|
| Flutter | 🟡 Em andamento | ApiClient já integrado com número de versão no header + camada de dados unificada ApiService; login/lista de produtos/carrinho/lista de recursos já conectados à API; histórico de pedidos/central de notificações aguardando validação em ambiente de compilação |
| HarmonyOS | 🔴 Inicial | Apenas página de login e ApiClient |
| Admin Panel | ✅ Concluído | Dashboard/usuários/produtos/pedidos/pagamentos/recursos/fornecedores/chamados/domínios/notificações/sistema/relatórios/Webhook/importação-exportação, funcionalidades completas |
