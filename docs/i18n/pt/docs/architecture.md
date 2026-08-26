# Documento de Design da Arquitetura CloudPlatform

## 1. Visão Geral do Sistema

O CloudPlatform é uma plataforma global de negociação de recursos em nuvem, que suporta o modelo híbrido de máquinas físicas próprias + fornecedores terceirizados. Os usuários podem comprar produtos como servidores (VM), endereços IP, discos em nuvem e domínios via Web/mobile; o sistema processa automaticamente o pagamento e a entrega dos recursos.

### 1.1 Decisões Centrais de Arquitetura

| Decisão | Escolha | Motivo |
|------|------|------|
| Framework de backend | PHP webman (Workerman) | Memória residente, orientado a eventos, multiprocesso, resposta em milissegundos |
| Padrão de arquitetura | Monólito modular | Módulos divididos verticalmente por negócio, camadas MVC internas, desacoplamento entre módulos via eventos |
| Painel administrativo | Instância webman independente (webman-admin + Layui) | Isola o tráfego de administração do tráfego de usuários, separação de domínios de falha |
| ORM | Illuminate/Eloquent | Ecossistema Laravel maduro, relacionamentos, Scopes, eventos, migrações |
| Chave primária distribuída | Snowflake 64-bit | Sem dependência de autoincremento, suporta naturalmente sharding de banco/tabela |
| Ofuscação de IDs | Hashids | Oculta o tamanho real dos IDs externamente, previne varredura por bots |
| Autenticação | JWT HS256 | Autenticação sem estado, Access 15min + Refresh 30d |
| Criptografia de transporte | AES-256-GCM | Criptografia/descriptografia transparente via middleware, GCM autenticado previne adulteração |
| Criptografia de campos | AES-128-ECB | Cast Eloquent com criptografia/descriptografia automática, criptografia determinística (cifra consultável por igualdade; necessária para login/verificação de unicidade); apenas ECB suportado |
| Fila de mensagens | Redis Queue | Processamento assíncrono de callbacks de pagamento, distribuição de notificações, provisão de recursos |
| Mecanismo de busca | database (padrão) / Elasticsearch 8.x | webman-scout usa o driver database por padrão (fallback SQL LIKE); com ES configurado, ativa o índice de tokenização IK |
| Virtualização | Proxmox VE + kvm-server | VMs próprias provisionadas pelo kvm-server em Rust (gRPC :50051, descoberta de serviço e-cat/etcd); o driver atual é simulado, o driver real libvirt fica na Fase 2 |
| Clientes | Flutter | Código único para iOS/macOS/Windows/Linux/Web (5 plataformas) + HarmonyOS |

### 1.2 Limites do Sistema

```
┌──────────────────────────────────────────────────────────────────┐
│                         Cliente                                  │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx (proxy reverso)                          │
│  Terminação SSL / compressão gzip / rate limit / WebSocket Upgrade│
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              Servidor webman (multiprocesso)                      │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │Cadeia de middlewares globais: Version→CORS→SecurityHeaders│   │
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale│   │
│  │             →Metrics→Hashid→Maintenance→[middleware de rota]│ │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ Servidor WebSocket (:8282) — push em tempo real          │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (mestre/escravo)│(cache/fila)│    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  Descoberta e-cat / etcd    │
    │  Driver simulado (libvirt Fase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  API Proxmox VE (:8006)     │
    │  Virtualização KVM/QEMU     │
    │  Pool de IPs / discos / hosts│
    └─────────────────────────────┘
```

---

## 2. Arquitetura de Componentes

### 2.1 Design de Dupla Instância

O projeto contém duas instâncias webman independentes, compartilhando o mesmo banco MySQL:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrador ────▶│   port: 8788         │
                    │   Middlewares: WAF→ACL│
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  Usuário/API ─────▶│   service/           │
                    │   port: 8787         │
                    │   12 globais + 6 de rota │
                    └─────────────────────┘
```

| Instância | Porta | Responsabilidade | Middlewares |
|------|------|------|--------|
| **service** | 8787 | API de usuários + API de administração + WebSocket | 12 globais + 6 de rota + SupplierApiKey |
| **admin** | 8788 | Painel HTML administrativo (Layui) | WafMiddleware + AccessControl |

### 2.2 Estrutura de Camadas dos Módulos

Cada módulo de negócio segue uma camada unificada internamente:

```
app/{Module}/
├── controller/     # Camada HTTP: validação de parâmetros, chamada de Service, retorno de Response
│   └── external/   # Controladores de API externa (autenticação via API Key do fornecedor)
├── service/        # Lógica de negócio: sem dependência de HTTP, reutilizável por Controller/Queue Worker
├── model/          # Modelos de dados Eloquent: definição de relacionamentos, scopes de consulta, Casts
├── event/          # Definição de eventos de domínio (OrderPaid, TicketCreated etc.)
├── listener/       # Listeners de eventos (Provisioning, push via WebSocket)
├── provider/       # Adaptadores de provedores de nuvem (ProxmoxProvider etc.)
├── queue/          # Consumidores de fila (ProvisionWorker, EmailSender etc.)
└── cron/           # Tarefas agendadas (ExchangeRateSync, ExpirationCheck etc.)
```

### 2.3 Camadas da Biblioteca Comum

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # Serviço de captcha por clique
├── clientplatform/      # ClientPlatformMiddleware (cabeçalho X-Client-Platform)
├── confirmation/        # Middleware de confirmação de senha secundária
├── encryption/          # Middleware de criptografia de transporte AES-256-GCM
├── feature/             # Feature Flags (interruptores de funcionalidades)
├── hashid/              # Middleware de decodificação de requisições Hashids + serviço de codificação/decodificação
├── helper/              # Formatação de Response + CacheService
├── http/                # Utilitário de cliente HTTP
├── i18n/middleware/     # LocaleMiddleware multilíngue
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # Serviço de IDs Snowflake + Trait Eloquent
├── metrics/             # Coletor de métricas Prometheus + renderizador + middleware de contagem de requisições HTTP
├── version/             # VersionMiddleware (cabeçalho X-Api-Version)
└── webhook/             # Distribuidor de eventos Webhook
```

---

## 3. Pipeline de Execução dos Middlewares

### 3.1 Cadeia de Middlewares Globais (todas as requisições)

```
Requisição HTTP
  │
  ▼
1. VersionMiddleware         ← Validação do cabeçalho X-Api-Version; padrão v1 se ausente; 400 se inválido
  │                            Aplica-se apenas a /api/ e /admin/api/
  ▼
2. CorsMiddleware            ← Preflight OPTIONS retorna cabeçalhos CORS, reflexo do Origin
  ▼
3. SecurityHeadersMiddleware ← Cabeçalhos de segurança HSTS / X-Frame-Options / CSP / Referrer-Policy
  ▼
4. ClientPlatformMiddleware  ← Identificação do cabeçalho X-Client-Platform (8 plataformas), injeta properties
  │                            Aplica-se apenas a /api/ e /admin/api/
  ▼
5. GeoBlockMiddleware        ← Bloqueio geográfico GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← Varredura de 8 categorias com 45+ regras (JSON body + URL + UA + corpo bruto)
  │                          ← Whitelist de Content-Type + limite de 10MB no corpo + limite de 2KB na URL
  │                             Se acionado → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← Detecção de 31 tipos de ataque (XSS/SQLi/SSRF/desserialização etc.), whitelist/blacklist de IPs
  ▼
8. RateLimitMiddleware       ← Rate limit em todas as rotas (dois buckets: per-IP + per-token)
  ▼
9. LocaleMiddleware          ← Análise de Accept-Language, define o locale
  ▼
10. MetricsMiddleware        ← Contagem de requisições HTTP e registro de latência para Prometheus
  ▼
11. HashidRequestMiddleware  ← Decodificação de strings hashid nos parâmetros → IDs inteiros reais
  ▼
12. MaintenanceMiddleware    ← Verificação de MAINTENANCE_MODE, IPs da whitelist passam
  │
  ▼
[Middlewares de rota — anexados por grupo de rotas]
  │
  ├─ /health (monitoramento interno) ──
  │   InternalTokenMiddleware      ← Validação de token interno em /health/live|ready|deps
  │
  ├─ /api/auth ─────────────────────
  │   EncryptionMiddleware          ← Criptografia/descriptografia AES-256-GCM do corpo da requisição/resposta
  │
  ├─ /api (autenticação de usuário) ─
  │   EncryptionMiddleware
  │   AuthMiddleware                ← Validação de JWT Bearer Token → $request->userId/role
  │
  ├─ /api (operações sensíveis) ────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← Confirmação de senha secundária, contador Redis, bloqueio de 15min após 5 tentativas
  │
  ├─ /api/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← Verificação SHA256 de sk_xxx → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← Verificação de permissões RBAC
  │
  └─ /admin/api (operações sensíveis) ─
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller → Service → Model → DB
```

### 3.2 Detalhes de Cada Middleware

| Middleware | Local | Registro | Responsabilidade |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | Global | Valida `X-Api-Version`, padrão v1 se ausente |
| `CorsMiddleware` | common/Security | Global | Preflight OPTIONS, reflexo do Origin |
| `SecurityHeadersMiddleware` | common/Security | Global | Cabeçalhos de segurança HSTS / X-Frame-Options / CSP / Referrer-Policy |
| `ClientPlatformMiddleware` | common/ClientPlatform | Global | Identificação de 8 plataformas via `X-Client-Platform` |
| `GeoBlockMiddleware` | common/Security | Global | Bloqueio geográfico GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | Global (service)+admin | 8 categorias com 45+ regras + limites de requisição |
| `SecurityPlugin` | Erikwang2013\Security | Global | Detecção de 31 tipos de ataque, whitelist/blacklist de IPs |
| `RateLimitMiddleware` | common/Security | Global | Rate limit com token bucket no Redis (dois buckets: per-IP + per-token) |
| `LocaleMiddleware` | common/I18n | Global | Análise de Accept-Language |
| `MetricsMiddleware` | common/Metrics | Global | Contagem de requisições HTTP e latência para Prometheus |
| `HashidRequestMiddleware` | common/Hashid | Global | Decodificação de hashid nas requisições |
| `MaintenanceMiddleware` | common/Security | Global | Modo de manutenção + whitelist de IPs |
| `InternalTokenMiddleware` | common/Security | Grupo de rotas | Validação de token interno em `/health/live|ready|deps` |
| `EncryptionMiddleware` | common/Encryption | Grupo de rotas | Criptografia/descriptografia AES-256-GCM |
| `AuthMiddleware` | common/Auth | Grupo de rotas | Validação de JWT Bearer Token |
| `AdminRoleMiddleware` | common/Auth | Grupo de rotas | RBAC de administrador |
| `ConfirmationMiddleware` | common/Confirmation | Grupo de rotas | Confirmação de senha secundária |
| `SupplierApiKeyMiddleware` | common/Auth | Grupo de rotas | Verificação SHA256 da API Key sk_xxx |

---

## 4. Arquitetura de Dados

### 4.1 Chave Primária Distribuída: Snowflake

```
Estrutura do ID Snowflake de 64 bits:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  Timestamp em ms   Data center   Nó de trabalho   Número de sequência
  Época: 2024-01-01
  Vida útil máxima: ~69 anos
```

Todos os modelos Eloquent geram automaticamente o ID no evento `creating` através do Trait `HasSnowflakeId`. O tipo da coluna no banco é `bigint unsigned`.

### 4.2 Ofuscação de IDs: Hashids

```
Fluxo da requisição:
  Cliente: GET /api/products/aB3xK7mQ9w
    → HashidRequestMiddleware decodifica → int(1234567890)
      → Controller/Service opera com o ID inteiro
        → Response::success() / Response::paginated()
          → hashids_encode_ids() codifica recursivamente todos os campos id/*_id
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Conexões de Banco de Dados

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL mestre (escrita)│   │  MySQL réplica (leitura)│
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  Conexão persistente (PDO) │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  banco audit (conexão separada) │
         │  Armazenamento isolado de logs de auditoria │
         └──────────────────────┘
```

### 4.4 Camadas de Criptografia

| Camada | Algoritmo | Implementação | Uso |
|------|------|------|------|
| Transporte | AES-256-GCM | EncryptionMiddleware | Criptografia do corpo de requisições/respostas da API, autenticação GCM |
| Campos | AES-128-ECB | Encryptable Cast | Criptografia/descriptografia automática de campos sensíveis (criptografia determinística: mesmo texto puro → mesma cifra; consultas por igualdade na cifra para login/verificação de unicidade; apenas ECB suportado, trocar de cipher exige migração de re-criptografia) |
| Hash | bcrypt + SHA256 | JWT / API Key | Armazenamento irreversível de senhas/Tokens |
| Chave primária | Hashids | Response + Middleware | Ofuscação externa de IDs |

### 4.5 Camadas de Cache

```
L1: Camada de cache Redis (CacheService)
    Listas de produtos TTL 5min | Detalhes do produto TTL 10min
    Regiões TTL 1h | Câmbio TTL 30min | TLD TTL 1h
    Estratégia de invalidação: forget / forgetPattern ativos em mudanças de dados

L2: Camada de consulta MySQL (Eloquent + otimização de índices)
    13 índices compostos/covering cobrem as consultas de alta frequência

L3: Compressão de resposta Nginx (gzip level 6)
    Taxa de compressão de respostas JSON de 70-85%
```

### 4.6 Internacionalização (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (middleware global)
         │  Analisa o idioma principal → zh-CN
         │  I18n::setLocale('zh-CN')
         │  Carrega i18n/zh-CN/messages.php
         ▼
  Controller / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  retorna o valor no idioma
         └── I18n::getLocale()                  →  'zh-CN'
```

| Capacidade | Descrição |
|------|------|
| Análise de cabeçalho | `LocaleMiddleware` analisa automaticamente o cabeçalho `Accept-Language` |
| Fallback de idioma | Idioma não suportado → `fallback_locale` |
| Traduções estáticas | 120 entradas, cobrindo 15 módulos (`i18n/{locale}/messages.php`) |
| Substituição de parâmetros | `I18n::trans('key', ['field' => 'value'])` |
| Campos JSON | `translateField()` processa colunas JSON multilíngues |

---

## 5. Arquitetura de Segurança

### 5.1 Sistema de Regras WAF (8 categorias, 45+ regras)

| Categoria | Nº de regras | Escopo de detecção |
|------|--------|---------|
| Injeção de SQL | 9 | Símbolos de comentário, palavras-chave, codificação hexadecimal, UNION, condições sempre verdadeiras, blind time-based, stacked queries |
| XSS | 8 | Tags HTML, variantes de Script, 13 manipuladores de eventos, pseudo-protocolo JS, codificação de entidades, Data URI |
| Injeção de comandos | 5 | Comandos após pipe, comandos após ponto e vírgula, $(cmd), crases, palavras-chave de comandos isoladas |
| Inclusão de arquivos | 4 | Path traversal, pseudo-protocolos PHP, caminhos absolutos, Null byte |
| Injeção de cabeçalho HTTP | 2 | CRLF, injeção em Host/Cookie/Set-Cookie |
| SSRF | 6 | IPs de rede interna, localhost, metadata de nuvem, protocolo file:// |
| Injeção NoSQL | 3 | Operadores MongoDB, comandos perigosos do Redis |
| Redirecionamento aberto | 2 | redirect_uri para URLs externas, bypass por dupla codificação |

**Escopo de varredura:** regras de injeção de valores (SQLi/XSS/injeção de comandos/injeção de cabeçalho/SSRF/NoSQL/redirecionamento aberto) varrem a query string, o corpo da requisição e o User-Agent; o path da URL usa apenas o padrão de inclusão de arquivos (path traversal) para validação estrutural. Como caminhos de negócio contêm palavras comuns como select/insert/alert (por exemplo, `/order_item/select`), uma varredura do path inteiro derrubaria todos os endpoints CRUD — por isso o path não participa da correspondência de injeção de valores.

**Proteção no nível de requisição:** whitelist de Content-Type, limite de 10MB no corpo, limite de 2KB na URL

### 5.2 Sistema de Autenticação

```
┌─────────────────────────────────────────────┐
│               Métodos de autenticação        │
├──────────────┬──────────────┬───────────────┤
│  Usuário     │  Admin       │  API de fornecedor │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min│  Access 2h   │  prefixo sk_xxx │
│  Refresh 30d │  Refresh 7d  │  armazenamento SHA256 │
│  TOTP opcional │              │  exibida uma única vez │
│  OAuth opcional │            │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. Arquitetura de Implantação

### 6.1 Topologia de Produção

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │
               │  DDoS / Bot     │
               └────────┬────────┘
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL mestre/escravo │ Redis Cluster │
         │ 1 mestre 2 escravos  │ cache+fila    │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  Registro e-cat / etcd     │
         │  Driver simulado (libvirt Fase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Cluster Proxmox VE        │
         │  Máquinas físicas × N      │
         │  Virtualização KVM/QEMU    │
         └────────────────────────────┘
```

### 6.2 Modelo de Processos

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (padrão 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. Dependências Técnicas

### 7.1 Framework Central

| Pacote | Versão | Uso |
|----|------|------|
| workerman/webman-framework | ^2.1 | Framework web (multiprocesso com memória residente) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | Sistema de eventos |
| illuminate/redis | ^10.0 | Cliente Redis |
| webman/redis-queue | ^1.0 | Fila de mensagens Redis |

### 7.2 Pacotes do Ecossistema erikwang2013

| Pacote | Uso |
|----|------|
| snowflake-php | Chave primária distribuída de 64 bits |
| hashids | Ofuscação de IDs na API |
| encryptable | Criptografia de campos no banco |
| encryption | Criptografia de transporte AES-256-GCM |
| jwt-webman | Autenticação JWT |
| webman-scout | Busca em texto completo no Elasticsearch |
| season | Emoji de bandeiras de países |
| poster-php | Captcha por clique |

### 7.3 Integrações de Terceiros

| Pacote | Uso |
|----|------|
| stripe/stripe-php | Pagamento Stripe |
| twilio/sdk | SMS |
| kreait/firebase-php | Push FCM |
| guzzlehttp/guzzle | Cliente HTTP (API Proxmox etc.) |
| sentry/sentry | Monitoramento de exceções |
| phpoffice/phpspreadsheet | Exportação Excel |
