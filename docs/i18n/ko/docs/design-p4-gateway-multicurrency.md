# P4.1 + P4.2 설계：독립 API 게이트웨이/통합限流 + 다중 통화 전 체인 일관성

> 버전：2026-08-17 v1｜아키텍트 산출물, gateway-impl / multicurrency-impl 구현 및 reviewer-gate 재검 용도
> 근거：docs/team-plan.md v2 Phase 4、docs/architecture.md、기존 코드 실독

---

## P4.1 독립 API 게이트웨이 + 통합限流

### 현황（실독 확인）

| 계층 | 현황 |
|----|------|
| 엣지 게이트웨이 | docker/nginx.conf가 service L7 게이트웨이 담당：`limit_req_zone api 10r/s`（전체限流）、proxy_pass 8787（service）、8282（ws）。**admin은 별도 컨테이너**（Dockerfile admin target，nginx-admin.conf listen 8788 proxy 8788），**limit_req 없음** |
| 애플리케이션限流 | `service/common/security/RateLimitMiddleware.php` 기존 구현：Redis INCR+expire 고정 창, **per-IP만**, `ROUTE_MAP` 기준 규칙 선택, **명시적 라우트**에 부착（route.php 총 ~12곳） |
| 규칙 구성 | `config/security.php rate_limits`：default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, 모두 rate/burst/per 포함, 단 **burst 필드는 현재 미사용** |
| 글로벌 미들웨어 | `config/middleware.php` `''` 키가 모든 라우트에 적용 지원（WAF/GeoBlock/Security 등 10개가 여기 있음） |
| 갭 | `/graphql`（public + authenticated 두 라우트）**限流 없음**；per-token限流 부재；429 응답에 `Retry-After` 헤더 없음；webhook 면제/전용 규칙 없음 |

### 결정

**D1：독립 게이트웨이 프로세스는 신설하지 않는다.** nginx가 게이트웨이（네트워크 엣지 +限流 + 라우트 분기），webman 내에서 통합限流 수행.
- 이유：독립 gateway 컨테이너는 새 의존성/새 배포 토폴로지/이중 인증이 필요하며, 현재 단일 인스턴스 규모에서는 과설계；
- 트레이드오프：게이트웨이 계층에서 token별/라우트별 차등限流 불가（nginx는 per-IP 구간만）. 차등은 애플리케이션 계층에서 보완, nginx 계층은 조립도 IP 폴백만 유지（기존 10r/s를 100r/s로 올려 비즈니스 오탐 방지, k6 검증 시 데모 임계값으로 조정）.
- 진화 경로：향후 다중 인스턴스/다중 서비스 시 `config/middleware.php`의 전역限流기를 그대로 독립 gateway 서비스로 옮기면 되며, 미들웨어는 배포 형태를 인지하지 않음.

**D2：통합限流 = 전역 미들웨어 + 이중 차원 버킷（per-IP + per-token）.**
- `RateLimitMiddleware`를 명시적 라우트에서 제거（route.php 실제 ~12곳, grep 기준），`config/middleware.php` `''` 전역 목록에 부착（WAF 이후、비즈니스 미들웨어 이전），**자연히 모든 애플리케이션 내 라우트（/graphql 두 곳 포함）를 커버**.
- **버킷 의미（명확화, 우회 방지）**：`ratelimit:ip:{realIp}:{rule}`과 `ratelimit:tok:{sha256(token)}:{rule}` 이중 버킷 독립 카운트, **어느 버킷이든 초과 시 429（OR）**. AND로 구현 금지——AND면 IP 교체로 per-IP 버킷 우회, token 교체로 per-token 버킷 우회 가능.
- **면제 목록**：`/health*`（모니터링 프로브）와 `/api/payments/webhook/stripe`（서명 검증이 실제 방어선 + Stripe 429 자동 백오프 재시도 + nginx 조립도 100r/s 폴백 여전히 유효；限流은 보안 이득 없고 이벤트 유실/입금 지연 위험만 있음）. 그 외 모든 라우트는 필수限流.
- 응답：`HTTP 429` + `Retry-After` 헤더（이중 버킷 창 잔여 중 **max** 취함, 고정 창은 Redis `PTTL`로 정확한 잔여 계산）+ body `{code:429, message, retry_after}`（기존 `Response::error` 정렬）.
- 버스트：burst 필드 활성화——`rate`는 창 내 정상 할당량, `burst`는 초과 사용 가능 분. Redis key 카운트 상한 `rate + burst`로 구현（고정 창 내 초과）, 슬라이딩 윈도우 불필요（ponytail: 고정 창은 경계에서 2배 창 확대 발생, per-IP는 단일 머신 남용에 충분；더 엄격하게 하려면 슬라이딩 윈도우로 교체）.
- 라우트→규칙 매핑：기존 `ROUTE_MAP` 유지, `'/graphql' => 'graphql'` 추가（config/security.php:46에 이미 `{rate:30, burst:5, per:60}`）；미지 라우트는 `default`（60/60s）.
- Redis 불가：기존 fail-open 유지（catch Exception 방출）——nginx 100r/s 조립도 폴백 유효.
- **범위**：service 컨테이너만. admin은 별도 컨테이너（nginx-admin.conf limit_req 없음、현재限流 없음）, service/config와 service 미들웨어 변경은 admin에 영향 없음——admin限流는 P4.1 범위 밖, 별도 결정.

**D3：인증 전限流.** 전역 미들웨어가 AuthMiddleware 이전（middleware.php 순서가 실행 순서）, 따라서 per-token 버킷은 token 없는 요청에 대해 per-IP 버킷으로 퇴화；token 있는 요청은 경로가 익명이어도（예: /api/products）token 버킷에 카운트——공유 token 남용 방지.

### 영향 범위

| 항목 | 변경 |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | 개조：per-token 버킷、burst、Retry-After、graphql 규칙 |
| `service/config/middleware.php` | `''` 목록에 RateLimitMiddleware 추가；route.php의 모든 명시적 부착 지점 제거 |
| `service/config/security.php` | `default` {60,10,60} 유지（수용 임계값 = rate+burst = 70）；`graphql` {30,5,60} 이미 존재, 추가 불필요；burst 필드 그대로 사용 |
| `service/config/route.php` | ~12곳 명시적 `RateLimitMiddleware::class` 부착 제거（grep 실측 기준, auth/supplier/admin 그룹） |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s（조립도 폴백, 전역 미들웨어 위에 비즈니스 카운트 중복 방지） |
| 테스트 | service 스위트에서限流 미들웨어 명시적 부착에 의존하는 테스트 동기화；미들웨어 단위 테스트 추가 |

### 수용 기준（k6）

```
# 익명 라우트 하나（예: GET /api/products）와 /graphql 각각 200 요청/10s 전송:
#限流 임계값 초과분 전부 429, 응답에 Retry-After 포함；임계값 미만은 전부 200.
# 단언: 429 카운트 == 전체 요청 - 임계값；/graphql도 동일 적용（기존 갭）.
```

---

## P4.2 다중 통화 전 체인 일관성（fee 반올림 전략 포함）

### 현황（실독 확인）

- **저장**：`install.sql` 전 금액 DECIMAL —— 잔액/동결 `(16,4)`，주문 subtotal/discount/tax/total、라인 항목 unit_price/total_price `(12,4)`，`exchange_rate DECIMAL(12,6)` 이미 `orders`、`payment_transactions`에 존재；`user_balances`는 통화별 행 분리（통화별 원장）.
- **환율 소스**：`service/app/cron/ExchangeRateSync.php` 이미 구현——외부 무료 API（`EXCHANGE_RATE_API_URL` env 설정 가능, 기본 exchangerate-api.com）매시간 Redis `exchange_rate:{CURRENCY}`로 동기화；`OrderService::getExchangeRate`가 주문 시 Redis 스냅샷 읽음（USD는 항상 1.0）주문 `exchange_rate` 필드에 기록. **외부 의존성 이미 있고 env로 소스 교체 가능, 신규 불필요.**
- **fee 절삭 문제**：`PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` —— bcmath는 scale 기준 **절삭**（반올림 아님）, 방향상 **적게 받음** <0.0001/건；또한 `total_amount = amount + fee`는 소수 5자리 이상 amount（예: 10.12345）에서 절삭 후 주문 total과 불일치 가능.
- **suspend 검사**는 이미 통화별 잔액 판정（다중 통화）, Billing은 meter 과금（usage_rates 단가 DECIMAL(12,4)）.

### 결정

**D4：통합 금액 불변식 —— 통화별 내부 정밀도 하나, 반올림은 단일 지점에서만 발생.**
- 내부 계산 통일 `DECIMAL(12,4)`（주문 정밀도）와 `DECIMAL(16,4)`（잔액 정밀도），모든 곱셈 후 반드시 `bcround(x, 4, PHP_ROUND_HALF_UP)` 경유, `bcadd/bcsub`는 동일 정밀도 덧셈/뺄셈만（자체적으로 정확）.
- 유일한 금액 헬퍼 신설 `service/common/money/Money.php`（약 40줄）：
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` —— 멱등；`round()`는 부동소수점 정밀도 위험, 반드시 문자열 경로：`bcadd($v, '0', $scale+1)` 후 $scale+1번째 자리 기준 HALF-UP 판정（구현 시 음수 처리 주의, bccomp로 abs 판정하면 됨）.
  - 금액 필드 기록 전 반드시 `bcround(…, 4)` 통과；**계산 체인 중간에 `(float)`/`round()` 금지**（기존 StripeChannel의 `round((float) bcmul(...))`이 바로 함정）.
- 기존 `calculateFee` 변경：`$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` —— 먼저 amount를 4자리 정렬, 곱셈 후 HALF_UP 4자리. **방향 수정：적게 받기 → 표준 반올림**（건당 차이 ≤0.00005, 기대값 0에 수렴）. **음수 fee 0 클램프 보호 유지**（현 코드 PaymentRouter.php:44 동작 불변）.

**D5：주문 항등식과 채널 수수료 분리（대조 영점 드리프트）.**
- **주문 라인 항등식** `total − subtotal − tax + discount == 0`（0.0000 정확도）：주문 생성 체인（OrderService::createFromCart）라인 항목 `bcround(bcmul(price, qty, 8), 4)`（고정밀 곱셈 후 반올림, 이중 절삭 방지）→ subtotal = 라인 합（정확）→ total = subtotal + tax − discount（동일 정밀도 덧셈/뺄셈, 정확）. **tax는 현재 항상 0**（createFromCart가 tax 설정 안 함, install.sql:345 DEFAULT 0.0000）——세금 계산 신설 안 함（P4.2 범위 초과、규정 영향 있음）, 단언은 `tax=0` 현재값 기준 구현하되 수식에는 tax 항목 유지.
- **채널 수수료**：channel_fee 독립 `bcround(…,4)`, 결제 채널 금액 = total + channel_fee가 4dp 정확히 일치.
- 검증：`PaymentController::reconcile*`와 리포트（Report）는 주문 저장 total 기준, 재계산하지 않음.

**D6：환율 스냅샷과 환산 지점.**
- 환율 소스는 ExchangeRateSync cron + Redis 유지（기존, 변경 없음）. `exchange_rate` 컬럼은 주문/거래와 함께 스냅샷（DECIMAL(12,6)），**환산 지점 = 정산（기록） 시점**, 표시 시 실시간 환산 안 함（표시 실시간 가격은 UI 계층에서 현재 Redis 환율 곱셈일 뿐, 장부 영향 없음）.
- 규칙：**장부/잔액 관련은 반드시 주문 스냅샷 rate；가격 표시/전시는 현재 rate 허용**. 정산 체인에서 두 rate 혼용 금지.
- 잔액 계층은 이미 통화별 원장（user_balances 통화별 행）, 통합 기준 통화 환산 안 함；리포트가 기준 통화（예: USD） 필요 시 주문 스냅샷 rate로 집계, 집계 결과도 `bcround(…,4)` 통과（ponytail: 통화 간 집계 반올림 오차는 합계 자리에서 발생, 추후 감사에서 통화별 합계 요구 시 분리）.

**D7：변경 목록（기존 다중 통화 코드 재검 지점 포함）.**
- 변경：`PaymentRouter::calculateFee`、`StripeChannel`（금액 인자 정렬 + float round 제거, convertToSmallest를 bcround($total,2)로 변경 포함）、`OrderService::createFromCart`（라인 항목/subtotal/total 순차 반올림）、**`Order/Model/Coupon.php::calculateDiscount`（:31-44 현재 float+round, bcround 문자열 경로로 변경）**、`PaymentController::reconcile*`（D5 항등식 단언）、`Report/*`（집계 통일 bcround）.
- 재검 후 불변：Billing meters（단가 이미 DECIMAL(12,4), 과금은 bcround 정렬이면 충분）、suspend 검사（통화별 잔액 판정, 이미 정확）、`Cron/ExchangeRateSync.php`（Redis에 6자리 원문 기록, 변경 없음）.
- 신설：`service/common/money/Money.php` + 단위 테스트（HALF_UP 경계：0.00005 → 0.0001、0.00004 → 0.0000、**-0.00005 → -0.0001（음수는 0에서 멀어짐）**、멱등성）.
- 마이그레이션：`install.sql` 구조 변경 없음（exchange_rate 컬럼 이미 존재）；과거 주문 fee 절삭으로 <0.0001 꼬리 차이 발생 시 장부상 되돌릴 수 없는 차이, **기록만 하고 보정하지 않음**（보정하면 과거 대조가 바뀜）, 신규 감사 쿼리 `fee_drift`로 |total−subtotal−tax+discount|>0 주문 나열, 수동 확인 용도.

### 수용 기준

```
# k6（P4.1）：고정 단일 IP. GET /api/products와 /graphql 각각 200 요청/10s:
#   default 규칙 임계값 = rate+burst = 70/60s 창 → 기대 429 ≈ 200−70 = 130（창 경계 1-2 허용）
#   graphql 규칙 임계값 = 35 → 기대 429 ≈ 165；모두 Retry-After 헤더 포함；저트래픽은 전부 200
# 단위 테스트（P4.2）：Money::bcround 경계（0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, 멱등성）
# 항등식 테스트：다중 라인 주문 구성（소수 5자리 단가 + 쿠폰 포함）, total−subtotal−tax+discount == 0 항상 성립 단언
# 회귀：기존 service 491 tests 전부 그린（금액 단언 포함）
```

---

## 리스크와 리뷰

- **D2 전역限流기 리스크（중）**：전역 부착은 service 전체 엔드포인트 영향（**admin 제외**——별도 컨테이너, service/config 변경과 무관）, webhook은 면제；임계값 부적절 시 오탐 가능, security-auditor가 기본 임계값과 fail-open 전략 재검 필요. **admin 컨테이너는 현재限流 없음**（nginx-admin.conf limit_req 없음）, P4.1에 미포함, 별도 결정.
- **D4/D5 자금 체인（고）**：반올림 방향 변경은 건당 주문 금액 영향（적게 받기 → 표준 반올림）, security-auditor 리뷰 + 2인 재검 필요；과거 데이터는 기록만 보정 안 함.
- **의존성**：신규 composer 의존성 없음；신규 테이블 없음；nginx 구성 변경은 리로드 필요.

```yaml
design:
  objective: "P4.1 통합限流 전 라우트 적용（graphql 포함）+ P4.2 다중 통화 반올림 전략 정렬、장부 항등식 영점 드리프트"
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
    - tests/ (middleware + money + 항등식)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # 구조 변경 없음；exchange_rate 컬럼 이미 존재；tax 0 유지 신설 안 함
  client_impact: ["flutter", "harmonyos"]  # 429는 클라이언트 우아한 처리 필요；admin 컨테이너 영향 없음
  risk: "high"       # D4/D5 자금 체인
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After 전 라우트（k6 단일 IP, 429≈130/165）", "graphql限流 갭 해소", "webhook 면제 429 없음", "이중 버킷 OR 의미（token 교체/IP 교체 모두 우회 불가）", "fee HALF_UP 경계 음수 포함", "Coupon bcround 문자열화", "total−subtotal−tax+discount==0 항등식", "과거 주문 fee_drift 감사 쿼리"]
  dependencies: []
```
