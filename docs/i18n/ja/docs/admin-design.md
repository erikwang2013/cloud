# Admin Panel 設計ドキュメント

## 概要

`admin/` は独立した webman v2.1 インスタンスで、Layui ベースの管理ダッシュボードを提供します。`service/` バックエンドとは独立して動作し、MySQL データベースと 7 つの erikwang2013 パッケージのみを共有します。

## アーキテクチャ

```
┌─────────────────────────────────────────────────┐
│                  Admin Panel                     │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │ Controller│  │  Model   │  │   Bootstrap   │ │
│  │ (Layui)  │  │(Eloquent)│  │(worker start) │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │             │               │          │
│  ┌────┴─────────────┴───────────────┴─────────┐ │
│  │         7 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season │ Poster     │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

### モジュール依存マップ

![Module Dependency Map](diagrams/module-dependency.svg)

## ディレクトリ構成

```
admin/
├── app/
│   ├── bootstrap/       # プロセスごとの起動処理
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 54 個のコントローラファイル (Base/Crud + エンティティ別 CRUD)
│   │   ├── Base.php     # hashids_encode_ids 付き json()
│   │   ├── Crud.php     # hashids デコード付き Select/Insert/Update/Delete/Export
│   │   ├── DashboardController.php  # ダッシュボードデータ API (ユーザー統計 + トレンド)
│   │   ├── AccountController.php    # ログイン/ログアウト/プロフィール/パスワード
│   │   ├── AdminController.php      # Admin CRUD + ロール
│   │   ├── RoleController.php       # ロール CRUD + ルールツリー
│   │   └── ...
│   ├── model/            # 44 個の Eloquent モデル（36 個は service のプレフィックスなし業務テーブル + alerts（install.sql で定義）+ 7 個の wa_* 管理テーブルをマッピング）
│   │   ├── Base.php     # Snowflake PK + Encryptable 対応
│   │   ├── Admin.php    # Encryptable: password, email, mobile
│   │   ├── User.php     # Encryptable: 6 フィールド + Searchable トレイト
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # WafMiddleware + AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # パブリック API (plugin\admin\api)
│   └── Auth.php          # canAccess() ACL
├── config/
│   ├── plugin/erikwang2013/  # 7 プラグイン設定
│   ├── hashids.php       # Hashids 接続 (main + alternative)
│   └── encryption.php    # 暗号化設定 (マスターキー、cipher)
├── tests/                # PHPUnit 11 テストスイート (286 tests, 962 assertions)
│   ├── HashidsTest.php   # 21 tests
│   ├── BaseJsonTest.php  # 13 tests
│   ├── CrudHashidsTest.php # 14 tests
│   ├── TreeTest.php      # 19 tests
│   ├── AccessControlMiddlewareTest.php # 7 tests（401/403/放行）
│   ├── AdminControllersTest.php        # 48 コントローラ反射回帰テスト
│   ├── UtilTest.php      # 17 tests
│   ├── DictTest.php      # 5 tests
│   ├── ExcelExportTest.php # 4 tests
│   ├── LayuiTest.php     # 5 tests
│   └── support/          # RequestMock, TestableCrud
├── install.sql           # DDL (bigint unsigned PK、auto-increment なし)
└── phpunit.xml
```

## パッケージ統合の詳細

### 1. Snowflake（分散主キー）

**設定**: `config/plugin/erikwang2013/snowflake-php/app.php`
**Bootstrap**: `app/bootstrap/SnowflakeBootstrap.php`

```php
// Model Base::boot() — creating イベント
static::creating(function (Model $model) {
    if (empty($model->getKey())) {
        $snowflake = Container::instance()->get(Snowflake::class);
        $model->setAttribute($model->getKeyName(), $snowflake->id());
    }
});
```

- 64 ビット ID: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`
- Epoch: 2024-01-01（最長寿命約 69 年）
- Base モデルで `$incrementing = false`、`$keyType = 'int'`
- 全 PK および FK カラム: `bigint unsigned NOT NULL`

### 2. Hashids（ID 難読化）

**設定**: `config/hashids.php` + `config/plugin/erikwang2013/hashids/app.php`

**エンコード経路**（レスポンス）:
- `Base::json()` が `hashids_encode_ids($data)` を再帰的に呼び出し
- `id`、`*_id`、`*_ids` という名前の正の整数フィールド → hashid 文字列に変換
- `Crud::formatNormal()` もエンコードを適用（コードレビューで修正済み）

**デコード経路**（リクエスト）:
- `Crud::selectInput()`: WHERE 句の `id`/`*_id` hashid 文字列をデコード
- `Crud::updateInput()`: `$request->post()` から主キーをデコード
- `Crud::deleteInput()`: `$request->post()` から PK 配列をデコード
- `AdminController::update()`: `updateInput()` の戻り値をそのまま使用（重複排除済み）
- `RoleController::select()`/`rules()`: `$request->get('id')` をデコード

**ヘルパー関数**（`app/functions.php`）:
- `hashids_encode(int $id): string`
- `hashids_decode(string $hash): int` — 失敗時は 0 を返す
- `hashids_encode_ids(array $data): array` — 再帰処理、`is_numeric()` 文字列に対応

### 3. Encryptable（データベースフィールド暗号化）

**設定**: `config/plugin/erikwang2013/encryptable/app.php`
**Bootstrap**: `app/bootstrap/EncryptableBootstrap.php`

Eloquent `CastsAttributes` インターフェースを使用:
- `get()`: DB から読み取る際に値を AES 復号
- `set()`: DB に書き込む際に値を AES 暗号化

**暗号化フィールド**:
| モデル | フィールド |
|-------|--------|
| Admin | password, email, mobile |
| User | password, email, mobile, token, last_ip, join_ip |

**重要なルール**: 常にモデルインスタンスの `save()` を使用し、Query Builder の `update()` は使わないこと。`Admin::where(...)->update(...)` を使用すると Eloquent のキャストを迂回して生の値を保存してしまいます。これはコードレビュー中に `AccountController` で修正されました。

**パスワードのレイヤリング**: パスワードはまず bcrypt ハッシュ化され（`insertInput`/`updateInput` 内）、その後 `save()` 時に Encryptable キャストで AES 暗号化されます。読み取り時: AES 復号 → bcrypt ハッシュ → `password_verify()`。

### 4. Encryption（API 転送）

**設定**: `config/encryption.php`
**Bootstrap**: `app/bootstrap/EncryptionBootstrap.php`

API レベルのリクエスト/レスポンス暗号化（AES-256-GCM）用に予約されています。提供する関数:
- `encrypt_data(string $plaintext): string`
- `decrypt_data(string $ciphertext): string`

`ENCRYPTION_MASTER_KEY` が設定されていない場合、明確なメッセージ付きで `RuntimeException` をスローします。

### 5. Webman-Scout (Elasticsearch)

**設定**: `config/plugin/erikwang2013/webman-scout/app.php` + `command.php`

User モデルは `Searchable` トレイトを使用:
```php
class User extends Base
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
```

### 6. Season（国旗）

**設定**: `config/plugin/erikwang2013/season/app.php`

グローバルヘルパー: `country_season_flag(string $code): string`
- `country_season_flag('CN')` → 🇨🇳
- `country_season_flag('US')` → 🇺🇸

また `CountrySeason` クラスでローカライズされた季節名も提供します。

### 7. Poster-PHP（クリック CAPTCHA）

**設定**: `config/poster.php` + `config/plugin/erikwang2013/poster/app.php`
**Bootstrap**: `config/plugin/erikwang2013/poster/bootstrap.php` → `CaptchaPlugin`

ログインと登録用のクリック型 CAPTCHA 検証を提供します:

```
Client                         Server
──────                         ──────
POST /api/v1/captcha/create
  → CaptchaService::create()
    → captcha_create('click')
      → ClickCaptcha::generate()
        → GD renders image with n randomly-placed Chinese words
        → Stores targets + key in Redis/File storage
      ← {key, image (base64), target_count, expires_in}

POST /api/v1/auth/login
  (with captcha_key + captcha_points)
  → AuthController::verifyCaptcha()
    → CaptchaService::verify(key, [[x1,y1], [x2,y2], ...])
      → captcha_verify(key, 'click', points)
        → CaptchaManager checks Euclidean distance ≤ 18px tolerance
      ← true/false
```

**セキュリティ機能**:
- ワンタイムキー: 検証成功後に削除
- ブルートフォース対策: キーごとに最大 3 回の失敗で削除
- 300 秒の TTL（`CAPTCHA_TTL` で設定可能）
- クリック許容範囲: 18px 半径（設定可能）
- 難易度レベル: easy (2 ターゲット)、medium (3)、hard (4)
- ストレージ: Redis → ファイルフォールバックの自動検出、`CAPTCHA_STORAGE` で設定可能

**ラッパー**: `Common\Captcha\CaptchaService` が `config/poster.php` からカスタム設定を読み込み、`create()`（セキュリティのためレスポンスからターゲットを除去）と `verify()` メソッドを提供します。`AuthController::register()` と `AuthController::login()` で使用されます。

### 8. ConfirmationMiddleware（パスワード再確認）

**設定**: `config/route.php` のルートグループミドルウェア

破壊的・機密性の高い操作を、ユーザーのパスワード再入力の要求で保護します。12 の機密ルートエンドポイントにミドルウェアとして適用されます:

```
Client                              Server
──────                              ──────
POST /api/v1/orders/{id}/pay
  (with confirm_password field)
    → ConfirmationMiddleware::process()
      → Checks userId present (401 if missing)
      → Checks Redis lock key (429 if locked out)
      → Validates password non-empty (422 if missing)
      → User::find() + Hash::check() verifies bcrypt
      → On failure:
        → Redis INCR confirm_failed:{userId} counter
        → If count ≥ 5, SETEX confirm_lock:{userId} for 900s
        → AuditLogger::record('confirm_failed', ...)
        → Returns 403
      → On success:
        → DEL confirm_failed:{userId} counter
        → AuditLogger::record('confirm_success', ...)
        → Calls $next($request)
```

**機密ユーザーエンドポイント** (Auth + Confirmation):
| メソッド | パス | 操作 |
|--------|------|-----------|
| POST | `/api/v1/orders/{id}/pay` | 支払い開始 |
| POST | `/api/v1/supplier/withdraw` | 出金申請 |
| DELETE | `/api/v1/dns/{domain}/records/{id}` | DNS レコード削除 |

**機密管理エンドポイント** (Auth + AdminRole + Confirmation):
| メソッド | パス | 操作 |
|--------|------|-----------|
| DELETE | `/admin/api/v1/products/{id}` | 商品削除 |
| POST | `/admin/api/v1/orders/{id}/refund` | 注文の返金 |
| POST | `/admin/api/v1/provisioning/resources/{id}/destroy` | リソース破棄 |
| POST | `/admin/api/v1/kyc/{id}/approve` | KYC 承認 |
| POST | `/admin/api/v1/kyc/{id}/reject` | KYC 却下 |
| POST | `/admin/api/v1/suppliers/{id}/approve` | サプライヤー承認 |
| POST | `/admin/api/v1/suppliers/{id}/settle` | 決済明細の生成 |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | 出金承認 |
| PUT | `/admin/api/v1/system/config` | システム設定の更新 |

API バージョンは URL パスで指定します（例: `/api/v1/products`）。

**セキュリティ機能**:
- `Hash::check()` による bcrypt パスワード検証
- レート制限: 5 回失敗で 15 分ロック（900s TTL）
- ロックは Redis キーでユーザーごとに適用（`confirm_lock:{userId}`、`confirm_failed:{userId}`）
- 成功時は失敗カウンターをリセット
- すべての試行が監査データベースに記録される（成功、失敗、ロック）
- `verifyPassword()` は protected メソッドで、匿名サブクラスによるオーバーライドでテスト可能

**テスト容易性**: `ConfirmationMiddlewareTest`（11 tests）は `verifyPassword()` をオーバーライドして固定の boolean を返す匿名サブクラスを使用し、Eloquent/DB 依存を回避します。テスト範囲: 401 未認証、422 パスワード欠落/空、403 パスワード誤り、成功パススルー、レート制限キー形式、ロックキー形式、最大失敗しきい値境界（4→ロックなし、5→ロック、6→ロック）。

## ACL システム

### コントローラレベル

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // ログイン不要
protected $noNeedAuth = ['select'];                         // 認証不要
```

`api/Auth::canAccess()` が `ReflectionClass` 経由でチェックします。

**AccessControlMiddleware のレスポンス**（`middleware/AccessControl.php`）:
- 未ログイン（`noNeedLogin` 以外）→ **HTTP 401**、body はログインページへのリダイレクトスクリプト
- ログイン済みだが権限不足 → **HTTP 403** エラーページ（ステータスコード 403、500 ではない）
- 許可リスト内（ログインページ/検証コードなど）→ 通常どおり許可

### ロールベース

- ロールは `rules`（カンマ区切りのルール ID、スーパー管理者は `*`）を持つ
- ルールは `wa_rules` に `{Controller}@{action}` キーとして保存
- `api/Auth::canAccess()` が `$controller@$action` キーをロールの rules と照合
- スーパー管理者（`rules = '*'`）はすべてのチェックをスキップ

### データ制限

```php
protected $dataLimit = null;     // 制限なし
protected $dataLimit = 'auth';   // 自分 + 子孫のデータのみ表示
protected $dataLimit = 'personal'; // 自分のデータのみ表示
protected $dataLimitField = 'admin_id';
```

## コードレビューでの指摘事項（修正済み）

初回コミットのレビュー中に以下が見つかり、修正されました:

### 重大 (Critical)
1. **AccountController が Encryptable を迂回**: `password()` と `update()` が `Admin::where()->update()` を使用しており、Eloquent キャストを迂回 → 暗号化カラムに生の値を保存。`Admin::find()->save()` を使用するよう修正。
2. **Crud::formatNormal() が ID をエンコードしない**: グローバル `json()` を呼び出しており、`hashids_encode_ids()` を適用していなかった。修正済み。

### 重要 (Important)
3. **hashids_encode_ids の厳格な `is_int`**: PDO からの大きな bigint 値は PHP 文字列として到着。整数チェック付きの `is_numeric()` に変更。
4. **AdminController の重複 ID デコード**: `update()` が同じ PK を 2 回デコード。重複排除し、`insert()` のループ変数シャドウイングを修正。
5. **AccountController::update() のデッドパスワードコード**: パスワードフィールドが許可リストにない。削除済み。
6. **ハードコードされた MySQL ドライバ**: `config('database.default')` に変更。

## Excel エクスポート

### アーキテクチャ

Excel エクスポートは PhpSpreadsheet ^2.0 を使用してサーバー側で .xlsx ファイルを生成します。管理パネルには 2 つの CRUD メカニズムがあるため、2 つの独立したエクスポート経路があります:

```
Export request (with current table filters)
  ├── Crud-based controllers (User, Admin, Role, etc.)
  │     → Crud::export()
  │       → selectInput() reuses query parsing (hashids decode, WHERE, ORDER)
  │       → doSelect() builds Eloquent query
  │       → 10,000 row cap
  │       → hashids_encode_ids() applied to result data
  │       → ExcelExport::export() generates .xlsx
  │
  └── TableController (generic tables like wa_dict, wa_rules)
        → TableController::export()
          → Builds query from table schema + request params
          → hashids_encode_ids() applied
          → ExcelExport::export() generates .xlsx
```

### ExcelExport ユーティリティ（`app/common/ExcelExport.php`）

PhpSpreadsheet のフルーエントラッパー:

- `setColumns(array $columns)` — カラム順を定義
- `setLabels(array $labels)` — 人が読めるカラムヘッダーを設定
- `addRow(array $row)` / `addRows(array $rows)` — データを投入
- `save(string $title): string` — .xlsx を `runtime/exports/` に書き込み、ファイルパスを返す
- 静的ヘルパー: `ExcelExport::export($title, $columns, $data, $labels)` — ワンショットエクスポート
- `Worksheet::getColumnDimension()` でカラム幅を自動調整

### Crud::export()

```php
public function export(Request $request): Response
{
    [$where, $format, $limit, $field, $order] = $this->selectInput($request);
    $query = $this->doSelect($where, $field, $order);
    $maxRows = 10000;
    $total = min($query->count(), $maxRows);
    $items = $query->limit($maxRows)->get();
    if (method_exists($this, 'afterQuery')) {
        $items = call_user_func([$this, 'afterQuery'], $items);
    }
    $data = array_map(fn($item) => ...toArray(), $items->toArray());
    $data = hashids_encode_ids($data);
    // Derive column labels from table schema comments
    $path = ExcelExport::export($table, $columns, $data, $labels);
    return response()->download($path, $table . '_' . date('YmdHis') . '.xlsx');
}
```

すべての Crud ベースのコントローラ（Admin、User、Role など）は `export()` を自動的に継承します。

### フロントエンド連携

- Layui 内蔵の `"exports"` ツールバー項目（クライアント側 CSV）は、カスタムの `{title: "导出", layEvent: "export"}` ボタンに置き換えられます
- `export` イベントハンドラーは `window.exportExcel()` を呼び出し、現在のテーブルフィルタパラメータを収集してダウンロード URL を開きます
- `Layui::buildTable()` がすべての CRUD ページにカスタムエクスポートボタン付きのツールバーを生成します

### Service 管理 API のエクスポート

service バックエンド（`service/`）にも独自の `Common\ExcelExport` ラッパーによる Excel エクスポートがあります:

| エンドポイント | コントローラ | エクスポートデータ |
|----------|-----------|---------------|
| `GET /admin/api/v1/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/v1/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/v1/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

すべての API エンドポイントは URL パスでバージョンを指定します（例: `/api/v1/products`）。

エクスポートルートは競合を避けるため `/{id}` パラメータルートの**前に**配置されます。

## Service 管理 API — 拡張機能

### 管理 API エンドポイント（Service レイヤー）

すべての管理 REST エンドポイントは `/admin/api/v1` プレフィックスで、`AdminRoleMiddleware` が必要です。

| グループ | エンドポイント | コントローラ |
|-------|-----------|------------|
| Dashboard | `GET /dashboard`, `/kyc`, `POST /kyc/{id}/approve`, `/reject` | `Admin\DashboardController` |
| Users | `GET /users`, `/users/export`, `/users/{id}`, `PUT /users/{id}/status` | `Admin\UserController` |
| Products | `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}`, `POST /products/{id}/skus`, `PUT /skus/{id}`, `POST /skus/{id}/region-price` | `Admin\ProductController` |
| Product Import/Export | `GET /products/export` (CSV), `POST /products/import` (CSV upsert) | `Admin\ImportExportController` |
| Orders | `GET /orders`, `/orders/export`, `/orders/{id}`, `POST /orders/{id}/refund` | `Admin\OrderController` |
| Invoices | `GET /invoices`, `POST /invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Payments | `GET /payments/channels`, `PUT /payments/channels/{id}`, `GET /payments/transactions`, `/reconcile` | `Admin\PaymentController` |
| Provisioning | `GET /provisioning/tasks`, `POST /tasks/{id}/retry`, `POST /resources/{id}/upgrade`, `/destroy`, `GET /hosts` | `Provisioning\TaskController` |
| Provider APIs | `GET /providers`, `POST /providers`, `PUT /providers/{id}`, `DELETE /providers/{id}` | `Admin\ProviderApiController` |
| CDN | `GET /cdn/domains`, `PUT /cdn/domains/{id}` | `Admin\CdnController` |
| Suppliers | `GET /suppliers`, `/suppliers/export`, `POST /suppliers/{id}/approve`, `/settle`, `/withdraws/{id}/approve` | `Admin\SupplierController` |
| Supplier API Keys | `GET /suppliers/{id}/api-keys`, `POST /suppliers/{id}/api-keys`, `DELETE /suppliers/api-keys/{id}` | `Admin\SupplierController` |
| Tickets | `GET /tickets`, `POST /tickets/{id}/assign`, `/close` | `Ticket\TicketController` |
| Coupons | `GET /coupons`, `POST /coupons`, `DELETE /coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET /webhooks`, `POST /webhooks`, `DELETE /webhooks`, `POST /webhooks/test` | `Admin\WebhookController` |
| Domains | `GET /domains/tlds`, `POST /domains/tlds`, `PUT /domains/tlds/{id}`, `DELETE /domains/tlds/{id}`, `GET /domains/zones`, `/transfers`, `POST /transfers/{id}/approve` | `Admin\DomainController` |
| Notifications | `GET /notifications/templates`, `PUT /notifications/templates/{id}`, `GET /notifications/log` | `Admin\NotificationController` |
| Help Articles | `GET /help`, `POST /help`, `PUT /help/{id}`, `DELETE /help/{id}` | `Admin\HelpController` |
| Reports | `GET /reports/revenue`, `/supplier`, `/region` | `Report\ReportController` |
| Monitoring | `GET /monitor/dashboard`, `/resources/{id}` | `Monitor\MonitorController` |
| Audit | `GET /audit-logs` | `Admin\SystemController` |
| System Config | `PUT /system/config` | `Admin\SystemController` |

### CDN 資源管理

CDN 製品は 4 社のプロバイダー（Cloudflare / CloudFront / Aliyun / Tencent）に対応し、管理側は 2 つの部分に分かれる：

**プロバイダーアカウント設定**（ProviderApi モデルを再利用、`Admin\ProviderApiController`）：

- `GET/POST /admin/api/v1/providers`、`PUT/DELETE /admin/api/v1/providers/{id}`、`RbacMiddleware('provider.config')` を適用
- `code` は `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent` と規定；資格情報フィールドは Encryptable で暗号化して保存、`config` JSON 列は非機密メタデータを保存
- ユーザー側の資格情報解決順序：バインドアカウント → code 一致のアクティブアカウント → env フォールバック；削除/purge は厳格スナップショット（バインドアカウントのみ使用、欠落/無効は 4003）

**CDN ドメイン管理**（`Admin\CdnController`）：

```
GET /admin/api/v1/cdn/domains        → 全ドメイン（所属 user_id 含む）、RbacMiddleware('cdn.manage') を適用
PUT /admin/api/v1/cdn/domains/{id}   → プラン更新、plan ホワイトリスト standard | pro | enterprise、
                                    不正値は 400 を返す；変更は監査ログ admin_cdn_update_plan に記録
```

### ダッシュボードデータ（Service レイヤー）

`Admin\DashboardController::index()` が実際の運用メトリクスを提供します:

```php
[
    'today_stats' => [todayOrders, todayRevenue, newUsers, activeResources],
    'revenue_trend_30d' => [...],   // 直近 30 日間の日次売上
    'region_distribution' => [...],  // 地域別のアクティブリソース
    'pending_orders' => ...,         // 支払い待ちの注文
    'pending_kyc' => ...,            // 審査待ちの KYC 申請
    'open_tickets' => ...,           // オープンまたは処理中のチケット
]
```

### Admin Panel ダッシュボードビュー（`app/view/index/dashboard.html`）

- **8 つのアニメーション統計カード**: 今日/今週/今月/合計ユーザー + 今日の注文 + 今日の売上 + 保留中の注文 + アクティブリソース — それぞれ Layui `count` モジュールによるカウントアップアニメーション付き
- **3 つの ECharts チャート**:
  1. 7 日間のユーザー登録トレンド — エリア折れ線チャート
  2. 30 日間のユーザー登録トレンド — 棒グラフ
  3. ユーザーサマリー — ドーナツ/円グラフ（今日 / 今週 / 今月）
- **システム情報テーブル**: PHP/Workerman/Webman/Admin/MySQL/OS のバージョンを動的に表示
- **ツールバー**: PDF エクスポートとリフレッシュボタン
- すべてのデータは `/app/admin/dashboard/data` への AJAX で取得

### ルート

```
Route::any('/app/admin/dashboard/data', [DashboardController::class, 'index']);
```

明示的に登録されたルート以外に、`admin/config/route.php` は `app/controller/` 配下の各コントローラの公開メソッドに対して `/app/admin/{snake_case_controller}/{action}` ルートを自動登録します（例: `/app/admin/order_item/index`）。URL とメニューは snake_case のコントローラ名に一致します。`/app/admin` と `/app/admin/index` は管理画面のホーム/ログインページのエントリです（未ログイン時はログインビューをレンダリング）。未一致のリクエストは一律 404 を返します。

## PDF エクスポート

ダッシュボードページでのクライアント側 PDF 生成:

- **html2canvas 1.4.1**（CDN）を使用してダッシュボード DOM をキャンバスとしてキャプチャ
- **jsPDF 2.5.1**（CDN）を使用してダウンロード可能な A4 PDF を作成
- 統計カードと ECharts チャート（canvas 要素としてレンダリング）をキャプチャ
- タイトル、タイムスタンプ、ブランディングを PDF に含める
- ダッシュボードツールバーの「Export PDF」ボタンでトリガー

```
Dashboard DOM → html2canvas screenshot → jsPDF document → browser download
```

### 実装

```javascript
// In dashboard.html
html2canvas(document.querySelector('.layui-body'), {scale: 2}).then(canvas => {
    const pdf = new jsPDF('p', 'mm', 'a4');
    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 10, 10, 190, 0);
    pdf.save('dashboard_' + new Date().toISOString().slice(0, 10) + '.pdf');
});
```

## テストスイート

```
PHPUnit 11.5 | 67 tests | 124 assertions
```

### HashidsTest (21 tests)
- encode/decode ラウンドトリップ（0 から PHP_INT_MAX まで）
- 決定的エンコード
- 無効/空文字列の処理
- `hashids_encode_ids` のフィールドパターン（`id`、`*_id`、`*_ids`）
- ゼロ/負値のスキップ、数値文字列対応
- ネスト配列の再帰、非 ID フィールドの保持

### BaseJsonTest (13 tests)
- `json()`/`success()`/`fail()` が hashids エンコードを適用
- ネストオブジェクトのエンコード
- Snowflake サイズの ID 処理
- 非 ID フィールドの保持
- ゼロの処理
- レスポンス構造の検証

### CrudHashidsTest (14 tests)
- `selectInput`: `id`/`*_id` WHERE フィールドの hashid デコード
- `selectInput`: 数値文字列/素の int のパススルー
- `updateInput`: hashid 主キーのデコード
- `updateInput`: 数値文字列の主キーを int にキャスト
- `deleteInput`: バッチ ID デコード、混合型
- `deleteInput`: 空配列、単一 ID の処理

## データベースマイグレーションシステム

### アーキテクチャ

`service/` と `admin/` の両インスタンスは、`illuminate/database` Schema Builder に基づく独立したマイグレーションシステムを持ちます。各インスタンスは `config/command.php` で Symfony Console コマンドを登録し、webman のコンソールランナーから利用できます。

```
php webman migrate          # 未実行のマイグレーションを実行
php webman migrate:rollback # 直前のバッチをロールバック
php webman migrate:status   # マイグレーション状態を表示
```

### MigrationRunner (`service/support/MigrationRunner.php`、`admin/app/common/MigrationRunner.php`)

両インスタンスで共有されるコアエンジン:

- **`ensureTable()`** — 初回実行時に `migrations` 追跡テーブル（id、マイグレーション名、バッチ番号）を作成
- **`migrate()`** — `database/migrations/` のマイグレーションファイルをスキャンし、未実行の `up()` メソッドを実行、バッチを記録
- **`rollback()`** — 直前のバッチを逆順で各マイグレーションの `down()` を呼び出してロールバック
- **`status()`** — バッチ番号付きで全マイグレーションを一覧表示
- **`resolve()`** — ファイルからマイグレーションクラスをインスタンス化

### Migration 基底クラス (`service/support/Migration.php`、`admin/app/common/Migration.php`)

```php
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
```

各マイグレーションファイルは `Migration` を拡張するクラスを返し、ファイル名にタイムスタンププレフィックスが付きます（例: `2024_01_01_000001_create_initial_schema.php`）。

### Service マイグレーション

**ディレクトリ**: `service/database/migrations/` — 38 個のマイグレーションファイル（テーブル名に erik_ プレフィックスなし、admin モデルが直接マッピング）

| マイグレーション | テーブル |
|-----------|--------|
| `0001_create_users_tables` | users, user_profiles, user_kyc, user_balance, user_balance_log, user_addresses, refresh_tokens |
| `0002_create_product_tables` | product_categories, regions, products, product_skus, product_regions, product_images, product_attributes, product_reviews |
| `0003_create_order_tables` | carts, orders, order_items, order_timeline, order_invoices, refunds |
| `0004_create_payment_tables` | payment_channels, payment_transactions, payment_reconcile |
| `0005_create_provisioning_tables` | resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis |
| `0006_create_host_tables` | host_machines, ip_pools, ip_allocations, disks, disk_resizes |
| `0007_create_supplier_tables` | suppliers, supplier_products, supplier_settlements, supplier_withdraws |
| `0008_create_domain_tables` | domain_tlds, domain_transfers, dns_zones, dns_records |
| `0009_create_ticket_notification_tables` | tickets, ticket_messages, notifications, notification_templates |
| `0010_create_audit_table` | audit_logs |
| `0011_create_kvm_service_tables` | network_services, firewall_services, switch_services |
| `2024_01_01_000001_create_initial_schema` | `Capsule::unprepared()` で `docs/database.sql` を実行、`down()` で全テーブルをドロップ |
| `2025_05_16_000002_add_fcm_token_to_users` | users に `fcm_token`、`fcm_platform` カラム + インデックスを追加 |
| `2026_08_26_000003_widen_encrypted_columns` | users.phone / user_addresses.phone / suppliers.contact_phone VARCHAR(20)→VARCHAR(255)（Encryptable 暗号文の長さ） |

### Admin マイグレーション

**ディレクトリ**: `admin/database/migrations/` — 1 個のマイグレーションファイル

| マイグレーション | 説明 |
|-----------|-------------|
| `2024_01_01_000001_create_admin_schema` | `Capsule::unprepared()` で `admin/install.sql` を実行 — シードデータ付きで wa_* テーブルを作成 |

### コンソールコマンドの登録

**`service/config/command.php`**:
```php
return [
    \App\Command\MigrateCommand::class,
    \App\Command\MigrateRollbackCommand::class,
    \App\Command\MigrateStatusCommand::class,
];
```

**`admin/config/command.php`** — `app\command` 名前空間で同様のパターン。

## Stripe 本番統合

### アーキテクチャ

偽の `random_bytes()` ペイメント ID を、`stripe/stripe-php` ^15.0 による実際の Stripe API 統合に置き換えました。

**ファイル**: `service/app/payment/service/channels/StripeChannel.php`

```
Client-side                    Server-side                    Stripe API
───────────                    ───────────                    ──────────
Select Stripe at checkout
  → POST /orders/{id}/pay
    → StripeChannel::createPaymentIntent()
      → StripeClient->paymentIntents->create(amount, currency)
        ← {id, client_secret}
      → Save pi_xxx as transaction_no
      ← Return client_secret
  → Stripe.js confirmCardPayment(client_secret)
    ← Payment confirmed by Stripe
      → POST /payments/webhook/stripe
        → StripeChannel::handleWebhook()
          → Webhook::constructEvent(payload, signature, secret)
          → Verify idempotency (skip non-pending transactions)
          → Update order status, create transaction record
```

### PaymentIntent の作成

```php
public function createPaymentIntent(Order $order): array
{
    $intent = $this->stripe()->paymentIntents->create([
        'amount'   => (int) round($order->total * 100),  // cents
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id, 'order_no' => $order->order_no],
    ]);
    return [
        'transaction_no' => $intent->id,          // pi_xxxxxxxxxxxxx
        'client_secret'  => $intent->client_secret, // pi_xxx_secret_yyy
    ];
}
```

- `$this->stripe()` が env の `STRIPE_SECRET_KEY` で `\Stripe\StripeClient` を遅延初期化
- env 変数が未設定の場合は `$this->channel->api_key_encrypted`（Encryptable で復号）にフォールバック
- 金額はセントに変換: `(int) round($order->total * 100)`

### Webhook 署名検証

```php
public function handleWebhook(string $payload, string $signature): void
{
    $event = \Stripe\Webhook::constructEvent(
        $payload, $signature, $this->channel->webhook_secret_encrypted
    );
    // Idempotency: skip if transaction already processed
    $existing = Transaction::where('transaction_no', $event->id)->first();
    if ($existing && $existing->status !== 'pending') return;
    
    match ($event->type) {
        'payment_intent.succeeded' => $this->confirmPayment($event),
        'payment_intent.payment_failed' => $this->failPayment($event),
        default => null,
    };
}
```

- `Webhook::constructEvent()` で Stripe 署名ヘッダーを検証
- **冪等性ガード**: `transaction_no` で重複する Webhook 配信をチェック
- 成功と失敗の両イベントタイプに対応

## Twilio SMS 統合

### アーキテクチャ

`error_log()` スタブを `twilio/sdk` ^8.0 による実際の SMS 配信に置き換えました。

**ファイル**: `service/app/notification/queue/SmsSender.php`

### メッセージ送信

```php
public function consume(): void
{
    $client = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );
    $message = $client->messages->create(
        $this->notification->recipient_phone,
        ['from' => getenv('TWILIO_PHONE_NUMBER'), 'body' => $this->notification->body]
    );
    $this->notification->provider_message_id = $message->sid;
}
```

### エラー処理

- `Twilio\Exceptions\RestException` をキャッチ — Twilio のエラーコードとメッセージを取得
- `send_status = 'failed'` の失敗 Notification レコードを作成
- 配信追跡用に `provider_message_id`（Twilio SID）を記録
- Twilio 認証情報が未設定の場合（開発モード）は `error_log()` にフォールバック

### 設定

env 変数: `TWILIO_ACCOUNT_SID`、`TWILIO_AUTH_TOKEN`、`TWILIO_PHONE_NUMBER`

## FCM プッシュ統合

### アーキテクチャ

`error_log()` スタブを `kreait/firebase-php` ^7.0 による実際のプッシュ配信に置き換えました。

**ファイル**: `service/app/notification/queue/PushSender.php`

### デバイストークンの保存

マイグレーションで `users` テーブルに追加:
- `fcm_token VARCHAR(512) DEFAULT NULL` — デバイス登録トークン
- `fcm_platform VARCHAR(16) DEFAULT NULL` — `ios` / `android` / `web`
- `INDEX idx_fcm_token (fcm_token)` — トークンによる検索

User モデル: `fcm_token` と `fcm_platform` を `$fillable` に追加。

### プッシュ送信

```php
public function consume(): void
{
    $factory = new \Kreait\Firebase\Factory();
    if ($credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH')) {
        $factory = $factory->withServiceAccount($credentialsPath);
    }
    $messaging = $factory->createMessaging();
    
    $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget(
        'token', $this->user->fcm_token
    )->withNotification([
        'title' => $this->notification->title,
        'body'  => $this->notification->body,
    ]);
    
    $result = $messaging->send($message);
}
```

### トークンのクリーンアップ

- `Kreait\Firebase\Exception\Messaging\InvalidToken` をキャッチ — ユーザーの `fcm_token` を null 化
- `Kreait\Firebase\Exception\Messaging\NotFound` をキャッチ — 未登録トークンを削除
- Firebase 認証情報が未設定の場合（開発モード）は `error_log()` にフォールバック

### 設定

env 変数: `FIREBASE_CREDENTIALS_PATH`（サービスアカウント JSON）、`FCM_SERVER_KEY`（レガシー）

## ビジネスフロー図

### 注文 → 支払い → プロビジョニング（コアビジネスフロー）

![Order Payment Provisioning Flow](diagrams/order-payment-provisioning.svg)

### イベント駆動プロビジョニングの詳細

![Event-Driven Provisioning](diagrams/provisioning-detail.svg)

### 通知ディスパッチ

![Notification Dispatch](diagrams/notification-dispatch.svg)

### サプライヤーライフサイクル

![Supplier Lifecycle](diagrams/supplier-lifecycle.svg)

### チケットライフサイクル

![Ticket Lifecycle](diagrams/ticket-lifecycle.svg)

## Service レイヤーのテストスイート

### 概要

```
PHPUnit 10.5 | 295 tests | 455 assertions
```

**ディレクトリ**: `service/tests/` — 7 モジュールにわたる 12 テストファイル

**設定**: `service/phpunit.xml` — 単一の `unit` テストスイートで、`app/` と `common/` のソースをカバー

### テストブートストラップ

`service/tests/bootstrap.php` は Composer autoload を読み込み、テスト対象コードが必要とする 2 つのグローバルヘルパーを定義します:

- `request_id()` — 一意のリクエスト ID 文字列を返す
- `now()` — 現在の `DateTime` オブジェクトを返す

重要な学び: `Webman\Config` は `loadFromDir()` が `route.php` をトリガーし、それが null に対して `Route::addRoute()` を呼び出すため、テストコンテキストではロードできません。テストは Config を完全に回避します — `HashidServiceTest` は `new Hashids()` を直接使用し、`ResponseTest` はローカルのヘルパーメソッドを使用します。

### テストファイル

| ファイル | テスト数 | カバレッジ |
|------|-------|----------|
| `Captcha/CaptchaServiceTest.php` | 9 | create 構造、難易度レベル、verify 成功/失敗、ワンタイム使用、一意キー |
| `Confirmation/ConfirmationMiddlewareTest.php` | 11 | 認証必須、パスワード欠落、パスワード誤り、成功パススルー、レート制限キー形式、ロックキー形式、最大失敗しきい値 |
| `Common/HashidServiceTest.php` | 17 | encode/decode ラウンドトリップ、決定性、ソルト分離、再帰 ID ウォーク |
| `Common/ResponseTest.php` | 16 | 成功/エラー/ページネーション構造、request_id 一貫性、HTTP エラーコード |
| `Common/SnowflakeTest.php` | 6 | タイムスタンプ順序、一意性、bigint 範囲、init パターン |
| `Common/ValidatorTest.php` | 22 | required()、email()、minLength() 検証ルール |
| `Common/LogSanitizerTest.php` | 34 | PII 秘匿化、ネスト配列、大文字小文字を区別しないマッチング、20 種類の機密フィールド型 |
| `Payment/StripeChannelTest.php` | 19 | チャネル設定、金額計算、webhook 署名、冪等性 |
| `Payment/PaymentRouterTest.php` | 10 | チャネルフィルタ、金額制約、通貨/地域サポート、手数料計算 |
| `Notification/NotificationDispatcherTest.php` | 8 | テンプレートレンダリング、チャネルルーティング、非アクティブユーザーのスキップ |
| `Provisioning/ProviderFactoryTest.php` | 12 | register、create、createFromResource、エラーケース |
| `Provisioning/RetryLogicTest.php` | 12 | 指数バックオフ、最大リトライ、ステータス遷移、ホスト選択 |
| `ClientPlatform/ClientPlatformMiddlewareTest.php` | 13 | 有効なプラットフォーム、ヘッダー欠落/デフォルト、サポート外プラットフォーム、大文字小文字を区別しない、非 API スキップ、admin ルート、ダウンストリームアクセス |
| `Security/WafMiddlewareTest.php` | 37 | SQLi (4)、XSS (6)、CMDi (4)、ファイルインクルージョン (3)、ヘッダーインジェクション/CRLF (2)、SSRF (5)、NoSQL インジェクション (4)、オープンリダイレクト (2)、安全パススルー (5)、URL スキャン、UA スキャン |
| `Version/VersionMiddlewareTest.php` | 6 | 有効なバージョン、バージョン欠落のデフォルト、サポート外バージョン 400、非 API スキップ、admin API 検証、エラーレスポンスヘッダー |

### テストインフラストラクチャ

- `tests/TestCase.php` — PHPUnit TestCase を拡張する基底クラス
- `tests/Support/RequestMock.php` — コンストラクタ注入パラメータ付きのモックリクエスト

## CI/CD パイプライン

### アーキテクチャ

`.github/workflows/ci.yml` の GitHub Actions ワークフロー。

**トリガー**: `main` への push、`main` への PR

### ジョブ

| ジョブ | 戦略 | 説明 |
|-----|----------|-------------|
| `syntax` | PHP 8.2 | admin/ と service/ の全 .php ファイルを `php -l` で lint |
| `admin-tests` | PHP 8.2, 8.3 | `composer install -d admin` → `admin/vendor/bin/phpunit` |
| `service-tests` | PHP 8.2, 8.3 | `composer install -d service` → `service/vendor/bin/phpunit` |
| `composer-validate` | PHP 8.2 | 両方の composer.json で `composer validate --strict` |

### PHP バージョンマトリックス

両テストジョブとも `shivammathur/setup-php@v2` で PHP 8.2 と 8.3 で実行されます。

### 現在のステータス

4 ジョブすべてがパス: 合計 243 tests（admin 67 + service 176）、400 assertions、両 PHP バージョンでグリーン。

## データベースエンティティ関連

![Database Entity Relationship](diagrams/database-er.svg)

## 主要な設計判断

1. **独立インスタンス**: admin/ は service/ 内のプラグインではなく、独自の webman インスタンスとして実行されます。これにより、管理トラフィックと障害を顧客向け API から分離できます。

2. **Encryptable + パスワードハッシュ**: パスワードはまず bcrypt ハッシュ化され、その後 AES 暗号化されます。Encryptable キャストは Eloquent レベル（ハッシュ化の上）で動作するため、レイヤリングは: `入力 → bcrypt ハッシュ → モデル属性セット → Encryptable::set() が暗号化 → DB`。読み取り時: `DB → Encryptable::get() が復号 → bcrypt ハッシュ → password_verify()`。

3. **コントローラ境界での Hashids**: エンコード/デコードはモデルや ORM レベルではなく、HTTP 境界（コントローラ）で行われます。これによりモデルはデータベース非依存のまま、hashids は純粋なプレゼンテーション上の関心事になります。

4. **コンテナベースのサービス解決**: サービス（Snowflake、HashidsManager、EncryptionManager）は worker 起動時に Bootstrap クラス経由でシングルトン登録されます。`\support\Container::instance()` によるコンテナ解決は遅延インスタンス化を使用 — サービスは最初のアクセス時にのみ作成されます。

## 拡張機能（2026-05-20）

### Service 管理 API — 新エンドポイント

| グループ | エンドポイント | コントローラ |
|-------|-----------|------------|
| Invoices | `GET /admin/api/v1/invoices`, `POST .../invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Provider APIs | `GET/POST /admin/api/v1/providers`, `PUT/DELETE .../providers/{id}` | `Admin\ProviderApiController` |
| Supplier API Keys | `GET/POST /admin/api/v1/suppliers/{id}/api-keys`, `DELETE .../api-keys/{id}` | `Admin\SupplierController` |
| Coupons | `GET/POST /admin/api/v1/coupons`, `DELETE .../coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET/POST/DELETE /admin/api/v1/webhooks`, `POST .../webhooks/test` | `Admin\WebhookController` |
| Product Import/Export | `GET /admin/api/v1/products/export`, `POST .../products/import` | `Admin\ImportExportController` |
| Domain Management | `GET/POST/PUT/DELETE /admin/api/v1/domains/tlds`, `GET .../zones`, `GET .../transfers`, `POST .../transfers/{id}/approve` | `Admin\DomainController` |
| Notification Templates | `GET /admin/api/v1/notifications/templates`, `PUT .../templates/{id}`, `GET .../log` | `Admin\NotificationController` |
| Help Articles | `GET/POST /admin/api/v1/help`, `PUT/DELETE .../help/{id}` | `Admin\HelpController` |

### 新しいミドルウェア

| ミドルウェア | 目的 |
|------------|---------|
| `VersionMiddleware` | URL パスから API バージョンを読み取り検証 |
| `RateLimitMiddleware` | Redis トークンバケットによるレート制限（デフォルト 60req/min、ログイン 5req/min） |
| `GeoBlockMiddleware` | MaxMind GeoIP2 による地域ブロック |
| `MaintenanceMiddleware` | メンテナンスモード（環境変数スイッチ + IP ホワイトリスト） |
| `ClientPlatformMiddleware` | クライアントプラットフォーム識別（X-Client-Platform ヘッダー）、8 プラットフォーム対応 |
| `SupplierApiKeyMiddleware` | サプライヤー外部 API 認証（sk_xxx Key SHA256 検証） |
| `WafMiddleware` (admin) | Admin パネル用 WAF ミドルウェア、8 カテゴリ 45+ ルール + リクエストサイズ制限 + Content-Type 検証 |

### 定期タスク

| スケジュール | タスク | 目的 |
|----------|------|---------|
| `13 */4 * * *` | ExchangeRateSync | 為替レート更新 |
| `37 2 * * *` | PaymentReconcile | 日次決済照合 |
| `17 4 * * 1` | SupplierSettlement | 週次サプライヤー決済 |
| `23 6 * * *` | ExpirationCheck | リソース/ドメイン期限チェック+通知 |
| `43 7 * * *` | SslCertificateCheck | SSL 証明書期限チェック+通知 |
| `*/5 * * * *` | CollectMetrics | リソースメトリクス収集 |
| `*/30 * * * *` | CheckExpirations | リソース期限チェック |

### CLI コマンド

| コマンド | 目的 |
|---------|---------|
| `php webman migrate` | 未実行のマイグレーションを実行 |
| `php webman migrate:rollback` | 直前のバッチをロールバック |
| `php webman migrate:status` | マイグレーション状態を表示 |
| `php webman db:backup` | データベースを SQL ファイルにバックアップ（オプションで --s3 アップロード） |

### 追加されたデータベースマイグレーション（2026-05-20）

| マイグレーション | テーブル/カラム |
|-----------|---------------|
| 000003 | users + totp_secret, totp_enabled |
| 000004 | coupons, user_coupons |
| 000005 | help_articles, supplier_api_keys |
| 000006 | roles, permissions, role_permission + seed データ |
| 000007 | users + email_verified_at, email_verify_token |
| 000008 | users + last_login_ip, last_login_at |

## ドキュメント索引

### コアドキュメント

| ドキュメント | パス | 説明 |
|----------|------|-------------|
| アーキテクチャ設計 | `docs/architecture.md` | システムアーキテクチャ、コンポーネント関係、ミドルウェアパイプライン、セキュリティレイヤリング、データアーキテクチャ、デプロイトポロジー |
| 機能設計 | `docs/features.md` | 21 モジュールの詳細な機能設計。フローチャート、データモデル、インタラクション説明を含む |
| API インターフェースドキュメント | `docs/api-reference.md` | 200+ エンドポイントの完全リファレンス。モジュール別にグループ化、リクエスト/レスポンス例とエラーコードを含む |
| API オンラインドキュメント (service) | `http://localhost:8787/apidoc` | hg/apidoc 自動生成、機能別グループ化、オンラインデバッグ対応 |
| API オンラインドキュメント (admin) | `http://localhost:8788/apidoc` | hg/apidoc 自動生成、54 コントローラ 13 機能グループ |
| システム設計仕様 | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` | 完全なアーキテクチャ、データモデル、API 設計、セキュリティポリシー |
| 管理パネル設計 | `docs/admin-design.md` | Admin パネルのアーキテクチャ、パッケージ統合、ACL 権限、テストスイート |
| サプライヤー API ドキュメント | `docs/supplier-api.md` | サプライヤー API リファレンス（内部 API + 外部 API）、SDK サンプル |
| デプロイチェックリスト | `docs/deployment.md` | サーバー設定、環境変数、データベースマイグレーション、Nginx、HTTPS、定期タスク |

### 実装計画

| ドキュメント | パス | 説明 |
|----------|------|-------------|
| Phase 0 — 基盤フレームワーク | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase0.md` | プロジェクトスケルトン、ディレクトリ構造、コアインフラストラクチャ |
| Phase 1 — ユーザーとモール | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase1.md` | ユーザー認証、商品管理、カート、注文 |
| Phase 2 — リソースとサプライヤー | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase2.md` | リソース開通、DNS、サプライヤー登録 |
| Phase 3 — クライアントと納品 | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase3.md` | Flutter クライアント、マルチプラットフォーム対応、CI/CD |

### ツールとリソース

| ドキュメント | パス | 説明 |
|----------|------|-------------|
| API スモークテスト | `docs/api-test.sh` | curl ベースの API エンドポイント自動テストスクリプト |
| データベース DDL | `docs/database.sql` | データベーステーブル作成文 |

## 最終テスト統計

```
OK (362 tests, 579 assertions)
Test files: 22
```
- Admin: 67 tests, 124 assertions
- Service: 295 tests, 455 assertions
