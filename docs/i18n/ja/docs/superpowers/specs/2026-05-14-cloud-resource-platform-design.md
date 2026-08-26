# グローバルクラウドリソース取引プラットフォーム — システム設計

## プロジェクト概要

グローバルユーザー向けのクラウドリソース取引プラットフォーム。自営 + 第三者サプライヤーのハイブリッドモデルをサポートします。ユーザーはサーバー、IP、クラウドディスク、ドメインなどのクラウド製品を購入できます。全自動のリソース開通、複数決済チャネル、多通貨、多言語。

### 技術スタック

| レイヤー | 技術 |
|------|------|
| ユーザー端 App | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| 管理バックエンド | webman-admin |
| サーバー側 | PHP webman (モジュラーなモノリス) |
| データベース | MySQL 8.0 (マスター/スレーブ) |
| キャッシュ/キュー | Redis (キャッシュ + Session + キュー) |
| ストレージ | S3/OSS + CDN |
| 監視 | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. モジュール分割 (12 個のコアモジュール)
| モジュール | 責務 |
|------|------|
| **User** | 登録/ログイン(OAuth+メール+電話)、KYC 実名認証、会員レベル、残高口座 |
| **Product** | 商品定義(SKU)、多地域価格設定、在庫管理、カテゴリ、検索、レビュー |
| **Order** | カート、注文、注文ライフサイクル(未払い→支払い済み→開通中→完了→返金)、更新/アップグレード |
| **Payment** | 決済チャネルルーティング、多通貨見積、為替レート、返金、照合 |
| **Provisioning** | 各クラウドベンダー API への接続、リソースの自動作成/更新/破棄 |
| **Domain** | ドメイン照会、登録、移管、更新、DNS 管理 |
| **Supplier** | サプライヤー登録、審査、商品上架、決済、配分 |
| **Monitor** | リソースステータス探知、使用量収集、アラートルール |
| **Ticket** | チケット提出、割り当て、SLA 追跡 |
| **Notification** | メール/SMS/App Push/サイト内メッセージ、多テンプレート多言語 |
| **Report** | 売上レポート、サプライヤー決済レポート、販売トレンド |
| **I18n** | 多言語用語、多通貨レート、多タイムゾーン |

---

## 2. コアデータモデル
### ユーザーセンター (User)

- **users** — ユーザー主テーブル (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — ユーザープロフィール (user_id, avatar, nickname, country)
- **user_kyc** — 実名認証 (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — 残高口座 (user_id, currency, balance, frozen_balance)
- **user_balance_log** — 残高変動記録 (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — ユーザー住所 (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### 商品センター (Product)

- **product_categories** — 商品カテゴリ (id, parent_id, name, icon, sort)
- **products** — 商品主テーブル (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — 地域価格設定 (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — 商品画像 (product_id, url, sort)
- **product_attributes** — カスタム属性 (product_id, key, value)
- **product_reviews** — 商品レビュー (user_id, product_id, order_id, rating, content)
- **regions** — 地域テーブル (id, name, continent, country, city, data_center, status)

### 注文センター (Order)

- **carts** — カート (user_id, sku_id, region_id, quantity, cycle)
- **orders** — 注文主テーブル (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — 注文明細 (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — 注文タイムライン (order_id, status, operator, remark, created_at)
- **order_invoices** — 請求書 (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — 返金票 (order_id, user_id, amount, reason, status, handled_by)

### 決済センター (Payment)

- **payment_channels** — 決済チャネル設定 (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — 取引記録 (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — 照合テーブル (date, channel_id, channel_total, system_total, diff, status)

### リソース開通 (Provisioning)

- **resources** — リソース主テーブル (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — サーバー詳細 (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP 詳細 (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — クラウドディスク詳細 (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — ドメイン詳細 (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — 開通タスク (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — クラウドベンダー API 設定 (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### 物理マシンリソース管理 (Host & IP Pool)

自営物理サーバーは Proxmox VE (コミュニティ版、無料) で仮想マシンを管理し、REST API で VM の作成/管理、IP 割り当て、ディスクマウントを行います。

- **host_machines** — ホストマシン (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP プール (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP 割り当て記録 (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM ディスク明細 (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — ディスク拡張記録 (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### サプライヤー (Supplier)

- **suppliers** — サプライヤー主テーブル (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — サプライヤー商品関連付け (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — 決済明細 (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — 出金記録 (supplier_id, amount, method, account_info, status)

### ドメインサービス (Domain)

- **domain_tlds** — 対応 TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — ドメイン移管 (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS ゾーン (domain_name, user_id, zone_id)
- **dns_records** — DNS レコード (zone_id, type, name, value, ttl, priority)

### チケットと通知 (Ticket & Notification)

- **tickets** — チケット (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — チケットメッセージ (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — 通知記録 (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — 通知テンプレート (code, name, channels, title_template, body_template, variables)

---

## 3. API 設計規範
### バージョン管理

API バージョンは HTTP リクエストヘッダー `X-Api-Version` で指定し、URL パスには含まれません。サーバー側はミドルウェアでバージョンヘッダーを内部ルートに注入します。

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**対応バージョン**: `v1`（デフォルト、リクエストヘッダー欠落時に自動使用）

**バージョン制御メカニズム**: `VersionMiddleware` がすべての `/api/*` と `/admin/api/*` パスの `X-Api-Version` リクエストヘッダーを検証し、欠落時はデフォルト `v1`、サポート外のバージョンは `400` を返します。URL パスにはバージョン番号は含まれません。

**新バージョン追加手順**:
1. `VersionMiddleware::SUPPORTED` 配列にバージョン番号を追加
2. `route.php` に新バージョンのルートグループを登録
3. コントローラは `$request->properties['api_version']` でバージョン番号を取得して差分処理

### RESTful ルート

```
統一プレフィックス: /api
管理バックエンド: /admin/api
```

**ルートグループとミドルウェアマトリックス:**

| ルートグループ | ミドルウェア | エンドポイント例 |
|--------|--------|---------|
| 公開 (プレフィックスなし) | グローバルミドルウェアチェーン | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | グローバル + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (ユーザー) | グローバル + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (機密) | グローバル + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | グローバル + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (機密) | グローバル + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### 統一レスポンス形式

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### 認証スキーム

| 端 | 方式 |
|----|------|
| ユーザー端 | JWT (access_token 2h + refresh_token 30d) + TOTP 二段階認証 + リカバリーコード |
| 管理端 | JWT (access_token 2h + refresh_token 7d) |
| サプライヤー API | API Key (sk_ プレフィックス、SHA256 ハッシュで保存、作成時のみ 1 回表示) |
| クラウドベンダーコールバック | 署名検証 (HMAC-SHA256) |

**実装済みの認証機能**:
- メール登録 + メール検証リンク
- 電話番号登録 + Twilio SMS 認証コード（60s クールダウン + IP レート制限 5 回/時間）
- Google OAuth ログイン / Apple Sign In
- パスワード忘れ（メール認証コード + Redis 10min TTL）
- TOTP 二段階認証（QR コードスキャン設定、リカバリーコードによるフォールバック）
- アクティブセッション管理（ログイン端末の表示/失効、client_platform 情報を含む）
- アカウント削除 GDPR（パスワード確認 + ソフト削除 + 全 token 失効）
- ログイン異常アラート（新規 IP ログイン時のメール通知）
- ログインロック（5 回失敗で 15 分ロック）

**ユーザー認証フロー:**

```
注册流程                             登录流程
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(点击位置)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 扫描]                         → [WAF 扫描]
   → [限流: 3 req/min]                  → [限流: 5 req/min]
   → [密码 bcrypt(cost=12)]             → [Hash::check()]
   → [设备指纹: sha256(UA+IP)]           → [设备指纹: sha256(UA+IP)]
   → [client_platform 记录]              → [client_platform 记录]
   → User::create()                    → [失败 5 次 → 锁 15min]
   → RefreshToken::create()            → [新 IP 检测 → 邮件告警]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (验证邮件)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 授权 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. 验证 Google token
                                      5. 新建或查找用户
                                      6. 签发 token（含 client_platform）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 两步验证                          会话管理
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 成功
   或 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 密码确认 + 软删除 + 全部 token 撤销
登录锁定机制
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### 多言語スキーム

- リクエストヘッダー: Accept-Language: zh-CN / en-US / ja-JP
- JSON カラムで多言語文言を保存: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n ファイルで静的テキストを管理し、フロントエンドとバックエンドに各 1 セット

---

## 4. セキュリティ防護体系
### レイヤー別防護モデル

```
┌─────────────────────────────────────────────────────┐
│ 第一层: 网络边界防护                                    │
│   DDoS清洗 / WAF / IP黑白名单 / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ 第二层: 传输与应用防护                                  │
│   HTTPS+TLS1.3 / CSP / CORS / JWT鉴权 / 限流          │
├─────────────────────────────────────────────────────┤
│ 第三层: 数据与存储安全                                  │
│   加密存储 / 脱敏 / 审计日志 / 备份                     │
├─────────────────────────────────────────────────────┤
│ 第四层: 虚拟化与资源隔离                                 │
│   Proxmox安全加固 / VM间隔离 / 网络隔离                 │
├─────────────────────────────────────────────────────┤
│ 第五层: 运营与风控                                     │
│   操作审计 / 异常检测 / 告警 / 应急响应                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 ネットワーク境界防護

#### DDoS 防護

```
用户请求 → CDN (Cloudflare / 阿里云CDN)
              │
              ├── JS质询 / 验证码 (可疑流量)
              ├── 速率限制 (每IP每秒请求数)
              ├── 区域封禁 (阻断指定国家/地区)
              │
              ▼
          源站 (Nginx + webman)
```

| レイヤー | 対策 | 説明 |
|------|------|------|
| CDN レイヤー | 自動 DDoS クリーニング | Cloudflare 無料プランでも L3/L4 防護をサポート |
| CDN レイヤー | Bot Management | 悪意のあるクローラー/注文水増しスクリプトの識別とブロック |
| Nginx レイヤー | limit_req_zone | IP ごとに 10 req/s、超過は 429 を返す |
| Nginx レイヤー | limit_conn | IP ごとに最大 20 同時接続 |
| webman レイヤー | トークンバケット限流ミドルウェア | ユーザー/API 粒度での精密なレート制限 |

#### WAF ルール (webman ミドルウェア)

WAF ミドルウェアは 8 カテゴリの正規表現ルールグループでリクエストをスキャンし、ルールは `config/security.php` でホットリロードでき、再起動は不要です。スキャン範囲はリクエストボディ JSON、URL パス+クエリ文字列、User-Agent、生のリクエストボディ（JSON エンコードエスケープ対策）をカバーします。

**8 カテゴリの検出ルール（45+ 条）:**

| カテゴリ | カバー範囲 |
|------|---------|
| SQL インジェクション | シングルクォート/コメント記号、SQL キーワード、16 進エンコード、UNION クエリ変形、永真条件(`' OR '1'='1`)、時間ベースブラインド(`sleep`/`benchmark`)、スタッククエリ、複数行コメントバイパス |
| XSS | HTML タグ(エンコード変形含む)、Script タグと変種、13 種の JS イベントハンドラ、JS グローバルオブジェクト/危険関数、`javascript:` 擬似プロトコル、HTML エンティティエンコード、Data URI インジェクション、インラインイベント属性 |
| コマンドインジェクション | パイプに続くコマンド(`\| cat`)、セミコロンに続くコマンド(`; whoami`)、`$(cmd)` とバッククォートのコマンド置換、単独コマンドキーワード |
| ファイルインクルージョン | パストラバーサル(多重エンコード)、PHP 擬似プロトコル(`php://`/`data://`/`phar://`)、絶対パス探知(`/etc/`/`C:\`)、Null byte インジェクション |
| HTTP ヘッダーインジェクション | CRLF 改行インジェクション(`%0d%0a`/`\r\n`)、Host/Cookie/Set-Cookie ヘッダーインジェクション |
| **SSRF** | 内網 IPv4 アドレス(127.x/10.x/172.16-31.x/192.168.x)、localhost エイリアス、クラウド metadata エンドポイント(169.254.169.254)、file:// プロトコル |
| **NoSQL インジェクション** | MongoDB 演算子($where/$gt/$regex/$or など)、$where JS インジェクション、Redis 危険コマンド(FLUSHALL/CONFIG SET/SHUTDOWN) |
| **オープンリダイレクト** | redirect_uri/return_url/next/callback などのパラメータの外部 URL 検出、二重エンコードバイパス |

**リクエストレイヤー防護:**

| 防護項目 | 対策 |
|--------|------|
| リクエストボディサイズ制限 | 最大 10MB（超過は 413 を返す） |
| URL 長制限 | 最大 2KB（超過は 414 を返す、ReDoS 対策） |
| Content-Type ホワイトリスト | application/json、multipart/form-data、application/x-www-form-urlencoded のみ許可 |

**WAF 検出フロー:**

```
请求进入
  │
  ▼
1. 获取待扫描文本
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 请求体
   │     └── false → serialize() 回退
   ├── mb_substr(path + queryString, 0, 2048)                # URL（防 ReDoS 截断）
   ├── User-Agent 头                                          # UA
   └── file_get_contents('php://input')                      # 原始体（防 JSON 编码逃逸）
  │
  ▼
2. 加载规则（从 config/security.php）
   ├── security.waf.sqli_patterns               (9 条)
   ├── security.waf.xss_patterns                (8 条)
   ├── security.waf.cmd_injection_patterns      (5 条)
   ├── security.waf.file_inclusion_patterns     (4 条)
   ├── security.waf.header_injection_patterns   (2 条)
   ├── security.waf.ssrf_patterns               (6 条)
   ├── security.waf.nosql_injection_patterns    (3 条)
   └── security.waf.open_redirect_patterns      (2 条)
   → array_merge() + array_unique()
  │
  ▼
3. 逐条匹配
   foreach patterns as pattern:
     match($pattern, $input) ───→ 命中 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 命中 → 返回 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ 命中 →
     match($pattern, $raw)   ───→ 命中 →
  │
  ▼
4. match() 严格检查
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 命中 ✓
   ├── $result === 0    → 未命中（安全放行）
   └── $result === false → 模式错误 → error_log() → 作为未命中处理
  │
  ▼
5. 全部未命中 → $next($request) 放行到下一中间件
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### IP ブラックリスト/ホワイトリスト

```
黑名单:
- 已知恶意 IP 库 (定期同步 AbuseIPDB)
- 频繁触发 WAF 规则的 IP (自动加入，Redis TTL 24h)
- 暴力破解登录的 IP (5次失败 → 锁定 30min)

白名单:
- Proxmox 宿主机 IP
- 云厂商回调 IP 段
- 支付网关 webhook IP 段
- 管理员办公网络 IP (可选)
```

#### Geo-Blocking

```php
// GeoIP2 库 (MaxMind)
$country = geoip($request->getRealIp());

// 可配置的阻断列表
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 転送とアプリケーションセキュリティ

#### グローバルミドルウェア実行チェーン

すべての HTTP リクエストは次の順序でミドルウェアを通過し、各ミドルウェアは独立してテスト可能です:

```
请求 → VersionMiddleware        # X-Api-Version 校验（缺失默认 v1，无效返回 400）
     → CorsMiddleware            # CORS 跨域响应头
     → ClientPlatformMiddleware  # X-Client-Platform 识别（8 种平台），注入 $request->properties
     → WafMiddleware             # 8 类 45+ 规则安全扫描（SQLi/XSS/命令注入/文件包含/头注入/SSRF/NoSQL/开放重定向）
     → LocaleMiddleware          # Accept-Language 解析，设置区域
     → HashidRequestMiddleware   # 请求参数 hashid → 真实 ID 解码
     → MaintenanceMiddleware     # 维护模式（IP 白名单放行）
     ↓
  [路由中间件—按路由组附加]
     → EncryptionMiddleware      # AES-256-GCM 请求/响应体加密
     → Captcha                   # 点击验证码校验（登录/注册前）
     → AuthMiddleware            # JWT Bearer Token 验证 + 角色注入
     → AdminRoleMiddleware       # 管理员 RBAC 权限检查
     → ConfirmationMiddleware    # 敏感操作二次密码确认（5 次失败锁 15min）
     ↓
     控制器
```

#### 各ミドルウェアの責務

| ミドルウェア | 登録方式 | 責務 |
|--------|---------|------|
| `VersionMiddleware` | グローバル | `X-Api-Version` リクエストヘッダーを検証、欠落時はデフォルト `v1`、サポート外のバージョンは `400` を返す |
| `CorsMiddleware` | グローバル | OPTIONS プリフライトを処理、Origin を `Access-Control-Allow-Origin` に反映 |
| `ClientPlatformMiddleware` | グローバル | `X-Client-Platform` リクエストヘッダーを検証し、クライアント OS プラットフォーム（iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web）を識別、`$request->properties['client_platform']` に注入 |
| `WafMiddleware` | グローバル(service) + admin インスタンス | 8 カテゴリ 45+ ルール + リクエストサイズ制限 + Content-Type 検証、ブロック後は監査ログを記録 |
| `LocaleMiddleware` | グローバル | `Accept-Language` ヘッダーを解析し、多言語リージョンを設定 |
| `HashidRequestMiddleware` | グローバル | リクエスト内の hashid 文字列を実際の整数 ID に自動デコード |
| `MaintenanceMiddleware` | グローバル | `MAINTENANCE_MODE` 環境変数をチェック、ホワイトリスト IP は許可 |
| `EncryptionMiddleware` | ルートグループ (/api/auth, /api, /admin/api) | AES-256-GCM リクエスト/レスポンスボディ暗号化、`X-Encrypted: 1` ヘッダーでトリガー |
| `AuthMiddleware` | ルートグループ (/api, /admin/api) | JWT HS256 access_token 検証、`$request->userId` と `$request->userRole` を注入 |
| `AdminRoleMiddleware` | ルートグループ (/admin/api) | 管理者 RBAC 権限チェック |
| `ConfirmationMiddleware` | ルートグループ (機密操作) | パスワード二次確認、Redis 失敗カウンター、5 回で 15 分ロック |

#### ClientPlatform ミドルウェア詳細

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**データフロー**: ミドルウェア注入 → `AuditLogger` が自動記録 → `AuthService::issueTokens()` が `refresh_tokens` に書き込み → `GET /api/user/sessions` がプラットフォーム情報を返す

#### HTTPS 強制

```nginx
# Nginx 配置
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### JWT セキュリティ強化

```
- access_token 有効期間 2h、refresh_token 有効期間 30d
- 鍵は RSA256 (非対称)、定期的にローテーション (90日)
- jti (JWT ID) を Redis に保存し能動的失効を実現
- refresh_token はデバイスフィンガープリントにバインド (User-Agent + IP セグメント)
- refresh_token 再発行時は旧 token を即時失効 (rotation)
- 機密操作 (支払い/リソース破棄) は二次検証が必要

设备指纹:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 表记录此指纹，换发时校验
```

#### パスワードポリシー

```
- bcrypt 暗号化、cost factor = 12
- 最小 8 文字、大文字小文字 + 数字の両方を含む必要がある
- 登録/ログイン連続失敗 5 回 → アカウント 15 分ロック
- パスワード変更後、発行済みの全 token が即時失効
- TOTP 二段階認証に対応 (ユーザーが任意で有効化)
```

#### CORS ポリシー

```php
// webman 中间件
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### ファイルアップロードセキュリティ

```
- 拡張子のホワイトリスト検証 (許可: jpg, jpeg, png, pdf, gif のみ)
- ファイル MIME タイプの検証 (Content-Type の偽装を許可しない)
- ファイルサイズ制限: アバター 2MB、KYC 書類 5MB、添付 10MB
- アップロード後のリネーム: {uuid}.{ext}、元のファイル名は保持しない
- 画像の二次処理: GD/Imagick で EXIF + メタデータを除去
- 保存パスは web からアクセスできないディレクトリ、PHP プロキシ経由で読み取り
- ウイルススキャン: ClamAV (KYC 書類/ユーザーアップロードファイル)
```

---

### 4.3 データとストレージのセキュリティ

#### 機密データの暗号化

```
暗号化アルゴリズム: AES-256-GCM (認証付き暗号化、改ざん防止)
鍵管理: マスターキーは環境変数に保存、各フィールドは独立した派生鍵を使用

暗号化して保存する必要があるフィールド:
| データ型 | フィールド | 暗号化方式 |
|----------|------|----------|
| パスワード | users.password_hash | bcrypt (一方向) |
| 決済キー | payment_channels.api_key | AES-256-GCM |
| クラウドベンダーキー | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 書類番号 | user_kyc.id_number | AES-256-GCM |
| 決済口座 | 出金口座 | AES-256-GCM |
| ログインパスワード(VNC) | resource_servers.login_password | AES-256-GCM |

鍵派生:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### ログ秘匿化

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### データベースセキュリティ

```
- MySQL は prepared statement を使用 (Eloquent が自動処理)
- データベースアクセスアカウントの最小権限原則:
  - app_user: SELECT, INSERT, UPDATE, DELETE (DDL なし)
  - migration_user: DDL 権限 (マイグレーション時のみ使用、IP 制限)
  - read_user: SELECT 読み取り専用 (レポート/データ分析用)
- 接続は SSL/TLS を使用 (PHP PDO SSL options)
- データベースポートは公網に開放しない (内網からのみアクセス可能)
- 定期バックアップ: フルバックアップ 1日、binlog リアルタイム同期
```

#### データバックアップと復旧

```
バックアップ戦略:
- MySQL: 毎日フル + binlog リアルタイム増分
- Redis: RDB 毎時間 + AOF リアルタイム永続化
- ユーザーアップロードファイル: S3/OSS 自動マルチレプリカ + クロスリージョン複製
- Proxmox VM スナップショット: 毎週 1 回 (4 週間保持)
- バックアップ暗号化: AES-256 暗号化後に保存

復旧演習:
- 四半期に 1 回ディザスタリカバリ演習を実施
- 復旧時間目標 (RTO): < 4 時間
- 復旧ポイント目標 (RPO): < 1 時間
```

---

### 4.4 仮想化とリソース分離

#### Proxmox セキュリティ強化

```
1. API アクセス制御:
   - Proxmox API は内網 IP のみをリッスン (公網にバインドしない)
   - Token 権限の最小化: 各ロールには必要な権限のみ付与
   - API ポート (8006) は PHP アプリケーションサーバー IP のみアクセス許可 (iptables)

2. SSH 強化:
   - パスワードログイン無効化、鍵認証のみ許可
   - root ログイン無効化、専用管理アカウントを使用
   - SSH ポートを非標準ポートに変更 (スキャン減少)
   - Fail2ban: 5 回失敗で 1 時間ロック

3. システム更新:
   - Proxmox セキュリティ更新メールリストを購読
   - 定期的に apt update && apt upgrade
   - カーネル livepatch (Canonical Livepatch Service)

4. ファイアウォール (iptables/nftables):
   - デフォルトで全インバウンド拒否
   - 開放は: 8006 (アプリケーションサーバー IP のみ)、SSH ポート (管理 IP のみ)
   - VM ブリッジとホストマシン管理ネットワークの分離
```

#### VM 間分離

```
- 各 VM は独立した仮想ブリッジ VLAN を使用
- VM 間通信を禁止 (Proxmox ファイアウォールルール + VLAN 分離)
- ユーザーは公網 IP 経由で自分の VM にのみアクセス可能
- VM リソース制限 (cgroup): 単一 VM によるホストリソース枯渇を防止
  - CPU limit: 購入したコア数の上限
  - RAM limit: 購入した容量の上限
  - Disk IOPS limit: ディスク競合を防止
  - Network bandwidth limit: 購入した帯域幅の上限
```

#### IP 割り当てセキュリティ

```
- IP 割り当て記録を完全監査 (誰が、いつ、どの IP を割り当てたか)
- IP 解放後のクールダウン 24h (IP が他の人に即時割り当てられて誤用されるのを防止)
- IP ブラックリスト: 苦情/乱用があった IP を割り当て不可にマーク
- IP 使用監視: 割り当てた IP が正常に使用中かを定期的にチェック
```

---

### 4.5 決済セキュリティ

```
1. PCI DSS 準拠:
   - クレジットカードデータは自社サーバーを経由しない (Stripe Elements / Checkout)
   - card_token は Stripe フロントエンドで直接生成、バックエンドは token のみ受信
   - ログ/データベースに CVV/完全なカード番号を保存しない

2. 暗号通貨:
   - 受取秘密鍵はコールドストレージ (オフライン署名)
   - ホットウォレットには日常の回転額のみ保持
   - 受取アドレス生成後にチェックサムを検証
   - 大口取引 ( > $10000) は手動審査後に確認

3. 決済詐欺防止:
   - 同一ユーザー/IP の短時間での高頻度決済 → 風控凍結
   - 新規登録ユーザーの大口決済 → 手動審査
   - 決済金額の異常 (商品価格と不一致) → ブロック
   - 返金率が高すぎるユーザー → 風控マーク

4. コールバック署名検証:
   - Stripe: webhook signature を検証 (stripe-signature header)
   - Coinbase: webhook signature を検証 (X-CC-Webhook-Signature header)
   - 支付宝 (Alipay): notify_id を検証し、支付宝サーバーに二次確認
   - すべてのコールバック: IP が既知の決済ゲートウェイ IP セグメントかを検証
```

#### 返金セキュリティ

```
- 返金は二段階承認が必要 (カスタマーサポートが発起 → 管理者が確認)
- 返金前の検証: 注文ステータス、返金期限、返金回数
- 返金金額は元の注文実支払金額を超えられない
- 元路退回: 決済チャネルの返金 API + 残高返金
- 返金ミューテックスロック (Redis): 並行する重複返金を防止
```

---

### 4.6 アクセス制御と権限

#### RBAC モデル

```
角色层级:
  super_admin    (超级管理员 — 全部权限)
  admin          (管理员 — 除系统配置外全部)
  finance        (财务 — 支付/对账/退款/结算)
  support        (客服 — 用户/订单/工单管理)
  supplier       (供应商 — 自己的商品/订单/结算)
  user           (普通用户 — 自己的资源/订单/工单)

权限定义:
  {module}.{action}
  例: order.view, order.create, order.refund, resource.destroy

权限检查中间件:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### API レート制限

```php
// webman 限流中间件 (Redis 令牌桶)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### サプライヤーデータ分離

```
データ分離の原則:
- サプライヤーは自分のリソースのみ照会・操作できる
- supplier_id に関わるすべてのクエリに WHERE supplier_id = auth()->supplier_id を自動追加

実装方式:
  // 全局 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // 在 Product/Order 等 Model 上注册
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 操作監査

```
監査ログの記録内容:
- 操作者 ID、IP、User-Agent
- 操作時間
- 操作モジュール (どのメニュー/API か)
- 操作タイプ: 作成/変更/削除/エクスポート/承認
- 操作対象: どのリソースのどのフィールドか
- 操作前の値 / 操作後の値 (フィールドレベル変更)
- 操作結果: 成功/失敗
- リクエスト ID (全チェーン追跡)

記録範囲:
- すべての管理端操作 (100% 記録)
- ユーザー端の機密操作: 支払い/リソース破棄/KYC 提出/パスワード変更 (100% 記録)
- ログイン/ログアウト (100% 記録)
- API Key の作成/失効 (100% 記録)

保存と保持:
- 監査ログは独立データベース (audit_db) に書き込み、アプリケーション DB と分離
- 最低 1 年保持、金融関連は 3 年保持
- コンプライアンス審査用に CSV/JSON エクスポート対応

監査ログミドルウェア:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 風控ルール

```
リアルタイム風控エンジン:

规则 1: 新账号异常行为
  条件: 注册时间 < 24h AND (支付总额 > $500 OR 创建工单 > 5)
  动作: 标记账号为"观察中"，通知风控管理员

规则 2: 批量注册检测
  条件: 同一 IP 24h 内注册 > 3 个账号
  动作: 拒绝新注册，冻结该 IP 下新账号

规则 3: 支付异常
  条件: 同一用户 1h 内支付失败 > 5 次
  动作: 冻结支付功能 2h，生成风控工单

规则 4: 退款滥用
  条件: 同一用户 30 天内退款 > 3 笔 OR 退款率 > 20%
  动作: 限制该账号退款权限，新订单标记风控审查

规则 5: API 滥用
  条件: 单 token 1h 内 API 调用 > 10000 次
  动作: 该 token 降级 (降低限流阈值)，通知管理员

规则 6: 资源滥用
  条件: VM 被投诉 spam/DDoS/挖矿 (接收 Abuse 通知)
  动作: 自动关机，冻结资源，生成高优先级工单

風控アクション:
- マーク (flag): 記録のみ、利用には影響しない
- 降格 (throttle): レート制限しきい値を引き下げ
- 凍結 (freeze): 特定機能を一時無効化
- 禁止 (ban): アカウントを永久禁止
```

---

### 4.9 緊急対応

```
セキュリティイベントのレベル分け:

P0 (緊急) — データ漏えい、資金損失、プラットフォーム停止
  → 即座に CTO + セキュリティチームへ通知
  → 30 分以内に緊急対応を開始
  → 上流の影響を受けるサービスを停止し、証拠を保存
  → 修正後 24h 以内にイベントレポートを公開

P1 (重大) — 単一アカウントの乗っ取り、決済詐欺、WAF トリガー異常上昇
  → セキュリティ責任者に通知
  → 2h 以内に処理
  → 影響を受けたアカウント/リソースを凍結

P2 (一般) — 脆弱性スキャンで中低リスク脆弱性、異常ログインアラート
  → チケットシステムに登録
  → 次のイテレーションで修正

緊急連絡:
- P0/P1 アラート発火後、自動通知 (メール + SMS + 電話)
- webman ヘルスチェックエンドポイント: GET /health (200 またはアラートを返す)
- 当番表: 7×24 輪番、少なくとも 2 人バックアップ
```

---

## 5. リソース開通エンジン
### Provider プラグインアーキテクチャ

各クラウド製品タイプ × クラウドベンダーの組み合わせで、統一インターフェースを実装:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory は (product_type, provider) に基づいて具体的な実装にルーティング:
- ProxmoxProvider (自営物理機: サーバー/データディスク/IP)
- AwsServerProvider / AliyunServerProvider (第三者クラウドサーバー)
- GcpIpProvider (第三者 IP)
- AzureDiskProvider (第三者クラウドディスク)
- NamecheapDomainProvider / GoDaddyDomainProvider (ドメイン)

### 非同期タスク保証

- Provisioning Worker が provision_tasks テーブルをポーリング
- provider 別に並行数を制御 (各 provider 最大 5 並行)
- リトライ戦略: 1min → 5min → 15min → 1h → 6h → 24h (最大 6 回)
- リトライ不可の失敗 → アラート + チケット自動生成

### 注文からリソース開通までの完全なチェーン

```
用户下单                               支付                             资源开通
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 事件
   → addToCart(sku, region, qty)          → 密码二次确认 (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          选择支付通道                   10. 每个 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. 应用优惠券                               createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. 前端 confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe webhook 回调            12. Provider->create()
   → 获取可用支付通道                       → 验签 + 幂等检查                   ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → 触发 OrderPaid 事件               │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                                                              │  startVM()
                                        重试策略 (失败时)                      ├→ 创建 Resource 记录
                                        ────────────────                     └→ 更新 host_machine
                                        1min → 5min → 15min                      已分配资源量
                                        → 1h → 6h → 24h
                                        (6 次后标记失败 + 告警)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        退款流程                                ::send('resource_ready')
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### 自営物理機ソリューション: Proxmox VE (コミュニティ版)

自営サーバーは Proxmox VE (オープンソース無料、AGPL v3) を採用し、PHP が HTTP で Proxmox REST API を呼び出して KVM 仮想マシンのライフサイクルとリソース割り当てを管理します。

アーキテクチャ:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### ProxmoxApi クライアントラッパー

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### リソース操作

**VM の作成 (サーバー):**
1. HostSelector がリソースが十分なホストマシンを選択 (cpu/ram/disk の余量 + 負荷分散でソート)
2. そのホストマシンの ip_pool から IP を 1 つ割り当て
3. ProxmoxApi.post("/nodes/{node}/qemu") で VM を作成 (vmid、name、cores、memory、net0、ipconfig0 を設定)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") でシステムディスクをマウント (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") で VM を起動
6. host_machine.specs の割り当て量を更新 (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**CPU アップグレード (オンライン):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**メモリアップグレード (オンライン):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**システムディスク拡張:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**データディスクの単独作成:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**IP の単独作成:**
IP プールから割り当て → Proxmox API で仮想 NIC を追加し IP を設定、または独立リソースとして保持し既存 VM の追加 NIC に割り当て。

**VM の破棄:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### ホストマシン選択戦略

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### リソース分割操作のまとめ

| 操作 | 実装方式 | ホット操作 |
|------|----------|--------|
| VM の作成 (CPU+メモリ+システムディスク+IP) | Proxmox create qemu | — |
| CPU の単独アップグレード | PUT config cores | オンライン |
| メモリの単独アップグレード | PUT config memory | オンライン |
| システムディスクの拡張 | PUT resize disk | オンライン (VM の対応が必要) |
| データディスクの単独作成 | POST config でディスク追加 | オンライン |
| IP の単独作成 | IP プールから割り当て + VM に NIC 追加 | オンライン |

### リソースライフサイクル

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

更新: active → (renew) → active (expired_at を延長)
アップグレード: active → (upgrade) → upgrading → active

### リソースソース

| ソース | 仮想化/API | 製品タイプ | 説明 |
|------|-----------|----------|------|
| 自営物理機 | Proxmox VE (コミュニティ版) | サーバー、データディスク、IP | 自社データセンターでホスティング、PHP から Proxmox API を呼び出し |
| 第三者クラウドベンダー | AWS/GCP/阿里云/华为云/Azure SDK | サーバー、IP、クラウドディスク | 第三者クラウドリソースの転売 |
| ドメインレジストラ | Namecheap/GoDaddy/阿里云万网 API | ドメイン登録/移管 | ドメインサービス |

### 初期待ち受け

| 地域 | サーバー | IP | クラウドディスク | ドメイン |
|------|--------|----|------|------|
| アジア太平洋 | 阿里云、华为云、AWS | 阿里云、GCP | 阿里云、华为云 | 阿里云万网、Namecheap |
| ヨーロッパ | AWS、GCP、Hetzner | GCP、OVH | AWS、GCP | Namecheap、Gandi |
| 北米 | AWS、GCP、Azure | AWS、GCP | AWS、Azure | GoDaddy、Namecheap |

---

## 6. 決済システム
### マルチチャネルルーティング

PaymentRouter がユーザーの通貨プリファレンスに基づいて利用可能なチャネルを照会し、各チャネルの実支払金額 (チャネル手数料込み) を計算して、決済オプションリストを返します。

### 決済フロー (Stripe)

```
用户端 (Flutter)               服务端 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. 选择 Stripe 支付
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. 创建 payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 用户确认支付
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  验签 (stripe-signature)
                                  幂等检查 (transaction_no)
                               8. 更新 transaction=success
                               9. 触发 OrderPaid 事件
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 支付成功页面               ← 返回订单状态
```

### 暗号通貨決済

1. ユーザーが通貨を選択 (例 USDT-TRC20)
2. バックエンドが Coinbase Commerce / BitPay API で受取アドレスを生成
3. Worker が 30 秒ごとにブロックチェーン確認 (または webhook)
4. 着金確認 → OrderPaid イベントをトリガー

### 為替レートと多通貨

- 為替レートソースは exchangerate-api から定期的に取得して Redis に保存
- 商品価格は USD 基準、他の通貨はリアルタイム換算
- 注文時に為替レートをロック、返金時は元のレートで返金

### 決済チャネルの可視性制御

payment_channels テーブルのフィールド:
- is_visible: ユーザー端に表示するか
- visible_regions: 表示地域の制限、空は全部
- min_amount / max_amount: 注文金額区間の制限

### 照合

毎日早朝に各チャネルの決済レポートを取得し、システムの transaction と 1 件ずつ照合。差異 > $0.01 でアラート。

### 返金ポリシー

- サーバー/VPS: 購入後 72h 以内は全額返金
- ドメイン: 登録後 5 日以内は返金可能 (ICANN 規範)
- IP: 購入後は返金不可
- クラウドディスク: サーバーと同ポリシー
- 特別プロモーション商品: 返金不可

返金フロー: ユーザー申請 → Ticket 生成 → カスタマーサポート審査 → admin 確認 → provider.destroy() → payment.refund() → 元路退回

---

## 7. クライアントページ構造
### Flutter / HarmonyOS ユーザー端

- **認証**: ログイン/登録 (メール+パスワード、Google OAuth、Apple ID、電話番号)、パスワード忘れ、二段階認証
- **ホーム**: 地域セレクタ、商品カテゴリ入口、Banner/プロモーション、おすすめ商品
- **商品**: リスト (多条件フィルタ)、詳細 (設定/地域/価格計算機)、レビュー
- **ショッピング&決済**: カート、注文確認 (決済方法/請求先住所/残高/プロモーションコード)、レジ、決済結果
- **マイリソース**: リソースリスト (ステータスでフィルタ)、詳細操作 (再起動/シャットダウン/更新/アップグレード/破棄)、コンソール SSO、使用量グラフ
- **注文**: リスト (未払い/支払い済み/完了/返金済み)、詳細、請求書
- **チケット**: リスト、新規作成、会話
- **個人センター**: プロフィール/KYC、残高&チャージ、通知、住所管理、言語/通貨/セキュリティ設定
- **共通**: ヘルプセンター、利用規約、アバウト

### webman-admin 管理バックエンド

- **ダッシュボード**: 概要 + トレンドチャート
- **ユーザー管理**: リスト/詳細/KYC 審査
- **商品管理**: カテゴリ/リスト/価格設定(SKU×地域)/在庫/レビュー
- **注文管理**: リスト/詳細/返金審査/請求書
- **決済管理**: チャネル設定/取引記録/照合レポート
- **リソース管理**: リスト/開通タスク監視/クラウドベンダー API 設定
- **サプライヤー管理**: 登録審査/リスト/商品割り当て/決済/出金
- **チケット管理**: キュー/マイチケット/SLA 監視
- **ドメイン管理**: TLD 価格/レジストラ API/移管管理
- **メッセージ通知**: テンプレート管理/送信記録
- **システム設定**: 管理者&ロール/操作ログ/多言語/為替レート/地域/システムパラメータ
- **レポート**: 売上/サプライヤー決済/製品販売分析/地域分析

---

## 8. メッセージ通知システム
### 4 チャネル

Email (SMTP/SendGrid) / SMS (Twilio/阿里短信) / Push (FCM/HMS) / サイト内メッセージ

### フロー

イベントトリガー → Notification Dispatcher → テンプレート照合 (イベントコード+言語プリファレンス) → ユーザープリファレンスに従って各チャネルに配信 → Redis Queue で非同期送信

### 通知タイプ

登録認証コード、注文支払い成功、リソース開通完了、リソース期限リマインダー (7d/3d/1d)、チケット返信、返金完了、セキュリティアラート、プロモーション

### 失敗リトライ

3 回のバックオフで、webman redis-queue で管理。

---

## 9. サプライヤーシステム
### 登録フロー

登録 → 会社情報+連絡先+決済方法を提出 → 管理者審査 → 承認後に商品を上架 → admin が商品を審査 → ユーザー購入 → 自動配分 → サプライヤーが出金申請 → admin が振込

### 権限分離

サプライヤーは自分の商品/注文/決済明細/チケット/出金記録のみ閲覧できます。プラットフォーム売上、他サプライヤーデータ、決済チャネル設定は閲覧できません。

### 配分ルール

- 自営商品: commission_rate = 100% (全てプラットフォーム)
- 第三者商品: commission_rate = 5%~20% (プラットフォーム抽成)
- 決済計算式: 注文商品金額 - プラットフォーム抽成 - チャネル手数料 = サプライヤー受取額
- 決済周期: 週次 / 月次

### サプライヤー完全業務フロー

```
供应商入驻                              管理员审批
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 审核供应商信息
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 确认密码
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 成功
商品上架
────────
POST /supplier/products               管理员审核
  → {product_id, commission_rate}        → 关联供应商商品 + 设置佣金比例
  ← {supplier_product}                    → 商品状态: published

用户下单 ──→ 支付完成 ──→ 资源开通 ──→ 订单完成

定时结算 (每周一 04:17)                   提现
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 统计周期内已完成订单                    → 密码二次确认 (ConfirmationMiddleware)
  → 计算 total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → 检查可提现余额
  → 创建 SupplierSettlement                 → 创建 SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← 成功

管理员打款                              管理员 API Key 管理
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → 生成 sk_xxx (SHA256 存储)
  → 确认密码                               ← {api_key} (仅显示一次)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. 監視と運用
### リソース監視

- 収集メトリクス: CPU/メモリ/ディスク/帯域幅使用率、IP 接続性、クラウドディスク IOPS、DNS 解決、SSL 証明書期限
- 収集方式: Agent 報告 / SNMP (自前) + クラウドベンダー監視 API (第三者) + WHOIS/DNS ポーリング (ドメイン)
- 収集周期: 5 分、Prometheus + VictoriaMetrics に保存

### アラートルール

| アラートイベント | 重大度 | トリガー条件 |
|----------|--------|----------|
| サーバーダウン | 重大 | Ping 連続 3 回不通 |
| CPU/メモリ > 90% | 情報 | 10 分継続 |
| ディスク > 90% | 警告 | 5 分継続 |
| 帯域幅 > 80% | 情報 | 30 分継続 |
| SSL 証明書 < 30 日で期限切れ | 警告 | 毎日チェック |
| ドメイン < 30 日で期限切れ | 警告 | 毎日チェック |
| 開通タスク失敗 | 重大 | 連続 2 回失敗 |
| 決済照合差異 | 重大 | 単筆 > $0.01 |

---

## 11. デプロイアーキテクチャ
### 本番環境

- アプリケーションサーバー × 2: webman (マルチプロセス) + Nginx + Supervisor
- データベース: MySQL 8.0 マスター/スレーブ (1 マスター 2 スレーブ) + Redis Cluster
- キュー: webman redis-queue (決済コールバック/通知/開通タスク)
- 定期タスク: Crontab (照合/決済/ドメインチェック/更新リマインダー)
- ストレージ: S3/OSS + CDN
- ログ監視: ELK/Loki + Prometheus + Grafana + Sentry

### ディレクトリ構造

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 客户端
│   └── harmonyos/         # HarmonyOS 客户端 (ArkTS)
├── service/               # webman 服务端
│   ├── app/
│   │   ├── controller/    # 控制器 (按模块)
│   │   ├── service/       # 业务逻辑 (按模块)
│   │   ├── model/         # 数据模型
│   │   ├── middleware/     # 中间件
│   │   ├── event/         # 事件定义
│   │   ├── listener/      # 事件监听器
│   │   ├── queue/         # 队列任务
│   │   ├── provider/      # 云厂商适配器
│   │   └── cron/          # 定时任务
│   ├── common/            # 公共库 (auth/payment/i18n/notification/helper)
│   ├── config/            # 配置文件
│   ├── database/
│   │   └── migrations/    # 数据库迁移
│   └── storage/           # 日志/缓存/上传
├── admin/                 # webman-admin
├── docs/                  # 文档
└── docker/                # Docker 配置
```

### 主要 Composer 依存

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### 高並行最適化

#### 1. MySQL 読み書き分離

Eloquent が SELECT を自動的に read 接続に、INSERT/UPDATE/DELETE を write 接続にルーティングします。

```
配置 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (主库)
  connections.mysql.read  → DB_READ_HOST  (从库，可配置多个实现负载均衡)
  sticky = true           → 同一请求周期内写后读走主库（防主从延迟）

环境变量:
  DB_HOST=10.0.1.1          # 主库（写）
  DB_READ_HOST=10.0.2.1     # 从库（读），可部署多个
```

**読み書きルーティングルール:**

| 操作タイプ | ルーティング先 | 例 |
|---------|---------|------|
| SELECT | read 接続 | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | write 接続 | `Order::create(...)` |
| トランザクション内の全操作 | write 接続 | `DB::transaction(...)` |
| 書き込み後の読み取り（sticky） | write 接続 | 同一リクエスト周期内 |

#### 2. Redis 多段キャッシュ戦略

`CacheService` で高頻度読み取りデータをキャッシュし、Redis が利用できない場合は自動でデータベース直接クエリにフォールバックします。

```
缓存分层:
  L1: Redis (进程间共享，毫秒级)
  L2: MySQL (持久化，兜底)

缓存策略:
  产品列表        TTL 5min    按 region_id + category_id + keyword 分键
  产品详情        TTL 10min   按 product_id 分键，内容变更时主动失效
  区域列表        TTL 1h      区域数据极少变动
  汇率            TTL 30min   定时任务刷新 + 主动更新
  TLD 定价        TTL 1h      TLD 价格变动频率低
  帮助文章        TTL 10min   发布/修改时主动失效
  商品分类        TTL 10min   分类树变更时主动失效

缓存预热 (部署后):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

主动失效 (数据变更时):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// 使用示例
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Nginx レスポンス圧縮 + レート制限

```
gzip 压缩:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  效果: JSON 响应压缩率 70-85%，节省带宽

proxy 优化:
  proxy_buffering on           # 缓冲上游响应，慢客户端不占 worker
  proxy_http_version 1.1       # HTTP/1.1 长连接复用
  keep-alive 到上游             # 减少 TCP 握手

限流:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  /health 端点不限流（关闭 access_log 减 I/O）
```

#### 4. データベースインデックス推奨

クエリパターン分析に基づき、以下のインデックスは高並行シナリオでスキャン行数を大幅に削減します:

| テーブル | 推奨インデックス | カバーするクエリ |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | ユーザー注文リスト + ステータスフィルタ |
| `orders` | `(order_no)` (ユニーク) | 注文番号の正確なクエリ |
| `products` | `(status, category_id, sort)` | フロント商品リスト + カテゴリフィルタ + ソート |
| `product_skus` | `(product_id, status)` | SKU リスト + ステータスフィルタ |
| `product_regions` | `(sku_id, region_id)` (ユニーク) | 地域価格設定の検索 |
| `resources` | `(user_id, status)` | マイリソースリスト |
| `resources` | `(expired_at, status)` | 期限チェック定期タスク |
| `provision_tasks` | `(status, next_retry_at)` | Worker の未処理タスクポーリング |
| `refresh_tokens` | `(user_id, revoked)` | セッション管理クエリ |
| `payment_transactions` | `(order_id)` | 注文別取引照会 |
| `payment_transactions` | `(transaction_no)` (ユニーク) | Webhook 冪等チェック |
| `tickets` | `(user_id, status)` | ユーザーチケットリスト |
| `notifications` | `(user_id, read_at, created_at)` | ユーザー通知リスト |

#### 5. 並行接続の見積り

```
webman 多进程:
  CPU 核数 × 进程数 = worker 数
  例: 4核 × 8 worker = 32 worker 进程
  
MySQL 连接数:
  每个 worker 维持 1 个持久连接
  32 worker × 2 实例 (service + admin) = 64 连接
  主库 32 + 从库 32，保守建议 MySQL max_connections ≥ 200

Nginx 连接数:
  worker_connections 1024 × worker_processes auto
  峰值并发 ≈ worker_connections × worker_processes / 2
  4核服务器 ≈ 2048 并发连接
```

---

## 12. 実装ステータス総表
### コアモジュール

| モジュール | ステータス | 説明 |
|------|------|------|
| **User** | ✅ 完了 | 登録/ログイン/メール検証/OAuth/TOTP/セッション管理/GDPR 削除/住所 CRUD |
| **Product** | ✅ 完了 | SKU×地域価格、カテゴリ、検索(ES)、レビュー、属性、バッチインポートエクスポート |
| **Order** | ✅ 完了 | カート、注文、ライフサイクル、返金、請求書(PDF)、クーポン |
| **Payment** | ✅ 完了 | Stripe チャネル、マルチチャネルルーティング、webhook 署名検証、照合 |
| **Provisioning** | ✅ 完了 | Proxmox + AWS EC2 + ProviderFactory 拡張可能アーキテクチャ |
| **Domain** | ✅ 完了 | TLD 価格、DNS レコード、ドメイン移管承認 |
| **Supplier** | ✅ 完了 | 登録承認、商品上架、決済、出金、API Key 管理 |
| **Monitor** | ✅ 完了 | リソース探知、アラートエンジン、SSL 証明書監視 |
| **Ticket** | ✅ 完了 | 作成/返信/割り当て/クローズ/SLA 追跡 |
| **Notification** | ✅ 完了 | メール/SMS/Push/サイト内メッセージの 4 チャネル + ユーザープリファレンス管理 |
| **Report** | ✅ 完了 | 売上/サプライヤー/地域レポート |
| **I18n** | ✅ 完了 | 多言語、多通貨、多タイムゾーン |

### セキュリティ体系

| 機能 | ステータス |
|------|------|
| WAF (8 カテゴリ 45+ ルール: SQL インジェクション/XSS/コマンドインジェクション/ファイルインクルージョン/ヘッダーインジェクション/SSRF/NoSQL インジェクション/オープンリダイレクト) | ✅ |
| CORS ミドルウェア | ✅ |
| ClientPlatform プラットフォーム識別ミドルウェア (8 プラットフォーム) | ✅ |
| API レート制限 (Redis トークンバケット) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| メンテナンスモード (環境変数スイッチ + IP ホワイトリスト) | ✅ |
| リクエスト/レスポンス暗号化 (AES-256-GCM) | ✅ |
| 監査ログ (独立 DB、client_platform 追跡を含む) | ✅ |
| データ秘匿化 (ログ/レスポンス自動処理) | ✅ |
| JWT デバイスフィンガープリントバインド + token ローテーション + client_platform 記録 | ✅ |
| bcrypt パスワード (cost=12) + Encryptable 二次暗号化 | ✅ |
| パスワード二次確認 (ConfirmationMiddleware、5 回失敗で 15min ロック) | ✅ |
| Admin パネル WAF ミドルウェア | ✅ |
| Sentry 異常監視（SentryBootstrap + before_send 秘匿化） | ✅ |
| Feature Flags 機能スイッチ（Redis 動的オーバーライド + 管理バックエンド API） | ✅ |

### 新機能 (2026-05-21)

| 機能 | ステータス |
|------|------|
| サプライヤー外部 API（API Key 認証 + 注文/リソース/決済/出金エンドポイント） | ✅ |
| WebSocket リアルタイムプッシュ（Workerman ネイティブ WebSocket + イベントリスナー） | ✅ |
| k6 負荷テストスクリプト（スモーク/商品/並行） | ✅ |

### バックエンド統計

| 指標 | 数量 |
|------|------|
| API エンドポイント | 135 |
| データモデル | 50+ |
| データベーステーブル | 50+ |
| ミドルウェア | 15 個（グローバル 7 + ルート 6 + 外部 API 1 + admin WebSocket） |
| 定期タスク | 7 個 |
| マイグレーションファイル | 22 個 |
| テスト | 362 tests / 579 assertions（Service 295 + Admin 67） |
| テストファイル | 22 個 |
| k6 負荷テストスクリプト | 3 個 (smoke / products / concurrent) |

### ドキュメント

| ドキュメント | パス |
|------|------|
| システム設計規範 | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| 管理バックエンド設計 | `docs/admin-design.md` |
| サプライヤー API ドキュメント | `docs/supplier-api.md` |
| デプロイチェックリスト | `docs/deployment.md` |
| API スモークテストスクリプト | `docs/api-test.sh` |

### フロントエンドステータス

| 端 | ステータス | 説明 |
|----|------|------|
| Flutter | 🟡 進行中 | ApiClient がヘッダーバージョン番号を組み込み + ApiService 統一データレイヤー；ログイン/商品リスト/カート/リソースリストは API 接続済み；注文履歴/通知センターはビルド環境での検証が必要 |
| HarmonyOS | 🔴 初期 | ログインページと ApiClient のみ |
| Admin Panel | ✅ 完了 | ダッシュボード/ユーザー/商品/注文/決済/リソース/サプライヤー/チケット/ドメイン/通知/システム/レポート/Webhook/インポートエクスポート 全機能 |
