# CloudPlatform 审查报告（第 3 ラウンド、2026-08-06）

> 範囲：全体実測（サービス起動 + スモークテスト）+ 詳細コード検査 + エコシステム/セキュリティ設定の完全性確認。
> 今回のラウンドは「静的読解」から「**起動可能**」へ前進：起動レベルの P0 を 5 件と実行レベルの P0/P1 を 3 件修正し、完全なミドルウェアチェーンでスモークテストを通過。
> テストベースライン：service **316/316 通過（502 アサーション）**；admin **67/67 通過（124 アサーション）**。

---

## 1. 今回の修正リスト（すべて実測検証済み）
### P0 — 起動レベル（worker クラッシュ / サイト全体が利用不可）

| # | 問題 | 根本原因 | 修正 |
|---|------|------|------|
| 1 | `A facade root has not been set` → 起動クラッシュ | bootstrap が Illuminate Facade にコンテナを設定していない | `Facade::setFacadeApplication($capsule->getContainer())`（bootstrap.php:149） |
| 2 | `Target class [events] does not exist` | イベントリスナに Event Facade を使用しているが、コンテナに events サービスがない | `Dispatcher` インスタンスに変更：`$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()`（3 個のリスナ） |
| 3 | `Class support\SentryBootstrap not found` | composer.json の psr-4 に `support\` マッピングがない | `"support\\": "support/"` を追加 + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` が空 → 暗号化サービスがクラッシュ | .env の空値（phpdotenv createUnsafeMutable が注入を上書き） | 32 バイト base64 キーを生成して .env に書き込み |
| 5 | 全 `/api/*` ルートが 404 | `ApiRequest::path()` が `/api/xxx` を `/api/v1/xxx` に書き換える一方、ルート登録にバージョン接頭辞がない | 書き換えロジックを削除し、パスをそのまま維持（バージョン検証は VersionMiddleware が X-Api-Version ヘッダーに基づき実施） |
| 6 | `Class "ErikJwt\JWTFactory" not found` | 存在しない `ErikJwt\` 名前空間を使用 | パッケージ内の実際の名前空間 `Erikwang2013\Jwt\*` に変更 |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` が null を返す → `createFromConfig()` が型エラー | webman の `Config::loadFromDir` はプラグインディレクトリに `app.php` が必要（なければディレクトリ全体をスキップ）；jwt プラグインディレクトリに欠落 | `config/plugin/erikwang2013/jwt/app.php` を追加（`'enable' => true`、vendor テンプレートと一致） |

### P0 — 実行レベル（最初のリクエストで即 500）

| # | 問題 | 根本原因 | 修正 |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware が ext-redis `\Redis::get()` を直接静的呼び出し | `\support\Redis::get/setex/incr` に変更 |
| 9 | `Class support\Redis not found` | `support\Redis` は webman スケルトン層（webman/webman パッケージ）に属し、本プロジェクトは framework のみインストールのため欠落 | `support/Redis.php` を新規作成（下層は既存の illuminate/redis + config/redis.php） |
| 10 | AuthController の `Illuminate\Support\Facades\Redis::*` が**素の phpredis インスタンス**（未接続）に解決 → "server went away" | コンテナに `redis` バインドがないため、自動配線が `Redis` クラスにフォールバック | bootstrap で `$container->singleton('redis', fn() => support\Redis::manager())` を登録 |
| 11 | `Call to undefined function storage_path()` | `storage_path()` はスケルトンのヘルパーに属し、本プロジェクトには欠落 | bootstrap にヘルパーを追加（`base_path()/storage`、function_exists ガード付き） |

### P1 — 境界検証

| # | 問題 | 修正 |
|---|------|------|
| 12 | `/api/auth/refresh` が refresh_token 欠落時に TypeError 500 | AuthController::refresh に `is_string` 検証を追加 → 422 |

### 一時状態の復元

- `config/server.php`（8787）、`config/process.php`（9100/8282）、`config/middleware.php`（完全な 11 層チェーン）を git から復元
- bootstrap.php の `[AUDIT]` デバッグ error_log を削除

---

## 2. スモークテスト結果（完全なミドルウェアチェーン、ポート 8787）
| エンドポイント | 結果 | 説明 |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | click 認証コード画像を返す |
| POST /api/auth/login（認証コード欠落） | 422 | captcha 検証が有効 |
| POST /api/auth/register（空引数） | 422 | フィールド検証が有効 |
| POST /api/auth/refresh（token 欠落） | 422 | 今回の修正項目 |
| POST /api/auth/forgot-password | 500（DB 接続拒否） | **環境ギャップ**：.env に DB_PASSWORD がない、§四 を参照 |
| GET with X-Api-Version: v99 | 400 | VersionMiddleware が有効 |
| GET /api/nonexistent | 404 | 正常な 404 ページ |

Redis パス（認証コード、レート制限、JWT ブラックリスト保存）はすべて実測で利用可能。

---

## 3. セキュリティ防護の確認
### 達成済み ✓

- **キー管理**：プロジェクト全体でハードコードされたキー/パスワードなし（grep スキャン）；キーはすべて `getenv()` 経由；.env は gitignore 済み
- **SQL インジェクション**：文字列連結 SQL なし；すべて Eloquent クエリビルダー経由
- **入力検証**：アップロード type ホワイトリスト + finfo コンテンツスニッフィング + タイプ別サイズ上限；auth エンドポイントのフィールドレベル検証
- **レート制限**：公開の機密エンドポイントを完全カバー（login 5/min、register 3/min、sms 5/h、captcha 30/60s、oauth 10/60s、password_reset 3/5min）、default 60/min
- **JWT**：HS256 + 32 バイトキー；access/refresh 分離；type 検証；Redis ブラックリスト（ライブラリ内で jti により検証）；TOTP 強制 + 失敗ロック
- **CORS**：Origin ホワイトリスト（`CORS_ALLOWED_ORIGINS`）、ワイルドカードなし、資格情報ヘッダーなし
- **セキュリティヘッダー**：nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS（env スイッチ）
- **列挙対策**：forgot-password は存在しないユーザーにも一貫した成功メッセージを返す

### 提案（低優先、未変更）

| 項目 | 説明 |
|----|------|
| CSP ヘッダー欠落 | サイト全体で Content-Security-Policy 未設定；API JSON シナリオではリスク低、SecurityHeadersMiddleware に `default-src 'none'` レベルのポリシー追加を提案 |
| WAF パフォーマンス | WafMiddleware がリクエストごとに `file_get_contents('php://input')` でボディ全体を読み取りスキャン（31 種のパターン）、高トラフィック時はメモリ/CPU オーバーヘッドあり。POST/PUT かつ Content-Type が一致する場合のみボディを読むことを提案 |
| HealthController の `shell_exec('git rev-parse')` | health リクエストごとに子プロセス起動；本番では `APP_VERSION` env のみ使用し、shell はローカル開発のフォールバックのみを提案 |
| ~~RateLimit TOCTOU~~ | ~~check-then-set が非アトミック~~ **修正済み（2026-08-07）：** アトミック `INCR` + 初回 `EXPIRE` に変更、§七-6 を参照 |
| X-XSS-Protection | 非推奨ヘッダー、残しても無害；CSP 導入後に削除可能 |

---

## 4. 環境ギャップ（コードの問題ではなく、運用での補完が必要）
1. **`.env` に `DB_PASSWORD` が欠落**（唯一のブロッカー）：docker-compose は `${DB_PASSWORD}` で app_user を作成するが、ローカル .env にこのキーがない → すべての DB エンドポイントが 500。`DB_PASSWORD` は `.env.example` に定義済みで、デプロイ用の資格情報のためユーザーが `.env` に補入する必要がある。
2. **9100 が本機の dart プロセスに占有**：metrics プロセスのデフォルトポートバインドに失敗すると**グループ全体の起動をブロック**（webman は起動前に全ポートを事前チェック）。永続的な回避策として `.env` に `METRICS_PORT=9199` を書き込み済み（2026-08-07）。dart が 9100 を解放したらデフォルトに戻せる。
3. **composer validate fatal**（サードパーティ）：`erikwang2013/security-php` の composer プラグインが composer 自身の eval と衝突（`isLaravel()` の重複宣言）、本プロジェクトのコードとは無関係；CI の `composer validate --strict` ステップがこれで失敗する可能性があるため、CI の該当ステップに continue-on-error を付けるか service パッケージをスキップすることを提案。
4. 前回記録された 8787 の erp-php 占有は解消済み（今回は実測でバインド可能）。

---

## 5. エコシステム設定の確認
| 項目 | 結果 |
|----|------|
| CI（.github/workflows/ci.yml） | 完全：PHP 構文チェック + admin/service テスト（PHP 8.2/8.3 マトリクス）+ composer validate |
| マイグレーション | 30 個の migration ファイル |
| Docker | compose（MySQL+Redis+app）、Dockerfile、nginx.conf、prometheus、grafana、supervisor（nginx+webman） |
| 監視 | MetricsServer（Prometheus 独立ポート）+ websocket プロセス（process.php） |
| 負荷テスト | tests/k6（smoke/products/concurrent） |
| .env.example | .env よりキーが完全（OAuth/Feature スイッチ等もカバー）；.env にスーパーセットのキーなし |
| composer audit | セキュリティ脆弱性なし；非推奨パッケージ 1 個 doctrine/annotations（hg/apidoc の依存、評価の上保留） |
| キュー/非同期 | webman/redis-queue インストール済み；通知は NotificationDispatcher 経由 |

---

## 6. 残置提案（今後のイテレーション）
1. **CSP ヘッダー**（§三 を参照）
2. **WAF ボディ読み取りの最適化**（§三 を参照）
3. **DB_PASSWORD 補入後の DB 全チェーン再テスト**（register→login→refresh→logout の実フロー + JWT ブラックリスト失効検証）
4. ~~**supervisor に cron プロセスがない**：Billing\Cron\SuspendCheck 等の定期タスクにデーモン入口がない~~ **解決済み（2026-08-07）：** `App\Cron\CronRunner` プロセスを新設（毎分 config/cron.php の 5 フィールド式を評価）し、`queue_consumer` プロセスを登録して provisioning/notification キューを消費；cron.php 内のスクリプトファイルを指す 2 つの無効な登録を `ResourceMonitor` の呼び出し可能メソッドに変更
5. **CI の composer-validate ステップ**：サードパーティプラグインの衝突のため、容錯処理の追加を提案（§四-3 を参照）

---

## 7. 第 4 ラウンド補充修正（2026-08-07）
1. **課金の原子性（P0 財務）**：`BillingEngine::runDaily()` がリソース単位でトランザクションをラップし、課金/一時停止/イベントマークを同一トランザクションでコミット；`StripeChannel::confirmPayment()` は `UPDATE ... WHERE status='pending'` のアトミックな占有 + 注文行ロックで、webhook の重複入金を防止。
2. **並行冪等（P0/P1）**：`AffiliateService::requestPayout()` は行ロック + 既存の pending 出金があれば直接返す；`SupplierSettlement`（cron と `generateSettlement`）はサプライヤー+期間で重複判定。
3. **データ正しさ（P1）**：`MeterCollector` の `$resource->first()` による意図しない全表クエリを修正；`ExchangeRateSync` に 10s タイムアウトを追加。
4. **パフォーマンス（P2）**：Dashboard の 30 回の SUM クエリを単一 GROUP BY に統合；`CacheService::forgetPattern()` を KEYS→SCAN カーソルに変更；`I18n` 言語パックを locale 単位でプロセス内キャッシュ；`ImportExport` のインポートを全体トランザクションに；`BillingEngine` が手数料率マップをプリフェッチして N+1 を解消。
5. **セキュリティ（P1）**：`InternalTokenMiddleware` が `getRemoteIp()` を使用して XFF 偽装を防止；Webhook 登録でプライベートネットワークアドレスを拒否（SSRF）；`JwtAuth` は空キーで fail-fast；`DbBackupCommand` のパスワードを `MYSQL_PWD` に変更して `ps` での漏洩を防止；CSV/Excel エクスポートで数式インジェクション防止；サプライヤー外部 API に `supplier_api` レート制限をマウント。
6. **インフラ（P2）**：`RateLimitMiddleware` をアトミック INCR に（TOCTOU 解消）；`MetricsServer` の `onMessage` 型クラッシュループを修正；`HealthController` の Redis をコネクションプール化；`symfony/mailer ^6.4` を追加インストール（EmailSender は元々隠れた雷）；admin 側 `EncryptableBootstrap` の名前空間を修正。

---

## 8. 第 5 ラウンド補充修正（2026-08-07）
1. **自動交付の接続（P0）**：`ProvisioningService::handleOrderPaid` が交付タスク作成後に `provisioning` キューへ投入；`process.php` に `queue_consumer` プロセスを登録（app/ 配下の全 `Webman\RedisQueue\Consumer` 実装をスキャン）。
2. **定期タスクの実行可能化（P0）**：`App\Cron\CronRunner` プロセスを新設（毎分 config/cron.php の 5 フィールド式を評価、`*/n`/`,`/`-` 構文をサポート）；cron.php 内のスクリプトファイル（クラスでない）を指す 2 つの無効な登録を `ResourceMonitor::collectAllMetrics`/`checkSslCertificates` の呼び出し可能メソッドに変更し、ExpirationCheck と重複する checkExpirations 登録を削除。
3. **通知クラスが存在しない（P0）**：AuthService/AuthController/ExpirationCheck 内の 4 箇所 `\Common\Notification\NotificationDispatcher::send()`（クラス存在せず）を `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)` に統一。
4. **表名の三系統統一（P0）**：install.sql 内の 39 個の `erik_*` 業務テーブルを接頭辞なしに変更（Eloquent のデフォルト命名、migrations と一致）、`wa_*` 管理テーブルは維持；インストールウィザード（install/index.php）を「.env 書き込み → サブプロセスで service migrations を補完実行（30 個の migration ファイル）→ install.sql（IF NOT EXISTS で既存テーブルをスキップ）」に変更、インストール後のテーブルが完全。
5. **P1/P2 グループ（サブエージェントが完了、316 テストで検証済み）**：イベント配線、為替レートの通貨別書き込み、`Response::error` 単引数の 400 補完（10 箇所）、返金実行器（RefundService 新設）、承認冪等、admin 機密操作の監査、noNeedAuth 削除、管理 API のレート制限、WebSocket の Redis Pub/Sub 化、SSL クエリのバグ、通貨/滞納、credentials の秘匿化、クーポン適用、数量検証、CI 耐障害、ES_HOST 透過。

**テストベースライン**：service 316/316（502 アサーション）、admin 67/67（124 アサーション）全緑；変更ファイルすべて `php -l` 通過。

## 結論

今回のラウンドは「コードが読める」から「**起動可能、実行可能**」へ前進：P0 レベルの故障 8 件をすべて修正して実測し、316 テスト全緑、完全なミドルウェアチェーンでスモークテストを通過。残るブロッカーは環境ギャップ 1 件のみ（DB_PASSWORD）、補入すれば全チェーンを検証可能。第 4 ラウンド（2026-08-07）は課金原子性、並行冪等、レート制限/インジェクション防護など 20+ 項目の強化を追加完了；第 5 ラウンド（2026-08-07）は自動交付、cron スケジューリング、通知クラス、表名体系の 4 件の P0 と P1/P2 グループをすべて修正し、テストは全緑を維持。
