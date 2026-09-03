# CloudPlatform アーキテクチャ設計ドキュメント

## 1. システム概要

CloudPlatform は世界中のユーザー向けのクラウドリソース取引プラットフォームで、自社運営の物理マシン + サードパーティサプライヤーのハイブリッドモデルをサポートする。ユーザーは Web/モバイルからサーバー(VM)、IP アドレス、クラウドディスク、ドメインなどの製品を購入でき、システムが決済処理とリソース交付を自動で完了する。

### 1.1 中核アーキテクチャの判断

| 判断 | 選択 | 理由 |
|------|------|------|
| バックエンドフレームワーク | PHP webman (Workerman) | 常駐メモリ、イベント駆動、マルチプロセス、ミリ秒応答 |
| アーキテクチャパターン | モジュール式モノリス | モジュールは業務ごとに垂直分割、内部は MVC レイヤリング、モジュール間はイベントで疎結合 |
| 管理画面 | 独立 webman インスタンス (webman-admin + Layui) | 管理トラフィックとユーザートラフィックを分離、障害ドメインを分離 |
| ORM | Illuminate/Eloquent | Laravel エコシステムが成熟、関連クエリ、Scope、イベント、マイグレーション |
| 分散主キー | Snowflake 64-bit | オートインクリメントに依存せず、シャーディングに自然対応 |
| ID 混淆 | Hashids | 外部に実 ID 規模を隠し、クローラーの走査を防止 |
| 認証 | JWT HS256 | ステートレス認証、Access 15min + Refresh 30d |
| 転送暗号化 | AES-256-GCM | ミドルウェアが透過的に暗号化/復号、GCM 認証暗号で改ざん防止 |
| フィールド暗号化 | AES-128-ECB | Eloquent Cast が自動で暗号化/復号、決定性暗号化（暗号文で等値クエリ可能、ログイン/一意性検証が依存）；ECB のみ対応 |
| メッセージキュー | Redis Queue | 決済コールバック、通知配信、リソース開通を非同期処理 |
| 検索エンジン | database（デフォルト）/ Elasticsearch 8.x | webman-scout はデフォルトで database ドライバー（SQL LIKE にフォールバック）；ES 設定後に IK 分詞インデックスを有効化 |
| 仮想化 | Proxmox VE + kvm-server | 自社 VM は Rust kvm-server（gRPC :50051、e-cat/etcd 登録検出）が供給；ドライバー層は現在シミュレーションドライバー、libvirt 実ドライバーは Phase 2 |
| クライアント | Flutter | 単一コードベースで iOS/macOS/Windows/Linux/Web の 5 端末 + HarmonyOS |

### 1.2 システム境界

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

## 2. コンポーネントアーキテクチャ

### 2.1 デュアルインスタンス設計

プロジェクトは 2 つの独立した webman インスタンスを含み、MySQL データベースを共有する：

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

| インスタンス | ポート | 責務 | ミドルウェア |
|------|------|------|--------|
| **service** | 8787 | ユーザー API + 管理 API + WebSocket | グローバル 12 + ルート 6 + SupplierApiKey |
| **admin** | 8788 | 管理画面 HTML パネル (Layui) | WafMiddleware + AccessControl |

### 2.2 モジュールレイヤリング構造

各業務モジュール内部は統一されたレイヤリングに従う：

```
app/{Module}/
├── controller/     # HTTP 層：参数校验、调用 Service、返回 Response
│   └── external/   # 外部 API 控制器（供应商 API Key 认证）
├── service/        # 业务逻辑：无 HTTP 依赖，可被 Controller/Queue Worker 复用
├── model/          # Eloquent 数据模型：关系定义、查询作用域、Casts
├── event/          # 领域事件定义（OrderPaid、TicketCreated 等）
├── listener/       # 事件监听器（Provisioning、WebSocket 推送）
├── provider/       # 云厂商适配器（ProxmoxProvider 等）
├── queue/          # 队列消费者（ProvisionWorker、EmailSender 等）
└── cron/           # 定时任务（ExchangeRateSync、ExpirationCheck 等）
```

### 2.3 共通ライブラリのレイヤリング

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
├── version/             # VersionMiddleware（从 URL 路径校验版本）
└── webhook/             # Webhook 事件分发器
```

### 2.4 CDN モジュール

製品レベルの CDN モジュール（`service/app/cdn/`）はアダプターパターンで 4 社のプロバイダーと連携し、サーバーまたはストレージバケットをオリジンとして CDN に接続する：

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4（SSL SaaS 自動証明書）、ICP 登録不要
  ├── CloudFrontAdapter   aws-sdk-php（CloudFront + ACM）、ICP 登録不要
  ├── AliyunCdnAdapter    RPC 署名、ICP 登録必要
  └── TencentCdnAdapter   TC3 署名、ICP 登録必要
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① バインドアカウント (provider_account_id) → ② code=cdn-{type} のアクティブアカウント → ③ env フォールバック
  strict=true（削除/purge）：バインドアカウントのみ使用、欠落時は 4003、静かに切り替えない
```

**アカウント管理：** `provider_apis` モデルを再利用（資格情報は Encryptable で暗号化して保存）、管理側 `/admin/providers` CRUD（RbacMiddleware）、`code` は `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent` と規定、env 資格情報は fallback に降格。

**データモデル：** `resource_cdn`（provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config；cert_config は保存前に秘密鍵を除去）。権限分離：CDN リソースは `resource.user_id` の帰属検証を経て、非自ユーザーは一律 404。

---

## 3. ミドルウェア実行パイプライン

### 3.1 グローバルミドルウェアチェーン（全リクエスト）

```
HTTP 请求
  │
  ▼
1. VersionMiddleware         ← 从 URL 路径校验版本，无效版本返回 400
  │                            仅对 /api/v1/ 和 /admin/api/v1/ 生效
  ▼
2. CorsMiddleware            ← OPTIONS 预检返回 CORS 头，Origin 反射
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy 安全响应头
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform 头识别（8 平台），注入 properties
  │                            仅对 /api/v1/ 和 /admin/api/v1/ 生效
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
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM 请求/响应体加解密
  │
  ├─ /api/v1 ((用户认证) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token 验证 → $request->userId/role
  │
  ├─ /api/v1 ((敏感操作) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← 密码二次确认，Redis 计数器，5 次锁 15min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 验证 → $request->supplierId
  │
  ├─ /admin/api/v1 ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC 权限检查
  │
  └─ /admin/api/v1 (敏感操作) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
控制器 → Service → Model → DB
```

### 3.2 各ミドルウェアの詳細

| ミドルウェア | 場所 | 登録方式 | 責務 |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | グローバル | URL パスからバージョンを検証 |
| `CorsMiddleware` | common/Security | グローバル | OPTIONS プリフライト、Origin 反射 |
| `SecurityHeadersMiddleware` | common/Security | グローバル | HSTS / X-Frame-Options / CSP / Referrer-Policy セキュリティレスポンスヘッダー |
| `ClientPlatformMiddleware` | common/ClientPlatform | グローバル | `X-Client-Platform` 8 プラットフォーム識別 |
| `GeoBlockMiddleware` | common/Security | グローバル | GEO_BLOCKED_COUNTRIES 地域ブロック（MaxMind GeoIP2） |
| `WafMiddleware` | common/Security | グローバル(service)+admin | 8 カテゴリ 45+ ルール + リクエスト制限 |
| `SecurityPlugin` | Erikwang2013\Security | グローバル | 31 種の攻撃検知、IP ホワイト/ブラックリスト |
| `RateLimitMiddleware` | common/Security | グローバル | Redis トークンバケットレート制限（per-IP + per-token デュアルバケット） |
| `LocaleMiddleware` | common/I18n | グローバル | Accept-Language 解析 |
| `MetricsMiddleware` | common/Metrics | グローバル | Prometheus HTTP リクエストカウントと遅延 |
| `HashidRequestMiddleware` | common/Hashid | グローバル | hashid リクエストデコード |
| `MaintenanceMiddleware` | common/Security | グローバル | メンテナンスモード + IP ホワイトリスト |
| `InternalTokenMiddleware` | common/Security | ルートグループ | `/health/live|ready|deps` 内部トークン検証 |
| `EncryptionMiddleware` | common/Encryption | ルートグループ | AES-256-GCM 暗号化/復号 |
| `AuthMiddleware` | common/Auth | ルートグループ | JWT Bearer Token 検証 |
| `AdminRoleMiddleware` | common/Auth | ルートグループ | 管理者 RBAC |
| `ConfirmationMiddleware` | common/Confirmation | ルートグループ | パスワード二次確認 |
| `SupplierApiKeyMiddleware` | common/Auth | ルートグループ | sk_xxx API キー SHA256 署名検証 |

---

## 4. データアーキテクチャ

### 4.1 分散主キー：Snowflake

```
64-bit Snowflake ID 结构:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  毫秒级时间戳      数据中心   工作节点    序列号
  纪元: 2024-01-01
  最大寿命: ~69 年
```

すべての Eloquent Model は `creating` イベントで `HasSnowflakeId` Trait により自動生成される。データベースの列タイプは `bigint unsigned`。

### 4.2 ID 混淆：Hashids

```
请求流程:
  Client: GET /api/v1/products/aB3xK7mQ9w
    → HashidRequestMiddleware 解码 → int(1234567890)
      → Controller/Service 使用整数 ID 操作
        → Response::success() / Response::paginated()
          → hashids_encode_ids() 递归编码所有 id/*_id 字段
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 データベース接続

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

### 4.4 暗号化レイヤリング

| 層 | アルゴリズム | 実装 | 用途 |
|------|------|------|------|
| 転送層 | AES-256-GCM | EncryptionMiddleware | API リクエスト/レスポンスボディ暗号化、GCM 認証 |
| フィールド層 | AES-128-ECB | Encryptable Cast | 機密フィールドの自動暗号化/復号（決定性暗号化：同一平文→同一暗号文、ログイン/一意性検証は暗号文の等値クエリ；ECB のみ対応、cipher 変更には再暗号化マイグレーションが必要） |
| ハッシュ層 | bcrypt + SHA256 | JWT / API Key | パスワード/Token の不可逆保存 |
| 主キー層 | Hashids | Response + Middleware | ID の外部混淆 |

### 4.5 キャッシュレイヤリング

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

### 4.6 国際化（i18n）

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

| 機能 | 説明 |
|------|------|
| ヘッダー解析 | `LocaleMiddleware` が `Accept-Language` ヘッダーを自動解析 |
| 言語フォールバック | 未対応言語 → `fallback_locale` |
| 静的翻訳 | 120 エントリ、15 モジュールをカバー（`i18n/{locale}/messages.php`） |
| パラメータ置換 | `I18n::trans('key', ['field' => 'value'])` |
| JSON フィールド | `translateField()` が多言語 JSON 列を処理 |

---

## 5. セキュリティアーキテクチャ

### 5.1 WAF ルール体系（8 カテゴリ 45+ 条）

| カテゴリ | ルール数 | 検知範囲 |
|------|--------|---------|
| SQL インジェクション | 9 | コメント記号、キーワード、16 進エンコード、UNION クエリ、恒真条件、時間ベースブラインド、スタッククエリ |
| XSS | 8 | HTML タグ、Script 変種、13 種のイベントハンドラ、JS プロトコル、エンティティエンコード、Data URI |
| コマンドインジェクション | 5 | パイプ後コマンド、セミコロン後コマンド、$(cmd)、バッククォート、独立コマンドキーワード |
| ファイルインクルード | 4 | パストラバーサル、PHP 疑似プロトコル、絶対パス、Null byte |
| HTTP ヘッダーインジェクション | 2 | CRLF 改行、Host/Cookie/Set-Cookie インジェクション |
| SSRF | 6 | 内網 IP、localhost、クラウド metadata、file:// プロトコル |
| NoSQL インジェクション | 3 | MongoDB オペレーター、Redis 危険コマンド |
| オープンリダイレクト | 2 | redirect_uri 外部 URL、二重エンコードバイパス |

**スキャン範囲:** 値注入系ルール（SQLi/XSS/コマンドインジェクション/ヘッダーインジェクション/SSRF/NoSQL/オープンリダイレクト）は query string、リクエストボディ、User-Agent をスキャン；URL path はファイルインクルード（パストラバーサル）パターンでのみ構造検証を行う。業務パスに select/insert/alert などの通常の語（`/order_item/select` など）が含まれる場合、パス全体をスキャンすると全 CRUD エンドポイントが誤検知されるため、path は値注入マッチングに参加しない。

**リクエスト層の防護:** Content-Type ホワイトリスト、リクエストボディ 10MB 制限、URL 2KB 制限

### 5.2 認証体系

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

## 6. デプロイアーキテクチャ

### 6.1 本番トポロジー

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← プラットフォーム自身のエッジ防御（DDoS/Bot）、
               │  DDoS / Bot     │    製品レベルの CDN モジュール（4 社のプロバイダー、
               └────────┬────────┘    2.4 節参照）とは無関係
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

### 6.2 プロセスモデル

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

## 7. 技術依存

### 7.1 中核フレームワーク

| パッケージ | バージョン | 用途 |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web フレームワーク（常駐メモリマルチプロセス） |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | イベントシステム |
| illuminate/redis | ^10.0 | Redis クライアント |
| webman/redis-queue | ^1.0 | Redis メッセージキュー |

### 7.2 erikwang2013 エコシステムパッケージ

| パッケージ | 用途 |
|----|------|
| snowflake-php | 64 ビット分散主キー |
| hashids | API ID 混淆 |
| encryptable | データベースフィールド暗号化 |
| encryption | 転送暗号化 AES-256-GCM |
| jwt-webman | JWT 認証 |
| webman-scout | Elasticsearch 全文検索 |
| season | 国旗 emoji |
| poster-php | クリック認証コード |

### 7.3 サードパーティ統合

| パッケージ | 用途 |
|----|------|
| stripe/stripe-php | Stripe 決済 |
| twilio/sdk | SMS |
| kreait/firebase-php | FCM プッシュ |
| guzzlehttp/guzzle | HTTP クライアント（Proxmox API など） |
| sentry/sentry | 例外監視 |
| phpoffice/phpspreadsheet | Excel エクスポート |
