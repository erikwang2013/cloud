# CloudPlatform 团队规划

> 版本：2026-08-17（v2）｜v1 由多智能体流水线编制（PASS_WITH_FIXES）；v2 基于 Phase 0-2 实际执行结果由 Lead 更新
> 依据：v1 + Phase 0-2 全部提交（git 111 commits）+ 双人复核记录 + 实测测试基线

## 1. 现状总览（2026-08-17）

### 1.1 阶段完成度

| 阶段 | 状态 | 关键产出 |
|------|------|----------|
| Phase 0 止血 | ✅ 4/4 | 发票真实渲染、通知模板 6 类、对账显式 unverified、CSP 头/环境模板 |
| Phase 1 近期 | ✅ 8/8 | 购物车改数量、评价状态统一、对账真实化（Stripe 报表+按日）、退款条件校验（72h/5 天+幂等+TOCTOU 索引）、供应商 7 类 webhook、Feature Flags 接线+管理端、文档同步、真实测试 |
| Phase 2 中期 | ✅ 8/8 | 资金守卫 4 项、service/admin 测试债、install.sql 31 表、RbacMiddleware 挂载 57 路由、admin 入镜像+nginx 8788+CI 双端、audit 回归+login 全链路 |
| Phase 3 远期 | ✅ 9/9 | 网关+统一限流（P4.1）、多币种全链路（P4.2）、HarmonyOS 工程化+CI（P4.3）、ES 落地（P4.4）、观察项消化（P4.5）、文档背离 4 项（P3.1）、权限收敛（P3.2）、订单幂等键（P3.3）、供应商评分校验（P3.4）、i18n 7 语言（P3.6）；reviewer-gate 独立复核全 approve |

### 1.2 质量基线（实测，提交后串行验证）

- service 套件：**568 tests / 1279 assertions**，10 skip（全部为 DB 环境缺口）
- admin 套件：**255 tests / 887 assertions**，1 skip（DB 写路径）
- CI 6 job：PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check /（docker 相关）
- 资金/安全全部双人复核（security-auditor + reviewer 独立结论一致）；git 按任务分组提交，工作树干净
- 附带给付：9 个 Encryptable 模型凭据序列化隐藏（P1/P2 全量排查）

## 2. 遗留与风险清单（2026-08-17 复核）

### 2.1 阻塞部署项（高优先）

- **DB_PASSWORD 环境缺口**：service/.env 为空串 → 全部 DB 端点 500，9+1 个 skip 测试的根因。非代码问题，需运维填实值（根 .env.example 已有模板）
- **HarmonyOS 工程脚手架缺失**：apps/harmonyos 仅 3 个 .ets（LoginPage/AuthManager/ApiClient），缺 hvigor/DevEco 全部工程配置 → 无法构建；CI harmonyos-check 已诚实报错（exit 1）

### 2.2 文档-代码背离（P1 未决 4 项）

- GET /api/v1/orders status 过滤未实现
- WebSocket 推送事件缺失（websocket_push 相关文档有声明）
- ticket.updated 触发范围不明
- product_attributes 死 schema（无代码使用）

### 2.3 资金/安全观察项（双人复核记录，low 级）

- **订单无幂等键**：同一 cart 重复提交可生成双单（medium，建议排期）
- 供应商评分不校验订单归属/状态
- fee bcmath 截断（第 5 位小数，方向少收 <0.0001/笔；与路由一致无对账偏差）
- WAF multipart 大 body 仍读 raw（json 场景由 $input 覆盖，multipart 为额外防御面）
- user_coupons 无唯一约束（语义允许一用户多单多行，观察）
- nginx-admin 未加 CSP（admin 为 Layui 前端含内联脚本，保留现状）

### 2.4 权限模型不一致（P2 新发现，待收敛）

- DB-only 6 个权限标识 / Rbac-only 19 个 / 角色分配差异（support/supplier）
- AdminRoleMiddleware 排除 finance，而 Rbac.php 定义了 finance 角色

### 2.5 其他

- i18n 新语言文件为英文原文（T6），7 语言未完成
- HarmonyOS CI 结构检查待脚手架补齐后升级为真实 hvigor 构建

## 3. 路线图

优先级原则（不变）：**资金/安全 > 交付可靠性 > 核心业务闭环 > 体验与扩展**。

### Phase 3 — 残留收口（1 个月）

**目标**：关闭全部背离与观察项，部署可复现（DB 全链路测试实跑绿）。

| 任务 | 涉及 | 角色 | 依赖 |
|------|------|------|------|
| 文档-代码背离 4 项收口（orders status 过滤实现 / WebSocket 推送接线 / ticket.updated 修正 / product_attributes 删或实） | Order、WebSocket、Ticket、Product、docs | coder + researcher | 无 |
| 权限模型收敛（DB/Rbac 差异对齐 + 角色种子 + AdminRoleMiddleware 复核） | Rbac、install.sql、admin | coder + security-auditor | 无 |
| 订单幂等键（cart→order 防双单） | OrderService | coder | 无（资金类双人复核） |
| 供应商评分校验订单归属/状态 | Supplier、Review | coder | 无 |
| DB_PASSWORD 运维接通 + 10 个 skip 测试实跑 | 运维、tests | security-auditor | 运维配合 |
| i18n 7 语言翻译补全 | i18n 文件 | coder | 无 |

**验收**：4 项背离关闭；权限矩阵 DB/代码一致；幂等键测试；DB 全链路测试实跑绿；i18n 至少中英可用。

### Phase 4 — 架构演进（1-3 个月）

**目标**：四层架构成型，支撑多端多币种增长。

| 任务 | 涉及 | 角色 | 依赖 |
|------|------|------|------|
| 独立 API 网关 + 统一限流挂载（含 graphql 缺口） | gateway、route | architect + coder | P3 |
| 多币种全链路一致性（含 fee 四舍五入策略） | Payment、Billing | architect + performance-engineer | 同上 |
| HarmonyOS 工程化：脚手架 + CI 真实构建 + 登录打通 | apps/harmonyos | mobile-dev | 无 |
| ES 审计落地，替换绕行方案 | docker、Product 搜索 | coder | 无 |
| 观察项批量消化（WAF multipart / user_coupons 约束 / 供应商 webhook 端到端） | Security、Order、Supplier | coder + tester | 无 |

**验收**：k6 验证限流全路由生效；多币种核算零误差；HarmonyOS 出包过 CI；ES 搜索真实可用。

## 4. 团队分工

固定核心：Lead(planner) / architect / coder / tester / reviewer / researcher
按需拉入：mobile-dev / security-architect / security-auditor / performance-engineer

| 阶段 | 拉入角色 | 说明 |
|------|----------|------|
| P3 | coder（主力）、researcher、security-auditor | 收口为主；权限/幂等双人复核 |
| P4 | architect、coder、mobile-dev、performance-engineer | 架构演进；security-architect 常驻顾问 |

协作模式不变：CLAUDE.md 管道（architect→coder→tester→reviewer），P3/P4 内部任务 fan-out 并行；**资金/安全任务强制双人复核**；每阶段结束更新本文档（本 v2 由 Lead 直接编制，未走流水线，可复核）。

## 5. 风险跟踪方式

- 本清单随每阶段结束滚动更新；新发现（如 P2 的权限模型不一致、订单幂等）即时并入
- 已知低优先级（供应商 webhook 端到端、multipart body）已入 P4 消化批次，不在清单外扩散

## 6. 主要证据出处

- 提交：git log（111 commits，Phase 0-2 按任务分组）
- 测试基线：service/admin 套件实测输出
- 复核记录：P1/P2 双人复核消息（资金守卫、logout/WAF、RBAC、audit 回归）
- 文档：v1（docs/team-plan.md 历史）、docs/audit-report-2026-08-06-v3.md、docs/api-reference.md
