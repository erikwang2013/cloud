# Cloud Platform — 글로벌 클라우드 리소스 거래 플랫폼

## Languages

| Language | Docs |
|----------|------|
| 简体中文 | [README.md](../../../README.md) |
| English | [README_EN.md](../../../README_EN.md) |
| English | [en docs](../../en/README.md) |
| 한국어 | [ko docs](../../ko/README.md) |
| Русский | [ru docs](../../ru/README.md) |
| Deutsch | [de docs](../../de/README.md) |
| Français | [fr docs](../../fr/README.md) |
| Español | [es docs](../../es/README.md) |
| Português | [pt docs](../../pt/README.md) |
| हिन्दी | [hi docs](../../hi/README.md) |
| العربية | [ar docs](../../ar/README.md) |
| বাংলা | [bn docs](../../bn/README.md) |
| Bahasa Indonesia | [id docs](../../id/README.md) |
| 日本語 | [ja docs](../../ja/README.md) |

<p align="center">
  <img src="docs/diagrams/c.svg" alt="CloudPlatform 项目宠物" width="220">
</p>

전 세계 사용자를 대상으로 하는 클라우드 리소스 거래 플랫폼으로, 서버(VM), IP 주소, 클라우드 디스크, 도메인, SSL 인증서, 객체 스토리지(S3), CDN 가속 등의 제품을 온라인으로 구매하고 자동으로 인도받을 수 있습니다. 자체 운영 물리 머신은 Proxmox VE 가상화로 인도되며, 제3자 공급업체의 입점 판매도 지원합니다. 종량제 과금, 추천 제휴 마케팅, GraphQL API 및 Prometheus/Grafana 관측성을 제공합니다.

## 기술 스택

| 계층 | 기술 |
|------|------|
| 백엔드 프레임워크 | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| 관리자 백오피스 | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| 인증 | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| 분산 기본 키 | Snowflake 스노우플레이크 ID ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| ID 난독화 | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| 전송 암호화 | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| 필드 암호화 | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| 전문(full-text) 검색 | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| 국가 국기 | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| 클릭 캡차 | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| 보안 방어 | 31종 공격 탐지 ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| 테이블 내보내기 | PhpSpreadsheet ^2.0 |
| 결제 SDK | Stripe PHP ^15.0 |
| SMS SDK | Twilio PHP ^8.0 |
| 푸시 SDK | Firebase PHP ^7.0 |
| 큐 | webman redis-queue |
| 데이터베이스 | MySQL 8.0（마스터 DB + 감사 DB 이중 연결） |
| 검색 엔진 | Elasticsearch 8.x |
| 가상화 | Proxmox VE（Rust kvm-server gRPC 채널, e-cat/etcd 등록） |
| 클라이언트 | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| 객체 스토리지 | AWS S3 SDK PHP ^3.300 |
| 관측성 | Prometheus + Grafana（사전 구성된 대시보드） |
| 다국어 | i18n 7개 언어（중/영/일/한/독/프/서） |
| 배포 | Docker Compose 원클릭 시작 |

## 시스템 아키텍처

![시스템 아키텍처](docs/diagrams/system-architecture-zh.svg)

## 핵심 비즈니스 프로세스

사용자 가입부터 리소스 인도까지의 완전한 엔드투엔드 비즈니스 프로세스로, 상품 선택, 주문, 결제, 자동 인도, 사후 관리 및 갱신 사이클을 포함합니다.

![핵심 비즈니스 프로세스](docs/diagrams/business-flowchart-zh.svg)

## 다중 통화 결제

시스템은 다중 통화 가격 책정, 결제 및 정산을 기본 지원하며, 사용자 통화 설정, 지역별 가격 책정, 환율 스냅샷부터 결제 수취, 잔액 입금 및 공급업체 정산까지의 전체 체인을 아우릅니다.

![다중 통화 결제 흐름도](docs/diagrams/currency-settlement-zh.svg)

**1. 다중 통화 잔액 계정**

`user_balances`는 `(user_id, currency)` 기준으로 통화별 원장을 기록합니다（고유 인덱스 `uk_user_currency`）. 가입 시 USD + CNY 두 개의 통화 계정이 기본 생성되며, 잔액과 동결 잔액은 통화별로 독립 관리되고 Stripe가 지원하는 임의의 통화로 확장할 수 있습니다.

**2. 다중 통화 지역별 가격 책정**

`product_regions`는 동일 SKU를 같은 지역에서 여러 통화로 가격 책정할 수 있게 지원합니다（고유 인덱스 `uk_sku_region_currency`）. 프런트엔드는 사용자 선호 통화로 가격을 표시하고, 주문 시 `OrderService`가 `(sku_id, region_id, currency)`로 정확히 가격을 조회합니다.

**3. 환율 체계**

`ExchangeRateSync` 예약 작업이 exchangerate-api에서 환율을 동기화하여 Redis에 저장합니다（30분 TTL 캐시）. 각 주문은 주문 시점의 `exchange_rate` 환율 스냅샷을 기록하여 이후 정산의 추적 가능성을 보장합니다.

**4. 다중 통화 결제**

`payment_channels.currency_support`는 각 결제 채널이 지원하는 통화 화이트리스트를 선언하고, `PaymentRouter`가 통화 / 금액 구간 / 노출 지역에 따라 사용 가능한 채널을 동적으로 필터링합니다. Stripe PaymentIntent는 주문 통화로 직접 수취하며, 16종의 소수점 없는 통화(JPY / KRW / VND 등)에 대한 소수 자리 처리가 내장되어 있고, Webhook 콜백에서 금액과 통화 일치 여부를 검증합니다.

**5. 정산 및 리포트**

결제 거래（`payment_transactions`）, 공급업체 정산（`supplier_settlements`）, 매출 리포트 모두 통화와 환율 필드를 보유하며, 통화별로 통계 집계됩니다.

## 기능 모듈 개요

시스템은 4계층 아키텍처로 구성됩니다: 클라이언트 계층(6개 플랫폼 연동), API 게이트웨이 계층(12개 미들웨어), 비즈니스 서비스 계층(20+ 기능 모듈), 인프라 계층(8개 핵심 컴포넌트).

![기능 모듈 개요](docs/diagrams/module-overview-zh.svg)

## 리소스 생명주기

리소스는 생성부터 종료까지 총 6개 상태를 거치며, 8개 생명주기 이벤트에 의해 구동되어 자동 인도, 정지/복구, 만료 알림 및 폐기 정리를 지원합니다.

![리소스 생명주기](docs/diagrams/resource-lifecycle-zh.svg)

## 문서 내비게이션

| 문서 | 설명 |
|------|------|
| [아키텍처 설계 문서](docs/architecture.md) | 시스템 아키텍처, 컴포넌트 관계, 미들웨어 파이프라인, 보안 계층, 데이터 아키텍처, 배포 토폴로지 |
| [기능 설계 문서](docs/features.md) | 21개 모듈 상세 기능 설계, 흐름도, 데이터 모델, 상호작용 설명 포함 |
| [API 인터페이스 문서](docs/api-reference.md) | 200+ 엔드포인트 전체 레퍼런스, 모듈별 그룹화, 요청/응답 예시, 오류 코드 포함 |
| [API 온라인 문서 (service)](http://localhost:8787/apidoc) | hg/apidoc 자동 생성, 기능별 그룹화, 온라인 디버깅 지원 |
| [API 온라인 문서 (admin)](http://localhost:8788/apidoc) | hg/apidoc 자동 생성, 54개 컨트롤러 13개 기능 그룹 |
| [관리자 백오피스 설계](docs/admin-design.md) | Admin 패널 아키텍처, 패키지 통합, ACL 권한, 테스트 스위트 |
| [공급업체 API 문서](docs/supplier-api.md) | 공급업체 API 레퍼런스（내부 + 외부）, SDK 예시 |
| [배포 체크리스트](docs/deployment.md) | 서버 구성, 환경 변수, Nginx, HTTPS, 예약 작업 |
| [리뷰 보고서](docs/review-report-2026-08-04.md) | 생태계 확장 리뷰 보고서, 통계 데이터, 이슈 추적, 확장 제안 포함 |
| [버전 비교](docs/editions.md) | 라이트/스탠다드/프로 버전 기능, 설계, 아키텍처 비교 |

## 디렉터리 구조

```
cloud-php/
├── .claude/                    # Claude Code 구성（settings / skills）
├── .github/workflows/          # CI/CD 파이프라인（문법 검사 + 양단 PHPUnit）
├── admin/                      # 관리자 백오피스（독립 webman 인스턴스）
│   ├── app/                    # 플러그인 소스 (PSR-4: app\)
│   │   ├── bootstrap/          # 프로세스 시작 부트스트랩（Snowflake / Encryptable / Encryption）
│   │   ├── command/            # 콘솔 명령（Migrate / Rollback / Status）
│   │   ├── common/             # 유틸리티 클래스（Auth / Tree / Layui / Util / ExcelExport / Migration）
│   │   ├── controller/         # 54개 컨트롤러 파일（Base / Crud 기반 클래스 + 각 비즈니스 CRUD）
│   │   ├── exception/          # 예외 처리
│   │   ├── middleware/          # 접근 제어 미들웨어（WafMiddleware + AccessControl）
│   │   ├── model/              # 46개 Eloquent 모델（Base 기반 클래스에 Snowflake PK + Encryptable 포함）
│   │   ├── view/               # 뷰 템플릿（Layui 백오피스 패널）
│   │   └── functions.php       # 전역 헬퍼 함수（hashids / encrypt / decrypt）
│   ├── api/                    # 외부 인터페이스 (PSR-4: plugin\admin\api)
│   │   ├── Auth.php            # 인증 인터페이스
│   │   ├── Menu.php            # 메뉴 인터페이스
│   │   ├── Install.php         # 설치 인터페이스
│   │   └── Middleware.php      # 미들웨어 인터페이스
│   ├── config/                 # 애플리케이션 구성
│   │   ├── plugin/erikwang2013/ # 6개 erikwang2013 패키지 구성
│   │   │   ├── snowflake-php/  # 스노우플레이크 ID 생성
│   │   │   ├── hashids/        # ID 난독화
│   │   │   ├── encryptable/    # 필드 수준 암호화
│   │   │   ├── encryption/     # 전송 암호화
│   │   │   ├── webman-scout/   # Elasticsearch 동기화
│   │   │   └── season/         # 국가 국기
│   │   ├── route.php           # 라우트 정의
│   │   ├── middleware.php       # 미들웨어 구성
│   │   ├── database.php        # 데이터베이스 연결
│   │   └── ...                 # 18개 구성 파일
│   ├── database/migrations/    # 데이터베이스 마이그레이션 파일
│   ├── tests/                  # 단위 테스트（PHPUnit 11, 286 tests / 962 assertions）
│   │   ├── HashidsTest.php     # hashids 인코딩/디코딩（21 tests）
│   │   ├── BaseJsonTest.php    # Base::json() ID 인코딩（13 tests）
│   │   ├── CrudHashidsTest.php # Crud 입력 디코딩（14 tests）
│   │   ├── TreeTest.php        # 트리 구조（19 tests）
│   │   ├── AccessControlMiddlewareTest.php # RBAC 접근 제어
│   │   ├── AdminControllersTest.php        # 컨트롤러 회귀 테스트
│   │   └── support/            # 테스트 헬퍼 클래스
│   ├── public/                 # 문서 루트（정적 리소스）
│   ├── vendor/                 # Composer 의존성
│   ├── .env.example            # 환경 변수 템플릿
│   ├── composer.json           # 의존성 선언
│   ├── generate.php            # 코드 생성기
│   ├── phpunit.xml             # PHPUnit 구성
│   └── start.php               # 시작 엔트리
├── service/                    # 백엔드 서비스（독립 webman 인스턴스）
│   ├── app/                    # 비즈니스 모듈 (PSR-4: App\)，각 모듈에 Controller / Model / Service 등 계층 포함
│   │   ├── admin/controller/   # 관리자 백오피스 API（15개 컨트롤러：Dashboard / User / Product / Order / Payment / Supplier / Coupon / Invoice / Domain / Webhook 등）
│   │   ├── affiliate/          # 제휴 커미션 / 추천 분배（Controller / Listener / Model / Service）
│   │   ├── billing/            # 사용량 과금 / 청구서（Cron / Service）
│   │   ├── captcha/controller/ # 클릭 캡차
│   │   ├── cdn/                # CDN 리소스 호스팅（Controller / Model / Provider / Service）
│   │   ├── command/            # 콘솔 명령（Migrate / Rollback / Status / DbBackup）
│   │   ├── controller/         # 공용 컨트롤러（Health / Status / Help / Upload）
│   │   ├── cron/               # 예약 작업（CronRunner 스케줄러 + ExchangeRateSync / PaymentReconcile / SupplierSettlement / ExpirationCheck / SslCertificateCheck）
│   │   ├── domain/             # 도메인 등록 / DNS 관리（Controller / Model / Service）
│   │   ├── graphql/            # GraphQL API（Mutation / Query / Schema）
│   │   ├── grpc/               # kvm-server gRPC 클라이언트 + etcd 등록（KvmClient / EtcdRegistry）
│   │   ├── model/              # 공용 모델（HelpArticle / Role / Permission）
│   │   ├── monitor/            # 리소스 모니터링 / 알람（Controller / Cron / Model / Service）
│   │   ├── notification/       # 메시지 알림（Controller / Model / Queue / Service）
│   │   ├── order/              # 장바구니 / 주문 / 쿠폰 / 인보이스（Controller / Model / Service）
│   │   ├── payment/            # 결제 라우팅 / Stripe 채널（Controller / Event / Model / Service）
│   │   ├── product/            # 제품 / SKU / 지역 가격 / 리뷰（Controller / Model / Service）
│   │   ├── provisioning/       # 리소스 인도 엔진（Controller / Event / Listener / Model / Provider / Queue / Service）
│   │   ├── report/             # 매출 / 공급업체 / 지역 리포트（Controller / Service）
│   │   ├── ssl/                # SSL 인증서 발급 / 관리（Controller / Model / Service）
│   │   ├── storage/            # 객체 스토리지 리소스（Controller / Model / Provider / Service）
│   │   ├── supplier/           # 공급업체 입점 / 정산 / 출금 + 외부 API（Controller / Model / Service）
│   │   ├── ticket/             # 티켓 시스템（Controller / Event / Listener / Model / Service）
│   │   ├── user/               # 사용자 / 인증 / KYC / 잔액 / 주소（Controller / Model / Service）
│   │   ├── webhook/            # Webhook 메시지 큐（Queue）
│   │   └── websocket/          # WebSocket 서버 + 이벤트 리스너
│   ├── common/                 # 공용 라이브러리 (PSR-4: Common\)
│   │   ├── auth/middleware/     # AuthMiddleware / AdminRoleMiddleware / RbacMiddleware / RoleMiddleware / SupplierApiKeyMiddleware
│   │   ├── captcha/            # 클릭 캡차 서비스
│   │   ├── confirmation/       # 2차 확인 미들웨어（비밀번호 재검증）
│   │   ├── encryption/middleware/ # AES-256-GCM 전송 암호화 미들웨어
│   │   ├── hashid/middleware/   # Hashids 요청 자동 디코딩 미들웨어 + 인코딩/디코딩 서비스
│   │   ├── helper/             # Response 포맷팅（자동 hashid 인코딩）
│   │   ├── http/               # HTTP 클라이언트 유틸리티（ApiRequest）
│   │   ├── i18n/middleware/     # 다국어 미들웨어（Locale）
│   │   ├── security/           # CORS / WAF / 빈도 제한 / 지역 차단 / 유지보수 모드 / 감사 로그
│   │   ├── snowflake/          # 스노우플레이크 ID 생성 서비스 / Eloquent HasSnowflakeId Trait
│   │   ├── version/middleware/  # API 버전 미들웨어（X-Api-Version 헤더 검증）
│   │   ├── clientplatform/middleware/  # 클라이언트 플랫폼 미들웨어（X-Client-Platform 헤더 인식）
│   │   ├── feature/            # Feature Flags 기능 스위치 서비스
│   │   └── webhook/            # Webhook 이벤트 디스패처
│   ├── config/                 # 17개 구성 파일（route / middleware / database / redis / cron / auth / security / i18n / ...）
│   │   └── plugin/             # 플러그인 구성
│   │       ├── erikwang2013/   # encryptable / hashids / jwt / poster / season / webman-scout
│   │       └── webman/         # event / redis-queue
│   ├── database/migrations/    # 데이터베이스 마이그레이션 파일（37개 마이그레이션）
│   ├── i18n/                   # 다국어 리소스（en-US / zh-CN）
│   ├── support/                # Bootstrap 부트스트랩（Eloquent / Redis / Event / 암호화 / 스노우플레이크ID / Hashids / Scout / MigrationRunner）
│   ├── tests/                  # 단위 테스트（PHPUnit 10, 672 tests / 1632 assertions）
│   │   ├── admin/              # ImportExport / SupplierWithdrawApprove
│   │   ├── affiliate/          # AffiliateService
│   │   ├── auth/               # JwtAuth / RbacSeed / Rbac
│   │   ├── billing/            # MeterCollector / UsageAggregator / SuspendCheck
│   │   ├── captcha/            # CaptchaService
│   │   ├── cdn/                # ResourceCdn
│   │   ├── clientplatform/     # ClientPlatformMiddleware
│   │   ├── common/             # Response / Hashid / Snowflake / Validator / LogSanitizer / Totp / ApiRequest
│   │   ├── confirmation/       # ConfirmationMiddleware
│   │   ├── cron/               # SupplierSettlement
│   │   ├── domain/             # DomainService / DomainTransfer
│   │   ├── graphql/            # Schema
│   │   ├── grpc/               # KvmClient / EtcdRegistry
│   │   ├── monitor/            # AlertEngine
│   │   ├── notification/       # NotificationDispatcher
│   │   ├── order/              # Coupon / Invoice
│   │   ├── payment/            # StripeChannel / PaymentRouter
│   │   ├── product/            # ProductService / Search / ReviewStatus
│   │   ├── provisioning/       # ProviderFactory / RetryLogic
│   │   ├── report/             # ReportService
│   │   ├── security/           # RateLimit / Maintenance / UploadSecurity
│   │   ├── ssl/                # SslCertificate
│   │   ├── storage/            # StorageBucket
│   │   ├── supplier/           # SupplierService / Settlement / Rating / Webhook
│   │   ├── ticket/             # TicketUpdatedWiring
│   │   ├── user/               # AddressController
│   │   ├── version/            # VersionMiddleware
│   │   ├── webhook/            # WebhookDispatcher / WebhookE2E
│   │   ├── websocket/          # WebSocketAuth / EventsWiring
│   │   ├── support/            # RequestMock
│   │   ├── bootstrap.php       # 테스트 부트스트랩
│   │   └── TestCase.php        # 테스트 기반 클래스
│   ├── runtime/                # 런타임 파일（로그 / 캐시）
│   ├── vendor/                 # Composer 의존성
│   ├── .env.example            # 환경 변수 템플릿
│   ├── .env                    # 로컬 환경 변수（gitignore）
│   ├── composer.json           # 의존성 선언
│   ├── phpunit.xml             # PHPUnit 구성
│   └── start.php               # 시작 엔트리
├── apps/
│   ├── flutter/                # Flutter 클라이언트（iOS / macOS / Windows / Linux / Web）
│   │   ├── lib/                # Dart 소스（core / features）
│   │   ├── ios/                # iOS 프로젝트
│   │   ├── macos/              # macOS 프로젝트
│   │   ├── windows/            # Windows 프로젝트
│   │   ├── linux/              # Linux 프로젝트
│   │   ├── web/                # Web 프로젝트
│   │   ├── test/               # Flutter 테스트
│   │   ├── pubspec.yaml        # 의존성 선언
│   │   └── analysis_options.yaml # Dart 정적 분석 구성
│   └── harmonyos/              # HarmonyOS 클라이언트 스켈레톤
│       └── entry/src/          # ArkTS 소스
├── docker/                     # Docker 배포
│   ├── Dockerfile              # PHP 8.2 이미지
│   ├── docker-compose.yml      # 서비스 오케스트레이션
│   ├── nginx.conf              # Nginx 구성
│   └── supervisor.conf         # Supervisor 프로세스 데몬
├── infrastructure/             # Rust 인프라（e-cat workspace）
│   ├── kvm-server/             # 자체 클라우드 서비스：VM 공급 gRPC 서비스（:50051，etcd 등록）
│   │   ├── src/                # main / grpc / driver（시뮬레이션 드라이버，libvirt는 Phase 2）
│   │   ├── tests/              # 통합 테스트
│   │   └── Cargo.toml          # e-cat workspace 멤버 선언
│   └── ecat-*/                 # e-cat 인프라 crate（transport-grpc / registry-etcd / protos / config / data 등）
├── docs/                       # 문서
│   ├── admin-design.md         # 관리자 백오피스 설계 문서
│   ├── supplier-api.md         # 공급업체 API 문서
│   ├── deployment.md           # 배포 체크리스트
│   ├── api-test.sh             # API 스모크 테스트 스크립트
│   ├── database.sql            # 데이터베이스 DDL
│   ├── alipay.png / weixinpay.png  # 후원 QR 코드
│   ├── diagrams/               # 18개 SVG 아키텍처 다이어그램（시스템 아키텍처 / 보안 파이프라인 / ER 다이어그램 / 비즈니스 프로세스 / 다중 통화 정산 등）
│   ├── test-reports/           # 테스트 리포트（PHPUnit / Rust / API / UI + 페이지 스크린샷）
│   └── superpowers/            # 설계 스펙과 구현 계획
│       ├── specs/              # 시스템 설계 스펙 문서
│       └── plans/              # Phase 0~3 단계별 구현 계획
├── scripts/                     # 운영 스크립트（push-release.sh 푸시 릴리스 규칙：버전 증분 + tag）
├── tests/k6/                    # k6 부하 테스트 스크립트（스모크/제품/동시성）
├── install.php                 # 원클릭 설치 마법사 엔트리
├── install/                    # 설치 마법사 페이지
│   └── index.php               # 마법사 웹 애플리케이션
├── install.sql                 # 통합 데이터베이스 DDL（46개 테이블）
├── .gitignore
├── README.md                   # 프로젝트 설명（중국어）
└── README_EN.md                # 프로젝트 설명（영어）
```

## 빠른 시작

### 환경 요구 사항

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### 원클릭 설치（권장）

프로젝트는 웹 설치 마법사를 제공하며, 브라우저에서 전체 구성을 완료할 수 있습니다:

```bash
# 1. 의존성 설치
cd service && composer install && cd ../admin && composer install && cd ..

# 2. 설치 마법사 시작
php install.php
# 브라우저에서 http://localhost:8888 접속

# 3. 마법사 안내에 따라 완료:
#    - 환경 검사
#    - 데이터베이스 구성（호스트、포트、DB명、사용자명、비밀번호）
#    - 백오피스 관리자 계정 설정（사용자명、비밀번호、이메일）
#    - 원클릭 설치 실행（테이블 생성 + 구성 작성）
```

설치가 완료되면 마법사가 자동으로:
- 전체 46개 데이터베이스 테이블 생성（wa_* 관리 테이블 + 접두사 없는 비즈니스 테이블）
- 슈퍼 관리자 역할과 계정 생성
- `service/.env` 및 `admin/.env` 구성 파일 생성（자동 생성된 JWT/암호화 키 포함）

### 수동 설치

```bash
cd service

# 1. 의존성 설치
composer install

# 2. 환경 변수 구성
cp .env.example .env
# .env 편집: 데이터베이스 비밀번호、JWT 키、암호화 키 등 입력
# ENCRYPTION_MASTER_KEY 생성：openssl rand -base64 32
# ENCRYPTION_KEY 생성：echo -n "$(openssl rand -base64 16)" | base64 -w0
# JWT_SECRET_KEY 생성：openssl rand -base64 32

# 3. 데이터베이스 생성 및 임포트
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cloud_platform < ../install.sql

# 4. 서비스 시작（개발 모드）
php start.php start
# http://localhost:8787 접속
```

### Docker 배포

```bash
# 프로젝트 루트에서
cp service/.env.example .env
# .env 편집: 각종 키 입력

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### 관리자 백오피스

```bash
cd admin

# 1. 의존성 설치
composer install

# 2. 환경 변수 구성
cp .env.example .env
# 원클릭 설치 마법사를 사용했다면 이 파일은 이미 자동 생성됨

# 3. 서비스 시작（개발 모드）
php start.php start
# http://localhost:8787/app/admin 접속
```

### 데몬 프로세스 모드

```bash
php start.php start -d          # 시작
php start.php status            # 상태 확인
php start.php restart           # 재시작
php start.php stop              # 중지
```

## 사용 안내

### 로그인

- **사용자**: API 서비스 주소（기본 `http://localhost:8787`）로 접속해 회원가입 후 로그인합니다. Google / Apple OAuth 및 TOTP 2단계 인증을 지원합니다
- **관리자 백오피스**: 브라우저에서 `http://localhost:8787/app/admin`을 엽니다（관리 패널은 별도 인스턴스, 포트 8788）. 설치 마법사에서 생성한 관리자 계정으로 로그인합니다

### 관리자 주요 기능

- **대시보드**: 오늘의 주문 / 매출 / 신규 사용자 / 활성 리소스 통계, 30일 매출 추이 차트, PDF 내보내기
- **보고서 센터**: 주문 보고서, 상품 랭킹, 채널 통계, 사용자 증가, Excel 내보내기
- **일상 관리**: 사용자 / 상품 / 주문 / 공급업체 / 티켓 / 도메인 / CDN 관리, KYC 심사, 환불, 정산·출금 승인
- **시스템 설정**: 결제 채널, CDN 계정, Webhook, 알림 템플릿, 도움말 문서, 감사 로그

### 클라이언트 빌드

- **Flutter 클라이언트**（`apps/flutter/`）: iOS / Android / Web / Linux / macOS / Windows 지원. `flutter pub get`으로 의존성 설치, `flutter run`으로 디버그, `flutter build apk` / `flutter build ios` / `flutter build web`으로 패키징
- **HarmonyOS 클라이언트**（`apps/harmonyos/`）: ArkTS 네이티브 앱. DevEco Studio로 `entry` 프로젝트를 열어 빌드·실행

## API 개요

인터페이스는 모듈별로 그룹화되며 요청/응답 예시와 오류 코드를 포함합니다: [API 개요](docs/api-overview.md)（선별） · [API 인터페이스 문서](docs/api-reference.md)（200+ 엔드포인트 전체 레퍼런스） · [온라인 디버깅](http://localhost:8787/apidoc)

## 관리자 백오피스 아키텍처

### 기술 통합

관리자 백오피스는 독립 webman 인스턴스로, 7개 erikwang2013 패키지를 통합합니다:

| 패키지 | 용도 | 구현 방식 |
|---|------|---------|
| snowflake-php | 64비트 분산 기본 키 | `Base::boot()` creating 이벤트에서 자동 생성 |
| hashids | API ID 난독화 | `Base::json()` 응답 인코딩, `Crud::selectInput/updateInput/deleteInput` 요청 디코딩 |
| encryptable | 데이터베이스 필드 암호화 | Eloquent `Encryptable` cast, Admin（password/email/mobile）、User（6개 필드）투명 암복호화 |
| encryption | API 전송 암호화 | 예약된 `encrypt_data()`/`decrypt_data()` 헬퍼 함수 |
| webman-scout | ES 전문 검색 | User 모델 `Searchable` trait, 자동 인덱스 동기화 |
| season | 국가 국기 emoji | `country_season_flag()` 전역 헬퍼 함수 |
| poster-php | 클릭 캡차 | `CaptchaPlugin` Bootstrap, `captcha_create()`/`captcha_verify()` 전역 함수 |

### 보안 계층

```
요청 → Hashids 디코딩 (Crud::selectInput/updateInput/deleteInput)
  → ACL 인증 (api/Auth.php, 컨트롤러 noNeedLogin/noNeedAuth)
  → 비즈니스 처리 (CRUD / 모델 이벤트)
  → Encryptable 필드 암호화 (Eloquent casts set)
  → 데이터베이스 쓰기
응답 ← Hashids 인코딩 (Base::json → hashids_encode_ids)

로그인/가입：Captcha 검증 → Auth → 비즈니스 처리
```

### 데이터 흐름

- **쓰기 경로**: 요청 ID (hashid) → int로 디코딩 → CRUD 작업 → Snowflake로 새 ID 생성 → Encryptable로 민감 필드 암호화 → DB
- **읽기 경로**: DB → Encryptable 복호화 → Hashids로 ID 인코딩 → JSON 응답

### 테스트 커버리지

```
phpunit.xml (PHPUnit 11, 286 tests / 962 assertions)
├── HashidsTest              (21 tests) encode/decode/encode_ids
├── BaseJsonTest             (13 tests) Base::json/success/fail 인코딩
├── CrudHashidsTest          (14 tests) Crud 입력 디코딩 (select/update/delete)
├── TreeTest                 (19 tests) 트리 구조 / 자손 / 조상 / 고아 노드
├── AccessControlMiddlewareTest (7 tests) 미로그인 401 / 403 페이지 / 통과
├── AdminControllersTest     (data provider) 48개 컨트롤러 조립 / CRUD 면 / GET 뷰 경로
├── UtilTest                 (17 tests) 비밀번호 / 시간 / 바이트 / 입력 필터링 / 컨트롤 속성
├── DictTest                 (5 tests) 사전명↔option 변환 / save/get/delete
├── ExcelExportTest          (4 tests) 헤더 / JSON 플래트닝 / 행 번호 / 빈 셀
└── LayuiTest                (5 tests) input / inputNumber / label 이스케이프 / switch / html
```

## 설계 철학

### 1. 모듈형 모놀리스

모듈은 비즈니스 도메인별로 수직 분할되며（User / Product / Order / Payment / Provisioning / Ticket / Notification 등）, 각 모듈 내부는 MVC 계층을 따릅니다:

- **Controller** — HTTP 계층, 파라미터 검증, Service 호출, Response 반환
- **Service** — 비즈니스 로직, HTTP 의존성 없음, Controller와 Queue Worker가 공용으로 사용 가능
- **Model** — Eloquent 데이터 모델, 관계와 쿼리 스코프 정의

모듈 간에는 **이벤트**와 **인터페이스**로 결합도를 낮추며, 상대 모듈의 Service를 직접 호출하지 않습니다. 예: 결제 완료 → `OrderPaid` 이벤트 → `ProvisioningService`가 리소스를 자동 개통, Ticket 생성 → `TicketCreated` 이벤트 → 상담원 자동 배정.

### 2. 이벤트 기반 인도

```
사용자 주문 → 결제 성공 → OrderPaid 이벤트
  → ProvisioningService.handleOrderPaid()
    → 각 OrderItem에 ProvisionTask 생성 (status=pending)
    → Redis Queue 소비자 ProvisionWorker
      → ProviderFactory.create(task)로 Provider 해석
      → ProxmoxProvider.create()
        → HostSelector로 가장 한가한 물리 머신 선택
        → ProxmoxApi로 VM 생성 / 디스크 마운트 / IP 할당
          （Rust kvm-server gRPC 공급 서비스가 저장소에 반영됨：e-cat/etcd 등록 디스커버리,
           PHP 측 KvmClient 연동；시뮬레이션 드라이버, libvirt 실제 드라이버는 Phase 2）
        → Resource / Disk 레코드 생성
      → Order 상태를 completed로 업데이트
```

인도 실패 시 자동 재시도하며, 백오프 전략: 1min → 5min → 15min → 1h → 6h → 24h, 6회 초과 시 실패로 표시하고 알람을 트리거합니다.

### 3. Provider 플러그인 아키텍처

리소스 인도는 `ProviderInterface`로 추상화되어, 서로 다른 인프라가 동일한 인터페이스를 구현합니다:

```
ProviderInterface
  ├── ProxmoxProvider    (자체 운영 Proxmox VE)
  ├── AliyunProvider     (향후：Alibaba Cloud)
  ├── AwsProvider        (향후：AWS EC2)
  └── DomainProvider     (향후：도메인 레지스트라)
```

`ProviderFactory`는 `productType:provider` 키로 팩토리 함수를 등록하고, 런타임에 ProvisionTask에 따라 동적으로 해석합니다.

### 4. 다중 결제 라우팅

`PaymentRouter`가 주문 금액 / 통화 / 지역에 따라 사용 가능한 결제 채널을 동적으로 반환하며, 프런트엔드는 채널을 전환하여 결제를 시작할 수 있습니다. 결제 채널은 `PaymentChannel` 테이블로 구성됩니다（수수료율、최소/최대 금액、노출 지역）, 코드 수정 없이 상/하선할 수 있습니다.

### 5. 보안 아키텍처

전역 미들웨어 체인: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [라우트: Encryption → Captcha → Auth → Confirmation]`

![보안 미들웨어 파이프라인](docs/diagrams/security-middleware-zh.svg)

- **CORS** — 크로스 도메인 요청 헤더 처리（화이트리스트 모드, *.example.com 와일드카드 지원）
- **SecurityHeaders** — 보안 응답 헤더（HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy）
- **GeoBlock** — 지리 차단（GEO_BLOCKED_COUNTRIES에 따라 지정 국가 접근 차단, GeoIP2 기반）
- **WAF** — 8개 카테고리 45+ 규칙（SQL 주입/XSS/명령 주입/파일 포함/헤더 주입/SSRF/NoSQL 주입/오픈 리다이렉트）+ 요청 크기 제한 + Content-Type 검증（값 주입은 query/body/UA 스캔, path는 경로 트래버설만 검사）
- **Security Plugin** — 31종 공격 탐지（XSS/SQL 주입/명령 주입/SSRF/역직렬화/JWT 공격/Host 헤더 공격/요청 스머글링/GraphQL 주입/민감 데이터 유출 등）, IP 화이트리스트 + IP 블랙리스트 자동 차단
- **Locale** — Accept-Language 해석, 다국어 설정
- **HashidRequest** — 요청 내 hashid 문자열을 실제 정수 ID로 자동 디코딩
- **Version** — `X-Api-Version` 요청 헤더 검증, 누락 시 기본 `v1`, 지원하지 않는 버전은 `400` 반환
- **ClientPlatform** — `X-Client-Platform` 요청 헤더 검증, 클라이언트 OS 플랫폼 인식（iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web）
- **Encryption** — AES-256-GCM 전송 암호화（인증 인터페이스 및 관리자 백오피스）, 중간자 도청·변조 방지
- **Captcha** — 클릭 캡차, 로그인/가입 전 검증（GD 그리기 + Redis 저장, 일회용 키, 300s 유효, 3회 시도 제한）
- **Auth** — JWT HS256 인증, Access Token 15분, Refresh Token 30일, Redis 블랙리스트
- **Confirmation** — 민감 작업（결제/삭제/환불/승인 등）은 비밀번호 재입력 검증 필요, 5회 실패 시 15분 잠금
- **빈도 제한** — 기본 60회/분, 로그인 5회/분, 가입 3회/분, 결제 10회/분
- **감사 로그** — 모든 민감 작업은 독립 감사 DB에 기록

### 6. 데이터 보안

**계층형 암호화 전략:**

| 계층 | 기술 | 설명 |
|------|------|------|
| 전송 계층 | AES-256-GCM | API 요청/응답 본문 암호화, GCM 인증 암호화로 변조 방지 |
| 필드 계층 | AES-256-CBC | 모델 민감 필드 자동 암복호화, CBC 랜덤 IV로 값 패턴 유출 방지 |
| 기본 키 계층 | Hashids | 외부 ID를 12자리 문자열로 난독화, 실제 데이터 규모 은닉 |

**민감 필드 암호화:** 7개 모델의 14개 필드가 `Encryptable::class`로 자동 암복호화됩니다 —— `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**키 관리:** 전송 암호화와 필드 암호화는 서로 다른 독립 키를 사용하며（`ENCRYPTION_MASTER_KEY` vs `ENCRYPTION_KEY`）, 이전 키 목록（`ENCRYPTION_PREVIOUS_KEYS`）을 지원하여 무중단 키 로테이션이 가능합니다.

### 7. 분산 ID 생성

Twitter Snowflake 알고리즘으로 64비트 전역 고유 ID를 생성합니다: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. 모든 46개 Eloquent 모델은 `creating` 이벤트에서 스노우플레이크 ID를 자동 생성하며, DB 자동 증가에 의존하지 않고 샤딩/파티셔닝을 자연스럽게 지원합니다.

### 8. 다국어（i18n）

**전역 미들웨어 자동 해석:**
- `LocaleMiddleware`가 `Accept-Language` 요청 헤더를 읽어 현재 언어 자동 설정
- 언어 폴백 지원: 지원하지 않는 언어 → `fallback_locale` (en-US)

**정적 텍스트 번역:**
- `I18n::trans('auth.login_success')` → `登录成功` / `Login successful`
- 번역 파일: `i18n/{locale}/messages.php`, 120개 항목이 전체 15개 모듈 커버
- 파라미터 치환 지원: `I18n::trans('validation.required', ['field' => '邮箱'])`

**JSON 다국어 필드:**
- 제품명 / 설명이 `{"zh-CN":"云服务器","en-US":"Cloud Server"}`로 저장
- `I18n::translateField($json)`가 현재 언어에 따라 자동 값 조회
- 알림 템플릿도 다국어 지원, 사용자 선호 언어로 푸시

### 9. 전문 검색

제품, 사용자, 주문, 티켓 4개 모델이 `Erikwang2013\WebmanScout\Searchable` Trait으로 검색에 연동됩니다. 드라이버 기본값은 `database`（쓰기는 no-op, 검색은 SQL LIKE로 폴백, ES 의존성 없음）；Elasticsearch 드라이버 구성 시 인덱스를 자동 동기화하며 지원:

- **다국어 토큰화** — IK Analyzer（ik_max_word / ik_smart）
- **중국어 전문 검색** — 제품명, 설명, 티켓 제목
- **정밀 필터링** — 상태, 카테고리, 가격 구간, 시간 범위 필터
- **일괄 동기화** — `php webman scout:import "App\Product\Model\Product"`
- **검색 예시** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. 국가 국기

`erikwang2013/season`으로 전 지역 국가 국기 emoji를 지원합니다:

- `country_season_flag('CN')` → 🇨🇳, `country_season_flag('JP')` → 🇯🇵
- 남북반구 자동 인식, 해당 계절 반환（중/영）
- 30+ 언어의 현지화 계절 이름 지원
- 프런트엔드 지역 선택, 사용자 국적 표시 등 시나리오에서 바로 호출 가능

## 할 일 목록

- [x] 데이터베이스 DDL（`install.sql`, 46개 테이블, wa_* 관리 테이블 + 접두사 없는 비즈니스 테이블, BigInt 비자동 증가 기본 키）
- [x] 스노우플레이크 ID 생성（`erikwang2013/snowflake-php`）
- [x] JWT 인증（`erikwang2013/jwt-webman`, HS256 + Redis 블랙리스트）
- [x] API ID 난독화（`erikwang2013/hashids`, 요청 자동 디코딩 + 응답 자동 인코딩）
- [x] 전송 암호화（`erikwang2013/encryption`, AES-256-GCM 미들웨어）
- [x] 필드 수준 암호화（`erikwang2013/encryptable`, 민감 필드 자동 암복호화）
- [x] 전문 검색（`erikwang2013/webman-scout`, 기본 database 드라이버 SQL LIKE 폴백, 선택적 Elasticsearch + IK 토큰화）
- [x] 국가 국기（`erikwang2013/season`, Unicode flag emoji）
- [x] 관리자 백오피스（`admin/`, webman-admin + 7개 패키지 통합, 286개 단위 테스트）
- [x] 코드 리뷰（중요 수정 2건 + 중요 수정 4건 적용）
- [x] Excel 내보내기（PhpSpreadsheet ^2.0, 관리자 백오피스 Crud/Table + 서버 측 관리 API）
- [x] 대시보드 시각화（ECharts 차트 + 애니메이션 통계 카드 + 시스템 정보 패널）
- [x] PDF 내보내기（html2canvas + jsPDF, 대시보드 스크린샷 내보내기）
- [x] 데이터베이스 마이그레이션 스크립트（`install.sql` 통합 DDL, `php webman migrate` 명령화）
- [x] Stripe 실제 통합（stripe-php SDK, PaymentIntent + Webhook 서명 검증）
- [x] Twilio SMS 실제 통합（twilio/sdk, 전송 실패 처리 포함）
- [x] FCM 푸시 실제 통합（kreait/firebase-php, 유효하지 않은 token 정리 포함）
- [x] 클릭 캡차（erikwang2013/poster-php, 로그인/가입 민감 작업 검증）
- [x] 2차 확인（ConfirmationMiddleware, 민감 작업 비밀번호 재검증, 5회 실패 시 15분 잠금）
- [x] 서버 측 단위 테스트（672 tests / 1632 assertions, 15 skipped）
- [x] 클라이언트 플랫폼 인식（ClientPlatformMiddleware, X-Client-Platform 헤더로 8개 플랫폼 지원）
- [x] WAF 보안 강화（8개 카테고리 45+ 규칙: SQL 주입/XSS/명령 주입/파일 포함/헤더 주입/SSRF/NoSQL 주입/오픈 리다이렉트 + 요청 크기 제한 + Content-Type 검증）
- [x] Security Plugin（erikwang2013/security-php, 31종 공격 탐지 + IP 블랙리스트 자동 차단 + 로그 로테이션）
- [x] Admin 패널 WAF 미들웨어
- [x] MySQL 읽기/쓰기 분리（Eloquent read/write 연결 + sticky）
- [x] Redis 다단계 캐시 계층（CacheService：제품/지역/환율/TLD/사용자, TTL + 능동 무효화 + 워밍업）
- [x] Nginx 응답 압축 + 연결 최적화（gzip/proxy_buffering/keep-alive/limit_req+limit_conn）
- [x] 데이터베이스 인덱스 제안（13개 권장 복합/커버링 인덱스）
- [x] Sentry 예외 모니터링（SentryBootstrap + before_send 비식별화 콜백）
- [x] Feature Flags 기능 스위치（Redis 동적 오버라이드 + 관리자 백오피스 API）
- [x] 공급업체 외부 API（API Key 인증 + 주문/리소스/정산/출금 엔드포인트）
- [x] WebSocket 실시간 푸시（Workerman 네이티브 WebSocket + 주문/티켓 이벤트 리스너）
- [x] k6 부하 테스트 스크립트（스모크/제품/동시성 부하 테스트）
- [x] CI/CD 파이프라인（GitHub Actions, 문법 검사 + 양단 PHPUnit + Composer 검증）
- [x] 원클릭 설치 마법사（Web UI, 환경 검사 + 데이터베이스 구성 + 관리자 생성 + .env 자동 생성）

## 오픈소스 유지도 쉽지 않습니다, 응원 부탁드립니다

| 위챗 | 알리페이 |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

### 글로벌 송금（은행 송금）

**수취인 정보**

- 수취인 이름：WANG KEXUN
- 수취 계좌 번호：881015918251

**수취 은행（ZA Bank）**

- SWIFT Code：AABLHKHHXXX
- 은행 이름：ZA Bank Limited
- 은행 코드：387
- 은행 주소：Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**해외 송금 중개 은행（필요 시）**

> 참고: 이 정보는 해외 송금 중개 은행（중간 은행）정보이며, 수취 은행 정보가 아닙니다. 송금 은행에 해외 송금 중개 은행 정보가 필요한지 문의하시기 바랍니다.

- 홍콩 달러, 위안화 및 미국 달러 입금 시 중개 은행은 **Citibank**:
  - 은행 이름：Citibank N.A. Hong Kong
  - SWIFT Code：CITIHKHXXXX
  - 은행 코드：006
  - 지점 이름：Hong Kong Branch
  - 지점 코드：391
  - 은행 주소：Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- 기타 통화 입금 시 중개 은행은 **BNY Mellon**:
  - 은행 이름：THE BANK OF NEW YORK MELLON
  - SWIFT Code：IRVTUS3NXXX
  - 은행 주소：THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### 암호화폐 후원 (Crypto Donation)

이 프로젝트가 도움이 되셨다면, QR 코드를 스캔하여 후원해 주세요. 감사합니다!

| <img src="../../coin/1.jpg" width="200" alt="BNB Smart Chain (BEP20)"><br>**BNB Smart Chain (BEP20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/2.jpg" width="200" alt="Tron (TRC20)"><br>**Tron (TRC20)**<br>`TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| <img src="../../coin/3.jpg" width="200" alt="Ethereum (ERC20)"><br>**Ethereum (ERC20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/4.jpg" width="200" alt="Aptos"><br>**Aptos**<br>`0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| <img src="../../coin/5.jpg" width="200" alt="Plasma"><br>**Plasma**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/6.jpg" width="200" alt="Polygon POS"><br>**Polygon POS**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |
| <img src="../../coin/7.jpg" width="200" alt="Solana"><br>**Solana**<br>`2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` | <img src="../../coin/8.jpg" width="200" alt="The Open Network (TON)"><br>**The Open Network (TON)**<br>`UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| <img src="../../coin/9.jpg" width="200" alt="Arbitrum One"><br>**Arbitrum One**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/10.jpg" width="200" alt="AVAX C-Chain"><br>**AVAX C-Chain**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## License

라이트 버전 — MIT License | 스탠다드/프로 버전 — Proprietary
