# CloudPlatform — 全球云资源交易平台

> [English Documentation](README_EN.md)

面向全球用户的云资源交易平台，支持服务器（VM）、IP 地址、云磁盘、域名等产品的在线购买与自动交付。自营物理机通过 Proxmox VE 虚拟化交付，同时支持第三方供应商入驻售卖。

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端框架 | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| ORM | Illuminate/Eloquent 10.x |
| 认证 | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| 分布式主键 | Snowflake 雪花 ID ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| ID 混淆 | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| 传输加密 | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| 字段加密 | AES-128-ECB ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| 全文搜索 | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| 国家旗帜 | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| 队列 | webman redis-queue |
| 数据库 | MySQL 8.0（主库 + 审计库双连接） |
| 搜索引擎 | Elasticsearch 8.x |
| 虚拟化 | Proxmox VE REST API |
| 客户端 | Flutter (iOS / Android / Web PC) + HarmonyOS ArkTS |
| 部署 | Docker Compose 一键启动 |

## 目录结构

```
cloud-php/
├── service/                   # 后端服务
│   ├── app/                   # 业务模块 (PSR-4: App\)
│   │   ├── Admin/             # 管理后台控制器
│   │   ├── Controller/        # 健康检查
│   │   ├── Domain/            # 域名注册 / DNS 管理
│   │   ├── Monitor/           # 资源监控 / 告警引擎 / 定时任务
│   │   ├── Notification/      # 消息通知（站内信 / 邮件 / 短信 / 推送）
│   │   ├── Order/             # 购物车 / 订单
│   │   ├── Payment/           # 支付路由 / Stripe 通道 / Webhook
│   │   ├── Product/           # 产品 / SKU / 区域定价
│   │   ├── Provisioning/      # 资源交付引擎 / Proxmox Provider
│   │   ├── Report/            # 营收 / 供应商 / 区域报表
│   │   ├── Supplier/          # 供应商入驻 / 结算 / 提现
│   │   ├── Ticket/            # 工单系统 / SLA 自动分配
│   │   └── User/              # 用户 / 认证 / KYC / 余额
│   ├── common/                # 公共库 (PSR-4: Common\)
│   │   ├── Auth/              # JWT 认证 / 中间件
│   │   ├── Encryption/        # 传输加密中间件 (AES-256-GCM) / 加密服务
│   │   ├── Hashid/            # Hashids 请求中间件 / ID 编解码服务
│   │   ├── Helper/            # Response 格式化（自动 hashid 编码）
│   │   ├── I18n/              # 多语言支持
│   │   ├── Security/          # CORS / WAF / 频率限制 / 审计日志
│   │   └── Snowflake/         # 雪花 ID 生成服务 / Eloquent 模型 Trait
│   ├── config/                # 路由 / 中间件 / 日志 / 数据库 / 队列 / 加密 / ES 等配置
│   └── support/               # 启动引导 (Eloquent / Event / 加密 / 雪花 ID / Hashids / Scout 初始化)
├── apps/
│   ├── flutter/               # Flutter 客户端 (PC 优先 Web 布局)
│   └── harmonyos/             # HarmonyOS 客户端骨架
├── docker/                    # Dockerfile / docker-compose / nginx / supervisor
├── docs/                      # 数据库 DDL / 设计文档 / 实施计划
└── README*.md                 # 项目说明文档
```

## 快速开始

### 环境要求

- PHP 8.2+ (ext-json, ext-pdo, ext-redis)
- MySQL 8.0
- Redis 7

### 本地开发

```bash
cd service

# 1. 安装依赖
composer install

# 2. 配置环境变量
cp .env.example .env
# 编辑 .env 填写数据库密码、JWT 密钥、加密密钥等
# ENCRYPTION_MASTER_KEY 生成：openssl rand -base64 32
# ENCRYPTION_KEY 生成：echo -n "$(openssl rand -base64 16)" | base64 -w0
# JWT_SECRET_KEY 生成：openssl rand -base64 32

# 3. 创建数据库
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. 导入数据库结构
mysql -u root -p cloud_platform < ../docs/database.sql
mysql -u root -p cloud_platform_audit < ../docs/database_audit.sql

# 5. 启动服务（开发模式）
php start.php start
# 访问 http://localhost:8787
```

### Docker 部署

```bash
# 从项目根目录
cp service/.env.example .env
# 编辑 .env 填写各项密钥

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### 守护进程模式

```bash
php start.php start -d          # 启动
php start.php status            # 查看状态
php start.php restart           # 重启
php start.php stop              # 停止
```

## API 概览

### 公开接口
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/health` | 健康检查 |
| POST | `/api/v1/auth/register` | 用户注册（请求体需 AES-256-GCM 加密） |
| POST | `/api/v1/auth/login` | 用户登录（请求体需 AES-256-GCM 加密） |
| POST | `/api/v1/auth/refresh` | 刷新 Token（请求体需 AES-256-GCM 加密） |
| GET | `/api/v1/products` | 产品列表（支持分类/区域/关键词筛选） |
| GET | `/api/v1/products/{id}` | 产品详情（id 为 hashid 字符串） |
| GET | `/api/v1/regions` | 可用区域 |
| GET | `/api/v1/domain/check/{domain}/{tld}` | 域名可用性查询 |
| GET | `/api/v1/domain/tlds` | 可注册后缀列表 |
| POST | `/api/v1/payments/webhook/stripe` | Stripe 回调（签名校验，无需加密） |

### 认证接口（需 Bearer Token）
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/user/profile` | 个人信息 |
| PUT | `/api/v1/user/profile` | 更新信息 |
| POST | `/api/v1/user/kyc` | 提交实名认证 |
| GET | `/api/v1/user/balance` | 账户余额 |
| GET/POST | `/api/v1/cart` | 购物车 |
| POST/GET | `/api/v1/orders` | 订单 |
| GET | `/api/v1/orders/{id}/payment-methods` | 可用支付方式 |
| POST | `/api/v1/orders/{id}/pay` | 发起支付 |
| GET/POST | `/api/v1/resources` | 我的资源 |
| GET | `/api/v1/resources/{id}/status` | 资源状态 |
| GET | `/api/v1/resources/{id}/console` | VNC 控制台链接 |
| GET/POST | `/api/v1/tickets` | 工单 |
| POST | `/api/v1/tickets/{id}/reply` | 工单回复 |
| GET/POST | `/api/v1/dns/{domain}` | DNS 管理 |
| POST | `/api/v1/supplier/apply` | 供应商申请 |
| GET | `/api/v1/supplier/settlements` | 供应商结算记录 |
| POST | `/api/v1/supplier/withdraw` | 供应商提现 |

> **说明：** 认证接口和管理员接口的请求/响应均经过 `EncryptionMiddleware` 处理。客户端设置 `X-Encrypted: 1` 请求头，请求体格式为 `{"payload": "<base64(AES-256-GCM)>"}`，响应体同样加密后包裹于 `payload` 字段。所有整数 ID 在 API 响应中自动转为 12 位 Hashid 字符串，请求中的 Hashid 字符串由 `HashidRequestMiddleware` 自动解码回整数 ID。

### 管理员接口
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | 运营仪表盘 |
| GET/PUT | `/admin/api/v1/users` | 用户管理 |
| GET/POST | `/admin/api/v1/kyc` | KYC 审核 |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | 产品管理 |
| POST | `/admin/api/v1/products/{productId}/skus` | 创建 SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | 设置区域价格 |
| GET/POST | `/admin/api/v1/orders` | 订单管理（含退款） |
| GET/PUT | `/admin/api/v1/payments/*` | 支付通道 / 交易 / 对账 |
| GET/POST | `/admin/api/v1/provisioning/*` | 交付任务 / 主机管理 |
| GET/POST | `/admin/api/v1/suppliers/*` | 供应商审批 / 结算 / 提现 |
| GET/POST | `/admin/api/v1/tickets` | 工单分配 / 关闭 |
| GET | `/admin/api/v1/reports/*` | 营收 / 区域 / 供应商报表 |
| GET | `/admin/api/v1/monitor/*` | 监控面板 / 资源指标 |
| GET | `/admin/api/v1/audit-logs` | 审计日志 |
| PUT | `/admin/api/v1/system/config` | 系统配置 |

## 设计思路

### 1. 模块化单体

模块按业务领域垂直切分（User / Product / Order / Payment / Provisioning / Ticket / Notification 等），每个模块内部遵循 MVC 分层：

- **Controller** — HTTP 层，参数校验、调用 Service、返回 Response
- **Service** — 业务逻辑，无 HTTP 依赖，可被 Controller 和 Queue Worker 复用
- **Model** — Eloquent 数据模型，定义关系和查询作用域

模块间通过**事件**和**接口**解耦，不直接调用对方的 Service。比如支付完成 → `OrderPaid` 事件 → `ProvisioningService` 自动开通资源，Ticket 创建 → `TicketCreated` 事件 → 自动分配客服。

### 2. 事件驱动交付

```
用户下单 → 支付成功 → OrderPaid 事件
  → ProvisioningService.handleOrderPaid()
    → 为每个 OrderItem 创建 ProvisionTask (status=pending)
    → Redis Queue 消费者 ProvisionWorker
      → ProviderFactory.create(task) 解析 Provider
      → ProxmoxProvider.create()
        → HostSelector 选最空闲物理机
        → ProxmoxApi 创建 VM / 挂载磁盘 / 分配 IP
        → 创建 Resource / Disk 记录
      → 更新 Order 状态为 completed
```

交付失败自动重试，退避策略：1min → 5min → 15min → 1h → 6h → 24h，超过 6 次标记失败并触发告警。

### 3. Provider 插件架构

资源交付通过 `ProviderInterface` 抽象，不同基础设施实现同一接口：

```
ProviderInterface
  ├── ProxmoxProvider    (自营 Proxmox VE)
  ├── AliyunProvider     (未来：阿里云)
  ├── AwsProvider        (未来：AWS EC2)
  └── DomainProvider     (未来：域名注册商)
```

`ProviderFactory` 按 `productType:provider` 键注册工厂函数，运行时根据 ProvisionTask 动态解析。

### 4. 多支付路由

`PaymentRouter` 根据订单金额 / 币种 / 区域动态返回可用支付通道，前端切换通道即可拉起支付。支付通道通过 `PaymentChannel` 表配置（费率、最小/最大金额、可见区域），无需改代码即可上下线。

### 5. 安全架构

全局中间件链：`CORS → WAF → Locale → HashidRequest → [路由: Encryption → Auth]`

- **CORS** — 跨域请求头处理
- **WAF** — 拦截 SQL 注入 / XSS / 路径遍历
- **Locale** — 解析 Accept-Language，设置多语言
- **HashidRequest** — 自动解码请求中的 hashid 字符串为真实整数 ID
- **Encryption** — AES-256-GCM 传输加密（认证接口和管理后台），防中间人窃听和篡改
- **Auth** — JWT HS256 认证，Access Token 15 分钟，Refresh Token 30 天，Redis 黑名单
- **频率限制** — 默认 60次/分，登录 5次/分，注册 3次/分，支付 10次/分
- **审计日志** — 所有敏感操作写入独立审计库

### 6. 数据安全

**分层加密策略：**

| 层级 | 技术 | 说明 |
|------|------|------|
| 传输层 | AES-256-GCM | API 请求/响应体加密，GCM 认证加密防篡改 |
| 字段层 | AES-128-ECB | 模型敏感字段自动加解密，ECB 确定性加密支持数据库查询 |
| 主键层 | Hashids | 对外 ID 混淆为 12 位字符串，隐藏真实数据规模 |

**敏感字段加密：** 7 个模型的 14 个字段使用 `Encryptable::class` 自动加解密 —— `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`。

**密钥管理：** 传输加密和字段加密使用不同的独立密钥（`ENCRYPTION_MASTER_KEY` vs `ENCRYPTION_KEY`），支持旧密钥列表（`ENCRYPTION_PREVIOUS_KEYS`）实现零停机密钥轮换。

### 7. 分布式 ID 生成

采用 Twitter Snowflake 算法生成 64 位全局唯一 ID：`timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`。所有 38 个 Eloquent 模型在 `creating` 事件中自动生成雪花 ID，无数据库自增依赖，天然支持分库分表。

### 8. 多语言

- 产品名称 / 描述存储为 JSON `{"en": "...", "zh": "..."}`，API 根据 `Accept-Language` 头返回对应语言
- 通知模板同样支持多语言，按用户偏好语言推送
- Flutter 客户端通过 Interceptor 携带语言标识

### 9. 全文搜索

产品、用户、订单、工单 4 个模型通过 `Erikwang2013\WebmanScout\Searchable` Trait 自动同步到 Elasticsearch，支持：

- **多语言分词** — IK Analyzer（ik_max_word / ik_smart）
- **中文全文搜索** — 产品名称、描述、工单标题
- **精确筛选** — 按状态、分类、价格区间、时间范围过滤
- **批量同步** — `php webman scout:import "App\Product\Model\Product"`
- **搜索示例** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. 国家旗帜

通过 `erikwang2013/season` 提供全域国家旗帜 emoji 支持：

- `country_season_flag('CN')` → 🇨🇳，`country_season_flag('JP')` → 🇯🇵
- 自动识别南北半球，返回对应季节（中英文）
- 支持 30+ 语言的本地化季节名称
- 前端区域选择、用户国籍展示等场景可直接调用

## 待办事项

- [x] 数据库 DDL（`docs/database.sql`，39 张表，erik_ 前缀，BigInt 非自增主键）
- [x] 雪花 ID 生成（`erikwang2013/snowflake-php`）
- [x] JWT 认证（`erikwang2013/jwt-webman`，HS256 + Redis 黑名单）
- [x] API ID 混淆（`erikwang2013/hashids`，请求自动解码 + 响应自动编码）
- [x] 传输加密（`erikwang2013/encryption`，AES-256-GCM 中间件）
- [x] 字段级加密（`erikwang2013/encryptable`，敏感字段自动加解密）
- [x] 全文搜索（`erikwang2013/webman-scout`，Elasticsearch + IK 分词）
- [x] 国家旗帜（`erikwang2013/season`，Unicode flag emoji）
- [ ] 数据库迁移脚本（`docs/database.sql` 已生成，待迁移命令化）
- [ ] Stripe 真实集成（当前为 mock）
- [ ] Twilio / 阿里云短信真实集成
- [ ] FCM 推送真实集成
- [ ] 单元测试与集成测试
- [ ] CI/CD 流水线

## License

Proprietary — All rights reserved.
