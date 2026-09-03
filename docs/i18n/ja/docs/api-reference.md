# CloudPlatform API インターフェースドキュメント

## 概要

**Base URL:** `https://api.example.com`

**バージョン管理:** API バージョンは URL パスに含まれます（例: `/api/v1/products`）。サポート外のバージョンは `400` を返します。

**認証方式:**

| 端 | 方式 | リクエストヘッダー |
|----|------|--------|
| ユーザー端 | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| 管理端 | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| サプライヤー外部 API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | 署名検証 | `Stripe-Signature: ...` |

**クライアントプラットフォーム:** すべての API リクエストで `X-Client-Platform` ヘッダーの送信を推奨します。対応は `ios/android/macos/windows/linux/web/harmonyos/ipados`。

**多言語:** すべての API リクエストで `Accept-Language` ヘッダー（`zh-CN` / `en-US`）の送信を推奨します。翻訳テキストと JSON 多言語フィールドの戻り値に影響します。欠落時はデフォルトで `en-US`。

---

## 統一レスポンス形式

### 成功

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### ページネーション

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### エラー

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP ステータスコード

| code | 説明 |
|------|------|
| 0 | 成功 |
| 400 | リクエストパラメータエラー / サポート外の API バージョン / サポート外のクライアントプラットフォーム |
| 401 | 未認証 |
| 403 | 権限なし / WAF ブロック |
| 404 | リソースが存在しない（firstOrFail/findOrFail 未ヒット時は一律 404 にマッピング） |
| 413 | リクエストボディが大きすぎる (>10MB) |
| 414 | URL が長すぎる (>2KB) |
| 415 | サポート外の Content-Type |
| 422 | パラメータ検証失敗 |
| 429 | リクエスト頻度制限超過 |

---

## ルートグループとミドルウェアマトリックス

| ルートグループ | ミドルウェア | プレフィックス |
|--------|--------|------|
| 公開 | グローバルミドルウェアチェーン | `/health`, `/api/v1/*` |
| `/health` (内部) | グローバル + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | グローバル + Encryption | `/api/v1/auth/*` |
| `/api/v1` (ユーザー) | グローバル + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (機密) | グローバル + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | サプライヤー外部 API |
| `/admin/api/v1` | グローバル + Encryption + Auth + AdminRole | 管理バックエンド API |
| `/admin/api/v1` (機密) | グローバル + Encryption + Auth + AdminRole + Confirmation | 機密管理操作 |

---

## 1. 公開エンドポイント
### ヘルスチェック

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### サービスステータス

```
GET /api/v1/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### 商品

```
GET /api/v1/products
  パラメータ: category_id, region_id, keyword, supplier_id, page (デフォルト1), page_size (デフォルト20, 最大50)
  → ページネーション付き商品リスト (category, skus.regionPrices を含む)

GET /api/v1/products/search
  パラメータ: q (必須), page
  → Elasticsearch 全文検索

GET /api/v1/products/{id}
  → 商品詳細 (category, skus, images, reviews を含む)

GET /api/v1/products/{productId}/reviews
  → レビューリスト + avg_rating + total + distribution
  ステータス列挙: pending(審査待ち)/approved(承認済み)/rejected(却下済み)、approved のみ返す
```

### ドメイン

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → 利用可能な TLD リスト (Redis キャッシュ 1h)
```

### ヘルプセンター

```
GET /api/v1/help
  パラメータ: category, page
  ヘッダー: Accept-Language (en-US / zh-CN)
  → ページネーション付きヘルプ記事

GET /api/v1/help/categories
  → 記事カテゴリリスト

GET /api/v1/help/{slug}
  → 記事詳細
```

---

## 2. 認証エンドポイント
### 認証コード（CAPTCHA）

```
POST /api/v1/captcha/create
  ヘッダー: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### 登録

```
POST /api/v1/auth/register
  ヘッダー: X-Encrypted: 1
  ボディ(暗号化): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

レート制限: 3 req/min
```

- `deviceFingerprint`（任意）: 登録時にデバイスフィンガープリントを記録し、ログイン/リフレッシュ時に検証。未送信の場合はフィンガープリントバインドをスキップ
- email/phone は保存前に Encryptable の決定的暗号化（ECB、暗号文での等値クエリ）が適用され、一意性検証とログインクエリはすべて暗号文で実行

### ログイン

```
POST /api/v1/auth/login
  ヘッダー: X-Encrypted: 1
  ボディ(暗号化): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

レート制限: 5 req/min、5 回失敗で 15 分ロック
```

- `login` は暗号文で等値クエリ（Encryptable 決定的暗号化）。平文クエリでは暗号化カラムにヒットしない

### Token リフレッシュ

```
POST /api/v1/auth/refresh
  ヘッダー: X-Encrypted: 1
  ボディ(暗号化): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` が登録時の記録と一致しない → 401 `Device mismatch`。リフレッシュトークンは暗号文ハッシュでクエリ

### OAuth

対応プロバイダー: google, apple, facebook, x, microsoft, linkedin, github
（.env の `{PROVIDER}_OAUTH_CLIENT_ID` 等の設定で有効化が決定）

```
GET /api/v1/auth/{provider}            → { url }        # 認可ページへのリダイレクト（PKCE/nonce でリプレイ防止）
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  ボディ: { code, state }
```

- Apple/Microsoft は id_token を返し、サーバー側で JWKS により署名、iss/aud/exp/nonce を検証
- すべてのプロバイダーで `email_verified=true` がログインの必須条件、それ以外は 422
- `state` の欠落または不一致 → 422（CSRF 防止、5 分で期限切れ）
- OAuth フローはレート制限: 60 秒あたり 10 回（redirect + callback）

### パスワードリセット

```
POST /api/v1/auth/forgot-password
  ボディ: { email }
  → 認証コードメールを送信

POST /api/v1/auth/reset-password
  ボディ: { email, code, password }
  → リセット成功
  → エラー累計 5 回 → 429 レート制限 10 分
```

### メール検証

```
GET /api/v1/auth/verify-email?token=xxx
  → 検証成功
```

### SMS 検証

```
POST /api/v1/auth/send-sms
  ボディ: { phone }
  → SMS 認証コードを送信 (60s クールダウン)
```

### TOTP 二段階認証

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # 未永続化、10 分以内に verify で有効化
POST /api/v1/user/totp/verify       ボディ: { code } → { verified: true }   # 初回有効化時に有効化成功メッセージを返す
POST /api/v1/user/totp/disable      ボディ: { password }             # パスワード確認が必要、それ以外は 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # 毎回 8 個のワンタイムコードを生成、パスワード確認が必要、それ以外は 403
POST /api/v1/auth/login/recovery    ボディ: { login, password, recovery_code }
```

- ユーザーが TOTP を有効化した後のログインは `totp_code` 必須、それ以外は 401
- TOTP 連続エラー 5 回 → そのユーザーは 15 分ロック（login_lock）

---

## 3. ユーザーエンドポイント（認証必須）
### プロフィール

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  ボディ: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC 実名認証

```
POST /api/v1/user/kyc
  ボディ: { id_type, id_number, real_name, front_image, back_image }
```

### 残高

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  パラメータ: page
  → 残高変動履歴
```

### アドレス管理

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  ボディ: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### セッション管理

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → 指定セッションを失効

DELETE /api/v1/user/account
  ボディ: { confirm_password }
  → GDPR アカウント削除
```

### 通知

```
GET /api/v1/user/notifications
  パラメータ: page
  → ページネーション付き通知リスト

POST /api/v1/user/notifications/{id}/read
  → 既読にする

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  ボディ: { email: {order_paid: true, ...}, push: {...} }
```

### メール

```
POST /api/v1/user/resend-verify-email
  → 検証メールを再送信
```

### ファイルアップロード

```
POST /api/v1/upload
  ボディ: multipart/form-data { file, type: avatar/kyc/attach }
  制限: avatar 2MB, kyc 5MB, attach 10MB
  許可: jpg, jpeg, png, gif, pdf
  説明: タイプホワイトリスト検証 + finfo コンテンツスニッフィング（拡張子と MIME が不一致 → 422）
```

---

## 4. カートと注文
### カート

```
POST /api/v1/cart
  ボディ: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  ボディ: { quantity }
```

> 金額フィールドの規約（D4/P4.2 決定事項）: すべての金額は string、小数 4 桁（例 "9.9900"）、number/float 禁止——
> MySQL DECIMAL カラムの PDO 出力と一致させ、精度は 4dp 文字列自体が担います。注文/残高/レポートの全エンドポイントに適用。

### 注文

```
POST /api/v1/orders
  → カートから注文を作成
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  パラメータ: page, status (pending/paid/provisioning/completed/refunded、不正な値は 400)
  → 自分の注文リスト

GET /api/v1/orders/{id}
  → 注文詳細 (items, timeline を含む)

GET /api/v1/orders/{id}/payment-methods
  → 利用可能な決済チャネル + 各チャネルの実支払金額

POST /api/v1/orders/{id}/pay    🔒 パスワード確認
  ボディ: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### クーポン

```
POST /api/v1/coupons/validate
  ボディ: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（例 "2.0000"）

422: 無効/期限切れ/利用条件を満たしていない
```

### 請求書

```
GET /api/v1/invoices
  パラメータ: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF ダウンロード
```

---

## 5. リソース管理
```
GET /api/v1/resources
  パラメータ: page, status
  → 自分のリソースリスト

GET /api/v1/resources/{id}
  → リソース詳細

GET /api/v1/resources/{id}/status
  → リソースの現在のステータス + メトリクス

GET /api/v1/resources/{id}/console
  → VNC/コンソール URL

POST /api/v1/resources/batch
  ボディ: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. DNS 管理
```
GET /api/v1/dns/{domain}
  → DNS レコードリスト

POST /api/v1/dns/{domain}/records
  ボディ: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 パスワード確認
```

---

## 7. チケット
```
POST /api/v1/tickets
  ボディ: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  パラメータ: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  ボディ: { content }
```

---

## 8. サプライヤー（内部 API）
```
POST /api/v1/supplier/apply
  ボディ: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → 決済明細リスト

POST /api/v1/supplier/withdraw    🔒 パスワード確認
  ボディ: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  ボディ: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. サプライヤー外部 API
**認証:** `Authorization: Bearer sk_xxx...`（SHA256 検証）

**レート制限:** 120 req/min（出金は 10 req/min）

```
GET /api/v1/supplier/external/orders
  パラメータ: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → 注文詳細（自サプライヤー関連のみ）

GET /api/v1/supplier/external/resources
  パラメータ: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  パラメータ: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  ボディ: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  パラメータ: page
```

---

## 10. 管理バックエンド API
**認証:** JWT Bearer Token + Admin ロール

### ダッシュボード

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### ユーザー管理

```
GET /admin/api/v1/users              パラメータ: page, status, keyword
GET /admin/api/v1/users/export       → Excel ダウンロード
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  ボディ: { status }
```

### KYC 審査

```
GET /admin/api/v1/kyc                パラメータ: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 パスワード確認
  ボディ: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 パスワード確認
  ボディ: { confirm_password, reason }
```

### 商品管理

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 パスワード確認
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV ダウンロード
POST /admin/api/v1/products/import        → CSV アップロード upsert
```

### 注文管理

```
GET /admin/api/v1/orders              パラメータ: page, status, keyword
GET /admin/api/v1/orders/export       → Excel ダウンロード
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 パスワード確認
  ボディ: { confirm_password, amount?, reason }
```

### 決済管理

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions  パラメータ: page, channel, status
GET /admin/api/v1/payments/reconcile     パラメータ: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run  パラメータ: date; 日次照合をトリガー
```

### リソースと開通

```
GET /admin/api/v1/provisioning/tasks              パラメータ: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  ボディ: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 パスワード確認
GET /admin/api/v1/provisioning/hosts
```

### サプライヤー管理

```
GET /admin/api/v1/suppliers                 パラメータ: page, status
GET /admin/api/v1/suppliers/export          → Excel ダウンロード

POST /admin/api/v1/suppliers/{id}/approve    🔒 パスワード確認
POST /admin/api/v1/suppliers/{id}/settle     🔒 パスワード確認
  ボディ: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 パスワード確認
```

### サプライヤー API Key

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  ボディ: { name }
  ← { api_key: "sk_xxx...", prefix } (1 回だけ表示)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### チケット管理

```
GET /admin/api/v1/tickets                  パラメータ: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign     ボディ: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### ドメイン管理

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  ボディ: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones             パラメータ: page
GET /admin/api/v1/domains/transfers         パラメータ: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### 通知管理

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  ボディ: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log         パラメータ: page
```

### クーポン

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  ボディ: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### ヘルプ記事

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  ボディ: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → ソフト削除 (status=archived)
```

### クラウドベンダー API

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  ボディ: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → 無効化 (status=disabled)
```

### Webhook 管理

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  ボディ: { url }
DELETE /admin/api/v1/webhooks              ボディ: { id }
POST /admin/api/v1/webhooks/test           ボディ: { url }
```

### レポート

```
GET /admin/api/v1/reports/revenue           パラメータ: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL) と bcmath 集計が一致）
GET /admin/api/v1/reports/supplier          パラメータ: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region            パラメータ: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### モニタリング

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### 監査ログ

```
GET /admin/api/v1/audit-logs                パラメータ: page, user_id, action, from, to
  → ページネーション付き監査ログ (client_platform を含む)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  ボディ: { action: enable/disable/toggle/reset }
```

### システム設定

```
PUT /admin/api/v1/system/config              🔒 パスワード確認
```

### 商品インポートエクスポート

```
GET /admin/api/v1/products/export           → CSV ダウンロード
POST /admin/api/v1/products/import          → CSV アップロード upsert
```

### サプライヤー + ユーザーエクスポート

```
GET /admin/api/v1/suppliers/export          → Excel ダウンロード
GET /admin/api/v1/users/export              → Excel ダウンロード
GET /admin/api/v1/orders/export             → Excel ダウンロード
```

---

## 11. SSL 証明書
### ユーザー端

```
GET /api/v1/ssl/plans
  → SSL プランリスト（DV/OV/EV、価格は register/renew/transfer を含む）

GET /api/v1/ssl-certs
  → 自分の証明書リスト（status: pending/active/expired/revoked を含む）

GET /api/v1/ssl-certs/{id}
  → 証明書詳細（ドメイン、発行機関、有効期間、更新ステータス）

GET /api/v1/ssl-certs/{id}/download
  → 証明書ファイルをダウンロード（証明書チェーン + 秘密鍵）

POST /api/v1/ssl-certs/{id}/auto-renew
  ボディ: { auto_renew: true/false }
  → 自動更新の切り替え
```

### 管理端

```
GET /admin/api/v1/ssl/plans              → プランリスト
POST /admin/api/v1/ssl/plans             → プラン作成
PUT /admin/api/v1/ssl/plans/{id}         → プラン更新
DELETE /admin/api/v1/ssl/plans/{id}      → プラン削除
GET /admin/api/v1/ssl/certs              → 全証明書
POST /admin/api/v1/ssl/certs/{id}/revoke → 証明書の失効
```

---

## 12. オブジェクトストレージ
S3 互換オブジェクトストレージ。署名付き URL でアップロード/ダウンロードし、シークレットは外部に出しません。

```
GET /api/v1/storage/buckets
  → 自分のバケットリスト（使用量、ステータス）

GET /api/v1/storage/buckets/{id}
  → バケット詳細

POST /api/v1/storage/buckets/{id}/presign-upload
  ボディ: { filename, content_type, size }
  → { upload_url, object_key } 事前署名付きアップロード URL（期限付き）

POST /api/v1/storage/buckets/{id}/presign-download
  ボディ: { object_key }
  → 事前署名付きダウンロード URL（期限付き）

GET /api/v1/storage/buckets/{id}/credentials
  → 一時アクセス資格情報（短期有効、SDK 直接アップロード用）
```

---

## 13. CDN アクセラレーション
### ユーザー端

```
GET /api/v1/cdn/domains
  → 自分の CDN ドメインリスト（オリジン、ステータス、プラン）

POST /api/v1/cdn/domains
  ボディ: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
            origin_type (server|storage), origin_value, cert_config? }
  → CDN ドメイン作成（プロバイダー側で作成しオリジンをバインド）
  → provider_type=aliyun|tencent の場合は ICP 登録が必要（未登録は 4002）
  → レスポンスに requires_icp_registration ヒントフィールドを含む
  → 資格情報の解決：まずドメインにバインドされたアカウント（provider_account_id）、
    なければ code=cdn-{provider_type} のアクティブな provider_apis アカウント、
    いずれもなければ env 設定にフォールバック

GET /api/v1/cdn/domains/{id}
  → CDN ドメイン詳細

DELETE /api/v1/cdn/domains/{id}
  → CDN ドメイン削除（プロバイダー側ドメインを無効化、冪等）

POST /api/v1/cdn/domains/{id}/purge
  ボディ: { urls: ["https://cdn.example.com/path"] }
  → キャッシュ削除（重複 URL は自動的に除去、冪等、最大 100 個）

GET /api/v1/cdn/domains/{id}/stats
  → ドメイン概要（cdn_domain / provider_type / plan / status / purged_at）
```

### 管理端

```
GET /admin/api/v1/cdn/domains            → 全 CDN ドメイン（所属ユーザー含む）
PUT /admin/api/v1/cdn/domains/{id}       → ドメインプラン更新（plan ホワイトリスト: standard | pro | enterprise）
```

管理側の CDN ルートは `RbacMiddleware('cdn.manage')` を適用し、プラン変更は監査ログに記録される（`admin_cdn_update_plan`）。プロバイダーアカウントの資格情報は `/admin/api/v1/providers` CRUD で管理する（RbacMiddleware `provider.config`、`code` は `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent` と規定、資格情報は Encryptable で暗号化して保存）。

### CDN エラーコード

| code | 説明 |
|------|------|
| 4001 | CDN パラメータ欠落/不正（urls が空、provider_type が不正、ドメイン形式エラー） |
| 4002 | ドメインが ICP 登録未完了（Aliyun/Tencent API が拒否した場合にマッピング） |
| 4003 | CDN プロバイダー資格情報が未設定（アカウント欠落/無効、厳格スナップショットにより静かに切り替えない） |
| 4005 | CDN キャッシュパージ失敗 |
| 5001 | CDN プロバイダー API 呼び出し失敗 |

> 非自ユーザーの CDN リソース（他人の/存在しないリソース）は一律 **404** を返す（findOrFail マッピング、リソースの存在性を漏らさない）、独立したビジネスコードはなし。

---

## 14. 従量課金
```
GET /admin/api/v1/billing/rates          → 課金レートリスト（リソースタイプ/スペック別）
POST /admin/api/v1/billing/rates         → レート作成
PUT /admin/api/v1/billing/rates/{id}     → レート更新
DELETE /admin/api/v1/billing/rates/{id}  → レート削除
GET /admin/api/v1/billing/usage          → 使用量サマリー（ユーザー/リソース単位で集計）
```

課金パイプライン: ResourceMonitor が 5 分ごとに収集 → UsageAggregator が毎時間集計 → BillingEngine が日次で課金。残高不足時はリソースをサスペンド。

---

## 15. アフィリエイト
### ユーザー端

```
GET /api/v1/affiliate/summary
  → コミッション概要（累計/未決済/出金可能、リンク数、コンバージョン率）

POST /api/v1/affiliate/links
  ボディ: { source? }
  → プロモーションリンクを生成（?ref=CODE）

GET /api/v1/affiliate/earnings
  パラメータ: status, page
  → コミッション明細（注文帰属、割合、ステータス: pending/approved/paid）

POST /api/v1/affiliate/payout
  ボディ: { amount, method }
  → 出金申請を発起
```

### 管理端

```
GET /admin/api/v1/affiliate/plans                → コミッションプランリスト
POST /admin/api/v1/affiliate/plans               → コミッションプラン作成
GET /admin/api/v1/affiliate/earnings             → 全コミッション記録
POST /admin/api/v1/affiliate/earnings/{id}/approve → コミッション審査
GET /admin/api/v1/affiliate/payouts              → 出金申請リスト
POST /admin/api/v1/affiliate/payouts/{id}/approve → 出金の審査/振込
```

---

## 16. GraphQL
```
POST /graphql
  → 公開クエリ（商品、ドメイン、ヘルプなどの読み取り専用データ）
  制限: クエリ深度 5 層、複雑度 100

POST /api/v1/graphql                          🔒 認証必須
  → 完全クエリ（ユーザーデータを含む）
```

**機密操作は REST のみ:** 決済、出金、返金、KYC 審査は GraphQL では行いません。

---

## 17. サプライヤー評価と商品レビュー
### 公開

```
GET /api/v1/regions
  → 利用可能な地域リスト（通貨/タイムゾーンを含む）

GET /api/v1/suppliers/{supplierId}/ratings
  → サプライヤー評価リスト（4 次元: 品質/サポート/納品スピード/コスパ、approved のみ返す）
```

### ユーザー端（認証必須）

```
POST /api/v1/products/{productId}/reviews
  ボディ: { rating, content, images? }
  → 商品レビューを投稿（注文ごとに 1 回、審査後に表示）

POST /api/v1/supplier/ratings
  ボディ: { supplier_id, quality, support, delivery_speed, value, comment? }
  → サプライヤー評価を投稿（注文ごとに 1 回）

GET /api/v1/supplier/ratings/me
  → 自分の評価履歴
```

### 管理端

```
GET /admin/api/v1/suppliers/{id}/ratings          → 全評価（pending を含む）
POST /admin/api/v1/suppliers/ratings/{id}/approve → 審査承認
POST /admin/api/v1/suppliers/ratings/{id}/hide    → 非表示
```

---

## 18. 決済 Webhook
```
POST /api/v1/payments/webhook/stripe
  ヘッダー: Stripe-Signature: ...
  → Stripe コールバック（支払い成功/返金/紛争）、署名検証失敗は 400
```

---

## 19. WebSocket イベント
**接続:** `ws://host:8282`（docker デプロイでは WS が nginx リバースプロキシ経由、接続アドレスは `ws://host/ws/`、8282 はコンテナ内のみ公開）

認証は接続後の最初のメッセージで行います（token は URL/アクセスログに入れない）: 接続確立後、まず `auth` メッセージを送信する必要があり、30 秒以内に認証しないと切断されます。認証失敗時は `error` を返して切断します。

### クライアント → サーバー

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### サーバー → クライアント

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### プッシュイベント

| イベント | データ | トリガータイミング |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | 支払い成功 |
| `resource.provisioned` | `{resource_id, type, ip_address}` | リソース開通完了 |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | リソースが間もなく期限切れ |
| `ticket.updated` | `{ticket_id, title, status}` | チケットステータス変更 |
| `notification.new` | `{notification_id, title, body}` | 新しい通知 |

---

## 20. エラーコードリファレンス
| code | 説明 |
|------|------|
| 400 | パラメータエラー / サポート外の API バージョン / サポート外のクライアントプラットフォーム |
| 401 | 未認証 / Token 期限切れ / 無効な API Key / デバイスフィンガープリント不一致（Device mismatch） |
| 403 | 権限なし / サプライヤーロールではない / WAF ブロック / パスワード確認失敗 |
| 404 | リソースが存在しない（firstOrFail/findOrFail 未ヒット時は一律 404 にマッピング） |
| 413 | リクエストボディが 10MB を超過 |
| 414 | URL が 2KB を超過 |
| 415 | Content-Type がホワイトリスト外 (application/json, multipart/form-data, x-www-form-urlencoded のみ許可) |
| 422 | パラメータ検証失敗（メール登録済み / 在庫不足 / 出金可能残高不足 / 申請提出済み） |
| 429 | リクエスト頻度制限超過 |
| 500 | サーバーエラー |

### よくある 422 メッセージ

| メッセージ | エンドポイント |
|------|------|
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
