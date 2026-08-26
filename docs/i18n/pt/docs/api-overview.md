# Visão Geral da API

> Referência completa da API (200+ endpoints, com exemplos de requisição/resposta e códigos de erro): [Documentação da API](api-reference.md)
> Depuração online: [documentação da API do service](http://localhost:8787/apidoc) · [documentação da API do admin](http://localhost:8788/apidoc)

## Interfaces Públicas

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/health` | Verificação de saúde |
| POST | `/api/auth/register` | Registro de usuário (corpo da requisição precisa de criptografia AES-256-GCM) |
| POST | `/api/auth/login` | Login de usuário (corpo da requisição precisa de criptografia AES-256-GCM) |
| POST | `/api/auth/refresh` | Renovação de Token (corpo da requisição precisa de criptografia AES-256-GCM) |
| POST | `/api/captcha/create` | Gerar captcha de clique (obter antes do login/registro) |
| GET | `/api/products` | Lista de produtos (com filtros por categoria/região/palavra-chave) |
| GET | `/api/products/{id}` | Detalhes do produto (id é uma string hashid) |
| GET | `/api/regions` | Regiões disponíveis |
| GET | `/api/domain/check/{domain}/{tld}` | Consulta de disponibilidade de domínio |
| GET | `/api/domain/tlds` | Lista de sufixos registráveis |
| POST | `/api/payments/webhook/stripe` | Callback do Stripe (validação de assinatura, sem criptografia) |

## Interfaces Autenticadas (exigem Bearer Token)

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/api/user/profile` | Informações pessoais |
| PUT | `/api/user/profile` | Atualizar informações |
| POST | `/api/user/kyc` | Enviar verificação de identidade (KYC) |
| GET | `/api/user/balance` | Saldo da conta |
| GET/POST | `/api/cart` | Carrinho de compras |
| POST/GET | `/api/orders` | Pedidos |
| GET | `/api/orders/{id}/payment-methods` | Métodos de pagamento disponíveis |
| POST | `/api/orders/{id}/pay` | Iniciar pagamento |
| GET/POST | `/api/resources` | Meus recursos |
| GET | `/api/resources/{id}/status` | Status do recurso |
| GET | `/api/resources/{id}/console` | Link do console VNC |
| GET/POST | `/api/tickets` | Tickets de suporte |
| POST | `/api/tickets/{id}/reply` | Responder ticket |
| GET/POST | `/api/dns/{domain}` | Gerenciamento de DNS |
| POST | `/api/supplier/apply` | Solicitação de fornecedor |
| GET | `/api/supplier/settlements` | Registros de liquidação do fornecedor |
| POST | `/api/supplier/withdraw` | Saque do fornecedor |

> **Observação:** todas as requisições à API devem incluir o cabeçalho `X-Api-Version: v1` (se ausente, o padrão é `v1`, validado pelo `VersionMiddleware`). As requisições/respostas das interfaces autenticadas e administrativas passam pelo `EncryptionMiddleware`. O cliente define o cabeçalho `X-Encrypted: 1` e o corpo da requisição segue o formato `{"payload": "<base64(AES-256-GCM)>"}`; o corpo da resposta também é criptografado e envolvido no campo `payload`. Todos os IDs inteiros são convertidos automaticamente em strings Hashid de 12 caracteres nas respostas da API, e as strings Hashid nas requisições são decodificadas automaticamente de volta para IDs inteiros pelo `HashidRequestMiddleware`.

## Interfaces de Administração

| Método | Caminho | Descrição |
|------|------|------|
| GET | `/admin/api/dashboard` | Dashboard operacional |
| GET/PUT | `/admin/api/users` | Gestão de usuários |
| GET/POST | `/admin/api/kyc` | Revisão de KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Gestão de produtos |
| POST | `/admin/api/products/{productId}/skus` | Criar SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Definir preço regional |
| GET/POST | `/admin/api/orders` | Gestão de pedidos (inclui reembolso) |
| GET | `/admin/api/orders/export` | Exportação de pedidos (.xlsx) |
| GET | `/admin/api/users/export` | Exportação de usuários (.xlsx) |
| GET | `/admin/api/suppliers/export` | Exportação de fornecedores (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Canais de pagamento / transações / conciliação |
| GET/POST | `/admin/api/provisioning/*` | Tarefas de entrega / gestão de hosts |
| GET/POST | `/admin/api/suppliers/*` | Aprovação de fornecedores / liquidação / saques |
| GET/POST | `/admin/api/tickets` | Atribuição / fechamento de tickets |
| GET | `/admin/api/reports/*` | Relatórios de receita / região / fornecedores |
| GET | `/admin/api/monitor/*` | Painel de monitoramento / métricas de recursos |
| GET | `/admin/api/audit-logs` | Logs de auditoria |
| PUT | `/admin/api/system/config` | Configuração do sistema |
