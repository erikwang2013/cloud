# CloudPlatform チーム計画

> バージョン：2026-08-17（v2）｜v1 はマルチエージェントパイプラインが作成（PASS_WITH_FIXES）；v2 は Phase 0-2 の実実行結果に基づき Lead が更新
> 根拠：v1 + Phase 0-2 の全コミット（git 111 commits）+ 二人レビュー記録 + 実測テストベースライン

## 1. 現状概要（2026-08-17）

### 1.1 フェーズ完了度

| フェーズ | ステータス | 主要成果物 |
|------|------|----------|
| Phase 0 止血 | ✅ 4/4 | 請求書の実描画、通知テンプレート 6 種、照合の明示的 unverified、CSP ヘッダー/環境テンプレート |
| Phase 1 直近 | ✅ 8/8 | カートの数量変更、レビューステータス統一、照合の実化（Stripe レポート+日次）、返金条件検証（72h/5 日+冪等+TOCTOU インデックス）、サプライヤー 7 種 webhook、Feature Flags 接続+管理側、ドキュメント同期、実テスト |
| Phase 2 中期 | ✅ 8/8 | 資金ガード 4 項目、service/admin テスト債務、install.sql 31 テーブル、RbacMiddleware を 57 ルートにマウント、admin をイメージ+nginx 8788+CI 両端に統合、audit 回帰+login 全チェーン |
| Phase 3 長期 | ✅ 9/9 | ゲートウェイ+統一レート制限（P4.1）、多通貨全チェーン（P4.2）、HarmonyOS エンジニアリング化+CI（P4.3）、ES 実装（P4.4）、観察項目の消化（P4.5）、ドキュメント乖離 4 項目（P3.1）、権限収束（P3.2）、注文冪等キー（P3.3）、サプライヤー評価検証（P3.4）、i18n 7 言語（P3.6）；reviewer-gate 独立再レビューはすべて approve |

### 1.2 品質ベースライン（実測、コミット後に直列検証）

- service スイート：**568 tests / 1279 assertions**、10 skip（すべて DB 環境ギャップ）
- admin スイート：**255 tests / 887 assertions**、1 skip（DB 書き込みパス）
- CI 6 job：PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check /（docker 関連）
- 資金/セキュリティはすべて二人レビュー（security-auditor + reviewer の独立結論が一致）。git はタスク単位でコミット、作業ツリーはクリーン
- おまけの成果：9 個の Encryptable モデルの認証情報シリアライズ非表示（P1/P2 全量調査）

## 2. 残存とリスク一覧（2026-08-17 レビュー）

### 2.1 デプロイをブロックする項目（高優先）

- **DB_PASSWORD 環境ギャップ**：service/.env が空文字列 → 全 DB エンドポイントが 500、9+1 個の skip テストの根本原因。コード問題ではなく、運用で値を埋める必要あり（ルート .env.example にテンプレート済み）
- **HarmonyOS エンジニアリングの足場欠落**：apps/harmonyos は .ets が 3 つだけ（LoginPage/AuthManager/ApiClient）、hvigor/DevEco の全工程設定が欠落 → ビルド不能。CI harmonyos-check は正直にエラーを報告（exit 1）

### 2.2 ドキュメント-コード乖離（P1 未決 4 項目）

- GET /api/v1/orders の status フィルタが未実装
- WebSocket プッシュイベント欠落（websocket_push 関連ドキュメントに宣言あり）
- ticket.updated のトリガー範囲不明
- product_attributes が死んだ schema（使用するコードなし）

### 2.3 資金/セキュリティ観察項目（二人レビュー記録、low レベル）

- **注文に冪等キーなし**：同一カートの重複送信で二重注文が発生しうる（medium、スケジュールを推奨）
- サプライヤー評価が注文の帰属/ステータスを検証しない
- fee の bcmath 切り捨て（第 5 位小数、方向は少収 <0.0001/件。ルートと一致しており照合乖離なし）
- WAF の multipart 大ボディが引き続き raw を読む（json シナリオは $input がカバー、multipart は追加の防御面）
- user_coupons に一意制約なし（セマンティクス上、一ユーザー複数注文複数行を許容、観察）
- nginx-admin に CSP なし（admin は Layui フロントエンドでインラインスクリプトを含むため現状維持）

### 2.4 権限モデルの不一致（P2 新発見、収束待ち）

- DB-only の権限識別子 6 個 / Rbac-only 19 個 / ロール割当の差異（support/supplier）
- AdminRoleMiddleware が finance を除外する一方、Rbac.php は finance ロールを定義

### 2.5 その他

- i18n の新言語ファイルが英語原文のまま（T6）、7 言語未完了
- HarmonyOS CI の構造チェックは足場完成後に実 hvigor ビルドへアップグレード予定

## 3. ロードマップ

優先度原則（不変）：**資金/セキュリティ > デリバリー信頼性 > コア業務クローズループ > 体験と拡張**。

### Phase 3 — 残存の収束（1 ヶ月）

**目標**：全乖離と観察項目をクローズし、デプロイを再現可能に（DB 全チェーンテストを実実行でグリーン）。

| タスク | 関連 | ロール | 依存 |
|------|------|------|------|
| ドキュメント-コード乖離 4 項目の収束（orders status フィルタ実装 / WebSocket プッシュ接続 / ticket.updated 修正 / product_attributes 削除か実装） | Order、WebSocket、Ticket、Product、docs | coder + researcher | なし |
| 権限モデル収束（DB/Rbac 差異の整合 + ロールシード + AdminRoleMiddleware 再レビュー） | Rbac、install.sql、admin | coder + security-auditor | なし |
| 注文冪等キー（cart→order の二重注文防止） | OrderService | coder | なし（資金系は二人レビュー） |
| サプライヤー評価が注文の帰属/ステータスを検証 | Supplier、Review | coder | なし |
| DB_PASSWORD の運用接続 + 10 個の skip テスト実実行 | 運用、tests | security-auditor | 運用の協力 |
| i18n 7 言語翻訳の補完 | i18n ファイル | coder | なし |

**受入基準**：乖離 4 項目クローズ。権限マトリクスが DB/コードで一致。冪等キーテスト。DB 全チェーンテスト実実行グリーン。i18n は最低限中英が利用可能。

### Phase 4 — アーキテクチャ進化（1〜3 ヶ月）

**目標**：四層アーキテクチャを形にし、マルチ端末・多通貨の成長を支える。

| タスク | 関連 | ロール | 依存 |
|------|------|------|------|
| 独立 API ゲートウェイ + 統一レート制限マウント（graphql ギャップ含む） | gateway、route | architect + coder | P3 |
| 多通貨全チェーンの一貫性（fee 四捨五入戦略含む） | Payment、Billing | architect + performance-engineer | 同上 |
| HarmonyOS エンジニアリング化：足場 + CI 実ビルド + ログイン接続 | apps/harmonyos | mobile-dev | なし |
| ES 監査の実装化、迂回方式の置き換え | docker、Product 検索 | coder | なし |
| 観察項目の一括消化（WAF multipart / user_coupons 制約 / サプライヤー webhook エンドツーエンド） | Security、Order、Supplier | coder + tester | なし |

**受入基準**：k6 でレート制限が全ルートに有効と検証。多通貨計算がゼロ誤差。HarmonyOS がパッケージ出力で CI を通過。ES 検索が実際に利用可能。

## 4. チーム分業

固定コア：Lead(planner) / architect / coder / tester / reviewer / researcher
必要に応じて参入：mobile-dev / security-architect / security-auditor / performance-engineer

| フェーズ | 参入ロール | 説明 |
|------|----------|------|
| P3 | coder（主力）、researcher、security-auditor | 収束が主体。権限/冪等は二人レビュー |
| P4 | architect、coder、mobile-dev、performance-engineer | アーキテクチャ進化。security-architect が常駐アドバイザー |

協業モードは不変：CLAUDE.md パイプライン（architect→coder→tester→reviewer）、P3/P4 内部のタスクは fan-out 並列。**資金/セキュリティタスクは二人レビューを強制**。各フェーズ終了時に本ドキュメントを更新（本 v2 は Lead が直接作成、パイプライン未経由、レビュー可能）。

## 5. リスク追跡方法

- 本一覧は各フェーズ終了時にロールアップ更新。新発見（P2 の権限モデル不一致、注文冪等等）は即時組み込み
- 既知の低優先事項（サプライヤー webhook エンドツーエンド、multipart body）は P4 の消化バッチに入っており、一覧外に拡散しない

## 6. 主な証拠の出所

- コミット：git log（111 commits、Phase 0-2 をタスク単位でグループ化）
- テストベースライン：service/admin スイートの実測出力
- レビュー記録：P1/P2 二人レビューメッセージ（資金ガード、logout/WAF、RBAC、audit 回帰）
- ドキュメント：v1（docs/team-plan.md 履歴）、docs/audit-report-2026-08-06-v3.md、docs/api-reference.md
