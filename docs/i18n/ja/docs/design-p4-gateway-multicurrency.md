# P4.1 + P4.2 設計：独立 API ゲートウェイ/統一レート制限 + 多通貨全チェーン一貫性

> バージョン：2026-08-17 v1｜アーキテクト作成、gateway-impl / multicurrency-impl 実装用、reviewer-gate が再レビュー
> 根拠：docs/team-plan.md v2 Phase 4、docs/architecture.md、既存コードの実読

---

## P4.1 独立 API ゲートウェイ + 統一レート制限

### 現状（実読確認）

| 層 | 現状 |
|----|------|
| エッジゲートウェイ | docker/nginx.conf が service の L7 ゲートウェイを担う：`limit_req_zone api 10r/s`（全局限流）、proxy_pass 8787（service）、8282（ws）。**admin は独立コンテナ**（Dockerfile admin target、nginx-admin.conf listen 8788 proxy 8788）、**limit_req なし** |
| アプリ限流 | `service/common/security/RateLimitMiddleware.php` は既に存在：Redis INCR+expire 固定ウィンドウ、**per-IP のみ**、`ROUTE_MAP` でルールを選択し、**明示ルート**に付与（route.php で計約 12 箇所） |
| ルール設定 | `config/security.php rate_limits`：default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql、すべて rate/burst/per を含むが、**burst フィールドは現在未使用** |
| グローバルミドルウェア | `config/middleware.php` の `''` キーが全ルートに有効（WAF/GeoBlock/Security など 10 項目がここにある） |
| ギャップ | `/graphql`（public + authenticated の 2 ルート）**に限流なし**；per-token 限流なし；429 レスポンスに `Retry-After` ヘッダーなし；webhook の免除/専用ルールなし |

### 決定

**D1：独立ゲートウェイプロセスは新設しない。** nginx がゲートウェイ（ネットワークエッジ + 限流 + ルート分流）を担い、webman 内で統一レート制限を行う。
- 理由：独立 gateway コンテナは新依存/新デプロイトポロジ/二重の認証が必要。現在の単一インスタンス規模では過剰設計。
- トレードオフ：ゲートウェイ層では token 別/ルート別の差異化限流は不可（nginx は per-IP 区間のみ）。差異化はアプリ層で補い、nginx 層は粗粒度の IP フォールバックのみ保持（現 10r/s を 100r/s に引き上げ業務を誤殺しないようにし、k6 検証時にデモ閾値へ戻す）。
- 進化パス：将来マルチインスタンス/マルチサービスになった場合、`config/middleware.php` の全局限流器をそのまま独立 gateway サービスに移すだけでよい。ミドルウェアはデプロイ形態を感知しない。

**D2：統一レート制限 = グローバルミドルウェア + 2 次元バケット（per-IP + per-token）。**
- `RateLimitMiddleware` を明示ルートから外し（route.php の実約 12 箇所、grep 実測が正）、`config/middleware.php` の `''` グローバルリストにマウント（WAF の後、業務ミドルウェアの前）。**アプリ内の全ルート（/graphql の 2 ルート含む）を自然にカバー。**
- **バケットのセマンティクス（明確化、迂回防止）**：`ratelimit:ip:{realIp}:{rule}` と `ratelimit:tok:{sha256(token)}:{rule}` の 2 バケットを独立カウントし、**いずれかのバケットが超過で 429（OR）**。AND 実装は禁止——AND では IP を変えれば per-IP バケットを迂回でき、token を変えれば per-token バケットを迂回できる。
- **免除リスト**：`/health*`（監視プローブ）と `/api/payments/webhook/stripe`（署名検証が真の防衛線 + Stripe が 429 を自動退避再試行 + nginx 粗粒度 100r/s フォールバックが依然有効。限流には安全上の利得がなく、イベント喪失/遅延着金のリスクしかない）。他の全ルートは必ず限流。
- レスポンス：`HTTP 429` + `Retry-After` ヘッダー（2 バケットのウィンドウ残りは **max** を取る。固定ウィンドウは Redis `PTTL` で正確な残り）+ body `{code:429, message, retry_after}`（既存 `Response::error` に整合）。
- バースト：burst フィールドを有効化——`rate` はウィンドウ内の定常クォータ、`burst` は使い切れる超過分。実装は Redis キー計数の上限 `rate + burst`（固定ウィンドウ内の超過）。スライディングウィンドウは不要（ponytail: 固定ウィンドウは境界で 2 倍のウィンドウ増幅があるが、per-IP なら単一マシンの濫用には十分。より厳格にしたければスライディングウィンドウに交換）。
- ルート→ルールマッピング：既存 `ROUTE_MAP` を維持し、`'/graphql' => 'graphql'` を追加（config/security.php:46 に `{rate:30, burst:5, per:60}` が既存）。未知ルートは `default`（60/60s）。
- Redis 利用不可：既存の fail-open を踏襲（catch Exception で通過）——nginx 100r/s 粗粒度フォールバックが残っている。
- **範囲**：service コンテナのみ。admin は独立コンテナ（nginx-admin.conf に limit_req なし、現状無制限）。service/config と service ミドルウェアの変更は admin に影響しない——admin の限流は P4.1 の範囲外、別途決定。

**D3：認証前のレート制限。** グローバルミドルウェアは AuthMiddleware の前（middleware.php の順序が実行順序）にあるため、per-token バケットは token 未携帯のリクエストでは per-IP バケットに退化。token を携帯したリクエストは、パスが匿名（例：/api/products）でも token バケットに計上される——共有 token の濫用を防止。

### 影響面

| 項目 | 変更 |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | 改造：per-token バケット、burst、Retry-After、graphql ルール |
| `service/config/middleware.php` | `''` リストに RateLimitMiddleware を追加。route.php の全明示マウント点から削除 |
| `service/config/security.php` | `default` {60,10,60} は据え置き（受入閾値 = rate+burst = 70）。`graphql` {30,5,60} は既存のため追加不要。burst フィールドを沿用 |
| `service/config/route.php` | 約 12 箇所の明示 `RateLimitMiddleware::class` マウントを削除（grep 実測が正、auth/supplier/admin グループ） |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s（粗粒度フォールバック。グローバルミドルウェアの上でさらに業務を締めない） |
| テスト | service スイートで限流ミドルウェアの明示マウントに依存するテストを同期。ミドルウェアの単体テストを新規追加 |

### 受入（k6）

```
# 任意の匿名ルート（例：GET /api/products）と /graphql を選び、各 200 リクエスト/10s を打つ：
# 限流閾値以上はすべて 429、かつレスポンスに Retry-After 付き。閾値以下はすべて 200。
# アサート：429 件数 == 総リクエスト - 閾値。/graphql も同様に有効（元のギャップ）。
```

---

## P4.2 多通貨全チェーン一貫性（fee 丸め戦略含む）

### 現状（実読確認）

- **ストレージ**：`install.sql` の全金額は DECIMAL —— 残高/凍結 `(16,4)`、注文の subtotal/discount/tax/total、行項目の unit_price/total_price `(12,4)`、`exchange_rate DECIMAL(12,6)` は `orders`、`payment_transactions` に既存。`user_balances` は通貨ごとに行を分ける（通貨別記帳）。
- **為替レート供給源**：`service/app/cron/ExchangeRateSync.php` が実装済み——外部無料 API（`EXCHANGE_RATE_API_URL` env で設定可、デフォルト exchangerate-api.com）から毎時 Redis `exchange_rate:{CURRENCY}` に同期。`OrderService::getExchangeRate` が注文時に Redis スナップショット（USD は恒に 1.0）を読み、注文の `exchange_rate` フィールドに書き込み。**外部依存は既にあり env で供給源を変更可能、新規追加不要。**
- **fee 切り捨て問題**：`PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` —— bcmath は scale で**切り捨て**（四捨五入ではない）。方向は**少収** <0.0001/件。また `total_amount = amount + fee` は 5 桁以上の小数の amount（例：10.12345）で切り捨て後、注文 total と一致しない可能性。
- **suspend チェック**は通貨別残高で判定済み（多通貨）。Billing は meter 課金（usage_rates 単価 DECIMAL(12,4)）。

### 決定

**D4：統一金額不変式 —— 通貨ごとに 1 つの内部精度、丸めは単一地点でのみ。**
- 内部計算は統一 `DECIMAL(12,4)`（注文粒度）と `DECIMAL(16,4)`（残高粒度）。すべての乗算後は必ず `bcround(x, 4, PHP_ROUND_HALF_UP)` を通す。`bcadd/bcsub` は同精度の加減のみ（それ自体は正確）。
- 唯一の金額ヘルパー `service/common/money/Money.php`（約 40 行）を新規追加：
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` —— 冪等。`round()` は浮動小数点で精度リスクがあるため、文字列パスが必須：`bcadd($v, '0', $scale+1)` の後、第 $scale+1 桁で HALF-UP 判定（実装は負数の処理に注意。bccomp で abs 判定すればよい）。
  - 金額フィールドの書庫前には必ず `bcround(…, 4)`。計算チェーン途中での `(float)`/`round()` は**禁止**（既存 StripeChannel の `round((float) bcmul(...))` はまさに隐患）。
- 既存 `calculateFee` の変更：`$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` —— 先に amount を 4 桁に整え、レートを掛け、HALF_UP で 4 桁へ。**方向修正：少収 → 標準ハーフ丸め**（1 件あたりの差 ≤0.00005、期待値は 0 に収束）。**負の fee の 0 クランプ保護は維持**（現コード PaymentRouter.php:44 の挙動を不変）。

**D5：注文恒等式とチャネル手数料の分離（照合ゼロドリフト）。** 2 つの独立した事実：
- **注文行の恒等式** `total − subtotal − tax + discount == 0`（0.0000 まで正確）：建注チェーン（OrderService::createFromCart）の行項目は `bcround(bcmul(price, qty, 8), 4)`（先に高精度乗算してから丸め、二重切り捨てを回避）→ subtotal = 行の和（正確）→ total = subtotal + tax − discount（同精度加減、正確）。**tax は現状恒に 0**（createFromCart は tax を設定せず、install.sql:345 DEFAULT 0.0000）——税計算は追加しない（P4.2 の範囲外、コンプライアンス影響あり）。アサートは `tax=0` の現値で実装するが、式には tax 項を残す。
- **チャネル手数料**：channel_fee を独立に `bcround(…,4)`。支払いチャネル金額 = total + channel_fee が 4dp で正確に一致。
- 検証：`PaymentController::reconcile*` とレポート（Report）は注文保存の total を基準とし、再計算しない。

**D6：為替レートスナップショットと換算ポイント。**
- レート供給源は ExchangeRateSync cron + Redis を維持（既存、動かさない）。`exchange_rate` 列は注文/取引とともにスナップショット済み（DECIMAL(12,6)）。**換算ポイント = 決済（書庫）時**。表示時のリアルタイム換算はしない（表示のリアルタイム価格は UI 層が現在の Redis レートを掛けるだけ、帳簿に影響しない）。
- ルール：**帳簿/残高に関わる場合は必ず注文スナップショット rate を使用。表示/価格提示に関わる場合は現在 rate を使用可。** 決済チェーンで 2 つの rate を混用することを禁止。
- 残高層は既に通貨別帳簿（user_balances は currency 行）で、統一基準通貨への換算はしない。レポートが基準通貨（例：USD）を必要とする場合は注文スナップショット rate で集計し、集計結果も `bcround(…,4)` を通す（ponytail: クロス通貨集計の丸め誤差は合計桁に出る。後続の監査で通貨別合計が要求されたら再分割）。

**D7：変更リスト（既存多通貨コードのレビュー地点を含む）。**
- 変更：`PaymentRouter::calculateFee`、`StripeChannel`（金額入力を整合 + float round を除去。convertToSmallest を bcround($total,2) に変更）、`OrderService::createFromCart`（行項目/subtotal/total の順序丸め）、**`Order/Model/Coupon.php::calculateDiscount`（:31-44 は現状 float+round、bcround 文字列パスに変更）**、`PaymentController::reconcile*`（D5 恒等式をアサート）、`Report/*`（集計を統一 bcround）。
- レビュー後変更なし：Billing meters（単価は既に DECIMAL(12,4)、課金は bcround で整合するのみ）、suspend チェック（通貨別残高判定、既に正しい）、`Cron/ExchangeRateSync.php`（Redis 書き込みは 6 桁原文を保持、動かさない）。
- 新規追加：`service/common/money/Money.php` + 単体テスト（HALF_UP 境界：0.00005 → 0.0001、0.00004 → 0.0000、**-0.00005 → -0.0001（負数はゼロから遠ざかる）**、冪等性）。
- マイグレーション：`install.sql` に構造変更なし（exchange_rate 列は既存）。過去注文の fee 切り捨てで <0.0001 の尾差が出た場合、帳簿上は不可逆の差異であり、**記録のみで補正しない**（1 件補正すると過去の照合が変わる）。監査クエリ `fee_drift` を新設し、|total−subtotal−tax+discount|>0 の注文を一覧して人手確認に供する。

### 受入

```
# k6（P4.1）：固定単一 IP。GET /api/products と /graphql を各 200 リクエスト/10s：
#   default ルール閾値 = rate+burst = 70/60s ウィンドウ → 期待 429 ≈ 200−70 = 130（±ウィンドウ境界 1-2）
#   graphql ルール閾値 = 35 → 期待 429 ≈ 165。いずれも Retry-After ヘッダー付き。低トラフィックは全 200
# 単体テスト（P4.2）：Money::bcround 境界（0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, 冪等）
# 恒等式テスト：複数行の注文（5 桁小数の単価 + クーポン含む）を構築し、total−subtotal−tax+discount == 0 が恒成立することをアサート
# 回帰：既存 service 491 tests が全グリーン（金額アサート含む）
```

---

## リスクとレビュー

- **D2 全局限流器のリスク（中）**：グローバルマウントは service の全エンドポイントに影響（**admin は含まない**——独立コンテナで、service/config の変更は触れない。webhook は免除済み）。閾値が不適切だと誤殺するため、security-auditor がデフォルト閾値と fail-open 戦略をレビューする必要あり。**admin コンテナは現状無制限**（nginx-admin.conf に limit_req なし）、P4.1 には含まず、別途決定。
- **D4/D5 資金チェーン（高）**：丸め方向の変更は注文ごとの金額に影響（少収 → 標準ハーフ丸め）。security-auditor レビュー + 二人レビューが必要。履歴データは記録のみで補正しない。
- **依存**：新規 composer 依存なし。新テーブルなし。nginx 設定変更はリロードが必要。

```yaml
design:
  objective: "P4.1 統一レート制限が全ルートに有効（graphql 含む）+ P4.2 多通貨丸め戦略の整合、帳簿恒等式ゼロドリフト"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + 恒等式)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # 構造変更なし。exchange_rate 列は既存。tax は 0 のまま新規追加なし
  client_impact: ["flutter", "harmonyos"]  # 429 はクライアントの優雅な処理が必要。admin コンテナは影響なし
  risk: "high"       # D4/D5 資金チェーン
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After 全ルート（k6 単一 IP、429≈130/165）", "graphql 限流ギャップのクローズ", "webhook 免除で 429 なし", "2 バケット OR セマンティクス（token 変更/IP 変更のいずれも迂回不可）", "fee HALF_UP 境界（負値含む）", "Coupon bcround 文字列化", "total−subtotal−tax+discount==0 恒等式", "過去注文 fee_drift 監査クエリ"]
  dependencies: []
```
