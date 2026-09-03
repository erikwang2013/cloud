# CloudPlatform API 인터페이스 문서

## 개요

**Base URL:** `https://api.example.com`

**버전 관리:** API 버전은 URL 경로에 포함됩니다（예: `/api/v1/products`）. 지원하지 않는 버전은 `400`을 반환합니다.

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
| 공개 | 전역 미들웨어 체인 | `/health`, `/api/v1/*` |
| `/health` (내부) | 전역 + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | 전역 + Encryption | `/api/v1/auth/*` |
| `/api/v1` (사용자) | 전역 + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (민감) | 전역 + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | 공급업체 외부 API |
| `/admin/api/v1` | 전역 + Encryption + Auth + AdminRole | 관리 백오피스 API |
| `/admin/api/v1` (민감) | 전역 + Encryption + Auth + AdminRole + Confirmation | 민감 관리 작업 |

---

## 1. 공개 엔드포인트
### 헬스 체크

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### 서비스 상태

```
GET /api/v1/status
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
GET /api/v1/products
   파라미터: category_id, region_id, keyword, supplier_id, page (기본1), page_size (기본20, 최대50)
  → 페이지네이션 상품 목록 (category, skus.regionPrices 포함)

GET /api/v1/products/search
   파라미터: q (필수), page
  → Elasticsearch 전문 검색

GET /api/v1/products/{id}
  → 상품 상세 (category, skus, images, reviews 포함)

GET /api/v1/products/{productId}/reviews
  → 평가 목록 + avg_rating + total + distribution
   상태 열거: pending(검토 대기)/approved(승인됨)/rejected(거부됨), approved만 반환
```

### 도메인

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → 사용 가능 TLD 목록 (Redis 캐시 1h)
```

### 고객센터

```
GET /api/v1/help
   파라미터: category, page
   헤더: Accept-Language (en-US / zh-CN)
  → 페이지네이션 도움말 문서

GET /api/v1/help/categories
  → 문서 카테고리 목록

GET /api/v1/help/{slug}
  → 문서 단일 상세
```

---

## 2. 인증 엔드포인트
### 캡차

```
POST /api/v1/captcha/create
   헤더: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### 가입

```
POST /api/v1/auth/register
   헤더: X-Encrypted: 1
   본문(암호화): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

빈도 제한: 3 req/min
```

- `deviceFingerprint`（선택）: 가입 시 디바이스 지문 기록, 로그인/갱신 시 검증；미지참 시 지문 바인딩 건너뜀
- email/phone은 저장 전 Encryptable 결정적 암호화（ECB, 암호문 등가 조회）, 고유성 검증과 로그인 조회 모두 암호문 기준

### 로그인

```
POST /api/v1/auth/login
   헤더: X-Encrypted: 1
   본문(암호화): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

빈도 제한: 5 req/min, 5회 실패 시 15min 잠금
```

- `login`은 암호문 등가 조회（Encryptable 결정적 암호화）, 평문 조회는 암호화 컬럼에 미적중

### Token 갱신

```
POST /api/v1/auth/refresh
   헤더: X-Encrypted: 1
   본문(암호화): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint`가 가입 시 기록과 불일치 → 401 `Device mismatch`；갱신 토큰은 암호문 해시로 조회

### OAuth

지원 제공자: google, apple, facebook, x, microsoft, linkedin, github
（.env의 `{PROVIDER}_OAUTH_CLIENT_ID` 등 구성으로 활성화 여부 결정）

```
GET /api/v1/auth/{provider}            → { url }        # 인증 페이지로 이동（PKCE/nonce 리플레이 방지）
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  본문: { code, state }
```

- Apple/Microsoft는 id_token 반환, 서버가 JWKS로 서명, iss/aud/exp/nonce 검증
- 모든 제공자는 `email_verified=true`여야 로그인 허용, 아니면 422
- `state` 누락 또는 불일치 → 422（CSRF 방지, 5분 만료）
- OAuth 흐름 빈도 제한: 60초당 10회（redirect + callback）

### 비밀번호 재설정

```
POST /api/v1/auth/forgot-password
   본문: { email }
  → 인증 코드 이메일 발송

POST /api/v1/auth/reset-password
   본문: { email, code, password }
  → 재설정 성공
  → 오류 누적 5회 → 429 빈도 제한 10분
```

### 이메일 인증

```
GET /api/v1/auth/verify-email?token=xxx
  → 인증 성공
```

### SMS 인증

```
POST /api/v1/auth/send-sms
   본문: { phone }
  → SMS 인증 코드 발송 (60s 쿨다운)
```

### TOTP 2단계 인증

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # 미영구화, 10분 내 verify로 적용
POST /api/v1/user/totp/verify       본문: { code } → { verified: true }   # 최초 활성화 시 성공 메시지 반환
POST /api/v1/user/totp/disable      본문: { password }             # 비밀번호 확인 필요, 아니면 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # 매회 8개 일회용 코드 생성, 비밀번호 확인 필요, 아니면 403
POST /api/v1/auth/login/recovery    본문: { login, password, recovery_code }
```

- 사용자가 TOTP 활성화 후 로그인 시 `totp_code` 필수, 아니면 401
- TOTP 연속 오류 5회 → 해당 사용자 15분 잠금（login_lock）

---

## 3. 사용자 엔드포인트（인증 필요）
### 프로필

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
   본문: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC 실명 인증

```
POST /api/v1/user/kyc
   본문: { id_type, id_number, real_name, front_image, back_image }
```

### 잔액

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
   파라미터: page
  → 잔액 변동 기록
```

### 주소 관리

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
   본문: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### 세션 관리

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → 지정 세션 폐기

DELETE /api/v1/user/account
   본문: { confirm_password }
  → GDPR 계정 탈퇴
```

### 알림

```
GET /api/v1/user/notifications
   파라미터: page
  → 페이지네이션 알림 목록

POST /api/v1/user/notifications/{id}/read
  → 읽음 표시

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
   본문: { email: {order_paid: true, ...}, push: {...} }
```

### 이메일

```
POST /api/v1/user/resend-verify-email
  → 인증 메일 재발송
```

### 파일 업로드

```
POST /api/v1/upload
   본문: multipart/form-data { file, type: avatar/kyc/attach }
   제한: avatar 2MB, kyc 5MB, attach 10MB
   허용: jpg, jpeg, png, gif, pdf
   설명: 타입 화이트리스트 검증 + finfo 내용 스니핑（확장자와 MIME 불일치 → 422）
```

---

## 4. 장바구니와 주문
### 장바구니

```
POST /api/v1/cart
   본문: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
   본문: { quantity }
```

> 금액 필드 규약（D4/P4.2 확정）: 모든 금액은 string, 소수 4자리（예: "9.9900"）, number/float 금지——
> MySQL DECIMAL 컬럼의 PDO 원시 출력과 일치, 정밀도는 4dp 문자열 자체가 담당. 주문/잔액/리포트 전 엔드포인트 적용.

### 주문

```
POST /api/v1/orders
  → 장바구니에서 주문 생성
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
   파라미터: page, status (pending/paid/provisioning/completed/refunded, 잘못된 값은 400)
  → 내 주문 목록

GET /api/v1/orders/{id}
  → 주문 상세 (items, timeline 포함)

GET /api/v1/orders/{id}/payment-methods
  → 사용 가능 결제 채널 + 채널별 실지급 금액

POST /api/v1/orders/{id}/pay    🔒 비밀번호 확인
   본문: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### 쿠폰

```
POST /api/v1/coupons/validate
   본문: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp（예: "2.0000"）

422: 무효/만료/사용 조건 미충족
```

### 인보이스

```
GET /api/v1/invoices
   파라미터: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF 다운로드
```

---

## 5. 리소스 관리
```
GET /api/v1/resources
   파라미터: page, status
  → 내 리소스 목록

GET /api/v1/resources/{id}
  → 리소스 상세

GET /api/v1/resources/{id}/status
  → 리소스 현재 상태 + 지표

GET /api/v1/resources/{id}/console
  → VNC/콘솔 URL

POST /api/v1/resources/batch
   본문: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. DNS 관리
```
GET /api/v1/dns/{domain}
  → DNS 레코드 목록

POST /api/v1/dns/{domain}/records
   본문: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 비밀번호 확인
```

---

## 7. 티켓
```
POST /api/v1/tickets
   본문: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
   파라미터: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
   본문: { content }
```

---

## 8. 공급업체（내부 API）
```
POST /api/v1/supplier/apply
   본문: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → 정산서 목록

POST /api/v1/supplier/withdraw    🔒 비밀번호 확인
   본문: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
   본문: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. 공급업체 외부 API
**인증:** `Authorization: Bearer sk_xxx...`（SHA256 검증）

**빈도 제한:** 120 req/min（출금 10 req/min）

```
GET /api/v1/supplier/external/orders
   파라미터: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → 주문 상세（해당 공급업체 연관만）

GET /api/v1/supplier/external/resources
   파라미터: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
   파라미터: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
   본문: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
   파라미터: page
```

---

## 10. 관리 백오피스 API
**인증:** JWT Bearer Token + Admin 역할

### 대시보드

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### 사용자 관리

```
GET /admin/api/v1/users               파라미터: page, status, keyword
GET /admin/api/v1/users/export       → Excel 다운로드
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status   본문: { status }
```

### KYC 심사

```
GET /admin/api/v1/kyc                 파라미터: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 비밀번호 확인
   본문: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 비밀번호 확인
   본문: { confirm_password, reason }
```

### 상품 관리

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 비밀번호 확인
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV 다운로드
POST /admin/api/v1/products/import        → CSV 업로드 upsert
```

### 주문 관리

```
GET /admin/api/v1/orders               파라미터: page, status, keyword
GET /admin/api/v1/orders/export       → Excel 다운로드
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 비밀번호 확인
   본문: { confirm_password, amount?, reason }
```

### 결제 관리

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions   파라미터: page, channel, status
GET /admin/api/v1/payments/reconcile      파라미터: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run   파라미터: date; 일별 대조 트리거
```

### 리소스와 개통

```
GET /admin/api/v1/provisioning/tasks               파라미터: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
   본문: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 비밀번호 확인
GET /admin/api/v1/provisioning/hosts
```

### 공급업체 관리

```
GET /admin/api/v1/suppliers                  파라미터: page, status
GET /admin/api/v1/suppliers/export          → Excel 다운로드

POST /admin/api/v1/suppliers/{id}/approve    🔒 비밀번호 확인
POST /admin/api/v1/suppliers/{id}/settle     🔒 비밀번호 확인
   본문: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 비밀번호 확인
```

### 공급업체 API Key

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
   본문: { name }
  ← { api_key: "sk_xxx...", prefix } (한 번만 표시)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### 티켓 관리

```
GET /admin/api/v1/tickets                   파라미터: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign      본문: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### 도메인 관리

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
   본문: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones              파라미터: page
GET /admin/api/v1/domains/transfers          파라미터: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### 알림 관리

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
   본문: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log          파라미터: page
```

### 쿠폰

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
   본문: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### 도움말 문서

```
GET /admin/api/v1/help
POST /admin/api/v1/help
   본문: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → 소프트 삭제 (status=archived)
```

### 클라우드 벤더 API

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
   본문: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → 비활성화 (status=disabled)
```

### Webhook 관리

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
   본문: { url }
DELETE /admin/api/v1/webhooks               본문: { id }
POST /admin/api/v1/webhooks/test            본문: { url }
```

### 리포트

```
GET /admin/api/v1/reports/revenue            파라미터: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp（SUM(DECIMAL)과 bcmath 집계 일치）
GET /admin/api/v1/reports/supplier           파라미터: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region             파라미터: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### 모니터링

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### 감사 로그

```
GET /admin/api/v1/audit-logs                 파라미터: page, user_id, action, from, to
  → 페이지네이션 감사 로그 (client_platform 포함)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
   본문: { action: enable/disable/toggle/reset }
```

### 시스템 구성

```
PUT /admin/api/v1/system/config              🔒 비밀번호 확인
```

### 상품 가져오기/내보내기

```
GET /admin/api/v1/products/export           → CSV 다운로드
POST /admin/api/v1/products/import          → CSV 업로드 upsert
```

### 공급업체 + 사용자 내보내기

```
GET /admin/api/v1/suppliers/export          → Excel 다운로드
GET /admin/api/v1/users/export              → Excel 다운로드
GET /admin/api/v1/orders/export             → Excel 다운로드
```

---

## 11. SSL 인증서
### 사용자 단말

```
GET /api/v1/ssl/plans
  → SSL 패키지 목록（DV/OV/EV, 가격에 register/renew/transfer 포함）

GET /api/v1/ssl-certs
  → 내 인증서 목록（status: pending/active/expired/revoked 포함）

GET /api/v1/ssl-certs/{id}
  → 인증서 상세（도메인, 발급 기관, 유효 기간, 갱신 상태）

GET /api/v1/ssl-certs/{id}/download
  → 인증서 파일 다운로드（인증서 체인 + 개인 키）

POST /api/v1/ssl-certs/{id}/auto-renew
   본문: { auto_renew: true/false }
  → 자동 갱신 전환
```

### 관리 단말

```
GET /admin/api/v1/ssl/plans              → 패키지 목록
POST /admin/api/v1/ssl/plans             → 패키지 생성
PUT /admin/api/v1/ssl/plans/{id}         → 패키지 업데이트
DELETE /admin/api/v1/ssl/plans/{id}      → 패키지 삭제
GET /admin/api/v1/ssl/certs              → 전체 인증서
POST /admin/api/v1/ssl/certs/{id}/revoke → 인증서 폐기
```

---

## 12. 객체 스토리지
S3 호환 객체 스토리지, 프리사인 URL로 업로드/다운로드, 키는 외부 유출 없음.

```
GET /api/v1/storage/buckets
  → 내 스토리지 버킷 목록（사용량, 상태）

GET /api/v1/storage/buckets/{id}
  → 버킷 상세

POST /api/v1/storage/buckets/{id}/presign-upload
   본문: { filename, content_type, size }
  → { upload_url, object_key } 프리사인 업로드 URL（시간 제한）

POST /api/v1/storage/buckets/{id}/presign-download
   본문: { object_key }
  → 프리사인 다운로드 URL（시간 제한）

GET /api/v1/storage/buckets/{id}/credentials
  → 임시 접근 자격증명（단기 유효, SDK 직송용）
```

---

## 13. CDN 가속
### 사용자 단말

```
GET /api/v1/cdn/domains
  → 내 CDN 도메인 목록（원본 서버, 상태, 패키지）

POST /api/v1/cdn/domains
  본문: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
          origin_type (server|storage), origin_value, cert_config? }
  → CDN 도메인 생성（서비스 제공업체 측에서 생성 후 원본 서버 바인딩）
  → provider_type=aliyun|tencent인 경우 도메인은 ICP 비안 완료 필요（미비안 시 4002 반환）
  → 응답에 requires_icp_registration 안내 필드 포함
  → 자격 증명 해석: 해당 도메인의 바인딩 계정（provider_account_id） 우선, 없으면 code=cdn-{provider_type}의
    활성 provider_apis 계정, 모두 없으면 env 구성 폴백

GET /api/v1/cdn/domains/{id}
  → CDN 도메인 상세

DELETE /api/v1/cdn/domains/{id}
  → CDN 도메인 삭제（서비스 제공업체 측 도메인 비활성화, 멱등）

POST /api/v1/cdn/domains/{id}/purge
  본문: { urls: ["https://cdn.example.com/path"] }
  → 캐시 삭제（중복 URL 자동 제거, 멱등; 최대 100개）

GET /api/v1/cdn/domains/{id}/stats
  → 도메인 개요（cdn_domain / provider_type / plan / status / purged_at）
```

### 관리 단말

```
GET /admin/api/v1/cdn/domains            → 전체 CDN 도메인（소속 사용자 포함）
PUT /admin/api/v1/cdn/domains/{id}       → 도메인 패키지 업데이트（plan 화이트리스트: standard | pro | enterprise）
```

관리 단말 CDN 라우트는 `RbacMiddleware('cdn.manage')`가 적용되고, 패키지 변경은 감사 로그（`admin_cdn_update_plan`）에 기록됩니다. 서비스 제공업체 계정 자격 증명은 `/admin/api/v1/providers` CRUD로 유지 관리（RbacMiddleware `provider.config`, `code` 규약 `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, 자격 증명은 Encryptable로 암호화 저장）.

### CDN 오류 코드

| code | 설명 |
|------|------|
| 4001 | CDN 파라미터 누락/무효（urls 비어 있음, provider_type 무효, 도메인 형식 오류） |
| 4002 | 도메인 ICP 비안 미완료（Aliyun/Tencent Cloud API 거부 시 매핑） |
| 4003 | CDN 서비스 제공업체 자격 증명 미구성（계정 없음/비활성, 엄격한 스냅샷으로 조용한 전환 없음） |
| 4005 | CDN 캐시 갱신 실패 |
| 5001 | CDN 서비스 제공업체 API 호출 실패 |

> 다른 사용자의 CDN 리소스（타인/존재하지 않는 리소스）는 일괄 **404** 반환（findOrFail 매핑, 리소스 존재성 노출 안 함）, 별도 비즈니스 코드 없음.

---

## 14. 사용량 과금
```
GET /admin/api/v1/billing/rates          → 과금 요율 목록（리소스 타입/사양별）
POST /admin/api/v1/billing/rates         → 요율 생성
PUT /admin/api/v1/billing/rates/{id}     → 요율 업데이트
DELETE /admin/api/v1/billing/rates/{id}  → 요율 삭제
GET /admin/api/v1/billing/usage          → 사용량 집계（사용자/리소스별 집계）
```

과금 파이프라인: ResourceMonitor가 5분마다 수집 → UsageAggregator가 매시간 집계 → BillingEngine이 매일 차감, 잔액 부족 시 리소스 중지.

---

## 15. 제휴 커미션（Affiliate）
### 사용자 단말

```
GET /api/v1/affiliate/summary
  → 커미션 개요（누적/정산 대기/출금 가능, 링크 수, 전환율）

POST /api/v1/affiliate/links
   본문: { source? }
  → 추천 링크 생성（?ref=CODE）

GET /api/v1/affiliate/earnings
   파라미터: status, page
  → 커미션 상세（주문 귀속, 비율, 상태: pending/approved/paid）

POST /api/v1/affiliate/payout
   본문: { amount, method }
  → 출금 신청 제출
```

### 관리 단말

```
GET /admin/api/v1/affiliate/plans                → 커미션 플랜 목록
POST /admin/api/v1/affiliate/plans               → 커미션 플랜 생성
GET /admin/api/v1/affiliate/earnings             → 전체 커미션 기록
POST /admin/api/v1/affiliate/earnings/{id}/approve → 커미션 심사
GET /admin/api/v1/affiliate/payouts              → 출금 신청 목록
POST /admin/api/v1/affiliate/payouts/{id}/approve → 출금 심사/지급
```

---

## 16. GraphQL
```
POST /graphql
  → 공개 조회（상품, 도메인, 도움말 등 읽기 전용 데이터）
   제한: 조회 깊이 5레벨, 복잡도 100

POST /api/v1/graphql                          🔒 인증 필요
  → 전체 조회（사용자 데이터 포함）
```

**민감 작업은 REST-only 유지:** 결제, 출금, 환불, KYC 심사는 GraphQL을 경유하지 않음.

---

## 17. 공급업체 평점과 상품 평가
### 공개

```
GET /api/v1/regions
  → 사용 가능 지역 목록（통화/시간대 포함）

GET /api/v1/suppliers/{supplierId}/ratings
  → 공급업체 평점 목록（4개 차원: 품질/지원/인도 속도/가성비, approved만 반환）
```

### 사용자 단말（인증 필요）

```
POST /api/v1/products/{productId}/reviews
   본문: { rating, content, images? }
  → 상품 평가 제출（주문당 1회, 심사 후 노출）

POST /api/v1/supplier/ratings
   본문: { supplier_id, quality, support, delivery_speed, value, comment? }
  → 공급업체 평점 제출（주문당 1회）

GET /api/v1/supplier/ratings/me
  → 내 평점 기록
```

### 관리 단말

```
GET /admin/api/v1/suppliers/{id}/ratings          → 전체 평점（pending 포함）
POST /admin/api/v1/suppliers/ratings/{id}/approve → 심사 통과
POST /admin/api/v1/suppliers/ratings/{id}/hide    → 숨김
```

---

## 18. 결제 Webhook
```
POST /api/v1/payments/webhook/stripe
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
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
