# P4.1 + P4.2 Design: Standalone API Gateway / Unified Rate Limiting + Full-Chain Multi-Currency Consistency

> Version: 2026-08-17 v1 | produced by the architect for implementation by gateway-impl / multicurrency-impl, review by reviewer-gate
> Basis: docs/team-plan.md v2 Phase 4, docs/architecture.md, direct reading of existing code

---

## P4.1 Standalone API Gateway + Unified Rate Limiting

### Current State (confirmed by direct reading)

| Layer | Current state |
|----|------|
| Edge gateway | docker/nginx.conf serves as the service L7 gateway: `limit_req_zone api 10r/s` (global limit), proxy_pass 8787 (service), 8282 (ws). **admin is a separate container** (Dockerfile admin target, nginx-admin.conf listen 8788 proxy 8788), **no limit_req** |
| Application rate limiting | `service/common/security/RateLimitMiddleware.php` already exists: Redis INCR+expire fixed window, **per-IP only**, rules selected via `ROUTE_MAP`, attached to **explicit routes** (~12 places in route.php) |
| Rule config | `config/security.php rate_limits`: default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, all with rate/burst/per, but **the burst field is currently unused** |
| Global middleware | `config/middleware.php` `''` key already supports applying to all routes (WAF/GeoBlock/Security etc., 10 items here) |
| Gaps | `/graphql` (public + authenticated routes) has **no rate limiting at all**; no per-token rate limiting; 429 responses lack the `Retry-After` header; webhook has no exemption/dedicated rule |

### Decisions

**D1: No new standalone gateway process.** nginx is the gateway (network edge + rate limiting + routing), unified rate limiting happens inside webman.
- Rationale: a standalone gateway container would need new dependencies/new deployment topology/duplicate authentication, which is over-engineering at the current single-instance scale;
- Trade-off: differentiated per-token/per-route rate limiting cannot be done at the gateway layer (nginx only has per-IP zones). Differentiation is covered by the application layer; nginx keeps only coarse-grained IP fallback (raise the current 10r/s to 100r/s to avoid harming business traffic, tune back to demo thresholds when k6-verifying).
- Evolution path: if multi-instance/multi-service arrives in the future, move the global limiter from `config/middleware.php` to a standalone gateway service as-is; the middleware is deployment-agnostic.

**D2: Unified rate limiting = global middleware + dual-dimension buckets (per-IP + per-token).**
- Remove `RateLimitMiddleware` from explicit routes (~12 places in route.php, verify by grep) and mount it in the `config/middleware.php` `''` global list (after WAF, before business middleware), **naturally covering all in-app routes (including the two /graphql routes)**.
- **Bucket semantics (explicit, anti-bypass)**: `ratelimit:ip:{realIp}:{rule}` and `ratelimit:tok:{sha256(token)}:{rule}` count independently in dual buckets; **either bucket exceeding its limit returns 429 (OR)**. AND semantics are forbidden — under AND, changing IP bypasses the per-IP bucket and changing token bypasses the per-token bucket.
- **Exemption list**: `/health*` (monitoring probes) and `/api/payments/webhook/stripe` (signature validation is the real defense + Stripe auto-backoff retries on 429 + nginx coarse-grained 100r/s fallback still applies; rate limiting adds no security value, only the risk of lost events/delayed crediting). All other routes are mandatory-limited.
- Response: `HTTP 429` + `Retry-After` header (take the **max** of the remaining windows of both buckets; fixed window uses Redis `PTTL` for exact remaining) + body `{code:429, message, retry_after}` (aligned with existing `Response::error`).
- Burst: enable the burst field — `rate` is the steady-state quota per window, `burst` is the overdraw allowance. Implemented as a Redis key count cap of `rate + burst` (overdraw within the fixed window), no sliding window needed (ponytail: fixed window has up to 2x window amplification at boundaries, sufficient for per-IP abuse on a single machine; switch to sliding window if stricter control is needed).
- Route→rule mapping: keep the existing `ROUTE_MAP`, add `'/graphql' => 'graphql'` (config/security.php:46 already has `{rate:30, burst:5, per:60}`); unknown routes fall to `default` (60/60s).
- Redis unavailable: keep the existing fail-open behavior (catch Exception and let through) — nginx 100r/s coarse-grained fallback still applies.
- **Scope**: service container only. admin is a separate container (nginx-admin.conf has no limit_req, currently unlimited); service config and service middleware changes do not touch admin — admin rate limiting is out of P4.1 scope, to be decided separately.

**D3: Rate limiting before authentication.** The global middleware sits before AuthMiddleware (order in middleware.php is execution order), so the per-token bucket degrades to the per-IP bucket for requests without a token; requests with a token are counted against the token bucket even on anonymous paths (e.g. /api/products) — preventing shared-token abuse.

### Impact

| Item | Change |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | Rework: per-token bucket, burst, Retry-After, graphql rule |
| `service/config/middleware.php` | Append RateLimitMiddleware to the `''` list; remove all explicit mount points from route.php |
| `service/config/security.php` | Keep `default` {60,10,60} unchanged (acceptance threshold = rate+burst = 70); `graphql` {30,5,60} already exists, no addition needed; burst field reused |
| `service/config/route.php` | Remove ~12 explicit `RateLimitMiddleware::class` mount points (per actual grep; auth/supplier/admin groups) |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s (coarse-grained fallback, avoid throttling business on top of the global middleware) |
| Tests | Tests in the service suite relying on explicit middleware mounting must be synced; add middleware unit tests |

### Acceptance (k6)

```
# Pick any anonymous route (e.g. GET /api/products) and /graphql, hit each with 200 requests/10s:
# All requests above the threshold return 429 with a Retry-After header; below the threshold all 200.
# Assert: 429 count == total requests - threshold; /graphql also limited (the original gap).
```

---

## P4.2 Full-Chain Multi-Currency Consistency (incl. fee rounding strategy)

### Current State (confirmed by direct reading)

- **Storage**: all amounts in `install.sql` are DECIMAL — balance/frozen `(16,4)`, order subtotal/discount/tax/total, line items unit_price/total_price `(12,4)`, `exchange_rate DECIMAL(12,6)` already on `orders` and `payment_transactions`; `user_balances` is split per currency (per-currency ledger).
- **Exchange rate source**: `service/app/cron/ExchangeRateSync.php` already implemented — external free API (`EXCHANGE_RATE_API_URL` env configurable, defaults to exchangerate-api.com) syncs hourly to Redis `exchange_rate:{CURRENCY}`; `OrderService::getExchangeRate` reads the Redis snapshot at order time (USD always 1.0) and writes it to the order `exchange_rate` field. **External dependency already exists and the source is env-swappable, nothing new needed.**
- **fee truncation problem**: `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath **truncates** at scale (not round-half-up), direction **short-collects** <0.0001/order; also `total_amount = amount + fee` for amounts with 5+ decimals (e.g. 10.12345) can diverge from the order total after truncation.
- **suspend check** already judges per-currency balance (multi-currency), Billing meters per meter (usage_rates unit price DECIMAL(12,4)).

### Decisions

**D4: Unified amount invariant — one internal precision per currency, rounding happens at a single point.**
- Internal calculations uniformly use `DECIMAL(12,4)` (order granularity) and `DECIMAL(16,4)` (balance granularity); every multiplication must pass through `bcround(x, 4, PHP_ROUND_HALF_UP)`, `bcadd/bcsub` only for same-precision addition/subtraction (exact by themselves).
- New single amount helper `service/common/money/Money.php` (~40 lines):
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` — idempotent; `round()` has precision risks on floats, must use the string path: `bcadd($v, '0', $scale+1)` then judge HALF-UP by the digit at $scale+1 (mind negative handling in implementation; use bccomp against abs).
  - Any amount field must pass `bcround(…, 4)` before being written to the database; **forbidden** to use `(float)`/`round()` mid-calculation-chain (the existing `round((float) bcmul(...))` in StripeChannel is exactly such a hazard).
- Existing `calculateFee` becomes: `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — first align amount to 4 digits, then multiply by rate, then HALF_UP to 4 digits. **Direction fix: short-collect → standard half-up rounding** (per-order difference ≤0.00005, expected value tends to 0). **Negative-fee clamp to 0 kept** (behavior of current PaymentRouter.php:44 unchanged).

**D5: Order identity and channel-fee separation (zero reconciliation drift).** Two independent facts:
- **Order-line identity** `total − subtotal − tax + discount == 0` (exact to 0.0000): in the order-creation chain (OrderService::createFromCart) line items `bcround(bcmul(price, qty, 8), 4)` (high-precision multiply then round, avoiding double truncation) → subtotal = sum of lines (exact) → total = subtotal + tax − discount (same-precision add/subtract, exact). **tax is currently always 0** (createFromCart does not set tax, install.sql:345 DEFAULT 0.0000) — no new tax calculation (out of P4.2 scope, compliance implications); assertions use the current `tax=0` value but the formula keeps the tax term.
- **Channel fee**: channel_fee independently `bcround(…,4)`, payment channel amount = total + channel_fee, exactly equal at 4dp.
- Validation: `PaymentController::reconcile*` and reports (Report) base on the stored order total, no recomputation.

**D6: Exchange rate snapshot and conversion point.**
- Rate source stays ExchangeRateSync cron + Redis (exists, untouched). The `exchange_rate` column is already snapshotted with orders/transactions (DECIMAL(12,6)); **conversion point = settlement (write-to-DB) time**, no real-time conversion for display (displaying a live price is just the UI layer multiplying the current Redis rate, doesn't affect the books).
- Rule: **anything touching books/balances must use the order snapshot rate; anything pricing/displaying may use the current rate**. Mixing the two rates in the settlement chain is forbidden.
- The balance layer is already a per-currency ledger (user_balances rows by currency), no conversion to a single base currency; when reports need a base currency (e.g. USD), aggregate with the order snapshot rate, and the aggregate result still passes `bcround(…,4)` (ponytail: cross-currency aggregate rounding error lands in the totals; split per-currency totals if later audits require).

**D7: Change list (incl. review points of existing multi-currency code).**
- Change: `PaymentRouter::calculateFee`, `StripeChannel` (amount input alignment + remove float round, incl. convertToSmallest to bcround($total,2)), `OrderService::createFromCart` (line item/subtotal/total sequential rounding), **`Order/Model/Coupon.php::calculateDiscount` (:31-44 currently float+round, change to bcround string path)**, `PaymentController::reconcile*` (assert D5 identity), `Report/*` (unified bcround aggregation).
- Review, no change: Billing meters (unit prices already DECIMAL(12,4), align billing with bcround), suspend check (per-currency balance judgment, already correct), `Cron/ExchangeRateSync.php` (write Redis keeping 6-digit source value, untouched).
- New: `service/common/money/Money.php` + unit tests (HALF_UP boundaries: 0.00005 → 0.0001, 0.00004 → 0.0000, **-0.00005 → -0.0001 (negative rounds away from zero)**, idempotency).
- Migration: no structural change to `install.sql` (exchange_rate column already exists); if historical orders have <0.0001 trailing differences from fee truncation, these are irreversible book differences, **record only, do not patch** (patching one entry would change historical reconciliation); add an audit query `fee_drift` listing orders with |total−subtotal−tax+discount|>0 for manual review.

### Acceptance

```
# k6 (P4.1): fixed single IP. GET /api/products and /graphql, 200 requests each/10s:
#   default rule threshold = rate+burst = 70/60s window → expect 429 ≈ 200−70 = 130 (±1-2 window boundary)
#   graphql rule threshold = 35 → expect 429 ≈ 165; both with Retry-After header; low traffic all 200
# Unit tests (P4.2): Money::bcround boundaries (0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, idempotent)
# Identity test: build multi-line orders (5-decimal unit prices + coupon), assert total−subtotal−tax+discount == 0 always
# Regression: existing service 491 tests all green (incl. amount assertions)
```

---

## Risks and Review

- **D2 global limiter risk (medium)**: global mounting affects all service endpoints (**not admin** — separate container, service config changes don't touch it), webhook exempted; improper thresholds could harm traffic, security-auditor must review default thresholds and the fail-open policy. **admin container currently unlimited** (nginx-admin.conf has no limit_req), P4.1 excludes it, decided separately.
- **D4/D5 funds chain (high)**: rounding direction change affects every order amount (short-collect → standard half-up), requires security-auditor review + two-person review; historical data recorded only, not patched.
- **Dependencies**: no new composer dependencies; no new tables; nginx config change requires reload.

```yaml
design:
  objective: "P4.1 unified rate limiting effective on all routes (incl. graphql) + P4.2 multi-currency rounding strategy aligned, zero drift on the accounting identity"
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
    - tests/ (middleware + money + identity)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # no structural change; exchange_rate column already exists; tax stays 0, not added
  client_impact: ["flutter", "harmonyos"]  # 429 needs graceful client handling; admin container unaffected
  risk: "high"       # D4/D5 funds chain
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After all routes (k6 single IP, 429≈130/165)", "graphql rate limit gap closed", "webhook exempt from 429", "dual-bucket OR semantics (token/IP switch cannot bypass)", "fee HALF_UP boundaries incl. negatives", "Coupon bcround string path", "total−subtotal−tax+discount==0 identity", "historical order fee_drift audit query"]
  dependencies: []
```
