# API 概览（英語版）

> 完全な API リファレンス（200+ エンドポイント、リクエスト/レスポンス例とエラーコードを含む）：[API リファレンス](api-reference.md)
> オンラインデバッグ：[service API ドキュメント](http://localhost:8787/apidoc) · [admin API ドキュメント](http://localhost:8788/apidoc)

## 公開エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| GET | `/health` | ヘルスチェック |
| POST | `/api/v1/auth/register` | 登録（ボディは AES-256-GCM 暗号化） |
| POST | `/api/v1/auth/login` | ログイン（ボディは AES-256-GCM 暗号化） |
| POST | `/api/v1/auth/refresh` | Token リフレッシュ（ボディは AES-256-GCM 暗号化） |
| POST | `/api/v1/captcha/create` | クリック CAPTCHA 生成（ログイン/登録前に必須） |
| GET | `/api/v1/products` | 製品一覧（カテゴリ/地域/キーワードでフィルタ可能） |
| GET | `/api/v1/products/{id}` | 製品詳細（id は hashid 文字列） |
| GET | `/api/v1/regions` | 利用可能な地域 |
| GET | `/api/v1/domain/check/{domain}/{tld}` | ドメイン利用可否の照会 |
| GET | `/api/v1/domain/tlds` | 利用可能な TLD |
| POST | `/api/v1/payments/webhook/stripe` | Stripe Webhook（署名検証あり、暗号化なし） |

## 認証エンドポイント（Bearer Token）

| メソッド | パス | 説明 |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | プロフィール取得 |
| PUT | `/api/v1/user/profile` | プロフィール更新 |
| POST | `/api/v1/user/kyc` | KYC 提出 |
| GET | `/api/v1/user/balance` | アカウント残高 |
| GET/POST | `/api/v1/cart` | ショッピングカート |
| POST/GET | `/api/v1/orders` | 注文 |
| GET | `/api/v1/orders/{id}/payment-methods` | 利用可能な支払い方法 |
| POST | `/api/v1/orders/{id}/pay` | 支払い開始 |
| GET/POST | `/api/v1/resources` | マイリソース |
| GET | `/api/v1/resources/{id}/status` | リソースステータス |
| GET | `/api/v1/resources/{id}/console` | VNC コンソール URL |
| GET/POST | `/api/v1/tickets` | サポートチケット |
| POST | `/api/v1/tickets/{id}/reply` | チケットへの返信 |
| GET/POST | `/api/v1/dns/{domain}` | DNS 管理 |
| POST | `/api/v1/supplier/apply` | サプライヤー申請 |
| GET | `/api/v1/supplier/settlements` | 決済履歴 |
| POST | `/api/v1/supplier/withdraw` | 出金リクエスト |

> **注記：** すべての API リクエストは URL パスでバージョンを指定します（例: `/api/v1/products`）。認証・管理者エンドポイントは `EncryptionMiddleware` で処理されます。クライアントは `X-Encrypted: 1` ヘッダーを設定し、ボディを `{"payload": "<base64(AES-256-GCM)>"}` としてラップします。レスポンスも同様に暗号化され `payload` フィールドにラップされます。API レスポンス内の整数 ID は自動的に 12 桁の Hashid 文字列に変換され、リクエスト内の Hashid 文字列は `HashidRequestMiddleware` によって整数 ID にデコードされます。

## 管理者エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | 運用ダッシュボード |
| GET/PUT | `/admin/api/v1/users` | ユーザー管理 |
| GET/POST | `/admin/api/v1/kyc` | KYC 審査 |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | 製品管理 |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU 作成 |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | 地域価格の設定 |
| GET/POST | `/admin/api/v1/orders` | 注文管理（返金含む） |
| GET | `/admin/api/v1/orders/export` | 注文エクスポート (.xlsx) |
| GET | `/admin/api/v1/users/export` | ユーザーエクスポート (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | サプライヤーエクスポート (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | チャネル / 取引 / 照合 |
| GET/POST | `/admin/api/v1/provisioning/*` | デリバリータスク / ホスト管理 |
| GET/POST | `/admin/api/v1/suppliers/*` | サプライヤー承認 / 決済 / 出金 |
| GET/POST | `/admin/api/v1/tickets` | チケット割り当て / クローズ |
| GET | `/admin/api/v1/reports/*` | 売上 / 地域 / サプライヤーレポート |
| GET | `/admin/api/v1/monitor/*` | モニターダッシュボード / リソースメトリクス |
| GET | `/admin/api/v1/audit-logs` | 監査ログ |
| PUT | `/admin/api/v1/system/config` | システム設定の更新 |
