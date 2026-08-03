# CloudPlatform 全面审查报告

**日期:** 2026-08-04  
**审查范围:** 全项目（代码质量、安全、生态配置、部署、文档）  
**分支:** main  
**最新提交:** e321bcc — 本轮修复的 3 个剩余问题

---

## 一、项目概览

| 维度 | 状态 |
|------|------|
| 项目类型 | PHP 8.2+ / webman 云资源交易平台 |
| 代码规模 | service（15 模块，295 tests）+ admin（53 控制器，67 tests）+ Flutter + HarmonyOS |
| 数据库 | MySQL 8.0，46 张表（7 wa_* + 39 erik_*） |
| 部署方式 | 一键安装向导 / Docker Compose / 手动 |
| 文档 | 10 篇文档 + 11 个 SVG 架构图 |

---

## 二、已发现问题

### CRITICAL（严重）

#### C1. Docker 部署缺少管理后台

**问题:** Dockerfile 只复制 `service/` 目录，docker-compose 只代理 8787 端口。管理后台（admin panel，端口 8788）完全没有 Docker 化。

```dockerfile
# docker/Dockerfile — 当前只处理 service
COPY service/ /app/
```

**影响:** 使用 Docker 部署的用户无法使用管理后台。与 README 声称的「Docker Compose 一键启动」不符。

**建议:** 增加 `admin/` 的 Dockerfile 或使用多阶段构建同时部署两个服务。

---

#### C2. Docker 数据库端口暴露到宿主机

**问题:** docker-compose.yml 中 MySQL (3306) 和 Redis (6379) 端口直接映射到宿主机：

```yaml
mysql:
  ports:
    - "3306:3306"    # 暴露到公网
redis:
  ports:
    - "6379:6379"    # 暴露到公网
```

**影响:** 如果服务器有公网 IP，数据库将对外暴露。这是常见的安全事故源头。

**建议:** 移除 `ports` 映射，或至少绑定 `127.0.0.1:3306:3306`。Docker 内部网络已可互通。

---

#### C3. 缺少 LICENSE 文件

**问题:** README 声明「简化版 — MIT License」，但项目根目录没有 `LICENSE` 文件。

**影响:** 开源法律要件缺失。GitHub 不会识别项目的许可证类型。

**建议:** 在根目录创建 `LICENSE` 文件，内容为标准 MIT License 文本。

---

### HIGH（高优先级）

#### H1. 重复的 SQL 文件造成混淆

**问题:** 项目中存在 3 个 SQL DDL 文件：

| 文件 | 行数 | 表数 | 状态 |
|------|------|------|------|
| `install.sql`（根目录） | 739 | 46 | **当前使用** |
| `admin/install.sql` | 152 | 7（仅 wa_*） | 旧版，未删除 |
| `docs/database.sql` | 629 | 39（仅 erik_*） | 旧版，未删除 |

**影响:** 维护者可能编辑错误的文件，导致不同步。

**建议:** 删除 `admin/install.sql` 和 `docs/database.sql`，或在文件头部添加醒目的废弃说明指向 `install.sql`。

---

#### H2. 安装向导不创建审计数据库

**问题:** `install/index.php` 生成 `service/.env` 时包含审计数据库配置：
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
但安装向导从未创建这个数据库。如果应用启动后尝试写入审计日志，会因 `Unknown database` 而失败。

**影响:** 审计日志功能不可用，合规性受影响。

**建议:** 在 step 4 安装执行时，增加 `CREATE DATABASE IF NOT EXISTS cloud_platform_audit`。

---

#### H3. Docker 缺少 Elasticsearch 服务

**问题:** docker-compose.yml 只有 app + mysql + redis 三个服务。README 技术栈明确列出 Elasticsearch 8.x 为必需组件。

**影响:** 全文搜索（产品、用户、订单、工单）在 Docker 部署中完全不可用。

**建议:** 在 docker-compose.yml 中添加 Elasticsearch 服务。

---

#### H4. Dockerfile 缺少 PHP 扩展

**问题:** Dockerfile 安装的 PHP 扩展为：`gd pdo_mysql zip bcmath redis`。但环境检查要求 9 个扩展，缺少：
- `intl`（PHP 国际化）
- `xml`（XML 解析）
- `fileinfo`（文件类型检测）

**影响:** 某些功能在 Docker 环境中可能静默失败。

**建议:** 添加缺失扩展：`docker-php-ext-install intl xml fileinfo`

---

### MEDIUM（中优先级）

#### M1. admin/.env.example 配置项不够详细

**问题:** service/.env.example（146 行）vs admin/.env.example（64 行），后者注释和配置项明显偏少。

**建议:** 补充 admin/.env.example 的注释说明，至少标注哪些字段必须与 service 端一致。

---

#### M2. .env.example 中的 HASHIDS_SALT 硬编码

**问题:** 两个 `.env.example` 文件都有：
```ini
HASHIDS_SALT=cloud-platform-hashids
```
如果运维人员直接 `cp .env.example .env` 而不修改此值，所有实例将共享同一盐值。

**建议:** `.env.example` 中使用占位符并在注释中强调「必须生成唯一随机值」。

---

#### M3. 安装向导成功页链接无效

**问题:** 安装完成页面的链接使用 `href="#"`，没有实际可点击的 URL。

**建议:** 至少显示具体的 URL/端口信息，附带启动命令。

---

#### M4. Docker 不包含安装向导

**问题:** Dockerfile 没有复制 `install.php` 或 `install/` 目录。使用 Docker 的用户无法使用一键安装向导。

**建议:** 明确文档说明 Docker 部署需手动配置，或在镜像中集成安装向导。

---

#### M5. Docker Compose 环境变量不完整

**问题:** docker-compose.yml 中的 `environment` 缺少多个必要配置：JWT 密钥、Hashids 盐值、加密密钥、SMTP、Stripe 等。

**建议:** 补充完整的环境变量列表，或引用 `.env` 文件。

---

### LOW（低优先级）

#### L1. 文档中 Docker 章节薄弱

README 中 Docker 部署只有几行，没有说明如何配置环境变量、初始化数据库、访问管理后台。

**建议:** 补充完整的 Docker 部署文档。

---

#### L2. 缺少 .editorconfig

**问题:** 项目没有 `.editorconfig` 文件。对于多贡献者项目，统一的缩进、换行符设置很重要。

**建议:** 添加标准 `.editorconfig`，约定 PHP 使用 4 空格缩进、UTF-8、LF 换行。

---

#### L3. 代码中硬编码默认值可集中管理

**问题:** `install/index.php` 中有多处硬编码默认值（数据库主机、端口、库名、管理员用户名），修改时容易遗漏。

**建议:** 提取为文件顶部的常量定义。

---

## 三、生态配置完整性评估

### .env 变量覆盖

| 配置域 | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| 数据库连接 | ✓ | ✓ | ✓ |
| 审计数据库 | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| JWT 认证 | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| 传输加密 (AES-256-GCM) | ✓ | ✓ | ✓ |
| 字段加密 (AES-128-ECB) | ✓ | ✓ | ✓ |
| SMTP 邮件 | ✓ | N/A | ✓ |
| Stripe 支付 | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| Twilio 短信 | ✓ | N/A | ✓ |
| Firebase 推送 | ✓ | N/A | ✓ |
| 点击验证码 | ✓ | N/A | ✓ |
| Sentry 监控 | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| 密钥轮换 | ✓ | N/A | ✓ |
| **评估** | **完整** | **完整** | **完整** |

### 安装向导生成的共享密钥一致性

| 密钥 | service | admin | 一致 |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **评估** | **通过** | **通过** | **通过** |

---

## 四、安全性评估

| 检查项 | 状态 | 说明 |
|--------|:--:|------|
| CSRF 防护 | ✓ | Token 生成 + hash_equals 验证 |
| Session 安全 | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| 输入验证 | ✓ | DB 名称正则校验，端口范围检查 |
| 密码强度 | ✓ | 最小 8 位 + 字母 + 数字/特殊字符 |
| 密码哈希 | ✓ | password_hash(PASSWORD_DEFAULT) |
| 密钥生成 | ✓ | openssl rand 或 random_bytes |
| SQL 注入防护 | ✓ | PDO prepared statements |
| 错误脱敏 | ✓ | 详细错误只写 error_log，用户看通用提示 |
| XSS 防护 | ✓ | htmlspecialchars() 输出转义 |
| 重装保护 | ✓ | 检测已有表 + .env 文件 |
| 步骤强制 | ✓ | session max_step 防止跳过步骤 |
| 事务包裹 | ✓ | beginTransaction/commit/rollBack |
| Docker 端口暴露 | ✗ | MySQL:3306 / Redis:6379 映射到宿主机 |
| 审计数据库创建 | ✗ | 安装向导未创建 _audit 库 |
| **综合评分** | **A-** | 核心安全措施完善，Docker 配置需改进 |

---

## 五、SQL 完整性

| 检查项 | 结果 |
|--------|------|
| 总表数 | 46 张（7 wa_* + 39 erik_*）✓ |
| 引擎 | 全部 InnoDB ✓ |
| 字符集 | 全部 utf8mb4 ✓ |
| 主键类型 | BIGINT UNSIGNED（非自增）✓ |
| CREATE IF NOT EXISTS | 全部使用 ✓ |
| 是否存在破坏性语句 | 无（无 DROP TABLE）✓ |
| 旧版 SQL 文件 | 仍存在 2 个旧版文件，需清理 ⚠ |

---

## 六、测试覆盖评估

| 测试套件 | 框架 | 测试数 | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**评估:** 测试数量充足（362 个测试），CI/CD 覆盖双 PHP 版本语法检查 + 双端单元测试。

---

## 七、文档完整性

| 文档 | 内容 | 状态 |
|------|------|:--:|
| README.md | 项目概述、架构、快速开始、API 概览 | ✓ |
| README_EN.md | 英文版 README | ✓ |
| docs/architecture.md | 系统架构设计 | ✓ |
| docs/features.md | 12 模块功能设计 | ✓ |
| docs/api-reference.md | 135+ 端点参考 | ✓ |
| docs/admin-design.md | 管理后台设计 | ✓ |
| docs/supplier-api.md | 供应商 API | ✓ |
| docs/deployment.md | 部署清单 | ✓ |
| docs/editions.md | 版本对比 | ✓ |
| docs/diagrams/ (11 SVG) | 架构/安全/业务流程 | ✓ |
| LICENSE 文件 | **缺失** | ✗ |

---

## 八、修复建议汇总

### 第一优先级（建议在下次发布前修复）

| # | 问题 | 等级 |
|---|------|:--:|
| 1 | 创建 LICENSE 文件（MIT） | CRITICAL |
| 2 | 删除旧 SQL 文件（admin/install.sql, docs/database.sql） | HIGH |
| 3 | Docker MySQL/Redis 端口不暴露到宿主机 | CRITICAL |
| 4 | 安装向导创建审计数据库 `_audit` | HIGH |

### 第二优先级（建议近期修复）

| # | 问题 | 等级 |
|---|------|:--:|
| 5 | Docker 支持管理后台（admin panel） | CRITICAL |
| 6 | Docker Compose 添加 Elasticsearch 服务 | HIGH |
| 7 | Dockerfile 补充 PHP 扩展（intl, xml, fileinfo） | HIGH |
| 8 | .env.example 的 HASHIDS_SALT 改用占位符 | MEDIUM |

### 第三优先级（持续改进）

| # | 问题 | 等级 |
|---|------|:--:|
| 9 | 完善 Docker 部署文档 | LOW |
| 10 | 添加 .editorconfig | LOW |
| 11 | 清理代码中的硬编码默认值 | LOW |
| 12 | 统一 .env 生成函数的配置项 | LOW |

---

## 九、结论

项目整体质量良好，核心安装向导经过上一轮审计后安全问题已全部修复。代码组织清晰，模块化程度高，文档完善。主要问题集中在 **Docker 部署配置不完整**——缺少管理后台、搜索服务、PHP 扩展，且存在数据库端口暴露的安全隐患。

**总评：B+** — 功能完整，安全核心到位，Docker 生态配置需要补充完善。
