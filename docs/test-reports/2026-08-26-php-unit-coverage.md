# PHP 单元测试覆盖补全报告（2026-08-26）

## 环境

- PHP 8.3.7（service 套件 PHPUnit 10.5.64 / admin 套件 PHPUnit 11.5.56）
- service/：业务 API；admin/：管理后台
- 测试数据：SQLite `:memory:`（Capsule 初始化，照抄现有 ReportServiceTest / OrderIdentityTest 模式）；外部服务（Redis/MySQL/Stripe）全降级或 mock

## 盘点结论：模块 vs 覆盖

### service/app（27 模块）

| 模块 | 盘点前测试 | 覆盖状态 |
|------|-----------|----------|
| order / payment / user / product / provisioning / supplier / affiliate / notification / webhook / websocket / graphql / grpc / monitor / billing / captcha / domain / ssl / storage / report / ticket / admin / security / confirmation / version / clientplatform | 各 1-12 个测试文件 | 已覆盖 |
| **command**（6 个命令） | **无** | **0 覆盖 → 本轮补 ReconcileCommandTest** |
| **cron**（6 个任务） | 仅 SupplierSettlementTest | 部分覆盖 → 本轮补 PaymentReconcileTest + ExchangeRateSyncTest |
| controller（Health/Help/Status/Upload） | 无 | 薄控制器（静态状态/健康检查），无业务逻辑 |
| model（payment/order 等 20+ 模型） | 经服务层间接覆盖 | 已覆盖 |

### admin/app（controller/common/model/middleware）

| 模块 | 盘点前测试 | 覆盖状态 |
|------|-----------|----------|
| controller（48 个控制器） | AdminControllersTest（全控制器反射：模型装配/CRUD 面/GET 视图路径）+ CrudHashidsTest | 已覆盖 |
| middleware | AccessControlMiddlewareTest | 已覆盖 |
| common | TreeTest / HashidsTest / BaseJsonTest | 部分覆盖 → 本轮补 UtilTest + LayuiTest + ExcelExportTest |
| model | 无直接测试 | 本轮补 DictTest；其余模型为薄映射 |

## 本轮新增测试

| 模块 | 新增文件 | 用例 | 断言 | 覆盖点 |
|------|----------|------|------|--------|
| Cron（资金对账） | `service/tests/cron/PaymentReconcileTest.php` | 7 | 24 | compare 按币种最小单位精度 half-up 舍入：子分残余 verified 且 diff 归零；真实差异 mismatch；零小数币种（JPY）整数进位；币种仅单侧存在；空侧 verified；非法日期抛 InvalidArgumentException；run() 对无报表通道 upsert unverified 行（仅 success 计入本地汇总，failed 排除，唯一索引镜像生产） |
| Cron（汇率同步） | `service/tests/cron/ExchangeRateSyncTest.php` | 2 | 2 | API 不可达安静完成（不抛给调度器）；合法 payload + Redis 不可用时不崩溃 |
| Command（对账命令） | `service/tests/command/ReconcileCommandTest.php` | 2 | 3 | 非法日期 → FAILURE + 错误消息；合法日期 → SUCCESS（空通道表） |
| Admin Common | `admin/tests/UtilTest.php` | 17 | 47 | 密码 hash/verify 往返；humanDate 五档相对时间；formatBytes；checkTableName/filterAlphaNum/filterNum/filterUrlPath/filterPath 校验（含 BusinessException）；controllerToUrlPath（含 @action 与非法输入）；camel/smCamel；getCommentFirstLine；typeToControl/typeToMethod；getLengthValue（decimal/enum/varchar）；getControlProps（select data 转 value/name 列表 vs 普通 key=>value） |
| Admin Model | `admin/tests/DictTest.php` | 5 | 10 | 字典名↔option 名转换；filterValue 格式校验；名称必须含字母；save/get/delete 全链路（SQLite 内存库，同名覆盖语义）；缺失返回 null |
| Admin Common | `admin/tests/ExcelExportTest.php` | 4 | 9 | 表头写入 + 加粗；数组字段 JSON 展平；逐行追加行号；缺失列空单元格（PhpSpreadsheet 内存断言，不落盘） |
| Admin Common | `admin/tests/LayuiTest.php` | 5 | 9 | input 渲染 name/value；inputNumber 强制 number 类型；label HTML 转义（防属性注入）；switch 渲染 lay-skin；html() 缩进重排 |

本轮新增 42 用例 / 104 断言。金额相关断言全部 `assertSame` 字符串精确比较（bcmath），无浮点。

## 测试环境修复（非业务代码）

1. **service/vendor 损坏**：`composer.lock` 已被升级（encryptable v2.0.2→v2.0.3 等多包）但 vendor 未同步，guzzle 缺失导致套件无法启动 → `composer install` 恢复，两套件可跑。
2. **UserModelTest 加密夹具失效**：encryptable v2.0.3 强制 32 字节密钥（默认 aes-256-gcm），旧夹具 16 字节 → 失败。修复：`service/tests/user/UserModelTest.php` setUp 钉死 32 字节密钥 + aes-256-gcm，并调用 `Encryption::setFallbackConfig(null)` 重置包进程级静态缓存 —— `tests/user/AuthFullChainTest.php` 会把 `service/.env`（cipher=aes-128-ecb、24 字符非 base64 密钥）注入 `$_ENV/$_SERVER`，静态 `$resolved` 缓存导致跨测试污染，单独跑通过、全量跑失败。该修复同时让后续依赖 Encryptable 的测试获得一致环境。

## 业务代码问题

本轮未发现业务 bug。`PaymentReconcile::compare` 两个易误判语义按实际实现断言并注释：diff 为原始总额差（非单位舍入差）；零小数币种进位后 mismatch 的 diff 为原始差（如 JPY 1234 vs 1234.5000 → diff -0.5000）。

## 全量结果

| 套件 | 用例 | 断言 | 失败 | 错误 | 跳过 |
|------|------|------|------|------|------|
| service | 672 | 1632 | 0 | 0 | 15 |
| admin | 286 | 962 | 0 | 0 | 1 |

- 基线对比：service 661→672（+11），admin 255→286（+31）；两套 0 failure / 0 error。
- 语法检查：新增及修改文件全部 `php -l` 通过。

## 遗留缺口与原因

| 缺口 | 原因 |
|------|------|
| cron/CronRunner、cron/SslCertificateCheck | 调度上下文 + 真实 TLS 证书探测，单测成本高 |
| command/Migrate*、DbBackupCommand、I18nSyncCommand | 依赖真实 MySQL 迁移/文件系统，需集成环境 |
| admin/common/Auth（getScopeRoleIds/isSuperAdmin） | 依赖会话与 DB 权限数据 |
| admin/common/Migration*、Layui::buildTable/buildForm | 依赖 DB information_schema / 全表结构 |
| service/controller 薄控制器（Health/Help/Status/Upload） | 无业务逻辑，返回值由 webman 运行时提供 |
| graphql/GraphqlController | 依赖 webman `json()`/`config()` 助手与 FeatureFlags 运行时，Schema 已由 SchemaTest 覆盖 |
| monitor/ResourceMonitor | 依赖 Redis + 真实 provider 调用，需 mock 层或集成环境 |
