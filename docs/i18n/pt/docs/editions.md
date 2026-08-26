# Cloud Platform — Plataforma Global de Negociação de Recursos em Nuvem

Plataforma de negociação de recursos em nuvem voltada a usuários globais, com suporte à compra online e entrega automática de servidores (VM), endereços IP, discos em nuvem, domínios e outros produtos. As máquinas físicas próprias são entregues por meio de virtualização Proxmox VE, com suporte também à integração de fornecedores terceirizados para venda.


## Visão Geral das Edições

| | Lite (Simplificada) | Standard (Padrão) | Full (Completa) |
|---|:---:|:---:|:---:|
| **Licença** | Open source (MIT) | Licença comercial | Licença comercial |
| **Contato** | GitHub | erik@erik.xyz | erik@erik.xyz |
| **Cenários de uso** | Projetos pessoais/estudo/pequenas lojas | Provedores de nuvem de pequeno/médio porte | Grandes plataformas de nuvem/múltiplos fornecedores |

---

## 1. Comparação de Funcionalidades

### 1.1 Sistema de Usuários

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Registro/login por email ou celular | ✅ | ✅ | ✅ |
| Autenticação JWT (Access + Refresh) | ✅ | ✅ | ✅ |
| Redefinição de senha | ✅ | ✅ | ✅ |
| Vínculo de fingerprint do dispositivo + rotação de token | ❌ | ✅ | ✅ |
| Bloqueio de login (5 falhas bloqueiam 15min) | ❌ | ✅ | ✅ |
| Login Google OAuth | ❌ | ✅ | ✅ |
| Apple Sign In | ❌ | ✅ | ✅ |
| Verificação em duas etapas TOTP + recovery codes | ❌ | ✅ | ✅ |
| Verificação de email | ❌ | ✅ | ✅ |
| Código de verificação por SMS | ❌ | ✅ | ✅ |
| Gerenciamento de sessões (visualizar/revogar) | ✅ | ✅ | ✅ |
| Exclusão de conta GDPR | ✅ | ✅ | ✅ |
| Gerenciamento de perfil | ✅ | ✅ | ✅ |
| Verificação de identidade KYC | ❌ | ✅ | ✅ |
| Gerenciamento de endereços | ❌ | ✅ | ✅ |
| Conta de saldo | ❌ | ✅ | ✅ |
| Alerta de login de novo IP | ❌ | ✅ | ✅ |
| Identificação da plataforma do cliente | ❌ | ✅ | ✅ |
| Internacionalização multilingue (i18n, 120 entradas) | ✅ | ✅ | ✅ |

### 1.2 Sistema de Produtos

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Listagem de produtos (filtros por categoria/região) | ✅ | ✅ | ✅ |
| Detalhes do produto (com SKU + preço regional) | ✅ | ✅ | ✅ |
| Busca em texto completo com Elasticsearch | ✅ | ✅ | ✅ |
| Avaliações de produtos (nota + conteúdo) | ✅ | ✅ | ✅ |
| Atributos de produtos | ❌ | ✅ | ✅ |
| Captcha de clique | ❌ | ✅ | ✅ |
| Importação/exportação em lote (CSV) | ❌ | ✅ | ✅ |

### 1.3 Sistema de Pedidos

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Carrinho de compras (CRUD) | ✅ | ✅ | ✅ |
| Fazer pedido | ✅ | ✅ | ✅ |
| Lista + detalhes de pedidos | ✅ | ✅ | ✅ |
| Cupons | ❌ | ✅ | ✅ |
| Faturas (geração + download PDF) | ❌ | ✅ | ✅ |
| Reembolsos | ❌ | ✅ | ✅ |

### 1.4 Sistema de Pagamentos

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Pagamento Stripe | ❌ | ✅ | ✅ |
| Roteamento multicanal | ❌ | ✅ | ✅ |
| Verificação de assinatura do Webhook | ❌ | ✅ | ✅ |
| Conciliação diária | ❌ | ✅ | ✅ |
| Câmbio multimoeda | ❌ | ✅ | ✅ |
| Reembolso pelo caminho original | ❌ | ✅ | ✅ |

### 1.5 Entrega de Recursos

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Virtualização Proxmox VE | ❌ | ✅ | ✅ |
| Ciclo de vida completo de servidores (VM) | ❌ | ✅ | ✅ |
| Discos em nuvem (criação/expansão) | ❌ | ✅ | ✅ |
| Gerenciamento + alocação de pool de IPs | ❌ | ✅ | ✅ |
| Estratégia de seleção de hosts (balanceamento de carga) | ❌ | ✅ | ✅ |
| Upgrade online de CPU/memória/disco | ❌ | ✅ | ✅ |
| Console VNC | ❌ | ✅ | ✅ |
| Fila de provisionamento assíncrono | ❌ | ✅ | ✅ |
| Estratégia de retry (6 tentativas com backoff) | ❌ | ✅ | ✅ |
| Arquitetura de plugins de Providers | ❌ | ✅ | ✅ |
| Monitoramento de vencimento de recursos | ❌ | ✅ | ✅ |

### 1.6 Domínios e DNS

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Consulta de disponibilidade de domínio | ❌ | ✅ | ✅ |
| Gerenciamento de preços de TLDs | ❌ | ✅ | ✅ |
| Gerenciamento de registros DNS | ❌ | ✅ | ✅ |
| Aprovação de transferência de domínio | ❌ | ✅ | ✅ |

### 1.7 Sistema de Tickets

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Criar/responder tickets | ❌ | ✅ | ✅ |
| Lista + detalhes de tickets | ❌ | ✅ | ✅ |
| Atribuição a atendentes | ❌ | ✅ | ✅ |
| Acompanhamento de SLA | ❌ | ✅ | ✅ |
| Atribuição automática (balanceamento de carga) | ❌ | ✅ | ✅ |

### 1.8 Sistema de Notificações

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Notificação por email | ❌ | ✅ | ✅ |
| Notificação por SMS (Twilio) | ❌ | ✅ | ✅ |
| Push no App (FCM) | ❌ | ✅ | ✅ |
| Mensagens internas | ❌ | ✅ | ✅ |
| Gerenciamento de modelos de notificação | ❌ | ✅ | ✅ |
| Preferências de notificação do usuário | ❌ | ✅ | ✅ |

### 1.9 Painel Administrativo

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ |
| Gestão de usuários (lista/detalhes/status) | ✅ | ✅ | ✅ |
| Gestão de produtos (CRUD) | ✅ | ✅ | ✅ |
| Gestão de pedidos (lista/detalhes) | ✅ | ✅ | ✅ |
| Logs de auditoria | ✅ | ✅ | ✅ |
| Revisão de KYC | ❌ | ✅ | ✅ |
| Gestão de SKU + preço regional | ❌ | ✅ | ✅ |
| Gestão de canais de pagamento + registros de transações | ❌ | ✅ | ✅ |
| Monitoramento de tarefas de provisionamento | ❌ | ✅ | ✅ |
| Gestão de hosts | ❌ | ✅ | ✅ |
| Atribuição/fechamento de tickets | ❌ | ✅ | ✅ |
| Gestão de TLDs de domínio + zonas DNS | ❌ | ✅ | ✅ |
| Gestão de modelos de notificação | ❌ | ✅ | ✅ |
| Gestão de cupons | ❌ | ✅ | ✅ |
| Gestão de artigos de ajuda | ❌ | ✅ | ✅ |
| Gestão de Webhooks | ❌ | ✅ | ✅ |
| Gestão de APIs de provedores de nuvem | ❌ | ✅ | ✅ |
| Importação/exportação de produtos | ❌ | ✅ | ✅ |
| Exportação de usuários/pedidos/fornecedores | ❌ | ✅ | ✅ |
| Relatórios (receita/região) | ❌ | ✅ | ✅ |
| Painel de monitoramento + métricas de recursos | ❌ | ✅ | ✅ |
| Gestão de fornecedores | ❌ | ❌ | ✅ |
| Gestão de API Keys de fornecedores | ❌ | ❌ | ✅ |
| Feature Flags dinâmicos | ❌ | ❌ | ✅ |

### 1.10 Sistema de Fornecedores

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Integração de fornecedores + aprovação | ❌ | ❌ | ✅ |
| Publicação de produtos + comissões | ❌ | ❌ | ✅ |
| Liquidação (semanal/mensal) | ❌ | ❌ | ✅ |
| Solicitação de saque + aprovação | ❌ | ❌ | ✅ |
| API externa (autenticação por API Key) | ❌ | ❌ | ✅ |
| Isolamento de dados de fornecedores | ❌ | ❌ | ✅ |

### 1.11 Comunicação em Tempo Real

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Push em tempo real via WebSocket | ❌ | ❌ | ✅ |
| Monitoramento de exceções com Sentry | ❌ | ❌ | ✅ |
| Scripts de teste de carga k6 | ❌ | ✅ | ✅ |


### 1.12 Certificados SSL

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Compra de certificados SSL (DV/OV/EV) | ❌ | ❌ | ✅ |
| Emissão automática Let's Encrypt | ❌ | ❌ | ✅ |
| Renovação automática (14 dias antes do vencimento) | ❌ | ❌ | ✅ |
| Download do certificado (PEM/KEY) | ❌ | ❌ | ✅ |
| Gestão de planos SSL (lado admin) | ❌ | ❌ | ✅ |

### 1.13 Armazenamento de Objetos

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Armazenamento de objetos compatível com S3 | ❌ | ❌ | ✅ |
| Armazenamento próprio com MinIO | ❌ | ❌ | ✅ |
| URLs de upload/download pré-assinados | ❌ | ❌ | ✅ |
| Gestão de cotas de armazenamento | ❌ | ❌ | ✅ |

### 1.14 Aceleração CDN

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Gestão de domínios CDN | ❌ | ❌ | ✅ |
| Purga de cache (Purge) | ❌ | ❌ | ✅ |
| Tipos de origem (servidor/armazenamento) | ❌ | ❌ | ✅ |
| Integração Cloudflare | ❌ | ❌ | ✅ |

### 1.15 Cobrança por Uso

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Cobrança por hora/tráfego | ❌ | ❌ | ✅ |
| Coleta e agregação de uso | ❌ | ❌ | ✅ |
| Débito automático do saldo | ❌ | ❌ | ✅ |
| Suspensão/retomada de recursos inadimplentes | ❌ | ❌ | ✅ |

### 1.16 Avaliação de Fornecedores

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Avaliação em quatro dimensões (qualidade/suporte/entrega/valor) | ❌ | ❌ | ✅ |
| Restrição a usuários compradores | ❌ | ❌ | ✅ |
| Revisão de avaliações (lado admin) | ❌ | ❌ | ✅ |
| Exibição da média dos fornecedores | ❌ | ❌ | ✅ |

### 1.17 Distribuição por Afiliados

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Geração de links de indicação | ❌ | ❌ | ✅ |
| Atribuição de pedidos (parâmetro ref) | ❌ | ❌ | ✅ |
| Cálculo e saque de comissões | ❌ | ❌ | ✅ |
| Gestão do plano de distribuição (lado admin) | ❌ | ❌ | ✅ |

### 1.18 GraphQL

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Endpoint GraphQL (público + autenticado) | ❌ | ❌ | ✅ |
| Consultas de produtos/pedidos/recursos | ❌ | ❌ | ✅ |
| Limite de profundidade de consulta | ❌ | ❌ | ✅ |

### 1.19 Observabilidade

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Exportação de métricas Prometheus | ❌ | ❌ | ✅ |
| Painéis pré-configurados do Grafana | ❌ | ❌ | ✅ |
| Regras de alerta (filas/taxa de erro/latência) | ❌ | ❌ | ✅ |
| Health checks (live/ready/deps) | ❌ | ❌ | ✅ |
| i18n em 7 idiomas (550+ entradas) | ❌ | ❌ | ✅ |

### 1.12 Clientes

| Funcionalidade | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Cliente Flutter | ❌ | ❌ | ✅ |
| Cliente HarmonyOS | ❌ | ❌ | ✅ |

---

## 2. Comparação de Design de Arquitetura

### 2.1 Middlewares

| Middleware | Lite | Standard | Full |
|--------|:---:|:---:|:---:|
| CorsMiddleware (CORS) | ✅ | ✅ | ✅ |
| LocaleMiddleware (multilíngue) | ✅ | ✅ | ✅ |
| HashidRequestMiddleware (decodificação de IDs) | ✅ | ✅ | ✅ |
| AuthMiddleware (autenticação JWT) | ✅ | ✅ | ✅ |
| RateLimitMiddleware (limite de frequência) | ✅ | ✅ | ✅ |
| WafMiddleware básico (SQLi/XSS) | ✅ | ✅ | ✅ |
| WafMiddleware completo (8 categorias, 45+ regras) | ❌ | ✅ | ✅ |
| AdminRoleMiddleware (RBAC) | ❌ | ✅ | ✅ |
| EncryptionMiddleware (AES-256-GCM) | ❌ | ✅ | ✅ |
| VersionMiddleware (versão da API) | ❌ | ✅ | ✅ |
| ClientPlatformMiddleware (identificação de plataforma) | ❌ | ✅ | ✅ |
| ConfirmationMiddleware (confirmação de senha) | ❌ | ✅ | ✅ |
| GeoBlockMiddleware (bloqueio geográfico) | ❌ | ✅ | ✅ |
| MaintenanceMiddleware (modo de manutenção) | ❌ | ✅ | ✅ |
| SupplierApiKeyMiddleware | ❌ | ❌ | ✅ |
| FeatureFlags | ❌ | ❌ | ✅ |
| RbacMiddleware | ❌ | ✅ | ✅ |

### 2.2 Arquitetura de Dados

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Chave primária distribuída Snowflake | ✅ | ✅ | ✅ |
| Ofuscação de IDs com Hashids | ✅ | ✅ | ✅ |
| MySQL com banco único | ✅ | ❌ | ❌ |
| Separação leitura/escrita MySQL | ❌ | ✅ | ✅ |
| Banco de auditoria independente | ❌ | ✅ | ✅ |
| Criptografia de transporte AES-256-GCM | ❌ | ✅ | ✅ |
| Criptografia de campos AES-128-ECB | ❌ | ✅ | ✅ |
| Cache Redis em múltiplos níveis | ❌ | ✅ | ✅ |
| Busca em texto completo com Elasticsearch | ✅ | ✅ | ✅ |
| Otimização de índices do banco (13) | ❌ | ✅ | ✅ |

### 2.3 Defesas de Segurança

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Detecção de injeção de SQL (2 regras) | ✅ | ✅ | ✅ |
| Detecção de XSS (3 regras) | ✅ | ✅ | ✅ |
| Detecção de injeção de comando | ❌ | ✅ | ✅ |
| Detecção de inclusão de arquivos | ❌ | ✅ | ✅ |
| Detecção de injeção de cabeçalho HTTP | ❌ | ✅ | ✅ |
| Detecção de SSRF | ❌ | ✅ | ✅ |
| Detecção de injeção NoSQL | ❌ | ✅ | ✅ |
| Detecção de redirecionamento aberto | ❌ | ✅ | ✅ |
| Limite de tamanho do corpo da requisição | ❌ | ✅ | ✅ |
| Lista de permissões de Content-Type | ❌ | ✅ | ✅ |

### 2.4 Alta Concorrência

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Multiprocessamento do webman | ✅ | ✅ | ✅ |
| Compressão gzip no Nginx | ❌ | ✅ | ✅ |
| proxy buffering no Nginx | ❌ | ✅ | ✅ |
| limit_req/limit_conn no Nginx | ❌ | ✅ | ✅ |
| Camada de cache Redis | ❌ | ✅ | ✅ |
| Invalidação ativa de cache | ❌ | ✅ | ✅ |
| Separação leitura/escrita do MySQL | ❌ | ✅ | ✅ |
| Índices compostos do banco | ❌ | ✅ | ✅ |
| Push via WebSocket | ❌ | ❌ | ✅ |

---

## 3. Implantação e Operação

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Implantação com Docker Compose | ✅ | ✅ | ✅ |
| Proxy reverso Nginx | ✅ | ✅ | ✅ |
| CI/CD (GitHub Actions) | ✅ | ✅ | ✅ |
| Testes PHPUnit | 95 tests | 295 tests | 295 tests |
| Tarefas agendadas (7) | ❌ | ✅ | ✅ |
| Processamento assíncrono com Redis Queue | ❌ | ✅ | ✅ |
| Comandos de migração do banco | ✅ | ✅ | ✅ |
| Comando de backup do banco | ❌ | ✅ | ✅ |
| Endpoint de health check | ✅ | ✅ | ✅ |
| Endpoint de status do serviço | ✅ | ✅ | ✅ |
| Monitoramento de exceções com Sentry | ❌ | ❌ | ✅ |
| Lançamento gradual com Feature Flags | ❌ | ❌ | ✅ |
| Teste de carga k6 | ❌ | ❌ | ✅ |

---

## 4. Números Estatísticos

| Métrica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Endpoints de API | ~35 | ~130 | 200+ |
| Modelos de dados | 15 | 50+ | 70+ |
| Tabelas de banco | 15 | 50+ | 60+ |
| Middlewares globais | 3 | 7 | 9 |
| Middlewares de rota | 1 | 5 | 6 |
| Tarefas agendadas | 0 | 7 | 10 |
| Arquivos de migração | 5 | 20 | 27 |
| Nº de testes | 95 | 295 | 295 |
| Nº de regras WAF | 5 | 45+ | 45+ |
| Nº de documentos | 2 | 6 | 8 |
| Documentação online hg/apidoc | ✅ | ✅ | ✅ |
| Endpoints GraphQL | ❌ | ❌ | ✅ |
| Métricas Prometheus | ❌ | ❌ | ✅ |
| Sistema de avaliação de fornecedores | ❌ | ❌ | ✅ |
| Sistema de indicação por afiliados | ❌ | ❌ | ✅ |

---

## 5. Caminho de Upgrade

```
Lite (Simplificada)
  │
  │  + Pagamentos + Entrega + Domínios + Tickets + Notificações
  │  + Painel admin completo + Pacote completo de segurança + Otimização de alta concorrência
  ▼
Standard (Padrão)
  │
  │  + Sistema de fornecedores + API externa + WebSocket
  │  + Sentry + Feature Flags + Cliente Flutter
  ▼
Full (Completa)
```

**Compatibilidade de dados:** a estrutura do banco da edição Lite é compatível com as tabelas centrais da Standard, permitindo migração/upgrade direto. O upgrade da Standard para a Full é puramente incremental (novas tabelas relacionadas a fornecedores), sem necessidade de migração de dados.

---

## 6. Como Obter

| Edição | Forma de obtenção |
|------|---------|
| **Lite (Simplificada)** | Open source no GitHub, licença MIT |
| **Standard (Padrão)** | Licença comercial, contato **erik@erik.xyz** |
| **Full (Completa)** | Licença comercial, contato **erik@erik.xyz** |
