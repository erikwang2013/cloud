# CloudPlatform 审查报告（第三轮，2026-08-06）

> 范围：整体实测（启动服务 + 冒烟测试）+ 深入代码检查 + 生态/安全配置完整性核查。
> 本轮从"静态可读"推进到"**可运行**"：修复 5 处启动级 P0 与 3 处运行级 P0/P1，服务在完整中间件链下冒烟通过。
> 测试基线：service **316/316 通过（502 断言）**；admin **67/67 通过（124 断言）**。

---

## 一、本轮修复清单（全部已实测验证）

### P0 — 启动级（worker 崩溃 / 全站不可用）

| # | 问题 | 根因 | 修复 |
|---|------|------|------|
| 1 | `A facade root has not been set` → 启动崩溃 | bootstrap 未给 Illuminate Facade 设置容器 | `Facade::setFacadeApplication($capsule->getContainer())`（bootstrap.php:149） |
| 2 | `Target class [events] does not exist` | 事件监听用 Event Facade，但容器无 events 服务 | 改用 `Dispatcher` 实例：`$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()`（3 个监听器） |
| 3 | `Class support\SentryBootstrap not found` | composer.json psr-4 缺 `support\` 映射 | 补 `"support\\": "support/"` + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` 为空 → 加密服务崩溃 | .env 空值（phpdotenv createUnsafeMutable 覆盖注入） | 生成 32 字节 base64 密钥写入 .env |
| 5 | 全部 `/api/*` 路由 404 | `ApiRequest::path()` 把 `/api/xxx` 重写为 `/api/v1/xxx`，而路由注册无版本前缀 | 移除重写逻辑，路径保持原样（版本校验由 VersionMiddleware 基于 X-Api-Version 头） |
| 6 | `Class "ErikJwt\JWTFactory" not found` | 使用了不存在的 `ErikJwt\` 命名空间 | 改为包内真实命名空间 `Erikwang2013\Jwt\*` |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` 返回 null → `createFromConfig()` 类型错误 | webman `Config::loadFromDir` 要求插件目录必须有 `app.php`（否则整目录跳过）；jwt 插件目录缺失 | 补 `config/plugin/erikwang2013/jwt/app.php`（`'enable' => true`，与 vendor 模板一致） |

### P0 — 运行级（首个请求即 500）

| # | 问题 | 根因 | 修复 |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware 直接静态调 ext-redis `\Redis::get()` | 改用 `\support\Redis::get/setex/incr` |
| 9 | `Class support\Redis not found` | `support\Redis` 属 webman 骨架层（webman/webman 包），本项目只装 framework 故缺失 | 新建 `support/Redis.php`（底层用已有 illuminate/redis + config/redis.php） |
| 10 | AuthController 的 `Illuminate\Support\Facades\Redis::*` 解析成**裸 phpredis 实例**（未连接）→ "server went away" | 容器无 `redis` 绑定，自动装配 fallback 到 `Redis` 类 | bootstrap 注册 `$container->singleton('redis', fn() => support\Redis::manager())` |
| 11 | `Call to undefined function storage_path()` | `storage_path()` 属骨架 helpers，本项目缺失 | bootstrap 补 helper（`base_path()/storage`，function_exists 守卫） |

### P1 — 边界校验

| # | 问题 | 修复 |
|---|------|------|
| 12 | `/api/auth/refresh` 缺 refresh_token 时 TypeError 500 | AuthController::refresh 补 `is_string` 校验 → 422 |

### 临时状态恢复

- `config/server.php`（8787）、`config/process.php`（9100/8282）、`config/middleware.php`（完整 11 层链）已从 git 恢复原样
- bootstrap.php 的 `[AUDIT]` 调试 error_log 已移除

---

## 二、冒烟测试结果（完整中间件链，端口 8787）

| 端点 | 结果 | 说明 |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | 返回 click 验证码图片 |
| POST /api/auth/login（缺验证码） | 422 | captcha 校验生效 |
| POST /api/auth/register（空参） | 422 | 字段校验生效 |
| POST /api/auth/refresh（缺 token） | 422 | 本轮修复项 |
| POST /api/auth/forgot-password | 500（DB 拒绝连接） | **环境缺口**：.env 缺 DB_PASSWORD，见 §四 |
| GET 带 X-Api-Version: v99 | 400 | VersionMiddleware 生效 |
| GET /api/nonexistent | 404 | 正常 404 页 |

Redis 路径（验证码、限流、JWT 黑名单存储）全部实测可用。

---

## 三、安全防护核查

### 已达标 ✓

- **密钥管理**：全项目无硬编码密钥/口令（grep 扫描）；密钥全部走 `getenv()`；.env 已 gitignore
- **SQL 注入**：无字符串拼接 SQL；全部走 Eloquent 查询构造器
- **输入校验**：上传 type 白名单 + finfo 内容嗅探 + 分类型大小上限；auth 端点字段级校验
- **限流**：公开敏感端点全覆盖（login 5/min、register 3/min、sms 5/h、captcha 30/60s、oauth 10/60s、password_reset 3/5min），default 60/min
- **JWT**：HS256 + 32 字节密钥；access/refresh 分离；type 校验；Redis 黑名单（库内按 jti 校验）；TOTP 强制 + 失败锁定
- **CORS**：Origin 白名单（`CORS_ALLOWED_ORIGINS`），无通配、无凭据头
- **安全头**：nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS（env 开关）
- **防枚举**：forgot-password 对不存在用户返回一致成功消息

### 建议（低优先，未改）

| 项 | 说明 |
|----|------|
| 缺 CSP 头 | 全站未配置 Content-Security-Policy；API JSON 场景风险低，建议在 SecurityHeadersMiddleware 补 `default-src 'none'` 级别策略 |
| WAF 性能 | WafMiddleware 每请求 `file_get_contents('php://input')` 读全量 body 扫描（31 种模式），高流量下有内存/CPU 开销，建议仅对 POST/PUT 且 Content-Type 匹配时读 body |
| HealthController `shell_exec('git rev-parse')` | 每个 health 请求起子进程；生产建议只用 `APP_VERSION` env，shell 仅本地开发 fallback |
| ~~RateLimit TOCTOU~~ | ~~check-then-set 非原子~~ **已修复（2026-08-07）：** 改为原子 `INCR` + 首次 `EXPIRE`，见 §七-6 |
| X-XSS-Protection | 已弃用头，保留无害；CSP 到位后可移除 |

---

## 四、环境缺口（非代码问题，需运维补）

1. **`.env` 缺 `DB_PASSWORD`**（唯一阻塞项）：docker-compose 以 `${DB_PASSWORD}` 创建 app_user，本地 .env 该键缺失 → 所有 DB 端点 500。`DB_PASSWORD` 已在 `.env.example` 定义，属部署凭据，需用户补入 `.env`。
2. **9100 被本机 dart 进程占用**：metrics 进程默认端口绑定失败会**阻止整组启动**（webman 启动前全端口预检）。已持久化绕行：`.env` 写入 `METRICS_PORT=9199`（2026-08-07）。dart 释放 9100 后可改回默认。
3. **composer validate fatal**（第三方）：`erikwang2013/security-php` 的 composer 插件与 composer 自身 eval 冲突（`isLaravel()` 重复声明），与本项目代码无关；CI 中 `composer validate --strict` 步骤可能因此失败，建议 CI 该步骤加 continue-on-error 或跳过 service 包。
4. 上轮记录的 8787 被 erp-php 占用已解除（本轮实测可绑定）。

---

## 五、生态配置核查

| 项 | 结果 |
|----|------|
| CI（.github/workflows/ci.yml） | 完整：PHP 语法检查 + admin/service 测试（PHP 8.2/8.3 矩阵）+ composer validate |
| 迁移 | 30 个 migration 文件 |
| Docker | compose（MySQL+Redis+app）、Dockerfile、nginx.conf、prometheus、grafana、supervisor（nginx+webman） |
| 监控 | MetricsServer（Prometheus 独立端口）+ websocket 进程（process.php） |
| 压测 | tests/k6（smoke/products/concurrent） |
| .env.example | 键比 .env 更全（OAuth/Feature 开关等均覆盖）；.env 无超集键 |
| composer audit | 无安全漏洞；1 个弃用包 doctrine/annotations（hg/apidoc 依赖，评估保留） |
| 队列/异步 | webman/redis-queue 已装；通知走 NotificationDispatcher |

---

## 六、遗留建议（后续迭代）

1. **CSP 头**（见 §三）
2. **WAF body 读取优化**（见 §三）
3. **补 DB_PASSWORD 后重测 DB 全链路**（register→login→refresh→logout 真实流程 + JWT 黑名单失效验证）
4. **supervisor 无 cron 进程**：Billing\Cron\SuspendCheck 等定时任务无守护入口，建议确认部署侧 crontab 或补 process.php cron worker
5. **CI composer-validate 步骤**：因第三方插件冲突，建议加容错（见 §四-3）

---

## 七、第四轮补充修复（2026-08-07）

1. **计费原子性（P0 财务）**：`BillingEngine::runDaily()` 按资源包裹事务，扣款/挂起/事件标记同事务提交；`StripeChannel::confirmPayment()` 用 `UPDATE ... WHERE status='pending'` 原子抢占 + 订单行锁，防 webhook 重复入账。
2. **并发幂等（P0/P1）**：`AffiliateService::requestPayout()` 行锁 + 已存在 pending 提现直接返回；`SupplierSettlement`（cron 与 `generateSettlement`）按供应商+周期判重。
3. **数据正确性（P1）**：`MeterCollector` 修复 `$resource->first()` 意外全表查询；`ExchangeRateSync` 加 10s 超时。
4. **性能（P2）**：Dashboard 30 次 SUM 查询合并为单条 GROUP BY；`CacheService::forgetPattern()` KEYS→SCAN 游标；`I18n` 语言包按 locale 进程内缓存；`ImportExport` 导入整轮事务；`BillingEngine` 预取费率映射消除 N+1。
5. **安全（P1）**：`InternalTokenMiddleware` 用 `getRemoteIp()` 防 XFF 伪造；Webhook 注册拒绝私网地址（SSRF）；`JwtAuth` 空密钥 fail-fast；`DbBackupCommand` 密码改 `MYSQL_PWD` 防 `ps` 泄漏；CSV/Excel 导出防公式注入；供应商外部 API 挂上 `supplier_api` 限流。
6. **基础设施（P2）**：`RateLimitMiddleware` 原子 INCR（消除 TOCTOU）；`MetricsServer` 修 `onMessage` 类型崩溃循环；`HealthController` Redis 连接池化；补装 `symfony/mailer ^6.4`（EmailSender 原为隐雷）；admin 侧 `EncryptableBootstrap` 命名空间修正。

---

## 结论

本轮从"代码可读"推进到"**可启动、可运行**"：8 处 P0 级故障全部修复并实测，316 个测试全绿，完整中间件链冒烟通过。剩余阻塞仅一项环境缺口（DB_PASSWORD），补入后即可全链路验证。第四轮（2026-08-07）进一步完成计费原子性、并发幂等、限流/注入防护等 20+ 项加固。
