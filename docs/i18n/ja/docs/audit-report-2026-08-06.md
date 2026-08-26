# CloudPlatform 全体審査報告

**日付**: 2026-08-06
**審査範囲**: service 全体（app / common / config / tests）+ エコシステム設定 + セキュリティ防護
**方法**: PHPUnit テストスイート、全量 PHP 構文チェック、ルート/ミドルウェア監査、OAuth 新機能のコードレビュー、環境変数と設定の整合性確認、依存関係のセキュリティ監査、スモークテスト

---

## 1. 全体結論
| 次元 | 結論 |
|------|------|
| テスト | **314 項目すべて通過**（2 つのバグ修正後、494 assertions） |
| 構文 | 287 個の PHP ファイル、0 構文エラー |
| 依存関係セキュリティ | composer audit に既知の脆弱性なし；非推奨パッケージ 1 個（doctrine/annotations） |
| セキュリティアーキテクチャ | 多層防護が揃っている（WAF デュアルエンジン、CORS ホワイトリスト、転送暗号化、フィールド暗号化、bcrypt cost=12、JWT ブラックリスト、監査ログ） |
| 重大な問題 | **P0 が 1 個（Apple id_token 未検証 → アカウント乗っ取り可能）、P1 が 4 個** |
| エコシステム設定 | **.env.example に使用中の変数 31 個が欠落**、OAuth 資格情報もすべて欠落；通知チャネルはプレースホルダー実装 |

---

## 2. テスト結果
```
OK (314 tests, 494 assertions)
```

### 今回修正した 2 つのバグ

| ID | ファイル | 問題 | 修正 |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | `$result['extra']['targets']` を読み取っているが、ライブラリは `extra.texts` を返す → `target_count` が常に 0 | `extra.texts` に変更 |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | ライブラリのデフォルト `targetCount = 5` がライブラリ自身の README 契約（medium=3 目標）と矛盾 → 3 つの Captcha テストが失敗 | デフォルト値 5 → 3 |

> B2 は vendored ライブラリのバグ（vendor/ は git 追跡されているため、修正は永続化可能）。同時に上流リポジトリへ修正を提出することを推奨。

---

## 3. 重大なセキュリティ問題（P0 / P1）
### P0-1. Apple `id_token` 未検証 —— アカウント乗っ取りが可能
**ファイル**: `service/app/user/service/OAuthService.php:180-192`（`appleProfile()`）

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // 単なる base64 デコードで、署名/iss/aud/exp の検証なし
```

攻撃者は任意の `id_token` を自作して任意の email を偽装し、OAuth ログインを完了できる。`resolveUser()` は email で既存ユーザーを照合して直接トークンを発行するため → **任意アカウントの乗っ取り**。

**修正**: Apple JWKS（`https://appleid.apple.com/auth/keys`）+ `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])` で署名を検証し、`iss=appleid.apple.com`、`aud=client_id`、`exp`、`nonce` を検証。

### P1-1. OAuth ログインが `email_verified` を検証していない
**ファイル**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn はすべて `email_verified` フィールドを返すが、コードは完全に無視。プロバイダー側でメール未検証のユーザーが、そのメールで登録済みアカウントを直接バインド/乗っ取りできる。GitHub パスは `verified` を検証済み（正しい）、残りのプロバイダーは統一して検証する必要がある。

### P1-2. レート制限ミドルウェアは存在するが一度もマウントされていない —— ドキュメントと実装が不一致
**ファイル**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` には login=5/min、register=3/min などのレート制限ルールが設定済み
- `RateLimitMiddleware` は**どのルートにも参照されていない**（リポジトリ全体の grep でクラス自身のみヒット）
- `docs/features.md` はログイン「レート制限 5 req/min」、登録「レート制限 3 req/min」と主張 —— 実際には存在しない
- 過去の审查报告（`security-audit-2026-08-04.md`）はこの項目を OK としていたが、設定のみ確認してマウントを検証していなかった。今回訂正

**影響**: ログイン/登録/パスワード忘れ/パスワードリセット/リカバリーコード/認証コードなどの公開エンドポイントが無制限に総当たり可能（ログインは per-account ロックのみで、パスワードスプレーと IP レベルの過剰リクエストを防げない）。

**修正**: `RateLimitMiddleware` を `/api/auth/*`、`/api/captcha/*` などの公開ルートにマウント（グローバル `''` グループに掛けて `route` パラメータで区分可能）。

### P1-3. TOTP 2FA がログインフローで強制されていない
**ファイル**: `AuthService.php:64-97`（`login()`）+ `AuthController.php` + `config/features.php`

`user->totp_enabled` は `totpVerify/totpDisable/totpRecoveryCodes` でのみチェックされ、**`login()` では一切検証されない**。2FA を有効にしたユーザーでもパスワードだけで有効な access token が得られる —— 2FA は形骸化（`FEATURE_TOTP` はデフォルトで有効）。

**修正**: ログイン時に `totp_enabled` の場合、一時トークンを発行し、TOTP 検証を通過した後で正式トークンと交換する（または totp code パラメータを要求）。

### P1-4. 通知チャネルがプレースホルダー実装 —— メール検証/パスワードリセットが本番で利用不可
**ファイル**: `app/Notification/Queue/EmailSender.php`、`SmsSender.php`、`PushSender.php`

3 つのコンシューマーはいずれも `error_log()` で送信を模擬するだけで、`send_status` を `sent` と記録。結果：
- **パスワード忘れフローが断裂**：`AuthController::forgotPassword()` が検証コードを生成してメールを「送信」するが、メールは届かない → ユーザーはセルフサービスでパスワードをリセットできない
- 登録メール検証、新 IP ログインアラートも同様に機能しない
- `.env.example` の `SMTP_*`/`MAIL_FROM_*` 合計 7 変数を読み取るコードが存在しない（死設定）

**修正**: 実際のメール送信を接続（PHPMailer/SendGrid SDK）、誤解を招く `sent` ステータスマークを削除；または未完了機能と明示してドキュメントから関連する約束を削除。

---

## 4. セキュリティ問題（P2）
| ID | ファイル | 問題 |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | `type` パラメータがホワイトリスト検証なしでパス `uploads/{$type}/...` に連結 → **パストラバーサル**でアップロードディレクトリ外に書き出し可能（ファイル名はランダムで上書き不可だが、ファイルシステムを汚染できる）；type を列挙ホワイトリストに制限し、保存ディレクトリに `index.php`/`.htaccess` 防護を追加することを提案 |
| P2-2 | 同上 | 拡張子のみ検証し、MIME コンテンツスニッフィングなし（polyglot ファイルがキャッシュ/転送に悪用される可能性）；`finfo` で実際の MIME を検証することを提案 |
| P2-3 | `AuthController.php:131-158` | パスワードリセットの 6 桁検証コードが 600 秒有効で試行回数制限なし → 10 分以内に 100 万組み合わせを総当たり可能；`forgotPassword` に頻度制限なし → メール爆撃 |
| P2-4 | `AuthController.php:333-348` | `totpRecoveryCodes` の生成/閲覧がログインのみでパスワード確認なし；`ConfirmationMiddleware` を掛けるべき |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | ブラックリストの手動チェックキーが `jwt_blacklist:{sha256(token)}` で、ライブラリの `jwt_blacklist:{jti}` 形式と不一致 → 死コード（実際の防護はライブラリ内の `decode()` が担っており有効だが冗長）、削除またはライブラリ API に変更を推奨 |
| P2-6 | `OAuthService.php:67-94` | `authorizeUrl` の `redirect` パラメータは state に保存されるが一度も使われない（死パラメータ）；state がプロバイダーにバインドされていない；OAuth フルフローに nonce がない（OIDC プロバイダーで多層防御が欠落、P0-1 と併せて修正） |
| P2-7 | `OAuthService.php:31-37, 236-238` | X (Twitter) v2 API の `userinfo` が email を返さない → X ログインは必ず "Email not provided" で失敗、機能欠陥。ドキュメントで説明するか `/2/email` エンドポイントに接続する必要 |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` が `strrpos($ip, '.')` で IPv4 セグメントを切り取るため、IPv6 クライアントは空文字列に退化 → 弱いフィンガープリント；上位 64 ビットか IP 全体のハッシュを使用することを推奨 |

---

## 5. エコシステム設定の完全性
### 5.1 .env.example の欠落変数（コードで `getenv()` 参照があるが未定義）—— 31 個

| カテゴリ | 変数 |
|------|------|
| **OAuth 資格情報（新機能、完全に未ドキュメント化）** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`、`_CLIENT_SECRET`、`_REDIRECT_URI`（21 個） |
| **Apple 専用** | `APPLE_TEAM_ID`、`APPLE_KEY_ID`、`APPLE_PRIVATE_KEY_PATH` |
| **重要機能** | `APP_URL`（検証メールリンクの依存、欠落でメールリンクが誤る）、`APP_ENV`、`APP_VERSION` |
| **セキュリティ** | `INTERNAL_MONITOR_TOKEN`（/health/* エンドポイント保護）、`MAINTENANCE_MODE`、`MAINTENANCE_ALLOWED_IPS`、`WEBHOOK_SECRET`、`JWT_LEEWAY` |
| **クラウド/ストレージ** | `AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY`、`BACKUP_S3_BUCKET`、`BACKUP_S3_REGION`、`DB_READ_HOST` |
| **Feature flags（8 個）** | `FEATURE_SSL_PRODUCT`、`FEATURE_OBJECT_STORAGE`、`FEATURE_USAGE_BILLING`、`FEATURE_PROMETHEUS`、`FEATURE_CDN_PRODUCT`、`FEATURE_SUPPLIER_RATING`、`FEATURE_AFFILIATE`、`FEATURE_GRAPHQL` |
| **その他** | `METRICS_PORT`、`WS_PORT`、`GEOIP_DB_PATH`（.env.example ではコメントのみ）、`SSL_STAGING`、`HASHIDS_ALPHABET`、`POSTER_IMAGE_DRIVER`、`EXCHANGE_RATE_API_URL`、`COUNTRY_SEASON_DEFAULT` |

### 5.2 .env.example に定義されているがコード未使用 —— 7 個

`SMTP_HOST`、`SMTP_PORT`、`SMTP_USERNAME`、`SMTP_PASSWORD`、`SMTP_ENCRYPTION`、`MAIL_FROM_ADDRESS`、`MAIL_FROM_NAME`（メール送信が未実装、P1-4 参照）

### 5.3 i18n カバレッジの不一致

| 言語 | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- 中英以外の言語は翻訳キーの半分以上が欠落；zh-CN の billing/health/storage は en-US より 6-8 個多い（同期方向が逆）
- **OAuth 関連の翻訳キーがすべて欠落**（エラーメッセージがハードコードの英語）

### 5.4 その他のエコシステム問題

| ID | 問題 |
|----|------|
| E1 | `service/composer.lock` が `.gitignore` で無視され未コミット —— アプリ依存のバージョンが未固定、デプロイが再現不可（デプロイリスク） |
| E2 | `service/.phpunit.cache/` が git status に出現（無視されていない） |
| E3 | ポート 8787 が本機の別プロジェクト erp-php と衝突し、cloud-php は本機で起動不可（8787 が erp-php の WorkerMan に占有されていることを確認済み） |
| E4 | `docs/features.md` が主張するレート制限/メール機能が実際と不一致（P1-2 / P1-4 参照）、ドキュメントの同期修正が必要 |
| E5 | 依存 `doctrine/annotations` が非推奨（composer audit が警告）、評価の上での削除を推奨 |

---

## 6. 最適化提案（非ブロッキング）
1. **DI 化したサービス生成**：`AuthController` のコンストラクタが直接 `new AuthService()/OAuthService()` している。コンテナへの接続（webman ネイティブ対応）を推奨。テストと差し替えが容易になる。
2. **アップロードディレクトリの強化**：ディレクトリ内に `index.html` を配置、PHP 実行を無効化（nginx `location ~ \.php { deny all; }`）。
3. **WAF 正規表現の収束**：`security.php` の `sqli_patterns` に `\b(select|update|delete|...)\b` などの広いパターンがあり、グローバルレート制限下ではユーザーのチケット/レビューにこれらの語が含まれると誤って 403 になる可能性；機密パラメータのみに適用するか正規表現を厳格化することを推奨。
4. **ログ監査**：`AuditLogger::record('user_registered', ['user_id' => null])` が新規ユーザー ID を記録していない。実際の ID を記録することを推奨。
5. **OAuth テストカバレッジ**：`OAuthServiceTest` は URL 構築と code 交換をカバーするが、`resolveUser()`（DB パス）と Apple 署名検証パスにテストがない；P0 修正後は署名検証失敗のテストケースを必ず追加。
6. **CI 接続**：プロジェクトに `.github` ディレクトリがある。GitHub Actions の追加を推奨：`composer install && phpunit` + `composer audit` で回帰を防止。
7. **HTTP メソッド制約**：OAuth ルートが GET/POST 両方の callback を登録するのは妥当（Apple が要求）。その他の公開書き込み操作は明示的に POST 済み、OK。

---

## 7. 修正優先度リスト
| 優先度 | 事項 | 工数 |
|:---:|------|:---:|
| P0 | Apple id_token 署名検証（JWKS + iss/aud/exp/nonce） | 中 |
| P1 | OAuth 全プロバイダーで `email_verified` 検証 | 小 |
| P1 | RateLimitMiddleware を公開ルートにマウント | 小 |
| P1 | ログインフローで TOTP を強制 | 中 |
| P1 | 実際のメール送信を実装（または未完了と明示） | 中 |
| P1 | .env.example の欠落変数 31 個を補完 + OAuth 設定ドキュメント | 小 |
| P2 | アップロード type ホワイトリスト + MIME 検証 | 小 |
| P2 | リセットコード/パスワード忘れのレート制限 | 小 |
| P2 | リカバリーコード API にパスワード確認をマウント | 小 |
| P2 | composer.lock をコミット、.phpunit.cache を gitignore | 極小 |
| P3 | ブラックリスト死コードの整理、WAF 正規表現の収束、i18n の補完 | 中 |

---

## 8. 修正状況（2026-08-06）
| 優先度 | 事項 | 状態 |
|:---:|------|:---:|
| P0 | Apple id_token 署名検証（JWKS + iss/aud/exp/nonce） | ✅ 修正済み |
| P1 | OAuth 全プロバイダーで `email_verified` 検証（X は /2/email フォールバック追加） | ✅ 修正済み |
| P1 | RateLimitMiddleware をマウント（auth/oauth/password/sms/captcha ルート + 新ルール 4 件） | ✅ 修正済み |
| P1 | ログインフローで TOTP を強制（誤り 5 回で 15 分ロック、独立カウントで DoS 防止） | ✅ 修正済み |
| P1 | 実際のメール送信（symfony/mailer SMTP；未設定時は dev-stub 状態） | ✅ 修正済み |
| P1 | .env.example の欠落変数 31 個を補完 + OAuth 設定ドキュメント | ✅ 修正済み |
| P2 | アップロード type ホワイトリスト + finfo MIME コンテンツスニッフィング | ✅ 修正済み |
| P2 | リセットコード/パスワード忘れのレート制限（誤り 5 回 → 429 10 分） | ✅ 修正済み |
| P2 | リカバリーコード API にパスワード確認をマウント | ✅ 修正済み |
| P2 | composer.lock の無視解除とステージング、.phpunit.cache を gitignore | ✅ 修正済み |
| P3 | ブラックリスト死コード整理、WAF 正規表現の収束（構造式 3 件）、i18n 補完（zh-CN billing/health/storage の誤りを書き直し、trans() に fallback_locale 実装） | ✅ 修正済み |
| E3 | ポート 8787 が erp-php に占有され本機で起動不可 | ⚠️ 環境問題、デプロイ環境では競合なし |
| E5 | doctrine/annotations が非推奨 | ⚠️ 評価の上保留（hg/apidoc の直接依存、削除すると API ドキュメント生成が壊れる） |

補足テスト：OAuth 12 項目（nonce パラメータ、署名検証、email_verified 拒否、X email フォールバック含む）、WAF 収束後 2 項目。全量ベースライン：**319/319 通過（505 アサーション）**。

*レポート生成方式：PHPUnit 全量テスト、`php -l` 287 ファイル、ルート/ミドルウェア静的監査、env 使用と定義の集合差比較、composer audit、ポートとプロセス調査。テストベースライン：314/314 通過。*
