# Design P4.1 + P4.2: Gateway de API independente/limite de frequência unificado + consistência multimoeda em cadeia completa

> Versão: 2026-08-17 v1 | produzido pelo arquiteto, para implementação em gateway-impl / multicurrency-impl e revisão pelo reviewer-gate
> Base: docs/team-plan.md v2 Fase 4, docs/architecture.md, leitura real do código existente

---

## P4.1 Gateway de API independente + limite de frequência unificado

### Estado atual (confirmado por leitura real)

| Camada | Estado atual |
|----|------|
| Gateway de borda | docker/nginx.conf atua como gateway L7 do service: `limit_req_zone api 10r/s` (limite global), proxy_pass 8787 (service), 8282 (ws). **O admin é um container independente** (Dockerfile target admin, nginx-admin.conf listen 8788 proxy 8788), **sem limit_req** |
| Limite na aplicação | `service/common/security/RateLimitMiddleware.php` já existe: janela fixa com Redis INCR+expire, **somente per-IP**, regras selecionadas por `ROUTE_MAP`, montado em **rotas explícitas** (~12 locais no route.php) |
| Configuração de regras | `config/security.php rate_limits`: default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, todas com rate/burst/per, mas **o campo burst não é usado atualmente** |
| Middleware global | a chave `''` do `config/middleware.php` já suporta efeito em todas as rotas (WAF/GeoBlock/Security etc., 10 itens aqui) |
| Lacunas | `/graphql` (rotas pública + autenticada) **sem nenhum limite de frequência**; não existe limite per-token; resposta 429 sem cabeçalho `Retry-After`; webhook sem isenção/regra dedicada |

### Decisões

**D1: não criar processo de gateway separado.** O nginx é o gateway (borda de rede + limite de frequência + distribuição de rotas); o limite unificado é feito dentro do webman.
- Justificativa: um container gateway independente exigiria nova dependência/nova topologia de implantação/autenticação dupla; é over-engineering na escala atual de instância única.
- Trade-off: não é possível fazer limite por token/por rota na camada do gateway (nginx só tem segmentos per-IP). A diferenciação é feita pela aplicação; a camada nginx mantém apenas o fallback de IP de granularidade grossa (os atuais 10r/s elevados para 100r/s para não prejudicar o negócio; na validação com k6, volta-se ao limite de demonstração).
- Caminho de evolução: se no futuro houver múltiplas instâncias/serviços, basta mover o limitador global do `config/middleware.php` para um serviço gateway independente; o middleware não percebe a forma de implantação.

**D2: limite unificado = middleware global + bucket duplo (per-IP + per-token).**
- Remover `RateLimitMiddleware` das rotas explícitas (~12 locais no route.php, conforme grep) e montá-lo na lista global `''` do `config/middleware.php` (depois do WAF, antes dos middlewares de negócio), **cobrindo naturalmente todas as rotas da aplicação (incluindo as duas do /graphql)**.
- **Semântica do bucket (explícita, contra contornos)**: os buckets `ratelimit:ip:{realIp}:{rule}` e `ratelimit:tok:{sha256(token)}:{rule}` contam independentemente; **qualquer bucket acima do limite gera 429 (OR)**. Proibido implementar com AND — com AND, trocar de IP contorna o bucket per-IP e trocar de token contorna o bucket per-token.
- **Lista de isenções**: `/health*` (sondas de monitoramento) e `/api/payments/webhook/stripe` (a validação de assinatura é a defesa real + o Stripe tem retry automático com backoff em 429 + o fallback de granularidade grossa do nginx de 100r/s continua ativo; o limite não traz ganho de segurança, apenas risco de perda de eventos/atraso de crédito). Todas as demais rotas são obrigatoriamente limitadas.
- Resposta: `HTTP 429` + cabeçalho `Retry-After` (remanescente do bucket duplo usando **max**, janela fixa com `PTTL` do Redis para precisão exata) + body `{code:429, message, retry_after}` (alinhado com o `Response::error` existente).
- Surto: ativar o campo burst — `rate` é a cota estável na janela, `burst` é o crédito utilizável a mais. Implementado como teto de contagem da chave Redis `rate + burst` (uso a mais dentro da janela fixa), sem janela deslizante (ponytail: janela fixa tem ampliação de 2x na borda; per-IP é suficiente contra abuso de máquina única; trocar por janela deslizante se precisar de algo mais estrito).
- Mapeamento rota→regra: manter o `ROUTE_MAP` existente, adicionar `'/graphql' => 'graphql'` (config/security.php:46 já tem `{rate:30, burst:5, per:60}`); rotas desconhecidas usam `default` (60/60s).
- Redis indisponível: manter o fail-open existente (catch Exception e liberar) — o fallback de granularidade grossa de 100r/s do nginx continua ativo.
- **Escopo**: apenas o container service. O admin é um container independente (nginx-admin.conf sem limit_req, atualmente sem limite); as alterações de service/config e do middleware do service não afetam o admin — o limite do admin não está no escopo do P4.1, decisão à parte.

**D3: limite antes da autenticação.** O middleware global está antes do AuthMiddleware (a ordem do middleware.php é a ordem de execução); portanto, o bucket per-token degenera em bucket per-IP para requisições sem token; requisições com token contam no bucket de token mesmo em rotas anônimas (como /api/products) — prevenindo abuso de token compartilhado.

### Superfície de impacto

| Item | Alteração |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | Refatoração: bucket per-token, burst, Retry-After, regra do graphql |
| `service/config/middleware.php` | RateLimitMiddleware adicionado à lista `''`; removido de todos os pontos de montagem explícitos no route.php |
| `service/config/security.php` | Manter `default` {60,10,60} inalterado (limiar de aceite = rate+burst = 70); `graphql` {30,5,60} já existe, sem necessidade de adicionar; campo burst mantido |
| `service/config/route.php` | Remover ~12 montagens explícitas de `RateLimitMiddleware::class` (conforme grep real, grupos auth/supplier/admin) |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s (fallback de granularidade grossa, evitando bloquear o negócio acima do middleware global) |
| Testes | Testes da suíte service que dependem da montagem explícita do middleware de limite devem ser sincronizados; novos testes unitários do middleware |

### Aceite (k6)

```
# Escolha uma rota anônima (ex.: GET /api/products) e o /graphql, cada um com 200 requisições/10s:
# Acima do limiar, todas 429 com Retry-After na resposta; abaixo do limiar, todas 200.
# Assert: contagem de 429 == total de requisições - limiar; /graphql também ativo (lacuna original).
```

---

## P4.2 Consistência multimoeda em cadeia completa (incluindo estratégia de arredondamento da taxa)

### Estado atual (confirmado por leitura real)

- **Armazenamento**: todos os valores do `install.sql` são DECIMAL — saldo/congelado `(16,4)`, subtotal/discount/tax/total do pedido e unit_price/total_price dos itens `(12,4)`, `exchange_rate DECIMAL(12,6)` já presente em `orders` e `payment_transactions`; `user_balances` com linhas por moeda (contabilidade por moeda).
- **Fonte da taxa de câmbio**: `service/app/cron/ExchangeRateSync.php` já implementado — API gratuita externa (`EXCHANGE_RATE_API_URL` configurável via env, padrão exchangerate-api.com) sincroniza por hora para o Redis `exchange_rate:{CURRENCY}`; `OrderService::getExchangeRate` lê o snapshot do Redis no momento do pedido (USD constante 1.0) e grava no campo `exchange_rate` do pedido. **Já existe dependência externa e o env permite trocar a fonte; sem necessidade de adicionar.**
- **Problema de truncamento da taxa**: `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath **trunca** pelo scale (não arredonda), na direção de **cobrar menos** <0,0001/pedido; além disso, `total_amount = amount + fee` para amounts com 5+ casas decimais (ex.: 10.12345) pode ficar inconsistente com o total do pedido após truncamento.
- **Verificação de suspensão** já decide por saldo de moeda (multimoeda); o Billing cobra por meter (unit_price dos usage_rates em DECIMAL(12,4)).

### Decisões

**D4: invariante de valor unificado — uma precisão interna por moeda, arredondamento apenas em ponto único.**
- Cálculo interno unificado com `DECIMAL(12,4)` (granularidade de pedido) e `DECIMAL(16,4)` (granularidade de saldo); toda multiplicação deve passar por `bcround(x, 4, PHP_ROUND_HALF_UP)`; `bcadd/bcsub` apenas para soma/subtração de mesma precisão (já exatas).
- Novo único helper de valores `service/common/money/Money.php` (~40 linhas):
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` — idempotente; `round()` tem risco de precisão com floats; caminho obrigatório por string: `bcadd($v, '0', $scale+1)` e decidir HALF-UP pela casa $scale+1 (atenção ao tratamento de negativos na implementação; usar bccomp com abs).
  - Qualquer campo de valor deve passar por `bcround(…, 4)` antes da escrita no banco; **proibido** usar `(float)`/`round()` no meio da cadeia de cálculo (o `round((float) bcmul(...))` existente no StripeChannel é justamente um risco).
- O `calculateFee` existente passa a: `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — primeiro alinha o amount a 4 casas, multiplica pela taxa, depois HALF_UP para 4 casas. **Correção de direção: cobrar a menos → meio-para-cima padrão** (diferença por pedido ≤0,00005, valor esperado tendendo a 0). **Proteção de taxa negativa presa em 0 mantida** (comportamento atual do PaymentRouter.php:44 inalterado).

**D5: identidade do pedido e taxa do canal separadas (conciliação sem deriva).** Dois fatos independentes:
- **Identidade do pedido** `total − subtotal − tax + discount == 0` (precisa até 0,0000): na criação do pedido (OrderService::createFromCart), itens com `bcround(bcmul(price, qty, 8), 4)` (multiplicação de alta precisão primeiro, depois arredondamento, evitando duplo truncamento) → subtotal = soma dos itens (exato) → total = subtotal + tax − discount (soma/subtração de mesma precisão, exata). **tax é atualmente constante 0** (createFromCart não define tax; install.sql:345 DEFAULT 0.0000) — não adicionar cálculo de imposto (fora do escopo do P4.2, com impacto de conformidade); os asserts são implementados com o valor atual `tax=0`, mas a fórmula mantém o termo tax.
- **Taxa do canal**: channel_fee com `bcround(…,4)` independente; valor do canal de pagamento = total + channel_fee com igualdade exata em 4dp.
- Validação: `PaymentController::reconcile*` e relatórios (Report) usam o total armazenado no pedido como base, sem recalcular.

**D6: snapshot de taxa de câmbio e ponto de conversão.**
- A fonte da taxa mantém o cron ExchangeRateSync + Redis (já existente, sem alteração). A coluna `exchange_rate` já é snapshot no pedido/transação (DECIMAL(12,6)); **o ponto de conversão = liquidação (escrita no banco)**, sem conversão em tempo real na exibição (o preço em tempo real na UI é apenas a camada de exibição multiplicando pela taxa atual do Redis, sem efeito contábil).
- Regra: **tudo que envolve contabilidade/saldo deve usar a rate do snapshot do pedido; tudo que envolve precificação/exibição pode usar a rate atual**. Proibido misturar as duas rates na cadeia de liquidação.
- A camada de saldo já é um livro por moeda (user_balances em linhas por currency), sem conversão para moeda base única; quando o relatório precisar de moeda base (ex.: USD), somar com a rate do snapshot do pedido; o resultado da soma ainda passa por `bcround(…,4)` (ponytail: o erro de arredondamento da soma entre moedas fica no dígito do total; se a auditoria futura exigir totais por moeda, separar).

**D7: lista de alterações (incluindo pontos de revisão do código multimoeda existente).**
- Alterar: `PaymentRouter::calculateFee`, `StripeChannel` (alinhamento dos valores de entrada + remoção do round float, incluindo convertToSmallest com bcround($total,2)), `OrderService::createFromCart` (arredondamento sequencial itens/subtotal/total), **`Order/Model/Coupon.php::calculateDiscount` (:31-44 atualmente float+round, mudar para caminho de string com bcround)**, `PaymentController::reconcile*` (assert da identidade D5), `Report/*` (soma unificada com bcround).
- Rever sem alterar: medidores do Billing (unit_price já DECIMAL(12,4); alinhar com bcround na cobrança), verificação de suspensão (decisão por saldo de moeda, já correta), `Cron/ExchangeRateSync.php` (escreve no Redis preservando as 6 casas originais, sem alteração).
- Novos: `service/common/money/Money.php` + teste unitário (limites do HALF_UP: 0,00005 → 0,0001; 0,00004 → 0,0000; **-0,00005 → -0,0001 (negativo afastando de zero)**; idempotência).
- Migração: sem mudança estrutural no `install.sql` (a coluna exchange_rate já existe); se pedidos históricos com taxa truncada geraram resíduos <0,0001, trata-se de diferença contábil irreversível, **apenas registrar sem corrigir** (uma correção alteraria a conciliação histórica); nova consulta de auditoria `fee_drift` listando pedidos com |total−subtotal−tax+discount|>0 para revisão manual.

### Aceite

```
# k6 (P4.1): IP único fixo. GET /api/products e /graphql, cada um com 200 requisições/10s:
#   limiar da regra default = rate+burst = 70/janela de 60s → esperado 429 ≈ 200−70 = 130 (±1-2 na borda da janela)
#   limiar da regra graphql = 35 → esperado 429 ≈ 165; todos com cabeçalho Retry-After; baixo tráfego, todos 200
# Teste unitário (P4.2): limites do Money::bcround (0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, idempotência)
# Teste de identidade: construir pedido com vários itens (unit price com 5 casas decimais + cupom), assert total−subtotal−tax+discount == 0 sempre
# Regressão: suíte service atual com 491 tests toda verde (incluindo asserts de valores)
```

---

## Riscos e Revisão

- **Risco do limitador global do D2 (médio)**: a montagem global afeta todos os endpoints do service (**sem incluir o admin** — container independente; alterações de service/config não o tocam); o webhook já está isento; limiares inadequados podem prejudicar por engano; o security-auditor deve revisar os limiares padrão e a política de fail-open. **O container admin está atualmente sem limite** (nginx-admin.conf sem limit_req); não faz parte do P4.1, decisão à parte.
- **Cadeia de fundos D4/D5 (alto)**: a mudança de direção de arredondamento afeta o valor de cada pedido (cobrar a menos → meio-para-cima padrão); requer revisão do security-auditor + revisão em dupla; dados históricos apenas registrados, sem correção.
- **Dependências**: nenhuma dependência composer nova; nenhuma tabela nova; a alteração de configuração do nginx requer reload.

```yaml
design:
  objective: "P4.1 limite de frequência unificado ativo em todas as rotas (incluindo graphql) + P4.2 estratégia de arredondamento multimoeda alinhada, identidade contábil sem deriva"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + 恒等式)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # 无结构变更；exchange_rate 列已存在；tax 维持 0 不新增
  client_impact: ["flutter", "harmonyos"]  # 429 需客户端优雅处理；admin 容器不受影响
  risk: "high"       # D4/D5 资金链路
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After 全路由（k6 单 IP，429≈130/165）", "graphql 限流缺口关闭", "webhook 豁免不 429", "双桶 OR 语义（换 token/换 IP 均不可绕）", "fee HALF_UP 边界含负值", "Coupon bcround 字符串化", "total−subtotal−tax+discount==0 恒等式", "历史订单 fee_drift 审计查询"]
  dependencies: []
```
