# PHP ユニットテストカバレッジ補完レポート（2026-08-26）

## 環境

- PHP 8.3.7（service スイート PHPUnit 10.5.64 / admin スイート PHPUnit 11.5.56）
- service/：業務 API；admin/：管理バックエンド
- テストデータ：SQLite `:memory:`（Capsule 初期化、既存の ReportServiceTest / OrderIdentityTest パターンを踏襲）；外部サービス（Redis/MySQL/Stripe）はすべて降格または mock

## 棚卸し結論：モジュール vs カバレッジ

### service/app（27 モジュール）

| モジュール | 棚卸し前のテスト | カバレッジ状態 |
|------|-----------|----------|
| order / payment / user / product / provisioning / supplier / affiliate / notification / webhook / websocket / graphql / grpc / monitor / billing / captcha / domain / ssl / storage / report / ticket / admin / security / confirmation / version / clientplatform | 各 1〜12 テストファイル | カバー済み |
| **command**（6 コマンド） | **なし** | **0 カバレッジ → 今回 ReconcileCommandTest を追加** |
| **cron**（6 タスク） | SupplierSettlementTest のみ | 部分カバー → 今回 PaymentReconcileTest + ExchangeRateSyncTest を追加 |
| controller（Health/Help/Status/Upload） | なし | 薄いコントローラー（静的ステータス/ヘルスチェック）、業務ロジックなし |
| model（payment/order など 20+ モデル） | サービス層経由で間接カバー | カバー済み |

### admin/app（controller/common/model/middleware）

| モジュール | 棚卸し前のテスト | カバレッジ状態 |
|------|-----------|----------|
| controller（48 コントローラー） | AdminControllersTest（全コントローラー反射：モデル組み立て/CRUD 面/GET ビューパス）+ CrudHashidsTest | カバー済み |
| middleware | AccessControlMiddlewareTest | カバー済み |
| common | TreeTest / HashidsTest / BaseJsonTest | 部分カバー → 今回 UtilTest + LayuiTest + ExcelExportTest を追加 |
| model | 直接テストなし | 今回 DictTest を追加；他モデルは薄いマッピング |

## 今回追加のテスト

| モジュール | 追加ファイル | ケース | アサーション | カバー内容 |
|------|----------|------|------|--------|
| Cron（資金照合） | `service/tests/cron/PaymentReconcileTest.php` | 7 | 24 | compare の通貨別最小単位精度 half-up 丸め：子分残余は verified かつ diff ゼロ；実差異は mismatch；ゼロ小数通貨（JPY）は整数繰り上げ；通貨が片側のみ存在；空側は verified；不正日付は InvalidArgumentException；run() はレポートチャネルなしで unverified 行を upsert（success のみローカル集計に計上、failed は除外、一意インデックスは本番をミラー） |
| Cron（為替同期） | `service/tests/cron/ExchangeRateSyncTest.php` | 2 | 2 | API 到達不能でも静かに完了（スケジューラーに投げない）；正しい payload + Redis 利用不可でもクラッシュしない |
| Command（照合コマンド） | `service/tests/command/ReconcileCommandTest.php` | 2 | 3 | 不正日付 → FAILURE + エラーメッセージ；正しい日付 → SUCCESS（空チャネルテーブル） |
| Admin Common | `admin/tests/UtilTest.php` | 17 | 47 | パスワード hash/verify 往復；humanDate の 5 段階相対時間；formatBytes；checkTableName/filterAlphaNum/filterNum/filterUrlPath/filterPath 検証（BusinessException 含む）；controllerToUrlPath（@action と不正入力を含む）；camel/smCamel；getCommentFirstLine；typeToControl/typeToMethod；getLengthValue（decimal/enum/varchar）；getControlProps（select データの value/name リスト変換 vs 通常の key=>value） |
| Admin Model | `admin/tests/DictTest.php` | 5 | 10 | 辞書名↔option 名変換；filterValue フォーマット検証；名前に英字必須；save/get/delete 全連鎖（SQLite メモリ DB、同名上書きセマンティクス）；欠落時は null 返却 |
| Admin Common | `admin/tests/ExcelExportTest.php` | 4 | 9 | ヘッダー書き込み + 太字；配列フィールドの JSON フラット化；行ごとの行番号追記；欠落列は空セル（PhpSpreadsheet メモリ内アサート、ディスク書き出しなし） |
| Admin Common | `admin/tests/LayuiTest.php` | 5 | 9 | input の name/value 描画；inputNumber の number 型強制；label の HTML エスケープ（属性インジェクション防止）；switch の lay-skin 描画；html() のインデント再配置 |

今回追加 42 ケース / 104 アサーション。金額関連アサーションはすべて `assertSame` の文字列厳密比較（bcmath）、浮動小数点なし。

## テスト環境の修正（業務コード以外）

1. **service/vendor の破損**：`composer.lock` がアップグレード済み（encryptable v2.0.2→v2.0.3 など複数パッケージ）だが vendor が未同期で、guzzle 欠落のためスイートが起動不能 → `composer install` で復旧、両スイートが実行可能に。
2. **UserModelTest の暗号化フィクスチャ無効化**：encryptable v2.0.3 が 32 バイトキー（デフォルト aes-256-gcm）を強制、旧フィクスチャは 16 バイト → 失敗。修正：`service/tests/user/UserModelTest.php` の setUp で 32 バイトキー + aes-256-gcm を固定し、`Encryption::setFallbackConfig(null)` を呼んでパッケージのプロセスレベル静的キャッシュをリセット —— `tests/user/AuthFullChainTest.php` が `service/.env`（cipher=aes-128-ecb、24 文字の非 base64 キー）を `$_ENV/$_SERVER` に注入し、静的 `$resolved` キャッシュがテスト間汚染を起こすため、単独実行は通るが全量実行は失敗する。この修正により、以降の Encryptable 依存テストも一貫した環境を獲得。

## 業務コードの問題

今回、業務バグは発見されず。`PaymentReconcile::compare` の 2 つの誤判定しやすいセマンティクスは実装に従ってアサートしコメント済み：diff は元の総額差（単位丸め差ではない）；ゼロ小数通貨の繰り上げ後、mismatch の diff は元の差（例：JPY 1234 vs 1234.5000 → diff -0.5000）。

## 全量結果

| スイート | ケース | アサーション | 失敗 | エラー | スキップ |
|------|------|------|------|------|------|
| service | 672 | 1632 | 0 | 0 | 15 |
| admin | 286 | 962 | 0 | 0 | 1 |

- ベースライン比較：service 661→672（+11）、admin 255→286（+31）；両スイート 0 failure / 0 error。
- 構文チェック：追加・修正ファイルはすべて `php -l` 合格。

## 残存ギャップと原因

| ギャップ | 原因 |
|------|------|
| cron/CronRunner、cron/SslCertificateCheck | スケジュールコンテキスト + 実 TLS 証明書プローブ、単体テストのコストが高い |
| command/Migrate*、DbBackupCommand、I18nSyncCommand | 実 MySQL マイグレーション/ファイルシステムに依存、統合環境が必要 |
| admin/common/Auth（getScopeRoleIds/isSuperAdmin） | セッションと DB 権限データに依存 |
| admin/common/Migration*、Layui::buildTable/buildForm | DB information_schema / 全テーブル構造に依存 |
| service/controller の薄いコントローラー（Health/Help/Status/Upload） | 業務ロジックなし、戻り値は webman ランタイムが提供 |
| graphql/GraphqlController | webman の `json()`/`config()` ヘルパーと FeatureFlags ランタイムに依存、Schema は SchemaTest でカバー済み |
| monitor/ResourceMonitor | Redis + 実プロバイダー呼び出しに依存、mock 層か統合環境が必要 |
