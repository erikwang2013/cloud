# Documentação da API de Fornecedores v1

## Visão Geral

A funcionalidade de fornecedores oferece dois conjuntos de APIs:

| Tipo | Autenticação | Prefixo | Status |
|------|---------|------|------|
| **API interna** | Bearer Token do usuário | `/api/v1/supplier/` | Disponível |
| **API externa** | API Key (`sk_xxx`) | `/api/v1/supplier/external/` | Disponível |

**Base URL**: `https://api.example.com`

**Versionamento**: especificado no caminho da URL (por exemplo, `/api/v1/supplier/products`). Versões não suportadas retornam `400`. Aplica-se apenas aos caminhos `/api/v1/*` e `/admin/api/v1/*`, tratado de forma unificada pelo `VersionMiddleware`.

---

## API Interna (atualmente disponível)

A API interna usa a mesma autenticação por Bearer Token de usuário das demais interfaces da plataforma, destinada a fornecedores já autenticados em chamadas no cliente/frontend.

### Autenticação

```
Authorization: Bearer <user_access_token>
```

O usuário deve primeiro obter o Token via `/api/v1/auth/login`, e o papel da conta deve ser `supplier` (definido quando o administrador aprova a solicitação de fornecedor).

---

### Formato de Resposta

#### Resposta de Sucesso

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Resposta Paginada

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### Resposta de Erro

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Descrição |
|------|------|
| 0 | Sucesso |
| 400 | Erro nos parâmetros da requisição / versão de API não suportada |
| 401 | Não autenticado ou Token expirado |
| 403 | Sem permissão (papel diferente de fornecedor / falha na confirmação de senha) |
| 404 | Recurso não encontrado |
| 422 | Falha na validação de parâmetros |
| 429 | Excedeu o limite de frequência |

---

### Endpoints

#### 1. Cadastro de Fornecedor

```
POST /api/v1/supplier/apply
```

Solicitar para se tornar fornecedor. Cada usuário só pode enviar uma solicitação.

**Corpo da requisição**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Parâmetro | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| company_name | string | sim | Nome da empresa |
| contact_name | string | sim | Nome do contato |
| contact_phone | string | sim | Telefone de contato |
| contact_email | string | sim | Email de contato |
| settlement_method | string | não | Forma de liquidação, padrão `bank` |

**Resposta**: objeto do fornecedor, com status `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> Campos sensíveis (nome do contato, telefone, email) são armazenados criptografados no banco e retornam parcialmente mascarados na API.

**Erros**:

| code | Cenário |
|------|------|
| 422 | Solicitação de fornecedor já enviada |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Gerenciamento de Produtos

##### Obter Produtos Atribuídos

```
GET /api/v1/supplier/products
```

**Parâmetros Query**:

| Parâmetro | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| page | int | não | Número da página, padrão 1 |

**Resposta**: lista paginada; cada item contém as informações do produto e a taxa de comissão

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### Adicionar Produto

```
POST /api/v1/supplier/products
```

Associa um produto existente ao fornecedor atual.

**Corpo da requisição**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Parâmetro | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| product_id | string | sim | ID do produto (Hashid) |
| commission_rate | float | não | Taxa de comissão, padrão 0.1 |

**Resposta**: objeto SupplierProduct criado

**Erros**:

| code | Cenário |
|------|------|
| 422 | Produto já atribuído a este fornecedor |

##### Remover Produto

```
DELETE /api/v1/supplier/products/{id}
```

Cancela a associação entre produto e fornecedor.

**Resposta**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Gerenciamento de Liquidações

##### Obter Lista de Liquidações

```
GET /api/v1/supplier/settlements
```

**Resposta**: todas as liquidações do fornecedor atual, em ordem decrescente de criação

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| Campo | Descrição |
|------|------|
| total_sales | Vendas totais dos pedidos concluídos no período |
| commission | Comissão total da plataforma |
| payable | Valor a pagar ao fornecedor (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Saque

##### Solicitar Saque

```
POST /api/v1/supplier/withdraw
```

> Esta operação exige confirmação de senha secundária (campo `confirm_password`), verificada pelo `ConfirmationMiddleware`.
> Após 5 falhas, bloqueio de 15 minutos.

**Corpo da requisição**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| Parâmetro | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| amount | string | sim | Valor do saque (string para evitar problemas de precisão de ponto flutuante) |
| confirm_password | string | sim | Senha de login do usuário (confirmação secundária) |
| account_info | object | sim | Informações da conta de recebimento |
| account_info.method | string | sim | Forma de saque: `bank_transfer` / `alipay` / `wechat` |

**Cálculo do saldo sacável**: soma de `payable` de todas as liquidações concluídas - soma de `amount` de todos os saques em andamento

**Resposta**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Erros**:

| code | Cenário |
|------|------|
| 422 | Saldo sacável insuficiente |
| 403 | Falha na confirmação de senha |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Resumo dos Endpoints da API Interna

| Método | Caminho | Autenticação | Confirmação de senha | Descrição |
|------|------|------|---------|------|
| POST | `/api/v1/supplier/apply` | Token | - | Solicitar para se tornar fornecedor |
| GET | `/api/v1/supplier/products` | Token | - | Ver produtos atribuídos |
| POST | `/api/v1/supplier/products` | Token | - | Adicionar associação de produto |
| DELETE | `/api/v1/supplier/products/{id}` | Token | - | Remover associação de produto |
| GET | `/api/v1/supplier/settlements` | Token | - | Ver liquidações |
| POST | `/api/v1/supplier/withdraw` | Token | sim | Solicitar saque |

---

## API Externa (especificação de design, a implementar)

A API externa permite que fornecedores gerenciem pedidos, recursos e liquidações de forma programática. Todas as requisições exigem autenticação por API Key.

**Base URL**: `https://api.example.com/api/v1`

### Autenticação

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

A API Key é gerada pelo administrador da plataforma no painel administrativo, em `Gerenciamento de Fornecedores → API Keys`.

**Requisitos de segurança**:
- Acesso apenas via HTTPS
- A API Key é exibida apenas uma vez na criação; guarde-a com segurança
- Recomenda-se adicionar o IP do servidor à lista de permissões

---

### Formato de Resposta

Igual ao da API interna, com `request_id` adicional para rastreamento:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Endpoints

#### 1. Gerenciamento de Pedidos

##### Obter Lista de Pedidos

```
GET /api/v1/supplier/orders
```

**Parâmetros Query**:

| Parâmetro | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| page | int | não | Número da página, padrão 1 |
| page_size | int | não | Itens por página, padrão 20, máximo 50 |
| status | string | não | Filtrar por status: pending/paid/completed/refunded |
| from | date | não | Data inicial YYYY-MM-DD |
| to | date | não | Data final YYYY-MM-DD |

##### Obter Detalhes do Pedido

```
GET /api/v1/supplier/orders/{id}
```

---

#### 2. Gerenciamento de Recursos

##### Obter Lista de Recursos

```
GET /api/v1/supplier/resources
```

**Parâmetros Query**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Obter Status do Recurso

```
GET /api/v1/supplier/resources/{id}/status
```

---

#### 3. Gerenciamento de Liquidações

##### Obter Lista de Liquidações

```
GET /api/v1/supplier/settlements
```

##### Obter Detalhes da Liquidação

```
GET /api/v1/supplier/settlements/{id}
```

---

#### 4. Saque

##### Solicitar Saque

```
POST /api/v1/supplier/withdraw
```

##### Histórico de Saques

```
GET /api/v1/supplier/withdraws
```

---

#### 5. Gerenciamento de Produtos

##### Obter Meus Produtos

```
GET /api/v1/supplier/products
```

##### Enviar Solicitação de Listagem de Produto

```
POST /api/v1/supplier/products
```

---

### Resumo dos Endpoints da API Externa

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/api/v1/supplier/orders` | Lista de pedidos |
| GET | `/api/v1/supplier/orders/{id}` | Detalhes do pedido |
| GET | `/api/v1/supplier/resources` | Lista de recursos |
| GET | `/api/v1/supplier/resources/{id}/status` | Status do recurso |
| GET | `/api/v1/supplier/settlements` | Lista de liquidações |
| GET | `/api/v1/supplier/settlements/{id}` | Detalhes da liquidação |
| POST | `/api/v1/supplier/withdraw` | Solicitar saque |
| GET | `/api/v1/supplier/withdraws` | Histórico de saques |
| GET | `/api/v1/supplier/products` | Lista de produtos |
| POST | `/api/v1/supplier/products` | Enviar produto |

---

## Webhook (recebimento de eventos da plataforma)

Os fornecedores podem registrar uma URL de Webhook para receber eventos em tempo real. Configurado no painel administrativo.

### Tipos de Evento

| Evento | Momento do disparo |
|------|----------|
| `order.paid` | Usuário conclui o pagamento |
| `order.refunded` | Pedido reembolsado |
| `resource.provisioned` | Provisionamento do recurso concluído |
| `resource.expiring` | Recurso próximo da expiração (em até 7 dias) |
| `resource.destroyed` | Recurso destruído |
| `settlement.created` | Liquidação gerada |
| `withdrawal.approved` | Saque aprovado |

### Formato da Requisição Webhook

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**Verificação de assinatura**: `HMAC-SHA256(payload, webhook_secret)`

---

## Limite de Frequência

| Endpoint | Limite |
|------|------|
| API interna | 60 req/min por usuário (padrão) |
| Login da API interna | 5 req/min |
| API externa | 120 req/min por API Key (regra `supplier_api`, aplicada via `RateLimitMiddleware`) |
| Saque da API externa | 10 req/min (valor sugerido, ajustável em `config/security.php`) |

> A regra de limite da API externa é definida em `rate_limits.supplier_api` no `config/security.php`,
> aplicada de forma unificada pelo `RateLimitMiddleware` aos caminhos `/api/v1/supplier/external/*` (contagem atômica com INCR;
> quando o Redis está indisponível, o tráfego é liberado).

Cabeçalhos de limite:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## Exemplos de SDK

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/v1/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
    ],
]);

// Solicitar para se tornar fornecedor
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// Obter liquidações
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// Solicitar saque
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
}

# Obter produtos atribuídos
resp = requests.get('https://api.example.com/api/v1/supplier/products',
                     headers=headers)
products = resp.json()

# Solicitar saque
resp = requests.post('https://api.example.com/api/v1/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## Recomendações de Tratamento de Erros

1. **429 limite de frequência**: aguarde o número de segundos indicado em `Retry-After` e tente novamente
2. **401 não autorizado**: verifique se o Token é válido e se não expirou
3. **403 proibido**: verifique se o papel da conta é `supplier`; falha na confirmação de senha exige aguardar o fim do bloqueio
4. **422 falha de validação**: corrija os parâmetros da requisição conforme o campo `message`
5. **5xx erro de servidor**: retry com backoff exponencial (1s -> 5s -> 25s)

---

## Referência dos Endpoints do Painel Administrativo

Abaixo estão os endpoints de administração de fornecedores (somente para uso no backend, exige papel Admin):

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/admin/api/v1/suppliers` | Lista de fornecedores (suporta filtro por status) |
| GET | `/admin/api/v1/suppliers/export` | Exportar fornecedores em Excel |
| POST | `/admin/api/v1/suppliers/{id}/approve` | Aprovar fornecedor |
| POST | `/admin/api/v1/suppliers/{id}/settle` | Gerar liquidação |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | Aprovar saque |
| GET | `/admin/api/v1/suppliers/{id}/api-keys` | Ver lista de API Keys do fornecedor |
| POST | `/admin/api/v1/suppliers/{id}/api-keys` | Criar API Key (a Key original é retornada apenas uma vez) |
| DELETE | `/admin/api/v1/suppliers/api-keys/{id}` | Revogar API Key |
