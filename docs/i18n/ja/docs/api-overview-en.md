# API 概览（英語版）

> 完全な API リファレンス（200+ エンドポイント、リクエスト/レスポンス例とエラーコードを含む）：[API リファレンス](api-reference.md)
> オンラインデバッグ：[service API ドキュメント](http://localhost:8787/apidoc) · [admin API ドキュメント](http://localhost:8788/apidoc)

## 公開エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| GET | `/health` | ヘルスチェック |
| POST | `/api/auth/register` | 登録（ボディは AES-256-GCM 暗号化） |
| POST | `/api/auth/login` | ログイン（ボディは AES-256-GCM 暗号化） |
| POST | `/api/auth/refresh` | Token リフレッシュ（ボディは AES-256-GCM 暗号化） |
| POST | `/api/captcha/create` | クリック CAPTCHA 生成（ログイン/登録前に必須） |
| GET | `/api/products` | 製品一覧（カテゴリ/地域/キーワードでフィルタ可能） |
| GET | `/api/products/{id}` | 製品詳細（id は hashid 文字列） |
| GET | `/api/regions` | 利用可能な地域 |
| GET | `/api/domain/check/{domain}/{tld}` | ドメイン利用可否の照会 |
| GET | `/api/domain/tlds` | 利用可能な TLD |
| POST | `/api/payments/webhook/stripe` | Stripe Webhook（署名検証あり、暗号化なし） |

## 認証エンドポイント（Bearer Token）

| メソッド | パス | 説明 |
|--------|------|-------------|
| GET | `/api/user/profile` | プロフィール取得 |
| PUT | `/api/user/profile` | プロフィール更新 |
| POST | `/api/user/kyc` | KYC 提出 |
| GET | `/api/user/balance` | アカウント残高 |
| GET/POST | `/api/cart` | ショッピングカート |
| POST/GET | `/api/orders` | 注文 |
| GET | `/api/orders/{id}/payment-methods` | 利用可能な支払い方法 |
| POST | `/api/orders/{id}/pay` | 支払い開始 |
| GET/POST | `/api/resources` | マイリソース |
| GET | `/api/resources/{id}/status` | リソースステータス |
| GET | `/api/resources/{id}/console` | VNC コンソール URL |
| GET/POST | `/api/tickets` | サポートチケット |
| POST | `/api/tickets/{id}/reply` | チケットへの返信 |
| GET/POST | `/api/dns/{domain}` | DNS 管理 |
| POST | `/api/supplier/apply` | サプライヤー申請 |
| GET | `/api/supplier/settlements` | 決済履歴 |
| POST | `/api/supplier/withdraw` | 出金リクエスト |

> **注記：** すべての API リクエストは `X-Api-Version: v1` ヘッダーを携帯する必要があります（欠落時はデフォルト `v1`、`VersionMiddleware` が検証）。認証・管理者エンドポイントは `EncryptionMiddleware` で処理されます。クライアントは `X-Encrypted: 1` ヘッダーを設定し、ボディを `{"payload": "<base64(AES-256-GCM)>"}` としてラップします。レスポンスも同様に暗号化され `payload` フィールドにラップされます。API レスポンス内の整数 ID は自動的に 12 桁の Hashid 文字列に変換され、リクエスト内の Hashid 文字列は `HashidRequestMiddleware` によって整数 ID にデコードされます。

## 管理者エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | 運用ダッシュボード |
| GET/PUT | `/admin/api/users` | ユーザー管理 |
| GET/POST | `/admin/api/kyc` | KYC 審査 |
| GET/POST/PUT/DELETE | `/admin/api/products` | 製品管理 |
| POST | `/admin/api/products/{productId}/skus` | SKU 作成 |
| POST | `/admin/api/skus/{skuId}/region-price` | 地域価格の設定 |
| GET/POST | `/admin/api/orders` | 注文管理（返金含む） |
| GET | `/admin/api/orders/export` | 注文エクスポート (.xlsx) |
| GET | `/admin/api/users/export` | ユーザーエクスポート (.xlsx) |
| GET | `/admin/api/suppliers/export` | サプライヤーエクスポート (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | チャネル / 取引 / 照合 |
| GET/POST | `/admin/api/provisioning/*` | デリバリータスク / ホスト管理 |
| GET/POST | `/admin/api/suppliers/*` | サプライヤー承認 / 決済 / 出金 |
| GET/POST | `/admin/api/tickets` | チケット割り当て / クローズ |
| GET | `/admin/api/reports/*` | 売上 / 地域 / サプライヤーレポート |
| GET | `/admin/api/monitor/*` | モニターダッシュボード / リソースメトリクス |
| GET | `/admin/api/audit-logs` | 監査ログ |
| PUT | `/admin/api/system/config` | システム設定の更新 |
