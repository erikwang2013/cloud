# Cloud Platform — глобальная торговая платформа облачных ресурсов

## Languages

| Language | Docs |
|----------|------|
| 简体中文 | [README.md](../../../README.md) |
| English | [README_EN.md](../../../README_EN.md) |
| English | [en docs](../../en/README.md) |
| 한국어 | [ko docs](../../ko/README.md) |
| Русский | [ru docs](../../ru/README.md) |
| Deutsch | [de docs](../../de/README.md) |
| Français | [fr docs](../../fr/README.md) |
| Español | [es docs](../../es/README.md) |
| Português | [pt docs](../../pt/README.md) |
| हिन्दी | [hi docs](../../hi/README.md) |
| العربية | [ar docs](../../ar/README.md) |
| বাংলা | [bn docs](../../bn/README.md) |
| Bahasa Indonesia | [id docs](../../id/README.md) |
| 日本語 | [ja docs](../../ja/README.md) |

<p align="center">
  <img src="docs/diagrams/c.svg" alt="Талисман проекта CloudPlatform" width="220">
</p>

Торговая платформа облачных ресурсов для пользователей по всему миру: онлайн-покупка и автоматическая поставка серверов (VM), IP-адресов, облачных дисков, доменов, SSL-сертификатов, объектного хранилища (S3), CDN-ускорения и других продуктов. Собственные физические серверы поставляются через виртуализацию Proxmox VE, также поддерживается размещение и продажа товаров сторонними поставщиками. Предусмотрены поминутная тарификация, партнёрская дистрибуция, GraphQL API и наблюдаемость через Prometheus/Grafana.

## Технологический стек

| Уровень | Технология |
|------|------|
| Бэкенд-фреймворк | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| Панель управления | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| Аутентификация | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| Распределённый первичный ключ | Snowflake ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| Запутывание ID | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| Шифрование передачи | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| Шифрование полей | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| Полнотекстовый поиск | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| Флаги стран | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| Капча по клику | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| Защита | 31 вид обнаружения атак ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| Экспорт таблиц | PhpSpreadsheet ^2.0 |
| Платёжный SDK | Stripe PHP ^15.0 |
| SMS SDK | Twilio PHP ^8.0 |
| Push SDK | Firebase PHP ^7.0 |
| Очереди | webman redis-queue |
| База данных | MySQL 8.0 (двойное подключение: основная БД + БД аудита) |
| Поисковая система | Elasticsearch 8.x |
| Виртуализация | Proxmox VE (канал gRPC Rust kvm-server, регистрация e-cat/etcd) |
| Клиенты | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| Объектное хранилище | AWS S3 SDK PHP ^3.300 |
| Наблюдаемость | Prometheus + Grafana (предустановленные дашборды) |
| Многоязычность | i18n на 7 языках (кит./англ./яп./кор./нем./фр./исп.) |
| Развёртывание | Docker Compose одной командой |

## Архитектура системы

![Архитектура системы](docs/diagrams/system-architecture-zh.svg)

## Основные бизнес-процессы

Сквозной процесс от регистрации пользователя до поставки ресурса: выбор товара, оформление заказа, оплата, автоматическая поставка, послепродажное обслуживание и цикл продления.

![Основные бизнес-процессы](docs/diagrams/business-flowchart-zh.svg)

## Мультивалютные расчёты

Система нативно поддерживает ценообразование, оплату и расчёты в нескольких валютах, покрывая весь контур: от валютных настроек пользователя, регионального ценообразования и снимков курсов до приёма платежей, зачисления баланса и расчётов с поставщиками.

![Схема мультивалютных расчётов](docs/diagrams/currency-settlement-zh.svg)

**1. Мультивалютные балансовые счета**

Таблица `user_balances` ведёт учёт по валютам на основе `(user_id, currency)` (уникальный индекс `uk_user_currency`). При регистрации по умолчанию создаются два валютных счёта — USD и CNY; баланс и замороженный баланс управляются независимо по каждой валюте; можно расширить на любые валюты, поддерживаемые Stripe.

**2. Мультивалютное региональное ценообразование**

Таблица `product_regions` позволяет задавать для одного SKU в одном регионе цены в нескольких валютах (уникальный индекс `uk_sku_region_currency`). Фронтенд отображает цену в предпочитаемой пользователем валюте, а при оформлении заказа `OrderService` получает точную цену по `(sku_id, region_id, currency)`.

**3. Система курсов валют**

Фоновая задача `ExchangeRateSync` синхронизирует курсы валют с exchangerate-api и сохраняет их в Redis (кэш с TTL 30 минут). Каждый заказ фиксирует снимок курса `exchange_rate` на момент оформления, что обеспечивает прослеживаемость последующих расчётов.

**4. Мультивалютные платежи**

Поле `payment_channels.currency_support` объявляет белый список валют для каждого платёжного канала; `PaymentRouter` динамически фильтрует доступные каналы по валюте / диапазону суммы / доступным регионам. Stripe PaymentIntent принимает оплату непосредственно в валюте заказа; встроена обработка разрядности для 16 валют без десятичных знаков (JPY / KRW / VND и др.); Webhook-колбэки проверяют соответствие суммы и валюты.

**5. Расчёты и отчётность**

Платёжные транзакции (`payment_transactions`), расчёты с поставщиками (`supplier_settlements`) и отчёты о выручке сохраняют поля валюты и курса; статистика ведётся в разрезе валют.

## Обзор функциональных модулей

Система организована по четырёхуровневой архитектуре: уровень клиентов (подключение 6 платформ), уровень API-шлюза (12 промежуточных слоёв), уровень бизнес-сервисов (20+ функциональных модулей), уровень инфраструктуры (8 ключевых компонентов).

![Обзор функциональных модулей](docs/diagrams/module-overview-zh.svg)

## Жизненный цикл ресурсов

Ресурс проходит 6 состояний от создания до прекращения действия, управляемых 8 событиями жизненного цикла; поддерживаются автоматическая поставка, восстановление после приостановки, напоминания об истечении срока и очистка при уничтожении.

![Жизненный цикл ресурсов](docs/diagrams/resource-lifecycle-zh.svg)

## Навигация по документации

| Документ | Описание |
|------|------|
| [Архитектура](docs/architecture.md) | Архитектура системы, связи компонентов, конвейер промежуточных слоёв, уровни безопасности, модель данных, топология развёртывания |
| [Функциональный дизайн](docs/features.md) | Детальный дизайн 21 модуля: блок-схемы, модели данных, описания взаимодействия |
| [Справочник API](docs/api-reference.md) | Полный справочник 200+ эндпоинтов, сгруппированных по модулям: примеры запросов/ответов, коды ошибок |
| [Онлайн-документация API (service)](http://localhost:8787/apidoc) | Автоматически генерируется hg/apidoc, сгруппирована по функциям, поддерживает онлайн-отладку |
| [Онлайн-документация API (admin)](http://localhost:8788/apidoc) | Автоматически генерируется hg/apidoc: 54 контроллера, 13 функциональных групп |
| [Дизайн панели управления](docs/admin-design.md) | Архитектура панели Admin, интеграция пакетов, права ACL, наборы тестов |
| [API поставщиков](docs/supplier-api.md) | Справочник API поставщиков (внутренний + внешний), примеры SDK |
| [Чек-лист развёртывания](docs/deployment.md) | Конфигурация сервера, переменные окружения, Nginx, HTTPS, фоновые задачи |
| [Отчёт о ревизии](docs/review-report-2026-08-04.md) | Отчёт о ревизии экосистемы: статистика, трекинг проблем, рекомендации по расширению |
| [Сравнение редакций](docs/editions.md) | Сравнение редакций Lite/Standard/Full: функции, дизайн, архитектура |

## Структура каталогов

```
cloud-php/
├── .claude/                    # Claude Code 配置（settings / skills）
├── .github/workflows/          # CI/CD 流水线（语法检查 + 双端 PHPUnit）
├── admin/                      # 管理后台（独立 webman 实例）
│   ├── app/                    # 插件源码 (PSR-4: app\)
│   │   ├── bootstrap/          # 进程启动引导（Snowflake / Encryptable / Encryption）
│   │   ├── command/            # 控制台命令（Migrate / Rollback / Status）
│   │   ├── common/             # 工具类（Auth / Tree / Layui / Util / ExcelExport / Migration）
│   │   ├── controller/         # 54 个控制器文件（Base / Crud 基类 + 各业务 CRUD）
│   │   ├── exception/          # 异常处理
│   │   ├── middleware/          # 访问控制中间件（WafMiddleware + AccessControl）
│   │   ├── model/              # 46 个 Eloquent 模型（Base 基类含 Snowflake PK + Encryptable）
│   │   ├── view/               # 视图模板（Layui 后台面板）
│   │   └── functions.php       # 全局辅助函数（hashids / encrypt / decrypt）
│   ├── api/                    # 对外接口 (PSR-4: plugin\admin\api)
│   │   ├── Auth.php            # 鉴权接口
│   │   ├── Menu.php            # 菜单接口
│   │   ├── Install.php         # 安装接口
│   │   └── Middleware.php      # 中间件接口
│   ├── config/                 # 应用配置
│   │   ├── plugin/erikwang2013/ # 6 个 erikwang2013 包配置
│   │   │   ├── snowflake-php/  # 雪花 ID 生成
│   │   │   ├── hashids/        # ID 混淆
│   │   │   ├── encryptable/    # 字段级加密
│   │   │   ├── encryption/     # 传输加密
│   │   │   ├── webman-scout/   # Elasticsearch 同步
│   │   │   └── season/         # 国家旗帜
│   │   ├── route.php           # 路由定义
│   │   ├── middleware.php       # 中间件配置
│   │   ├── database.php        # 数据库连接
│   │   └── ...                 # 18 个配置文件
│   ├── database/migrations/    # 数据库迁移文件
│   ├── tests/                  # 单元测试（PHPUnit 11, 286 tests / 962 assertions）
│   │   ├── HashidsTest.php     # hashids 编解码（21 tests）
│   │   ├── BaseJsonTest.php    # Base::json() ID 编码（13 tests）
│   │   ├── CrudHashidsTest.php # Crud 输入解码（14 tests）
│   │   ├── TreeTest.php        # 树形结构（19 tests）
│   │   ├── AccessControlMiddlewareTest.php # RBAC 访问控制
│   │   ├── AdminControllersTest.php        # 控制器回归
│   │   └── support/            # 测试辅助类
│   ├── public/                 # 文档根目录（静态资源）
│   ├── vendor/                 # Composer 依赖
│   ├── .env.example            # 环境变量模板
│   ├── composer.json           # 依赖声明
│   ├── generate.php            # 代码生成器
│   ├── phpunit.xml             # PHPUnit 配置
│   └── start.php               # 启动入口
├── service/                    # 后端服务（独立 webman 实例）
│   ├── app/                    # 业务模块 (PSR-4: App\)，每个模块含 Controller / Model / Service 等分层
│   │   ├── admin/controller/   # 管理后台 API（15 个控制器：Dashboard / User / Product / Order / Payment / Supplier / Coupon / Invoice / Domain / Webhook 等）
│   │   ├── affiliate/          # 联盟佣金 / 推广分佣（Controller / Listener / Model / Service）
│   │   ├── billing/            # 用量计费 / 账单（Cron / Service）
│   │   ├── captcha/controller/ # 点击验证码
│   │   ├── cdn/                # CDN 资源托管（Controller / Model / Provider / Service）
│   │   ├── command/            # 控制台命令（Migrate / Rollback / Status / DbBackup）
│   │   ├── controller/         # 公共控制器（Health / Status / Help / Upload）
│   │   ├── cron/               # 定时任务（CronRunner 调度器 + ExchangeRateSync / PaymentReconcile / SupplierSettlement / ExpirationCheck / SslCertificateCheck）
│   │   ├── domain/             # 域名注册 / DNS 管理（Controller / Model / Service）
│   │   ├── graphql/            # GraphQL API（Mutation / Query / Schema）
│   │   ├── grpc/               # kvm-server gRPC 客户端 + etcd 注册（KvmClient / EtcdRegistry）
│   │   ├── model/              # 公共模型（HelpArticle / Role / Permission）
│   │   ├── monitor/            # 资源监控 / 告警（Controller / Cron / Model / Service）
│   │   ├── notification/       # 消息通知（Controller / Model / Queue / Service）
│   │   ├── order/              # 购物车 / 订单 / 优惠券 / 发票（Controller / Model / Service）
│   │   ├── payment/            # 支付路由 / Stripe 通道（Controller / Event / Model / Service）
│   │   ├── product/            # 产品 / SKU / 区域定价 / 评价（Controller / Model / Service）
│   │   ├── provisioning/       # 资源交付引擎（Controller / Event / Listener / Model / Provider / Queue / Service）
│   │   ├── report/             # 营收 / 供应商 / 区域报表（Controller / Service）
│   │   ├── ssl/                # SSL 证书签发 / 管理（Controller / Model / Service）
│   │   ├── storage/            # 对象存储资源（Controller / Model / Provider / Service）
│   │   ├── supplier/           # 供应商入驻 / 结算 / 提现 + 外部 API（Controller / Model / Service）
│   │   ├── ticket/             # 工单系统（Controller / Event / Listener / Model / Service）
│   │   ├── user/               # 用户 / 认证 / KYC / 余额 / 地址（Controller / Model / Service）
│   │   ├── webhook/            # Webhook 消息队列（Queue）
│   │   └── websocket/          # WebSocket 服务器 + 事件监听器
│   ├── common/                 # 公共库 (PSR-4: Common\)
│   │   ├── auth/middleware/     # AuthMiddleware / AdminRoleMiddleware / RbacMiddleware / RoleMiddleware / SupplierApiKeyMiddleware
│   │   ├── captcha/            # 点击验证码服务
│   │   ├── confirmation/       # 二次确认中间件（密码复核）
│   │   ├── encryption/middleware/ # AES-256-GCM 传输加密中间件
│   │   ├── hashid/middleware/   # Hashids 请求自动解码中间件 + 编解码服务
│   │   ├── helper/             # Response 格式化（自动 hashid 编码）
│   │   ├── http/               # HTTP 客户端工具（ApiRequest）
│   │   ├── i18n/middleware/     # 多语言中间件（Locale）
│   │   ├── security/           # CORS / WAF / 频率限制 / 地域封锁 / 维护模式 / 审计日志
│   │   ├── snowflake/          # 雪花 ID 生成服务 / Eloquent HasSnowflakeId Trait
│   │   ├── version/middleware/  # API 版本中间件（X-Api-Version 头校验）
│   │   ├── clientplatform/middleware/  # 客户端平台中间件（X-Client-Platform 头识别）
│   │   ├── feature/            # Feature Flags 功能开关服务
│   │   └── webhook/            # Webhook 事件分发器
│   ├── config/                 # 17 个配置文件（route / middleware / database / redis / cron / auth / security / i18n / ...）
│   │   └── plugin/             # 插件配置
│   │       ├── erikwang2013/   # encryptable / hashids / jwt / poster / season / webman-scout
│   │       └── webman/         # event / redis-queue
│   ├── database/migrations/    # 数据库迁移文件（37 个迁移）
│   ├── i18n/                   # 多语言资源（en-US / zh-CN）
│   ├── support/                # Bootstrap 引导（Eloquent / Redis / Event / 加密 / 雪花ID / Hashids / Scout / MigrationRunner）
│   ├── tests/                  # 单元测试（PHPUnit 10, 672 tests / 1632 assertions）
│   │   ├── admin/              # ImportExport / SupplierWithdrawApprove
│   │   ├── affiliate/          # AffiliateService
│   │   ├── auth/               # JwtAuth / RbacSeed / Rbac
│   │   ├── billing/            # MeterCollector / UsageAggregator / SuspendCheck
│   │   ├── captcha/            # CaptchaService
│   │   ├── cdn/                # ResourceCdn
│   │   ├── clientplatform/     # ClientPlatformMiddleware
│   │   ├── common/             # Response / Hashid / Snowflake / Validator / LogSanitizer / Totp / ApiRequest
│   │   ├── confirmation/       # ConfirmationMiddleware
│   │   ├── cron/               # SupplierSettlement
│   │   ├── domain/             # DomainService / DomainTransfer
│   │   ├── graphql/            # Schema
│   │   ├── grpc/               # KvmClient / EtcdRegistry
│   │   ├── monitor/            # AlertEngine
│   │   ├── notification/       # NotificationDispatcher
│   │   ├── order/              # Coupon / Invoice
│   │   ├── payment/            # StripeChannel / PaymentRouter
│   │   ├── product/            # ProductService / Search / ReviewStatus
│   │   ├── provisioning/       # ProviderFactory / RetryLogic
│   │   ├── report/             # ReportService
│   │   ├── security/           # RateLimit / Maintenance / UploadSecurity
│   │   ├── ssl/                # SslCertificate
│   │   ├── storage/            # StorageBucket
│   │   ├── supplier/           # SupplierService / Settlement / Rating / Webhook
│   │   ├── ticket/             # TicketUpdatedWiring
│   │   ├── user/               # AddressController
│   │   ├── version/            # VersionMiddleware
│   │   ├── webhook/            # WebhookDispatcher / WebhookE2E
│   │   ├── websocket/          # WebSocketAuth / EventsWiring
│   │   ├── support/            # RequestMock
│   │   ├── bootstrap.php       # 测试引导
│   │   └── TestCase.php        # 测试基类
│   ├── runtime/                # 运行时文件（日志 / 缓存）
│   ├── vendor/                 # Composer 依赖
│   ├── .env.example            # 环境变量模板
│   ├── .env                    # 本地环境变量（gitignore）
│   ├── composer.json           # 依赖声明
│   ├── phpunit.xml             # PHPUnit 配置
│   └── start.php               # 启动入口
├── apps/
│   ├── flutter/                # Flutter 客户端（iOS / macOS / Windows / Linux / Web）
│   │   ├── lib/                # Dart 源码（core / features）
│   │   ├── ios/                # iOS 工程
│   │   ├── macos/              # macOS 工程
│   │   ├── windows/            # Windows 工程
│   │   ├── linux/              # Linux 工程
│   │   ├── web/                # Web 工程
│   │   ├── test/               # Flutter 测试
│   │   ├── pubspec.yaml        # 依赖声明
│   │   └── analysis_options.yaml # Dart 静态分析配置
│   └── harmonyos/              # HarmonyOS 客户端骨架
│       └── entry/src/          # ArkTS 源码
├── docker/                     # Docker 部署
│   ├── Dockerfile              # PHP 8.2 镜像
│   ├── docker-compose.yml      # 服务编排
│   ├── nginx.conf              # Nginx 配置
│   └── supervisor.conf         # Supervisor 进程守护
├── infrastructure/             # Rust 基础设施（e-cat workspace）
│   ├── kvm-server/             # 自有云服务：VM 供应 gRPC 服务（:50051，etcd 注册）
│   │   ├── src/                # main / grpc / driver（模拟驱动，libvirt 为 Phase 2）
│   │   ├── tests/              # 集成测试
│   │   └── Cargo.toml          # e-cat workspace 成员声明
│   └── ecat-*/                 # e-cat 基础设施 crate（transport-grpc / registry-etcd / protos / config / data 等）
├── docs/                       # 文档
│   ├── admin-design.md         # 管理后台设计文档
│   ├── supplier-api.md         # 供应商 API 文档
│   ├── deployment.md           # 部署清单
│   ├── api-test.sh             # API 冒烟测试脚本
│   ├── database.sql            # 数据库 DDL
│   ├── alipay.png / weixinpay.png  # 打赏二维码
│   ├── diagrams/               # 18 个 SVG 架构图（系统架构 / 安全管道 / ER 图 / 业务流程 / 多币种结算等）
│   ├── test-reports/           # 测试报告（PHPUnit / Rust / API / UI + 页面截图）
│   └── superpowers/            # 设计规格与实施计划
│       ├── specs/              # 系统设计规格文档
│       └── plans/              # Phase 0~3 分阶段实施计划
├── scripts/                     # 运维脚本（push-release.sh 推送发布规则：版本增量 + tag）
├── tests/k6/                    # k6 负载测试脚本（冒烟/产品/并发）
├── install.php                 # 一键安装向导入口
├── install/                    # 安装向导页面
│   └── index.php               # 向导 Web 应用
├── install.sql                 # 统一数据库 DDL（46 张表）
├── .gitignore
├── README.md                   # 项目说明（中文）
└── README_EN.md                # 项目说明（英文）
```

## Быстрый старт

### Требования к окружению

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### Установка в один клик (рекомендуется)

Проект предоставляет Web-мастер установки, позволяющий выполнить всю настройку в браузере:

```bash
# 1. 安装依赖
cd service && composer install && cd ../admin && composer install && cd ..

# 2. 启动安装向导
php install.php
# 打开浏览器访问 http://localhost:8888

# 3. 按向导提示完成：
#    - 环境检查
#    - 数据库配置（主机、端口、库名、用户名、密码）
#    - 后台管理员账号设置（用户名、密码、邮箱）
#    - 一键执行安装（建表 + 写入配置）
```

После установки мастер автоматически:
- Создаст все 46 таблиц базы данных (управляющие таблицы `wa_*` + бизнес-таблицы без префикса)
- Создаст роль и учётную запись супер-администратора
- Сгенерирует файлы конфигурации `service/.env` и `admin/.env` (с автоматически созданными ключами JWT/шифрования)

### Ручная установка

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

# 3. 创建数据库并导入
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cloud_platform < ../install.sql

# 4. 启动服务（开发模式）
php start.php start
# 访问 http://localhost:8787
```

### Развёртывание через Docker

```bash
# 从项目根目录
cp service/.env.example .env
# 编辑 .env 填写各项密钥

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### Панель управления

```bash
cd admin

# 1. 安装依赖
composer install

# 2. 配置环境变量
cp .env.example .env
# 如果使用一键安装向导，此文件已自动生成

# 3. 启动服务（开发模式）
php start.php start
# 访问 http://localhost:8787/app/admin
```

### Режим демона

```bash
php start.php start -d          # 启动
php start.php status            # 查看状态
php start.php restart           # 重启
php start.php stop              # 停止
```

## Инструкция по использованию

### Вход

- **Пользователь**: откройте адрес API-сервиса (по умолчанию `http://localhost:8787`), зарегистрируйте аккаунт и войдите. Поддерживается OAuth Google / Apple и двухфакторная аутентификация TOTP
- **Панель управления**: откройте в браузере `http://localhost:8787/app/admin` (панель — отдельный экземпляр, порт 8788) и войдите под учётной записью администратора, созданной мастером установки

### Основные функции панели

- **Дашборд**: статистика заказов / выручки / новых пользователей / активных ресурсов за сегодня, график выручки за 30 дней, экспорт в PDF
- **Отчёты**: отчёты по заказам, рейтинг товаров, статистика по каналам, рост пользователей, экспорт в Excel
- **Повседневное управление**: пользователи / товары / заказы / поставщики / тикеты / домены / CDN, проверка KYC, возвраты, утверждение расчётов и выводов
- **Настройки системы**: платёжные каналы, аккаунты CDN, Webhook, шаблоны уведомлений, справочные статьи, журналы аудита

### Сборка клиентов

- **Flutter-клиент** (`apps/flutter/`): iOS / Android / Web / Linux / macOS / Windows. `flutter pub get` для установки зависимостей, `flutter run` для отладки, `flutter build apk` / `flutter build ios` / `flutter build web` для сборки
- **Клиент HarmonyOS** (`apps/harmonyos/`): нативное приложение ArkTS — откройте проект `entry` в DevEco Studio для сборки и запуска

## Обзор API

Интерфейсы сгруппированы по модулям и включают примеры запросов/ответов и коды ошибок: [Обзор API](docs/api-overview.md) (избранное) · [Справочник API](docs/api-reference.md) (полный справочник 200+ эндпоинтов) · [Онлайн-отладка](http://localhost:8787/apidoc)

## Архитектура панели управления

### Техническая интеграция

Панель управления — это отдельный экземпляр webman, интегрирующий 7 пакетов erikwang2013:

| Пакет | Назначение | Реализация |
|---|------|---------|
| snowflake-php | 64-битный распределённый первичный ключ | Автоматически генерируется в событии `creating` в `Base::boot()` |
| hashids | Запутывание ID в API | Кодирование ответа в `Base::json()`, декодирование запросов в `Crud::selectInput/updateInput/deleteInput` |
| encryptable | Шифрование полей БД | cast Eloquent `Encryptable`: прозрачное шифрование/дешифрование для Admin (password/email/mobile) и User (6 полей) |
| encryption | Шифрование передачи API | Зарезервированные вспомогательные функции `encrypt_data()`/`decrypt_data()` |
| webman-scout | Полнотекстовый поиск ES | trait `Searchable` модели User, автоматическая синхронизация индексов |
| season | Эмодзи флагов стран | Глобальная вспомогательная функция `country_season_flag()` |
| poster-php | Капча по клику | Bootstrap `CaptchaPlugin`, глобальные функции `captcha_create()`/`captcha_verify()` |

### Уровни безопасности

```
请求 → Hashids 解码 (Crud::selectInput/updateInput/deleteInput)
  → ACL 鉴权 (api/Auth.php, 控制器 noNeedLogin/noNeedAuth)
  → 业务处理 (CRUD / 模型事件)
  → Encryptable 字段加密 (Eloquent casts set)
  → 数据库写入
响应 ← Hashids 编码 (Base::json → hashids_encode_ids)

登录/注册：Captcha 验证 → Auth → 业务处理
```

### Поток данных

- **Путь записи**: ID запроса (hashid) → декодируется в int → операция CRUD → Snowflake генерирует новый ID → `Encryptable` шифрует чувствительные поля → БД
- **Путь чтения**: БД → дешифрование `Encryptable` → кодирование ID через Hashids → JSON-ответ

### Покрытие тестами

```
phpunit.xml (PHPUnit 11, 286 tests / 962 assertions)
├── HashidsTest              (21 tests) encode/decode/encode_ids
├── BaseJsonTest             (13 tests) Base::json/success/fail 编码
├── CrudHashidsTest          (14 tests) Crud 输入解码 (select/update/delete)
├── TreeTest                 (19 tests) 树形结构 / 子孙 / 祖先 / 孤儿节点
├── AccessControlMiddlewareTest (7 tests) 未登录 401 / 403 页面 / 放行
├── AdminControllersTest     (data provider) 48 控制器装配 / CRUD 面 / GET 视图路径
├── UtilTest                 (17 tests) 密码 / 时间 / 字节 / 输入过滤 / 控件属性
├── DictTest                 (5 tests) 字典名↔option 转换 / save/get/delete
├── ExcelExportTest          (4 tests) 表头 / JSON 展平 / 行号 / 空单元格
└── LayuiTest                (5 tests) input / inputNumber / label 转义 / switch / html
```

## Архитектурные решения

### 1. Модульный монолит

Модули вертикально разделены по бизнес-доменам (User / Product / Order / Payment / Provisioning / Ticket / Notification и др.), внутри каждого модуля соблюдается слоистая структура MVC:

- **Controller** — HTTP-слой: проверка параметров, вызов Service, возврат Response
- **Service** — бизнес-логика без зависимостей от HTTP; может повторно использоваться Controller'ом и Queue Worker'ом
- **Model** — модель данных Eloquent: определяет связи и области запросов

Модули слабо связаны через **события** и **интерфейсы** и не вызывают Service друг друга напрямую. Например: оплата завершена → событие `OrderPaid` → `ProvisioningService` автоматически активирует ресурсы; создан тикет → событие `TicketCreated` → автоматическое назначение оператора поддержки.

### 2. Событийно-управляемая поставка

```
用户下单 → 支付成功 → OrderPaid 事件
  → ProvisioningService.handleOrderPaid()
    → 为每个 OrderItem 创建 ProvisionTask (status=pending)
    → Redis Queue 消费者 ProvisionWorker
      → ProviderFactory.create(task) 解析 Provider
      → ProxmoxProvider.create()
        → HostSelector 选最空闲物理机
        → ProxmoxApi 创建 VM / 挂载磁盘 / 分配 IP
          （Rust kvm-server gRPC 供应服务已入库：e-cat/etcd 注册发现，
           PHP 侧 KvmClient 接线；模拟驱动，libvirt 真实驱动为 Phase 2）
        → 创建 Resource / Disk 记录
      → 更新 Order 状态为 completed
```

При сбое поставка автоматически повторяется по стратегии отступления: 1 мин → 5 мин → 15 мин → 1 ч → 6 ч → 24 ч; после 6 попыток задача помечается как неудачная и инициируется оповещение.

### 3. Плагинная архитектура Provider

Поставка ресурсов абстрагирована через `ProviderInterface`; разные инфраструктуры реализуют один и тот же интерфейс:

```
ProviderInterface
  ├── ProxmoxProvider    (自营 Proxmox VE)
  ├── AliyunProvider     (未来：阿里云)
  ├── AwsProvider        (未来：AWS EC2)
  └── DomainProvider     (未来：域名注册商)
```

`ProviderFactory` регистрирует фабричные функции по ключу `productType:provider` и динамически разрешает провайдера на основе ProvisionTask во время выполнения.

### 4. Множественная маршрутизация платежей

`PaymentRouter` динамически возвращает доступные платёжные каналы на основе суммы / валюты / региона заказа; фронтенд переключает канал для запуска оплаты. Каналы настраиваются через таблицу `PaymentChannel` (комиссия, мин./макс. сумма, видимые регионы) — подключение и отключение не требует изменения кода.

### 5. Архитектура безопасности

Глобальная цепочка промежуточных слоёв: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [маршрут: Encryption → Captcha → Auth → Confirmation]`

![Конвейер безопасности](docs/diagrams/security-middleware-zh.svg)

- **CORS** — обработка заголовков кросс-доменных запросов (режим белого списка, поддержка подстановочных знаков *.example.com)
- **SecurityHeaders** — заголовки безопасности ответа (HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy)
- **GeoBlock** — геоблокировка (блокировка доступа из указанных стран по GEO_BLOCKED_COUNTRIES, на основе GeoIP2)
- **WAF** — 45+ правил 8 категорий (SQL-инъекции/XSS/инъекции команд/включение файлов/инъекции в заголовки/SSRF/инъекции NoSQL/открытое перенаправление) + ограничение размера запроса + проверка Content-Type (инъекции значений сканируются в query/body/UA, в path проверяется только обход пути)
- **Security Plugin** — 31 вид обнаружения атак (XSS/SQL-инъекции/инъекции команд/SSRF/десериализация/атаки на JWT/атаки на Host-заголовок/протаскивание запросов/инъекции GraphQL/утечка чувствительных данных и др.), белый список IP + автоматическая блокировка по чёрному списку IP
- **Locale** — разбор `Accept-Language`, установка языка
- **HashidRequest** — автоматическое декодирование строк hashid в запросах в реальные целочисленные ID
- **Version** — проверка заголовка `X-Api-Version`; по умолчанию `v1`, неподдерживаемая версия возвращает `400`
- **ClientPlatform** — проверка заголовка `X-Client-Platform`, определение ОС клиента (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)
- **Encryption** — шифрование передачи AES-256-GCM (интерфейсы аутентификации и панель управления), защита от перехвата и подмены атакующим
- **Captcha** — капча по клику, проверка перед входом/регистрацией (GD-рисование + хранение в Redis, одноразовый ключ, срок действия 300 с, ограничение 3 попыток)
- **Auth** — аутентификация JWT HS256: Access Token 15 минут, Refresh Token 30 дней, чёрный список в Redis
- **Confirmation** — для чувствительных операций (оплата/удаление/возврат/согласование и др.) требуется повторный ввод пароля; 5 неудачных попыток блокируют на 15 минут
- **Ограничение частоты** — по умолчанию 60 раз/мин, вход 5 раз/мин, регистрация 3 раза/мин, оплата 10 раз/мин
- **Журнал аудита** — все чувствительные операции записываются в отдельную базу аудита

### 6. Безопасность данных

**Стратегия многоуровневого шифрования:**

| Уровень | Технология | Описание |
|------|------|------|
| Уровень передачи | AES-256-GCM | Шифрование тел запросов/ответов API; аутентифицированное шифрование GCM защищает от подмены |
| Уровень полей | AES-256-CBC | Автоматическое шифрование/дешифрование чувствительных полей моделей; случайный IV в CBC не раскрывает совпадения значений |
| Уровень первичных ключей | Hashids | Запутывание внешних ID в 12-символьные строки, скрытие реальных масштабов данных |

**Шифрование чувствительных полей:** 14 полей в 7 моделях автоматически шифруются/дешифруются через `Encryptable::class` — `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**Управление ключами:** шифрование передачи и шифрование полей используют разные независимые ключи (`ENCRYPTION_MASTER_KEY` против `ENCRYPTION_KEY`); поддерживается список старых ключей (`ENCRYPTION_PREVIOUS_KEYS`) для ротации ключей без простоя.

### 7. Генерация распределённых ID

Глобально уникальные 64-битные ID генерируются по алгоритму Twitter Snowflake: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. Все 46 моделей Eloquent автоматически получают Snowflake ID в событии `creating`, без зависимости от автоинкремента БД — это естественно поддерживает шардирование базы и таблиц.

### 8. Многоязычность (i18n)

**Автоматическое определение языка глобальным промежуточным слоем:**
- `LocaleMiddleware` считывает заголовок `Accept-Language` и автоматически устанавливает текущий язык
- Поддерживается откат языка: неподдерживаемый язык → `fallback_locale` (en-US)

**Перевод статических текстов:**
- `I18n::trans('auth.login_success')` → `登录成功` / `Login successful`
- Файлы перевода: `i18n/{locale}/messages.php`, 120 записей покрывают все 15 модулей
- Поддержка подстановки параметров: `I18n::trans('validation.required', ['field' => '邮箱'])`

**Многоязычные JSON-поля:**
- Название/описание продукта хранятся как `{"zh-CN":"云服务器","en-US":"Cloud Server"}`
- `I18n::translateField($json)` автоматически выбирает значение по текущему языку
- Шаблоны уведомлений также многоязычны: рассылка идёт на языке, предпочитаемом пользователем

### 9. Полнотекстовый поиск

4 модели — товары, пользователи, заказы, тикеты — подключаются к поиску через trait `Erikwang2013\WebmanScout\Searchable`. По умолчанию используется драйвер `database` (запись — no-op, поиск — деградация до SQL LIKE, без зависимости от ES); после настройки драйвера Elasticsearch индексы синхронизируются автоматически. Поддерживается:

- **Многоязычная сегментация** — IK Analyzer (ik_max_word / ik_smart)
- **Полнотекстовый поиск** — названия товаров, описания, заголовки тикетов
- **Точная фильтрация** — по статусу, категории, ценовому диапазону, периоду времени
- **Пакетная синхронизация** — `php webman scout:import "App\Product\Model\Product"`
- **Пример поиска** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. Флаги стран

Поддержка эмодзи флагов всех стран через `erikwang2013/season`:

- `country_season_flag('CN')` → 🇨🇳, `country_season_flag('JP')` → 🇯🇵
- Автоматически определяет северное/южное полушарие и возвращает соответствующий сезон (на китайском и английском)
- Поддержка локализованных названий сезонов на 30+ языках
- Можно напрямую использовать в селекторе регионов фронтенда, при отображении гражданства пользователя и в других сценариях

## Журнал выполненных задач

- [x] DDL базы данных (`install.sql`, 46 таблиц: управляющие wa_* + бизнес-таблицы без префикса, первичные ключи BigInt без автоинкремента)
- [x] Генерация Snowflake ID (`erikwang2013/snowflake-php`)
- [x] Аутентификация JWT (`erikwang2013/jwt-webman`, HS256 + чёрный список Redis)
- [x] Запутывание ID в API (`erikwang2013/hashids`: автодекодирование запросов + автокодирование ответов)
- [x] Шифрование передачи (`erikwang2013/encryption`, промежуточный слой AES-256-GCM)
- [x] Шифрование на уровне полей (`erikwang2013/encryptable`, автоматическое шифрование/дешифрование чувствительных полей)
- [x] Полнотекстовый поиск (`erikwang2013/webman-scout`: по умолчанию драйвер database с деградацией до SQL LIKE, опционально Elasticsearch + сегментация IK)
- [x] Флаги стран (`erikwang2013/season`, эмодзи флагов Unicode)
- [x] Панель управления (`admin/`, webman-admin + интеграция 7 пакетов, 286 модульных тестов)
- [x] Ревизия кода (применены 2 критических + 4 важных исправления)
- [x] Экспорт Excel (PhpSpreadsheet ^2.0, Crud/Table панели управления + серверное API управления)
- [x] Визуализация дашборда (диаграммы ECharts + анимированные карточки статистики + панель информации о системе)
- [x] Экспорт PDF (html2canvas + jsPDF, экспорт снимков дашборда)
- [x] Скрипты миграции БД (единый DDL `install.sql`, команда `php webman migrate`)
- [x] Реальная интеграция Stripe (SDK stripe-php, PaymentIntent + проверка подписи Webhook)
- [x] Реальная интеграция SMS Twilio (twilio/sdk, включая обработку сбоев отправки)
- [x] Реальная интеграция push FCM (kreait/firebase-php, включая очистку недействительных токенов)
- [x] Капча по клику (erikwang2013/poster-php, проверка при входе/регистрации и чувствительных операциях)
- [x] Повторное подтверждение (ConfirmationMiddleware, повторный ввод пароля для чувствительных операций, 5 неудач = блокировка на 15 минут)
- [x] Модульные тесты сервера (672 теста / 1632 утверждения, 15 пропущено)
- [x] Определение платформы клиента (ClientPlatformMiddleware, заголовок X-Client-Platform поддерживает 8 платформ)
- [x] Усиление WAF (45+ правил 8 категорий: SQL-инъекции/XSS/инъекции команд/включение файлов/инъекции в заголовки/SSRF/инъекции NoSQL/открытое перенаправление + ограничение размера запроса + проверка Content-Type)
- [x] Security Plugin (erikwang2013/security-php: 31 вид обнаружения атак + автоматическая блокировка по чёрному списку IP + ротация логов)
- [x] Промежуточный слой WAF панели Admin
- [x] Разделение чтения/записи MySQL (подключения Eloquent read/write + sticky)
- [x] Многоуровневый кэш Redis (CacheService: товары/регионы/курсы/TLD/пользователи, TTL + активная инвалидация + прогрев)
- [x] Сжатие ответов Nginx + оптимизация соединений (gzip/proxy_buffering/keep-alive/limit_req+limit_conn)
- [x] Рекомендации по индексам БД (13 рекомендованных составных/покрывающих индексов)
- [x] Мониторинг исключений Sentry (SentryBootstrap + колбэк деидентификации before_send)
- [x] Переключатели Feature Flags (динамическое переопределение через Redis + API панели управления)
- [x] Внешний API поставщиков (аутентификация по API Key + эндпоинты заказов/ресурсов/расчётов/вывода средств)
- [x] Реальный push WebSocket (нативный WebSocket Workerman + прослушивание событий заказов/тикетов)
- [x] Скрипты нагрузочного тестирования k6 (дымовые/продуктовые/конкурентные нагрузки)
- [x] Конвейер CI/CD (GitHub Actions: проверка синтаксиса + PHPUnit обеих частей + проверка Composer)
- [x] Мастер установки в один клик (Web UI: проверка окружения + настройка БД + создание администратора + автогенерация .env)

## Спасибо за поддержку проекта

| WeChat | Alipay |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

### Международный банковский перевод

**Информация о получателе**

- Имя получателя: WANG KEXUN
- Номер счёта получателя: 881015918251

**Банк получателя (ZA Bank)**

- SWIFT Code: AABLHKHHXXX
- Название банка: ZA Bank Limited
- Код банка: 387
- Адрес банка: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**Банк-посредник для международных переводов (при необходимости)**

> Обратите внимание: это информация о банке-посреднике (корреспондентском банке) для международных переводов, а не о банке получателя. Уточните в своём банке, требуется ли указывать данные банка-посредника.

- Для переводов в гонконгских долларах, юанях и долларах США банком-посредником является **Citibank**:
  - Название банка: Citibank N.A. Hong Kong
  - SWIFT Code: CITIHKHXXXX
  - Код банка: 006
  - Название отделения: Hong Kong Branch
  - Код отделения: 391
  - Адрес банка: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- Для переводов в других валютах банком-посредником является **BNY Mellon**:
  - Название банка: THE BANK OF NEW YORK MELLON
  - SWIFT Code: IRVTUS3NXXX
  - Адрес банка: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### Пожертвование в криптовалюте (Crypto Donation)

Если этот проект помог вам, отсканируйте QR-код, чтобы сделать пожертвование, спасибо!

| <img src="../../coin/1.jpg" width="200" alt="BNB Smart Chain (BEP20)"><br>**BNB Smart Chain (BEP20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/2.jpg" width="200" alt="Tron (TRC20)"><br>**Tron (TRC20)**<br>`TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| <img src="../../coin/3.jpg" width="200" alt="Ethereum (ERC20)"><br>**Ethereum (ERC20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/4.jpg" width="200" alt="Aptos"><br>**Aptos**<br>`0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| <img src="../../coin/5.jpg" width="200" alt="Plasma"><br>**Plasma**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/6.jpg" width="200" alt="Polygon POS"><br>**Polygon POS**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |
| <img src="../../coin/7.jpg" width="200" alt="Solana"><br>**Solana**<br>`2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` | <img src="../../coin/8.jpg" width="200" alt="The Open Network (TON)"><br>**The Open Network (TON)**<br>`UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| <img src="../../coin/9.jpg" width="200" alt="Arbitrum One"><br>**Arbitrum One**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/10.jpg" width="200" alt="AVAX C-Chain"><br>**AVAX C-Chain**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## License

Редакция Lite — MIT License | Редакции Standard/Full — Proprietary
