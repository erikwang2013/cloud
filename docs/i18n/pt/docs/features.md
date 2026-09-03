# Documento de Design Funcional CloudPlatform

## 1. Autenticação e Autorização de Usuários

### 1.1 Registro

```
POST /api/v1/auth/register
  → varredura WAF
  → rate limit 3 req/min
  → validação de senha len≥8
  → verificação de unicidade de email/telefone
  → bcrypt(password, cost=12)
  → Snowflake::id() gera user_id
  → Encryptable::set() criptografa campos sensíveis
  → criação de User + UserProfile + UserBalance
  → NotificationDispatcher::send('email_verify') envia email de verificação
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**Fluxo de dados:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/v1/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 Login

```
POST /api/v1/auth/login
  → varredura WAF
  → rate limit 5 req/min
  → verificação de Captcha (captcha por clique, limite de 3 tentativas)
  → Hash::check(password, user->password_hash)
  → 5 falhas → login_lock:{userId} Redis TTL 900s
  → verificação TOTP (obrigatória quando ativada pelo usuário, totp_code é obrigatório;
      após 5 erros acumulados → totp_fail:{userId} → login_lock TTL 900s)
  → detecção de novo IP → alerta por email
  → deviceFingerprint = sha256(UA + faixa de IP, prefixo para IPv6)
  → clientPlatform = cabeçalho X-Client-Platform
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/v1/auth/google → OAuth do Google → callback?code=xxx
  1. Verifica o ID Token do Google/Apple
  2. Busca ou cria o usuário (correspondência por email)
  3. Emite tokens (incluindo client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 Verificação em Duas Etapas TOTP

```
1. POST /api/v1/user/totp/setup
     → gera secret + URL do QR (armazenado temporariamente no Redis por 10 min, não persistido)
     ← {secret, qr_url, manual}
2. POST /api/v1/user/totp/verify
     → valida o código TOTP (primeira vez ativa o setup, depois é apenas verificação)
     ← {verified: true}
3. GET /api/v1/user/totp/recovery-codes
     → gera 8 códigos de recuperação de uso único (exige confirmação de senha)
     ← {recovery_codes: [8 códigos]}
4. No login: digite o código TOTP ou use um código de recuperação
     → POST /api/v1/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Gerenciamento de Sessões

```
GET /api/v1/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/v1/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/v1/user/account (cancelamento GDPR)
  → confirmação de senha secundária
  → soft delete do User
  → todos os RefreshTokens revogados
```

---

## 2. Gerenciamento de Produtos

### 2.1 Modelo de Produto

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (JSON multilingue) │                 │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 Lista de Produtos (com cache)

```
GET /api/v1/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → filtro por category_id/region_id/keyword/supplier_id
    → paginação count + skip/take
  ← resultado paginado

Invalidação de cache:
  Alterações de produto/SKU/preço regional no admin
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 Busca de Produtos (Elasticsearch)

```
GET /api/v1/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (tokenização em chinês com IK Analyzer)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Avaliações de Produtos

```
GET /api/v1/products/{id}/reviews
  → avaliações aprovadas + nota média + distribuição de notas
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/v1/products/{id}/reviews (exige login)
  → rating (1-5) + content
  → status = pending (exibida após aprovação do administrador)
```

### 2.5 Importação/Exportação em Lote

```
GET /admin/api/v1/products/export
  → download CSV (produtos + SKU + preços regionais)

POST /admin/api/v1/products/import
  → upload de CSV com upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Sistema de Pedidos

### 3.1 Carrinho de Compras

```
POST /api/v1/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/v1/cart           → lista do carrinho (com detalhes do SKU + preço em tempo real)
DELETE /api/v1/cart/{id}   → removeFromCart
PUT /api/v1/cart/{id}      → updateCartQuantity
```

### 3.2 Fluxo de Compra

```
1. POST /api/v1/orders                            Cria o pedido
     → valida estoque, calcula preço, aplica cupom
     ← {order_id, order_no, items, total}

2. POST /api/v1/coupons/validate                  Aplica cupom
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/v1/orders/{id}/payment-methods        Obtém os canais de pagamento disponíveis
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/v1/orders/{id}/pay                   Inicia o pagamento
     → confirmação de senha secundária (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Ciclo de Vida do Pedido

```
                    ┌─────────┐
                    │ pending │  aguardando pagamento
                    └────┬─────┘
                         │ pagamento bem-sucedido
                    ┌────┴─────┐
                    │  paid    │  pago
                    └────┬─────┘
                         │ evento OrderPaid
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│  concluído
                    └────┬─────┘
                         │ usuário solicita reembolso
                    ┌────┴─────┐
                    │ refunded │  reembolsado
                    └──────────┘

Condições de reembolso: servidores em até 72h | domínios em até 5 dias | IPs não reembolsáveis | produtos promocionais não reembolsáveis (outros tipos, como disk, sem janela de restrição; categorias desconhecidas passam por padrão)
Fluxo de reembolso: solicitação do usuário → criação de Ticket → revisão do suporte → confirmação do admin → Provider.destroy() → Payment.refund()
```

---

## 4. Sistema de Pagamento

### 4.1 Roteamento Multicanal

```
PaymentRouter::route(Order $order)
  → filtra canais disponíveis (is_visible + visible_regions + min/max_amount)
  → correspondência por currency
  → calcula o valor real de cada canal (incluindo taxa)
  → ordena por fee crescente
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Pagamento Stripe

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. seleciona Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. cria transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. usuário confirma o pagamento
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             verificação da assinatura stripe-signature
                             verificação de idempotência transaction_no
                          8. transaction=success
                          9. dispara o evento OrderPaid
                             → ProvisioningService
                             → push via WebSocket
                             → notificações email/SMS/Push
```

### 4.3 Conciliação

```
Cron: PaymentReconcile (diariamente às 02:37)
  → busca relatórios de liquidação de cada canal
  → concilia transação por transação com o sistema
  → diferença > $0.01 → alerta
```

---

## 5. Mecanismo de Provisionamento de Recursos

### 5.1 Arquitetura de Plugins Provider

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → instância Provider
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (extensível)
  'server:aliyun_ecs'  → AliyunProvider (extensível)
  'domain:namecheap'   → DomainProvider (extensível)
```

### 5.2 Cadeia Completa de Provisionamento

```
disparo do evento OrderPaid
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ cria um ProvisionTask para cada OrderItem
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (consumo da fila Redis)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     ordena por sobra de cpu/ram/disk + balanceamento de carga
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     cria a VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     monta o disco do sistema (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     inicia a VM
  │
  ├→ cria registros Resource + Disk + IpAllocation
  │
  ├→ atualiza a quantidade de recursos alocados de host_machine
  │
  └→ Order::status = completed
       → push via WebSocket 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

Estratégia de retry:
  1min → 5min → 15min → 1h → 6h → 24h (após 6 tentativas, marca falha + alerta)
```

> **Evolução do canal de provisionamento**: o kvm-server em Rust (`infrastructure/kvm-server`, workspace e-cat) já está no repositório —
> gRPC `ping/create_vm/vm_status` (:50051) + descoberta de serviço via etcd; no lado PHP, KvmClient /
> RegistryProcess (`service/app/grpc/`) já estão conectados. A camada de driver é atualmente um **driver simulado** (o driver real
> libvirt é a Fase 2); a cadeia de provisionamento ainda usa a conexão direta com ProxmoxProvider; quando o kvm-server assumir a
> criação de VMs, o fluxo desta seção permanece o mesmo, apenas muda o canal.

### 5.3 Resumo das Operações Proxmox

| Operação | API | Operação a quente |
|------|-----|--------|
| Criar VM | POST /nodes/{node}/qemu | — |
| Upgrade de CPU | PUT /qemu/{vmid}/config cores | online |
| Upgrade de memória | PUT /qemu/{vmid}/config memory | online |
| Expandir disco do sistema | PUT /qemu/{vmid}/resize disk | online |
| Criar disco de dados | POST /qemu/{vmid}/config scsi{n} | online |
| Criar IP independente | POST /qemu/{vmid}/config net{n} | online |
| Destruir VM | POST stop → DELETE qemu | — |
| Consulta de status | GET /qemu/{vmid}/status/current | — |

---

## 6. Sistema de Fornecedores

### 6.1 Fluxo de Cadastro

```
POST /api/v1/supplier/apply (exige login do usuário)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → revisão do administrador

Aprovação pelo administrador:
  POST /admin/api/v1/suppliers/{id}/approve (confirmação de senha)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → o usuário ganha permissões de fornecedor

Listagem de produtos:
  POST /api/v1/supplier/products
    → {product_id, commission_rate}
    → associa o produto ao fornecedor

Liquidação:
  Cron: SupplierSettlement (segundas-feiras às 04:17)
    → soma os pedidos concluídos no período
    → total_sales - commission = payable
    → cria SupplierSettlement

Saque:
  POST /api/v1/supplier/withdraw (confirmação de senha)
    → verifica o saldo disponível para saque
    → cria SupplierWithdraw (status=pending)
    → aprovação do administrador e transferência
```

### 6.2 API Externa

```
POST /admin/api/v1/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → armazenamento hash('sha256', rawKey)
  ← {api_key: "sk_xxx..."} (exibida uma única vez)

Uso pelo fornecedor:
  GET /api/v1/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → verificação da assinatura no SupplierApiKeyMiddleware
    → filtra dados por supplierId
```

---

## 7. Domínios e DNS

```
GET /api/v1/domain/check/{domain}/{tld}    # disponibilidade do domínio
GET /api/v1/domain/tlds                     # lista de TLDs registráveis (cache 1h)
GET /api/v1/dns/{domain}                    # lista de registros DNS
POST /api/v1/dns/{domain}/records           # adiciona registro DNS
DELETE /api/v1/dns/{domain}/records/{id}    # exclui registro DNS (confirmação de senha)
```

---

## 8. Sistema de Tickets

```
POST /api/v1/tickets                    # cria ticket
GET /api/v1/tickets                     # meus tickets
GET /api/v1/tickets/{id}                # detalhes do ticket
POST /api/v1/tickets/{id}/reply         # responde ao ticket

Administrador:
  GET /admin/api/v1/tickets              # fila de tickets
  POST /admin/api/v1/tickets/{id}/assign # atribui atendente
  POST /admin/api/v1/tickets/{id}/close  # fecha ticket

Orientado a eventos:
  evento TicketCreated
    → AutoAssignListener: atribui ao atendente com menos carga
    → push via WebSocket 'ticket.created'
```

---

## 9. Sistema de Notificações

### 9.1 Distribuição em Quatro Canais

```
disparo do evento → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → escrita direta na tabela notifications
```

### 9.2 Tipos de Notificação

| Evento | Canal | Momento do disparo |
|------|------|---------|
| Verificação de registro | email | após registro por email |
| Alerta de login anômalo | email | login de novo IP |
| Pagamento do pedido bem-sucedido | email/push | pagamento concluído |
| Provisionamento de recurso concluído | email/push/in_app | Provisioning concluído |
| Lembrete de expiração de recurso | email/push | 7d/3d/1d antes |
| Resposta de ticket | email/push/in_app | nova mensagem no Ticket |
| Reembolso concluído | email/push | reembolso processado |
| Expiração de certificado SSL | email | 30d antes |
| Expiração de domínio | email | 30d antes |

---

## 10. Monitoramento e Alertas

### 10.1 Monitoramento de Recursos

```
Cron: CollectMetrics (a cada 5 minutos)
  → consulta recursos ativos
  → ProxmoxApi::status() / API do Provider
  → métricas armazenadas em hash do Redis (TTL 1h)

Administrador:
  GET /admin/api/v1/monitor/dashboard
    → estatísticas gerais + alertas recentes
  GET /admin/api/v1/monitor/resources/{id}
    → métricas em tempo real (lidas do Redis)
```

### 10.2 Regras de Alerta

| Regra | Severidade | Condição de disparo |
|------|--------|---------|
| server_down | grave | 3 Pings inalcançáveis consecutivos |
| cpu_high | aviso | CPU > 90% por 10min |
| disk_high | aviso | disco > 90% por 5min |
| ssl_expiring | aviso | certificado SSL expira em < 30 dias |
| domain_expiring | aviso | domínio expira em < 30 dias |
| provision_failed | grave | falha contínua da tarefa de provisionamento |

---

## 11. Tarefas Agendadas

| Expressão Cron | Tarefa | Uso |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | sincronização de câmbio a cada 4 horas |
| `37 2 * * *` | PaymentReconcile | conciliação diária |
| `17 4 * * 1` | SupplierSettlement | liquidação de fornecedores às segundas |
| `23 6 * * *` | ExpirationCheck | verificação de expiração + notificação |
| `43 7 * * *` | SslCertificateCheck | verificação de certificados SSL |
| `*/5 * * * *` | CollectMetrics | coleta de métricas de recursos |
| `*/30 * * * *` | CheckExpirations | verificação de expiração de recursos |

---

## 12. Internacionalização (i18n)

### 12.1 Fluxo da Requisição

```
cliente → Accept-Language: zh-CN
  → LocaleMiddleware (middleware global)
    → I18n::setLocale('zh-CN')
    → carrega i18n/zh-CN/messages.php
```

### 12.2 Formas de Tradução

**Texto estático:** `I18n::trans('auth.login_success')` → `登录成功`
**Campos JSON:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Substituição de parâmetros:** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 Cobertura

120 entradas, cobrindo todos os módulos: autenticação/produtos/pedidos/pagamento/recursos/KYC/tickets/notificações/fornecedores/Webhook/sistema. Suporta fallback de idioma (idioma não suportado → en-US).

---

## 13. Feature Flags

```
config/features.php (valores padrão)
  ↓ pode ser sobrescrito
.env variáveis de ambiente FEATURE_*
  ↓ pode ser sobrescrito em tempo de execução
Redis feature:{name} (TTL 1h, ajustado dinamicamente pela API de administração)

API de administração:
  GET /admin/api/v1/features → lista todas as Flags com status/origem
  PUT /admin/api/v1/features/{name} → enable/disable/toggle/reset

Flags atuais:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. Certificados SSL

O produto de certificados SSL suporta três tipos: DV/OV/EV, com emissão e renovação automáticas via protocolo ACME (Let's Encrypt) ou API de CA externa (ZeroSSL/GoGetSSL).

**Fluxo principal:**

    usuário escolhe o plano SSL → pedido pago → criação do ProvisionTask
      → SslProvider::create() → CertificateAuthority::issue()
      → verificação ACME HTTP-01/DNS-01 → emissão do certificado
      → verificação diária de expires_at → renovação automática 14 dias antes do vencimento
      → expirou → status=expired → notificação ao usuário

**Modelos de dados:** `ssl_plans` (planos), `resource_ssl_certs` (instâncias de certificados)

## 15. Armazenamento de Objetos (S3)

Armazenamento de objetos compatível com a API S3, suportando AWS S3 e MinIO auto-hospedado. Os usuários fazem upload/download de arquivos via URLs pré-assinadas.

**Modelo de dados:** `resource_storage_buckets`

## 16. Aceleração CDN

O produto CDN suporta quatro provedores (Cloudflare / AWS CloudFront / Aliyun CDN / Tencent Cloud CDN), permitindo usar servidores ou buckets como origem no CDN, com suporte a purga de cache e configuração opcional de certificado HTTPS.

**Arquitetura de adaptadores:** um adaptador por provedor em `service/app/cdn/provider/`, todos implementando a `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration), despachados pela `CdnAdapterFactory` conforme o `provider_type`:

| provider_type | Adaptador | Protocolo de integração | Requer registro ICP |
|---------------|-----------|------------------------|----------------------|
| `cloudflare` | CloudflareAdapter | API REST v4 (inclui certificado automático SSL SaaS) | Não |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | Não |
| `aliyun` | AliyunCdnAdapter | Assinatura RPC | Sim |
| `tencent` | TencentCdnAdapter | Assinatura TC3 | Sim |

**Configuração de contas de provedores:** o painel admin mantém contas `provider_apis` via CRUD em `/admin/providers` (credenciais criptografadas no banco com Encryptable, `code` convencionado como `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`). Prioridade de resolução de credenciais no lado do usuário: conta vinculada (provider_account_id) → conta ativa com code correspondente → fallback para configuração de env.

**Vínculo por snapshot estrito:** o `provider_account_id` é definido na criação do domínio; exclusões/purga posteriores usam apenas essa conta vinculada; conta ausente ou desabilitada retorna 4003, sem troca silenciosa de conta. Domínios Aliyun/Tencent exigem registro ICP; sem registro, retorna 4002 (com aviso `requires_icp_registration`).

**Purga de cache:** `POST /api/v1/cdn/domains/{id}/purge`, URLs deduplicadas automaticamente e sem espaços (máx. 100), permitindo apenas o próprio domínio ou subdomínios, rejeitando curingas e URLs externas, idempotente.

**Interfaces:** CdnAdapterInterface + CdnProvider (reutiliza o canal de upgrade do ProvisionProvider, com suporte a upgrade de plano)

**Modelo de dados:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; a chave privada é removida do cert_config antes de gravar, apenas informações não sensíveis do certificado)

## 17. Cobrança por Uso

Pipeline completo de coleta de uso → agregação → cobrança → débito:

    ResourceMonitor coleta métricas a cada 5 min → resource_metrics
      → UsageAggregator agrega por hora → usage_events
      → BillingEngine debita o saldo diariamente → saldo insuficiente → suspende o recurso
      → SuspendCheck verifica a cada 30 min → saldo restaurado → reativa

**Modelos de dados:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Avaliação de Fornecedores

Usuários que compraram podem avaliar o fornecedor em quatro dimensões (qualidade/suporte/velocidade de entrega/custo-benefício), uma avaliação por pedido. O painel administrativo pode revisar (approve/hide).

**Modelos de dados:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Distribuição por Afiliados

Usuários geram links de indicação (?ref=CODE); novos usuários vinculam o affiliate_code no registro; após o pagamento do pedido, a comissão é atribuída automaticamente.

**Orientado a eventos:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Modelos de dados:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. API GraphQL

Oferece dois endpoints: POST /graphql (consultas públicas) e POST /api/v1/graphql (consultas autenticadas). Baseado em webonyx/graphql-php, com limite de profundidade de consulta de 5 níveis e limite de complexidade de 100.

**Operações sensíveis permanecem REST-only:** pagamento, saque, reembolso, revisão KYC.

## 21. Observabilidade

O endpoint de métricas Prometheus roda em processo independente em 127.0.0.1:9100, fora da influência do WAF/rate limit. O MetricsMiddleware registra a contagem de requisições HTTP e a latência. O Docker Compose inclui Prometheus + Grafana + regras de alerta + dashboards.

**Health checks:** /health (público), /health/live, /health/ready (5 verificações de dependências), /health/deps (detalhes de latência)
