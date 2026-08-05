# CloudPlatform 整体审查报告

**日期**: 2026-08-06
**审查范围**: service 全量（app / common / config / tests）+ 生态配置 + 安全防护
**方法**: PHPUnit 测试套件、全量 PHP 语法检查、路由/中间件审计、OAuth 新功能代码审查、环境变量与配置一致性核对、依赖安全审计、冒烟测试

---

## 一、总体结论

| 维度 | 结论 |
|------|------|
| 测试 | **314 项全部通过**（修复 2 个 bug 后，494 assertions） |
| 语法 | 287 个 PHP 文件 0 语法错误 |
| 依赖安全 | composer audit 无已知漏洞；1 个废弃包（doctrine/annotations） |
| 安全架构 | 多层防护齐全（WAF 双引擎、CORS 白名单、传输加密、字段加密、bcrypt cost=12、JWT 黑名单、审计日志） |
| 严重问题 | **1 个 P0（Apple id_token 未验签 → 可账号接管）、4 个 P1** |
| 生态配置 | **.env.example 缺 31 个在用变量**，含全部 OAuth 凭据；通知渠道为占位实现 |

---

## 二、测试结果

```
OK (314 tests, 494 assertions)
```

### 本次修复的 2 个 bug

| ID | 文件 | 问题 | 修复 |
|----|------|------|------|
| B1 | `service/common/Captcha/CaptchaService.php:31` | 读取 `$result['extra']['targets']`，但库返回 `extra.texts` → `target_count` 恒为 0 | 改为 `extra.texts` |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | 库默认 `targetCount = 5`，与库自身 README 契约（medium=3 目标）矛盾 → 3 项 Captcha 测试失败 | 默认值 5 → 3 |

> B2 属于 vendored 库 bug（vendor/ 已被 git 跟踪，修复可持久）。建议同时向上游仓库提交修复。

---

## 三、严重安全问题（P0 / P1）

### P0-1. Apple `id_token` 未验签 —— 可直接账号接管
**文件**: `service/app/User/Service/OAuthService.php:180-192`（`appleProfile()`）

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // 仅 base64 解码，无签名/iss/aud/exp 校验
```

攻击者可自行构造 `id_token` 伪造任意 email 完成 OAuth 登录。`resolveUser()` 会按 email 匹配已有用户并直接签发令牌 → **任意账号接管**。

**修复**: 用 Apple JWKS（`https://appleid.apple.com/auth/keys`）+ `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])` 验签，并校验 `iss=appleid.apple.com`、`aud=client_id`、`exp`、`nonce`。

### P1-1. OAuth 登录未校验 `email_verified`
**文件**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn 均返回 `email_verified` 字段，代码完全忽略。提供商上邮箱未验证的用户可用该邮箱直接绑定/接管已注册账号。GitHub 路径已校验 `verified`（正确），其余提供商需统一校验。

### P1-2. 速率限制中间件存在但从未挂载 —— 文档与实现不符
**文件**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` 中已配置 login=5/min、register=3/min 等限流规则
- `RateLimitMiddleware` **未被任何路由引用**（全库 grep 仅命中类本身）
- `docs/features.md` 声称登录"限流 5 req/min"、注册"限流 3 req/min" —— 实际不存在
- 历史审查报告（`security-audit-2026-08-04.md`）将该项标记为 OK，系只看配置未验证挂载，本次纠正

**影响**: 登录/注册/忘记密码/重置密码/恢复码/验证码等公开端点均可无限速爆破（登录仅靠 per-account 锁定，不防撞库与 IP 级刷量）。

**修复**: 将 `RateLimitMiddleware` 挂到 `/api/auth/*`、`/api/captcha/*` 等公开路由（可挂全局 `''` 组，按 `route` 参数区分）。

### P1-3. TOTP 2FA 未在登录流程强制执行
**文件**: `AuthService.php:64-97`（`login()`）+ `AuthController.php` + `config/features.php`

`user->totp_enabled` 仅在 `totpVerify/totpDisable/totpRecoveryCodes` 中检查，**`login()` 从不校验**。开启 2FA 的用户仍仅凭密码即获得有效 access token —— 2FA 形同虚设（`FEATURE_TOTP` 默认开启）。

**修复**: 登录时若 `totp_enabled`，签发临时令牌并要求 TOTP 校验通过后换发正式令牌（或要求 totp code 参数）。

### P1-4. 通知渠道为占位实现 —— 邮件验证/密码重置在生产环境不可用
**文件**: `app/Notification/Queue/EmailSender.php`、`SmsSender.php`、`PushSender.php`

三个消费者均仅 `error_log()` 模拟发送，且将 `send_status` 记为 `sent`。后果：
- **忘记密码流程断裂**：`AuthController::forgotPassword()` 生成验证码并"发送"邮件，但邮件永远不会送达 → 用户无法自助重置密码
- 注册邮箱验证、新 IP 登录告警同理失效
- `.env.example` 中 `SMTP_*`/`MAIL_FROM_*` 共 7 个变量无任何代码读取（死配置）

**修复**: 接入真实邮件发送（PHPMailer/SendGrid SDK），移除误导性的 `sent` 状态标记；或明确标记为未完成功能并从文档移除相关承诺。

---

## 四、安全问题（P2）

| ID | 文件 | 问题 |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | `type` 参数未白名单校验即拼入路径 `uploads/{$type}/...` → **路径穿越**可写出上传目录（文件名随机，无法覆盖，但可污染文件系统）；建议限制 type 到枚举白名单，并对存储目录加 `index.php`/`.htaccess` 防护 |
| P2-2 | 同上 | 仅校验扩展名，无 MIME 内容嗅探（polyglot 文件可被缓存/转发利用）；建议 `finfo` 校验真实 MIME |
| P2-3 | `AuthController.php:131-158` | 重置密码 6 位验证码 600s 有效、无尝试次数限制 → 10 分钟内可暴力枚举 100 万组合；`forgotPassword` 无频率限制 → 邮件轰炸 |
| P2-4 | `AuthController.php:333-348` | `totpRecoveryCodes` 生成/查看恢复码仅需登录，无需密码确认；应挂 `ConfirmationMiddleware` |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | 黑名单手工检查 key 为 `jwt_blacklist:{sha256(token)}`，与库的 `jwt_blacklist:{jti}` 格式不符 → 死代码（实际防护由库内 `decode()` 完成，生效但冗余），建议删除或改用库接口 |
| P2-6 | `OAuthService.php:67-94` | `authorizeUrl` 的 `redirect` 参数存入 state 后从未使用（死参数）；state 未绑定 provider；OAuth 全流程无 nonce（OIDC 提供商，防御纵深缺失，与 P0-1 一并修复） |
| P2-7 | `OAuthService.php:31-37, 236-238` | X (Twitter) v2 API `userinfo` 不返回 email → X 登录必然失败"Email not provided"，功能缺陷，需文档说明或改接 `/2/email` 端点 |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` 用 `strrpos($ip, '.')` 截 IPv4 网段，IPv6 客户端退化为空串 → 弱指纹；建议使用前 64 位或哈希整 IP |

---

## 五、生态配置完整性

### 5.1 .env.example 缺失变量（代码中 `getenv()` 引用但未定义）—— 31 个

| 类别 | 变量 |
|------|------|
| **OAuth 凭据（新增功能，完全未文档化）** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`、`_CLIENT_SECRET`、`_REDIRECT_URI`（21 个） |
| **Apple 专用** | `APPLE_TEAM_ID`、`APPLE_KEY_ID`、`APPLE_PRIVATE_KEY_PATH` |
| **关键功能** | `APP_URL`（验证邮件链接依赖，缺失导致邮件链接错误）、`APP_ENV`、`APP_VERSION` |
| **安全** | `INTERNAL_MONITOR_TOKEN`（/health/* 端点保护）、`MAINTENANCE_MODE`、`MAINTENANCE_ALLOWED_IPS`、`WEBHOOK_SECRET`、`JWT_LEEWAY` |
| **云/存储** | `AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY`、`BACKUP_S3_BUCKET`、`BACKUP_S3_REGION`、`DB_READ_HOST` |
| **Feature flags（8 个）** | `FEATURE_SSL_PRODUCT`、`FEATURE_OBJECT_STORAGE`、`FEATURE_USAGE_BILLING`、`FEATURE_PROMETHEUS`、`FEATURE_CDN_PRODUCT`、`FEATURE_SUPPLIER_RATING`、`FEATURE_AFFILIATE`、`FEATURE_GRAPHQL` |
| **其他** | `METRICS_PORT`、`WS_PORT`、`GEOIP_DB_PATH`（.env.example 中仅注释）、`SSL_STAGING`、`HASHIDS_ALPHABET`、`POSTER_IMAGE_DRIVER`、`EXCHANGE_RATE_API_URL`、`COUNTRY_SEASON_DEFAULT` |

### 5.2 .env.example 定义了但代码未使用 —— 7 个

`SMTP_HOST`、`SMTP_PORT`、`SMTP_USERNAME`、`SMTP_PASSWORD`、`SMTP_ENCRYPTION`、`MAIL_FROM_ADDRESS`、`MAIL_FROM_NAME`（邮件发送未实现，见 P1-4）

### 5.3 i18n 覆盖不一致

| 语言 | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- 非中英语言缺失一半以上翻译键；zh-CN 的 billing/health/storage 比 en-US 多 6-8 个键（同步方向反了）
- **OAuth 相关翻译键全部缺失**（错误消息为硬编码英文）

### 5.4 其他生态问题

| ID | 问题 |
|----|------|
| E1 | `service/composer.lock` 被 `.gitignore` 忽略且未提交 —— 应用依赖未锁定版本，部署不可复现（部署风险） |
| E2 | `service/.phpunit.cache/` 出现在 git status（未忽略） |
| E3 | 端口 8787 与本机另一项目 erp-php 冲突，cloud-php 在本机无法启动（已确认 8787 被 erp-php 的 WorkerMan 占用） |
| E4 | `docs/features.md` 声称的限流/邮件功能与实际不符（见 P1-2 / P1-4），文档需同步修正 |
| E5 | 依赖 `doctrine/annotations` 已废弃（composer audit 提示），建议评估移除 |

---

## 六、优化建议（非阻塞）

1. **DI 化服务创建**：`AuthController` 构造函数直接 `new AuthService()/OAuthService()`，建议接入容器（webman 原生支持），便于测试与替换。
2. **上传目录加固**：目录内放置 `index.html`、禁用 PHP 执行（nginx `location ~ \.php { deny all; }`）。
3. **WAF 正则收敛**：`security.php` 的 `sqli_patterns` 含 `\b(select|update|delete|...)\b` 等宽泛模式，全局限流下用户工单/评价中出现这些词会被误伤 403；建议仅对敏感参数生效或收紧正则。
4. **日志审计**：`AuditLogger::record('user_registered', ['user_id' => null])` 未记录新用户 ID，建议登记实际 ID。
5. **OAuth 测试覆盖**：`OAuthServiceTest` 覆盖了 URL 构造与 code 交换，但 `resolveUser()`（DB 路径）与 Apple 验签路径无测试；P0 修复后必须补充验签失败的测试用例。
6. **CI 接入**：项目有 `.github` 目录，建议添加 GitHub Actions：`composer install && phpunit` + `composer audit`，防止回归。
7. **HTTP 方法约束**：OAuth 路由同时注册 GET/POST callback 是合理的（Apple 需要），其余公开写操作已显式 POST，OK。

---

## 七、修复优先级清单

| 优先级 | 事项 | 工作量 |
|:---:|------|:---:|
| P0 | Apple id_token 验签（JWKS + iss/aud/exp/nonce） | 中 |
| P1 | OAuth 全提供商校验 `email_verified` | 小 |
| P1 | 挂载 RateLimitMiddleware 到公开路由 | 小 |
| P1 | 登录流程强制执行 TOTP | 中 |
| P1 | 实现真实邮件发送（或标注未完成） | 中 |
| P1 | .env.example 补齐 31 个缺失变量 + OAuth 配置文档 | 小 |
| P2 | 上传 type 白名单 + MIME 校验 | 小 |
| P2 | 重置码/忘记密码限流 | 小 |
| P2 | 恢复码接口挂密码确认 | 小 |
| P2 | 提交 composer.lock、gitignore .phpunit.cache | 极小 |
| P3 | 清理黑名单死代码、WAF 正则收敛、i18n 补齐 | 中 |

---

## 八、修复状态（2026-08-06）

| 优先级 | 事项 | 状态 |
|:---:|------|:---:|
| P0 | Apple id_token 验签（JWKS + iss/aud/exp/nonce） | ✅ 已修复 |
| P1 | OAuth 全提供商校验 `email_verified`（X 增加 /2/email 兜底） | ✅ 已修复 |
| P1 | 挂载 RateLimitMiddleware（auth/oauth/password/sms/captcha 路由 + 4 条新规则） | ✅ 已修复 |
| P1 | 登录流程强制执行 TOTP（错误 5 次锁定 15 分钟，独立计数防 DoS） | ✅ 已修复 |
| P1 | 真实邮件发送（symfony/mailer SMTP；未配置时 dev-stub 状态） | ✅ 已修复 |
| P1 | .env.example 补齐 31 个缺失变量 + OAuth 配置文档 | ✅ 已修复 |
| P2 | 上传 type 白名单 + finfo MIME 内容嗅探 | ✅ 已修复 |
| P2 | 重置码/忘记密码限流（错误 5 次 → 429 10 分钟） | ✅ 已修复 |
| P2 | 恢复码接口挂密码确认 | ✅ 已修复 |
| P2 | composer.lock 解除忽略并暂存、gitignore .phpunit.cache | ✅ 已修复 |
| P3 | 黑名单死代码清理、WAF 正则收敛（结构式 3 条）、i18n 补齐（zh-CN billing/health/storage 错误内容重写、trans() 实现 fallback_locale） | ✅ 已修复 |
| E3 | 端口 8787 被 erp-php 占用，本机无法启动 | ⚠️ 环境问题，部署环境无冲突 |
| E5 | doctrine/annotations 已废弃 | ⚠️ 评估后保留（hg/apidoc 直接依赖，移除会破坏 API 文档生成） |

补充测试：OAuth 12 项（含 nonce 参数、验签、email_verified 拒绝、X email 兜底）、WAF 收紧后 2 项。全量基线：**319/319 通过（505 断言）**。

*报告生成方式：PHPUnit 全量测试、`php -l` 287 文件、路由/中间件静态审计、env 使用与定义集合差集比对、composer audit、端口与进程探查。测试基线：314/314 通过。*
