# P4.1 + P4.2 设计：独立 API 网关/统一限流 + 多币种全链路一致性

> 版本：2026-08-17 v1｜架构师产出，供 gateway-impl / multicurrency-impl 实现、reviewer-gate 复核
> 依据：docs/team-plan.md v2 Phase 4、docs/architecture.md、现有代码实读

---

## P4.1 独立 API 网关 + 统一限流

### 现状（实读确认）

| 层 | 现状 |
|----|------|
| 边缘网关 | docker/nginx.conf 承担 service L7 网关：`limit_req_zone api 10r/s`（全局限流）、proxy_pass 8787（service）、8282（ws）。**admin 是独立容器**（Dockerfile admin target，nginx-admin.conf listen 8788 proxy 8788），**无 limit_req** |
| 应用限流 | `service/common/Security/RateLimitMiddleware.php` 已存在：Redis INCR+expire 固定窗口，**仅 per-IP**，按 `ROUTE_MAP` 选规则，附到**显式路由**上（route.php 共 ~12 处） |
| 规则配置 | `config/security.php rate_limits`：default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql，均含 rate/burst/per，但 **burst 字段当前未被使用** |
| 全局中间件 | `config/middleware.php` `''` key 已支持对所有路由生效（WAF/GeoBlock/Security 等 10 项在此） |
| 缺口 | `/graphql`（public + authenticated 两条路由）**无任何限流**；per-token 限流不存在；429 响应无 `Retry-After` 头；webhook 无豁免/专用规则 |

### 决策

**D1：不新建独立网关进程。** nginx 即网关（网络边缘 + 限流 + 路由分流），webman 内做统一限流。
- 理由：独立 gateway 容器需新依赖/新部署拓扑/双份鉴权，当前单实例规模下是过度设计；
- 取舍：无法在网关层做按 token/按路由差异化限流（nginx 只有 per-IP 区段）。差异化由应用层补，nginx 层仅保留粗粒度 IP 兜底（现有 10r/s 提高至 100r/s 以免误伤业务，k6 验证时调回演示阈值）。
- 演进路径：若未来多实例/多服务，将 `config/middleware.php` 的全局限流器原样搬到独立 gateway 服务即可，中间件不感知部署形态。

**D2：统一限流 = 全局中间件 + 双维度桶（per-IP + per-token）。**
- 把 `RateLimitMiddleware` 从显式路由移除（route.php 实际 ~12 处，以 grep 为准），挂到 `config/middleware.php` `''` 全局列表（WAF 之后、业务中间件之前），**天然覆盖全部应用内路由（含 /graphql 两条）**。
- **桶语义（明确，防绕行）**：`ratelimit:ip:{realIp}:{rule}` 与 `ratelimit:tok:{sha256(token)}:{rule}` 双桶独立计数，**任一桶超限即 429（OR）**。禁止按 AND 实现——AND 下换 IP 可绕 per-IP 桶、换 token 可绕 per-token 桶。
- **豁免列表**：`/health*`（监控探针）与 `/api/payments/webhook/stripe`（签名校验为真实防线 + Stripe 429 自动退避重试 + nginx 粗粒度 100r/s 兜底仍生效；限流无安全增益、只有丢事件/延迟到账风险）。其余全部路由必限。
- 响应：`HTTP 429` + `Retry-After` 头（双桶窗口剩余取 **max**，固定窗口用 Redis `PTTL` 精确剩余）+ body `{code:429, message, retry_after}`（对齐现有 `Response::error`）。
- 突发：启用 burst 字段——`rate` 为窗口内稳态配额，`burst` 为可透支额度。实现为 Redis key 计数上限 `rate + burst`（固定窗口内透支），无需滑动窗口（ponytail: 固定窗口在边界有 2 倍窗口放大，per-IP 对单机滥用足够；要更严再换滑动窗口）。
- 路由→规则映射：保留现有 `ROUTE_MAP`，补 `'/graphql' => 'graphql'`（config/security.php:46 已有 `{rate:30, burst:5, per:60}`）；未知路由走 `default`（60/60s）。
- Redis 不可用：沿用现有 fail-open（catch Exception 放行）——nginx 100r/s 粗粒度兜底仍在。
- **范围**：仅 service 容器。admin 是独立容器（nginx-admin.conf 无 limit_req、现状无限流），service/config 与 service 中间件改动不影响 admin——admin 限流不在 P4.1 范围，另行决策。

**D3：认证前限流。** 全局中间件位于 AuthMiddleware 之前（middleware.php 顺序即执行顺序），因此 per-token 桶对未携带 token 的请求退化为 per-IP 桶；已带 token 的请求即使路径匿名（如 /api/products）也计入 token 桶——防共享 token 滥用。

### 影响面

| 项 | 改动 |
|----|------|
| `service/common/Security/RateLimitMiddleware.php` | 改造：per-token 桶、burst、Retry-After、graphql 规则 |
| `service/config/middleware.php` | `''` 列表追加 RateLimitMiddleware；从 route.php 全部显式挂载点移除 |
| `service/config/security.php` | 维持 `default` {60,10,60} 不动（验收阈值 = rate+burst = 70）；`graphql` {30,5,60} 原已存在，无需加；burst 字段沿用 |
| `service/config/route.php` | 删 ~12 处显式 `RateLimitMiddleware::class` 挂载（以 grep 实际为准，auth/supplier/admin 组） |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s（粗粒度兜底，避免全局中间件之上再卡业务） |
| 测试 | service 套件中依赖限流中间件显式挂载的测试需同步；新增中间件单测 |

### 验收（k6）

```
# 任选一匿名路由（如 GET /api/products）与 /graphql，各打 200 请求/10s：
# 限流阈值以上全部 429，且响应带 Retry-After；低于阈值全部 200。
# 断言：429 计数 == 总请求 - 阈值；/graphql 同样生效（原缺口）。
```

---

## P4.2 多币种全链路一致性（含 fee 舍入策略）

### 现状（实读确认）

- **存储**：`install.sql` 全部金额为 DECIMAL —— 余额/冻结 `(16,4)`，订单 subtotal/discount/tax/total、行项 unit_price/total_price `(12,4)`，`exchange_rate DECIMAL(12,6)` 已在 `orders`、`payment_transactions` 上；`user_balances` 按币种分行（分币种记账）。
- **汇率来源**：`service/app/Cron/ExchangeRateSync.php` 已实现——外部免费 API（`EXCHANGE_RATE_API_URL` env 可配，默认 exchangerate-api.com）每小时同步到 Redis `exchange_rate:{CURRENCY}`；`OrderService::getExchangeRate` 下单时读 Redis 快照（USD 恒 1.0）写入订单 `exchange_rate` 字段。**已有外部依赖且 env 可换源，无需新增。**
- **fee 截断问题**：`PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` —— bcmath 按 scale **截断**（非四舍五入），方向**少收** <0.0001/单；且 `total_amount = amount + fee` 对 5+ 位小数的 amount（如 10.12345）截断后与订单 total 可能不一致。
- **suspend 检查**已按币种余额判断（多币种），Billing 按 meter 计费（usage_rates 单价 DECIMAL(12,4)）。

### 决策

**D4：统一金额不变式 —— 每个币种一个内部精度，舍入只发生在单点。**
- 内部计算统一 `DECIMAL(12,4)`（订单粒度）与 `DECIMAL(16,4)`（余额粒度），所有乘法后必须经 `bcround(x, 4, PHP_ROUND_HALF_UP)`，`bcadd/bcsub` 仅做同精度加减（本身精确）。
- 新增唯一金额助手 `service/common/Money/Money.php`（约 40 行）：
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` —— 幂等；`round()` 对浮点有精度风险，必须字符串路径：`bcadd($v, '0', $scale+1)` 后按第 $scale+1 位判断 HALF-UP（实现注意负数处理，用 bccomp 对 abs 判断即可）。
  - 任何金额字段写库前必须过 `bcround(…, 4)`；**禁止**在计算链中途用 `(float)`/`round()`（现有 StripeChannel 的 `round((float) bcmul(...))` 即隐患）。
- 现有 `calculateFee` 改为：`$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` —— 先对齐 amount 到 4 位，再乘率、再 HALF_UP 到 4 位。**方向修正：少收 → 标准半舍入**（每单差异 ≤0.00005，期望值趋 0）。**负 fee 钳 0 保护保留**（现代码 PaymentRouter.php:44 行为不变）。

**D5：订单恒等式与通道费分离（对账零漂移）。** 两个独立事实：
- **订单行恒等式** `total − subtotal − tax + discount == 0`（精确到 0.0000）：建单链路（OrderService::createFromCart）行项 `bcround(bcmul(price, qty, 8), 4)`（先高精度乘法再舍，避免双重截断）→ subtotal = 行和（精确）→ total = subtotal + tax − discount（同精度加减，精确）。**tax 现状恒为 0**（createFromCart 不设 tax，install.sql:345 DEFAULT 0.0000）——不新增税计算（超 P4.2 范围、有合规影响），断言按 `tax=0` 的现值实现但公式保留 tax 项。
- **通道费**：channel_fee 独立 `bcround(…,4)`，支付通道金额 = total + channel_fee 在 4dp 精确相等。
- 校验：`PaymentController::reconcile*` 与报表（Report）以订单存储的 total 为基准，不再重算。

**D6：汇率快照与换算点。**
- 汇率来源维持 ExchangeRateSync cron + Redis（已存在，不动）。`exchange_rate` 列已随订单/交易快照（DECIMAL(12,6)），**换算点 = 结算（写库）时**，不做显示时实时换算（显示实时价只是 UI 层乘当前 Redis 汇率，不影响账面）。
- 规则：**凡涉及账面/余额，必须用订单快照 rate；凡涉及标价/展示，可用当前 rate**。禁止在结算链中混用两个 rate。
- 余额层已是分币种账本（user_balances 按 currency 行），不做统一本位币折算；报表需要本位币（如 USD）时用订单快照 rate 汇总，汇总结果仍过 `bcround(…,4)`（ponytail: 跨币种汇总的舍入误差在合计位，若后续审计要求分币种合计再拆）。

**D7：改动清单（含既有多币种代码复核点）。**
- 改：`PaymentRouter::calculateFee`、`StripeChannel`（金额入参对齐 + 移除 float round，含 convertToSmallest 改 bcround($total,2)）、`OrderService::createFromCart`（行项/subtotal/total 顺序舍入）、**`Order/Model/Coupon.php::calculateDiscount`（:31-44 现为 float+round，改 bcround 字符串路径）**、`PaymentController::reconcile*`（断言 D5 恒等式）、`Report/*`（汇总统一 bcround）。
- 复核不改：Billing meters（单价已是 DECIMAL(12,4)，计费按 bcround 对齐即可）、suspend 检查（分币种余额判断，已正确）、`Cron/ExchangeRateSync.php`（写 Redis 保留 6 位原文，不动）。
- 新增：`service/common/Money/Money.php` + 单测（HALF_UP 边界：0.00005 → 0.0001、0.00004 → 0.0000、**-0.00005 → -0.0001（负数远离零）**、幂等性）。
- 迁移：`install.sql` 无结构变更（exchange_rate 列已存在）；若历史订单 fee 截断产生 <0.0001 尾差，属账面不可逆差异，**只记录不修补**（补一笔会改变历史对账），新增审计查询 `fee_drift` 列出 |total−subtotal−tax+discount|>0 的订单供人工核。

### 验收

```
# k6（P4.1）：固定单 IP。GET /api/products 与 /graphql 各打 200 请求/10s：
#   default 规则阈值 = rate+burst = 70/60s 窗口 → 期望 429 ≈ 200−70 = 130（±窗口边界 1-2）
#   graphql 规则阈值 = 35 → 期望 429 ≈ 165；均带 Retry-After 头；低流量全 200
# 单测（P4.2）：Money::bcround 边界（0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, 幂等）
# 恒等式测试：构造多行订单（含 5 位小数单价 + 优惠券），断言 total−subtotal−tax+discount == 0 恒成立
# 回归：现有 service 491 tests 全绿（含金额断言）
```

---

## 风险与评审

- **D2 全局限流器风险（中）**：全局挂载影响 service 全部端点（**不含 admin**——独立容器，service/config 改动不触及），webhook 已豁免；阈值不当会误伤，需 security-auditor 复核默认阈值与 fail-open 策略。**admin 容器现状无限流**（nginx-admin.conf 无 limit_req），P4.1 不含，另行决策。
- **D4/D5 资金链路（高）**：舍入方向变更影响每笔订单金额（少收→标准半舍入），需 security-auditor 评审 + 双人复核；历史数据只记录不修补。
- **依赖**：无新增 composer 依赖；无新表；nginx 配置变更需重载。

```yaml
design:
  objective: "P4.1 统一限流全路由生效（含 graphql）+ P4.2 多币种舍入策略对齐、账务恒等式零漂移"
  files_affected:
    - service/common/Security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/Money/Money.php (new)
    - service/app/Payment/Service/PaymentRouter.php
    - service/app/Payment/Service/Channels/StripeChannel.php
    - service/app/Order/Service/OrderService.php
    - service/app/Order/Model/Coupon.php
    - service/app/Payment/Controller/PaymentController.php
    - service/app/Report/Controller/ReportController.php
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
