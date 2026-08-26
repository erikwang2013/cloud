# CloudPlatform 全面审查报告

**日付:** 2026-08-04  
**審査範囲:** プロジェクト全体（コード品質、セキュリティ、エコシステム設定、デプロイ、ドキュメント）  
**ブランチ:** main  
**最新コミット:** e321bcc — 今回修正した残り 3 つの問題

---

## 1. プロジェクト概要
| 次元 | 状態 |
|------|------|
| プロジェクトタイプ | PHP 8.2+ / webman クラウドリソース取引プラットフォーム |
| コード規模 | service（15 モジュール、295 tests）+ admin（53 コントローラー、67 tests）+ Flutter + HarmonyOS |
| データベース | MySQL 8.0、46 テーブル（7 wa_* + 39 erik_*） |
| デプロイ方法 | ワンクリックインストールウィザード / Docker Compose / 手動 |
| ドキュメント | 10 篇 + 11 個の SVG アーキテクチャ図 |

---

## 2. 発見された問題
### CRITICAL（深刻）

#### C1. Docker デプロイに管理画面がない

**問題:** Dockerfile は `service/` ディレクトリのみをコピーし、docker-compose は 8787 ポートのみをプロキシする。管理画面（admin panel、ポート 8788）はまったく Docker 化されていない。

```dockerfile
# docker/Dockerfile — 現在は service のみ処理
COPY service/ /app/
```

**影響:** Docker でデプロイするユーザーは管理画面を使用できない。README が主張する「Docker Compose ワンクリック起動」と一致しない。

**提案:** `admin/` の Dockerfile を追加するか、マルチステージビルドで両サービスを同時にデプロイする。

---

#### C2. Docker データベースポートがホストに露出

**問題:** docker-compose.yml で MySQL (3306) と Redis (6379) のポートがホストに直接マッピングされている：

```yaml
mysql:
  ports:
    - "3306:3306"    # 公網に露出
redis:
  ports:
    - "6379:6379"    # 公網に露出
```

**影響:** サーバーに公網 IP があれば、データベースが外部に露出する。これはよくあるセキュリティ事故の発生源である。

**提案:** `ports` マッピングを削除するか、少なくとも `127.0.0.1:3306:3306` にバインドする。Docker 内部ネットワークはすでに相互通信可能。

---

#### C3. LICENSE ファイルがない

**問題:** README は「簡易版 — MIT License」と宣言しているが、プロジェクトルートに `LICENSE` ファイルがない。

**影響:** オープンソースの法的要件が欠落。GitHub はプロジェクトのライセンスタイプを認識できない。

**提案:** ルートディレクトリに標準 MIT License テキストの `LICENSE` ファイルを作成する。

---

### HIGH（高優先度）

#### H1. SQL ファイルの重複による混乱

**問題:** プロジェクトに 3 つの SQL DDL ファイルが存在する：

| ファイル | 行数 | テーブル数 | 状態 |
|------|------|------|------|
| `install.sql`（ルート） | 739 | 46 | **現在使用中** |
| `admin/install.sql` | 152 | 7（wa_* のみ） | 旧版、未削除 |
| `docs/database.sql` | 629 | 39（erik_* のみ） | 旧版、未削除 |

**影響:** 保守者が誤ったファイルを編集し、非同期になる可能性がある。

**提案:** `admin/install.sql` と `docs/database.sql` を削除するか、ファイルヘッダーに `install.sql` を指す目立つ廃止説明を追加する。

---

#### H2. インストールウィザードが監査データベースを作成しない

**問題:** `install/index.php` は `service/.env` 生成時に監査データベース設定を含む：
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
しかしインストールウィザードはこのデータベースを一度も作成しない。アプリケーション起動後に監査ログを書き込もうとすると、`Unknown database` で失敗する。

**影響:** 監査ログ機能が利用不可になり、コンプライアンスに影響する。

**提案:** step 4 のインストール実行時に `CREATE DATABASE IF NOT EXISTS cloud_platform_audit` を追加する。

---

#### H3. Docker に Elasticsearch サービスがない

**問題:** docker-compose.yml は app + mysql + redis の 3 サービスだけ。README の技術スタックは Elasticsearch 8.x を必須コンポーネントとして明記している。

**影響:** 全文検索（製品、ユーザー、注文、チケット）が Docker デプロイで完全に利用不可。

**提案:** docker-compose.yml に Elasticsearch サービスを追加する。

---

#### H4. Dockerfile に PHP 拡張がない

**問題:** Dockerfile がインストールする PHP 拡張は `gd pdo_mysql zip bcmath redis`。しかし環境チェックは 9 拡張を要求しており、以下が欠落：
- `intl`（PHP 国際化）
- `xml`（XML 解析）
- `fileinfo`（ファイルタイプ検出）

**影響:** Docker 環境で一部の機能が静かに失敗する可能性がある。

**提案:** 欠落拡張を追加：`docker-php-ext-install intl xml fileinfo`

---

### MEDIUM（中優先度）

#### M1. admin/.env.example の設定項目が不十分

**問題:** service/.env.example（146 行）vs admin/.env.example（64 行）、後者はコメントと設定項目が明らかに少ない。

**提案:** admin/.env.example にコメント説明を補完し、少なくともどのフィールドが service 側と一致する必要があるかを明記する。

---

#### M2. .env.example の HASHIDS_SALT ハードコード

**問題:** 両方の `.env.example` ファイルに：
```ini
HASHIDS_SALT=cloud-platform-hashids
```
運用担当者がこの値を変更せずに直接 `cp .env.example .env` すると、全インスタンスが同一のソルトを共有する。

**提案:** `.env.example` でプレースホルダーを使用し、コメントで「一意のランダム値を必ず生成すること」を強調する。

---

#### M3. インストールウィザードの成功ページリンクが無効

**問題:** インストール完了ページのリンクが `href="#"` を使用し、実際にクリック可能な URL がない。

**提案:** 具体的な URL/ポート情報と起動コマンドを少なくとも表示する。

---

#### M4. Docker にインストールウィザードが含まれない

**問題:** Dockerfile は `install.php` や `install/` ディレクトリをコピーしない。Docker を使用するユーザーはワンクリックインストールウィザードを使えない。

**提案:** Docker デプロイは手動設定が必要であることをドキュメントで明記するか、イメージにインストールウィザードを統合する。

---

#### M5. Docker Compose の環境変数が不完全

**問題:** docker-compose.yml の `environment` に複数の必須設定が欠落：JWT キー、Hashids ソルト、暗号化キー、SMTP、Stripe など。

**提案:** 完全な環境変数リストを補完するか、`.env` ファイルを参照する。

---

### LOW（低優先度）

#### L1. ドキュメントの Docker 章が手薄

README の Docker デプロイは数行だけで、環境変数の設定方法、データベースの初期化、管理画面へのアクセス方法が説明されていない。

**提案:** 完全な Docker デプロイドキュメントを補完する。

---

#### L2. .editorconfig がない

**問題:** プロジェクトに `.editorconfig` ファイルがない。多人数貢献プロジェクトでは、統一されたインデント、改行設定が重要。

**提案:** 標準の `.editorconfig` を追加し、PHP は 4 スペースインデント、UTF-8、LF 改行と規約を定める。

---

#### L3. コードのハードコードデフォルト値を集中管理できる

**問題:** `install/index.php` に複数のハードコードデフォルト値（データベースホスト、ポート、データベース名、管理者ユーザー名）があり、変更時に見落としやすい。

**提案:** ファイル冒頭の定数定義に抽出する。

---

## 3. エコシステム設定の完全性評価
### .env 変数カバレッジ

| 設定ドメイン | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| データベース接続 | ✓ | ✓ | ✓ |
| 監査データベース | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| JWT 認証 | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| 転送暗号化 (AES-256-GCM) | ✓ | ✓ | ✓ |
| フィールド暗号化 (AES-128-ECB) | ✓ | ✓ | ✓ |
| SMTP メール | ✓ | N/A | ✓ |
| Stripe 支払い | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| Twilio SMS | ✓ | N/A | ✓ |
| Firebase プッシュ | ✓ | N/A | ✓ |
| クリック認証コード | ✓ | N/A | ✓ |
| Sentry 監視 | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| キーローテーション | ✓ | N/A | ✓ |
| **評価** | **完全** | **完全** | **完全** |

### インストールウィザードが生成する共有キーの一致性

| キー | service | admin | 一致 |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **評価** | **通過** | **通過** | **通過** |

---

## 4. セキュリティ評価
| チェック項目 | 状態 | 説明 |
|--------|:--:|------|
| CSRF 防護 | ✓ | Token 生成 + hash_equals 検証 |
| Session セキュリティ | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| 入力検証 | ✓ | DB 名の正規表現検証、ポート範囲チェック |
| パスワード強度 | ✓ | 最小 8 桁 + 英字 + 数字/特殊文字 |
| パスワードハッシュ | ✓ | password_hash(PASSWORD_DEFAULT) |
| キー生成 | ✓ | openssl rand または random_bytes |
| SQL インジェクション防護 | ✓ | PDO prepared statements |
| エラー脱敏 | ✓ | 詳細エラーは error_log のみ、ユーザーは汎用メッセージ |
| XSS 防護 | ✓ | htmlspecialchars() 出力エスケープ |
| 再インストール保護 | ✓ | 既存テーブル + .env ファイルを検出 |
| ステップ強制 | ✓ | session max_step でステップ飛ばしを防止 |
| トランザクションラップ | ✓ | beginTransaction/commit/rollBack |
| Docker ポート露出 | ✗ | MySQL:3306 / Redis:6379 がホストにマッピング |
| 監査データベースの作成 | ✗ | インストールウィザードが _audit 庫を作成しない |
| **総合評価** | **A-** | 中核のセキュリティ対策は完善、Docker 設定の改善が必要 |

---

## 5. SQL 完全性
| チェック項目 | 結果 |
|--------|------|
| 総テーブル数 | 46（7 wa_* + 39 erik_*）✓ |
| エンジン | すべて InnoDB ✓ |
| 文字セット | すべて utf8mb4 ✓ |
| 主キータイプ | BIGINT UNSIGNED（非オートインクリメント）✓ |
| CREATE IF NOT EXISTS | すべて使用 ✓ |
| 破壊的ステートメントの有無 | なし（DROP TABLE なし）✓ |
| 旧版 SQL ファイル | 旧版 2 ファイルが残存、クリーンアップが必要 ⚠ |

---

## 6. テストカバレッジ評価
| テストスイート | フレームワーク | テスト数 | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**評価:** テスト数は十分（362 テスト）、CI/CD は二つの PHP バージョンの構文チェック + 両端のユニットテストをカバー。

---

## 7. ドキュメント完全性
| ドキュメント | 内容 | 状態 |
|------|------|:--:|
| README.md | プロジェクト概要、アーキテクチャ、クイックスタート、API 概览 | ✓ |
| README_EN.md | 英語版 README | ✓ |
| docs/architecture.md | システムアーキテクチャ設計 | ✓ |
| docs/features.md | 12 モジュール機能設計 | ✓ |
| docs/api-reference.md | 135+ エンドポイントリファレンス | ✓ |
| docs/admin-design.md | 管理画面設計 | ✓ |
| docs/supplier-api.md | サプライヤー API | ✓ |
| docs/deployment.md | デプロイチェックリスト | ✓ |
| docs/editions.md | バージョン比較 | ✓ |
| docs/diagrams/ (11 SVG) | アーキテクチャ/セキュリティ/業務フロー | ✓ |
| LICENSE ファイル | **欠落** | ✗ |

---

## 8. 修正提案のまとめ
### 第一優先度（次回リリース前に修正を推奨）

| # | 問題 | レベル |
|---|------|:--:|
| 1 | LICENSE ファイルを作成（MIT） | CRITICAL |
| 2 | 旧 SQL ファイルを削除（admin/install.sql, docs/database.sql） | HIGH |
| 3 | Docker の MySQL/Redis ポートをホストに露出しない | CRITICAL |
| 4 | インストールウィザードが監査データベース `_audit` を作成 | HIGH |

### 第二優先度（近日中の修正を推奨）

| # | 問題 | レベル |
|---|------|:--:|
| 5 | Docker が管理画面（admin panel）に対応 | CRITICAL |
| 6 | Docker Compose に Elasticsearch サービスを追加 | HIGH |
| 7 | Dockerfile に PHP 拡張を補充（intl, xml, fileinfo） | HIGH |
| 8 | .env.example の HASHIDS_SALT をプレースホルダーに変更 | MEDIUM |

### 第三優先度（継続的改善）

| # | 問題 | レベル |
|---|------|:--:|
| 9 | Docker デプロイドキュメントを整備 | LOW |
| 10 | .editorconfig を追加 | LOW |
| 11 | コード内のハードコードデフォルト値を整理 | LOW |
| 12 | .env 生成関数の設定項目を統一 | LOW |

---

## 9. 結論
プロジェクト全体の品質は良好で、中核のインストールウィザードは前回の監査後にセキュリティ問題がすべて修正されている。コード構成は明確、モジュール化の程度が高く、ドキュメントも完備。主な問題は **Docker デプロイ設定の不完全さ** に集中——管理画面、検索サービス、PHP 拡張が欠落し、データベースポート露出のセキュリティリスクも存在する。

**総評：B+** — 機能は完全、セキュリティの中核は到位、Docker エコシステム設定の補完が必要。
