# CloudPlatform 전체 리뷰 리포트

**날짜**: 2026-08-06
**리뷰 범위**: service 전량（app / common / config / tests）+ 생태 구성 + 보안 방어
**방법**: PHPUnit 테스트 스위트, 전체 PHP 문법 검사, 라우트/미들웨어 감사, OAuth 신기능 코드 리뷰, 환경 변수와 구성 일치성 대조, 의존성 보안 감사, 스모크 테스트

---

## 1. 총체적 결론
| 차원 | 결론 |
|------|------|
| 테스트 | **314항목 전부 통과**（버그 2개 수정 후, 494 assertions） |
| 문법 | PHP 파일 287개 0 문법 오류 |
| 의존성 보안 | composer audit 알려진 취약점 없음; 폐기 패키지 1개（doctrine/annotations） |
| 보안 아키텍처 | 다계층 방어 완비（WAF 이중 엔진, CORS 화이트리스트, 전송 암호화, 필드 암호화, bcrypt cost=12, JWT 블랙리스트, 감사 로그） |
| 심각 이슈 | **P0 1개（Apple id_token 미검증 → 계정 탈취 가능）、P1 4개** |
| 생태 구성 | **.env.example에 사용 중 변수 31개 누락**, OAuth 자격증명 전부 포함; 알림 채널은 플레이스홀더 구현 |

---

## 2. 테스트 결과
```
OK (314 tests, 494 assertions)
```

### 이번에 수정한 버그 2개

| ID | 파일 | 이슈 | 수정 |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | `$result['extra']['targets']`를 읽지만 라이브러리는 `extra.texts` 반환 → `target_count`가 항상 0 | `extra.texts`로 변경 |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | 라이브러리 기본 `targetCount = 5`, 자체 README 계약(medium=3 목표)과 모순 → Captcha 테스트 3개 실패 | 기본값 5 → 3 |

> B2는 vendored 라이브러리 버그입니다（vendor/가 git 추적 중이라 수정이 유지됨）. 상류 저장소에 수정 제출도 권장.

---

## 3. 심각한 보안 이슈（P0 / P1）
### P0-1. Apple `id_token` 미검증 —— 계정 탈취 가능
**파일**: `service/app/user/service/OAuthService.php:180-192`（`appleProfile()`）

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // base64 디코딩만, 서명/iss/aud/exp 검증 없음
```

공격자는 자체적으로 `id_token`을 구성해 임의 email로 OAuth 로그인을 위조할 수 있습니다. `resolveUser()`는 email로 기존 사용자를 매칭해 바로 토큰을 발급합니다 → **임의 계정 탈취**.

**수정**: Apple JWKS（`https://appleid.apple.com/auth/keys`）+ `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])`로 서명 검증, `iss=appleid.apple.com`、`aud=client_id`、`exp`、`nonce` 검증.

### P1-1. OAuth 로그인이 `email_verified` 미검증
**파일**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn 모두 `email_verified` 필드를 반환하지만 코드가 완전히 무시합니다. 제공업체에서 이메일 미인증 사용자가 해당 이메일로 기존 계정을 직접 바인딩/탈취할 수 있습니다. GitHub 경로는 `verified`를 검증（정상）, 나머지 제공업체는 통일 검증 필요.

### P1-2. 빈도 제한 미들웨어가 존재하지만 한 번도 마운트되지 않음 —— 문서와 구현 불일치
**파일**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php`에 login=5/min、register=3/min 등 제한 규칙이 이미 구성됨
- `RateLimitMiddleware`가 **어떤 라우트에서도 참조되지 않음**（전체 grep이 클래스 자체만 적중）
- `docs/features.md`가 로그인"제한 5 req/min"、가입"제한 3 req/min" 주장 —— 실제로는 없음
- 이전 리뷰 리포트（`security-audit-2026-08-04.md`）에서 이 항목을 OK로 표시, 구성만 보고 마운트를 검증하지 않은 것이며 이번에 시정

**영향**: 로그인/가입/비밀번호 찾기/재설정/복구 코드/캡차 등 공개 엔드포인트가 무제한 브루트포스 가능（로그인은 per-account 잠금뿐, 크레덴셜 스터핑과 IP 레벨 브루트포스 방어 안 됨）.

**수정**: `RateLimitMiddleware`를 `/api/auth/*`、`/api/captcha/*` 등 공개 라우트에 마운트（전역 `''` 그룹에 마운트해 `route` 파라미터로 구분 가능）.

### P1-3. TOTP 2FA가 로그인 흐름에서 강제되지 않음
**파일**: `AuthService.php:64-97`（`login()`）+ `AuthController.php` + `config/features.php`

`user->totp_enabled`는 `totpVerify/totpDisable/totpRecoveryCodes`에서만 확인되고, **`login()`은 절대 검증하지 않습니다**. 2FA를 켠 사용자도 비밀번호만으로 유효한 access token을 얻습니다 —— 2FA가 무용지물（`FEATURE_TOTP` 기본 켜짐）.

**수정**: 로그인 시 `totp_enabled`면 임시 토큰을 발급하고 TOTP 검증 통과 후 정식 토큰으로 교환（또는 totp code 파라미터 요구）.

### P1-4. 알림 채널이 플레이스홀더 구현 —— 이메일 인증/비밀번호 재설정이 프로덕션에서 사용 불가
**파일**: `app/Notification/Queue/EmailSender.php`、`SmsSender.php`、`PushSender.php`

세 소비자 모두 `error_log()`로만 발송을 흉내내고 `send_status`를 `sent`로 기록합니다. 결과:
- **비밀번호 찾기 흐름 단절**: `AuthController::forgotPassword()`가 검증 코드를 생성하고 이메일을 "발송"하지만 실제로 도착하지 않음 → 사용자가 셀프 재설정 불가
- 가입 이메일 인증, 새 IP 로그인 경보도 동일하게 무효
- `.env.example`의 `SMTP_*`/`MAIL_FROM_*` 7개 변수는 어떤 코드에서도 읽지 않음（죽은 구성）

**수정**: 실제 메일 발송 연동（PHPMailer/SendGrid SDK）, 오도하는 `sent` 상태 표시 제거; 또는 미완성 기능으로 명시하고 문서에서 관련 약속 제거.

---

## 4. 보안 이슈（P2）
| ID | 파일 | 이슈 |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | `type` 파라미터가 화이트리스트 검증 없이 경로 `uploads/{$type}/...`에 결합 → **경로 트래버설**로 업로드 디렉터리 밖 작성 가능（파일명 랜덤, 덮어쓰기 불가지만 파일시스템 오염 가능）; type을 열거형 화이트리스트로 제한하고 스토리지 디렉터리에 `index.php`/`.htaccess` 방어 권장 |
| P2-2 | 상동 | 확장자만 검증, MIME 내용 스니핑 없음（polyglot 파일이 캐시/포워딩에 악용될 수 있음）; `finfo`로 실제 MIME 검증 권장 |
| P2-3 | `AuthController.php:131-158` | 비밀번호 재설정 6자리 코드 600s 유효, 시도 횟수 제한 없음 → 10분 안에 100만 조합 브루트포스 가능; `forgotPassword` 빈도 제한 없음 → 메일 폭탄 |
| P2-4 | `AuthController.php:333-348` | `totpRecoveryCodes` 생성/조회가 로그인만으로 가능, 비밀번호 확인 불필요; `ConfirmationMiddleware` 마운트 필요 |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | 블랙리스트 수동 검사 키가 `jwt_blacklist:{sha256(token)}`, 라이브러리의 `jwt_blacklist:{jti}` 형식과 불일치 → 죽은 코드（실제 방어는 라이브러리 내 `decode()`가 수행, 유효하지만 중복）; 삭제하거나 라이브러리 인터페이스 사용 권장 |
| P2-6 | `OAuthService.php:67-94` | `authorizeUrl`의 `redirect` 파라미터가 state에 저장된 후 한 번도 사용 안 됨（죽은 파라미터）; state가 provider에 바인딩되지 않음; OAuth 전체 흐름에 nonce 없음（OIDC 제공업체, 방어 심층성 부재, P0-1과 함께 수정） |
| P2-7 | `OAuthService.php:31-37, 236-238` | X (Twitter) v2 API `userinfo`가 email 미반환 → X 로그인이 반드시 "Email not provided"로 실패, 기능 결함, 문서화하거나 `/2/email` 엔드포인트로 변경 필요 |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint`가 `strrpos($ip, '.')`로 IPv4 네트워크 세그먼트를 자르는데, IPv6 클라이언트는 빈 문자열로 퇴화 → 약한 지문; 상위 64비트 또는 전체 IP 해시 권장 |

---

## 5. 생태 구성 완전성
### 5.1 .env.example 누락 변수（코드에서 `getenv()` 참조하지만 미정의）—— 31개

| 카테고리 | 변수 |
|------|------|
| **OAuth 자격증명（신기능, 완전히 미문서화）** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`、`_CLIENT_SECRET`、`_REDIRECT_URI`（21개） |
| **Apple 전용** | `APPLE_TEAM_ID`、`APPLE_KEY_ID`、`APPLE_PRIVATE_KEY_PATH` |
| **핵심 기능** | `APP_URL`（인증 메일 링크 의존, 누락 시 메일 링크 오류）、`APP_ENV`、`APP_VERSION` |
| **보안** | `INTERNAL_MONITOR_TOKEN`（/health/* 엔드포인트 보호）、`MAINTENANCE_MODE`、`MAINTENANCE_ALLOWED_IPS`、`WEBHOOK_SECRET`、`JWT_LEEWAY` |
| **클라우드/스토리지** | `AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY`、`BACKUP_S3_BUCKET`、`BACKUP_S3_REGION`、`DB_READ_HOST` |
| **Feature flags（8개）** | `FEATURE_SSL_PRODUCT`、`FEATURE_OBJECT_STORAGE`、`FEATURE_USAGE_BILLING`、`FEATURE_PROMETHEUS`、`FEATURE_CDN_PRODUCT`、`FEATURE_SUPPLIER_RATING`、`FEATURE_AFFILIATE`、`FEATURE_GRAPHQL` |
| **기타** | `METRICS_PORT`、`WS_PORT`、`GEOIP_DB_PATH`（.env.example에서 주석만）、`SSL_STAGING`、`HASHIDS_ALPHABET`、`POSTER_IMAGE_DRIVER`、`EXCHANGE_RATE_API_URL`、`COUNTRY_SEASON_DEFAULT` |

### 5.2 .env.example에 정의됐지만 코드에서 미사용 —— 7개

`SMTP_HOST`、`SMTP_PORT`、`SMTP_USERNAME`、`SMTP_PASSWORD`、`SMTP_ENCRYPTION`、`MAIL_FROM_ADDRESS`、`MAIL_FROM_NAME`（메일 발송 미구현, P1-4 참조）

### 5.3 i18n 커버리지 불일치

| 언어 | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- 중영 외 언어는 번역 키 절반 이상 누락; zh-CN의 billing/health/storage가 en-US보다 6-8개 키 많음（동기화 방향 역전）
- **OAuth 관련 번역 키 전부 누락**（오류 메시지가 하드코딩 영어）

### 5.4 기타 생태 이슈

| ID | 이슈 |
|----|------|
| E1 | `service/composer.lock`이 `.gitignore`에 무시되고 커밋 안 됨 —— 의존성 버전 미고정, 배포 재현 불가（배포 리스크） |
| E2 | `service/.phpunit.cache/`가 git status에 나타남（미무시） |
| E3 | 포트 8787이 로컬의 다른 프로젝트 erp-php와 충돌, cloud-php가 로컬에서 시작 불가（8787이 erp-php의 WorkerMan에 점유 확인됨） |
| E4 | `docs/features.md`가 주장하는 빈도 제한/메일 기능이 실제와 불일치（P1-2 / P1-4 참조）, 문서 동기화 수정 필요 |
| E5 | 의존성 `doctrine/annotations` 폐기됨（composer audit 안내）, 제거 평가 권장 |

---

## 6. 최적화 권장（비차단）
1. **DI화 서비스 생성**: `AuthController` 생성자가 직접 `new AuthService()/OAuthService()` — 컨테이너 연동（webman 네이티브 지원）권장, 테스트와 교체 용이.
2. **업로드 디렉터리 강화**: 디렉터리 내 `index.html` 배치, PHP 실행 비활성화（nginx `location ~ \.php { deny all; }`）.
3. **WAF 정규식 수렴**: `security.php`의 `sqli_patterns`에 `\b(select|update|delete|...)\b` 등 광범위 패턴 포함, 전역 제한 하에 사용자 티켓/리뷰에 이 단어들이 나오면 403 오탐; 민감 파라미터에만 적용하거나 정규식 수렴 권장.
4. **로그 감사**: `AuditLogger::record('user_registered', ['user_id' => null])`가 새 사용자 ID 미기록, 실제 ID 등록 권장.
5. **OAuth 테스트 커버리지**: `OAuthServiceTest`가 URL 구성과 code 교환을 커버하지만 `resolveUser()`（DB 경로）와 Apple 검증 경로에 테스트 없음; P0 수정 후 검증 실패 테스트 케이스 필수.
6. **CI 연동**: 프로젝트에 `.github` 디렉터리 있음, GitHub Actions 추가 권장: `composer install && phpunit` + `composer audit`, 회귀 방지.
7. **HTTP 메서드 제약**: OAuth 라우트가 GET/POST callback을 동시 등록하는 것은 합리적（Apple 필요）, 나머지 공개 쓰기 작업은 명시적 POST, OK.

---

## 7. 수정 우선순위 체크리스트
| 우선순위 | 항목 | 작업량 |
|:---:|------|:---:|
| P0 | Apple id_token 서명 검증（JWKS + iss/aud/exp/nonce） | 중 |
| P1 | OAuth 전 제공업체 `email_verified` 검증 | 소 |
| P1 | RateLimitMiddleware를 공개 라우트에 마운트 | 소 |
| P1 | 로그인 흐름에서 TOTP 강제 | 중 |
| P1 | 실제 메일 발송 구현（또는 미완성 표기） | 중 |
| P1 | .env.example 누락 변수 31개 보완 + OAuth 구성 문서 | 소 |
| P2 | 업로드 type 화이트리스트 + MIME 검증 | 소 |
| P2 | 재설정 코드/비밀번호 찾기 빈도 제한 | 소 |
| P2 | 복구 코드 인터페이스에 비밀번호 확인 마운트 | 소 |
| P2 | composer.lock 커밋, .phpunit.cache gitignore | 극소 |
| P3 | 블랙리스트 죽은 코드 정리, WAF 정규식 수렴, i18n 보완 | 중 |

---

## 8. 수정 상태（2026-08-06）
| 우선순위 | 항목 | 상태 |
|:---:|------|:---:|
| P0 | Apple id_token 서명 검증（JWKS + iss/aud/exp/nonce） | ✅ 수정됨 |
| P1 | OAuth 전 제공업체 `email_verified` 검증（X에 /2/email 폴백 추가） | ✅ 수정됨 |
| P1 | RateLimitMiddleware 마운트（auth/oauth/password/sms/captcha 라우트 + 규칙 4개 신규） | ✅ 수정됨 |
| P1 | 로그인 흐름에서 TOTP 강제（오류 5회 시 15분 잠금, 독립 카운터로 DoS 방지） | ✅ 수정됨 |
| P1 | 실제 메일 발송（symfony/mailer SMTP; 미구성 시 dev-stub 상태） | ✅ 수정됨 |
| P1 | .env.example 누락 변수 31개 보완 + OAuth 구성 문서 | ✅ 수정됨 |
| P2 | 업로드 type 화이트리스트 + finfo MIME 내용 스니핑 | ✅ 수정됨 |
| P2 | 재설정 코드/비밀번호 찾기 빈도 제한（오류 5회 → 429 10분） | ✅ 수정됨 |
| P2 | 복구 코드 인터페이스에 비밀번호 확인 마운트 | ✅ 수정됨 |
| P2 | composer.lock 무시 해제 및 스테이징, .phpunit.cache gitignore | ✅ 수정됨 |
| P3 | 블랙리스트 죽은 코드 정리, WAF 정규식 수렴（구조적 3개）, i18n 보완（zh-CN billing/health/storage 오류 내용 재작성, trans()에 fallback_locale 구현） | ✅ 수정됨 |
| E3 | 포트 8787이 erp-php에 점유, 로컬 시작 불가 | ⚠️ 환경 문제, 배포 환경에선 충돌 없음 |
| E5 | doctrine/annotations 폐기 | ⚠️ 평가 후 유지（hg/apidoc 직접 의존, 제거 시 API 문서 생성 깨짐） |

보충 테스트: OAuth 12항목（nonce 파라미터, 서명 검증, email_verified 거부, X email 폴백 포함）、WAF 수렴 후 2항목. 전체 기준: **319/319 통과（505 assertions）**.

*리포트 생성 방식: PHPUnit 전체 테스트, `php -l` 287개 파일, 라우트/미들웨어 정적 감사, env 사용·정의 집합 차집합 대조, composer audit, 포트·프로세스 조사. 테스트 기준: 314/314 통과.*
