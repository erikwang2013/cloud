# 글로벌 클라우드 리소스 거래 플랫폼 — 시스템 설계

## 프로젝트 개요

글로벌 사용자를 위한 클라우드 리소스 거래 플랫폼으로, 자체 운영 + 제3자 공급업체 하이브리드 모델을 지원합니다. 사용자는 서버, IP, 클라우드 디스크, 도메인 등 클라우드 제품을 구매할 수 있습니다. 자동 리소스 프로비저닝, 다중 결제 채널, 다중 통화, 다중 언어를 제공합니다.

### 기술 스택

| 계층 | 기술 |
|------|------|
| 사용자 앱 | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| 관리 백엔드 | webman-admin |
| 서버 | PHP webman (모듈형 모노리스) |
| 데이터베이스 | MySQL 8.0 (마스터/슬레이브) |
| 캐시/큐 | Redis (캐시 + Session + 큐) |
| 스토리지 | S3/OSS + CDN |
| 모니터링 | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. 모듈 구분 (12개 핵심 모듈)
| 모듈 | 책임 |
|------|------|
| **User** | 회원가입/로그인(OAuth+이메일+휴대폰)、KYC 실명 인증、회원 등급、잔액 계정 |
| **Product** | 상품 정의(SKU)、다중 지역 가격 책정、재고 관리、분류、검색、평가 |
| **Order** | 장바구니、주문、주문 수명주기(결제 대기→결제 완료→개통 중→완료→환불)、갱신/업그레이드 |
| **Payment** | 결제 채널 라우팅、다중 통화 견적、환율、환불、대사 |
| **Provisioning** | 각 클라우드 공급업체 API 연동、리소스 자동 생성/갱신/폐기 |
| **Domain** | 도메인 조회、등록、이전、갱신、DNS 관리 |
| **Supplier** | 공급업체 입점、승인、상품 등록、정산、수수료 배분 |
| **Monitor** | 리소스 상태 헬스체크、사용량 수집、알람 규칙 |
| **Ticket** | 티켓 제출、할당、SLA 추적 |
| **Notification** | 이메일/SMS/App Push/사이트 내 메시지、다중 템플릿 다중 언어 |
| **Report** | 매출 리포트、공급업체 정산 리포트、판매 추이 |
| **I18n** | 다중 언어 번역、다중 통화 환율、다중 시간대 |

---

## 2. 핵심 데이터 모델
### 사용자 센터 (User)

- **users** — 사용자 마스터 테이블 (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — 사용자 프로필 (user_id, avatar, nickname, country)
- **user_kyc** — 실명 인증 (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — 잔액 계정 (user_id, currency, balance, frozen_balance)
- **user_balance_log** — 잔액 변동 기록 (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — 사용자 주소 (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### 상품 센터 (Product)

- **product_categories** — 상품 분류 (id, parent_id, name, icon, sort)
- **products** — 상품 마스터 테이블 (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — 지역 가격 (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — 상품 이미지 (product_id, url, sort)
- **product_attributes** — 커스텀 속성 (product_id, key, value)
- **product_reviews** — 상품 평가 (user_id, product_id, order_id, rating, content)
- **regions** — 지역 테이블 (id, name, continent, country, city, data_center, status)

### 주문 센터 (Order)

- **carts** — 장바구니 (user_id, sku_id, region_id, quantity, cycle)
- **orders** — 주문 마스터 테이블 (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — 주문 상세 (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — 주문 타임라인 (order_id, status, operator, remark, created_at)
- **order_invoices** — 인보이스 (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — 환불 내역 (order_id, user_id, amount, reason, status, handled_by)

### 결제 센터 (Payment)

- **payment_channels** — 결제 채널 설정 (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — 거래 기록 (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — 대사 테이블 (date, channel_id, channel_total, system_total, diff, status)

### 리소스 프로비저닝 (Provisioning)

- **resources** — 리소스 마스터 테이블 (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — 서버 상세 (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP 상세 (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — 클라우드 디스크 상세 (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — 도메인 상세 (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — 프로비저닝 태스크 (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — 클라우드 공급업체 API 설정 (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### 물리 머신 리소스 관리 (Host & IP Pool)

자체 운영 물리 서버는 Proxmox VE (커뮤니티 에디션, 무료)를 사용하여 가상 머신을 관리하며, REST API를 통해 VM 생성/관리, IP 할당, 디스크 마운트를 수행합니다.

- **host_machines** — 호스트 머신 (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP 풀 (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP 할당 기록 (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM 디스크 상세 (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — 디스크 확장 기록 (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### 공급업체 (Supplier)

- **suppliers** — 공급업체 마스터 테이블 (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — 공급업체 상품 연결 (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — 정산서 (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — 출금 기록 (supplier_id, amount, method, account_info, status)

### 도메인 서비스 (Domain)

- **domain_tlds** — 지원 TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — 도메인 이전 (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS 영역 (domain_name, user_id, zone_id)
- **dns_records** — DNS 레코드 (zone_id, type, name, value, ttl, priority)

### 티켓과 알림 (Ticket & Notification)

- **tickets** — 티켓 (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — 티켓 메시지 (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — 알림 기록 (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — 알림 템플릿 (code, name, channels, title_template, body_template, variables)

---

## 3. API 설계 규약
### 버전 관리

API 버전은 HTTP 요청 헤더 `X-Api-Version`로 지정하며 URL 경로에는 포함하지 않습니다. 서버는 미들웨어를 통해 버전 헤더를 내부 라우트에 주입합니다.

```
요청:  GET /api/auth/login
요청 헤더: X-Api-Version: v1

내부 라우트 → /api/auth/login → 컨트롤러
응답 헤더: X-Api-Version: v1
```

**지원 버전**: `v1`（기본값, 요청 헤더 누락 시 자동 사용）

**버전 제어 메커니즘**: `VersionMiddleware`가 모든 `/api/*` 및 `/admin/api/*` 경로의 `X-Api-Version` 요청 헤더를 검증하며, 누락 시 기본값 `v1`, 지원하지 않는 버전은 `400`을 반환합니다. URL 경로에는 더 이상 버전 번호가 포함되지 않습니다.

**새 버전 추가 절차**:
1. `VersionMiddleware::SUPPORTED` 배열에 버전 번호 추가
2. `route.php`에 새 버전 라우트 그룹 등록
3. 컨트롤러에서 `$request->properties['api_version']`로 버전 번호를 가져와 차별화 처리

### RESTful 라우트

```
통일 접두사: /api
관리 백엔드: /admin/api
```

**라우트 그룹과 미들웨어 매트릭스:**

| 라우트 그룹 | 미들웨어 | 엔드포인트 예시 |
|--------|--------|---------|
| 공개 (접두사 없음) | 전역 미들웨어 체인 | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | 전역 + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (사용자) | 전역 + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (민감) | 전역 + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | 전역 + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (민감) | 전역 + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### 통일 응답 형식

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### 인증 방안

| 엔드 | 방식 |
|----|------|
| 사용자 앱 | JWT (access_token 2h + refresh_token 30d) + TOTP 2단계 인증 + 복구 코드 |
| 관리자 앱 | JWT (access_token 2h + refresh_token 7d) |
| 공급업체 API | API Key (sk_ 접두사, SHA256 해시 저장, 생성 시에만 1회 표시) |
| 클라우드 공급업체 콜백 | 서명 검증 (HMAC-SHA256) |

**구현 완료된 인증 기능**:
- 이메일 가입 + 이메일 인증 링크
- 휴대폰 번호 가입 + Twilio SMS 인증 코드（60초 쿨다운 + IP 제한 5회/시간）
- Google OAuth 로그인 / Apple Sign In
- 비밀번호 찾기（이메일 인증 코드 + Redis 10분 TTL）
- TOTP 2단계 인증（QR 코드 스캔 설정、복구 코드 폴백）
- 활성 세션 관리（로그인 기기 조회/취소, client_platform 정보 포함）
- 계정 삭제 GDPR（비밀번호 확인 + 소프트 삭제 + 모든 토큰 취소）
- 로그인 이상 알림（새 IP 로그인 시 이메일 알림）
- 로그인 잠금（5회 실패 시 15분 잠금）

**사용자 인증 흐름:**

```
가입 프로세스                           로그인 프로세스
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(클릭 위치)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 스캔]                         → [WAF 스캔]
   → [제한: 3 req/min]                  → [제한: 5 req/min]
   → [비밀번호 bcrypt(cost=12)]         → [Hash::check()]
   → [기기 지문: sha256(UA+IP)]          → [기기 지문: sha256(UA+IP)]
   → [client_platform 기록]              → [client_platform 기록]
   → User::create()                    → [실패 5회 → 15분 잠금]
   → RefreshToken::create()            → [새 IP 감지 → 이메일 알림]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (인증 이메일)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 인증 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. Google token 검증
                                      5. 사용자 신규 생성 또는 조회
                                      6. 토큰 발급（client_platform 포함）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 2단계 인증                        세션 관리
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 성공
   또는 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 비밀번호 확인 + 소프트 삭제 + 전체 토큰 취소
로그인 잠금 메커니즘
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### 다중 언어 방안

- 요청 헤더: Accept-Language: zh-CN / en-US / ja-JP
- JSON 컬럼에 다중 언어 문구 저장: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n 파일로 정적 텍스트 관리, 프런트엔드와 백엔드 각각 한 세트

---

## 4. 보안 방어 체계
### 계층형 방어 모델

```
┌─────────────────────────────────────────────────────┐
│ 1계층: 네트워크 경계 방어                                  │
│   DDoS 클리닝 / WAF / IP 블랙·화이트리스트 / Geo-Blocking │
├─────────────────────────────────────────────────────┤
│ 2계층: 전송 및 애플리케이션 방어                             │
│   HTTPS+TLS1.3 / CSP / CORS / JWT 인증 / 제한          │
├─────────────────────────────────────────────────────┤
│ 3계층: 데이터 및 스토리지 보안                              │
│   암호화 저장 / 마스킹 / 감사 로그 / 백업                  │
├─────────────────────────────────────────────────────┤
│ 4계층: 가상화 및 리소스 격리                               │
│   Proxmox 보안 강화 / VM 간 격리 / 네트워크 격리          │
├─────────────────────────────────────────────────────┤
│ 5계층: 운영 및 리스크 컨트롤                                │
│   운영 감사 / 이상 감지 / 알람 / 사고 대응                │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 네트워크 경계 방어

#### DDoS 방어

```
사용자 요청 → CDN (Cloudflare / 알리바바 CDN)
              │
              ├── JS 질문 / 캡차 (의심 트래픽)
              ├── 속도 제한 (IP당 초당 요청 수)
              ├── 지역 차단 (특정 국가/지역 차단)
              │
              ▼
           오리진 (Nginx + webman)
```

| 계층 | 조치 | 설명 |
|------|------|------|
| CDN 계층 | 자동 DDoS 클리닝 | Cloudflare 무료 플랜에서도 L3/L4 방어 지원 |
| CDN 계층 | Bot Management | 악성 크롤러/주문 조작 스크립트 식별 및 차단 |
| Nginx 계층 | limit_req_zone | IP당 10 req/s, 초과 시 429 반환 |
| Nginx 계층 | limit_conn | IP당 최대 20 동시 연결 |
| webman 계층 | 토큰 버킷 제한 미들웨어 | 사용자/인터페이스 단위 정밀 제한 |

#### WAF 규칙 (webman 미들웨어)

WAF 미들웨어는 8개 클래스의 정규식 규칙 그룹으로 요청을 스캔하며, 규칙은 `config/security.php`에 구성되어 재시작 없이 핫 업데이트됩니다. 스캔 범위는 요청 본문 JSON, URL 경로+쿼리 문자열, User-Agent, 원본 요청 본문（JSON 인코딩 이스케이프 우회 방지）을 포함합니다.

**8개 클래스 감지 규칙（45개 이상）:**

| 카테고리 | 커버 범위 |
|------|---------|
| SQL 인젝션 | 단일 따옴표/주석 기호, SQL 키워드, 16진수 인코딩, 유니온 쿼리 변형, 항상 참 조건(`' OR '1'='1`), 시간 기반 블라인드(`sleep`/`benchmark`), 스택드 쿼리, 다중 줄 주석 우회 |
| XSS | HTML 태그(인코딩 변형 포함), Script 태그 및 변형, 13가지 JS 이벤트 핸들러, JS 전역 객체/위험 함수, `javascript:` 의사 프로토콜, HTML 엔티티 인코딩, Data URI 인젝션, 인라인 이벤트 속성 |
| 명령어 인젝션 | 파이프 기호 뒤 명령어(`\| cat`), 세미콜론 뒤 명령어(`; whoami`), `$(cmd)` 및 백틱 명령 치환, 단독 명령어 키워드 |
| 파일 인클루전 | 경로 탐색(다중 인코딩), PHP 의사 프로토콜(`php://`/`data://`/`phar://`), 절대 경로 탐지(`/etc/`/`C:\`), Null byte 인젝션 |
| HTTP 헤더 인젝션 | CRLF 줄바꿈 인젝션(`%0d%0a`/`\r\n`), Host/Cookie/Set-Cookie 헤더 인젝션 |
| **SSRF** | 내부망 IPv4 주소(127.x/10.x/172.16-31.x/192.168.x), localhost 별칭, 클라우드 metadata 엔드포인트(169.254.169.254), file:// 프로토콜 |
| **NoSQL 인젝션** | MongoDB 연산자($where/$gt/$regex/$or 등), $where JS 인젝션, Redis 위험 명령어(FLUSHALL/CONFIG SET/SHUTDOWN) |
| **오픈 리다이렉트** | redirect_uri/return_url/next/callback 등 파라미터의 외부 URL 감지, 이중 인코딩 우회 |

**요청 레벨 방어:**

| 방어 항목 | 조치 |
|--------|------|
| 요청 본문 크기 제한 | 최대 10MB（초과 시 413 반환） |
| URL 길이 제한 | 최대 2KB（초과 시 414 반환, ReDoS 방지） |
| Content-Type 화이트리스트 | application/json, multipart/form-data, application/x-www-form-urlencoded만 허용 |

**WAF 감지 흐름:**

```
요청 진입
  │
  ▼
1. 스캔 대상 텍스트 획득
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 요청 본문
   │     └── false → serialize() 폴백
   ├── mb_substr(path + queryString, 0, 2048)                # URL（ReDoS 방지 절단）
   ├── User-Agent 헤더                                        # UA
   └── file_get_contents('php://input')                      # 원본 본문（JSON 인코딩 이스케이프 방지）
  │
  ▼
2. 규칙 로드（config/security.php에서）
   ├── security.waf.sqli_patterns               (9개)
   ├── security.waf.xss_patterns                (8개)
   ├── security.waf.cmd_injection_patterns      (5개)
   ├── security.waf.file_inclusion_patterns     (4개)
   ├── security.waf.header_injection_patterns   (2개)
   ├── security.waf.ssrf_patterns               (6개)
   ├── security.waf.nosql_injection_patterns    (3개)
   └── security.waf.open_redirect_patterns      (2개)
   → array_merge() + array_unique()
  │
  ▼
3. 패턴별 매칭
   foreach patterns as pattern:
     match($pattern, $input) ───→ 히트 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 히트 → 403 "Request blocked by WAF" 반환
     match($pattern, $ua)    ───→ 히트 →
     match($pattern, $raw)   ───→ 히트 →
  │
  ▼
4. match() 엄격 검사
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 히트 ✓
   ├── $result === 0    → 미히트（안전 통과）
   └── $result === false → 패턴 오류 → error_log() → 미히트로 처리
  │
  ▼
5. 전부 미히트 → $next($request)로 다음 미들웨어 통과
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // config/security.php에서 8개 클래스 규칙 로드
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### IP 블랙·화이트리스트

```
블랙리스트:
- 알려진 악성 IP 목록 (AbuseIPDB 주기적 동기화)
- WAF 규칙을 자주 트리거하는 IP (자동 등록, Redis TTL 24h)
- 로그인 무차별 대입 IP (5회 실패 → 30분 잠금)

화이트리스트:
- Proxmox 호스트 머신 IP
- 클라우드 공급업체 콜백 IP 대역
- 결제 게이트웨이 webhook IP 대역
- 관리자 사무망 IP (선택)
```

#### Geo-Blocking

```php
// GeoIP2 라이브러리 (MaxMind)
$country = geoip($request->getRealIp());

// 설정 가능한 차단 목록
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 전송 및 애플리케이션 보안

#### 전역 미들웨어 실행 체인

모든 HTTP 요청은 다음 순서로 미들웨어를 거치며, 각 미들웨어는 독립적으로 테스트 가능합니다:

```
요청 → VersionMiddleware        # X-Api-Version 검증（누락 시 v1 기본값, 무효 시 400）
     → CorsMiddleware            # CORS 크로스 오리진 응답 헤더
     → ClientPlatformMiddleware  # X-Client-Platform 식별（8개 플랫폼）, $request->properties에 주입
     → WafMiddleware             # 8개 클래스 45+ 규칙 보안 스캔（SQLi/XSS/명령어 인젝션/파일 인클루전/헤더 인젝션/SSRF/NoSQL/오픈 리다이렉트）
     → LocaleMiddleware          # Accept-Language 파싱, 지역 설정
     → HashidRequestMiddleware   # 요청 파라미터 hashid → 실제 ID 디코딩
     → MaintenanceMiddleware     # 유지보수 모드（IP 화이트리스트 통과）
     ↓
  [라우트 미들웨어—라우트 그룹별 부착]
     → EncryptionMiddleware      # AES-256-GCM 요청/응답 본문 암호화
     → Captcha                   # 클릭 캡차 검증（로그인/가입 전）
     → AuthMiddleware            # JWT Bearer Token 검증 + 역할 주입
     → AdminRoleMiddleware       # 관리자 RBAC 권한 검사
     → ConfirmationMiddleware    # 민감 작업 2차 비밀번호 확인（5회 실패 시 15분 잠금）
     ↓
     컨트롤러
```

#### 각 미들웨어 책임

| 미들웨어 | 등록 방식 | 책임 |
|--------|---------|------|
| `VersionMiddleware` | 전역 | `X-Api-Version` 요청 헤더 검증, 누락 시 기본값 `v1`, 지원하지 않는 버전은 `400` 반환 |
| `CorsMiddleware` | 전역 | OPTIONS 사전 요청 처리, Origin을 `Access-Control-Allow-Origin`에 반영 |
| `ClientPlatformMiddleware` | 전역 | `X-Client-Platform` 요청 헤더 검증, 클라이언트 OS 플랫폼 식별（iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web）, `$request->properties['client_platform']`에 주입 |
| `WafMiddleware` | 전역(service) + admin 인스턴스 | 8개 클래스 45+ 규칙 + 요청 크기 제한 + Content-Type 검증, 차단 시 감사 로그 기록 |
| `LocaleMiddleware` | 전역 | `Accept-Language` 헤더 파싱, 다중 언어 지역 설정 |
| `HashidRequestMiddleware` | 전역 | 요청의 hashid 문자열을 실제 정수 ID로 자동 디코딩 |
| `MaintenanceMiddleware` | 전역 | `MAINTENANCE_MODE` 환경 변수 확인, 화이트리스트 IP 통과 |
| `EncryptionMiddleware` | 라우트 그룹 (/api/auth, /api, /admin/api) | AES-256-GCM 요청/응답 본문 암호화, `X-Encrypted: 1` 헤더로 트리거 |
| `AuthMiddleware` | 라우트 그룹 (/api, /admin/api) | JWT HS256 access_token 검증, `$request->userId` 및 `$request->userRole` 주입 |
| `AdminRoleMiddleware` | 라우트 그룹 (/admin/api) | 관리자 RBAC 권한 검사 |
| `ConfirmationMiddleware` | 라우트 그룹 (민감 작업) | 2차 비밀번호 확인, Redis 실패 카운터, 5회 실패 시 15분 잠금 |

#### ClientPlatform 미들웨어 상세

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // API 라우트에만 적용
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 하위 흐름에서 사용할 요청 속성 주입（감사 로그, 세션 기록）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**데이터 흐름**: 미들웨어 주입 → `AuditLogger` 자동 기록 → `AuthService::issueTokens()`가 `refresh_tokens`에 기록 → `GET /api/user/sessions`가 플랫폼 정보 반환

#### HTTPS 강제

```nginx
# Nginx 설정
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### JWT 보안 강화

```
- access_token 유효기간 2h, refresh_token 유효기간 30d
- 키는 RSA256 (비대칭) 사용, 주기적 교체 (90일)
- jti (JWT ID)를 Redis에 저장하여 능동 폐기 구현
- refresh_token이 기기 지문에 바인딩 (User-Agent + IP 대역)
- refresh_token 재발급 시 기존 토큰 즉시 무효화 (rotation)
- 민감 작업 (결제/리소스 폐기)은 2차 인증 필요

기기 지문:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 테이블에 이 지문을 기록, 재발급 시 검증
```

#### 비밀번호 정책

```
- bcrypt 암호화, cost factor = 12
- 최소 8자, 대문자/소문자 + 숫자 필수
- 가입/로그인 연속 5회 실패 → 계정 15분 잠금
- 비밀번호 변경 후 발급된 모든 토큰 즉시 무효화
- TOTP 2단계 인증 지원 (사용자 선택 활성화)
```

#### CORS 정책

```php
// webman 미들웨어
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### 파일 업로드 보안

```
- 확장자 화이트리스트 검증 (허용: jpg, jpeg, png, pdf, gif)
- 파일 MIME 타입 검증 (위조 Content-Type 불허)
- 파일 크기 제한: 아바타 2MB, KYC 서류 5MB, 첨부 10MB
- 업로드 후 파일명 재생성: {uuid}.{ext}, 원본 파일명 미보존
- 이미지 재처리: GD/Imagick으로 EXIF + 메타데이터 제거
- 저장 경로는 web 접근 불가 디렉터리, PHP 프록시로 읽기
- 바이러스 스캔: ClamAV (KYC 서류/사용자 업로드 파일)
```

---

### 4.3 데이터 및 스토리지 보안

#### 민감 데이터 암호화

```
암호화 알고리즘: AES-256-GCM (인증 포함 암호화, 위조 방지)
키 관리: 마스터 키는 환경 변수에 저장, 각 필드는 독립 파생 키 사용

암호화 저장이 필요한 필드:
| 데이터 유형 | 필드 | 암호화 방식 |
|----------|------|----------|
| 비밀번호 | users.password_hash | bcrypt (단방향) |
| 결제 키 | payment_channels.api_key | AES-256-GCM |
| 클라우드 공급업체 키 | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox 토큰 | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 증빙 번호 | user_kyc.id_number | AES-256-GCM |
| 결제 계좌 | 출금 계좌 | AES-256-GCM |
| 로그인 비밀번호(VNC) | resource_servers.login_password | AES-256-GCM |

키 파생:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### 로그 마스킹

```php
class LogSanitizer
{
    // 자동 마스킹 대상 필드명 패턴
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor가 로그 기록 전 자동 호출
```

#### 데이터베이스 보안

```
- MySQL prepared statement 사용 (Eloquent가 자동 처리)
- 데이터베이스 접근 계정 최소 권한 원칙:
  - app_user: SELECT, INSERT, UPDATE, DELETE (DDL 없음)
  - migration_user: DDL 권한 (마이그레이션 시에만 사용, IP 제한)
  - read_user: SELECT 읽기 전용 (리포트/데이터 분석용)
- 연결은 SSL/TLS 사용 (PHP PDO SSL options)
- 데이터베이스 포트는 공개망 미개방 (내부망만 접근 가능)
- 정기 백업: 전체 백업 1일, binlog 실시간 동기화
```

#### 데이터 백업 및 복구

```
백업 전략:
- MySQL: 매일 전체 + binlog 실시간 증분
- Redis: RDB 매시간 + AOF 실시간 지속화
- 사용자 업로드 파일: S3/OSS 자동 다중 복제 + 크로스 리전 복제
- Proxmox VM 스냅샷: 주 1회 (4주 보관)
- 백업 암호화: AES-256 암호화 후 저장

복구 훈련:
- 분기마다 1회 재해 복구 훈련 실시
- 복구 시간 목표 (RTO): < 4시간
- 복구 시점 목표 (RPO): < 1시간
```

---

### 4.4 가상화 및 리소스 격리

#### Proxmox 보안 강화

```
1. API 접근 제어:
   - Proxmox API는 내부망 IP만 리슨 (공개망 미바인딩)
   - 토큰 권한 최소화: 각 role에 필요한 권한만 부여
   - API 포트 (8006)는 PHP 애플리케이션 서버 IP만 접근 허용 (iptables)

2. SSH 강화:
   - 비밀번호 로그인 비활성화, 키 인증만 허용
   - root 로그인 비활성화, 전용 관리 계정 사용
   - SSH 포트를 비표준 포트로 변경 (스캔 감소)
   - Fail2ban: 5회 실패 시 1시간 잠금

3. 시스템 업데이트:
   - Proxmox 보안 업데이트 이메일 구독
   - 정기 apt update && apt upgrade
   - 커널 livepatch (Canonical Livepatch Service)

4. 방화벽 (iptables/nftables):
   - 기본적으로 모든 인바운드 거부
   - 허용: 8006 (애플리케이션 서버 IP만), SSH 포트 (관리 IP만)
   - VM 브리지와 호스트 관리 네트워크 격리
```

#### VM 간 격리

```
- 각 VM은 독립 가상 브리지 VLAN 사용
- VM 간 통신 금지 (Proxmox 방화벽 규칙 + VLAN 격리)
- 사용자는 공개 IP로만 자신의 VM 접근 가능
- VM 리소스 제한 (cgroup): 단일 VM이 호스트 리소스 고갈 방지
  - CPU limit: 구매한 코어 수 상한
  - RAM limit: 구매한 용량 상한
  - Disk IOPS limit: 디스크 경합 방지
  - Network bandwidth limit: 구매한 대역폭 상한
```

#### IP 할당 보안

```
- IP 할당 기록 전체 감사 (누가, 언제, 어떤 IP를 할당했는지)
- IP 해제 후 24h 쿨다운 (해제 IP가 즉시 다른 사용자에게 할당되어 오용되는 것 방지)
- IP 블랙리스트: 신고/남용된 IP는 할당 불가로 표시
- IP 사용 모니터링: 할당된 IP가 정상 사용 중인지 정기 확인
```

---

### 4.5 결제 보안

```
1. PCI DSS 컴플라이언스:
   - 신용카드 데이터는 자체 서버를 경유하지 않음 (Stripe Elements / Checkout)
   - card_token은 Stripe 프런트엔드에서 직접 생성, 백엔드는 토큰만 수신
   - 로그/데이터베이스에 CVV/전체 카드 번호 미저장

2. 암호화폐:
   - 수금 개인 키 콜드 스토리지 (오프라인 서명)
   - 핫 월렛에는 일상 회전 한도만 보유
   - 수금 주소 생성 후 체크섬 검증
   - 대액 거래 ( > $10000)는 수동 심사 후 수동 확인

3. 결제 사기 방지:
   - 동일 사용자/IP의 단시간 고빈도 결제 → 리스크 컨트롤 동결
   - 신규 가입 사용자의 대액 결제 → 수동 심사
   - 결제 금액 이상 (상품 가격과 불일치) → 차단
   - 환불율 과도한 사용자 → 리스크 컨트롤 표시

4. 콜백 서명 검증:
   - Stripe: webhook signature 검증 (stripe-signature header)
   - Coinbase: webhook signature 검증 (X-CC-Webhook-Signature header)
   - 알리페이: notify_id 검증 후 알리페이 서버에 2차 확인
   - 모든 콜백: IP가 알려진 결제 게이트웨이 IP 대역인지 검증
```

#### 환불 보안

```
- 환불은 2단계 승인 필수 (고객지원 요청 → 관리자 확인)
- 환불 전 검증: 주문 상태, 환불 기한, 환불 횟수
- 환불 금액은 원래 주문 실결제 금액 초과 불가
- 원경로 환불: 결제 채널 환불 인터페이스 + 잔액 환불
- 환불 뮤텍스 락 (Redis): 동시 중복 환불 방지
```

---

### 4.6 접근 제어 및 권한

#### RBAC 모델

```
역할 계층:
  super_admin    (슈퍼 관리자 — 전체 권한)
  admin          (관리자 — 시스템 설정 제외 전부)
  finance        (재무 — 결제/대사/환불/정산)
  support        (고객지원 — 사용자/주문/티켓 관리)
  supplier       (공급업체 — 자신의 상품/주문/정산)
  user           (일반 사용자 — 자신의 리소스/주문/티켓)

권한 정의:
  {module}.{action}
  예: order.view, order.create, order.refund, resource.destroy

권한 검사 미들웨어:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### API 속도 제한

```php
// webman 제한 미들웨어 (Redis 토큰 버킷)
class RateLimitMiddleware
{
    // 기본값: 사용자당 60 req/min
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 무차별 대입 방지
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 대량 가입 방지
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 결제 속도 제한
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 호출
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 업로드 속도 제한
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### 공급업체 데이터 격리

```
데이터 격리 원칙:
- 공급업체는 자신의 리소스만 조회/조작 가능
- supplier_id가 포함된 모든 쿼리에 WHERE supplier_id = auth()->supplier_id 자동 추가

구현 방식:
  // 전역 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // Product/Order 등 Model에 등록
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 운영 감사

```
감사 로그 기록 내용:
- 조작자 ID, IP, User-Agent
- 조작 시간
- 조작 모듈 (어떤 메뉴/인터페이스)
- 조작 유형: 생성/수정/삭제/내보내기/승인
- 조작 대상: 어떤 리소스의 어떤 필드
- 조작 전 값 / 조작 후 값 (필드 레벨 변경)
- 조작 결과: 성공/실패
- 요청 ID (전체 체인 추적)

기록 범위:
- 모든 관리자 앱 조작 (100% 기록)
- 사용자 앱 민감 조작: 결제/리소스 폐기/KYC 제출/비밀번호 변경 (100% 기록)
- 로그인/로그아웃 (100% 기록)
- API Key 생성/취소 (100% 기록)

저장 및 보관:
- 감사 로그는 별도 데이터베이스 (audit_db)에 기록, 애플리케이션 DB와 분리
- 최소 1년 보관, 금융 관련 3년 보관
- 컴플라이언스 심사를 위한 CSV/JSON 내보내기 지원

감사 로그 미들웨어:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 리스크 컨트롤 규칙

```
실시간 리스크 컨트롤 엔진:

규칙 1: 신규 계정 이상 행위
  조건: 가입 시간 < 24h AND (결제 총액 > $500 OR 티켓 생성 > 5)
  조치: 계정을 "관찰 중"으로 표시, 리스크 관리자에게 알림

규칙 2: 대량 가입 감지
  조건: 동일 IP 24h 내 가입 > 3개 계정
  조치: 신규 가입 거부, 해당 IP 아래 신규 계정 동결

규칙 3: 결제 이상
  조건: 동일 사용자 1h 내 결제 실패 > 5회
  조치: 결제 기능 2h 동결, 리스크 티켓 생성

규칙 4: 환불 남용
  조건: 동일 사용자 30일 내 환불 > 3건 OR 환불율 > 20%
  조치: 해당 계정 환불 권한 제한, 신규 주문 리스크 심사 표시

규칙 5: API 남용
  조건: 단일 토큰 1h 내 API 호출 > 10000회
  조치: 해당 토큰 등급 강등 (제한 임계값 인하), 관리자에게 알림

규칙 6: 리소스 남용
  조건: VM이 spam/DDoS/채굴로 신고됨 (Abuse 알림 수신)
  조치: 자동 종료, 리소스 동결, 고우선순위 티켓 생성

리스크 컨트롤 조치:
- 표시 (flag): 기록만, 사용에 영향 없음
- 강등 (throttle): 제한 임계값 인하
- 동결 (freeze): 특정 기능 임시 비활성화
- 차단 (ban): 계정 영구 차단
```

---

### 4.9 사고 대응

```
보안 사고 등급:

P0 (긴급) — 데이터 유출, 자금 손실, 플랫폼 다운
  → 즉시 CTO + 보안 팀에 알림
  → 30분 내 사고 대응 시작
  → 영향받은 업스트림 서비스 내리기, 증거 보존
  → 수정 후 24h 내 사고 리포트 발행

P1 (심각) — 단일 계정 도난, 결제 사기, WAF 트리거 비정상 증가
  → 보안 책임자에게 알림
  → 2h 내 처리
  → 영향받은 계정/리소스 동결

P2 (일반) — 취약점 스캔으로 중저위험 취약점 발견, 로그인 이상 알림
  → 티켓 시스템에 등록
  → 다음 이터레이션에서 수정

사고 연락:
- P0/P1 알람 트리거 후 자동 알림 (이메일 + SMS + 전화)
- webman 헬스체크 엔드포인트: GET /health (200 또는 알람 반환)
- 당직표: 7×24 교대, 최소 2인 예비 근무
```

---

## 5. 리소스 프로비저닝 엔진
### Provider 플러그인 아키텍처

각 클라우드 제품 유형 × 클라우드 공급업체 조합이 통일 인터페이스를 구현합니다:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 물리 머신 자체 운영 전용
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory가 (product_type, provider)에 따라 구체 구현으로 라우팅합니다:
- ProxmoxProvider (자체 운영 물리 머신: 서버/데이터 디스크/IP)
- AwsServerProvider / AliyunServerProvider (제3자 클라우드 서버)
- GcpIpProvider (제3자 IP)
- AzureDiskProvider (제3자 클라우드 디스크)
- NamecheapDomainProvider / GoDaddyDomainProvider (도메인)

### 비동기 태스크 보장

- Provisioning Worker가 provision_tasks 테이블 폴링
- provider별 그룹으로 동시성 제어 (각 provider 최대 5 동시)
- 재시도 전략: 1min → 5min → 15min → 1h → 6h → 24h (최대 6회)
- 재시도 불가 실패 → 알람 + 자동 티켓 생성

### 주문에서 리소스 프로비저닝까지 전체 체인

```
사용자 주문                             결제                              리소스 프로비저닝
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 이벤트
   → addToCart(sku, region, qty)          → 비밀번호 2차 확인 (Confirmation)  → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                           결제 채널 선택                   10. 각 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. 쿠폰 적용                               createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. 프런트엔드 confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe webhook 콜백            12. Provider->create()
   → 사용 가능한 결제 채널 조회               → 서명 검증 + 멱등 검사              ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → OrderPaid 이벤트 트리거           │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                        재시도 전략 (실패 시)                     │  startVM()
                                        ────────────────                      ├→ Resource 레코드 생성
                                        1min → 5min → 15min                  └→ host_machine 업데이트
                                        → 1h → 6h → 24h                           할당된 리소스량
                                        (6회 후 실패 표시 + 알람)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        환불 프로세스                             ::send('resource_ready')
                                        ────────
                                        사용자 신청 → 고객지원 심사 → admin 확인
                                        → provider.destroy()
                                        → payment.refund()
                                        → 원경로 환불
```

### 자체 운영 물리 머신 방안: Proxmox VE (커뮤니티 에디션)

자체 운영 서버는 Proxmox VE (오픈소스 무료, AGPL v3)를 사용하며, PHP가 HTTP로 Proxmox REST API를 호출하여 KVM 가상 머신 수명주기와 리소스 할당을 관리합니다.

아키텍처:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (사용자에게 할당)
```

#### ProxmoxApi 클라이언트 래퍼

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### 리소스 작업

**VM 생성 (서버):**
1. HostSelector가 리소스가 충분한 호스트 머신 선택 (cpu/ram/disk 여유 + 부하 분산 기준 정렬)
2. 해당 호스트의 ip_pool에서 IP 하나 할당
3. ProxmoxApi.post("/nodes/{node}/qemu")로 VM 생성 (vmid, name, cores, memory, net0, ipconfig0 설정)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config")로 시스템 디스크 마운트 (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start")로 VM 시작
6. host_machine.specs 할당량 업데이트 (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**CPU 업그레이드 (온라인):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 호스트 머신 리소스 통계 업데이트
```

**메모리 업그레이드 (온라인):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**시스템 디스크 확장:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**데이터 디스크 단독 생성:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**IP 단독 생성:**
IP 풀에서 할당 → Proxmox API로 가상 NIC 추가 + IP 설정, 또는 독립 리소스로 보존하여 기존 VM의 추가 NIC에 할당.

**VM 폐기:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 종료
$api->delete("/nodes/{node}/qemu/{vmid}");             // VM 삭제
releaseIp($resourceId);                                // IP 풀에 IP 반환
$host->deallocate($specs);                             // 호스트 리소스 회수
```

#### 호스트 머신 선택 전략

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### 리소스 분리 작업 요약

| 작업 | 구현 방식 | 핫 작업 |
|------|----------|--------|
| VM 생성 (CPU+메모리+시스템 디스크+IP) | Proxmox create qemu | — |
| CPU 단독 업그레이드 | PUT config cores | 온라인 |
| 메모리 단독 업그레이드 | PUT config memory | 온라인 |
| 시스템 디스크 확장 | PUT resize disk | 온라인 (VM 지원 필요) |
| 데이터 디스크 단독 생성 | POST config 디스크 추가 | 온라인 |
| IP 단독 생성 | IP 풀에서 할당 + VM에 NIC 추가 | 온라인 |

### 리소스 수명주기

```
pending → active → destroyed (30일 보관) → purged (복구 불가)
```

갱신: active → (renew) → active (expired_at 연장)
업그레이드: active → (upgrade) → upgrading → active

### 리소스 출처

| 출처 | 가상화/API | 제품 유형 | 설명 |
|------|-----------|----------|------|
| 자체 운영 물리 머신 | Proxmox VE (커뮤니티 에디션) | 서버, 데이터 디스크, IP | 자체 데이터센터 위탁, PHP가 Proxmox API 호출 |
| 제3자 클라우드 공급업체 | AWS/GCP/알리바바/화웨이/Azure SDK | 서버, IP, 클라우드 디스크 | 제3자 클라우드 리소스 재판매 |
| 도메인 등록기관 | Namecheap/GoDaddy/알리바바 완왕 API | 도메인 등록/이전 | 도메인 서비스 |

### 1차 연동

| 지역 | 서버 | IP | 클라우드 디스크 | 도메인 |
|------|--------|----|------|------|
| 아시아태평양 | 알리바바, 화웨이, AWS | 알리바바, GCP | 알리바바, 화웨이 | 알리바바 완왕, Namecheap |
| 유럽 | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| 북미 | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## 6. 결제 시스템
### 다중 채널 라우팅

PaymentRouter가 사용자의 통화 선호에 따라 사용 가능한 채널을 조회하고, 각 채널의 실결제 금액 (채널 수수료 포함)을 계산하여 결제 옵션 목록을 반환합니다.

### 결제 흐름 (Stripe)

```
사용자 앱 (Flutter)               서버 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. Stripe 결제 선택
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. payment_transaction 생성
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 사용자 결제 확인
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  서명 검증 (stripe-signature)
                                  멱등 검사 (transaction_no)
                               8. transaction=success 업데이트
                               9. OrderPaid 이벤트 트리거
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 결제 성공 페이지               ← 주문 상태 반환
```

### 암호화폐 결제

1. 사용자가 통화 선택 (예: USDT-TRC20)
2. 백엔드가 Coinbase Commerce / BitPay API로 수금 주소 생성
3. Worker가 30초마다 블록체인 확인 (또는 webhook)
4. 입금 확인 → OrderPaid 이벤트 트리거

### 환율과 다중 통화

- 환율 출처는 exchangerate-api에서 정기적으로 가져와 Redis에 저장
- 상품 가격은 USD 기준, 다른 통화는 실시간 환산
- 주문 시 환율 고정, 환불 시 원래 환율로 반환

### 결제 채널 가시성 제어

payment_channels 테이블 필드:
- is_visible: 사용자 앱에 노출 여부
- visible_regions: 표시 지역 제한, 빈 값은 전체
- min_amount / max_amount: 주문 금액 구간 제한

### 대사

매일 새벽 각 채널 정산 리포트를 가져와 시스템 transaction과 건별 대사, 차액 > $0.01이면 알람.

### 환불 정책

- 서버/VPS: 구매 후 72h 내 전액 환불
- 도메인: 등록 후 5일 내 환불 가능 (ICANN 규정)
- IP: 구매 후 환불 불가
- 클라우드 디스크: 서버와 동일 정책
- 특별 프로모션 상품: 환불 불가

환불 흐름: 사용자 신청 → Ticket 생성 → 고객지원 심사 → admin 확인 → provider.destroy() → payment.refund() → 원경로 환불

---

## 7. 클라이언트 페이지 구조
### Flutter / HarmonyOS 사용자 앱

- **인증**: 로그인/가입 (이메일+비밀번호, Google OAuth, Apple ID, 휴대폰 번호), 비밀번호 찾기, 2단계 인증
- **홈**: 지역 선택기, 제품 분류 진입, Banner/프로모션, 추천 상품
- **제품**: 목록 (다중 조건 필터), 상세 (구성/지역/가격 계산기), 평가
- **쇼핑&결제**: 장바구니, 주문 확인 (결제 수단/청구 주소/잔액/쿠폰), 결제 창구, 결제 결과
- **내 리소스**: 리소스 목록 (상태별 필터), 상세 조작 (재시작/종료/갱신/업그레이드/폐기), 콘솔 SSO, 사용량 차트
- **주문**: 목록 (결제 대기/결제 완료/완료/환불), 상세, 인보이스
- **티켓**: 목록, 신규 생성, 대화
- **개인 센터**: 프로필/KYC, 잔액&충전, 알림, 주소 관리, 언어/통화/보안 설정
- **공통**: 헬프 센터, 서비스 약관, 소개

### webman-admin 관리 백엔드

- **대시보드**: 총괄 + 추이 그래프
- **사용자 관리**: 목록/상세/KYC 심사
- **상품 관리**: 분류/목록/가격(SKU×지역)/재고/평가
- **주문 관리**: 목록/상세/환불 심사/인보이스
- **결제 관리**: 채널 설정/거래 기록/대사 리포트
- **리소스 관리**: 목록/프로비저닝 태스크 모니터링/클라우드 공급업체 API 설정
- **공급업체 관리**: 입점 심사/목록/상품 배분/정산/출금
- **티켓 관리**: 큐/내 티켓/SLA 모니터링
- **도메인 관리**: TLD 가격/등록기관 API/이전 관리
- **메시지 알림**: 템플릿 관리/발송 기록
- **시스템 설정**: 관리자&역할/조작 로그/다중 언어/환율/지역/시스템 파라미터
- **리포트**: 매출/공급업체 정산/제품 판매 분석/지역 분석

---

## 8. 메시지 알림 시스템
### 4개 채널

Email (SMTP/SendGrid) / SMS (Twilio/알리 SMS) / Push (FCM/HMS) / 사이트 내 메시지

### 흐름

이벤트 트리거 → Notification Dispatcher → 템플릿 매칭 (이벤트 코드+언어 선호) → 사용자 선호별 각 채널 배포 → Redis Queue 비동기 발송

### 알림 유형

가입 인증 코드, 주문 결제 성공, 리소스 개통 완료, 리소스 만료 알림 (7d/3d/1d), 티켓 답변, 환불 완료, 보안 알림, 프로모션

### 실패 재시도

3회 백오프, webman redis-queue로 관리.

---

## 9. 공급업체 시스템
### 입점 프로세스

가입 → 회사 정보+연락처+정산 방식 제출 → 관리자 심사 → 승인 후 상품 등록 → admin 상품 심사 → 사용자 구매 → 자동 정산 → 공급업체 출금 신청 → admin 송금

### 권한 격리

공급업체는 자신의 상품/주문/정산서/티켓/출금 기록만 볼 수 있습니다. 플랫폼 매출, 다른 공급업체 데이터, 결제 채널 설정은 볼 수 없습니다.

### 정산 규칙

- 자체 운영 상품: commission_rate = 100% (전액 플랫폼 귀속)
- 제3자 상품: commission_rate = 5%~20% (플랫폼 수수료)
- 정산 공식: 주문 상품 금액 - 플랫폼 수수료 - 채널 수수료 = 공급업체 수취액
- 정산 주기: 주간 정산 / 월간 정산

### 공급업체 전체 업무 흐름

```
공급업체 입점                              관리자 승인
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 공급업체 정보 심사
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 비밀번호 확인
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 성공
상품 등록
────────
POST /supplier/products                관리자 심사
  → {product_id, commission_rate}        → 공급업체 상품 연결 + 수수료 비율 설정
  ← {supplier_product}                    → 상품 상태: published

사용자 주문 ──→ 결제 완료 ──→ 리소스 개통 ──→ 주문 완료

정기 정산 (매주 월요일 04:17)                    출금
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 기간 내 완료 주문 집계                → 비밀번호 2차 확인 (ConfirmationMiddleware)
  → total_sales - commission 계산      → SupplierService::requestWithdraw()
  → = payable                            → 출금 가능 잔액 확인
  → SupplierSettlement 생성              → SupplierWithdraw 생성 (status:pending)
  → Webhook: settlement.created          ← 성공

관리자 송금                              관리자 API Key 관리
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → sk_xxx 생성 (SHA256 저장)
  → 비밀번호 확인                         ← {api_key} (1회만 표시)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. 모니터링 및 운영
### 리소스 모니터링

- 수집 지표: CPU/메모리/디스크/대역폭 사용률, IP 연결성, 클라우드 디스크 IOPS, DNS 해석, SSL 인증서 만료
- 수집 방식: Agent 보고 / SNMP (자체) + 클라우드 공급업체 모니터링 API (제3자) + WHOIS/DNS 폴링 (도메인)
- 수집 주기: 5분, Prometheus + VictoriaMetrics 저장

### 알람 규칙

| 알람 이벤트 | 심각도 | 트리거 조건 |
|----------|--------|----------|
| 서버 다운 | 심각 | 연속 3회 Ping 불가 |
| CPU/메모리 > 90% | 안내 | 10분 지속 |
| 디스크 > 90% | 경고 | 5분 지속 |
| 대역폭 > 80% | 안내 | 30분 지속 |
| SSL 인증서 < 30일 만료 | 경고 | 매일 검사 |
| 도메인 < 30일 만료 | 경고 | 매일 검사 |
| 프로비저닝 태스크 실패 | 심각 | 연속 2회 실패 |
| 결제 대사 차이 | 심각 | 건당 > $0.01 |

---

## 11. 배포 아키텍처
### 프로덕션 환경

- 애플리케이션 서버 × 2: webman (멀티 프로세스) + Nginx + Supervisor
- 데이터베이스: MySQL 8.0 마스터/슬레이브 (1 마스터 2 슬레이브) + Redis Cluster
- 큐: webman redis-queue (결제 콜백/알림/프로비저닝 태스크)
- 스케줄: Crontab (대사/정산/도메인 검사/갱신 알림)
- 스토리지: S3/OSS + CDN
- 로그 모니터링: ELK/Loki + Prometheus + Grafana + Sentry

### 디렉터리 구조

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 클라이언트
│   └── harmonyos/         # HarmonyOS 클라이언트 (ArkTS)
├── service/               # webman 서버
│   ├── app/
│   │   ├── controller/    # 컨트롤러 (모듈별)
│   │   ├── service/       # 비즈니스 로직 (모듈별)
│   │   ├── model/         # 데이터 모델
│   │   ├── middleware/     # 미들웨어
│   │   ├── event/         # 이벤트 정의
│   │   ├── listener/      # 이벤트 리스너
│   │   ├── queue/         # 큐 태스크
│   │   ├── provider/      # 클라우드 공급업체 어댑터
│   │   └── cron/          # 스케줄 태스크
│   ├── common/            # 공용 라이브러리 (auth/payment/i18n/notification/helper)
│   ├── config/            # 설정 파일
│   ├── database/
│   │   └── migrations/    # 데이터베이스 마이그레이션
│   └── storage/           # 로그/캐시/업로드
├── admin/                 # webman-admin
├── docs/                  # 문서
└── docker/                # Docker 설정
```

### 핵심 Composer 의존성

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### 고동시성 최적화

#### 1. MySQL 읽기/쓰기 분리

Eloquent가 SELECT를 read 연결로, INSERT/UPDATE/DELETE를 write 연결로 자동 라우팅합니다.

```
설정 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (마스터)
  connections.mysql.read  → DB_READ_HOST  (슬레이브, 여러 개 설정해 로드 밸런싱 가능)
  sticky = true           → 동일 요청 주기 내 쓰기 후 읽기는 마스터 사용（마스터-슬레이브 지연 방지）

환경 변수:
  DB_HOST=10.0.1.1          # 마스터（쓰기）
  DB_READ_HOST=10.0.2.1     # 슬레이브（읽기）, 여러 대 배포 가능
```

**읽기/쓰기 라우팅 규칙:**

| 작업 유형 | 라우팅 대상 | 예시 |
|---------|---------|------|
| SELECT | read 연결 | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | write 연결 | `Order::create(...)` |
| 트랜잭션 내 모든 작업 | write 연결 | `DB::transaction(...)` |
| 쓰기 후 읽기（sticky） | write 연결 | 동일 요청 주기 내 |

#### 2. Redis 다중 레벨 캐시 전략

`CacheService`로 고빈도 읽기 데이터를 캐시하며, Redis를 사용할 수 없으면 자동으로 데이터베이스 직접 조회로 강등됩니다.

```
캐시 계층:
  L1: Redis (프로세스 간 공유, 밀리초 단위)
  L2: MySQL (영속화, 폴백)

캐시 전략:
  제품 목록        TTL 5min    region_id + category_id + keyword별 키 분리
  제품 상세        TTL 10min   product_id별 키, 내용 변경 시 능동 무효화
  지역 목록        TTL 1h      지역 데이터는 변동이 극히 적음
  환율            TTL 30min   스케줄 태스크 갱신 + 능동 업데이트
  TLD 가격        TTL 1h      TLD 가격 변동 빈도 낮음
  헬프 문서        TTL 10min   발행/수정 시 능동 무효화
  상품 분류        TTL 10min   분류 트리 변경 시 능동 무효화

캐시 워밍 (배포 후):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

능동 무효화 (데이터 변경 시):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// 사용 예시
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Nginx 응답 압축 + 제한

```
gzip 압축:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  효과: JSON 응답 압축률 70-85%, 대역폭 절약

proxy 최적화:
  proxy_buffering on           # 업스트림 응답 버퍼링, 느린 클라이언트가 worker 점유하지 않음
  proxy_http_version 1.1       # HTTP/1.1 긴 연결 재사용
  keep-alive to upstream        # TCP 핸드셰이크 감소

제한:
  limit_req: IP당 10 req/s (burst 20)
  limit_conn: IP당 20 동시 연결
  /health 엔드포인트는 제한 없음（access_log 꺼서 I/O 감소）
```

#### 4. 데이터베이스 인덱스 제안

쿼리 패턴 분석에 기반하여, 다음 인덱스는 고동시성 시나리오에서 스캔 행 수를 크게 줄여줍니다:

| 테이블 | 제안 인덱스 | 커버 쿼리 |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | 사용자 주문 목록 + 상태 필터 |
| `orders` | `(order_no)` (유니크) | 주문 번호 정확 조회 |
| `products` | `(status, category_id, sort)` | 프런트엔드 제품 목록 + 분류 필터 + 정렬 |
| `product_skus` | `(product_id, status)` | SKU 목록 + 상태 필터 |
| `product_regions` | `(sku_id, region_id)` (유니크) | 지역 가격 조회 |
| `resources` | `(user_id, status)` | 내 리소스 목록 |
| `resources` | `(expired_at, status)` | 만료 검사 스케줄 태스크 |
| `provision_tasks` | `(status, next_retry_at)` | Worker 폴링 대기 태스크 |
| `refresh_tokens` | `(user_id, revoked)` | 세션 관리 쿼리 |
| `payment_transactions` | `(order_id)` | 주문별 거래 조회 |
| `payment_transactions` | `(transaction_no)` (유니크) | Webhook 멱등 검사 |
| `tickets` | `(user_id, status)` | 사용자 티켓 목록 |
| `notifications` | `(user_id, read_at, created_at)` | 사용자 알림 목록 |

#### 5. 동시 연결 추정

```
webman 멀티 프로세스:
  CPU 코어 수 × 프로세스 수 = worker 수
  예: 4코어 × 8 worker = 32 worker 프로세스
  
MySQL 연결 수:
  각 worker가 지속 연결 1개 유지
  32 worker × 2 인스턴스 (service + admin) = 64 연결
  마스터 32 + 슬레이브 32, 보수적으로 MySQL max_connections ≥ 200 권장

Nginx 연결 수:
  worker_connections 1024 × worker_processes auto
  피크 동시 ≈ worker_connections × worker_processes / 2
  4코어 서버 ≈ 2048 동시 연결
```

---

## 12. 구현 상태 총괄표
### 핵심 모듈

| 모듈 | 상태 | 설명 |
|------|------|------|
| **User** | ✅ 완료 | 가입/로그인/이메일 인증/OAuth/TOTP/세션 관리/GDPR 삭제/주소 CRUD |
| **Product** | ✅ 완료 | SKU×지역 가격, 분류, 검색(ES), 평가, 속성, 일괄 가져오기/내보내기 |
| **Order** | ✅ 완료 | 장바구니, 주문, 수명주기, 환불, 인보이스(PDF), 쿠폰 |
| **Payment** | ✅ 완료 | Stripe 채널, 다중 채널 라우팅, webhook 서명 검증, 대사 |
| **Provisioning** | ✅ 완료 | Proxmox + AWS EC2 + ProviderFactory 확장 가능 아키텍처 |
| **Domain** | ✅ 완료 | TLD 가격, DNS 레코드, 도메인 이전 승인 |
| **Supplier** | ✅ 완료 | 입점 승인, 상품 등록, 정산, 출금, API Key 관리 |
| **Monitor** | ✅ 완료 | 리소스 헬스체크, 알람 엔진, SSL 인증서 모니터링 |
| **Ticket** | ✅ 완료 | 생성/답변/할당/종료/SLA 추적 |
| **Notification** | ✅ 완료 | 이메일/SMS/Push/사이트 내 메시지 4채널 + 사용자 선호 관리 |
| **Report** | ✅ 완료 | 매출/공급업체/지역 리포트 |
| **I18n** | ✅ 완료 | 다중 언어, 다중 통화, 다중 시간대 |

### 보안 체계

| 기능 | 상태 |
|------|------|
| WAF (8개 클래스 45+ 규칙: SQL 인젝션/XSS/명령어 인젝션/파일 인클루전/헤더 인젝션/SSRF/NoSQL 인젝션/오픈 리다이렉트) | ✅ |
| CORS 미들웨어 | ✅ |
| ClientPlatform 플랫폼 식별 미들웨어 (8개 플랫폼) | ✅ |
| API 제한 (Redis 토큰 버킷) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| 유지보수 모드 (환경 변수 스위치 + IP 화이트리스트) | ✅ |
| 요청/응답 암호화 (AES-256-GCM) | ✅ |
| 감사 로그 (독립 DB, client_platform 추적 포함) | ✅ |
| 데이터 마스킹 (로그/응답 자동 처리) | ✅ |
| JWT 기기 지문 바인딩 + 토큰 로테이션 + client_platform 기록 | ✅ |
| bcrypt 비밀번호 (cost=12) + Encryptable 2차 암호화 | ✅ |
| 2차 비밀번호 확인 (ConfirmationMiddleware, 5회 실패 시 15분 잠금) | ✅ |
| Admin 패널 WAF 미들웨어 | ✅ |
| Sentry 예외 모니터링（SentryBootstrap + before_send 마스킹） | ✅ |
| Feature Flags 기능 스위치（Redis 동적 오버라이드 + 관리 백엔드 API） | ✅ |

### 신규 기능 (2026-05-21)

| 기능 | 상태 |
|------|------|
| 공급업체 외부 API（API Key 인증 + 주문/리소스/정산/출금 엔드포인트） | ✅ |
| WebSocket 실시간 푸시（Workerman 네이티브 WebSocket + 이벤트 리스닝） | ✅ |
| k6 부하 테스트 스크립트（스모크/제품/동시성） | ✅ |

### 백엔드 통계

| 지표 | 수량 |
|------|------|
| API 엔드포인트 | 135 |
| 데이터 모델 | 50+ |
| 데이터베이스 테이블 | 50+ |
| 미들웨어 | 15개（전역 7 + 라우트 6 + 외부 API 1 + admin WebSocket） |
| 스케줄 태스크 | 7개 |
| 마이그레이션 파일 | 22개 |
| 테스트 | 362 tests / 579 assertions（Service 295 + Admin 67） |
| 테스트 파일 | 22개 |
| k6 부하 테스트 스크립트 | 3개 (smoke / products / concurrent) |

### 문서

| 문서 | 경로 |
|------|------|
| 시스템 설계 규격 | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| 관리 백엔드 설계 | `docs/admin-design.md` |
| 공급업체 API 문서 | `docs/supplier-api.md` |
| 배포 체크리스트 | `docs/deployment.md` |
| API 스모크 테스트 스크립트 | `docs/api-test.sh` |

### 프런트엔드 상태

| 엔드 | 상태 | 설명 |
|----|------|------|
| Flutter | 🟡 진행 중 | ApiClient에 header 버전 번호 연동 + ApiService 통일 데이터 레이어; 로그인/상품 목록/장바구니/리소스 목록은 API 연동 완료; 주문 기록/알림 센터는 컴파일 환경 검증 필요 |
| HarmonyOS | 🔴 초기 | 로그인 페이지와 ApiClient만 있음 |
| Admin Panel | ✅ 완료 | 대시보드/사용자/상품/주문/결제/리소스/공급업체/티켓/도메인/알림/시스템/리포트/Webhook/가져오기·내보내기 전 기능 |
