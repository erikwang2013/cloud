# Documentação da API CloudPlatform

## Visão Geral

**URL Base:** `https://api.example.com`

**Controle de versão:** a versão da API faz parte do caminho da URL (por exemplo, `/api/v1/products`). Versões não suportadas retornam `400`.

**Métodos de autenticação:**

| Extremidade | Método | Cabeçalho |
|----|------|------|
| Cliente | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Painel administrativo | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| API externa de fornecedores | API Key | `Authorization: Bearer sk_xxx...` |
| Webhook do Stripe | Verificação de assinatura | `Stripe-Signature: ...` |

**Plataforma do cliente:** Recomenda-se que todas as requisições de API incluam o cabeçalho `X-Client-Platform`, com suporte a `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Idioma:** Recomenda-se que todas as requisições de API incluam o cabeçalho `Accept-Language` (`zh-CN` / `en-US`), que afeta o retorno de textos traduzidos e campos multilíngues em JSON. Se ausente, assume `en-US`.

---

## Formato de Resposta Padrão

### Sucesso

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Paginação

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Erro

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### Códigos de Status HTTP

| code | Descrição |
|------|------|
| 0 | Sucesso |
| 400 | Erro de parâmetro de requisição / versão de API não suportada / plataforma de cliente não suportada |
| 401 | Não autenticado |
| 403 | Sem permissão / bloqueado pelo WAF |
| 404 | Recurso não encontrado (falha de firstOrFail/findOrFail mapeada uniformemente para 404) |
| 413 | Corpo da requisição muito grande (>10MB) |
| 414 | URL muito longa (>2KB) |
| 415 | Content-Type não suportado |
| 422 | Falha na validação de parâmetros |
| 429 | Excedeu o limite de frequência de requisições |

---

## Grupos de Rotas e Matriz de Middleware

| Grupo de rotas | Middlewares | Prefixo |
|--------|--------|------|
| Público | Cadeia de middlewares globais | `/health`, `/api/v1/*` |
| `/health` (interno) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | Global + Encryption | `/api/v1/auth/*` |
| `/api/v1` (usuário) | Global + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (sensível) | Global + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | API externa de fornecedores |
| `/admin/api/v1` | Global + Encryption + Auth + AdminRole | API do painel administrativo |
| `/admin/api/v1` (sensível) | Global + Encryption + Auth + AdminRole + Confirmation | Operações administrativas sensíveis |

---

## 1. Endpoints Públicos

### Verificação de Saúde

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Status do Serviço

```
GET /api/v1/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### Produtos

```
GET /api/v1/products
  Parâmetros: category_id, region_id, keyword, supplier_id, page (padrão 1), page_size (padrão 20, máximo 50)
  → Lista paginada de produtos (inclui category, skus.regionPrices)

GET /api/v1/products/search
  Parâmetros: q (obrigatório), page
  → Busca em texto completo via Elasticsearch

GET /api/v1/products/{id}
  → Detalhes do produto (inclui category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → Lista de avaliações + avg_rating + total + distribution
  Enums de status: pending(aguardando revisão)/approved(aprovado)/rejected(rejeitado); apenas approved é retornado
```

### Domínios

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → Lista de TLDs disponíveis (cache Redis de 1h)
```

### Central de Ajuda

```
GET /api/v1/help
  Parâmetros: category, page
  Cabeçalho: Accept-Language (en-US / zh-CN)
  → Artigos de ajuda paginados

GET /api/v1/help/categories
  → Lista de categorias de artigos

GET /api/v1/help/{slug}
  → Detalhes de um artigo
```

---

## 2. Endpoints de Autenticação

### Captcha

```
POST /api/v1/captcha/create
  Cabeçalho: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registro

```
POST /api/v1/auth/register
  Cabeçalho: X-Encrypted: 1
  Corpo (criptografado): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Limite de frequência: 3 req/min
```

- `deviceFingerprint` (opcional): registra a impressão digital do dispositivo no cadastro, validada no login/refresh; se ausente, a vinculação de impressão digital é ignorada
- email/phone são criptografados antes do armazenamento com criptografia determinística via Encryptable (ECB, consulta por igualdade sobre o texto cifrado); tanto a validação de unicidade quanto a consulta de login usam o texto cifrado

### Login

```
POST /api/v1/auth/login
  Cabeçalho: X-Encrypted: 1
  Corpo (criptografado): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Limite de frequência: 5 req/min; 5 falhas bloqueiam por 15min
```

- `login` é consultado por igualdade sobre o texto cifrado (criptografia determinística via Encryptable); consultas em texto puro não encontram colunas criptografadas

### Atualização de Token

```
POST /api/v1/auth/refresh
  Cabeçalho: X-Encrypted: 1
  Corpo (criptografado): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` divergente do registrado no cadastro → 401 `Device mismatch`; o token de refresh é consultado por hash do texto cifrado

### OAuth

Provedores suportados: google, apple, facebook, x, microsoft, linkedin, github
(Habilitados conforme configuração como `{PROVIDER}_OAUTH_CLIENT_ID` no `.env`)

```
GET /api/v1/auth/{provider}            → { url }        # redireciona para a página de autorização (PKCE/nonce contra replay)
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  Corpo: { code, state }
```

- Apple/Microsoft retornam id_token; o servidor valida a assinatura via JWKS e iss/aud/exp/nonce
- Todos os provedores exigem `email_verified=true` para permitir o login; caso contrário, 422
- `state` ausente ou incompatível → 422 (anti-CSRF, expira em 5 minutos)
- Limite de frequência do fluxo OAuth: 10 vezes a cada 60 segundos (redirect + callback)

### Redefinição de Senha

```
POST /api/v1/auth/forgot-password
  Corpo: { email }
  → Envia e-mail com código de verificação

POST /api/v1/auth/reset-password
  Corpo: { email, code, password }
  → Redefinição bem-sucedida
  → 5 erros acumulados → 429, limite de 10 minutos
```

### Verificação de E-mail

```
GET /api/v1/auth/verify-email?token=xxx
  → Verificação bem-sucedida
```

### Verificação por SMS

```
POST /api/v1/auth/send-sms
  Corpo: { phone }
  → Envia código de verificação por SMS (cooldown de 60s)
```

### Verificação em Duas Etapas TOTP

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # não persistido; deve ser ativado via verify em até 10 minutos
POST /api/v1/user/totp/verify       Corpo: { code } → { verified: true }   # na primeira ativação, retorna mensagem de sucesso
POST /api/v1/user/totp/disable      Corpo: { password }             # exige confirmação de senha, caso contrário 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # gera 8 códigos de uso único por vez; exige confirmação de senha, caso contrário 403
POST /api/v1/auth/login/recovery    Corpo: { login, password, recovery_code }
```

- Após ativar o TOTP, o login do usuário deve incluir `totp_code`; caso contrário, 401
- 5 erros consecutivos de TOTP → usuário bloqueado por 15 minutos (login_lock)

---

## 3. Endpoints do Usuário (exigem autenticação)

### Perfil

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  Corpo: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC — Verificação de Identidade

```
POST /api/v1/user/kyc
  Corpo: { id_type, id_number, real_name, front_image, back_image }
```

### Saldo

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  Parâmetros: page
  → Histórico de alterações de saldo
```

### Gerenciamento de Endereços

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  Corpo: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### Gerenciamento de Sessões

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → Revoga a sessão especificada

DELETE /api/v1/user/account
  Corpo: { confirm_password }
  → Exclusão de conta conforme GDPR
```

### Notificações

```
GET /api/v1/user/notifications
  Parâmetros: page
  → Lista paginada de notificações

POST /api/v1/user/notifications/{id}/read
  → Marcar como lida

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  Corpo: { email: {order_paid: true, ...}, push: {...} }
```

### E-mail

```
POST /api/v1/user/resend-verify-email
  → Reenvia o e-mail de verificação
```

### Upload de Arquivos

```
POST /api/v1/upload
  Corpo: multipart/form-data { file, type: avatar/kyc/attach }
  Limites: avatar 2MB, kyc 5MB, attach 10MB
  Permitidos: jpg, jpeg, png, gif, pdf
  Observação: validação por lista de tipos permitidos + inspeção de conteúdo via finfo (extensão incompatível com MIME → 422)
```

---

## 4. Carrinho e Pedidos

### Carrinho

```
POST /api/v1/cart
  Corpo: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  Corpo: { quantity }
```

> Convenção de campos de valor (decisão D4/P4.2): todo valor monetário é obrigatoriamente string com 4 casas decimais (ex.: "9.9900"); proibido number/float —
> consistente com a saída crua das colunas DECIMAL do MySQL via PDO; a precisão é carregada pela própria string de 4dp. Aplica-se a todos os endpoints de pedidos/saldos/relatórios.

### Pedidos

```
POST /api/v1/orders
  → Cria o pedido a partir do carrinho
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  Parâmetros: page, status (pending/paid/provisioning/completed/refunded; valor inválido retorna 400)
  → Lista dos meus pedidos

GET /api/v1/orders/{id}
  → Detalhes do pedido (inclui items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → Canais de pagamento disponíveis + valor a pagar por canal

POST /api/v1/orders/{id}/pay    🔒 Confirmação de senha
  Corpo: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Cupons

```
POST /api/v1/coupons/validate
  Corpo: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (ex.: "2.0000")

422: inválido/expirado/não atende às condições de uso
```

### Faturas

```
GET /api/v1/invoices
  Parâmetros: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → Download do PDF
```

---

## 5. Gerenciamento de Recursos

```
GET /api/v1/resources
  Parâmetros: page, status
  → Lista dos meus recursos

GET /api/v1/resources/{id}
  → Detalhes do recurso

GET /api/v1/resources/{id}/status
  → Status atual do recurso + métricas

GET /api/v1/resources/{id}/console
  → URL do console VNC

POST /api/v1/resources/batch
  Corpo: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. Gerenciamento de DNS

```
GET /api/v1/dns/{domain}
  → Lista de registros DNS

POST /api/v1/dns/{domain}/records
  Corpo: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 Confirmação de senha
```

---

## 7. Tickets de Suporte

```
POST /api/v1/tickets
  Corpo: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  Parâmetros: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  Corpo: { content }
```

---

## 8. Fornecedores (API interna)

```
POST /api/v1/supplier/apply
  Corpo: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → Lista de liquidações

POST /api/v1/supplier/withdraw    🔒 Confirmação de senha
  Corpo: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  Corpo: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. API Externa de Fornecedores

**Autenticação:** `Authorization: Bearer sk_xxx...` (verificação de assinatura SHA256)

**Limite de frequência:** 120 req/min (saques: 10 req/min)

```
GET /api/v1/supplier/external/orders
  Parâmetros: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → Detalhes do pedido (apenas os relacionados a este fornecedor)

GET /api/v1/supplier/external/resources
  Parâmetros: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  Parâmetros: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  Corpo: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  Parâmetros: page
```

---

## 10. API do Painel Administrativo

**Autenticação:** JWT Bearer Token + papel de Administrador

### Painel

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Gerenciamento de Usuários

```
GET /admin/api/v1/users              Parâmetros: page, status, keyword
GET /admin/api/v1/users/export       → Download em Excel
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  Corpo: { status }
```

### Revisão de KYC

```
GET /admin/api/v1/kyc                Parâmetros: page, status

POST /admin/api/v1/kyc/{id}/approve  🔒 Confirmação de senha
  Corpo: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject   🔒 Confirmação de senha
  Corpo: { confirm_password, reason }
```

### Gerenciamento de Produtos

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 Confirmação de senha
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export          → Download em CSV
POST /admin/api/v1/products/import         → Upload de CSV com upsert
```

### Gerenciamento de Pedidos

```
GET /admin/api/v1/orders              Parâmetros: page, status, keyword
GET /admin/api/v1/orders/export       → Download em Excel
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund 🔒 Confirmação de senha
  Corpo: { confirm_password, amount?, reason }
```

### Gerenciamento de Pagamentos

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions  Parâmetros: page, channel, status
GET /admin/api/v1/payments/reconcile     Parâmetros: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run  Parâmetros: date; dispara a conciliação diária
```

### Recursos e Provisionamento

```
GET /admin/api/v1/provisioning/tasks              Parâmetros: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  Corpo: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 Confirmação de senha
GET /admin/api/v1/provisioning/hosts
```

### Gerenciamento de Fornecedores

```
GET /admin/api/v1/suppliers                 Parâmetros: page, status
GET /admin/api/v1/suppliers/export          → Download em Excel

POST /admin/api/v1/suppliers/{id}/approve   🔒 Confirmação de senha
POST /admin/api/v1/suppliers/{id}/settle    🔒 Confirmação de senha
  Corpo: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 Confirmação de senha
```

### API Key de Fornecedores

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  Corpo: { name }
  ← { api_key: "sk_xxx...", prefix } (exibido apenas uma vez)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### Gerenciamento de Tickets

```
GET /admin/api/v1/tickets                  Parâmetros: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign     Corpo: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### Gerenciamento de Domínios

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  Corpo: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones             Parâmetros: page
GET /admin/api/v1/domains/transfers         Parâmetros: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### Gerenciamento de Notificações

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  Corpo: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log         Parâmetros: page
```

### Cupons

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  Corpo: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### Artigos de Ajuda

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  Corpo: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → Exclusão lógica (status=archived)
```

### APIs de Provedores de Nuvem

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  Corpo: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → Desativação (status=disabled)
```

### Gerenciamento de Webhooks

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  Corpo: { url }
DELETE /admin/api/v1/webhooks               Corpo: { id }
POST /admin/api/v1/webhooks/test            Corpo: { url }
```

### Relatórios

```
GET /admin/api/v1/reports/revenue           Parâmetros: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (consistente com SUM(DECIMAL) e soma via bcmath)
GET /admin/api/v1/reports/supplier          Parâmetros: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region            Parâmetros: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Monitoramento

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Logs de Auditoria

```
GET /admin/api/v1/audit-logs                Parâmetros: page, user_id, action, from, to
  → Logs de auditoria paginados (inclui client_platform)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  Corpo: { action: enable/disable/toggle/reset }
```

### Configuração do Sistema

```
PUT /admin/api/v1/system/config             🔒 Confirmação de senha
```

### Importação e Exportação de Produtos

```
GET /admin/api/v1/products/export           → Download em CSV
POST /admin/api/v1/products/import          → Upload de CSV com upsert
```

### Exportação de Fornecedores e Usuários

```
GET /admin/api/v1/suppliers/export          → Download em Excel
GET /admin/api/v1/users/export              → Download em Excel
GET /admin/api/v1/orders/export             → Download em Excel
```

---

## 11. Certificados SSL

### Lado do cliente

```
GET /api/v1/ssl/plans
  → Lista de planos SSL (DV/OV/EV; preços incluem register/renew/transfer)

GET /api/v1/ssl-certs
  → Lista dos meus certificados (status: pending/active/expired/revoked)

GET /api/v1/ssl-certs/{id}
  → Detalhes do certificado (domínio, autoridade emissora, validade, status de renovação)

GET /api/v1/ssl-certs/{id}/download
  → Download dos arquivos do certificado (cadeia de certificados + chave privada)

POST /api/v1/ssl-certs/{id}/auto-renew
  Corpo: { auto_renew: true/false }
  → Alterna a renovação automática
```

### Lado administrativo

```
GET /admin/api/v1/ssl/plans              → Lista de planos
POST /admin/api/v1/ssl/plans             → Cria plano
PUT /admin/api/v1/ssl/plans/{id}         → Atualiza plano
DELETE /admin/api/v1/ssl/plans/{id}      → Exclui plano
GET /admin/api/v1/ssl/certs              → Todos os certificados
POST /admin/api/v1/ssl/certs/{id}/revoke → Revoga certificado
```

---

## 12. Armazenamento de Objetos

Armazenamento de objetos compatível com S3; upload/download via URLs pré-assinadas, sem exposição de chaves.

```
GET /api/v1/storage/buckets
  → Lista dos meus buckets (uso, status)

GET /api/v1/storage/buckets/{id}
  → Detalhes do bucket

POST /api/v1/storage/buckets/{id}/presign-upload
  Corpo: { filename, content_type, size }
  → { upload_url, object_key } URL de upload pré-assinada (válida por tempo limitado)

POST /api/v1/storage/buckets/{id}/presign-download
  Corpo: { object_key }
  → URL de download pré-assinada (válida por tempo limitado)

GET /api/v1/storage/buckets/{id}/credentials
  → Credenciais temporárias de acesso (curta duração, para envio direto via SDK)
```

---

## 13. Aceleração CDN

### Lado do cliente

```
GET /api/v1/cdn/domains
  → Lista dos meus domínios CDN (origem, status, plano)

POST /api/v1/cdn/domains
  Corpo: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
           origin_type (server|storage), origin_value, cert_config? }
  → Cria um domínio CDN (cria no provedor e vincula a origem)
  → Para provider_type=aliyun|tencent o domínio precisa de registro ICP (sem registro retorna 4002)
  → A resposta inclui o campo de aviso requires_icp_registration
  → Resolução de credenciais: primeiro a conta vinculada ao domínio (provider_account_id);
    senão a conta provider_apis ativa com code=cdn-{provider_type}; sem nenhuma,
    fallback para configuração de env

GET /api/v1/cdn/domains/{id}
  → Detalhes do domínio CDN

DELETE /api/v1/cdn/domains/{id}
  → Exclui o domínio CDN (desativa o domínio no provedor, idempotente)

POST /api/v1/cdn/domains/{id}/purge
  Corpo: { urls: ["https://cdn.example.com/path"] }
  → Limpa o cache (URLs duplicadas deduplicadas automaticamente, idempotente; máx. 100)

GET /api/v1/cdn/domains/{id}/stats
  → Visão geral do domínio (cdn_domain / provider_type / plan / status / purged_at)
```

### Lado administrativo

```
GET /admin/api/v1/cdn/domains            → Todos os domínios CDN (incluindo o usuário dono)
PUT /admin/api/v1/cdn/domains/{id}       → Atualiza o plano do domínio (whitelist de planos: standard | pro | enterprise)
```

As rotas admin de CDN usam `RbacMiddleware('cdn.manage')`, e alterações de plano são gravadas no log de auditoria (`admin_cdn_update_plan`). As credenciais das contas de provedores são mantidas via CRUD em `/admin/api/v1/providers` (RbacMiddleware `provider.config`, `code` convencionado como `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, credenciais criptografadas no banco com Encryptable).

### Códigos de erro CDN

| code | Descrição |
|------|-----------|
| 4001 | Parâmetro CDN ausente/inválido (urls vazio, provider_type inválido, formato de domínio incorreto) |
| 4002 | Domínio sem registro ICP (mapeado quando a API Aliyun/Tencent rejeita) |
| 4003 | Credenciais do provedor CDN não configuradas (conta ausente/desabilitada, snapshot estrito sem troca silenciosa) |
| 4005 | Falha ao purgar o cache CDN |
| 5001 | Falha na chamada à API do provedor CDN |

> Recursos CDN de outros usuários (alheios/inexistentes) retornam **404** uniformemente (mapeamento findOrFail, sem vazar a existência do recurso), sem código de negócio próprio.

---

## 14. Cobrança por Uso

```
GET /admin/api/v1/billing/rates          → Lista de tarifas (por tipo de recurso/especificação)
POST /admin/api/v1/billing/rates         → Cria tarifa
PUT /admin/api/v1/billing/rates/{id}     → Atualiza tarifa
DELETE /admin/api/v1/billing/rates/{id}  → Exclui tarifa
GET /admin/api/v1/billing/usage          → Resumo de uso (agregado por usuário/recurso)
```

Pipeline de cobrança: ResourceMonitor coleta a cada 5 minutos → UsageAggregator agrega a cada hora → BillingEngine debita diariamente; saldo insuficiente suspende o recurso.

---

## 15. Comissão de Afiliados (Affiliate)

### Lado do cliente

```
GET /api/v1/affiliate/summary
  → Resumo de comissões (acumulado/aguardando liquidação/retirável, número de links, taxa de conversão)

POST /api/v1/affiliate/links
  Corpo: { source? }
  → Gera link de divulgação (?ref=CODE)

GET /api/v1/affiliate/earnings
  Parâmetros: status, page
  → Detalhes de comissões (atribuição do pedido, percentual, status: pending/approved/paid)

POST /api/v1/affiliate/payout
  Corpo: { amount, method }
  → Solicita saque
```

### Lado administrativo

```
GET /admin/api/v1/affiliate/plans                → Lista de planos de comissão
POST /admin/api/v1/affiliate/plans               → Cria plano de comissão
GET /admin/api/v1/affiliate/earnings             → Todos os registros de comissão
POST /admin/api/v1/affiliate/earnings/{id}/approve → Revisa comissão
GET /admin/api/v1/affiliate/payouts              → Lista de solicitações de saque
POST /admin/api/v1/affiliate/payouts/{id}/approve → Revisa/efetua o pagamento do saque
```

---

## 16. GraphQL

```
POST /graphql
  → Consultas públicas (produtos, domínios, ajuda e outros dados somente leitura)
  Limites: profundidade de consulta de 5 níveis, complexidade 100

POST /api/v1/graphql                          🔒 Exige autenticação
  → Consultas completas (incluindo dados do usuário)
```

**Operações sensíveis permanecem exclusivas de REST:** pagamento, saque, reembolso e revisão de KYC não passam pelo GraphQL.

---

## 17. Avaliações de Fornecedores e de Produtos

### Público

```
GET /api/v1/regions
  → Lista de regiões disponíveis (inclui moeda/fuso horário)

GET /api/v1/suppliers/{supplierId}/ratings
  → Lista de avaliações do fornecedor (quatro dimensões: qualidade/suporte/velocidade de entrega/custo-benefício; apenas approved é retornado)
```

### Lado do cliente (exige autenticação)

```
POST /api/v1/products/{productId}/reviews
  Corpo: { rating, content, images? }
  → Envia avaliação de produto (uma por pedido; exibida após revisão)

POST /api/v1/supplier/ratings
  Corpo: { supplier_id, quality, support, delivery_speed, value, comment? }
  → Envia avaliação de fornecedor (uma por pedido)

GET /api/v1/supplier/ratings/me
  → Meus registros de avaliação
```

### Lado administrativo

```
GET /admin/api/v1/suppliers/{id}/ratings          → Todas as avaliações (inclui pending)
POST /admin/api/v1/suppliers/ratings/{id}/approve → Aprova
POST /admin/api/v1/suppliers/ratings/{id}/hide    → Oculta
```

---

## 18. Webhook de Pagamento

```
POST /api/v1/payments/webhook/stripe
  Cabeçalho: Stripe-Signature: ...
  → Callback do Stripe (pagamento aprovado/reembolso/contestação); falha na verificação da assinatura retorna 400
```

---

## 19. Eventos WebSocket

**Conexão:** `ws://host:8282` (em implantação docker, o WS passa por proxy reverso nginx; o endereço de conexão é `ws://host/ws/`, e a 8282 é exposta apenas dentro do container)

A autenticação ocorre pela primeira mensagem após a conexão (o token não vai na URL nem nos logs de acesso): após estabelecer a conexão, é necessário enviar a mensagem `auth`; conexões não autenticadas em 30 segundos são encerradas; falha na autenticação retorna `error` e encerra a conexão.

### Cliente → Servidor

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Servidor → Cliente

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Eventos enviados ao cliente

| Evento | Dados | Momento de disparo |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Pagamento aprovado |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Provisionamento do recurso concluído |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | Recurso prestes a expirar |
| `ticket.updated` | `{ticket_id, title, status}` | Alteração de status do ticket |
| `notification.new` | `{notification_id, title, body}` | Nova notificação |

---

## 20. Referência de Códigos de Erro

| code | Descrição |
|------|------|
| 400 | Erro de parâmetro / versão de API não suportada / plataforma de cliente não suportada |
| 401 | Não autenticado / Token expirado / API Key inválida / impressão digital do dispositivo incompatível (Device mismatch) |
| 403 | Sem permissão / papel não é de fornecedor / bloqueado pelo WAF / falha na confirmação de senha |
| 404 | Recurso não encontrado (falha de firstOrFail/findOrFail mapeada uniformemente para 404) |
| 413 | Corpo da requisição acima de 10MB |
| 414 | URL acima de 2KB |
| 415 | Content-Type fora da lista permitida (apenas application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | Falha na validação de parâmetros (e-mail já registrado / estoque insuficiente / saldo retirável insuficiente / solicitação já enviada) |
| 429 | Excedeu o limite de frequência de requisições |
| 500 | Erro do servidor |

### Mensagens 422 comuns

| Mensagem | Endpoint |
|------|------|
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
