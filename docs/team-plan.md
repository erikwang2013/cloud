# CloudPlatform 团队规划

> 版本：2026-08（v1）｜生成方式：多智能体团队流水线（6 研究员并行调研 → 架构师综合 → 规划师编制 → 评审员校验，评审结论 PASS_WITH_FIXES，修正项已并入）
> 依据：代码实证 + 项目文档（`docs/architecture.md`、`docs/features.md`、`docs/api-reference.md`、`docs/editions.md`、`docs/review-report-2026-08-04.md`、`docs/audit-report-2026-08-06-v3.md`、`docs/deployment.md`、`docs/supplier-api.md`）

## 1. 现状总览

### 1.1 领域完成度（已实现 vs 已规划）

| 领域 | 完成度 | 关键结论 |
|------|--------|----------|
| 用户/认证/增长 | ~90% | editions.md 用户系统 19 项几乎全覆盖：JWT 轮换+黑名单、TOTP 2FA、OAuth 7 家、KYC、GDPR、推荐分销均已实现 |
| 资金/支付/结算 | ~80% | 多通道路由、Stripe 原子入账、按量计费管线齐全；对账环节薄弱（本地自比恒 0） |
| 商品/订单 | ~75% | 主链路齐（ES 搜索、购物车、优惠券、退款）；购物车改数量、商品属性、退款条件缺失 |
| 供应商/工单/通知/平台 | ~70% | 功能面广（外部 API、SLA、WebSocket、GraphQL、监控）；通知模板缺失致静默失效 |
| 后台/客户端/基建 | ~65% | admin 成熟（51 控制器+ACL）；HarmonyOS 仅登录骨架无法构建、admin 未入 Docker 镜像 |

### 1.2 四层架构体检

- **业务服务层：最成型**。认证/订单/支付/计费/供应商/工单/通知/报表模块齐全（`service/app/*`、`service/common/*`）。
- **基础设施层：部分成型**。Docker Compose + CI 4 job + k6 + cron 10 任务齐备；但 admin 未入镜像、nginx 无 admin server、ES 部署不可用、`DB_PASSWORD` 环境缺口。
- **客户端层：薄弱**。Flutter 31 个 dart 中等完成；HarmonyOS 仅登录骨架、缺工程配置无法构建；CI 未覆盖两端。
- **API 网关层：最薄弱**。无独立网关，限流未统一挂载（graphql 规则未挂 RateLimitMiddleware），文档与路由多处不一致。

## 2. 差距与风险清单

### 2.1 静默失效类（高优先）

- 通知模板缺失：`alert_*` / `ssl_expiring` / `domain_expiring` / `resource_expiring` / `email_verify` / `new_ip_login` 共 6 类不在种子模板（9 条）内，Dispatcher 遇缺失静默 return → 告警/到期/验证通知失效
- WebhookDispatcher 无业务调用点，admin 注册 webhook 永不触发
- 发票"PDF"实为 HTML 字符串以 `application/pdf` 头返回（InvoiceController.php:46-61）
- PaymentReconcile 未拉取通道报表，仅本地 sum 自比（channel/system 同源，diff 恒 0、无告警）
- 评价状态词混乱（store 写 pending、index 查 approved、Product::reviews() 查 published）→ 新评价永不展示

### 2.2 死配置与文档-代码背离

- Feature Flags（totp/oauth/affiliate/websocket_push 等）已定义未接线，开关不影响行为
- RbacMiddleware / Rbac::hasPermission 已实现但未挂载任何路由
- 文档背离：totp 恢复码端点、OAuth 422/403、ticket.created vs ticket.updated、payment-methods POST/GET、GET /api/orders status 过滤、api-reference 190+ 端点与实现不一致处

### 2.3 资金守卫

- SuspendCheck 仅查 USD 余额解挂，与多币种扣款不一致
- Admin 供应商 approveWithdraw 无状态守卫可重复审批（对比 AffiliateService::approvePayout 有行锁+守卫）
- 下单用券后 used_count 不递增；user_coupons 表无代码使用
- 交易 channel_fee 恒写 0，路由算出的手续费未落库
- 退款无 72h/5 天等条件校验，仅状态机

### 2.4 债务与审计遗留

- install.sql 缺 13 张新表 DDL（coupons / order_invoices / supplier_* / affiliate_* / resource_metrics 等）
- 测试缺口：User 仅 2 用例；Order 测试为恒真断言伪测试；Payment/Billing/Report/Supplier/Ticket/Graphql/Monitor 零测试；admin 51 控制器无直接用例；CI 仅 PHP
- audit v3 遗留：缺 CSP 头、WAF 每请求全量读 body、环境缺口 `DB_PASSWORD` 缺失、`METRICS_PORT=9199` 绕行 9100 被占用、§六-3 登录全链路重测未完成
- review-report T6：新语言文件为英文原文（i18n 7 语言未完成）

## 3. 路线图

优先级原则：**资金/安全 > 交付可靠性 > 核心业务闭环 > 体验与扩展**。每阶段验收可度量，防止"规划了未实现"复发。

### Phase 0 — 止血（1 周，必做）

**目标**：清除资金类静默失效与环境缺口。

| 任务 | 涉及 | 角色 | 依赖 |
|------|------|------|------|
| 发票 PDF 改真实渲染 | InvoiceController | coder | 无 |
| 通知模板补全 6 类 | NotificationDispatcher、种子迁移 | coder | 无 |
| 对账未拉通道报表时显式报错（而非恒 0 diff） | PaymentReconcile | coder | 无 |
| 补 CSP 头、DB_PASSWORD / METRICS_PORT 环境缺口 | nginx、docker | security-auditor | 无 |

**验收**：4 项修复均有代码改动 + 日志/测试证据；线上对账不再静默恒 0。

### Phase 1 — 近期（1 个月）

**目标**：接通"已规划未实现"高价值功能，消除文档-代码背离。

| 任务 | 涉及 | 角色 | 依赖 |
|------|------|------|------|
| 购物车改数量 PUT /api/cart/{id} | Order/Cart、route | coder | 无 |
| 评价状态统一为单枚举，修复永不展示 | ReviewController、Product | coder | 无 |
| 对账真实化：拉通道报表 + reconcile 支持 date | PaymentReconcile、Admin PaymentController | coder | P0 |
| 退款条件校验（服务器 72h / 域名 5 天等） | RefundService、Admin OrderController | coder | 无 |
| 供应商 7 类 webhook 接线 + 外部商品控制器 | Supplier、common/Webhook | coder | 无 |
| Feature Flags 接线 | features.php、admin | coder | 无 |
| 文档同步（totp/OAuth/ticket 事件名/payment-methods/orders status/商品属性） | docs/* | researcher + reviewer | 任务完成后 |
| 新增功能回归测试 + 替换 Order 恒真断言伪测试 | service/tests/Order | tester | 同上 |

**验收**：每项含测试且文档-代码一致；评价可正常展示；对账 diff 非恒 0；管理端 flags 生效。

### Phase 2 — 中期（1–3 个月）

**目标**：清测试债与部署一致性，达成审计/CI/部署三闭环。

| 任务 | 涉及 | 角色 | 依赖 |
|------|------|------|------|
| Order/Payment/Billing/Report/Supplier/Ticket/Graphql/User/Auth 真实测试 | service/tests | tester | P1 |
| admin 51 控制器补直接用例 | admin/tests | tester | 同上 |
| CI 纳入 Flutter / HarmonyOS 构建 | .github/workflows | coder | 无 |
| admin 入 Docker 镜像 + nginx 补 :8788 server | docker/ | coder | 无 |
| install.sql 补 13 张缺失表 DDL | install.sql、migrations | coder | 无 |
| 资金守卫修复（SuspendCheck 多币种 / approveWithdraw 状态守卫 / used_count / channel_fee） | Billing、Admin Supplier、Order | coder + security-auditor | P1 |
| RbacMiddleware 挂载 + 权限模型校验 | route.php、common/Auth | coder | 无 |
| audit 修复项回归覆盖 + 重跑 login 全链路 | tests、manual 验证 | security-auditor | 无 |

**验收**：核心模块覆盖率 ≥60%；CI 全绿含双端构建；按 docs/deployment.md 可复现部署；install.sql 表全齐。

### Phase 3 — 远期（3 个月+）

**目标**：四层架构成型，支撑多端与多币种增长。

| 任务 | 涉及 | 角色 | 依赖 |
|------|------|------|------|
| 独立 API 网关 + 统一挂载限流 | gateway、route、security | architect + coder | P2 |
| 多币种全链路一致性 | Payment、Billing | architect + performance-engineer | 同上 |
| HarmonyOS 工程化：构建配置 / CI / 登录打通 | apps/harmonyos | mobile-dev | 无 |
| ES 审计落地，替换绕行方案 | docker、Product 搜索 | coder | 无 |

**验收**：k6 验证限流全路由生效；多币种核算无误差；HarmonyOS 可构建出包并通过 CI。

## 4. 团队分工

固定核心（常驻）：Lead(planner) / architect / coder / tester / reviewer / researcher
按需拉入（specialist）：mobile-dev / security-architect / security-auditor / performance-engineer / perf-analyzer

| 阶段 | 拉入角色 | 说明 |
|------|----------|------|
| P0 | coder、security-auditor | 止血为主 |
| P1 | coder（主力）、tester、researcher、reviewer | 功能接通 + 文档同步 |
| P2 | tester（主力）、coder、security-auditor | 测试债 + 部署一致性 |
| P3 | architect、coder、mobile-dev、performance-engineer | 架构演进；security-architect 常驻顾问 |

协作模式：CLAUDE.md 管道（architect→coder→tester→reviewer），P1/P2 内部任务可 fan-out 并行；**资金/安全任务强制双人复核**（security-auditor + reviewer 独立结论一致才放行）；每阶段结束输出验收报告，达标才进入下一阶段。

## 5. 评审结论与遗留

- **评审结论**：PASS_WITH_FIXES（修正已并入：通知模板 3→6 类、P0 改必做、资金守卫双人复核、User/Auth 测试入列、文档同步范围扩大）
- **显式留存的已知风险**（暂不排期，须在风险清单中跟踪）：
  - 供应商评分不校验订单归属/状态（建议随 P2 权限任务一并处理）
  - payment-methods 路由背离（已并入 P1 文档同步）
  - 新语言文件为英文原文（T6，建议随 i18n 专项排期）

## 6. 主要证据出处

- 代码：`service/app/*`（20+ 模块）、`service/common/*`、`admin/app/*`、`apps/flutter|harmonyos`、`docker/*`、`.github/workflows/ci.yml`、`service/config/{route,security,features,cron}.php`
- 文档：`docs/features.md`、`docs/api-reference.md`、`docs/editions.md`、`docs/architecture.md`、`docs/supplier-api.md`、`docs/admin-design.md`、`docs/deployment.md`
- 审计/审查：`docs/audit-report-2026-08-06-v3.md`、`docs/review-report-2026-08-04.md`、`docs/security-audit-2026-08-04.md`
