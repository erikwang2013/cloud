# サプライヤー API ドキュメント v1

## 概要

サプライヤー機能は 2 種類の API を提供します:

| 種類 | 認証方式 | プレフィックス | ステータス |
|------|---------|------|------|
| **内部 API** | ユーザー Bearer Token | `/api/v1/supplier/` | 利用可能 |
| **外部 API** | API Key (`sk_xxx`) | `/api/v1/supplier/external/` | 利用可能 |

**Base URL**: `https://api.example.com`

**バージョン管理**: URL パスで指定します（例: `/api/v1/supplier/products`）。サポート外のバージョンは `400` を返します。`/api/v1/*` と `/admin/api/v1/*` パスのみに適用され、`VersionMiddleware` が一元的に処理します。

---

## 内部 API（現在利用可能）

内部 API はプラットフォームの他 API と同じユーザー Bearer Token 認証を使用し、ログイン済みのサプライヤーユーザーがクライアント/フロントエンドから呼び出します。

### 認証

```
Authorization: Bearer <user_access_token>
```

ユーザーはまず `/api/v1/auth/login` でログインして Token を取得する必要があり、アカウントのロールが `supplier` である必要があります（管理者がサプライヤー申請を承認すると設定されます）。

---

### レスポンス形式

#### 成功レスポンス

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### ページネーション付きレスポンス

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### エラーレスポンス

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | 説明 |
|------|------|
| 0 | 成功 |
| 400 | リクエストパラメータエラー / サポート外の API バージョン |
| 401 | 未ログインまたは Token 有効期限切れ |
| 403 | アクセス権なし（サプライヤーロールではない / パスワード確認失敗） |
| 404 | リソースが存在しない |
| 422 | パラメータ検証失敗 |
| 429 | リクエスト頻度制限超過 |

---

### エンドポイント

#### 1. サプライヤー登録

```
POST /api/v1/supplier/apply
```

サプライヤーへの申込です。各ユーザーは 1 回しか申請できません。

**リクエストボディ**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| パラメータ | 型 | 必須 | 説明 |
|------|------|------|------|
| company_name | string | はい | 会社名 |
| contact_name | string | はい | 連絡担当者名 |
| contact_phone | string | はい | 連絡先電話番号 |
| contact_email | string | はい | 連絡先メールアドレス |
| settlement_method | string | いいえ | 決済方法、デフォルト `bank` |

**レスポンス**: サプライヤーオブジェクト、ステータスは `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> 機密フィールド（連絡担当者名、電話番号、メールアドレス）はデータベースに暗号化して保存され、API レスポンス時は一部が秘匿化されます。

**エラー**:

| code | シナリオ |
|------|------|
| 422 | サプライヤー申請を既に提出済み |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. 商品管理

##### 割り当て済み商品の取得

```
GET /api/v1/supplier/products
```

**Query パラメータ**:

| パラメータ | 型 | 必須 | 説明 |
|------|------|------|------|
| page | int | いいえ | ページ番号、デフォルト 1 |

**レスポンス**: ページネーション付きリスト。各項目に商品情報とコミッション率を含む

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### 商品の追加

```
POST /api/v1/supplier/products
```

既存の商品を現在のサプライヤーに関連付けます。

**リクエストボディ**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| パラメータ | 型 | 必須 | 説明 |
|------|------|------|------|
| product_id | string | はい | 商品 ID（Hashid） |
| commission_rate | float | いいえ | コミッション率、デフォルト 0.1 |

**レスポンス**: 作成された SupplierProduct オブジェクト

**エラー**:

| code | シナリオ |
|------|------|
| 422 | 商品が既にこのサプライヤーに割り当て済み |

##### 商品の削除

```
DELETE /api/v1/supplier/products/{id}
```

商品とサプライヤーの関連付けを解除します。

**レスポンス**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. 決済管理

##### 決済明細リストの取得

```
GET /api/v1/supplier/settlements
```

**レスポンス**: 現在のサプライヤーの全決済明細を、作成日時の降順で返す

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| フィールド | 説明 |
|------|------|
| total_sales | 期間内に完了した注文の総売上 |
| commission | プラットフォームのコミッション総額 |
| payable | サプライヤーへの支払額（total_sales - commission） |
| status | `pending` / `paid` |

---

#### 4. 出金

##### 出金申請

```
POST /api/v1/supplier/withdraw
```

> この操作にはパスワードの二次確認（`confirm_password` フィールド）が必要で、`ConfirmationMiddleware` が検証します。
> 5 回失敗すると 15 分間ロックされます。

**リクエストボディ**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| パラメータ | 型 | 必須 | 説明 |
|------|------|------|------|
| amount | string | はい | 出金額（浮動小数点の精度問題を避けるため文字列） |
| confirm_password | string | はい | ユーザーログインパスワード（二次確認） |
| account_info | object | はい | 受取口座情報 |
| account_info.method | string | はい | 出金方法: `bank_transfer` / `alipay` / `wechat` |

**出金可能残高の計算**: 完了した全決済明細の `payable` 合計 - 処理中の全出金 `amount` 合計

**レスポンス**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**エラー**:

| code | シナリオ |
|------|------|
| 422 | 出金可能残高不足 |
| 403 | パスワード確認失敗 |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### 内部 API エンドポイント一覧

| メソッド | パス | 認証 | パスワード確認 | 説明 |
|------|------|------|---------|------|
| POST | `/api/v1/supplier/apply` | Token | - | サプライヤーへの申込 |
| GET | `/api/v1/supplier/products` | Token | - | 割り当て済み商品の確認 |
| POST | `/api/v1/supplier/products` | Token | - | 商品関連付けの追加 |
| DELETE | `/api/v1/supplier/products/{id}` | Token | - | 商品関連付けの解除 |
| GET | `/api/v1/supplier/settlements` | Token | - | 決済明細の確認 |
| POST | `/api/v1/supplier/withdraw` | Token | 必要 | 出金申請 |

---

## 外部 API（設計仕様、未実装）

外部 API はサプライヤーがプログラムで注文、リソース、決済を管理できるようにします。すべてのリクエストに API Key 認証が必要です。

**Base URL**: `https://api.example.com/api/v1`

### 認証

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API Key はプラットフォーム管理者が管理バックエンドの `供应商管理 → API Keys` で生成します。

**セキュリティ要件**:
- HTTPS 経由でのみアクセス
- API Key は作成時に 1 回だけ表示されるため、大切に保管してください
- サーバー IP をホワイトリストに登録することを推奨

---

### レスポンス形式

内部 API と同様で、追跡用の `request_id` が追加されます:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### エンドポイント

#### 1. 注文管理

##### 注文リストの取得

```
GET /api/v1/supplier/orders
```

**Query パラメータ**:

| パラメータ | 型 | 必須 | 説明 |
|------|------|------|------|
| page | int | いいえ | ページ番号、デフォルト 1 |
| page_size | int | いいえ | 1 ページあたりの件数、デフォルト 20、最大 50 |
| status | string | いいえ | フィルタステータス: pending/paid/completed/refunded |
| from | date | いいえ | 開始日 YYYY-MM-DD |
| to | date | いいえ | 終了日 YYYY-MM-DD |

##### 注文詳細の取得

```
GET /api/v1/supplier/orders/{id}
```

---

#### 2. リソース管理

##### リソースリストの取得

```
GET /api/v1/supplier/resources
```

**Query パラメータ**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### リソースステータスの取得

```
GET /api/v1/supplier/resources/{id}/status
```

---

#### 3. 決済管理

##### 決済明細リストの取得

```
GET /api/v1/supplier/settlements
```

##### 決済明細詳細の取得

```
GET /api/v1/supplier/settlements/{id}
```

---

#### 4. 出金

##### 出金申請

```
POST /api/v1/supplier/withdraw
```

##### 出金履歴

```
GET /api/v1/supplier/withdraws
```

---

#### 5. 商品管理

##### 自分の商品の取得

```
GET /api/v1/supplier/products
```

##### 商品上架申請の提出

```
POST /api/v1/supplier/products
```

---

### 外部 API エンドポイント一覧

| メソッド | パス | 説明 |
|------|------|------|
| GET | `/api/v1/supplier/orders` | 注文リスト |
| GET | `/api/v1/supplier/orders/{id}` | 注文詳細 |
| GET | `/api/v1/supplier/resources` | リソースリスト |
| GET | `/api/v1/supplier/resources/{id}/status` | リソースステータス |
| GET | `/api/v1/supplier/settlements` | 決済明細リスト |
| GET | `/api/v1/supplier/settlements/{id}` | 決済明細詳細 |
| POST | `/api/v1/supplier/withdraw` | 出金申請 |
| GET | `/api/v1/supplier/withdraws` | 出金履歴 |
| GET | `/api/v1/supplier/products` | 商品リスト |
| POST | `/api/v1/supplier/products` | 商品の提出 |

---

## Webhook（プラットフォームイベントの受信）

サプライヤーは Webhook URL を登録してリアルタイムイベントを受信できます。管理バックエンドで設定します。

### イベントタイプ

| イベント | トリガータイミング |
|------|----------|
| `order.paid` | ユーザーが支払いを完了 |
| `order.refunded` | 注文が返金された |
| `resource.provisioned` | リソースの開通完了 |
| `resource.expiring` | リソースが間もなく期限切れ (7 日以内) |
| `resource.destroyed` | リソースが破棄された |
| `settlement.created` | 決済明細が生成された |
| `withdrawal.approved` | 出金が承認された |

### Webhook リクエスト形式

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**署名検証**: `HMAC-SHA256(payload, webhook_secret)`

---

## レート制限

| エンドポイント | 制限 |
|------|------|
| 内部 API | ユーザーごとに 60 req/min（デフォルト） |
| 内部 API ログイン | 5 req/min |
| 外部 API | API Key ごとに 120 req/min（`supplier_api` ルール、`RateLimitMiddleware` で適用） |
| 外部 API 出金 | 10 req/min（推奨値、`config/security.php` で調整可） |

> 外部 API のレート制限ルールは `config/security.php` の `rate_limits.supplier_api` で定義され、
> `RateLimitMiddleware` が `/api/v1/supplier/external/*` パスに一元的に適用します（アトミックな INCR カウント、
> Redis が利用できない場合は許可）。

レート制限ヘッダー:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK サンプル

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/v1/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
    ],
]);

// サプライヤーへの申込
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// 決済明細の取得
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// 出金申請
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
}

# 割り当て済み商品の取得
resp = requests.get('https://api.example.com/api/v1/supplier/products',
                     headers=headers)
products = resp.json()

# 出金申請
resp = requests.post('https://api.example.com/api/v1/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## エラー処理の推奨事項

1. **429 レート制限**: `Retry-After` 秒待ってからリトライ
2. **401 未認証**: Token が有効か、期限切れかを確認
3. **403 禁止**: アカウントのロールが `supplier` か確認。パスワード確認失敗時はロック解除を待つ
4. **422 検証失敗**: `message` フィールドに基づいてリクエストパラメータを修正
5. **5xx サーバーエラー**: 指数バックオフでリトライ (1s -> 5s -> 25s)

---

## 管理バックエンドのエンドポイントリファレンス

以下は管理者がサプライヤーを管理するための関連エンドポイントです（管理バックエンド専用、Admin ロールが必要）:

| メソッド | パス | 説明 |
|------|------|------|
| GET | `/admin/api/v1/suppliers` | サプライヤーリスト（status フィルタ対応） |
| GET | `/admin/api/v1/suppliers/export` | サプライヤーの Excel エクスポート |
| POST | `/admin/api/v1/suppliers/{id}/approve` | サプライヤーの承認 |
| POST | `/admin/api/v1/suppliers/{id}/settle` | 決済明細の生成 |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | 出金の承認 |
| GET | `/admin/api/v1/suppliers/{id}/api-keys` | サプライヤーの API Key リスト表示 |
| POST | `/admin/api/v1/suppliers/{id}/api-keys` | API Key の作成（元の Key は 1 回だけ返却） |
| DELETE | `/admin/api/v1/suppliers/api-keys/{id}` | API Key の失効 |
