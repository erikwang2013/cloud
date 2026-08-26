# 보안 감사 리포트 — cloud-php

**날짜**: 2026-08-04
**범위**: 전체 프로젝트（service + admin）
**방법론**: 구성 리뷰, 미들웨어 감사, 코드 점검

---

## 종합 평가: **B+ (양호, 갭 4개 수정 필요)**

프로젝트는 견고한 다계층 보안 아키텍처를 갖추고 있습니다. 31개 탐지기를 가진 erikwang2013/security-php 플러그인이 가장 눈에 띄는 특징입니다. 아래는 상세 분석입니다.

---

## 1. 적용된 방어（검증됨）

### 전송과 암호화
| 메커니즘 | 구현 | 상태 |
|-----------|---------------|--------|
| API 전송 암호화 | AES-256-GCM via erikwang2013/encryption | OK |
| DB 필드 암호화 | AES-128-ECB via erikwang2013/encryptable（결정적, 쿼리 가능） | OK |
| 키 로테이션 | ENCRYPTION_PREVIOUS_KEYS 쉼표 구분 이전 키 | OK |
| ID 난독화 | Hashids, 구성 가능한 솔트, 최소 길이 12 | OK |
| 비밀번호 해시 | bcrypt cost=12, 최소 길이 8 | OK |

### 인증과 접근 제어
| 메커니즘 | 구현 | 상태 |
|-----------|---------------|--------|
| JWT 인증 | erikwang2013/jwt-webman, HS256, access TTL 900s + refresh 30d | OK |
| JWT 블랙리스트 | Redis 기반 토큰 폐기 | OK |
| MFA/TOTP | 6자리, 30s 주기, Google/MS Authenticator 호환 | OK |
| RBAC | Admin AccessControl 미들웨어 + plugin\admin\api\Auth::canAccess() | OK |
| 세션 저장 | Redis (db2) | OK |
| 캡차 | erikwang2013/poster-php 클릭 텍스트 캡차（로그인/가입용） | OK |

### 공격 탐지（WAF — 이중 계층）
| 계층 | 커버리지 | 상태 |
|-------|----------|--------|
| 커스텀 WafMiddleware | SQLi, XSS, CMDi, 경로 트래버설, 헤더 주입, SSRF, NoSQLi, 오픈 리다이렉트 | OK |
| Security Plugin (31개 탐지기) | 위 전부 + XXE, 역직렬화, LDAP, 메일 헤더, SSTI, JWT 공격, Host 헤더, 요청 스머글링, GraphQL, XPATH, JNDI/Log4Shell, SSI, CSV 주입, 데이터 유출, prototype pollution, WebSocket, CORS 우회, DNS rebinding | OK |

### 빈도 제한 (service 전용)
| 라우트 | 속도 | 버스트 | 단위 | 상태 |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### 기타 보호
| 메커니즘 | 구현 | 상태 |
|-----------|---------------|--------|
| 요청 크기 제한 | 본문 10MB, URL 2KB | OK |
| Content-Type 검증 | 화이트리스트: JSON, multipart, form-urlencoded | OK |
| 데이터베이스 prepared statements | PDO::ATTR_EMULATE_PREPARES = false | OK |
| DB 읽기/쓰기 분리 | 마스터 쓰기, 레플리카 읽기, sticky 세션 | OK |
| 감사 로깅 | 별도 감사 DB, LogSanitizer가 민감 필드 비식별화 | OK |
| 유지보수 모드 | 화이트리스트 IP 우회, 나머지는 503 + Retry-After | OK |
| IP 자동 차단 | 60초 내 위반 5회 시 15분 차단 | OK |
| SQL strict mode | 데이터 절단과 암묵적 타입 변환 방지 | OK |

---

## 2. 갭과 권장 사항

### 갭 1 (Medium): CORS가 모든 오리진을 미러링
**파일**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

클라이언트가 보내는 어떤 Origin이든 그대로 반환하여, 사실상 아무 웹사이트나 인증된 크로스 오리진 요청을 할 수 있게 합니다. 보안 플러그인의 cors 탐지기가 일부 헤더 주입을 잡을 수 있지만, 미들웨어 자체에는 오리진 화이트리스트가 없습니다.

**수정**: 화이트리스트 검사 추가. 오리진이 허용 목록에 없으면 `Access-Control-Allow-Origin: null`로 응답하거나 헤더를 아예 생략.

### 갭 2 (Medium): 보안 응답 헤더 누락
service와 admin 모두 중요한 HTTP 보안 헤더를 설정하지 않습니다:

| 헤더 | 권장 | 현재 |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | 누락 |
| X-Content-Type-Options | nosniff | 누락 |
| X-Frame-Options | DENY 또는 SAMEORIGIN | 누락 |
| Content-Security-Policy | nonce/hash 포함 정책 | 누락 |
| X-XSS-Protection | 1; mode=block | 누락 |
| Referrer-Policy | strict-origin-when-cross-origin | 누락 |
| Permissions-Policy | 카메라/마이크/지오로케이션 제한 | 누락 |

**권장**: service와 admin 미들웨어 스택 모두에 SecurityHeadersMiddleware 추가. 영향력 크고 노력 적은 수정.

### 갭 3 (Low): admin/config/security.php에 빈도 제한 없음
**파일**: `admin/config/security.php`

admin 패널에 rate_limits 구성이 없습니다. admin WAF 미들웨어는 요청 크기/Content-Type 제한만 확인합니다. admin 로그인에 대한 브루트포스 공격이 애플리케이션 계층에서 제한되지 않습니다.

**권장**: admin/config/security.php에 rate_limits 추가 또는 RateLimitMiddleware를 admin 라우트에 적용.

### 갭 4 (Low): GeoBlockMiddleware가 정의만 되고 활성화 안 됨
**파일**: `service/common/security/GeoBlockMiddleware.php`

미들웨어는 존재하고 기능하지만 `service/config/middleware.php`에 등록되지 않았습니다. 지역 차단이 필요하면 스택에 추가하세요.

### 갭 5 (Info): 이중 WAF 오버헤드
WafMiddleware（커스텀, 40+ 정규식 패턴）와 SecurityMiddleware（플러그인, 31개 탐지기）가 모든 요청에 실행됩니다. SQLi, XSS, 명령 주입, 경로 트래버설, 헤더 주입, SSRF, NoSQLi, 오픈 리다이렉트에 대해 패턴 커버리지가 상당히 겹칩니다.

**권장**: 보안 플러그인이 더 포괄적이며（31개 탐지기 vs 8개 카테고리）IP 블랙리스트, 필드 화이트리스트, 로그 중복 제거를 갖고 있습니다. 커스텀 WafMiddleware 제거를 고려하거나, 최소한 WafMiddleware에서 겹치는 패턴을 제거하세요.

### 갭 6 (Info): Validator 클래스가 최소
**파일**: `service/common/helper/Validator.php`

required(), email(), minLength()만 있습니다. 최대 길이, 숫자 검증, 문자열 정화, URL 검증, 패턴 매칭이 없습니다. 프레임워크 레벨 검증을 쓰지 않는 컨트롤러는 잘못된 입력을 받을 위험이 있습니다.

---

## 3. Security Plugin — 31개 탐지기 상태

| # | 탐지기 | 모드 | 참고 |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF가 textarea 내용과 일치, log로 유지해야 함 |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }}가 Vue/Angular 템플릿과 일치 |
| 13 | nosql_injection | **log** | $ne/$gt가 셸 변수/LaTeX와 일치 |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | loopback Host（127.0.0.1/localhost）는 더 이상 403 아님（개발/테스트 상례, 기록만） |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

31개 탐지기 전부 활성화. 4개는 log 전용 모드（문서화된 오탐 위험）. 올바른 구성.

---

## 4. 미들웨어 실행 순서 (service)

```
1. VersionMiddleware          — API 버전 헤더 파싱
2. CorsMiddleware              — CORS 헤더 (너무 허용적, 갭 1 참조)
3. ClientPlatformMiddleware    — OS/플랫폼 감지
4. WafMiddleware               — 커스텀 WAF (40+ 정규식 패턴)
5. SecurityMiddleware           — 플러그인 WAF (31개 탐지기)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — ID 디코딩
8. MaintenanceMiddleware       — 유지보수 모드 확인
```

---

## 5. 요약

| 카테고리 | 등급 | 핵심 이슈 |
|----------|-------|------------|
| 공격 탐지 | **A** | 31개 탐지기, 이중 WAF 계층 (중복이지만 철저) |
| 인증 | **A-** | bcrypt+MFA+JWT 블랙리스트, admin 빈도 제한 누락 |
| 전송 보안 | **B+** | AES-256-GCM 양호, HSTS/CSP 헤더 누락 |
| 입력 검증 | **B** | WAF가 공격을 잡지만 앱 레벨 검증은 얇음 |
| 접근 제어 | **A-** | RBAC + 세션 확인, CORS 지나치게 허용적 |
| 감사/로깅 | **A** | 별도 감사 DB, 민감 필드 비식별화 |
| 빈도 제한 | **B+** | service는 잘 구성, admin은 누락 |

**우선 수정 순서:**
1. 보안 응답 헤더 추가 (HSTS, CSP, X-Frame-Options 등)
2. CORS를 오리진 미러링 대신 화이트리스트로 제한
3. admin 패널에 빈도 제한 추가
4. 지역 차단이 필요하면 GeoBlockMiddleware 활성화
5. WAF 계층 통합으로 요청당 정규식 오버헤드 절감 검토

---

## 6. 적용된 개선 (2026-08-04)

### 수정됨
| 갭 | 수정 | 변경 파일 |
|-----|-----|---------------|
| CORS 오리진 미러링 | `CORS_ALLOWED_ORIGINS` env 변수 기반 화이트리스트 모드, `*.example.com` 와일드카드와 전체 `*` 지원 | `service/common/security/CorsMiddleware.php` |
| 보안 헤더 누락 | service와 admin 스택 모두에 `SecurityHeadersMiddleware` 신규 추가: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (env로 선택) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| admin 빈도 제한 없음 | `rate_limits` 구성 + admin 패널에 `RateLimitMiddleware` 추가 (default 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock 미활성화 | service 미들웨어 스택에 `GeoBlockMiddleware` 등록 | `service/config/middleware.php` |

### 신규 환경 변수
| 변수 | 용도 | 기본값 |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | 쉼표 구분 허용 오리진 | (빈 값 = 전부 거부) |
| `SECURITY_HSTS_ENABLE` | HSTS 헤더 활성화 | false |
| `SECURITY_HSTS_VALUE` | HSTS 헤더 값 | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | X-Frame-Options 값 | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | 차단 국가 코드 (ISO 3166-1) | (빈 값 = 비활성화) |
| `GEOIP_DB_PATH` | GeoLite2 .mmdb 경로 | storage_path('geoip/GeoLite2-Country.mmdb') |

### 업데이트된 미들웨어 파이프라인

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
