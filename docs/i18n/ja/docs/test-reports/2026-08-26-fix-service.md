# 2026-08-26 service 欠陥修正レポート（A/C/F）

## 結論

- 3 つの欠陥をすべて修正し、エンドツーエンドで再テスト合格（9/9 PASS）
- PHPUnit 全量リグレッション：672 tests / 1632 assertions / 15 skipped / 0 failures
- .env、app/grpc/Generated、データベース schema には未着手。composer 依存も追加していない

## 欠陥 A：encryptable キーが base64 デコードされない → 登録/ログイン/リフレッシュ/住所がすべて 500

### 根本原因（3 層が重なる）

1. `config/encryptable.php` が `ENCRYPTION_KEY`（base64、デコード後 16 バイト、cipher=aes-128-ecb）を原文のままキーとして渡し、キー長検証で `MissingEncryptionKeyException` が発生。
2. 実行時に実際に読まれるのは `config/plugin/erikwang2013/encryptable/app.php`（`enable` のみ）で、このプラグイン設定にはキー自体がない。
3. webman にグローバル `app()` ヘルパーがなく、`Encryption::doResolve()` がコンテナパスに到達できないため `EnvEncryptableConfig`（元の env base64 文字列をデコードせず読む）にフォールバック —— プラグイン設定を直しても依然 500。

### 修正

| ファイル | 変更 |
|------|------|
| `service/config/encryptable.php` | `'key' => base64_decode(getenv('ENCRYPTION_KEY'), true) ?: ''`（legacy パスも併せて修正） |
| `service/config/plugin/erikwang2013/encryptable/app.php` | `key`（base64 デコード）/ `cipher` / `previous_keys` を補完 |
| `service/support/bootstrap.php` | `Encryption::setFallbackConfig(new WebmanPluginEncryptableConfig())` で実行時にプラグイン設定（キーはデコード済み）を通す |

### チェーン上で発見した同源バグ（併せて修正）

暗号化修正が有効になると、登録/ログイン/リフレッシュが 500 以外の失敗に変わりました：

- **ログイン 401**：`User::where('email', $login)->orWhere('phone', $login)` の平文クエリは暗号化列に永遠にヒットしない。修正：`where('email', Encryption::php()->encrypt($login))`（暗号化は決定的なので、暗号文が等しければヒットする）。
- **リフレッシュ 401 "Device mismatch"**：2 層の問題——
  - `RefreshToken::where('token_hash', hash(...))` の平文クエリもヒットしないため、`encrypt(hash(...))` に変更；
  - 登録パスはデバイスフィンガープリントを記録しない（`AuthService::register()` 内部の `issueTokens(..., '')`）が、リフレッシュ時はフィンガープリントを検証する → 登録後のリフレッシュは必ず失敗。修正：`AuthController::register` が `deviceFingerprint($request)` を渡し、`AuthService::register` に `$deviceFingerprint` パラメータを追加。
- **登録のメール/電話の一意性検証**：`User::where('email', ...)->exists()` も同バグ。暗号化値でクエリするよう変更（`recordFailedLogin` も併せて修正）。

## 欠陥 C：Searchable モデルに ES クライアントがない → プロフィール変更/注文作成が 500

### 決定：webman-scout ドライバーを `database` に変更（`null` ではなく）

`config/plugin/erikwang2013/webman-scout/app.php`：`'driver' => 'elasticsearch' → 'database'`。

理由：elasticsearch/elasticsearch クライアントが未インストールのため、elasticsearch ドライバーはモデル保存時に例外を投げる。`database` エンジンは書き込みが no-op・検索は SQL LIKE（製品検索は引き続き利用可能）。`null` エンジンの `search()` は静かに空配列を返し、製品キーワード検索結果を飲み込んでしまう。ソフトデリート設定はデフォルトのまま。

## 欠陥 F：dns_rebinding 検出器が Host=127.0.0.1 のローカルリクエストを 403

### 決定：dns_rebinding mode を `log` に変更（whitelist_ips ではなく）

`config/plugin/erikwang2013/security-php/app.php`：`dns_rebinding.mode = 'block' → 'log'`。

理由：`whitelist_ips` はクライアント IP で**全**検出器をスキップする——本環境の全トラフィックは nginx 経由で、クライアント IP は常にループバックになるため、31 個全部の検出器を切るのと同じ。本機直結（Host=127.0.0.1/localhost）は開発/テストの常態なので、log に変更してこの検出器だけを通過させ、残り 30 個は block のままにする。

## 追加発見：user_addresses.phone VARCHAR(20) は暗号文を格納できない

暗号化が有効になると住所追加が 500（`SQLSTATE[22001] Data too long for column 'phone'`）。制約「DB を変更しない」のため、コード側で修正：

- `service/app/user/model/UserAddress.php`：`phone` を Encryptable casts から外す（テーブル内 0 行、既存データ移行リスクなし）。`address` は暗号化を維持（VARCHAR(500) で収まる）。

**トレードオフと後続**：phone は PII であり、現在は平文で落庫している。落盤暗号化を復元するには `user_addresses.phone` と `users.phone`（同じく VARCHAR(20) + Encryptable、電話番号登録も同様に 500 になる）を VARCHAR(255) に拡張する必要がある —— schema migration が 1 回必要で、今回の「DB を変更しない」制約を超えるため、別途タスク化を推奨。

## レビュー対応：cipher 決定性ガード（reviewer blocking は解消済み）

reviewer の指摘：暗号文の等値クエリは決定性暗号化（ECB はランダム IV なし）に依存するが、`.env.example` は aes-256-cbc（ランダム IV）を推奨——新環境がサンプル通りにデプロイすると「起動は成功するがログイン/リフレッシュ/一意性検証がすべて永遠にヒットしない」という静かなログイン不能状態になる。

修正（fail-fast ガードで静かな障害を防止）：

- `service/support/bootstrap.php`：encryptable 設定の接続後にガードを追加——`PHPEncrypter(WebmanPluginEncryptableConfig)->cipher()` が `aes-128-ecb`/`aes-256-ecb` 以外なら起動時に即 `RuntimeException` を投げ、「決定性クエリモードは ECB のみ対応。cipher を変える場合は再暗号化マイグレーションが必要」と明示。
- `service/.env.example`：暗号化セクションのコメントに警告を追加（CBC/GCM は起動時に即エラー。決定性クエリは ECB のみ）。

検証：現在の .env（aes-128-ecb）はガードを通過。サービス再起動後 E2E 9/9 PASS。phpunit 672/1632/15 skipped/0 failures。

## 環境事故（コード外、環境側の対応が必要）

セッション途中で `/usr/local/php/conf.d/002-imagick.ini`（root 所有、mtime 2026-08-26 23:31）が作成され、その読み込む imagick.so が libgomp コンストラクタでクラッシュ → **ini を伴うすべての php CLI 呼び出しがセグフォ**（phpunit、start.php、php -l がすべて死亡。gdb で dlopen imagick.so 即 SIGSEGV を確認、OMP_NUM_THREADS=1 も無効）。root 権限がないためファイルを削除できず、本セッションは `PHP_INI_SCAN_DIR=/tmp/confd`（スキャン先ディレクトリのコピーから imagick を除外）で回避し、サービスと phpunit はこの方法で実行。

環境側の推奨：`/usr/local/php/conf.d/002-imagick.ini` を削除またはコメントアウト（imagick.so 自体が破損）、および誰がセッション中にこのファイルを作成したか調査。

## 変更ファイル一覧（すべて service/ 配下）

- `config/encryptable.php`
- `config/plugin/erikwang2013/encryptable/app.php`
- `config/plugin/erikwang2013/webman-scout/app.php`
- `config/plugin/erikwang2013/security-php/app.php`
- `support/bootstrap.php`（cipher 決定性ガードを含む）
- `.env.example`（コメントのみ、.env の値は変更していない）
- `app/user/service/AuthService.php`
- `app/user/controller/AuthController.php`
- `app/user/model/UserAddress.php`

## 検証記録

- E2E（`/tmp/verify_chain.php`、一時スクリプトでリポジトリ外）：F（Host=127.0.0.1 が 403 にならない）、登録→ログイン→リフレッシュ→住所追加、プロフィール変更 9/9 PASS。
- `vendor/bin/phpunit`：672 tests / 1632 assertions / 15 skipped / 0 failures。
