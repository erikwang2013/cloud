# CloudPlatform 架构设计文档

## 1. 系统概述

CloudPlatform 是一个面向全球的云资源交易平台，支持自营物理机 + 第三方供应商混合模式。用户可通过 Web/移动端购买服务器(VM)、IP 地址、云磁盘、域名等产品，系统自动完成支付处理和资源交付。

### 1.1 核心架构决策

| 决策 | 选择 | 理由 |
|------|------|------|
| 后端框架 | PHP webman (Workerman) | 常驻内存、事件驱动、多进程、毫秒级响应 |
| 架构模式 | 模块化单体 | 模块按业务垂直切分，内部 MVC 分层，模块间事件解耦 |
| 管理后台 | 独立 webman 实例 (webman-admin + Layui) | 隔离管理流量与用户流量，故障域分离 |
| ORM | Illuminate/Eloquent | Laravel 生态成熟，关联查询、Scope、事件、迁移 |
| 分布式主键 | Snowflake 64-bit | 无自增依赖，天然支持分库分表 |
| ID 混淆 | Hashids | 对外隐藏真实 ID 规模，防爬虫遍历 |
| 认证 | JWT HS256 | 无状态认证，Access 15min + Refresh 30d |
| 传输加密 | AES-256-GCM | 中间件透明加解密，GCM 认证加密防篡改 |
| 字段加密 | AES-128-ECB | Eloquent Cast 自动加解密，确定性加密（密文可等值查询，登录/唯一性校验依赖）；仅支持 ECB |
| 消息队列 | Redis Queue | 异步处理支付回调、通知分发、资源开通 |
| 搜索引擎 | database（默认）/ Elasticsearch 8.x | webman-scout 默认 database 驱动（SQL LIKE 降级）；配置 ES 后启用 IK 分词索引 |
| 虚拟化 | Proxmox VE + kvm-server | 自营 VM 由 Rust kvm-server（gRPC :50051，e-cat/etcd 注册发现）供应；驱动层当前为模拟驱动，libvirt 真实驱动 Phase 2 |
| 客户端 | Flutter | 单代码库 iOS/macOS/Windows/Linux/Web 五端 + HarmonyOS |

### 1.2 系统边界

```
┌──────────────────────────────────────────────────────────────────┐
│                         用户端                                    │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx 反向代理                                 │
│  SSL 终端 / gzip 压缩 / 限流 / WebSocket Upgrade                 │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman 服务端 (多进程)                               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ 全局中间件链: Version→CORS→SecurityHeaders→ClientPlatform │     │
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[路由中间件]       │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket Server (:8282) — 实时推送                      │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (主从)     │ │(缓存/队列) │ │    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  e-cat / etcd 注册发现      │
    │  模拟驱动 (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  KVM/QEMU 虚拟化             │
    │  IP 池 / 磁盘池 / 宿主机     │
    └─────────────────────────────┘
```

---

## 2. 组件架构

### 2.1 双实例设计

项目包含两个独立的 webman 实例，共享 MySQL 数据库：

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   中间件: WAF→ACL     │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 全局+6 路由中间件 │
                    └─────────────────────┘
```

| 实例 | 端口 | 职责 | 中间件 |
|------|------|------|--------|
| **service** | 8787 | 用户 API + 管理 API + WebSocket | 全局 12 + 路由 6 + SupplierApiKey |
| **admin** | 8788 | 管理后台 HTML 面板 (Layui) | WafMiddleware + AccessControl |

### 2.2 模块分层结构

每个业务模块内部遵循统一分层：

```
app/{Module}/
├── controller/     # HTTP 层：参数校验、调用 Service、返回 Response
│   └── external/   # 外部 API 控制器（供应商 API Key 认证）
├── service/        # 业务逻辑：无 HTTP 依赖，可被 Controller/Queue Worker 复用
├── model/          # Eloquent 数据模型：关系定义、查询作用域、Casts
├── event/          # 领域事件定义（OrderPaid、TicketCreated 等）
├── listener/       # 事件监听器（Provisioning、WebSocket 推送）
├── provider/       # 云厂商适配器（ProxmoxProvider 等）
├── queue/          # 队列消费者（ProvisionWorker、EmailSender 等）
└── cron/           # 定时任务（ExchangeRateSync、ExpirationCheck 等）
```

### 2.3 公共库分层

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # 点击验证码服务
├── clientplatform/      # ClientPlatformMiddleware（X-Client-Platform 头）
├── confirmation/        # 二次密码确认中间件
├── encryption/          # AES-256-GCM 传输加密中间件
├── feature/             # Feature Flags 功能开关
├── hashid/              # Hashids 请求解码中间件 + 编解码服务
├── helper/              # Response 格式化 + CacheService
├── http/                # HTTP 客户端工具
├── i18n/middleware/     # 多语言 LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # 雪花 ID 服务 + Eloquent Trait
├── metrics/             # Prometheus 指标采集器 + 渲染器 + HTTP 请求计数中间件
├── version/             # VersionMiddleware（X-Api-Version 头）
└── webhook/             # Webhook 事件分发器
```

---

## 3. 中间件执行管线

### 3.1 全局中间件链（所有请求）

```
HTTP 请求
  │
  ▼
1. VersionMiddleware         ← X-Api-Version 头校验，缺失默认 v1，无效返回 400
  │                            仅对 /api/ 和 /admin/api/ 生效
  ▼
2. CorsMiddleware            ← OPTIONS 预检返回 CORS 头，Origin 反射
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy 安全响应头
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform 头识别（8 平台），注入 properties
  │                            仅对 /api/ 和 /admin/api/ 生效
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES 国家封锁（MaxMind GeoIP2）
  ▼
6. WafMiddleware             ← 8 类 45+ 规则扫描（JSON body + URL + UA + 原始体）
  │                          ← Content-Type 白名单 + 请求体 10MB 限制 + URL 2KB 限制
  │                            命中 → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 种攻击检测（XSS/SQL注入/SSRF/反序列化等），IP 黑白名单
  ▼
8. RateLimitMiddleware       ← 全路由限流（per-IP + per-token 双桶）
  ▼
9. LocaleMiddleware          ← Accept-Language 解析，设置区域
  ▼
10. MetricsMiddleware        ← Prometheus HTTP 请求计数与延迟记录
  ▼
11. HashidRequestMiddleware  ← 请求参数 hashid 字符串 → 真实整数 ID 解码
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE 检查，白名单 IP 放行
  │
  ▼
[路由中间件 — 按路由组附加]
  │
  ├─ /health (内部监控) ────────────
  │   InternalTokenMiddleware      ← 内部令牌校验 /health/live|ready|deps
  │
  ├─ /api/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM 请求/响应体加解密
  │
  ├─ /api (用户认证) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token 验证 → $request->userId/role
  │
  ├─ /api (敏感操作) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← 密码二次确认，Redis 计数器，5 次锁 15min
  │
  ├─ /api/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 验证 → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC 权限检查
  │
  └─ /admin/api (敏感操作) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
控制器 → Service → Model → DB
```

### 3.2 各中间件详情

| 中间件 | 位置 | 注册方式 | 职责 |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | 全局 | 校验 `X-Api-Version`，缺失默认 v1 |
| `CorsMiddleware` | common/Security | 全局 | OPTIONS 预检，Origin 反射 |
| `SecurityHeadersMiddleware` | common/Security | 全局 | HSTS / X-Frame-Options / CSP / Referrer-Policy 安全响应头 |
| `ClientPlatformMiddleware` | common/ClientPlatform | 全局 | `X-Client-Platform` 8 平台识别 |
| `GeoBlockMiddleware` | common/Security | 全局 | GEO_BLOCKED_COUNTRIES 地域封锁（MaxMind GeoIP2） |
| `WafMiddleware` | common/Security | 全局(service)+admin | 8 类 45+ 规则 + 请求限制 |
| `SecurityPlugin` | Erikwang2013\Security | 全局 | 31 种攻击检测，IP 白/黑名单 |
| `RateLimitMiddleware` | common/Security | 全局 | Redis 令牌桶限流（per-IP + per-token 双桶） |
| `LocaleMiddleware` | common/I18n | 全局 | Accept-Language 解析 |
| `MetricsMiddleware` | common/Metrics | 全局 | Prometheus HTTP 请求计数与延迟 |
| `HashidRequestMiddleware` | common/Hashid | 全局 | hashid 请求解码 |
| `MaintenanceMiddleware` | common/Security | 全局 | 维护模式 + IP 白名单 |
| `InternalTokenMiddleware` | common/Security | 路由组 | `/health/live|ready|deps` 内部令牌校验 |
| `EncryptionMiddleware` | common/Encryption | 路由组 | AES-256-GCM 加解密 |
| `AuthMiddleware` | common/Auth | 路由组 | JWT Bearer Token 验证 |
| `AdminRoleMiddleware` | common/Auth | 路由组 | 管理员 RBAC |
| `ConfirmationMiddleware` | common/Confirmation | 路由组 | 密码二次确认 |
| `SupplierApiKeyMiddleware` | common/Auth | 路由组 | sk_xxx API Key SHA256 验签 |

---

## 4. 数据架构

### 4.1 分布式主键：Snowflake

```
64-bit Snowflake ID 结构:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  毫秒级时间戳      数据中心   工作节点    序列号
  纪元: 2024-01-01
  最大寿命: ~69 年
```

所有 Eloquent Model 在 `creating` 事件中通过 `HasSnowflakeId` Trait 自动生成。数据库列类型为 `bigint unsigned`。

### 4.2 ID 混淆：Hashids

```
请求流程:
  Client: GET /api/products/aB3xK7mQ9w
    → HashidRequestMiddleware 解码 → int(1234567890)
      → Controller/Service 使用整数 ID 操作
        → Response::success() / Response::paginated()
          → hashids_encode_ids() 递归编码所有 id/*_id 字段
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 数据库连接

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL 主库 (写)    │     │  MySQL 从库 (读)    │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  持久连接 (PDO)       │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  audit 库 (独立连接)  │
         │  审计日志隔离存储      │
         └──────────────────────┘
```

### 4.4 加密分层

| 层级 | 算法 | 实现 | 用途 |
|------|------|------|------|
| 传输层 | AES-256-GCM | EncryptionMiddleware | API 请求/响应体加密，GCM 认证 |
| 字段层 | AES-128-ECB | Encryptable Cast | 敏感字段自动加解密（确定性加密：相同明文→相同密文，登录/唯一性校验按密文等值查询；仅支持 ECB，换 cipher 需重加密迁移） |
| 哈希层 | bcrypt + SHA256 | JWT / API Key | 密码/Token 不可逆存储 |
| 主键层 | Hashids | Response + Middleware | ID 对外混淆 |

### 4.5 缓存分层

```
L1: Redis 缓存层（CacheService）
    产品列表 TTL 5min | 产品详情 TTL 10min
    区域 TTL 1h | 汇率 TTL 30min | TLD TTL 1h
    失效策略: 数据变更时主动 forget / forgetPattern

L2: MySQL 查询层（Eloquent + 索引优化）
    13 个复合/覆盖索引覆盖高频查询

L3: Nginx 响应压缩（gzip level 6）
    JSON 响应压缩率 70-85%
```

### 4.6 国际化（i18n）

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (全局中间件)
         │  解析主语言 → zh-CN
         │  I18n::setLocale('zh-CN')
         │  加载 i18n/zh-CN/messages.php
         ▼
  控制器 / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  按语言取值
         └── I18n::getLocale()                  →  'zh-CN'
```

| 能力 | 说明 |
|------|------|
| 头解析 | `LocaleMiddleware` 自动解析 `Accept-Language` 头 |
| 语言回落 | 不支持的语言 → `fallback_locale` |
| 静态翻译 | 120 词条，覆盖 15 个模块（`i18n/{locale}/messages.php`） |
| 参数替换 | `I18n::trans('key', ['field' => 'value'])` |
| JSON 字段 | `translateField()` 处理多语言 JSON 列 |

---

## 5. 安全架构

### 5.1 WAF 规则体系（8 类 45+ 条）

| 类别 | 规则数 | 检测范围 |
|------|--------|---------|
| SQL 注入 | 9 | 注释符、关键字、十六进制编码、联合查询、永真条件、时间盲注、堆叠查询 |
| XSS | 8 | HTML 标签、Script 变体、13 种事件处理器、JS 伪协议、实体编码、Data URI |
| 命令注入 | 5 | 管道后命令、分号后命令、$(cmd)、反引号、独立命令关键字 |
| 文件包含 | 4 | 路径穿越、PHP 伪协议、绝对路径、Null byte |
| HTTP 头注入 | 2 | CRLF 换行、Host/Cookie/Set-Cookie 注入 |
| SSRF | 6 | 内网 IP、localhost、云 metadata、file:// 协议 |
| NoSQL 注入 | 3 | MongoDB 操作符、Redis 危险命令 |
| 开放重定向 | 2 | redirect_uri 外部 URL、双重编码绕过 |

**扫描范围:** 值注入类规则（SQLi/XSS/命令注入/头注入/SSRF/NoSQL/开放重定向）扫描 query string、请求体、User-Agent；URL path 仅用文件包含（路径穿越）模式做结构校验。业务路径含 select/insert/alert 等普通词（如 `/order_item/select`），若整路径扫描会误杀全部 CRUD 端点，故 path 不参与值注入匹配。

**请求层面防护:** Content-Type 白名单、请求体 10MB 限制、URL 2KB 限制

### 5.2 认证体系

```
┌─────────────────────────────────────────────┐
│               认证方式                       │
├──────────────┬──────────────┬───────────────┤
│  用户端       │  管理端       │  供应商 API    │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min │  Access 2h   │  sk_xxx 前缀   │
│  Refresh 30d  │  Refresh 7d  │  SHA256 存储   │
│  TOTP 可选    │              │  仅显示一次     │
│  OAuth 可选   │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. 部署架构

### 6.1 生产拓扑

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │
               │  DDoS / Bot     │
               └────────┬────────┘
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL 主从  │ Redis Cluster │
         │ 1 主 2 从   │ 缓存+队列     │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  e-cat / etcd 注册         │
         │  模拟驱动 (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Proxmox VE 集群            │
         │  物理机 × N                 │
         │  KVM/QEMU 虚拟化            │
         └────────────────────────────┘
```

### 6.2 进程模型

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (默认 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. 技术依赖

### 7.1 核心框架

| 包 | 版本 | 用途 |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web 框架（常驻内存多进程） |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | 事件系统 |
| illuminate/redis | ^10.0 | Redis 客户端 |
| webman/redis-queue | ^1.0 | Redis 消息队列 |

### 7.2 erikwang2013 生态包

| 包 | 用途 |
|----|------|
| snowflake-php | 64 位分布式主键 |
| hashids | API ID 混淆 |
| encryptable | 数据库字段加密 |
| encryption | 传输加密 AES-256-GCM |
| jwt-webman | JWT 认证 |
| webman-scout | Elasticsearch 全文搜索 |
| season | 国家旗帜 emoji |
| poster-php | 点击验证码 |

### 7.3 第三方集成

| 包 | 用途 |
|----|------|
| stripe/stripe-php | Stripe 支付 |
| twilio/sdk | 短信 |
| kreait/firebase-php | FCM 推送 |
| guzzlehttp/guzzle | HTTP 客户端（Proxmox API 等） |
| sentry/sentry | 异常监控 |
| phpoffice/phpspreadsheet | Excel 导出 |
