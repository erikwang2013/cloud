# CloudPlatform 리뷰 리포트（2라운드, 2026-08-06）

> 범위: 이전 라운드（audit-report-2026-08-06.md）의 전체 이슈 수정 후 재검.
> 테스트 기준: PHPUnit **319/319 통과（505 assertions）**; `php -l` PHP 파일 253개 **0 문법 오류**.

---

## 1. 테스트와 정적 검사
| 항목 | 결과 |
|------|------|
| PHPUnit 전체 | OK（319 tests, 505 assertions） |
| `php -l`（app/common/config） | 253개 파일 전부 통과 |
| composer audit | **보안 취약점 없음**; 폐기 패키지 1개 doctrine/annotations（hg/apidoc 직접 의존, 유지로 평가） |
| composer.lock | 버전 관리 포함（스테이징 A） |

---

## 2. 생태 구성 대조
### 2.1 env 사용과 정의 —— 완전 ✓

- 코드의 모든 `getenv()` 키（동적 `{PROVIDER}_OAUTH_*` 패턴 포함）가 `.env.example`에 정의 또는 주석 형태의 선택 구성으로 존재（`#HASHIDS_ALPHABET`、`#POSTER_IMAGE_DRIVER`、`#EXCHANGE_RATE_API_URL`、`#COUNTRY_SEASON_DEFAULT`、`#SECURITY_HSTS_VALUE`）
- 템플릿 중복 항목（저위험）: `MAIL_FROM_NAME`은 코드에서 `getenv()` 참조 없음, 템플릿에만 유지

### 2.2 의존성 고정 ✓

- `service/composer.lock` 커밋됨; `.gitignore`에서 더 이상 제외 안 함; `service/.phpunit.cache/`는 무시됨

### 2.3 환경 설명

- 로컬 포트 8787이 여전히 erp-php에 점유, cloud-php 로컬 시작 불가（배포 환경에선 충돌 없음）
- `composer validate`가 vendor 플러그인 `erikwang2013/security-php`의 Installer와 composer 자체 eval 충돌로 fatal 보고（제3자 패키지 문제, 본 프로젝트 코드 아님）

---

## 3. 보안 방어 대조
### 3.1 전역 미들웨어 체인（11계층, 모든 라우트 커버）✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF（SQLi/XSS）→ SecurityPlugin（31종 공격 탐지）
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 공개 라우트 빈도 제한 —— 이번 라운드 수정 1곳

| 라우트 | 미들웨어 | 빈도 제한 규칙 |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min、login 5/min |
| **forgot-password / reset-password** | **RateLimit（이번 라운드 보완 마운트）** | password_reset 3/5min |
| oauthRedirect / oauthCallback（GET+POST） | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **수정**: `forgot-password`/`reset-password` 두 라우트는 이전 라운드에서 `password_reset` 규칙을 정의했지만 미들웨어 마운트를 누락（메일 폭탄/캡차 브루트포스 면）, 이번 라운드에서 보완 마운트.

### 3.3 업로드 파일 노출 —— 이번 라운드 수정 1곳（고위험）

**이슈**: `deployment.md`의 nginx 구성 `location /storage/ { alias .../service/storage/; }`이 storage 전체 디렉터리를 공개:

```
storage/
├── backups/    ← 데이터베이스 백업（.sql.gz）공개 다운로드 가능
├── apple/      ← AuthKey.p8 개인 키 공개 다운로드 가능（Apple 토큰 발급 가능）
├── firebase/   ← FCM 서비스 계정 자격증명（개인 키 포함）공개 다운로드 가능
├── geoip/      ← GeoLite2 데이터베이스
└── uploads/    ← 업로드 파일（공개 의도）
```

**수정**: deployment.md와 docker/nginx.conf 모두 `location ^~ /storage/uploads/`로 변경, uploads 하위 디렉터리만 노출.

### 3.4 기타 대조 ✓

- `verify-email`: 일회용 랜덤 토큰（검증 후 비움）, 브루트포스/열거 면 없음, 빈도 제한 불필요
- 업로드 인터페이스: type 화이트리스트 + finfo MIME 내용 스니핑（이전 라운드 수정）; uploads는 nginx 정적 alias 직접 서빙, PHP 미실행
- JWT: HS256 + Redis 블랙리스트（라이브러리 내 jti 검증）; TOTP 로그인 강제 + 실패 5회 시 15분 잠금
- OAuth: JWKS 서명 검증 + iss/aud/exp/nonce + email_verified 강제（이전 라운드 수정）
- 관리 라우트: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 4. 잔여 권장（비차단）
| 등급 | 항목 | 설명 |
|:---:|------|------|
| P3 | `service/service/` 중복 구 디렉터리（28K） | 구식 Supplier/WebSocket 복사본 포함, PSR-4 로드 안 됨, 추적 안 됨, 오수정 위험; 인적 확인 후 삭제 권장 |
| P3 | `MAIL_FROM_NAME` 템플릿 중복 | 코드 미사용, 메일 발신자 이름 예약 구성으로 유지 가능 |
| P3 | doctrine/annotations 폐기 | hg/apidoc 직접 의존, 제거 시 API 문서 생성 방안 동시 교체 필요 |
| P3 | 업로드 디렉터리 강화（2차 권장） | uploads 내 `index.html` 배치, 배포 계층에서 PHP 미실행 확인（nginx alias가 자연 회피, webman 내장 서버 시나리오는 주의 필요） |

---

## 5. 결론
이전 라운드 15항목 수정이 모두 재검으로 유효 확인, 테스트 기준 안정（319/505）. 이번 라운드 신규 발견 및 현장 수정 3곳: **forgot/reset 라우트 빈도 제한 누락（P1）**、**deployment.md nginx 구성으로 백업·개인 키 노출（P0）**、**docker nginx uploads 정적 구성 누락（P2）**. 수정 후 전체 테스트 재실행 통과.

*리포트 생성 방식: PHPUnit 전체, php -l 253개 파일, 라우트/미들웨어 정적 감사, nginx/docker 구성 감사, env 사용·정의 차집합 대조, composer audit.*
