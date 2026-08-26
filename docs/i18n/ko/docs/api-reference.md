# CloudPlatform API 인터페이스 문서

## 개요

**Base URL:** `https://api.example.com`

**버전 관리:** HTTP 요청 헤더 `X-Api-Version: v1`로 지정. 누락 시 기본 `v1`, 지원하지 않는 버전은 `400` 반환. 버전은 URL 경로에 없음.

**인증 방식:**

| 단말 | 방식 | 요청 헤더 |
|----|------|--------|
| 사용자 단말 | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| 관리 단말 | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| 공급업체 외부 API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | 서명 검증 | `Stripe-Signature: ...` |

**클라이언트 플랫폼:** 모든 API 요청에 `X-Client-Platform` 헤더 권장, `ios/android/macos/windows/linux/web/harmonyos/ipados` 지원.

**다국어:** 모든 API 요청에 `Accept-Language` 헤더 권장（`zh-CN` / `en-US`）, 번역 텍스트와 JSON 다국어 필드 반환값에 영향. 누락 시 기본 `en-US`.

---

## 통합 응답 형식

### 성공

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### 페이지네이션

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### 오류

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP 상태 코드

| code | 설명 |
|------|------|
| 0 | 성공 |
| 400 | 요청 파라미터 오류 / 지원하지 않는 API 버전 / 지원하지 않는 클라이언트 플랫폼 |
| 401 | 미인증 |
| 403 | 권한 없음 / WAF 차단 |
| 404 | 리소스 없음（firstOrFail/findOrFail 미적중 시 통일 404 매핑） |
| 413 | 요청 본문 과대 (>10MB) |
| 414 | URL 과장 (>2KB) |
| 415 | 지원하지 않는 Content-Type |
| 422 | 파라미터 검증 실패 |
| 429 | 요청 빈도 초과 |

---

## 라우트 그룹과 미들웨어 매트릭스

| 라우트 그룹 | 미들웨어 | 접두사 |
|--------|--------|------|
| 공개 | 전역 미들웨어 체인 | `/health`, `/api/*` |
| `/health` (내부) | 전역 + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | 전역 + Encryption | `/api/auth/*` |
| `/api` (사용자) | 전역 + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (민감) | 전역 + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | 공급업체 외부 API |
| `/admin/api` | 전역 + Encryption + Auth + AdminRole | 관리 백오피스 API |
| `/admin/api` (민감) | 전역 + Encryption + Auth + AdminRole + Confirmation | 민감 관리 작업 |

---

## 1. 공개 엔드포인트
### 헬스 체크

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### 서비스 상태

```
GET /api/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### 상품

```
GET /api/products
   파라미터: category_id, region_id, keyword, supplier_id, page (기본1), page_size (기본20, 최대50)
  → 페이지네이션 상품 목록 (category, skus.regionPrices 포함)

GET /api/products/search
   파라미터: q (필수), page
  → Elasticsearch 전문 검색

GET /api/products/{id}
  → 상품 상세 (category, skus, images, reviews 포함)

GET /api/products/{productId}/reviews
  → 평가 목록 + avg_rating + total + distribution
   상태 열거: pending(검토 대기)/approved(승인됨)/rejected(거부됨), approved만 반환
```

### 도메인

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → 사용 가능 TLD 목록 (Redis 캐시 1h)
```

### 고객센터

```
GET /api/help
   파라미터: category, page
   헤더: Accept-Language (en-US / zh-CN)
  → 페이지네이션 도움말 문서

GET /api/help/categories
  → 문서 카테고리 목록

GET /api/help/{slug}
  → 문서 단일 상세
```

---

## 2. 인증 엔드포인트
### 캡차

```
POST /api/captcha/create
   헤더: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### 가입

```
POST /api/auth/register
   헤더: X-Encrypted: 1
   본문(암호화): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

빈도 제한: 3 req/min
```

- `deviceFingerprint`（선택）: 가입 시 디바이스 지문 기록, 로그인/갱신 시 검증；미지참 시 지문 바인딩 건너뜀
- email/phone은 저장 전 Encryptable 결정적 암호화（ECB, 암호문 등가 조회）, 고유성 검증과 로그인 조회 모두 암호문 기준

### 로그인

```
POST /api/auth/login
   헤더: X-Encrypted: 1
   본문(암호화): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

빈도 제한: 5 req/min, 5회 실패 시 15min 잠금
```

- `login`은 암호문 등가 조회（Encryptable 결정적 암호화）, 평문 조회는 암호화 컬럼에 미적중

### Token 갱신

```
POST /api/auth/refresh
   헤더: X-Encrypted: 1
   본문(암호화): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint`가 가입 시 기록과 불일치 → 401 `Device mismatch`；갱신 토큰은 암호문 해시로 조회

### OAuth

지원 제공자: google, apple, facebook, x, microsoft, linkedin, github
（.env의 `{PROVIDER}_OAUTH_CLIENT_ID` 등 구성으로 활성화 여부 결정）

```
GET /api/auth/{provider}            → { url }        # 인증 페이지로 이동（PKCE/nonce 리플레이 방지）
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  본문: { code, state }
```

- Apple/Microsoft는 id_token 반환, 서버가 JWKS로 서명, iss/aud/exp/nonce 검증
- 모든 제공자는 `email_verified=true`여야 로그인 허용, 아니면 422
- `state` 누락 또는 불일치 → 422（CSRF 방지, 5분 만료）
- OAuth 흐름 빈도 제한: 60초당 10회（redirect + callback）

### 비밀번호 재설정

```
POST /api/auth/forgot-password
   본문: { email }
  → 인증 코드 이메일 발송

POST /api/auth/reset-password
   본문: { email, code, password }
  → 재설정 성공
  → 오류 누적 5회 → 429 빈도 제한 10분
```

### 이메일 인증

```
GET /api/auth/verify-email?token=xxx
  → 인증 성공
```

### SMS 인증

```
POST /api/auth/send-sms
   본문: { phone }
  → SMS 인증 코드 발송 (60s 쿨다운)
```

### TOTP 2단계 인증

```
POST /api/user/totp/setup        → { secret, qr_url }        # 미영구화, 10분 내 verify로 적용
POST /api/user/totp/verify       본문: { code } → { verified: true }   # 최초 활성화 시 성공 메시지 반환
POST /api/user/totp/disable      본문: { password }             # 비밀번호 확인 필요, 아니면 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # 매회 8개 일회용 코드 생성, 비밀번호 확인 필요, 아니면 403
POST /api/auth/login/recovery    본문: { login, password, recovery_code }
```

- 사용자가 TOTP 활성화 후 로그인 시 `totp_code` 필수, 아니면 401
- TOTP 연속 오류 5회 → 해당 사용자 15분 잠금（login_lock）

---

## 3. 사용자 엔드포인트（인증 필요）
### 프로필

```
GET /api/user/profile
PUT /api/user/profile
   본문: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC 실명 인증

```
POST /api/user/kyc
   본문: { id_type, id_number, real_name, front_image, back_image }
```

### 잔액

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
   파라미터: page
  → 잔액 변동 기록
```

### 주소 관리

```
GET /api/user/addresses
POST /api/user/addresses
   본문: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### 세션 관리

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → 지정 세션 폐기

DELETE /api/user/account
   본문: { confirm_password }
  → GDPR 계정 탈퇴
```

### 알림

```
GET /api/user/notifications
   파라미터: page
  → 페이지네이션 알림 목록

POST /api/user/notifications/{id}/read
  → 읽음 표시

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
   본문: { email: {order_paid: true, ...}, push: {...} }
```

### 이메일

```
POST /api/user/resend-verify-email
  → 인증 메일 재발송
```

### 파일 업로드

```
POST /api/upload
   본문: multipart/form-data { file, type: avatar/kyc/attach }
   제한: avatar 2MB, kyc 5MB, attach 10MB
   허용: jpg, jpeg, png, gif, pdf
   설명: 타입 화이트리스트 검증 + finfo 내용 스니핑（확장자와 MIME 불일치 → 422）
```

---

## 4. 장바구니와 주문
### 장바구니

```
POST /api/cart
   본문: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
   본문: { quantity }
```

> 금액 필드 규약（D4/P4.2 확정）: 모든 금액은 string, 소수 4자리（예: "9.9900"）, number/float 금지——
> MySQL DECIMAL 컬럼의 PDO 원시 출력과 일치, 정밀도는 4dp 문자열 자체가 담당. 주문/잔액/리포트 전 엔드포인트 적용.

### 주문

```
POST /api/orders
  → 장바구니에서 주문 생성
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
   파라미터: page, status (pending/paid/provisioning/completed/refunded, 잘못된 값은 400)
  → 내 주문 목록

GET /api/orders/{id}
  → 주문 상세 (items, timeline 포함)

GET /api/orders/{id}/payment-methods
  → 사용 가능 결제 채널 + 채널별 실지급 금액

POST /api/orders/{id}/pay    🔒 비밀번호 확인
   본문: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### 쿠폰

```
POST /api/coupons/validate
   본문: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（예: "2.0000"）

422: 무효/만료/사용 조건 미충족
```

### 인보이스

```
GET /api/invoices
   파라미터: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → PDF 다운로드
```

---

## 5. 리소스 관리
```
GET /api/resources
   파라미터: page, status
  → 내 리소스 목록

GET /api/resources/{id}
  → 리소스 상세

GET /api/resources/{id}/status
  → 리소스 현재 상태 + 지표

GET /api/resources/{id}/console
  → VNC/콘솔 URL

POST /api/resources/batch
   본문: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. DNS 관리
```
GET /api/dns/{domain}
  → DNS 레코드 목록

POST /api/dns/{domain}/records
   본문: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 비밀번호 확인
```

---

## 7. 티켓
```
POST /api/tickets
   본문: { resource_id?, category, priority?, title, content }

GET /api/tickets
   파라미터: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
   본문: { content }
```

---

## 8. 공급업체（내부 API）
```
POST /api/supplier/apply
   본문: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → 정산서 목록

POST /api/supplier/withdraw    🔒 비밀번호 확인
   본문: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
   본문: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## 9. 공급업체 외부 API
**인증:** `Authorization: Bearer sk_xxx...`（SHA256 검증）

**빈도 제한:** 120 req/min（출금 10 req/min）

```
GET /api/supplier/external/orders
   파라미터: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → 주문 상세（해당 공급업체 연관만）

GET /api/supplier/external/resources
   파라미터: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
   파라미터: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
   본문: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
   파라미터: page
```

---

## 10. 관리 백오피스 API
**인증:** JWT Bearer Token + Admin 역할

### 대시보드

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### 사용자 관리

```
GET /admin/api/users               파라미터: page, status, keyword
GET /admin/api/users/export       → Excel 다운로드
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status   본문: { status }
```

### KYC 심사

```
GET /admin/api/kyc                 파라미터: page, status

POST /admin/api/kyc/{id}/approve   🔒 비밀번호 확인
   본문: { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 비밀번호 확인
   본문: { confirm_password, reason }
```

### 상품 관리

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 비밀번호 확인
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → CSV 다운로드
POST /admin/api/products/import        → CSV 업로드 upsert
```

### 주문 관리

```
GET /admin/api/orders               파라미터: page, status, keyword
GET /admin/api/orders/export       → Excel 다운로드
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 비밀번호 확인
   본문: { confirm_password, amount?, reason }
```

### 결제 관리

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions   파라미터: page, channel, status
GET /admin/api/payments/reconcile      파라미터: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run   파라미터: date; 일별 대조 트리거
```

### 리소스와 개통

```
GET /admin/api/provisioning/tasks               파라미터: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
   본문: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 비밀번호 확인
GET /admin/api/provisioning/hosts
```

### 공급업체 관리

```
GET /admin/api/suppliers                  파라미터: page, status
GET /admin/api/suppliers/export          → Excel 다운로드

POST /admin/api/suppliers/{id}/approve    🔒 비밀번호 확인
POST /admin/api/suppliers/{id}/settle     🔒 비밀번호 확인
   본문: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 비밀번호 확인
```

### 공급업체 API Key

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
   본문: { name }
  ← { api_key: "sk_xxx...", prefix } (한 번만 표시)

DELETE /admin/api/suppliers/api-keys/{id}
```

### 티켓 관리

```
GET /admin/api/tickets                   파라미터: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign      본문: { user_id }
POST /admin/api/tickets/{id}/close
```

### 도메인 관리

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
   본문: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones              파라미터: page
GET /admin/api/domains/transfers          파라미터: page
POST /admin/api/domains/transfers/{id}/approve
```

### 알림 관리

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
   본문: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log          파라미터: page
```

### 쿠폰

```
GET /admin/api/coupons
POST /admin/api/coupons
   본문: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### 도움말 문서

```
GET /admin/api/help
POST /admin/api/help
   본문: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → 소프트 삭제 (status=archived)
```

### 클라우드 벤더 API

```
GET /admin/api/providers
POST /admin/api/providers
   본문: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → 비활성화 (status=disabled)
```

### Webhook 관리

```
GET /admin/api/webhooks
POST /admin/api/webhooks
   본문: { url }
DELETE /admin/api/webhooks               본문: { id }
POST /admin/api/webhooks/test            본문: { url }
```

### 리포트

```
GET /admin/api/reports/revenue            파라미터: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL)과 bcmath 집계 일치）
GET /admin/api/reports/supplier           파라미터: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region             파라미터: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### 모니터링

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### 감사 로그

```
GET /admin/api/audit-logs                 파라미터: page, user_id, action, from, to
  → 페이지네이션 감사 로그 (client_platform 포함)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
   본문: { action: enable/disable/toggle/reset }
```

### 시스템 구성

```
PUT /admin/api/system/config              🔒 비밀번호 확인
```

### 상품 가져오기/내보내기

```
GET /admin/api/products/export           → CSV 다운로드
POST /admin/api/products/import          → CSV 업로드 upsert
```

### 공급업체 + 사용자 내보내기

```
GET /admin/api/suppliers/export          → Excel 다운로드
GET /admin/api/users/export              → Excel 다운로드
GET /admin/api/orders/export             → Excel 다운로드
```

---

## 11. SSL 인증서
### 사용자 단말

```
GET /api/ssl/plans
  → SSL 패키지 목록（DV/OV/EV, 가격에 register/renew/transfer 포함）

GET /api/ssl-certs
  → 내 인증서 목록（status: pending/active/expired/revoked 포함）

GET /api/ssl-certs/{id}
  → 인증서 상세（도메인, 발급 기관, 유효 기간, 갱신 상태）

GET /api/ssl-certs/{id}/download
  → 인증서 파일 다운로드（인증서 체인 + 개인 키）

POST /api/ssl-certs/{id}/auto-renew
   본문: { auto_renew: true/false }
  → 자동 갱신 전환
```

### 관리 단말

```
GET /admin/api/ssl/plans              → 패키지 목록
POST /admin/api/ssl/plans             → 패키지 생성
PUT /admin/api/ssl/plans/{id}         → 패키지 업데이트
DELETE /admin/api/ssl/plans/{id}      → 패키지 삭제
GET /admin/api/ssl/certs              → 전체 인증서
POST /admin/api/ssl/certs/{id}/revoke → 인증서 폐기
```

---

## 12. 객체 스토리지
S3 호환 객체 스토리지, 프리사인 URL로 업로드/다운로드, 키는 외부 유출 없음.

```
GET /api/storage/buckets
  → 내 스토리지 버킷 목록（사용량, 상태）

GET /api/storage/buckets/{id}
  → 버킷 상세

POST /api/storage/buckets/{id}/presign-upload
   본문: { filename, content_type, size }
  → { upload_url, object_key } 프리사인 업로드 URL（시간 제한）

POST /api/storage/buckets/{id}/presign-download
   본문: { object_key }
  → 프리사인 다운로드 URL（시간 제한）

GET /api/storage/buckets/{id}/credentials
  → 임시 접근 자격증명（단기 유효, SDK 직송용）
```

---

## 13. CDN 가속
### 사용자 단말

```
GET /api/cdn/domains
  → 내 CDN 도메인 목록（원본 서버, 상태, 패키지）

GET /api/cdn/domains/{id}
  → CDN 도메인 상세

POST /api/cdn/domains/{id}/purge
  → 캐시 삭제（전체 사이트 또는 지정 URL 목록）

GET /api/cdn/domains/{id}/stats
   파라미터: range (day/week/month)
  → 트래픽/요청 수/히트율 통계
```

### 관리 단말

```
GET /admin/api/cdn/domains            → 전체 CDN 도메인
PUT /admin/api/cdn/domains/{id}       → 도메인 패키지/구성 업데이트
```

---

## 14. 사용량 과금
```
GET /admin/api/billing/rates          → 과금 요율 목록（리소스 타입/사양별）
POST /admin/api/billing/rates         → 요율 생성
PUT /admin/api/billing/rates/{id}     → 요율 업데이트
DELETE /admin/api/billing/rates/{id}  → 요율 삭제
GET /admin/api/billing/usage          → 사용량 집계（사용자/리소스별 집계）
```

과금 파이프라인: ResourceMonitor가 5분마다 수집 → UsageAggregator가 매시간 집계 → BillingEngine이 매일 차감, 잔액 부족 시 리소스 중지.

---

## 15. 제휴 커미션（Affiliate）
### 사용자 단말

```
GET /api/affiliate/summary
  → 커미션 개요（누적/정산 대기/출금 가능, 링크 수, 전환율）

POST /api/affiliate/links
   본문: { source? }
  → 추천 링크 생성（?ref=CODE）

GET /api/affiliate/earnings
   파라미터: status, page
  → 커미션 상세（주문 귀속, 비율, 상태: pending/approved/paid）

POST /api/affiliate/payout
   본문: { amount, method }
  → 출금 신청 제출
```

### 관리 단말

```
GET /admin/api/affiliate/plans                → 커미션 플랜 목록
POST /admin/api/affiliate/plans               → 커미션 플랜 생성
GET /admin/api/affiliate/earnings             → 전체 커미션 기록
POST /admin/api/affiliate/earnings/{id}/approve → 커미션 심사
GET /admin/api/affiliate/payouts              → 출금 신청 목록
POST /admin/api/affiliate/payouts/{id}/approve → 출금 심사/지급
```

---

## 16. GraphQL
```
POST /graphql
  → 공개 조회（상품, 도메인, 도움말 등 읽기 전용 데이터）
   제한: 조회 깊이 5레벨, 복잡도 100

POST /api/graphql                          🔒 인증 필요
  → 전체 조회（사용자 데이터 포함）
```

**민감 작업은 REST-only 유지:** 결제, 출금, 환불, KYC 심사는 GraphQL을 경유하지 않음.

---

## 17. 공급업체 평점과 상품 평가
### 공개

```
GET /api/regions
  → 사용 가능 지역 목록（통화/시간대 포함）

GET /api/suppliers/{supplierId}/ratings
  → 공급업체 평점 목록（4개 차원: 품질/지원/인도 속도/가성비, approved만 반환）
```

### 사용자 단말（인증 필요）

```
POST /api/products/{productId}/reviews
   본문: { rating, content, images? }
  → 상품 평가 제출（주문당 1회, 심사 후 노출）

POST /api/supplier/ratings
   본문: { supplier_id, quality, support, delivery_speed, value, comment? }
  → 공급업체 평점 제출（주문당 1회）

GET /api/supplier/ratings/me
  → 내 평점 기록
```

### 관리 단말

```
GET /admin/api/suppliers/{id}/ratings          → 전체 평점（pending 포함）
POST /admin/api/suppliers/ratings/{id}/approve → 심사 통과
POST /admin/api/suppliers/ratings/{id}/hide    → 숨김
```

---

## 18. 결제 Webhook
```
POST /api/payments/webhook/stripe
   헤더: Stripe-Signature: ...
  → Stripe 콜백（결제 성공/환불/이의 제기）, 서명 검증 실패 시 400 반환
```

---

## 19. WebSocket 이벤트
**연결:** `ws://host:8282`（docker 배포 시 WS는 nginx 리버스 프록시 경유, 연결 주소 `ws://host/ws/`, 8282는 컨테이너 내부에만 노출）

인증은 연결 후 첫 메시지로 수행（token이 URL/액세스 로그에 안 들어감）: 연결 수립 후 반드시 `auth` 메시지 먼저 전송, 30초 내 미인증 시 연결 종료；인증 실패 시 `error` 반환 후 종료.

### 클라이언트 → 서버

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### 서버 → 클라이언트

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### 푸시 이벤트

| 이벤트 | 데이터 | 트리거 시점 |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | 결제 성공 |
| `resource.provisioned` | `{resource_id, type, ip_address}` | 리소스 개통 완료 |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | 리소스 만료 임박 |
| `ticket.updated` | `{ticket_id, title, status}` | 티켓 상태 변경 |
| `notification.new` | `{notification_id, title, body}` | 새 알림 |

---

## 20. 오류 코드 참조
| code | 설명 |
|------|------|
| 400 | 파라미터 오류 / 지원하지 않는 API 버전 / 지원하지 않는 클라이언트 플랫폼 |
| 401 | 미인증 / Token 만료 / 무효 API Key / 디바이스 지문 불일치（Device mismatch） |
| 403 | 권한 없음 / 비공급업체 역할 / WAF 차단 / 비밀번호 확인 실패 |
| 404 | 리소스 없음（firstOrFail/findOrFail 미적중 시 통일 404 매핑） |
| 413 | 요청 본문 10MB 초과 |
| 414 | URL 2KB 초과 |
| 415 | Content-Type이 화이트리스트에 없음 (application/json, multipart/form-data, x-www-form-urlencoded만 허용) |
| 422 | 파라미터 검증 실패（이메일 이미 가입 / 재고 부족 / 출금 가능 잔액 부족 / 이미 신청 제출） |
| 429 | 요청 빈도 초과 |
| 500 | 서버 오류 |

### 일반적인 422 메시지

| 메시지 | 엔드포인트 |
|------|------|
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
