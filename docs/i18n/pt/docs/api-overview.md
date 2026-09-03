# Visão Geral da API

> Referência completa da API (200+ endpoints, com exemplos de requisição/resposta e códigos de erro): [Documentação da API](api-reference.md)
> Depuração online: [documentação da API do service](http://localhost:8787/apidoc) · [documentação da API do admin](http://localhost:8788/apidoc)

## Interfaces Públicas

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/health` | Verificação de saúde |
| POST | `/api/v1/auth/register` | Registro de usuário (corpo da requisição precisa de criptografia AES-256-GCM) |
| POST | `/api/v1/auth/login` | Login de usuário (corpo da requisição precisa de criptografia AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Renovação de Token (corpo da requisição precisa de criptografia AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Gerar captcha de clique (obter antes do login/registro) |
| GET | `/api/v1/products` | Lista de produtos (com filtros por categoria/região/palavra-chave) |
| GET | `/api/v1/products/{id}` | Detalhes do produto (id é uma string hashid) |
| GET | `/api/v1/regions` | Regiões disponíveis |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Consulta de disponibilidade de domínio |
| GET | `/api/v1/domain/tlds` | Lista de sufixos registráveis |
| POST | `/api/v1/payments/webhook/stripe` | Callback do Stripe (validação de assinatura, sem criptografia) |

## Interfaces Autenticadas (exigem Bearer Token)

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/api/v1/user/profile` | Informações pessoais |
| PUT | `/api/v1/user/profile` | Atualizar informações |
| POST | `/api/v1/user/kyc` | Enviar verificação de identidade (KYC) |
| GET | `/api/v1/user/balance` | Saldo da conta |
| GET/POST | `/api/v1/cart` | Carrinho de compras |
| POST/GET | `/api/v1/orders` | Pedidos |
| GET | `/api/v1/orders/{id}/payment-methods` | Métodos de pagamento disponíveis |
| POST | `/api/v1/orders/{id}/pay` | Iniciar pagamento |
| GET/POST | `/api/v1/resources` | Meus recursos |
| GET | `/api/v1/resources/{id}/status` | Status do recurso |
| GET | `/api/v1/resources/{id}/console` | Link do console VNC |
| GET/POST | `/api/v1/cdn/domains` | Lista / criação de domínios CDN (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | Detalhes / exclusão de domínio CDN |
| POST | `/api/v1/cdn/domains/{id}/purge` | Purga de cache (idempotente, máx. 100 URLs) |
| GET/POST | `/api/v1/tickets` | Tickets de suporte |
| POST | `/api/v1/tickets/{id}/reply` | Responder ticket |
| GET/POST | `/api/v1/dns/{domain}` | Gerenciamento de DNS |
| POST | `/api/v1/supplier/apply` | Solicitação de fornecedor |
| GET | `/api/v1/supplier/settlements` | Registros de liquidação do fornecedor |
| POST | `/api/v1/supplier/withdraw` | Saque do fornecedor |

> **Observação:** todas as requisições à API especificam a versão no caminho da URL (por exemplo, `/api/v1/products`). As requisições/respostas das interfaces autenticadas e administrativas passam pelo `EncryptionMiddleware`. O cliente define o cabeçalho `X-Encrypted: 1` e o corpo da requisição segue o formato `{"payload": "<base64(AES-256-GCM)>"}`; o corpo da resposta também é criptografado e envolvido no campo `payload`. Todos os IDs inteiros são convertidos automaticamente em strings Hashid de 12 caracteres nas respostas da API, e as strings Hashid nas requisições são decodificadas automaticamente de volta para IDs inteiros pelo `HashidRequestMiddleware`.

## Interfaces de Administração

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Dashboard operacional |
| GET/PUT | `/admin/api/v1/users` | Gestão de usuários |
| GET/POST | `/admin/api/v1/kyc` | Revisão de KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Gestão de produtos |
| POST | `/admin/api/v1/products/{productId}/skus` | Criar SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Definir preço regional |
| GET/POST | `/admin/api/v1/orders` | Gestão de pedidos (inclui reembolso) |
| GET | `/admin/api/v1/orders/export` | Exportação de pedidos (.xlsx) |
| GET | `/admin/api/v1/users/export` | Exportação de usuários (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Exportação de fornecedores (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Canais de pagamento / transações / conciliação |
| GET/POST | `/admin/api/v1/provisioning/*` | Tarefas de entrega / gestão de hosts |
| GET/PUT | `/admin/api/v1/cdn/domains` | Gestão de domínios CDN (alteração de plano) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Gestão de credenciais de contas de provedores (compartilhado CDN/entrega, criptografado com Encryptable) |
| GET/POST | `/admin/api/v1/suppliers/*` | Aprovação de fornecedores / liquidação / saques |
| GET/POST | `/admin/api/v1/tickets` | Atribuição / fechamento de tickets |
| GET | `/admin/api/v1/reports/*` | Relatórios de receita / região / fornecedores |
| GET | `/admin/api/v1/monitor/*` | Painel de monitoramento / métricas de recursos |
| GET | `/admin/api/v1/audit-logs` | Logs de auditoria |
| PUT | `/admin/api/v1/system/config` | Configuração do sistema |
