# Cloud Platform — Plataforma Global de Negociação de Recursos em Nuvem

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
  <img src="docs/diagrams/c.svg" alt="CloudPlatform 项目宠物" width="220">
</p>

Plataforma de negociação de recursos em nuvem voltada a usuários globais, com suporte à compra online e entrega automática de servidores (VM), endereços IP, discos em nuvem, domínios, certificados SSL, armazenamento de objetos (S3), aceleração de CDN e outros produtos. As máquinas físicas próprias são entregues por meio de virtualização Proxmox VE, com suporte também à integração de fornecedores terceirizados para venda. Oferece cobrança por uso (pay-as-you-go), distribuição por afiliados, API GraphQL e observabilidade via Prometheus/Grafana.

## Pilha de Tecnologias

| Camada | Tecnologia |
|------|------|
| Framework Backend | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| Painel Administrativo | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| Autenticação | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| Chave Primária Distribuída | ID Snowflake ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| Ofuscação de ID | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| Criptografia de Transporte | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| Criptografia de Campos | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| Busca em Texto Completo | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| Bandeiras de Países | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| Captcha de Clique | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| Proteção de Segurança | 31 tipos de detecção de ataques ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| Exportação de Tabelas | PhpSpreadsheet ^2.0 |
| SDK de Pagamento | Stripe PHP ^15.0 |
| SDK de SMS | Twilio PHP ^8.0 |
| SDK de Push | Firebase PHP ^7.0 |
| Filas | webman redis-queue |
| Banco de Dados | MySQL 8.0 (conexões duplas: principal + banco de auditoria) |
| Mecanismo de Busca | Elasticsearch 8.x |
| Virtualização | Proxmox VE (canal gRPC kvm-server em Rust, registro e-cat/etcd) |
| Clientes | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| Armazenamento de Objetos | AWS S3 SDK PHP ^3.300 |
| Observabilidade | Prometheus + Grafana (painéis pré-configurados) |
| Internacionalização | i18n com 7 idiomas (zh/en/ja/ko/de/fr/es) |
| Implantação | Docker Compose, inicialização com um clique |

## Arquitetura do Sistema

![Arquitetura do Sistema](docs/diagrams/system-architecture-zh.svg)

## Fluxo de Negócio Principal

Fluxo de negócio completo de ponta a ponta, do registro do usuário à entrega do recurso, incluindo seleção de produto, pedido, pagamento, entrega automática, pós-venda e ciclo de renovação.

![Fluxo de Negócio Principal](docs/diagrams/business-flowchart-zh.svg)

## Liquidação Multimoeda

O sistema suporta nativamente precificação, pagamento e liquidação em múltiplas moedas, cobrindo toda a cadeia: configuração de moeda do usuário, precificação por região, snapshot de taxa de câmbio, recebimento de pagamentos, crédito de saldo e liquidação com fornecedores.

![Fluxograma de Liquidação Multimoeda](docs/diagrams/currency-settlement-zh.svg)

**1. Contas de saldo multimoeda**

`user_balances` mantém a contabilidade por moeda com base em `(user_id, currency)` (índice único `uk_user_currency`). No registro, são criadas por padrão duas contas de moeda — USD e CNY — e o saldo e o saldo congelado são gerenciados de forma independente por moeda, podendo ser estendidos a qualquer moeda suportada pelo Stripe.

**2. Precificação regional multimoeda**

`product_regions` permite que um mesmo SKU seja precificado em múltiplas moedas em uma mesma região (índice único `uk_sku_region_currency`). O frontend exibe o preço na moeda preferida do usuário e, no momento do pedido, o `OrderService` obtém o preço exato por `(sku_id, region_id, currency)`.

**3. Sistema de câmbio**

A tarefa agendada `ExchangeRateSync` sincroniza as taxas de câmbio da exchangerate-api e as grava no Redis (cache com TTL de 30 minutos). Cada pedido registra um snapshot da taxa de câmbio `exchange_rate` no momento da compra, garantindo rastreabilidade na liquidação posterior.

**4. Pagamento multimoeda**

`payment_channels.currency_support` declara a lista de moedas suportadas por cada canal de pagamento, e o `PaymentRouter` filtra dinamicamente os canais disponíveis por moeda / faixa de valor / regiões visíveis. O Stripe PaymentIntent recebe o pagamento diretamente na moeda do pedido, com tratamento interno de casas decimais para 16 moedas de zero decimal (JPY / KRW / VND etc.), e o callback do Webhook valida a consistência entre valor e moeda.

**5. Liquidação e relatórios**

As transações de pagamento (`payment_transactions`), as liquidações com fornecedores (`supplier_settlements`) e os relatórios de receita mantêm campos de moeda e taxa de câmbio, permitindo agregação por moeda.

## Visão Geral dos Módulos de Funcionais

O sistema é organizado em uma arquitetura de quatro camadas: camada de clientes (integração de 6 plataformas), camada de gateway de API (12 middlewares), camada de serviços de negócio (20+ módulos funcionais) e camada de infraestrutura (8 componentes centrais).

![Visão Geral dos Módulos](docs/diagrams/module-overview-zh.svg)

## Ciclo de Vida dos Recursos

Os recursos passam por 6 estados, da criação à terminação, conduzidos por 8 eventos de ciclo de vida, com suporte a entrega automática, suspensão/retomada, lembretes de vencimento e limpeza na destruição.

![Ciclo de Vida dos Recursos](docs/diagrams/resource-lifecycle-zh.svg)

## Navegação da Documentação

| Documento | Descrição |
|------|------|
| [Documento de Arquitetura](docs/architecture.md) | Arquitetura do sistema, relações entre componentes, pipeline de middlewares, camadas de segurança, arquitetura de dados, topologia de implantação |
| [Documento de Design de Funcionalidades](docs/features.md) | Design funcional detalhado dos 21 módulos, com fluxogramas, modelos de dados e descrições de interação |
| [Documentação da API](docs/api-reference.md) | Referência completa de 200+ endpoints, agrupados por módulo, com exemplos de requisição/resposta e códigos de erro |
| [Documentação online da API (service)](http://localhost:8787/apidoc) | Gerada automaticamente por hg/apidoc, agrupada por funcionalidade, com depuração online |
| [Documentação online da API (admin)](http://localhost:8788/apidoc) | Gerada automaticamente por hg/apidoc, 54 controladores em 13 grupos funcionais |
| [Design do Painel Administrativo](docs/admin-design.md) | Arquitetura do painel Admin, integração de pacotes, ACL, suíte de testes |
| [Documentação da API de Fornecedores](docs/supplier-api.md) | Referência da API de fornecedores (interna + externa), exemplos de SDK |
| [Checklist de Implantação](docs/deployment.md) | Configuração do servidor, variáveis de ambiente, Nginx, HTTPS, tarefas agendadas |
| [Relatório de Revisão](docs/review-report-2026-08-04.md) | Relatório de revisão de extensão do ecossistema, com dados estatísticos, rastreamento de problemas e sugestões |
| [Comparação de Edições](docs/editions.md) | Comparação de funcionalidades, design e arquitetura entre as edições Lite/Standard/Pro |

## Estrutura de Diretórios

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
│   │   ├── version/middleware/  # API 版本中间件（URL 路径版本段校验）
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

## Início Rápido

### Requisitos de Ambiente

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### Instalação com um Clique (recomendada)

O projeto fornece um assistente de instalação web, com o qual toda a configuração pode ser concluída no navegador:

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

Após a instalação, o assistente fará automaticamente o seguinte:
- Criará todas as 46 tabelas do banco de dados (tabelas de administração `wa_*` + tabelas de negócio sem prefixo)
- Criará o papel de superadministrador e a conta correspondente
- Gerará os arquivos de configuração `service/.env` e `admin/.env` (com chaves JWT/criptografia geradas automaticamente)

### Instalação Manual

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

### Implantação com Docker

```bash
# 从项目根目录
cp service/.env.example .env
# 编辑 .env 填写各项密钥

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### Painel Administrativo

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

### Modo Daemon

```bash
php start.php start -d          # 启动
php start.php status            # 查看状态
php start.php restart           # 重启
php start.php stop              # 停止
```

## Guia de Uso

### Entrar

- **Portal do usuário**: acesse o serviço de API (padrão `http://localhost:8787`), registre-se e entre. OAuth Google / Apple e autenticação em duas etapas TOTP são suportados
- **Painel administrativo**: abra `http://localhost:8787/app/admin` no navegador (o painel é uma instância separada, porta 8788) e entre com a conta de administrador criada pelo instalador

### Recursos comuns do painel

- **Dashboard**: estatísticas de pedidos / receita / novos usuários / recursos ativos de hoje, tendência de receita de 30 dias, exportação em PDF
- **Central de relatórios**: relatórios de pedidos, ranking de produtos, estatísticas por canal, crescimento de usuários, exportação em Excel
- **Gestão diária**: usuários / produtos / pedidos / fornecedores / tickets / domínios / CDN, revisão KYC, reembolsos, aprovação de liquidações e saques
- **Configuração do sistema**: canais de pagamento, contas CDN, webhooks, modelos de notificação, artigos de ajuda, logs de auditoria

### Compilação de clientes

- **Cliente Flutter** (`apps/flutter/`): iOS / Android / Web / Linux / macOS / Windows. `flutter pub get` para dependências, `flutter run` para depurar, `flutter build apk` / `flutter build ios` / `flutter build web` para empacotar
- **Cliente HarmonyOS** (`apps/harmonyos/`): aplicativo nativo ArkTS — abra o projeto `entry` com o DevEco Studio para compilar e executar

## Visão Geral da API

As interfaces são agrupadas por módulo, com exemplos de requisição/resposta e códigos de erro: [Visão Geral da API](docs/api-overview.md) (selecionadas) · [Documentação da API](docs/api-reference.md) (referência completa de 200+ endpoints) · [Depuração online](http://localhost:8787/apidoc)

## Arquitetura do Painel Administrativo

### Integração Técnica

O painel administrativo é uma instância webman independente que integra 7 pacotes erikwang2013:

| Pacote | Finalidade | Implementação |
|---|------|---------|
| snowflake-php | Chave primária distribuída de 64 bits | Gerada automaticamente no evento `creating` do `Base::boot()` |
| hashids | Ofuscação de IDs de API | Codificação na resposta via `Base::json()`, decodificação na requisição via `Crud::selectInput/updateInput/deleteInput` |
| encryptable | Criptografia de campos do banco | Cast `Encryptable` do Eloquent; criptografia/descriptografia transparente em Admin (password/email/mobile) e User (6 campos) |
| encryption | Criptografia de transporte da API | Funções auxiliares `encrypt_data()`/`decrypt_data()` reservadas |
| webman-scout | Busca em texto completo no ES | Trait `Searchable` no modelo User, sincronização automática de índice |
| season | Emoji de bandeiras de países | Função global `country_season_flag()` |
| poster-php | Captcha de clique | Bootstrap `CaptchaPlugin`, funções globais `captcha_create()`/`captcha_verify()` |

### Camadas de Segurança

```
请求 → Hashids 解码 (Crud::selectInput/updateInput/deleteInput)
  → ACL 鉴权 (api/Auth.php, 控制器 noNeedLogin/noNeedAuth)
  → 业务处理 (CRUD / 模型事件)
  → Encryptable 字段加密 (Eloquent casts set)
  → 数据库写入
响应 ← Hashids 编码 (Base::json → hashids_encode_ids)

登录/注册：Captcha 验证 → Auth → 业务处理
```

### Fluxo de Dados

- **Caminho de escrita**: ID da requisição (hashid) → decodificado para int → operação CRUD → Snowflake gera novo ID → Encryptable criptografa campos sensíveis → DB
- **Caminho de leitura**: DB → Encryptable descriptografa → Hashids codifica ID → resposta JSON

### Cobertura de Testes

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

## Filosofia de Design

### 1. Monólito Modular

Os módulos são divididos verticalmente por domínio de negócio (User / Product / Order / Payment / Provisioning / Ticket / Notification etc.), e cada módulo segue internamente a arquitetura MVC:

- **Controller** — camada HTTP: validação de parâmetros, chamada a Services, retorno de Response
- **Service** — lógica de negócio, sem dependência de HTTP, reutilizável por Controllers e Queue Workers
- **Model** — modelos de dados Eloquent, definem relações e escopos de consulta

Os módulos se comunicam por meio de **eventos** e **interfaces**, sem chamar diretamente os Services uns dos outros. Por exemplo: pagamento concluído → evento `OrderPaid` → `ProvisioningService` entrega o recurso automaticamente; criação de Ticket → evento `TicketCreated` → atribuição automática de atendente.

### 2. Entrega Orientada a Eventos

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

As falhas de entrega são tentadas novamente automaticamente, com estratégia de backoff: 1min → 5min → 15min → 1h → 6h → 24h; após 6 tentativas, a tarefa é marcada como falha e um alerta é disparado.

### 3. Arquitetura de Plugins de Providers

A entrega de recursos é abstraída pela `ProviderInterface`, e diferentes infraestruturas implementam a mesma interface:

```
ProviderInterface
  ├── ProxmoxProvider    (自营 Proxmox VE)
  ├── AliyunProvider     (未来：阿里云)
  ├── AwsProvider        (未来：AWS EC2)
  └── DomainProvider     (未来：域名注册商)
```

O `ProviderFactory` registra funções de fábrica com a chave `productType:provider` e as resolve dinamicamente em tempo de execução com base no ProvisionTask.

### 4. Roteamento de Múltiplos Pagamentos

O `PaymentRouter` retorna dinamicamente os canais de pagamento disponíveis com base no valor do pedido / moeda / região; o frontend pode iniciar o pagamento simplesmente alternando o canal. Os canais são configurados pela tabela `PaymentChannel` (taxas, valores mínimo/máximo, regiões visíveis), podendo ser ativados ou desativados sem alteração de código.

### 5. Arquitetura de Segurança

Cadeia global de middlewares: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [路由: Encryption → Captcha → Auth → Confirmation]`

![Pipeline de Middlewares de Segurança](docs/diagrams/security-middleware-zh.svg)

- **CORS** — tratamento de cabeçalhos de requisições entre origens (modo de lista de permissões, com suporte a curinga `*.example.com`)
- **SecurityHeaders** — cabeçalhos de resposta de segurança (HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy)
- **GeoBlock** — bloqueio geográfico (bloqueia acesso de países específicos com base em GEO_BLOCKED_COUNTRIES, via GeoIP2)
- **WAF** — 8 categorias, 45+ regras (SQL injection/XSS/injeção de comando/inclusão de arquivo/injeção de cabeçalho/SSRF/injeção NoSQL/redirecionamento aberto) + limite de tamanho de requisição + validação de Content-Type (varredura de valores em query/body/UA; path verifica apenas travessia de caminho)
- **Security Plugin** — detecção de 31 tipos de ataques (XSS/injeção de SQL/injeção de comando/SSRF/desserialização/ataques JWT/ataques de Host header/smuggling de requisição/injeção GraphQL/vazamento de dados sensíveis etc.), com lista de permissões e banimento automático de IPs na lista negra
- **Locale** — analisa `Accept-Language` e define o idioma
- **HashidRequest** — decodifica automaticamente strings hashid nas requisições para IDs inteiros reais
- **Version** — valida o segmento de versão no URL path (ex.: `/api/v1/`); versões não suportadas retornam `400`
- **ClientPlatform** — valida o cabeçalho `X-Client-Platform` e identifica a plataforma do sistema operacional do cliente (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)
- **Encryption** — criptografia de transporte AES-256-GCM (interfaces de autenticação e painel administrativo), evita interceptação e adulteração intermediária
- **Captcha** — captcha de clique, verificado antes do login/registro (desenho GD + armazenamento em Redis, chave de uso único, validade de 300s, limite de 3 tentativas)
- **Auth** — autenticação JWT HS256, Access Token de 15 minutos, Refresh Token de 30 dias, lista negra em Redis
- **Confirmation** — operações sensíveis (pagamento/exclusão/reembolso/aprovação etc.) exigem reentrada da senha; 5 falhas bloqueiam por 15 minutos
- **Limite de frequência** — padrão de 60/min, login 5/min, registro 3/min, pagamento 10/min
- **Log de auditoria** — todas as operações sensíveis são gravadas em um banco de auditoria separado

### 6. Segurança de Dados

**Estratégia de criptografia em camadas:**

| Camada | Tecnologia | Descrição |
|------|------|------|
| Transporte | AES-256-GCM | Criptografia dos corpos de requisição/resposta da API; criptografia autenticada GCM evita adulteração |
| Campos | AES-256-CBC | Criptografia/descriptografia automática de campos sensíveis do modelo; IV aleatório no CBC não vaza padrões de equivalência |
| Chave primária | Hashids | IDs externos ofuscados em strings de 12 caracteres, ocultando a escala real dos dados |

**Criptografia de campos sensíveis:** 14 campos de 7 modelos usam `Encryptable::class` para criptografia/descriptografia automática — `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**Gerenciamento de chaves:** a criptografia de transporte e a de campos usam chaves independentes distintas (`ENCRYPTION_MASTER_KEY` vs `ENCRYPTION_KEY`), com suporte a lista de chaves anteriores (`ENCRYPTION_PREVIOUS_KEYS`) para rotação de chaves sem parada do serviço.

### 7. Geração Distribuída de IDs

Usa o algoritmo Twitter Snowflake para gerar IDs globais exclusivos de 64 bits: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. Todos os 46 modelos Eloquent geram automaticamente IDs Snowflake no evento `creating`, sem dependência de autoincremento do banco, com suporte natural a sharding de banco/tabela.

### 8. Internacionalização (i18n)

**Análise automática por middleware global:**
- `LocaleMiddleware` lê o cabeçalho `Accept-Language` e define o idioma atual automaticamente
- Suporte a fallback de idioma: idiomas não suportados → `fallback_locale` (en-US)

**Tradução de textos estáticos:**
- `I18n::trans('auth.login_success')` → `登录成功` / `Login successful`
- Arquivos de tradução: `i18n/{locale}/messages.php`, 120 entradas cobrindo todos os 15 módulos
- Suporte a substituição de parâmetros: `I18n::trans('validation.required', ['field' => '邮箱'])`

**Campos JSON multilíngues:**
- Nome/descrição do produto armazenados como `{"zh-CN":"云服务器","en-US":"Cloud Server"}`
- `I18n::translateField($json)` retorna automaticamente o valor no idioma atual
- Os modelos de notificação também suportam vários idiomas, enviados de acordo com o idioma preferido do usuário

### 9. Busca em Texto Completo

Os 4 modelos Product, User, Order e Ticket são integrados à busca por meio do Trait `Erikwang2013\WebmanScout\Searchable`. O driver padrão é `database` (escrita no-op, busca com fallback SQL LIKE, sem dependência de ES); ao configurar o driver Elasticsearch, os índices são sincronizados automaticamente, com suporte a:

- **Segmentação multilingue** — IK Analyzer (ik_max_word / ik_smart)
- **Busca em texto completo em chinês** — nome do produto, descrição, título do ticket
- **Filtros precisos** — por status, categoria, faixa de preço e intervalo de tempo
- **Sincronização em lote** — `php webman scout:import "App\Product\Model\Product"`
- **Exemplo de busca** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. Bandeiras de Países

Suporte global a emojis de bandeiras de países via `erikwang2013/season`:

- `country_season_flag('CN')` → 🇨🇳, `country_season_flag('JP')` → 🇯🇵
- Detecta automaticamente o hemisfério e retorna a estação correspondente (zh/en)
- Suporte a nomes de estações localizados em 30+ idiomas
- Pode ser chamado diretamente na seleção de região do frontend e na exibição da nacionalidade do usuário

## Lista de Tarefas Concluídas

- [x] DDL do banco de dados (`install.sql`, 46 tabelas, tabelas de administração `wa_*` + tabelas de negócio sem prefixo, chave primária BigInt não autoincrementável)
- [x] Geração de IDs Snowflake (`erikwang2013/snowflake-php`)
- [x] Autenticação JWT (`erikwang2013/jwt-webman`, HS256 + lista negra Redis)
- [x] Ofuscação de IDs da API (`erikwang2013/hashids`, decodificação automática na requisição + codificação automática na resposta)
- [x] Criptografia de transporte (`erikwang2013/encryption`, middleware AES-256-GCM)
- [x] Criptografia em nível de campo (`erikwang2013/encryptable`, criptografia/descriptografia automática de campos sensíveis)
- [x] Busca em texto completo (`erikwang2013/webman-scout`, driver padrão database com fallback SQL LIKE, opcional Elasticsearch + segmentação IK)
- [x] Bandeiras de países (`erikwang2013/season`, emoji de bandeiras Unicode)
- [x] Painel administrativo (`admin/`, webman-admin + integração de 7 pacotes, 286 testes unitários)
- [x] Revisão de código (2 correções críticas + 4 correções importantes aplicadas)
- [x] Exportação Excel (PhpSpreadsheet ^2.0, Crud/Table do painel + API de administração do servidor)
- [x] Visualização do dashboard (gráficos ECharts + cards de estatísticas animados + painel de informações do sistema)
- [x] Exportação PDF (html2canvas + jsPDF, exportação de capturas do dashboard)
- [x] Scripts de migração do banco (`install.sql` DDL unificado, comandos `php webman migrate`)
- [x] Integração real com Stripe (SDK stripe-php, PaymentIntent + validação de assinatura do Webhook)
- [x] Integração real com SMS Twilio (twilio/sdk, com tratamento de falha de envio)
- [x] Integração real com push FCM (kreait/firebase-php, com limpeza de tokens inválidos)
- [x] Captcha de clique (erikwang2013/poster-php, verificação em operações sensíveis de login/registro)
- [x] Confirmação secundária (ConfirmationMiddleware, reentrada de senha em operações sensíveis, 5 falhas bloqueiam 15 minutos)
- [x] Testes unitários do servidor (672 tests / 1632 assertions, 15 skipped)
- [x] Identificação de plataforma do cliente (ClientPlatformMiddleware, cabeçalho X-Client-Platform com suporte a 8 plataformas)
- [x] Reforço de segurança WAF (8 categorias, 45+ regras: injeção de SQL/XSS/injeção de comando/inclusão de arquivo/injeção de cabeçalho/SSRF/injeção NoSQL/redirecionamento aberto + limite de tamanho de requisição + validação de Content-Type)
- [x] Security Plugin (erikwang2013/security-php, detecção de 31 tipos de ataques + banimento automático de IPs da lista negra + rotação de logs)
- [x] Middleware WAF no painel Admin
- [x] Separação leitura/escrita no MySQL (conexões Eloquent read/write + sticky)
- [x] Camada de cache Redis em múltiplos níveis (CacheService: produtos/regiões/câmbio/TLD/usuários, TTL + invalidação ativa + pré-aquecimento)
- [x] Compressão de resposta Nginx + otimização de conexões (gzip/proxy_buffering/keep-alive/limit_req+limit_conn)
- [x] Recomendações de índices do banco (13 índices compostos/cobertos recomendados)
- [x] Monitoramento de exceções com Sentry (SentryBootstrap + callback before_send com mascaramento)
- [x] Feature Flags (sobreposição dinâmica via Redis + API do painel administrativo)
- [x] API externa de fornecedores (autenticação por API Key + endpoints de pedidos/recursos/liquidação/saques)
- [x] Push em tempo real via WebSocket (WebSocket nativo do Workerman + listeners de eventos de pedidos/tickets)
- [x] Scripts de teste de carga k6 (smoke/produtos/concorrência)
- [x] Pipeline CI/CD (GitHub Actions, lint + PHPUnit nos dois projetos + validação do Composer)
- [x] Assistente de instalação com um clique (Web UI, verificação de ambiente + configuração do banco + criação do admin + geração automática do .env)

## Projeto de Código Aberto — Seu Apoio é Bem-vindo

| WeChat | Alipay |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

### Transferência Internacional (Remessa Bancária)

**Dados do Beneficiário**

- Nome do beneficiário: WANG KEXUN
- Número da conta: 881015918251

**Banco Beneficiário (ZA Bank)**

- SWIFT Code: AABLHKHHXXX
- Nome do banco: ZA Bank Limited
- Código do banco: 387
- Endereço do banco: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**Banco Intermediário para Remessas Internacionais (se necessário)**

> Observe que estas são informações do banco intermediário (banco de trânsito) para remessas internacionais, e não do banco beneficiário. Consulte seu banco emissor para saber se as informações do banco intermediário são necessárias.

- Para remessas em dólares de Hong Kong, renminbi e dólares americanos, o banco intermediário é o **Citibank**:
  - Nome do banco: Citibank N.A. Hong Kong
  - SWIFT Code: CITIHKHXXXX
  - Código do banco: 006
  - Nome da agência: Hong Kong Branch
  - Código da agência: 391
  - Endereço do banco: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- Para remessas em outras moedas, o banco intermediário é o **BNY Mellon**:
  - Nome do banco: THE BANK OF NEW YORK MELLON
  - SWIFT Code: IRVTUS3NXXX
  - Endereço do banco: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### Doação em criptomoedas (Crypto Donation)

Se este projeto ajudar você, escaneie o código QR para doar, obrigado!

| <img src="../../coin/1.jpg" width="200" alt="BNB Smart Chain (BEP20)"><br>**BNB Smart Chain (BEP20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/2.jpg" width="200" alt="Tron (TRC20)"><br>**Tron (TRC20)**<br>`TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| <img src="../../coin/3.jpg" width="200" alt="Ethereum (ERC20)"><br>**Ethereum (ERC20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/4.jpg" width="200" alt="Aptos"><br>**Aptos**<br>`0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| <img src="../../coin/5.jpg" width="200" alt="Plasma"><br>**Plasma**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/6.jpg" width="200" alt="Polygon POS"><br>**Polygon POS**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |
| <img src="../../coin/7.jpg" width="200" alt="Solana"><br>**Solana**<br>`2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` | <img src="../../coin/8.jpg" width="200" alt="The Open Network (TON)"><br>**The Open Network (TON)**<br>`UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| <img src="../../coin/9.jpg" width="200" alt="Arbitrum One"><br>**Arbitrum One**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/10.jpg" width="200" alt="AVAX C-Chain"><br>**AVAX C-Chain**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## License

Edição Lite — MIT License | Edições Standard/Pro — Proprietary
