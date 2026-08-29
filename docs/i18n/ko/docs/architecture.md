# CloudPlatform 아키텍처 설계 문서

## 1. 시스템 개요

CloudPlatform은 글로벌 클라우드 리소스 거래 플랫폼으로, 자체 운영 물리 머신 + 제3자 공급업체 하이브리드 모델을 지원합니다. 사용자는 Web/모바일로 서버(VM), IP 주소, 클라우드 디스크, 도메인 등의 상품을 구매할 수 있으며, 시스템이 자동으로 결제 처리와 리소스 인도를 완료합니다.

### 1.1 핵심 아키텍처 결정

| 결정 | 선택 | 이유 |
|------|------|------|
| 백엔드 프레임워크 | PHP webman (Workerman) | 상주 메모리, 이벤트 기반, 멀티 프로세스, 밀리초 응답 |
| 아키텍처 패턴 | 모듈형 모노리스 | 모듈을 비즈니스 기준으로 수직 분할, 내부 MVC 계층화, 모듈 간 이벤트 디커플링 |
| 관리 백오피스 | 독립 webman 인스턴스 (webman-admin + Layui) | 관리 트래픽과 사용자 트래픽 격리, 장애 도메인 분리 |
| ORM | Illuminate/Eloquent | Laravel 생태계 성숙, 연관 쿼리, Scope, 이벤트, 마이그레이션 |
| 분산 기본 키 | Snowflake 64-bit | 자동 증가 의존 없음, 자연스러운 샤딩 지원 |
| ID 난독화 | Hashids | 외부에 실제 ID 규모 숨김, 크롤러 순회 방지 |
| 인증 | JWT HS256 | 무상태 인증, Access 15min + Refresh 30d |
| 전송 암호화 | AES-256-GCM | 미들웨어 투명 암호화/복호화, GCM 인증 암호화로 변조 방지 |
| 필드 암호화 | AES-128-ECB | Eloquent Cast 자동 암호화/복호화, 결정적 암호화（암호문 등가 조회 가능, 로그인/고유성 검증 의존）；ECB만 지원 |
| 메시지 큐 | Redis Queue | 결제 콜백, 알림 배포, 리소스 개통 비동기 처리 |
| 검색 엔진 | database（기본）/ Elasticsearch 8.x | webman-scout 기본 database 드라이버（SQL LIKE 폴백）；ES 구성 후 IK 분석기 인덱스 활성화 |
| 가상화 | Proxmox VE + kvm-server | 자체 운영 VM은 Rust kvm-server（gRPC :50051, e-cat/etcd 등록 발견）가 공급；드라이버 계층은 현재 시뮬레이션 드라이버, libvirt 실제 드라이버는 Phase 2 |
| 클라이언트 | Flutter | 단일 코드베이스로 iOS/macOS/Windows/Linux/Web 5개 플랫폼 + HarmonyOS |

### 1.2 시스템 경계

```
┌──────────────────────────────────────────────────────────────────┐
│                          사용자 단말                              │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx 리버스 프록시                            │
│  SSL 터미네이션 / gzip 압축 / 빈도 제한 / WebSocket Upgrade       │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman 서버 (멀티 프로세스)                          │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │전역 미들웨어 체인: Version→CORS→SecurityHeaders→ClientPlatform│ │
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[라우트 미들웨어]   │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket Server (:8282) — 실시간 푸시                   │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (주/복제)  │ │(캐시/큐)  │ │    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  e-cat / etcd 등록 발견     │
    │  시뮬레이션 드라이버 (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  KVM/QEMU 가상화            │
    │  IP 풀 / 디스크 풀 / 호스트  │
    └─────────────────────────────┘
```

---

## 2. 컴포넌트 아키텍처

### 2.1 이중 인스턴스 설계

프로젝트는 MySQL 데이터베이스를 공유하는 두 개의 독립 webman 인스턴스로 구성됩니다:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   미들웨어: WAF→ACL   │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   전역 12+라우트 6 미들웨어 │
                    └─────────────────────┘
```

| 인스턴스 | 포트 | 책임 | 미들웨어 |
|------|------|------|--------|
| **service** | 8787 | 사용자 API + 관리 API + WebSocket | 전역 12 + 라우트 6 + SupplierApiKey |
| **admin** | 8788 | 관리 백오피스 HTML 패널 (Layui) | WafMiddleware + AccessControl |

### 2.2 모듈 계층 구조

각 비즈니스 모듈 내부는 통일된 계층을 따릅니다:

```
app/{Module}/
├── controller/     # HTTP 계층: 파라미터 검증, Service 호출, Response 반환
│   └── external/   # 외부 API 컨트롤러 (공급업체 API Key 인증)
├── service/        # 비즈니스 로직: HTTP 의존 없음, Controller/Queue Worker가 재사용 가능
├── model/          # Eloquent 데이터 모델: 관계 정의, 쿼리 스코프, Casts
├── event/          # 도메인 이벤트 정의 (OrderPaid, TicketCreated 등)
├── listener/       # 이벤트 리스너 (Provisioning, WebSocket 푸시)
├── provider/       # 클라우드 벤더 어댑터 (ProxmoxProvider 등)
├── queue/          # 큐 컨슈머 (ProvisionWorker, EmailSender 등)
└── cron/           # 예약 작업 (ExchangeRateSync, ExpirationCheck 등)
```

### 2.3 공통 라이브러리 계층

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # 클릭 캡차 서비스
├── clientplatform/      # ClientPlatformMiddleware（X-Client-Platform 헤더）
├── confirmation/        # 2차 비밀번호 확인 미들웨어
├── encryption/          # AES-256-GCM 전송 암호화 미들웨어
├── feature/             # Feature Flags 기능 스위치
├── hashid/              # Hashids 요청 디코드 미들웨어 + 인코딩/디코딩 서비스
├── helper/              # Response 포맷팅 + CacheService
├── http/                # HTTP 클라이언트 도구
├── i18n/middleware/     # 다국어 LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # 스노우플레이크 ID 서비스 + Eloquent Trait
├── metrics/             # Prometheus 지표 수집기 + 렌더러 + HTTP 요청 카운트 미들웨어
├── version/             # VersionMiddleware（X-Api-Version 헤더）
└── webhook/             # Webhook 이벤트 디스패처
```

### 2.4 CDN 모듈

제품급 CDN 모듈（`service/app/cdn/`）은 어댑터 패턴으로 4개 서비스 제공업체에 연동하며, 서버나 스토리지 버킷을 원본 서버로 CDN에 연결합니다:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4（SSL SaaS 자동 인증서）, ICP 비안 불필요
  ├── CloudFrontAdapter   aws-sdk-php（CloudFront + ACM）, ICP 비안 불필요
  ├── AliyunCdnAdapter    RPC 서명, ICP 비안 필요
  └── TencentCdnAdapter   TC3 서명, ICP 비안 필요
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① 바인딩 계정 (provider_account_id) → ② code=cdn-{type} 활성 계정 → ③ env 폴백
  strict=true（삭제/purge）: 바인딩 계정만 사용, 누락 시 4003, 조용한 전환 없음
```

**계정 관리:** `provider_apis` 모델 재사용（자격 증명 Encryptable로 암호화 저장）, 관리 단말 `/admin/providers` CRUD（RbacMiddleware）, `code` 규약 `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, env 자격 증명은 fallback으로 강등.

**데이터 모델:** `resource_cdn`（provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; cert_config는 저장 전 개인 키 제거）. 권한 격리: CDN 리소스는 `resource.user_id` 귀속 검증을 거치며, 다른 사용자의 리소스는 일괄 404.

---

## 3. 미들웨어 실행 파이프라인

### 3.1 전역 미들웨어 체인 (모든 요청)

```
HTTP 요청
  │
  ▼
1. VersionMiddleware         ← X-Api-Version 헤더 검증, 누락 시 기본 v1, 무효 시 400
  │                            /api/와 /admin/api/에만 적용
  ▼
2. CorsMiddleware            ← OPTIONS 프리플라이트에 CORS 헤더 반환, Origin 반사
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy 보안 응답 헤더
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform 헤더 식별 (8개 플랫폼), properties 주입
  │                            /api/와 /admin/api/에만 적용
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES 국가 차단 (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← 8종 45+ 규칙 스캔 (JSON body + URL + UA + 원본 본문)
  │                          ← Content-Type 화이트리스트 + 요청 본문 10MB 제한 + URL 2KB 제한
  │                            적중 시 → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31종 공격 탐지 (XSS/SQLi/SSRF/역직렬화 등), IP 화이트/블랙리스트
  ▼
8. RateLimitMiddleware       ← 전체 라우트 빈도 제한 (per-IP + per-token 이중 버킷)
  ▼
9. LocaleMiddleware          ← Accept-Language 파싱, 로케일 설정
  ▼
10. MetricsMiddleware        ← Prometheus HTTP 요청 카운트와 지연 기록
  ▼
11. HashidRequestMiddleware  ← 요청 파라미터 hashid 문자열 → 실제 정수 ID 디코드
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE 검사, 화이트리스트 IP 방출
  │
  ▼
[라우트 미들웨어 — 라우트 그룹별 부착]
  │
  ├─ /health (내부 모니터링) ────────────
  │   InternalTokenMiddleware      ← 내부 토큰 검증 /health/live|ready|deps
  │
  ├─ /api/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM 요청/응답 본문 암호화/복호화
  │
  ├─ /api (사용자 인증) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token 검증 → $request->userId/role
  │
  ├─ /api (민감 작업) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← 비밀번호 2차 확인, Redis 카운터, 5회 시 15min 잠금
  │
  ├─ /api/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 검증 → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC 권한 검사
  │
  └─ /admin/api (민감 작업) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller → Service → Model → DB
```

### 3.2 미들웨어 상세

| 미들웨어 | 위치 | 등록 방식 | 책임 |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | 전역 | `X-Api-Version` 검증, 누락 시 기본 v1 |
| `CorsMiddleware` | common/Security | 전역 | OPTIONS 프리플라이트, Origin 반사 |
| `SecurityHeadersMiddleware` | common/Security | 전역 | HSTS / X-Frame-Options / CSP / Referrer-Policy 보안 응답 헤더 |
| `ClientPlatformMiddleware` | common/ClientPlatform | 전역 | `X-Client-Platform` 8개 플랫폼 식별 |
| `GeoBlockMiddleware` | common/Security | 전역 | GEO_BLOCKED_COUNTRIES 지역 차단 (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | 전역(service)+admin | 8종 45+ 규칙 + 요청 제한 |
| `SecurityPlugin` | Erikwang2013\Security | 전역 | 31종 공격 탐지, IP 화이트/블랙리스트 |
| `RateLimitMiddleware` | common/Security | 전역 | Redis 토큰 버킷 빈도 제한（per-IP + per-token 이중 버킷） |
| `LocaleMiddleware` | common/I18n | 전역 | Accept-Language 파싱 |
| `MetricsMiddleware` | common/Metrics | 전역 | Prometheus HTTP 요청 카운트와 지연 |
| `HashidRequestMiddleware` | common/Hashid | 전역 | hashid 요청 디코드 |
| `MaintenanceMiddleware` | common/Security | 전역 | 유지보수 모드 + IP 화이트리스트 |
| `InternalTokenMiddleware` | common/Security | 라우트 그룹 | `/health/live|ready|deps` 내부 토큰 검증 |
| `EncryptionMiddleware` | common/Encryption | 라우트 그룹 | AES-256-GCM 암호화/복호화 |
| `AuthMiddleware` | common/Auth | 라우트 그룹 | JWT Bearer Token 검증 |
| `AdminRoleMiddleware` | common/Auth | 라우트 그룹 | 관리자 RBAC |
| `ConfirmationMiddleware` | common/Confirmation | 라우트 그룹 | 비밀번호 2차 확인 |
| `SupplierApiKeyMiddleware` | common/Auth | 라우트 그룹 | sk_xxx API Key SHA256 검증 |

---

## 4. 데이터 아키텍처

### 4.1 분산 기본 키: Snowflake

```
64-bit Snowflake ID 구조:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  밀리초 타임스탬프   데이터센터  워커 노드  시퀀스 번호
  Epoch: 2024-01-01
  최대 수명: ~69년
```

모든 Eloquent Model은 `creating` 이벤트에서 `HasSnowflakeId` Trait으로 자동 생성. DB 컬럼 타입은 `bigint unsigned`.

### 4.2 ID 난독화: Hashids

```
요청 흐름:
  Client: GET /api/products/aB3xK7mQ9w
    → HashidRequestMiddleware 디코드 → int(1234567890)
      → Controller/Service가 정수 ID로 작업
        → Response::success() / Response::paginated()
          → hashids_encode_ids()가 모든 id/*_id 필드 재귀 인코딩
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 데이터베이스 연결

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL 주 DB (쓰기) │     │  MySQL 복제 DB (읽기)│
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  영속 연결 (PDO)      │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  audit DB (독립 연결) │
         │  감사 로그 격리 저장    │
         └──────────────────────┘
```

### 4.4 암호화 계층

| 계층 | 알고리즘 | 구현 | 용도 |
|------|------|------|------|
| 전송 계층 | AES-256-GCM | EncryptionMiddleware | API 요청/응답 본문 암호화, GCM 인증 |
| 필드 계층 | AES-128-ECB | Encryptable Cast | 민감 필드 자동 암호화/복호화（결정적 암호화: 동일 평문→동일 암호문, 로그인/고유성 검증은 암호문 등가 조회；ECB만 지원, cipher 변경 시 재암호화 마이그레이션 필요） |
| 해시 계층 | bcrypt + SHA256 | JWT / API Key | 비밀번호/Token 비가역 저장 |
| 기본 키 계층 | Hashids | Response + Middleware | ID 외부 난독화 |

### 4.5 캐시 계층

```
L1: Redis 캐시 계층 (CacheService)
    상품 목록 TTL 5min | 상품 상세 TTL 10min
    지역 TTL 1h | 환율 TTL 30min | TLD TTL 1h
    무효화 전략: 데이터 변경 시 능동 forget / forgetPattern

L2: MySQL 쿼리 계층 (Eloquent + 인덱스 최적화)
    복합/커버링 인덱스 13개가 고빈도 쿼리 커버

L3: Nginx 응답 압축 (gzip level 6)
    JSON 응답 압축률 70-85%
```

### 4.6 국제화 (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (전역 미들웨어)
         │  주 언어 파싱 → zh-CN
         │  I18n::setLocale('zh-CN')
         │  i18n/zh-CN/messages.php 로드
         ▼
  Controller / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  언어별 값 조회
         └── I18n::getLocale()                  →  'zh-CN'
```

| 기능 | 설명 |
|------|------|
| 헤더 파싱 | `LocaleMiddleware`가 `Accept-Language` 헤더 자동 파싱 |
| 언어 폴백 | 미지원 언어 → `fallback_locale` |
| 정적 번역 | 120개 항목, 15개 모듈 커버（`i18n/{locale}/messages.php`） |
| 파라미터 치환 | `I18n::trans('key', ['field' => 'value'])` |
| JSON 필드 | `translateField()`가 다국어 JSON 컬럼 처리 |

---

## 5. 보안 아키텍처

### 5.1 WAF 규칙 체계 (8종 45+개)

| 카테고리 | 규칙 수 | 탐지 범위 |
|------|--------|---------|
| SQL 주입 | 9 | 주석 기호, 키워드, 16진수 인코딩, 유니온 쿼리, 영진 조건, 시간 블라인드, 스택드 쿼리 |
| XSS | 8 | HTML 태그, Script 변형, 이벤트 핸들러 13종, JS 의사 프로토콜, 엔티티 인코딩, Data URI |
| 명령 주입 | 5 | 파이프 이후 명령, 세미콜론 이후 명령, $(cmd), 백틱, 독립 명령 키워드 |
| 파일 포함 | 4 | 경로 트래버설, PHP 의사 프로토콜, 절대 경로, Null byte |
| HTTP 헤더 주입 | 2 | CRLF 개행, Host/Cookie/Set-Cookie 주입 |
| SSRF | 6 | 내부망 IP, localhost, 클라우드 metadata, file:// 프로토콜 |
| NoSQL 주입 | 3 | MongoDB 연산자, Redis 위험 명령 |
| 오픈 리다이렉트 | 2 | redirect_uri 외부 URL, 이중 인코딩 우회 |

**스캔 범위:** 값 주입류 규칙（SQLi/XSS/명령 주입/헤더 주입/SSRF/NoSQL/오픈 리다이렉트）은 query string, 요청 본문, User-Agent 스캔；URL path는 파일 포함（경로 트래버설）패턴으로만 구조 검증. 비즈니스 경로에 select/insert/alert 같은 일반 단어가 포함되므로（예: `/order_item/select`）, 전체 경로를 스캔하면 모든 CRUD 엔드포인트가 오탐되므로 path는 값 주입 매칭에 참여하지 않음.

**요청 레벨 방어:** Content-Type 화이트리스트, 요청 본문 10MB 제한, URL 2KB 제한

### 5.2 인증 체계

```
┌─────────────────────────────────────────────┐
│                인증 방식                     │
├──────────────┬──────────────┬───────────────┤
│  사용자 단말   │  관리 단말    │  공급업체 API  │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min │  Access 2h   │  sk_xxx 접두사 │
│  Refresh 30d  │  Refresh 7d  │  SHA256 저장  │
│  TOTP 선택    │              │  한 번만 표시  │
│  OAuth 선택   │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. 배포 아키텍처

### 6.1 프로덕션 토폴로지

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← 플랫폼 자체 엣지 보호（DDoS/Bot）,
               │  DDoS / Bot     │    제품급 CDN 모듈（4개 서비스 제공업체,
               └────────┬────────┘    §2.4 참조）과 무관
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL 주복제│ Redis Cluster │
         │ 1주 2복제   │ 캐시+큐      │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  e-cat / etcd 등록         │
         │  시뮬레이션 드라이버 (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Proxmox VE 클러스터        │
         │  물리 머신 × N             │
         │  KVM/QEMU 가상화           │
         └────────────────────────────┘
```

### 6.2 프로세스 모델

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (기본 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. 기술 의존성

### 7.1 핵심 프레임워크

| 패키지 | 버전 | 용도 |
|----|------|------|
| workerman/webman-framework | ^2.1 | 웹 프레임워크（상주 메모리 멀티 프로세스） |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | 이벤트 시스템 |
| illuminate/redis | ^10.0 | Redis 클라이언트 |
| webman/redis-queue | ^1.0 | Redis 메시지 큐 |

### 7.2 erikwang2013 생태계 패키지

| 패키지 | 용도 |
|----|------|
| snowflake-php | 64비트 분산 기본 키 |
| hashids | API ID 난독화 |
| encryptable | DB 필드 암호화 |
| encryption | 전송 암호화 AES-256-GCM |
| jwt-webman | JWT 인증 |
| webman-scout | Elasticsearch 전문 검색 |
| season | 국가 국기 emoji |
| poster-php | 클릭 캡차 |

### 7.3 제3자 연동

| 패키지 | 용도 |
|----|------|
| stripe/stripe-php | Stripe 결제 |
| twilio/sdk | SMS |
| kreait/firebase-php | FCM 푸시 |
| guzzlehttp/guzzle | HTTP 클라이언트 (Proxmox API 등) |
| sentry/sentry | 예외 모니터링 |
| phpoffice/phpspreadsheet | Excel 내보내기 |
