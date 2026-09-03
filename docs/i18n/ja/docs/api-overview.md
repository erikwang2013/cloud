# API 概览

> 完全な API リファレンス（200+ エンドポイント、リクエスト/レスポンス例とエラーコードを含む）：[API インターフェースドキュメント](api-reference.md)
> オンラインデバッグ：[service API ドキュメント](http://localhost:8787/apidoc) · [admin API ドキュメント](http://localhost:8788/apidoc)

## 公開インターフェース

| メソッド | パス | 説明 |
|------|------|------|
| GET | `/health` | ヘルスチェック |
| POST | `/api/v1/auth/register` | ユーザー登録（リクエストボディは AES-256-GCM 暗号化が必要） |
| POST | `/api/v1/auth/login` | ユーザーログイン（リクエストボディは AES-256-GCM 暗号化が必要） |
| POST | `/api/v1/auth/refresh` | Token リフレッシュ（リクエストボディは AES-256-GCM 暗号化が必要） |
| POST | `/api/v1/captcha/create` | クリック CAPTCHA 生成（ログイン/登録前に取得） |
| GET | `/api/v1/products` | 製品一覧（カテゴリ/地域/キーワードでフィルタ可能） |
| GET | `/api/v1/products/{id}` | 製品詳細（id は hashid 文字列） |
| GET | `/api/v1/regions` | 利用可能な地域 |
| GET | `/api/v1/domain/check/{domain}/{tld}` | ドメイン利用可否の照会 |
| GET | `/api/v1/domain/tlds` | 登録可能な TLD 一覧 |
| POST | `/api/v1/payments/webhook/stripe` | Stripe コールバック（署名検証あり、暗号化不要） |

## 認証インターフェース（Bearer Token 必須）

| メソッド | パス | 説明 |
|------|------|------|
| GET | `/api/v1/user/profile` | 個人情報 |
| PUT | `/api/v1/user/profile` | 情報更新 |
| POST | `/api/v1/user/kyc` | 実名認証の提出 |
| GET | `/api/v1/user/balance` | アカウント残高 |
| GET/POST | `/api/v1/cart` | ショッピングカート |
| POST/GET | `/api/v1/orders` | 注文 |
| GET | `/api/v1/orders/{id}/payment-methods` | 利用可能な支払い方法 |
| POST | `/api/v1/orders/{id}/pay` | 支払い開始 |
| GET/POST | `/api/v1/resources` | マイリソース |
| GET | `/api/v1/resources/{id}/status` | リソースステータス |
| GET | `/api/v1/resources/{id}/console` | VNC コンソールリンク |
| GET/POST | `/api/v1/cdn/domains` | CDN ドメインリスト / 作成（cloudflare \| cloudfront \| aliyun \| tencent） |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | CDN ドメイン詳細 / 削除 |
| POST | `/api/v1/cdn/domains/{id}/purge` | キャッシュ削除（冪等、最大 100 URL） |
| GET/POST | `/api/v1/tickets` | サポートチケット |
| POST | `/api/v1/tickets/{id}/reply` | チケット返信 |
| GET/POST | `/api/v1/dns/{domain}` | DNS 管理 |
| POST | `/api/v1/supplier/apply` | サプライヤー申請 |
| GET | `/api/v1/supplier/settlements` | サプライヤー決済記録 |
| POST | `/api/v1/supplier/withdraw` | サプライヤー出金 |

> **説明：** すべての API リクエストは URL パスでバージョンを指定します（例: `/api/v1/products`）。認証インターフェースと管理者インターフェースのリクエスト/レスポンスはすべて `EncryptionMiddleware` で処理されます。クライアントは `X-Encrypted: 1` リクエストヘッダーを設定し、リクエストボディの形式は `{"payload": "<base64(AES-256-GCM)>"}` で、レスポンスボディも同様に暗号化されて `payload` フィールドにラップされます。すべての整数 ID は API レスポンスで自動的に 12 桁の Hashid 文字列に変換され、リクエスト内の Hashid 文字列は `HashidRequestMiddleware` によって自動的に整数 ID へデコードされます。

## 管理者インターフェース

| メソッド | パス | 説明 |
|------|------|------|
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
| GET/PUT | `/admin/api/v1/payments/*` | 支払いチャネル / 取引 / 照合 |
| GET/POST | `/admin/api/v1/provisioning/*` | デリバリータスク / ホスト管理 |
| GET/PUT | `/admin/api/v1/cdn/domains` | CDN ドメイン管理（プラン変更） |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | プロバイダーアカウント資格情報管理（CDN/デリバリー共用、Encryptable 暗号化） |
| GET/POST | `/admin/api/v1/suppliers/*` | サプライヤー承認 / 決済 / 出金 |
| GET/POST | `/admin/api/v1/tickets` | チケット割り当て / クローズ |
| GET | `/admin/api/v1/reports/*` | 売上 / 地域 / サプライヤーレポート |
| GET | `/admin/api/v1/monitor/*` | モニターパネル / リソースメトリクス |
| GET | `/admin/api/v1/audit-logs` | 監査ログ |
| PUT | `/admin/api/v1/system/config` | システム設定 |
