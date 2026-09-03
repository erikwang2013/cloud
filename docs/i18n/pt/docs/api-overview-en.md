# Visão Geral da API

> Referência completa da API (200+ endpoints, com exemplos de requisição/resposta e códigos de erro): [Documentação da API](api-reference.md)
> Depuração online: [documentação da API do service](http://localhost:8787/apidoc) · [documentação da API do admin](http://localhost:8788/apidoc)

## Endpoints Públicos

| Método | Caminho | Descrição |
|--------|------|-------------|
| GET | `/health` | Verificação de saúde |
| POST | `/api/v1/auth/register` | Registro (corpo criptografado com AES-256-GCM) |
| POST | `/api/v1/auth/login` | Login (corpo criptografado com AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Renovar token (corpo criptografado com AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Gerar CAPTCHA de clique (necessário antes do login/registro) |
| GET | `/api/v1/products` | Listagem de produtos (filtrável por categoria/região/palavra-chave) |
| GET | `/api/v1/products/{id}` | Detalhes do produto (id é uma string hashid) |
| GET | `/api/v1/regions` | Regiões disponíveis |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Verificação de disponibilidade de domínio |
| GET | `/api/v1/domain/tlds` | TLDs disponíveis |
| POST | `/api/v1/payments/webhook/stripe` | Webhook do Stripe (assinatura verificada, sem criptografia) |

## Endpoints Autenticados (Bearer Token)

| Método | Caminho | Descrição |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Obter perfil |
| PUT | `/api/v1/user/profile` | Atualizar perfil |
| POST | `/api/v1/user/kyc` | Enviar KYC |
| GET | `/api/v1/user/balance` | Saldo da conta |
| GET/POST | `/api/v1/cart` | Carrinho de compras |
| POST/GET | `/api/v1/orders` | Pedidos |
| GET | `/api/v1/orders/{id}/payment-methods` | Métodos de pagamento disponíveis |
| POST | `/api/v1/orders/{id}/pay` | Iniciar pagamento |
| GET/POST | `/api/v1/resources` | Meus recursos |
| GET | `/api/v1/resources/{id}/status` | Status do recurso |
| GET | `/api/v1/resources/{id}/console` | URL do console VNC |
| GET/POST | `/api/v1/tickets` | Tickets de suporte |
| POST | `/api/v1/tickets/{id}/reply` | Responder ao ticket |
| GET/POST | `/api/v1/dns/{domain}` | Gerenciamento de DNS |
| POST | `/api/v1/supplier/apply` | Candidatar-se como fornecedor |
| GET | `/api/v1/supplier/settlements` | Histórico de liquidações |
| POST | `/api/v1/supplier/withdraw` | Solicitar saque |

> **Observação:** todas as requisições à API especificam a versão no caminho da URL (por exemplo, `/api/v1/products`). Os endpoints autenticados e de administração são processados pelo `EncryptionMiddleware`. Os clientes definem o cabeçalho `X-Encrypted: 1` e envolvem o corpo como `{"payload": "<base64(AES-256-GCM)>"}`. As respostas também são criptografadas e envolvidas em um campo `payload`. IDs inteiros nas respostas da API são convertidos automaticamente em strings Hashid de 12 caracteres; strings Hashid nas requisições são decodificadas de volta para IDs inteiros pelo `HashidRequestMiddleware`.

## Endpoints de Administração

| Método | Caminho | Descrição |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Dashboard de operações |
| GET/PUT | `/admin/api/v1/users` | Gestão de usuários |
| GET/POST | `/admin/api/v1/kyc` | Revisão de KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Gestão de produtos |
| POST | `/admin/api/v1/products/{productId}/skus` | Criar SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Definir preço regional |
| GET/POST | `/admin/api/v1/orders` | Gestão de pedidos (inclui reembolsos) |
| GET | `/admin/api/v1/orders/export` | Exportar pedidos (.xlsx) |
| GET | `/admin/api/v1/users/export` | Exportar usuários (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Exportar fornecedores (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Canais / transações / conciliação |
| GET/POST | `/admin/api/v1/provisioning/*` | Tarefas de provisionamento / gestão de hosts |
| GET/POST | `/admin/api/v1/suppliers/*` | Aprovação de fornecedores / liquidação / saque |
| GET/POST | `/admin/api/v1/tickets` | Atribuição / fechamento de tickets |
| GET | `/admin/api/v1/reports/*` | Relatórios de receita / regional / fornecedores |
| GET | `/admin/api/v1/monitor/*` | Dashboard de monitoramento / métricas de recursos |
| GET | `/admin/api/v1/audit-logs` | Logs de auditoria |
| PUT | `/admin/api/v1/system/config` | Atualização de configuração do sistema |
