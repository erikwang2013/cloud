# CloudPlatform 审查报告（第 2 轮、2026-08-06）

> 範囲：前回（audit-report-2026-08-06.md）の全問題を修正した後の再検査。
> テストベースライン：PHPUnit **319/319 通過（505 アサーション）**。`php -l` 253 の PHP ファイル **0 構文エラー**。

---

## 1. テストと静的検査
| 項目 | 結果 |
|------|------|
| PHPUnit 全量 | OK（319 tests, 505 assertions） |
| `php -l`（app/common/config） | 253 ファイルすべて合格 |
| composer audit | **セキュリティ脆弱性なし**。非推奨パッケージ doctrine/annotations が 1 つ（hg/apidoc の直接依存、評価の上保留） |
| composer.lock | バージョン管理に組み込み済み（ステージング A） |

---

## 2. エコシステム設定の点検
### 2.1 env の使用と定義 —— 完全 ✓

- コード中のすべての `getenv()` キー（動的 `{PROVIDER}_OAUTH_*` パターン含む）が `.env.example` に定義またはコメント形式のオプション設定として存在（`#HASHIDS_ALPHABET`、`#POSTER_IMAGE_DRIVER`、`#EXCHANGE_RATE_API_URL`、`#COUNTRY_SEASON_DEFAULT`、`#SECURITY_HSTS_VALUE`）
- テンプレートの冗長項目（低リスク）：`MAIL_FROM_NAME` はコード内に `getenv()` 参照がなく、テンプレートにのみ残存

### 2.2 依存ロック ✓

- `service/composer.lock` はコミット済み。`.gitignore` で除外されなくなった。`service/.phpunit.cache/` は無視済み

### 2.3 環境説明

- 本機のポート 8787 は引き続き erp-php が占有しており、cloud-php はローカル起動不可（デプロイ環境では競合なし）
- `composer validate` は vendor プラグイン `erikwang2013/security-php` の Installer と composer 自身の eval が衝突して fatal（サードパーティパッケージの問題、本プロジェクトのコードではない）

---

## 3. セキュリティ防護の点検
### 3.1 グローバルミドルウェアチェーン（11 層、全ルートをカバー）✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF（SQLi/XSS）→ SecurityPlugin（31 種の攻撃検知）
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 公開ルートのレート制限 —— 今回は 1 箇所修正

| ルート | ミドルウェア | レート制限ルール |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min、login 5/min |
| **forgot-password / reset-password** | **RateLimit（今回補完）** | password_reset 3/5min |
| oauthRedirect / oauthCallback（GET+POST） | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **修正**：`forgot-password`/`reset-password` の 2 ルートは前回 `password_reset` ルールを定義したがミドルウェアのマウントを漏らしていた（メール爆撃/CAPTCHA 破りの面）。今回は補完してマウント。

### 3.3 アップロードファイルの露出 —— 今回は 1 箇所修正（高危険度）

**問題**：`deployment.md` の nginx 設定 `location /storage/ { alias .../service/storage/; }` が storage ディレクトリ全体を公開：

```
storage/
├── backups/    ← データベースバックアップ（.sql.gz）公開ダウンロード可能
├── apple/      ← AuthKey.p8 秘密鍵公開ダウンロード可能（Apple トークン署名可能）
├── firebase/   ← FCM サービスアカウント認証情報（秘密鍵含む）公開ダウンロード可能
├── geoip/      ← GeoLite2 データベース
└── uploads/    ← アップロードファイル（公開が想定）
```

**修正**：deployment.md と docker/nginx.conf をどちらも `location ^~ /storage/uploads/` に変更し、uploads サブディレクトリのみ公開。

### 3.4 その他の点検 ✓

- `verify-email`：ワンタイムランダム token（検証後に空に）、爆破/列挙の面なし、レート制限不要
- アップロード API：type ホワイトリスト + finfo MIME 内容スニッフィング（前回修正済み）。uploads は nginx 静的 alias 直出しで PHP を実行しない
- JWT：HS256 + Redis ブラックリスト（庫内は jti で検証）。TOTP ログイン強制 + 失敗 5 回で 15 分ロック
- OAuth：JWKS 署名検証 + iss/aud/exp/nonce + email_verified 強制（前回修正済み）
- 管理ルート：AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 4. 残存提案（非ブロッキング）
| レベル | 事項 | 説明 |
|:---:|------|------|
| P3 | `service/service/` 冗長な旧ディレクトリ（28K） | 古い Supplier/WebSocket のコピーを含む。PSR-4 でロードされず、追跡もされず、誤変更されやすい。人手確認後削除を推奨 |
| P3 | `MAIL_FROM_NAME` テンプレートの冗長 | コードで未使用。メール送信者名の予約設定として保持可 |
| P3 | doctrine/annotations の非推奨 | hg/apidoc の直接依存。削除には API ドキュメント生成方式の差し替えが必要 |
| P3 | アップロードディレクトリの強化（再提案） | uploads 内に `index.html` を置く。デプロイ層で PHP 実行なしを確認（nginx alias は天然に回避、webman 組み込みサービスのシナリオは注意が必要） |

---

## 5. 結論
前回の 15 件の修正はすべて再検査で有効と確認、テストベースラインは安定（319/505）。今回は 3 箇所を新たに発見し即時修正：**forgot/reset ルートのレート制限マウント漏れ（P1）**、**deployment.md の nginx 設定がバックアップと秘密鍵を露出（P0）**、**docker nginx に uploads 静的設定がない（P2）**。修正後、全量テスト再実行は合格。

*レポート生成方法：PHPUnit 全量、php -l 253 ファイル、ルート/ミドルウェア静的監査、nginx/docker 設定監査、env 使用と定義の差集合比較、composer audit。*
