# CloudPlatform 审查报告（第二轮，2026-08-06）

> 范围：上一轮（audit-report-2026-08-06.md）全部问题修复后的复检。
> 测试基线：PHPUnit **319/319 通过（505 断言）**；`php -l` 253 个 PHP 文件 **0 语法错误**。

---

## 一、测试与静态检查

| 项目 | 结果 |
|------|------|
| PHPUnit 全量 | OK（319 tests, 505 assertions） |
| `php -l`（app/common/config） | 253 文件全部通过 |
| composer audit | **无安全漏洞**；1 个弃用包 doctrine/annotations（hg/apidoc 直接依赖，评估保留） |
| composer.lock | 已纳入版本控制（暂存 A） |

---

## 二、生态配置核查

### 2.1 env 使用与定义 —— 完整 ✓

- 代码中全部 `getenv()` 键（含动态 `{PROVIDER}_OAUTH_*` 模式）均在 `.env.example` 中有定义或注释形式的可选配置（`#HASHIDS_ALPHABET`、`#POSTER_IMAGE_DRIVER`、`#EXCHANGE_RATE_API_URL`、`#COUNTRY_SEASON_DEFAULT`、`#SECURITY_HSTS_VALUE`）
- 模板冗余项（低危）：`MAIL_FROM_NAME` 在代码中无 `getenv()` 引用，仅模板保留

### 2.2 依赖锁定 ✓

- `service/composer.lock` 已提交；`.gitignore` 不再排除；`service/.phpunit.cache/` 已忽略

### 2.3 环境说明

- 本机端口 8787 仍被 erp-php 占用，cloud-php 无法本地启动（部署环境无冲突）
- `composer validate` 因 vendor 插件 `erikwang2013/security-php` 的 Installer 与 composer 自身 eval 冲突报 fatal（第三方包问题，非本项目代码）

---

## 三、安全防护核查

### 3.1 全局中间件链（11 层，覆盖所有路由）✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF（SQLi/XSS）→ SecurityPlugin（31 种攻击检测）
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 公开路由限流 —— 本轮修复 1 处

| 路由 | 中间件 | 限流规则 |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min、login 5/min |
| **forgot-password / reset-password** | **RateLimit（本轮补挂）** | password_reset 3/5min |
| oauthRedirect / oauthCallback（GET+POST） | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **修复**：`forgot-password`/`reset-password` 两路由上轮定义了 `password_reset` 规则但遗漏挂载中间件（邮件轰炸/验证码爆破面），本轮补挂。

### 3.3 上传文件暴露 —— 本轮修复 1 处（高危）

**问题**：`deployment.md` 的 nginx 配置 `location /storage/ { alias .../service/storage/; }` 将整个 storage 目录公开：

```
storage/
├── backups/    ← 数据库备份（.sql.gz）公开可下载
├── apple/      ← AuthKey.p8 私钥公开可下载（可签发 Apple 令牌）
├── firebase/   ← FCM 服务账号凭据（含私钥）公开可下载
├── geoip/      ← GeoLite2 数据库
└── uploads/    ← 上传文件（预期公开）
```

**修复**：deployment.md 与 docker/nginx.conf 均改为 `location ^~ /storage/uploads/`，仅暴露 uploads 子目录。

### 3.4 其他核查 ✓

- `verify-email`：一次性随机 token（验证后置空），无爆破/枚举面，无需限流
- 上传接口：type 白名单 + finfo MIME 内容嗅探（上轮已修）；uploads 走 nginx 静态 alias 直出，不执行 PHP
- JWT：HS256 + Redis 黑名单（库内按 jti 校验）；TOTP 登录强制 + 失败 5 次锁 15 分钟
- OAuth：JWKS 验签 + iss/aud/exp/nonce + email_verified 强制（上轮已修）
- 管理路由：AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 四、遗留建议（非阻塞）

| 级别 | 事项 | 说明 |
|:---:|------|------|
| P3 | `service/service/` 冗余旧目录（28K） | 含过时的 Supplier/WebSocket 副本，未被 PSR-4 加载、未跟踪，易被误改；建议人工确认后删除 |
| P3 | `MAIL_FROM_NAME` 模板冗余 | 代码未使用，可保留作邮件发件人名的预留配置 |
| P3 | doctrine/annotations 弃用 | hg/apidoc 直接依赖，移除需同步替换 API 文档生成方案 |
| P3 | 上传目录加固（二次建议） | uploads 目录内放置 `index.html`、确认部署层无 PHP 执行（nginx alias 已天然规避，webman 内置服务场景需注意） |

---

## 五、结论

上轮 15 项修复全部经复检确认有效，测试基线稳定（319/505）。本轮新发现并当场修复 3 处：**forgot/reset 路由漏挂限流（P1）**、**deployment.md nginx 配置暴露备份与私钥（P0）**、**docker nginx 缺 uploads 静态配置（P2）**。修复后全量测试重跑通过。

*报告生成方式：PHPUnit 全量、php -l 253 文件、路由/中间件静态审计、nginx/docker 配置审计、env 使用与定义差集比对、composer audit。*
