# CloudPlatform 기능 설계 문서

## 1. 사용자 인증과 권한 부여

### 1.1 가입

```
POST /api/auth/register
  → WAF 스캔
  → 빈도 제한 3 req/min
  → 비밀번호 검증 len≥8
  → 이메일/휴대폰 번호 고유성 검사
  → bcrypt(password, cost=12)
  → Snowflake::id()로 user_id 생성
  → Encryptable::set()으로 민감 필드 암호화
  → User + UserProfile + UserBalance 생성
  → NotificationDispatcher::send('email_verify') 인증 메일 발송
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**데이터 흐름:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 로그인

```
POST /api/auth/login
  → WAF 스캔
  → 빈도 제한 5 req/min
  → Captcha 검증（클릭 캡차, 3회 시도 제한）
  → Hash::check(password, user->password_hash)
  → 실패 5회 → login_lock:{userId} Redis TTL 900s
  → TOTP 검증（사용자가 활성화했으면 강제, totp_code 필수；
      오류 누적 5회 → totp_fail:{userId} → login_lock TTL 900s）
  → 새 IP 감지 → 이메일 경고
  → deviceFingerprint = sha256(UA + IP 대역, IPv6는 접두사 사용)
  → clientPlatform = X-Client-Platform 헤더
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth（Google / Apple）

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. Google/Apple ID Token 검증
  2. 사용자 조회 또는 생성（email 매칭）
  3. 토큰 발급（client_platform 포함）
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP 2단계 검증

```
1. POST /api/user/totp/setup
     → secret + QR URL 생성（Redis에 10분 임시 보관, 미영구화）
     ← {secret, qr_url, manual}
2. POST /api/user/totp/verify
     → TOTP code 검증（최초는 setup 활성화, 이후는 검증）
     ← {verified: true}
3. GET /api/user/totp/recovery-codes
     → 일회용 복구 코드 8개 생성（비밀번호 확인 필요）
     ← {recovery_codes: [8개]}
4. 로그인 시: TOTP code 입력 또는 복구 코드 사용
     → POST /api/auth/login/recovery (login, password, recovery_code)
```

### 1.5 세션 관리

```
GET /api/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/user/account (GDPR 탈퇴)
  → 비밀번호 2차 확인
  → User 소프트 삭제
  → 모든 RefreshToken revoked
```

---

## 2. 상품 관리

### 2.1 상품 모델

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (다국어 JSON)  │                      │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 상품 목록 (캐시 포함)

```
GET /api/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → category_id/region_id/keyword/supplier_id 필터
    → count + skip/take 페이지네이션
  ← 페이지네이션 결과

캐시 무효화:
  Admin product/SKU/region-price 변경
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 상품 검색 (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer 중국어 분사)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 상품 평가

```
GET /api/products/{id}/reviews
  → 심사 통과 평가 + 평균 평점 + 평점 분포
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (로그인 필요)
  → rating (1-5) + content
  → status = pending (관리자 심사 후 표시)
```

### 2.5 일괄 가져오기/내보내기

```
GET /admin/api/products/export
  → CSV 다운로드 (상품 + SKU + 지역 가격)

POST /admin/api/products/import
  → CSV 업로드 upsert
  ← {imported: N, errors: [...]}
```

---

## 3. 주문 시스템

### 3.1 장바구니

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → 장바구니 목록 (SKU 상세 + 실시간 가격 포함)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 주문 프로세스

```
1. POST /api/orders                            주문 생성
     → 재고 검증, 가격 계산, 쿠폰 적용
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                  쿠폰 적용
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods        사용 가능 결제 채널 조회
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/orders/{id}/pay                   결제 시작
     → 비밀번호 2차 확인 (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 주문 라이프사이클

```
                    ┌─────────┐
                    │ pending  │ 결제 대기
                    └────┬─────┘
                         │ 결제 성공
                    ┌────┴─────┐
                    │  paid    │ 결제 완료
                    └────┬─────┘
                         │ OrderPaid 이벤트
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ 완료
                    └────┬─────┘
                         │ 사용자 환불 신청
                    ┌────┴─────┐
                    │ refunded │ 환불 완료
                    └──────────┘

환불 조건: 서버 72h 이내 | 도메인 5일 이내 | IP 환불 불가 | 프로모션 상품 환불 불가（disk 등 기타 유형은 기간 제한 없음；미지 카테고리 유형은 기본 방출）
환불 흐름: 사용자 신청 → Ticket 생성 → 상담사 심사 → admin 확인 → Provider.destroy() → Payment.refund()
```

---

## 4. 결제 시스템

### 4.1 다중 채널 라우팅

```
PaymentRouter::route(Order $order)
  → 사용 가능 채널 필터링（is_visible + visible_regions + min/max_amount）
  → currency 매칭
  → 채널별 실지급 금액 계산（수수료 포함）
  → fee 오름차순 정렬
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe 결제

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Stripe 선택
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. transaction 생성
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. 사용자 결제 확인
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             stripe-signature 검증
                             transaction_no 멱등성 검사
                          8. transaction=success
                          9. OrderPaid 이벤트 트리거
                             → ProvisioningService
                             → WebSocket 푸시
                             → 이메일/SMS/Push 알림
```

### 4.3 대조

```
Cron: PaymentReconcile (매일 02:37)
  → 각 채널 정산 리포트 조회
  → 시스템 transaction과 건별 대조
  → 차이 > $0.01 → 경고
```

---

## 5. 리소스 개통 엔진

### 5.1 Provider 플러그인 아키텍처

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → Provider 인스턴스
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (확장 가능)
  'server:aliyun_ecs'  → AliyunProvider (확장 가능)
  'domain:namecheap'   → DomainProvider (확장 가능)
```

### 5.2 전체 개통 체인

```
OrderPaid 이벤트 트리거
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ 각 OrderItem에 ProvisionTask 생성
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (Redis Queue 소비)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     cpu/ram/disk 잔여량 + 부하 분산 순 정렬
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     VM 생성 (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     시스템 디스크 마운트 (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     VM 시작
  │
  ├→ Resource + Disk + IpAllocation 레코드 생성
  │
  ├→ host_machine 할당 리소스량 업데이트
  │
  └→ Order::status = completed
       → WebSocket 푸시 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

재시도 전략:
  1min → 5min → 15min → 1h → 6h → 24h (6회 후 실패 표시 + 경고)
```

> **공급 채널 진화**: Rust kvm-server（`infrastructure/kvm-server`, e-cat workspace）가 저장소에 포함됨——
> gRPC `ping/create_vm/vm_status`（:50051）+ etcd 등록 발견, PHP 측 KvmClient /
> RegistryProcess（`service/app/grpc/`）가 연결됨. 드라이버 계층은 현재 **시뮬레이션 드라이버**（libvirt 실제
> 드라이버는 Phase 2）, 개통 체인은 잠시 ProxmoxProvider 직결 유지；kvm-server가 VM 생성을 인수하면
> 이 섹션의 흐름은 동일하며 채널만 전환.

### 5.3 Proxmox 작업 요약

| 작업 | API | 핫 작업 |
|------|-----|--------|
| VM 생성 | POST /nodes/{node}/qemu | — |
| CPU 업그레이드 | PUT /qemu/{vmid}/config cores | 온라인 |
| 메모리 업그레이드 | PUT /qemu/{vmid}/config memory | 온라인 |
| 시스템 디스크 확장 | PUT /qemu/{vmid}/resize disk | 온라인 |
| 데이터 디스크 생성 | POST /qemu/{vmid}/config scsi{n} | 온라인 |
| 독립 IP 생성 | POST /qemu/{vmid}/config net{n} | 온라인 |
| VM 파기 | POST stop → DELETE qemu | — |
| 상태 조회 | GET /qemu/{vmid}/status/current | — |

---

## 6. 공급업체 시스템

### 6.1 입점 흐름

```
POST /api/supplier/apply (사용자 로그인 필요)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → 관리자 심사

관리자 승인:
  POST /admin/api/suppliers/{id}/approve (비밀번호 확인)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → 사용자에게 공급업체 권한 부여

상품 상장:
  POST /api/supplier/products
    → {product_id, commission_rate}
    → 공급업체 상품 연결

정산:
  Cron: SupplierSettlement (매주 월요일 04:17)
    → 기간 내 완료된 주문 집계
    → total_sales - commission = payable
    → SupplierSettlement 생성

출금:
  POST /api/supplier/withdraw (비밀번호 확인)
    → 출금 가능 잔액 확인
    → SupplierWithdraw 생성 (status=pending)
    → 관리자 승인 후 지급
```

### 6.2 외부 API

```
POST /admin/api/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → hash('sha256', rawKey) 저장
  ← {api_key: "sk_xxx..."} (한 번만 표시)

공급업체 사용:
  GET /api/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware 검증
    → supplierId 기준 데이터 필터
```

---

## 7. 도메인과 DNS

```
GET /api/domain/check/{domain}/{tld}    # 도메인 사용 가능 여부
GET /api/domain/tlds                     # 등록 가능 TLD 목록 (캐시 1h)
GET /api/dns/{domain}                    # DNS 레코드 목록
POST /api/dns/{domain}/records           # DNS 레코드 추가
DELETE /api/dns/{domain}/records/{id}    # DNS 레코드 삭제 (비밀번호 확인)
```

---

## 8. 티켓 시스템

```
POST /api/tickets                    # 티켓 생성
GET /api/tickets                     # 내 티켓
GET /api/tickets/{id}                # 티켓 상세
POST /api/tickets/{id}/reply         # 티켓 답변

관리자:
  GET /admin/api/tickets              # 티켓 큐
  POST /admin/api/tickets/{id}/assign # 상담사 배정
  POST /admin/api/tickets/{id}/close  # 티켓 종료

이벤트 기반:
  TicketCreated 이벤트
    → AutoAssignListener: 부하가 가장 적은 상담사에게 배정
    → WebSocket 푸시 'ticket.created'
```

---

## 9. 알림 시스템

### 9.1 4채널 배포

```
이벤트 트리거 → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → notifications 테이블에 직접 기록
```

### 9.2 알림 유형

| 이벤트 | 채널 | 트리거 시점 |
|------|------|---------|
| 가입 인증 | email | 이메일 가입 후 |
| 로그인 이상 경고 | email | 새 IP 로그인 |
| 주문 결제 성공 | email/push | 결제 완료 |
| 리소스 개통 완료 | email/push/in_app | Provisioning 완료 |
| 리소스 만료 알림 | email/push | 7d/3d/1d 전 |
| 티켓 답변 | email/push/in_app | Ticket 새 메시지 |
| 환불 완료 | email/push | 환불 처리 완료 |
| SSL 인증서 만료 | email | 30d 전 |
| 도메인 만료 | email | 30d 전 |

---

## 10. 모니터링과 경고

### 10.1 리소스 모니터링

```
Cron: CollectMetrics (5분마다)
  → 활성 리소스 폴링
  → ProxmoxApi::status() / Provider API
  → 지표를 Redis hash에 저장 (TTL 1h)

관리자:
  GET /admin/api/monitor/dashboard
    → 개요 통계 + 최근 경고
  GET /admin/api/monitor/resources/{id}
    → 실시간 지표 (Redis에서 읽기)
```

### 10.2 경고 규칙

| 규칙 | 심각도 | 트리거 조건 |
|------|--------|---------|
| server_down | 심각 | Ping 3회 연속 불가 |
| cpu_high | 경고 | CPU > 90% 10min 지속 |
| disk_high | 경고 | 디스크 > 90% 5min 지속 |
| ssl_expiring | 경고 | SSL 인증서 < 30일 만료 |
| domain_expiring | 경고 | 도메인 < 30일 만료 |
| provision_failed | 심각 | 개통 작업 연속 실패 |

---

## 11. 예약 작업

| Cron 표현식 | 작업 | 용도 |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | 4시간마다 환율 동기화 |
| `37 2 * * *` | PaymentReconcile | 매일 대조 |
| `17 4 * * 1` | SupplierSettlement | 매주 월요일 공급업체 정산 |
| `23 6 * * *` | ExpirationCheck | 만료 검사 + 알림 |
| `43 7 * * *` | SslCertificateCheck | SSL 인증서 검사 |
| `*/5 * * * *` | CollectMetrics | 리소스 지표 수집 |
| `*/30 * * * *` | CheckExpirations | 리소스 만료 검사 |

---

## 12. 국제화 (i18n)

### 12.1 요청 흐름

```
클라이언트 → Accept-Language: zh-CN
  → LocaleMiddleware（전역 미들웨어）
    → I18n::setLocale('zh-CN')
    → i18n/zh-CN/messages.php 로드
```

### 12.2 번역 방식

**정적 텍스트:** `I18n::trans('auth.login_success')` → `登录成功`
**JSON 필드:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**파라미터 치환:** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 커버 범위

120개 항목, 인증/상품/주문/결제/리소스/KYC/티켓/알림/공급업체/Webhook/시스템 등 전 모듈 커버. 언어 폴백 지원（미지원 언어 → en-US）.

---

## 13. Feature Flags 기능 스위치

```
config/features.php (기본값)
  ↓ 오버라이드 가능
.env FEATURE_* 환경 변수
  ↓ 런타임 오버라이드 가능
Redis feature:{name} (TTL 1h, 관리 API로 동적 조정)

관리 API:
  GET /admin/api/features → 모든 Flag 및 상태/소스 나열
  PUT /admin/api/features/{name} → enable/disable/toggle/reset

현재 Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL 인증서

SSL 인증서 상품은 DV/OV/EV 세 가지 유형을 지원하며, ACME 프로토콜（Let's Encrypt）또는 외부 CA API（ZeroSSL/GoGetSSL）로 자동 발급과 갱신.

**핵심 흐름:**

    사용자가 SSL 패키지 구매 → 주문 결제 → ProvisionTask 생성
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 검증 → 인증서 발급
      → 매일 expires_at 확인 → 만료 14일 전 자동 갱신
      → 만료 → status=expired → 사용자 알림

**데이터 모델:** `ssl_plans`（패키지）、`resource_ssl_certs`（인증서 인스턴스）

## 15. 객체 스토리지 (S3)

S3 API 호환 객체 스토리지, AWS S3 및 MinIO 자체 구축 스토리지 지원. 사용자는 프리사인 URL로 파일 업로드/다운로드.

**데이터 모델:** `resource_storage_buckets`

## 16. CDN 가속

CDN 상품은 Cloudflare 연동을 지원하며, 서버나 스토리지 버킷을 원본 서버로 CDN에 연결할 수 있고 캐시 삭제를 지원.

**인터페이스:** ProviderInterface + CachePurgeInterface（선택 기능 인터페이스）

**데이터 모델:** `resource_cdn`

## 17. 사용량 과금

리소스 사용량 수집 → 집계 → 과금 → 차감의 전체 파이프라인:

    ResourceMonitor가 5분마다 지표 수집 → resource_metrics
      → UsageAggregator가 매시간 집계 → usage_events
      → BillingEngine이 매일 잔액 차감 → 잔액 부족 → 리소스 중지
      → SuspendCheck가 30분마다 검사 → 잔액 회복 → 중지 해제

**데이터 모델:** `resource_metrics`、`usage_events`、`usage_rates`、`usage_invoice_items`

## 18. 공급업체 평점

구매 사용자가 공급업체를 4개 차원으로 평가（품질/지원/인도 속도/가성비）, 주문당 1회. 관리 단말에서 심사 가능（approve/hide）.

**데이터 모델:** `supplier_ratings`、`suppliers.rating_avg/rating_count`

## 19. 추천 배포

사용자가 추천 링크 생성（?ref=CODE）, 신규 사용자 가입 시 affiliate_code 바인딩, 주문 결제 후 자동 커미션 귀속.

**이벤트 기반:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**데이터 모델:** `affiliate_plans`、`affiliate_links`、`affiliate_earnings`、`affiliate_payouts`

## 20. GraphQL API

POST /graphql（공개 조회）와 POST /api/graphql（인증 조회）두 엔드포인트 제공. webonyx/graphql-php 기반, 조회 깊이 5레벨 제한, 복잡도 100 제한.

**민감 작업은 REST-only 유지:** 결제, 출금, 환불, KYC 심사.

## 21. 관측 가능성

Prometheus 지표 엔드포인트는 독립 프로세스 127.0.0.1:9100으로, WAF/빈도 제한의 영향을 받지 않음. MetricsMiddleware가 HTTP 요청 카운트와 지연을 기록. Docker Compose에 Prometheus + Grafana + 경고 규칙 + 대시보드가 사전 구성됨.

**헬스 체크:** /health（공개）、/health/live、/health/ready（의존성 5개 검사）、/health/deps（지연 상세）
