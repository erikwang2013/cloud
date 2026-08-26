# Security Audit Report — cloud-php

**日付**: 2026-08-04
**範囲**: プロジェクト全体（service + admin）
**方法**: 設定レビュー、ミドルウェア監査、コード検査

---

## 総合評価: **B+（良好、修正すべきギャップ 4 件）**

このプロジェクトは堅固な多層セキュリティアーキテクチャを持つ。31 個の検出器を持つ erikwang2013/security-php プラグインが最大の特長である。以下に詳細を示す。

---

## 1. 実施済みの防護（検証済み）

### 転送と暗号化
| メカニズム | 実装 | 状態 |
|-----------|---------------|--------|
| API 転送暗号化 | erikwang2013/encryption による AES-256-GCM | OK |
| DB フィールド暗号化 | erikwang2013/encryptable による AES-128-ECB（決定論的、クエリ可能） | OK |
| キーローテーション | ENCRYPTION_PREVIOUS_KEYS のカンマ区切り旧キー | OK |
| ID 混淆 | 設定可能なソルトと最小長 12 の Hashids | OK |
| パスワードハッシュ | bcrypt cost=12、最小長 8 | OK |

### 認証とアクセス制御
| メカニズム | 実装 | 状態 |
|-----------|---------------|--------|
| JWT 認証 | erikwang2013/jwt-webman、HS256、access TTL 900s + refresh 30d | OK |
| JWT ブラックリスト | Redis バックアップのトークン失効 | OK |
| MFA/TOTP | 6 桁、30 秒周期、Google/MS Authenticator 互換 | OK |
| RBAC | Admin AccessControl ミドルウェア + plugin\admin\api\Auth::canAccess() | OK |
| セッション保存 | Redis (db2) | OK |
| キャプチャ | ログイン/登録用の erikwang2013/poster-php クリックテキストキャプチャ | OK |

### 攻撃検知（WAF — 二重層）
| 層 | カバレッジ | 状態 |
|-------|----------|--------|
| カスタム WafMiddleware | SQLi、XSS、CMDi、パストラバーサル、ヘッダーインジェクション、SSRF、NoSQLi、オープンリダイレクト | OK |
| Security Plugin（31 検出器） | 上記すべて + XXE、デシリアライゼーション、LDAP、メールヘッダー、SSTI、JWT 攻撃、Host ヘッダー、リクエストスモークリング、GraphQL、XPATH、JNDI/Log4Shell、SSI、CSV インジェクション、データ漏洩、prototype pollution、WebSocket、CORS バイパス、DNS rebinding | OK |

### レート制限（service のみ）
| ルート | レート | バースト | 期間 | 状態 |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### その他の防護
| メカニズム | 実装 | 状態 |
|-----------|---------------|--------|
| リクエストサイズ制限 | 10MB ボディ、2KB URL | OK |
| Content-Type 検証 | ホワイトリスト: JSON、multipart、form-urlencoded | OK |
| データベース準備文 | PDO::ATTR_EMULATE_PREPARES = false | OK |
| DB 読み書き分離 | 書き込みはマスター、読み取りはレプリカ、スティッキーセッション | OK |
| 監査ログ | 独立した監査 DB、LogSanitizer が機密フィールドを秘匿化 | OK |
| メンテナンスモード | ホワイトリスト IP はバイパス、その他は 503 + Retry-After | OK |
| IP 自動封禁 | 60 秒内に違反 5 回で 15 分封禁 | OK |
| SQL 厳密モード | データの切り詰めと暗黙の型変換を防止 | OK |

---

## 2. ギャップと推奨事項

### ギャップ 1（中）: CORS が任意の Origin をミラーリング
**ファイル**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

クライアントが送ってくる Origin をそのままエコーバックしており、事実上あらゆるウェブサイトが認証済みクロスオリジンリクエストを送れる。セキュリティプラグインの cors 検出器が一部のヘッダーインジェクションを捕捉する可能性はあるが、ミドルウェア自体には Origin ホワイトリストがない。

**修正**: ホワイトリストチェックを追加。Origin が許可リストにない場合は `Access-Control-Allow-Origin: null` を返すか、ヘッダー自体を省略する。

### ギャップ 2（中）: セキュリティレスポンスヘッダー欠落
service も admin も重要な HTTP セキュリティヘッダーを設定していない：

| ヘッダー | 推奨 | 現在 |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | 欠落 |
| X-Content-Type-Options | nosniff | 欠落 |
| X-Frame-Options | DENY or SAMEORIGIN | 欠落 |
| Content-Security-Policy | nonce/hash 付きポリシー | 欠落 |
| X-XSS-Protection | 1; mode=block | 欠落 |
| Referrer-Policy | strict-origin-when-cross-origin | 欠落 |
| Permissions-Policy | カメラ/マイク/位置情報を制限 | 欠落 |

**推奨**: service と admin の両方のミドルウェアスタックに SecurityHeadersMiddleware を追加。高インパクト、低コストの修正。

### ギャップ 3（低）: admin/config/security.php にレート制限がない
**ファイル**: `admin/config/security.php`

admin panel には rate_limits 設定がない。admin の WAF ミドルウェアはリクエストサイズ/Content-Type 制限のみをチェックする。admin ログインへの総当たり攻撃はアプリケーション層でレート制限されない。

**推奨**: admin/config/security.php に rate_limits を追加するか、RateLimitMiddleware を admin ルートに適用する。

### ギャップ 4（低）: GeoBlockMiddleware が定義されているが有効化されていない
**ファイル**: `service/common/security/GeoBlockMiddleware.php`

ミドルウェアは存在し機能的だが、`service/config/middleware.php` に登録されていない。ジオブロッキングが必要ならスタックに追加する。

### ギャップ 5（情報）: 二重 WAF のオーバーヘッド
WafMiddleware（カスタム、40+ の正規表現パターン）と SecurityMiddleware（プラグイン、31 検出器）がすべてのリクエストで実行される。SQLi、XSS、コマンドインジェクション、パストラバーサル、ヘッダーインジェクション、SSRF、NoSQLi、オープンリダイレクトのパターンカバレッジが大幅に重複する。

**推奨**: セキュリティプラグインの方が包括的（31 検出器 vs 8 カテゴリ）で、IP ブラックリスト、フィールドホワイトリスト、ログ重複排除を備えている。カスタム WafMiddleware を削除してプラグインのみに依存するか、少なくとも WafMiddleware から重複パターンを削除することを検討すべき。

### ギャップ 6（情報）: Validator クラスが最小限
**ファイル**: `service/common/helper/Validator.php`

required()、email()、minLength() しかない。欠落: 最大長、数値検証、文字列サニタイズ、URL 検証、パターンマッチング。フレームワークレベルの検証を使わないコントローラーは、不正な入力を受け入れるリスクがある。

---

## 3. セキュリティプラグイン — 31 検出器の状態

| # | 検出器 | モード | 備考 |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF が textarea コンテンツと一致するため、log のまま維持 |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} が Vue/Angular テンプレートと一致 |
| 13 | nosql_injection | **log** | $ne/$gt がシェル変数/LaTeX と一致 |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | ループバック Host（127.0.0.1/localhost）は 403 にしない（開発/テストの通常パターン、記録のみ） |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

31 検出器すべて有効。4 個はログのみモード（誤検知リスクを文書化済み）。設定は正しい。

---

## 4. ミドルウェア実行順（service）

```
1. VersionMiddleware          — API バージョンヘッダー解析
2. CorsMiddleware              — CORS ヘッダー（過度に寛容、ギャップ 1 を参照）
3. ClientPlatformMiddleware    — OS/プラットフォーム検出
4. WafMiddleware               — カスタム WAF（40+ の正規表現パターン）
5. SecurityMiddleware           — プラグイン WAF（31 検出器）
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — ID デコード
8. MaintenanceMiddleware       — メンテナンスモードチェック
```

---

## 5. サマリー

| カテゴリ | 評価 | 主要な問題 |
|----------|-------|------------|
| 攻撃検知 | **A** | 31 検出器、二重 WAF 層（冗長だが徹底的） |
| 認証 | **A-** | bcrypt+MFA+JWT ブラックリスト、admin のレート制限が欠落 |
| 転送セキュリティ | **B+** | AES-256-GCM は良好、HSTS/CSP ヘッダーが欠落 |
| 入力検証 | **B** | WAF が攻撃を捕捉、アプリレベル検証は手薄 |
| アクセス制御 | **A-** | RBAC + セッションチェック、CORS が過度に寛容 |
| 監査/ログ | **A** | 独立した監査 DB、機密フィールドの秘匿化 |
| レート制限 | **B+** | service は良好に設定、admin には欠落 |

**優先修正順:**
1. セキュリティレスポンスヘッダーを追加（HSTS、CSP、X-Frame-Options など）
2. CORS を任意の Origin のミラーリングではなくホワイトリストに制限
3. admin panel にレート制限を追加
4. ジオブロッキングが必要なら GeoBlockMiddleware を有効化
5. WAF 層の統合を検討してリクエストごとの正規表現オーバーヘッドを削減

---

## 6. 実施済みの是正（2026-08-04）

### 修正済み
| ギャップ | 修正 | 変更ファイル |
|-----|-----|---------------|
| CORS が任意の Origin をミラーリング | `CORS_ALLOWED_ORIGINS` env 変数によるホワイトリストモード、`*.example.com` ワイルドカードと全許可の `*` に対応 | `service/common/security/CorsMiddleware.php` |
| セキュリティヘッダー欠落 | service と admin の両スタックに新しい `SecurityHeadersMiddleware` を追加：X-Content-Type-Options、X-Frame-Options、Referrer-Policy、X-XSS-Protection、Permissions-Policy、HSTS（env でオプトイン） | `service/common/security/SecurityHeadersMiddleware.php`、`admin/app/middleware/SecurityHeadersMiddleware.php` |
| admin にレート制限なし | admin panel に `rate_limits` 設定 + `RateLimitMiddleware` を追加（デフォルト 60/min、login 5/min） | `admin/config/security.php`、`admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock が有効化されていない | service のミドルウェアスタックに `GeoBlockMiddleware` を登録 | `service/config/middleware.php` |

### 新しい環境変数
| 変数 | 用途 | デフォルト |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | カンマ区切りの許可 Origin | （空 = すべて拒否） |
| `SECURITY_HSTS_ENABLE` | HSTS ヘッダーの有効化 | false |
| `SECURITY_HSTS_VALUE` | HSTS ヘッダー値 | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | X-Frame-Options 値 | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | ブロックする国コード（ISO 3166-1） | （空 = 無効） |
| `GEOIP_DB_PATH` | GeoLite2 .mmdb パス | storage_path('geoip/GeoLite2-Country.mmdb') |

### 更新後のミドルウェアパイプライン

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
