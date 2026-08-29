# API 概览

> 完全な API リファレンス（200+ エンドポイント、リクエスト/レスポンス例とエラーコードを含む）：[API インターフェースドキュメント](api-reference.md)
> オンラインデバッグ：[service API ドキュメント](http://localhost:8787/apidoc) · [admin API ドキュメント](http://localhost:8788/apidoc)

## 公開インターフェース

| メソッド | パス | 説明 |
|------|------|------|
| GET | `/health` | ヘルスチェック |
| POST | `/api/auth/register` | ユーザー登録（リクエストボディは AES-256-GCM 暗号化が必要） |
| POST | `/api/auth/login` | ユーザーログイン（リクエストボディは AES-256-GCM 暗号化が必要） |
| POST | `/api/auth/refresh` | Token リフレッシュ（リクエストボディは AES-256-GCM 暗号化が必要） |
| POST | `/api/captcha/create` | クリック CAPTCHA 生成（ログイン/登録前に取得） |
| GET | `/api/products` | 製品一覧（カテゴリ/地域/キーワードでフィルタ可能） |
| GET | `/api/products/{id}` | 製品詳細（id は hashid 文字列） |
| GET | `/api/regions` | 利用可能な地域 |
| GET | `/api/domain/check/{domain}/{tld}` | ドメイン利用可否の照会 |
| GET | `/api/domain/tlds` | 登録可能な TLD 一覧 |
| POST | `/api/payments/webhook/stripe` | Stripe コールバック（署名検証あり、暗号化不要） |

## 認証インターフェース（Bearer Token 必須）

| メソッド | パス | 説明 |
|------|------|------|
| GET | `/api/user/profile` | 個人情報 |
| PUT | `/api/user/profile` | 情報更新 |
| POST | `/api/user/kyc` | 実名認証の提出 |
| GET | `/api/user/balance` | アカウント残高 |
| GET/POST | `/api/cart` | ショッピングカート |
| POST/GET | `/api/orders` | 注文 |
| GET | `/api/orders/{id}/payment-methods` | 利用可能な支払い方法 |
| POST | `/api/orders/{id}/pay` | 支払い開始 |
| GET/POST | `/api/resources` | マイリソース |
| GET | `/api/resources/{id}/status` | リソースステータス |
| GET | `/api/resources/{id}/console` | VNC コンソールリンク |
| GET/POST | `/api/cdn/domains` | CDN ドメインリスト / 作成（cloudflare \| cloudfront \| aliyun \| tencent） |
| GET/DELETE | `/api/cdn/domains/{id}` | CDN ドメイン詳細 / 削除 |
| POST | `/api/cdn/domains/{id}/purge` | キャッシュ削除（冪等、最大 100 URL） |
| GET/POST | `/api/tickets` | サポートチケット |
| POST | `/api/tickets/{id}/reply` | チケット返信 |
| GET/POST | `/api/dns/{domain}` | DNS 管理 |
| POST | `/api/supplier/apply` | サプライヤー申請 |
| GET | `/api/supplier/settlements` | サプライヤー決済記録 |
| POST | `/api/supplier/withdraw` | サプライヤー出金 |

> **説明：** すべての API リクエストは `X-Api-Version: v1` リクエストヘッダーを携帯する必要があります（欠落時はデフォルト `v1`、`VersionMiddleware` が検証）。認証インターフェースと管理者インターフェースのリクエスト/レスポンスはすべて `EncryptionMiddleware` で処理されます。クライアントは `X-Encrypted: 1` リクエストヘッダーを設定し、リクエストボディの形式は `{"payload": "<base64(AES-256-GCM)>"}` で、レスポンスボディも同様に暗号化されて `payload` フィールドにラップされます。すべての整数 ID は API レスポンスで自動的に 12 桁の Hashid 文字列に変換され、リクエスト内の Hashid 文字列は `HashidRequestMiddleware` によって自動的に整数 ID へデコードされます。

## 管理者インターフェース

| メソッド | パス | 説明 |
|------|------|------|
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
| GET/PUT | `/admin/api/payments/*` | 支払いチャネル / 取引 / 照合 |
| GET/POST | `/admin/api/provisioning/*` | デリバリータスク / ホスト管理 |
| GET/PUT | `/admin/api/cdn/domains` | CDN ドメイン管理（プラン変更） |
| GET/POST/PUT/DELETE | `/admin/api/providers` | プロバイダーアカウント資格情報管理（CDN/デリバリー共用、Encryptable 暗号化） |
| GET/POST | `/admin/api/suppliers/*` | サプライヤー承認 / 決済 / 出金 |
| GET/POST | `/admin/api/tickets` | チケット割り当て / クローズ |
| GET | `/admin/api/reports/*` | 売上 / 地域 / サプライヤーレポート |
| GET | `/admin/api/monitor/*` | モニターパネル / リソースメトリクス |
| GET | `/admin/api/audit-logs` | 監査ログ |
| PUT | `/admin/api/system/config` | システム設定 |
