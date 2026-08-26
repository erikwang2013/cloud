# Plano de Equipe CloudPlatform

> Versão: 2026-08-17 (v2) | v1 elaborada por pipeline multiagente (PASS_WITH_FIXES); v2 atualizada pelo Lead com base nos resultados reais das Fases 0-2
> Base: v1 + todos os commits das Fases 0-2 (git 111 commits) + registros de revisão em dupla + linha de base de testes medida

## 1. Visão Geral do Estado Atual (2026-08-17)

### 1.1 Grau de Conclusão das Fases

| Fase | Status | Principais Entregas |
|------|------|----------|
| Fase 0 Estabilização | ✅ 4/4 | Renderização real de faturas, 6 tipos de modelos de notificação, conciliação explícita como unverified, cabeçalho CSP/modelos de ambiente |
| Fase 1 Curto prazo | ✅ 8/8 | Carrinho com quantidade, unificação de status de avaliações, conciliação real (relatórios Stripe + por dia), validação de condições de reembolso (72h/5 dias + idempotência + índice TOCTOU), 7 tipos de webhooks para fornecedores, conexão de Feature Flags + painel de administração, sincronização de documentação, testes reais |
| Fase 2 Médio prazo | ✅ 8/8 | 4 itens de guarda de fundos, dívida de testes service/admin, 31 tabelas no install.sql, RbacMiddleware montado em 57 rotas, admin na imagem + nginx 8788 + CI em ambos os lados, regressão de auditoria + fluxo completo de login |
| Fase 3 Longo prazo | ✅ 9/9 | Gateway + limite de frequência unificado (P4.1), cadeia completa multimoeda (P4.2), engenharia do projeto HarmonyOS + CI (P4.3), implementação de ES (P4.4), absorção de itens de observação (P4.5), 4 divergências de documentação (P3.1), convergência de permissões (P3.2), chave de idempotência de pedidos (P3.3), validação de avaliação de fornecedores (P3.4), i18n em 7 idiomas (P3.6); revisão independente do reviewer-gate aprovada integralmente |

### 1.2 Linha de Base de Qualidade (medida, verificação serial após commits)

- Suíte service: **568 tests / 1279 assertions**, 10 skip (todos por falta de ambiente de banco)
- Suíte admin: **255 tests / 887 assertions**, 1 skip (caminho de escrita no DB)
- CI com 6 jobs: PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check / (relacionados a docker)
- Todos os itens de fundos/segurança revisados em dupla (conclusões independentes de security-auditor + reviewer consistentes); commits git agrupados por tarefa, árvore de trabalho limpa
- Bônus: ocultação de credenciais na serialização de 9 modelos Encryptable (verificação completa em P1/P2)

## 2. Lista de Pendências e Riscos (revisão em 2026-08-17)

### 2.1 Itens que Bloqueiam Implantação (alta prioridade)

- **Lacuna de ambiente DB_PASSWORD**: service/.env com string vazia → todos os endpoints de DB retornam 500, causa raiz de 9+1 testes skip. Não é problema de código; precisa que a operação preencha o valor (o modelo já existe no .env.example da raiz)
- **Scaffold do projeto HarmonyOS ausente**: apps/harmonyos tem apenas 3 arquivos .ets (LoginPage/AuthManager/ApiClient), faltando toda a configuração do projeto hvigor/DevEco → não é possível compilar; o CI harmonyos-check reporta erro honestamente (exit 1)

### 2.2 Divergências Documentação-Código (4 itens não resolvidos em P1)

- Filtro de status em GET /api/orders não implementado
- Eventos de push WebSocket ausentes (a documentação de websocket_push declara existência)
- Escopo de disparo de ticket.updated indefinido
- product_attributes é um schema morto (nenhum código o utiliza)

### 2.3 Itens de Observação de Fundos/Segurança (registros de revisão em dupla, nível baixo)

- **Pedidos sem chave de idempotência**: o envio repetido do mesmo carrinho pode gerar pedidos duplicados (médio, sugerido agendar)
- Avaliação de fornecedores não valida a titularidade/status do pedido
- Truncamento do bcmath na taxa (5ª casa decimal, arrecadando menos <0,0001/transação; consistente com o roteamento, sem desvio de conciliação)
- WAF ainda lê body bruto em multipart grande (no cenário json é coberto por $input; multipart é superfície de defesa adicional)
- user_coupons sem restrição única (semanticamente permite vários pedidos/várias linhas por usuário, observação)
- nginx-admin sem CSP (o admin é frontend Layui com scripts inline, mantendo o estado atual)

### 2.4 Inconsistência do Modelo de Permissões (nova descoberta em P2, a convergir)

- 6 identificadores de permissão somente no DB / 19 somente no Rbac / diferenças de atribuição de papéis (support/supplier)
- AdminRoleMiddleware exclui finance, mas Rbac.php define o papel finance

### 2.5 Outros

- Os novos arquivos de idioma do i18n estão no texto original em inglês (T6); os 7 idiomas não estão concluídos
- A verificação estrutural do CI do HarmonyOS será atualizada para uma compilação hvigor real depois que o scaffold for concluído

## 3. Roteiro

Princípio de prioridade (inalterado): **fundos/segurança > confiabilidade de entrega > fechamento do ciclo de negócio principal > experiência e expansão**.

### Fase 3 — Fechamento de pendências (1 mês)

**Objetivo**: encerrar todas as divergências e itens de observação; implantação reproduzível (testes de DB em cadeia completa executados e verdes).

| Tarefa | Envolve | Papel | Dependência |
|------|------|------|------|
| Fechamento das 4 divergências documentação-código (implementar filtro de status de orders / conectar push WebSocket / corrigir ticket.updated / excluir ou implementar product_attributes) | Order, WebSocket, Ticket, Product, docs | coder + researcher | Nenhuma |
| Convergência do modelo de permissões (alinhar diferenças DB/Rbac + semear papéis + rever AdminRoleMiddleware) | Rbac, install.sql, admin | coder + security-auditor | Nenhuma |
| Chave de idempotência de pedidos (prevenir pedidos duplicados cart→order) | OrderService | coder | Nenhuma (revisão em dupla para itens de fundos) |
| Avaliação de fornecedores validando titularidade/status do pedido | Supplier, Review | coder | Nenhuma |
| Conexão do DB_PASSWORD pela operação + execução real dos 10 testes skip | Operação, tests | security-auditor | Apoio da operação |
| Complemento das traduções de i18n em 7 idiomas | Arquivos i18n | coder | Nenhuma |

**Aceite**: 4 divergências encerradas; matriz de permissões consistente entre DB/código; testes de chave de idempotência; testes de DB em cadeia completa verdes; i18n pelo menos zh/en utilizáveis.

### Fase 4 — Evolução da Arquitetura (1-3 meses)

**Objetivo**: arquitetura de quatro camadas consolidada, suportando crescimento de múltiplos clientes e moedas.

| Tarefa | Envolve | Papel | Dependência |
|------|------|------|------|
| Gateway de API independente + limite de frequência unificado (incluindo lacuna do graphql) | gateway, route | architect + coder | P3 |
| Consistência multimoeda em cadeia completa (incluindo estratégia de arredondamento da taxa) | Payment, Billing | architect + performance-engineer | Idem |
| Engenharia do projeto HarmonyOS: scaffold + compilação real no CI + login funcionando | apps/harmonyos | mobile-dev | Nenhuma |
| Auditoria ES implementada, substituindo a solução alternativa | docker, busca de Product | coder | Nenhuma |
| Absorção em lote de itens de observação (WAF multipart / restrição de user_coupons / webhook de fornecedores ponta a ponta) | Security, Order, Supplier | coder + tester | Nenhuma |

**Aceite**: k6 valida o limite de frequência ativo em todas as rotas; cálculo multimoeda sem erro; HarmonyOS gera pacote e passa no CI; busca ES realmente utilizável.

## 4. Divisão de Trabalho da Equipe

Núcleo fixo: Lead(planner) / architect / coder / tester / reviewer / researcher
Sob demanda: mobile-dev / security-architect / security-auditor / performance-engineer

| Fase | Papéis acionados | Descrição |
|------|----------|------|
| P3 | coder (principal), researcher, security-auditor | Foco em fechamento; permissões/idempotência com revisão em dupla |
| P4 | architect, coder, mobile-dev, performance-engineer | Evolução da arquitetura; security-architect como consultor permanente |

O modo de colaboração não muda: pipeline do CLAUDE.md (architect→coder→tester→reviewer), com fan-out paralelo das tarefas internas em P3/P4; **tarefas de fundos/segurança exigem revisão obrigatória em dupla**; este documento é atualizado ao final de cada fase (esta v2 foi elaborada diretamente pelo Lead, sem passar pelo pipeline, podendo ser revisada).

## 5. Forma de Acompanhamento de Riscos

- Esta lista é atualizada de forma contínua ao final de cada fase; novas descobertas (como a inconsistência do modelo de permissões e a idempotência de pedidos em P2) são incorporadas imediatamente
- Itens de baixa prioridade conhecidos (webhook de fornecedores ponta a ponta, body multipart) já estão no lote de absorção do P4 e não se expandem para fora da lista

## 6. Principais Fontes de Evidência

- Commits: git log (111 commits, Fases 0-2 agrupados por tarefa)
- Linha de base de testes: saída medida das suítes service/admin
- Registros de revisão: mensagens de revisão em dupla de P1/P2 (guarda de fundos, logout/WAF, RBAC, regressão de auditoria)
- Documentação: v1 (histórico de docs/team-plan.md), docs/audit-report-2026-08-06-v3.md, docs/api-reference.md
