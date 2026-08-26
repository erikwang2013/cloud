# CloudPlatform 리뷰 리포트（3라운드, 2026-08-06）

> 범위: 전체 실측（서비스 시작 + 스모크 테스트）+ 심층 코드 점검 + 생태/보안 구성 완전성 대조.
> 이번 라운드는 "정적 가독"에서 "**실행 가능**"으로 전진: 시작 레벨 P0 5곳과 실행 레벨 P0/P1 3곳 수정, 서비스가 완전한 미들웨어 체인에서 스모크 통과.
> 테스트 기준: service **316/316 통과（502 assertions）**; admin **67/67 통과（124 assertions）**.

---

## 1. 이번 라운드 수정 체크리스트（전부 실측 검증 완료）
### P0 — 시작 레벨（worker 크래시 / 전 사이트 불가）

| # | 이슈 | 근본 원인 | 수정 |
|---|------|------|------|
| 1 | `A facade root has not been set` → 시작 크래시 | bootstrap이 Illuminate Facade에 컨테이너를 설정하지 않음 | `Facade::setFacadeApplication($capsule->getContainer())`（bootstrap.php:149） |
| 2 | `Target class [events] does not exist` | 이벤트 리스너가 Event Facade를 사용하지만 컨테이너에 events 서비스 없음 | `Dispatcher` 인스턴스로 변경: `$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()`（리스너 3개） |
| 3 | `Class support\SentryBootstrap not found` | composer.json psr-4에 `support\` 매핑 누락 | `"support\\": "support/"` 추가 + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` 공백 → 암호화 서비스 크래시 | .env 빈 값（phpdotenv createUnsafeMutable 주입 덮어씀） | 32바이트 base64 키 생성해 .env에 기록 |
| 5 | `/api/*` 라우트 전부 404 | `ApiRequest::path()`가 `/api/xxx`를 `/api/v1/xxx`로 재작성하는데 라우트 등록에 버전 접두사 없음 | 재작성 로직 제거, 경로 원래대로（버전 검증은 VersionMiddleware가 X-Api-Version 헤더 기준） |
| 6 | `Class "ErikJwt\JWTFactory" not found` | 존재하지 않는 `ErikJwt\` 네임스페이스 사용 | 패키지 실제 네임스페이스 `Erikwang2013\Jwt\*`로 변경 |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` null 반환 → `createFromConfig()` 타입 오류 | webman `Config::loadFromDir`는 플러그인 디렉터리에 `app.php`가 있어야 함（없으면 디렉터리 통째 스킵）; jwt 플러그인 디렉터리 누락 | `config/plugin/erikwang2013/jwt/app.php` 보완（`'enable' => true`, vendor 템플릿과 일치） |

### P0 — 실행 레벨（첫 요청에 500）

| # | 이슈 | 근본 원인 | 수정 |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware가 ext-redis `\Redis::get()`을 직접 정적 호출 | `\support\Redis::get/setex/incr`로 변경 |
| 9 | `Class support\Redis not found` | `support\Redis`는 webman 스켈레톤 계층（webman/webman 패키지）, 본 프로젝트는 framework만 설치해 누락 | `support/Redis.php` 신규 생성（기존 illuminate/redis + config/redis.php 사용） |
| 10 | AuthController의 `Illuminate\Support\Facades\Redis::*`가 **원시 phpredis 인스턴스**로 해석（미연결）→ "server went away" | 컨테이너에 `redis` 바인딩 없음, 자동 와이어링이 `Redis` 클래스로 폴백 | bootstrap에서 `$container->singleton('redis', fn() => support\Redis::manager())` 등록 |
| 11 | `Call to undefined function storage_path()` | `storage_path()`는 스켈레톤 helpers, 본 프로젝트에 없음 | bootstrap에서 helper 보완（`base_path()/storage`, function_exists 가드） |

### P1 — 경계 검증

| # | 이슈 | 수정 |
|---|------|------|
| 12 | `/api/auth/refresh`에 refresh_token 없을 때 TypeError 500 | AuthController::refresh에 `is_string` 검증 추가 → 422 |

### 임시 상태 복구

- `config/server.php`（8787）、`config/process.php`（9100/8282）、`config/middleware.php`（완전한 11계층 체인）git에서 원상 복구됨
- bootstrap.php의 `[AUDIT]` 디버그 error_log 제거됨

---

## 2. 스모크 테스트 결과（완전한 미들웨어 체인, 포트 8787）
| 엔드포인트 | 결과 | 설명 |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | 클릭 캡차 이미지 반환 |
| POST /api/auth/login（캡차 없음） | 422 | captcha 검증 동작 |
| POST /api/auth/register（빈 파라미터） | 422 | 필드 검증 동작 |
| POST /api/auth/refresh（token 없음） | 422 | 이번 라운드 수정 항목 |
| POST /api/auth/forgot-password | 500（DB 연결 거부） | **환경 갭**: .env에 DB_PASSWORD 없음, §四 참조 |
| GET에 X-Api-Version: v99 | 400 | VersionMiddleware 동작 |
| GET /api/nonexistent | 404 | 정상 404 페이지 |

Redis 경로（캡차, 빈도 제한, JWT 블랙리스트 저장）전부 실측 사용 가능.

---

## 3. 보안 방어 대조
### 달성 ✓

- **키 관리**: 전 프로젝트에 하드코딩 키/패스워드 없음（grep 스캔）; 키 전부 `getenv()`; .env는 gitignore됨
- **SQL 주입**: 문자열 결합 SQL 없음; 전부 Eloquent 쿼리 빌더
- **입력 검증**: 업로드 type 화이트리스트 + finfo 내용 스니핑 + 유형별 크기 상한; auth 엔드포인트 필드 레벨 검증
- **빈도 제한**: 공개 민감 엔드포인트 전면 커버（login 5/min, register 3/min, sms 5/h, captcha 30/60s, oauth 10/60s, password_reset 3/5min）, default 60/min
- **JWT**: HS256 + 32바이트 키; access/refresh 분리; type 검증; Redis 블랙리스트（라이브러리 내 jti 검증）; TOTP 강제 + 실패 잠금
- **CORS**: Origin 화이트리스트（`CORS_ALLOWED_ORIGINS`）, 와일드카드·자격증명 헤더 없음
- **보안 헤더**: nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS（env 스위치）
- **열거 방지**: forgot-password가 없는 사용자에게도 동일한 성공 메시지 반환

### 권장（저우선순위, 미변경）

| 항목 | 설명 |
|----|------|
| CSP 헤더 부재 | 전 사이트에 Content-Security-Policy 미구성; API JSON 시나리오 위험 낮음, SecurityHeadersMiddleware에 `default-src 'none'` 레벨 정책 보완 권장 |
| WAF 성능 | WafMiddleware가 매 요청마다 `file_get_contents('php://input')`으로 전체 body 스캔（31종 패턴）, 고트래픽에서 메모리/CPU 부하; POST/PUT이면서 Content-Type이 일치할 때만 body를 읽도록 권장 |
| HealthController `shell_exec('git rev-parse')` | health 요청마다 자식 프로세스 생성; 프로덕션은 `APP_VERSION` env만 사용 권장, shell은 로컬 개발 폴백만 |
| ~~RateLimit TOCTOU~~ | ~~check-then-set 비원자~~ **수정됨（2026-08-07）:** 원자 `INCR` + 최초 `EXPIRE`로 변경, §七-6 참조 |
| X-XSS-Protection | 폐기된 헤더, 유지해도 무해; CSP 도입 후 제거 가능 |

---

## 4. 환경 갭（코드 문제 아님, 운영 보완 필요）
1. **`.env`에 `DB_PASSWORD` 누락**（유일한 차단 항목）: docker-compose가 `${DB_PASSWORD}`로 app_user를 생성하지만 로컬 .env에 해당 키 없음 → 모든 DB 엔드포인트 500. `DB_PASSWORD`는 `.env.example`에 정의되어 있으며 배포 자격증명이므로 사용자가 `.env`에 넣어야 함.
2. **9100이 로컬 dart 프로세스에 점유**: metrics 프로세스 기본 포트 바인딩 실패 시 **전체 그룹 시작을 막음**（webman 시작 전 전체 포트 사전 검사）. 영속 우회 적용: `.env`에 `METRICS_PORT=9199` 기록（2026-08-07）. dart가 9100을 놓아주면 기본값으로 복귀 가능.
3. **composer validate fatal**（제3자）: `erikwang2013/security-php`의 composer 플러그인이 composer 자체 eval과 충돌（`isLaravel()` 중복 선언）, 본 프로젝트 코드와 무관; CI의 `composer validate --strict` 단계가 이 때문에 실패할 수 있으므로 해당 단계에 continue-on-error 추가 또는 service 패키지 제외 권장.
4. 이전 라운드에 기록된 8787 erp-php 점유는 해소됨（이번 라운드 실측 바인딩 가능）.

---

## 5. 생태 구성 대조
| 항목 | 결과 |
|----|------|
| CI（.github/workflows/ci.yml） | 완전: PHP 문법 검사 + admin/service 테스트（PHP 8.2/8.3 매트릭스）+ composer validate |
| 마이그레이션 | migration 파일 30개 |
| Docker | compose（MySQL+Redis+app）、Dockerfile、nginx.conf、prometheus、grafana、supervisor（nginx+webman） |
| 모니터링 | MetricsServer（Prometheus 독립 포트）+ websocket 프로세스（process.php） |
| 부하 테스트 | tests/k6（smoke/products/concurrent） |
| .env.example | 키가 .env보다 더 완전（OAuth/Feature 스위치 등 전부 커버）; .env에 초집합 키 없음 |
| composer audit | 보안 취약점 없음; 폐기 패키지 1개 doctrine/annotations（hg/apidoc 의존, 유지로 평가） |
| 큐/비동기 | webman/redis-queue 설치됨; 알림은 NotificationDispatcher 경유 |

---

## 6. 잔여 권장（후속 이터레이션）
1. **CSP 헤더**（§三 참조）
2. **WAF body 읽기 최적화**（§三 참조）
3. **DB_PASSWORD 보완 후 DB 전체 체인 재테스트**（register→login→refresh→logout 실제 흐름 + JWT 블랙리스트 무효화 검증）
4. ~~**supervisor에 cron 프로세스 없음**: Billing\Cron\SuspendCheck 등 예약 작업에 데몬 엔트리 없음~~ **해결됨（2026-08-07）:** `App\Cron\CronRunner` 프로세스 신설（매분 config/cron.php 5필드 표현식 평가）, `queue_consumer` 프로세스를 등록해 provisioning/notification 큐 소비; cron.php에서 스크립트 파일을 가리키던 무효 등록 2개를 `ResourceMonitor` 호출 가능 메서드로 변경
5. **CI composer-validate 단계**: 제3자 플러그인 충돌로 인해 내결함성 추가 권장（§四-3 참조）

---

## 7. 4라운드 보충 수정（2026-08-07）
1. **과금 원자성（P0 재무）**: `BillingEngine::runDaily()`가 리소스 단위로 트랜잭션 래핑, 차감/정지/이벤트 표시를 같은 트랜잭션으로 커밋; `StripeChannel::confirmPayment()`가 `UPDATE ... WHERE status='pending'` 원자 선점 + 주문 행 잠금으로 webhook 중복 입금 방지.
2. **동시성 멱등（P0/P1）**: `AffiliateService::requestPayout()` 행 잠금 + 기존 pending 출금은 바로 반환; `SupplierSettlement`（cron과 `generateSettlement`）가 공급업체+기간 기준 중복 판정.
3. **데이터 정확성（P1）**: `MeterCollector`가 `$resource->first()`의 의도치 않은 전체 테이블 쿼리 수정; `ExchangeRateSync`에 10s 타임아웃 추가.
4. **성능（P2）**: Dashboard 30회 SUM 쿼리를 단일 GROUP BY로 병합; `CacheService::forgetPattern()` KEYS→SCAN 커서; `I18n` 언어 팩을 locale별 프로세스 내 캐시; `ImportExport` 임포트를 전체 트랜잭션으로; `BillingEngine` 요금 매핑 프리페치로 N+1 제거.
5. **보안（P1）**: `InternalTokenMiddleware`가 `getRemoteIp()`로 XFF 위조 방지; Webhook 등록이 사설망 주소 거부（SSRF）; `JwtAuth` 빈 키 fail-fast; `DbBackupCommand` 비밀번호를 `MYSQL_PWD`로 변경해 `ps` 유출 방지; CSV/Excel 내보내기 수식 주입 방지; 공급업체 외부 API에 `supplier_api` 빈도 제한 마운트.
6. **인프라（P2）**: `RateLimitMiddleware` 원자 INCR（TOCTOU 제거）; `MetricsServer`의 `onMessage` 타입 크래시 루프 수정; `HealthController` Redis 커넥션 풀링; `symfony/mailer ^6.4` 추가 설치（EmailSender가 원래 숨은 뇌관）; admin 측 `EncryptableBootstrap` 네임스페이스 수정.

---

## 8. 5라운드 보충 수정（2026-08-07）
1. **자동 인도 연결（P0）**: `ProvisioningService::handleOrderPaid`가 인도 작업 생성 후 `provisioning` 큐에 투입; `process.php`에 `queue_consumer` 프로세스 등록（app/ 아래 모든 `Webman\RedisQueue\Consumer` 구현 스캔）.
2. **예약 작업 실행 가능（P0）**: `App\Cron\CronRunner` 프로세스 신설（매분 config/cron.php 5필드 표현식 평가, `*/n`/`,`/`-` 문법 지원）; cron.php에서 스크립트 파일（클래스 아님）을 가리키던 무효 등록 2개를 `ResourceMonitor::collectAllMetrics`/`checkSslCertificates` 호출 가능 메서드로 변경, ExpirationCheck와 중복되던 checkExpirations 등록 제거.
3. **알림 클래스 부재（P0）**: AuthService/AuthController/ExpirationCheck의 `\Common\Notification\NotificationDispatcher::send()`（클래스 없음）4곳을 통일해 `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)`로 변경.
4. **테이블명 3체계 통일（P0）**: install.sql의 `erik_*` 비즈니스 테이블 39장을 접두사 없이 변경（Eloquent 기본 명명, migrations와 일치）, `wa_*` 관리 테이블은 유지; 설치 마법사（install/index.php）를「.env 작성 → 자식 프로세스로 service migrations 보충 실행（30개 마이그레이션 파일）→ install.sql（IF NOT EXISTS로 이미 생성된 테이블 스킵）」로 변경, 설치 후 DB 테이블 완전.
5. **P1/P2 그룹（서브 에이전트 완료, 316 테스트 검증 통과）**: 이벤트 연결, 환율 통화별 기록, `Response::error` 단일 파라미터에 400 보완（10곳）, 환불 실행기（RefundService 신설）, 승인 멱등, admin 민감 작업 감사, noNeedAuth 제거, 관리 API 빈도 제한, WebSocket을 Redis Pub/Sub으로 변경, SSL 조회 버그, 통화/미납, 자격증명 비식별화, 쿠폰 적용, 수량 검증, CI 내결함성, ES_HOST 투과.

**테스트 기준**: service 316/316（502 assertions）、admin 67/67（124 assertions）전부 그린; 변경 파일 전체 `php -l` 통과.

## 결론

이번 라운드는 "코드 가독"에서 "**시작 가능, 실행 가능**"으로 전진: P0 레벨 장애 8곳을 모두 수정하고 실측했으며, 테스트 316개 전부 그린, 완전한 미들웨어 체인 스모크 통과. 남은 차단 항목은 환경 갭 하나（DB_PASSWORD）뿐이며, 보완하면 전 체인 검증이 가능합니다. 4라운드（2026-08-07）에서 과금 원자성, 동시성 멱등, 빈도 제한/주입 방어 등 20+ 항목 강화를 추가로 완료했고; 5라운드（2026-08-07）에서 자동 인도, cron 스케줄링, 알림 클래스, 테이블명 체계 등 P0 4개와 P1/P2 그룹을 전부 수정했으며 테스트는 그린을 유지합니다.
