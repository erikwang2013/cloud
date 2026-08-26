# Visão Geral da API

> Referência completa da API (200+ endpoints, com exemplos de requisição/resposta e códigos de erro): [Documentação da API](api-reference.md)
> Depuração online: [documentação da API do service](http://localhost:8787/apidoc) · [documentação da API do admin](http://localhost:8788/apidoc)

## Endpoints Públicos

| Método | Caminho | Descrição |
|--------|------|-------------|
| GET | `/health` | Verificação de saúde |
| POST | `/api/auth/register` | Registro (corpo criptografado com AES-256-GCM) |
| POST | `/api/auth/login` | Login (corpo criptografado com AES-256-GCM) |
| POST | `/api/auth/refresh` | Renovar token (corpo criptografado com AES-256-GCM) |
| POST | `/api/captcha/create` | Gerar CAPTCHA de clique (necessário antes do login/registro) |
| GET | `/api/products` | Listagem de produtos (filtrável por categoria/região/palavra-chave) |
| GET | `/api/products/{id}` | Detalhes do produto (id é uma string hashid) |
| GET | `/api/regions` | Regiões disponíveis |
| GET | `/api/domain/check/{domain}/{tld}` | Verificação de disponibilidade de domínio |
| GET | `/api/domain/tlds` | TLDs disponíveis |
| POST | `/api/payments/webhook/stripe` | Webhook do Stripe (assinatura verificada, sem criptografia) |

## Endpoints Autenticados (Bearer Token)

| Método | Caminho | Descrição |
|--------|------|-------------|
| GET | `/api/user/profile` | Obter perfil |
| PUT | `/api/user/profile` | Atualizar perfil |
| POST | `/api/user/kyc` | Enviar KYC |
| GET | `/api/user/balance` | Saldo da conta |
| GET/POST | `/api/cart` | Carrinho de compras |
| POST/GET | `/api/orders` | Pedidos |
| GET | `/api/orders/{id}/payment-methods` | Métodos de pagamento disponíveis |
| POST | `/api/orders/{id}/pay` | Iniciar pagamento |
| GET/POST | `/api/resources` | Meus recursos |
| GET | `/api/resources/{id}/status` | Status do recurso |
| GET | `/api/resources/{id}/console` | URL do console VNC |
| GET/POST | `/api/tickets` | Tickets de suporte |
| POST | `/api/tickets/{id}/reply` | Responder ao ticket |
| GET/POST | `/api/dns/{domain}` | Gerenciamento de DNS |
| POST | `/api/supplier/apply` | Candidatar-se como fornecedor |
| GET | `/api/supplier/settlements` | Histórico de liquidações |
| POST | `/api/supplier/withdraw` | Solicitar saque |

> **Observação:** todas as requisições à API devem incluir o cabeçalho `X-Api-Version: v1` (o padrão é `v1` se omitido, validado pelo `VersionMiddleware`). Os endpoints autenticados e de administração são processados pelo `EncryptionMiddleware`. Os clientes definem o cabeçalho `X-Encrypted: 1` e envolvem o corpo como `{"payload": "<base64(AES-256-GCM)>"}`. As respostas também são criptografadas e envolvidas em um campo `payload`. IDs inteiros nas respostas da API são convertidos automaticamente em strings Hashid de 12 caracteres; strings Hashid nas requisições são decodificadas de volta para IDs inteiros pelo `HashidRequestMiddleware`.

## Endpoints de Administração

| Método | Caminho | Descrição |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Dashboard de operações |
| GET/PUT | `/admin/api/users` | Gestão de usuários |
| GET/POST | `/admin/api/kyc` | Revisão de KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Gestão de produtos |
| POST | `/admin/api/products/{productId}/skus` | Criar SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Definir preço regional |
| GET/POST | `/admin/api/orders` | Gestão de pedidos (inclui reembolsos) |
| GET | `/admin/api/orders/export` | Exportar pedidos (.xlsx) |
| GET | `/admin/api/users/export` | Exportar usuários (.xlsx) |
| GET | `/admin/api/suppliers/export` | Exportar fornecedores (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Canais / transações / conciliação |
| GET/POST | `/admin/api/provisioning/*` | Tarefas de provisionamento / gestão de hosts |
| GET/POST | `/admin/api/suppliers/*` | Aprovação de fornecedores / liquidação / saque |
| GET/POST | `/admin/api/tickets` | Atribuição / fechamento de tickets |
| GET | `/admin/api/reports/*` | Relatórios de receita / regional / fornecedores |
| GET | `/admin/api/monitor/*` | Dashboard de monitoramento / métricas de recursos |
| GET | `/admin/api/audit-logs` | Logs de auditoria |
| PUT | `/admin/api/system/config` | Atualização de configuração do sistema |
