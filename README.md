# CloudPlatform — 全球云资源交易平台

> [English Documentation](README_EN.md)

面向全球用户的云资源交易平台，支持服务器（VM）、IP 地址、云磁盘、域名等产品的在线购买与自动交付。自营物理机通过 Proxmox VE 虚拟化交付，同时支持第三方供应商入驻售卖。

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端框架 | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| ORM | Illuminate/Eloquent 10.x |
| 认证 | JWT RS256 (firebase/php-jwt) |
| 队列 | webman redis-queue |
| 数据库 | MySQL 8.0（主库 + 审计库双连接） |
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
│   │   ├── Auth/              # JWT 中间件 / RBAC
│   │   ├── Helper/            # Response 格式化
│   │   ├── I18n/              # 多语言支持
│   │   └── Security/          # CORS / WAF / 频率限制 / 审计日志
│   ├── config/                # 路由 / 中间件 / 日志 / 数据库 / 队列配置
│   └── support/               # 启动引导 (Eloquent / Event / 环境变量)
├── apps/
│   ├── flutter/               # Flutter 客户端 (PC 优先 Web 布局)
│   └── harmonyos/             # HarmonyOS 客户端骨架
├── docker/                    # Dockerfile / docker-compose / nginx / supervisor
└── docs/                      # 设计文档 / 实施计划
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
# 编辑 .env 填写数据库密码、JWT 密钥等

# 3. 创建数据库
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE DATABASE cloud_platform_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. 启动服务（开发模式）
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
| POST | `/api/v1/auth/register` | 用户注册 |
| POST | `/api/v1/auth/login` | 用户登录 |
| POST | `/api/v1/auth/refresh` | 刷新 Token |
| GET | `/api/v1/products` | 产品列表（支持分类/区域/关键词筛选） |
| GET | `/api/v1/products/{id}` | 产品详情 |
| GET | `/api/v1/regions` | 可用区域 |
| GET | `/api/v1/domain/check/{domain}/{tld}` | 域名可用性查询 |
| GET | `/api/v1/domain/tlds` | 可注册后缀列表 |
| POST | `/api/v1/payments/webhook/stripe` | Stripe 回调 |

### 认证接口（需 Bearer Token）
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/user/profile` | 个人信息 |
| PUT | `/api/v1/user/profile` | 更新信息 |
| POST | `/api/v1/user/kyc` | 提交实名认证 |
| GET | `/api/v1/user/balance` | 账户余额 |
| GET/POST | `/api/v1/cart` | 购物车 |
| POST/GET | `/api/v1/orders` | 订单 |
| GET/POST | `/api/v1/resources` | 我的资源 |
| GET/POST | `/api/v1/tickets` | 工单 |
| GET/POST | `/api/v1/dns/{domain}` | DNS 管理 |
| POST | `/api/v1/supplier/apply` | 供应商申请 |

### 管理员接口
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | 运营仪表盘 |
| GET/PUT | `/admin/api/v1/users` | 用户管理 |
| GET/POST | `/admin/api/v1/kyc` | KYC 审核 |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | 产品管理 |
| GET/POST | `/admin/api/v1/orders` | 订单管理（含退款） |
| GET/PUT | `/admin/api/v1/payments/*` | 支付通道 / 交易 / 对账 |
| GET | `/admin/api/v1/provisioning/*` | 交付任务 / 主机管理 |
| GET/POST | `/admin/api/v1/suppliers/*` | 供应商审批 / 结算 |
| GET/POST | `/admin/api/v1/tickets` | 工单分配 / 关闭 |
| GET | `/admin/api/v1/reports/*` | 营收 / 区域 / 供应商报表 |
| GET | `/admin/api/v1/monitor/*` | 监控面板 / 资源指标 |

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

全局中间件链：`CORS → WAF → Locale → Auth(认证路由) → RBAC(管理路由)`

- **WAF** — 拦截 SQL 注入 / XSS / 路径遍历
- **频率限制** — 注册 5次/小时，登录 10次/小时（按 IP）
- **JWT RS256** — Access Token（15 分钟）+ Refresh Token（30 天，token rotation）
- **审计日志** — 所有敏感操作写入独立审计库

### 6. 多语言

- 产品名称 / 描述存储为 JSON `{"en": "...", "zh": "..."}`，API 根据 `Accept-Language` 头返回对应语言
- 通知模板同样支持多语言，按用户偏好语言推送
- Flutter 客户端通过 Interceptor 携带语言标识

## 待办事项

- [ ] MySQL 数据库迁移脚本（当前模型已建好，需生成 DDL 或 migration）
- [ ] Stripe 真实集成（当前为 mock）
- [ ] Twilio / 阿里云短信真实集成
- [ ] FCM 推送真实集成
- [ ] 单元测试与集成测试
- [ ] CI/CD 流水线

## License

Proprietary — All rights reserved.
