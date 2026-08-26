# CloudPlatform 종합 리뷰 리포트

**날짜:** 2026-08-04  
**리뷰 범위:** 전체 프로젝트（코드 품질, 보안, 생태 구성, 배포, 문서）  
**브랜치:** main  
**최신 커밋:** e321bcc — 이번 라운드에서 수정한 3개 잔여 이슈

---

## 1. 프로젝트 개요
| 차원 | 상태 |
|------|------|
| 프로젝트 유형 | PHP 8.2+ / webman 클라우드 리소스 거래 플랫폼 |
| 코드 규모 | service（15개 모듈, 295 tests）+ admin（53개 컨트롤러, 67 tests）+ Flutter + HarmonyOS |
| 데이터베이스 | MySQL 8.0, 46개 테이블（7 wa_* + 39 erik_*） |
| 배포 방식 | 원클릭 설치 마법사 / Docker Compose / 수동 |
| 문서 | 문서 10편 + SVG 아키텍처 다이어그램 11개 |

---

## 2. 발견된 이슈
### CRITICAL（심각）

#### C1. Docker 배포에 관리자 백오피스 없음

**이슈:** Dockerfile이 `service/` 디렉터리만 복사하고, docker-compose가 8787 포트만 프록시합니다. 관리자 백오피스（admin panel, 포트 8788）가 전혀 Docker화되지 않았습니다.

```dockerfile
# docker/Dockerfile — 현재 service만 처리
COPY service/ /app/
```

**영향:** Docker로 배포하는 사용자는 관리자 백오피스를 사용할 수 없습니다. README의「Docker Compose 원클릭 시작」주장과 불일치합니다.

**권장:** `admin/`용 Dockerfile 추가 또는 멀티스테이지 빌드로 두 서비스를 동시에 배포.

---

#### C2. Docker 데이터베이스 포트가 호스트에 노출

**이슈:** docker-compose.yml에서 MySQL (3306)과 Redis (6379) 포트가 호스트에 직접 매핑됩니다:

```yaml
mysql:
  ports:
    - "3306:3306"    # 공개망에 노출
redis:
  ports:
    - "6379:6379"    # 공개망에 노출
```

**영향:** 서버에 공인 IP가 있다면 데이터베이스가 외부에 노출됩니다. 흔한 보안 사고 원인입니다.

**권장:** `ports` 매핑 제거 또는 최소한 `127.0.0.1:3306:3306`으로 바인딩. Docker 내부 네트워크로도 통신 가능.

---

#### C3. LICENSE 파일 없음

**이슈:** README가「라이트 버전 — MIT License」라고 선언하지만 프로젝트 루트에 `LICENSE` 파일이 없습니다.

**영향:** 오픈소스 법적 요건 누락. GitHub가 프로젝트의 라이선스 유형을 인식하지 못합니다.

**권장:** 루트에 표준 MIT License 텍스트로 `LICENSE` 파일 생성.

---

### HIGH（높은 우선순위）

#### H1. 중복 SQL 파일로 혼동 유발

**이슈:** 프로젝트에 SQL DDL 파일 3개가 존재합니다:

| 파일 | 라인 | 테이블 수 | 상태 |
|------|------|------|------|
| `install.sql`（루트） | 739 | 46 | **현재 사용** |
| `admin/install.sql` | 152 | 7（wa_*만） | 구버전, 미삭제 |
| `docs/database.sql` | 629 | 39（erik_*만） | 구버전, 미삭제 |

**영향:** 유지보수자가 잘못된 파일을 수정하여 동기화 불일치가 발생할 수 있습니다.

**권장:** `admin/install.sql`과 `docs/database.sql` 삭제, 또는 파일 헤더에 `install.sql`을 가리키는 눈에 띄는 폐기 안내 추가.

---

#### H2. 설치 마법사가 감사 데이터베이스를 생성하지 않음

**이슈:** `install/index.php`가 `service/.env` 생성 시 감사 데이터베이스 구성을 포함합니다:
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
하지만 설치 마법사가 이 데이터베이스를 생성한 적이 없습니다. 앱이 시작 후 감사 로그를 쓰려고 하면 `Unknown database`로 실패합니다.

**영향:** 감사 로그 기능 사용 불가, 컴플라이언스 영향.

**권장:** step 4 설치 실행 시 `CREATE DATABASE IF NOT EXISTS cloud_platform_audit` 추가.

---

#### H3. Docker에 Elasticsearch 서비스 없음

**이슈:** docker-compose.yml은 app + mysql + redis 세 개 서비스뿐입니다. README 기술 스택에 Elasticsearch 8.x가 필수 컴포넌트로 명시되어 있습니다.

**영향:** 전문 검색（제품, 사용자, 주문, 티켓）이 Docker 배포에서 완전히 사용 불가.

**권장:** docker-compose.yml에 Elasticsearch 서비스 추가.

---

#### H4. Dockerfile에 PHP 확장 누락

**이슈:** Dockerfile이 설치하는 PHP 확장은 `gd pdo_mysql zip bcmath redis`입니다. 하지만 환경 검사는 9개 확장을 요구하며 누락분:
- `intl`（PHP 국제화）
- `xml`（XML 파싱）
- `fileinfo`（파일 유형 감지）

**영향:** 일부 기능이 Docker 환경에서 조용히 실패할 수 있음.

**권장:** 누락 확장 추가: `docker-php-ext-install intl xml fileinfo`

---

### MEDIUM（중간 우선순위）

#### M1. admin/.env.example 구성 항목이 부족

**이슈:** service/.env.example（146줄）vs admin/.env.example（64줄）, 후자의 주석과 구성 항목이 확연히 적습니다.

**권장:** admin/.env.example에 주석 설명 보완, 최소한 어떤 필드가 service 측과 일치해야 하는지 표시.

---

#### M2. .env.example의 HASHIDS_SALT 하드코딩

**이슈:** 두 `.env.example` 파일 모두:
```ini
HASHIDS_SALT=cloud-platform-hashids
```
운영자가 이 값을 수정하지 않고 `cp .env.example .env`를 실행하면 모든 인스턴스가 동일한 솔트를 공유하게 됩니다.

**권장:** `.env.example`에서 플레이스홀더 사용 및 주석으로「반드시 고유한 랜덤 값 생성」강조.

---

#### M3. 설치 마법사 성공 페이지 링크 무효

**이슈:** 설치 완료 페이지 링크가 `href="#"`를 사용하여 실제 클릭 가능한 URL이 없습니다.

**권장:** 최소한 구체적인 URL/포트 정보와 시작 명령을 표시.

---

#### M4. Docker에 설치 마법사 미포함

**이슈:** Dockerfile이 `install.php` 또는 `install/` 디렉터리를 복사하지 않습니다. Docker 사용자는 원클릭 설치 마법사를 사용할 수 없습니다.

**권장:** Docker 배포는 수동 구성이 필요하다고 문서에 명시하거나, 이미지에 설치 마법사 통합.

---

#### M5. Docker Compose 환경 변수 불완전

**이슈:** docker-compose.yml의 `environment`에 필수 구성 다수가 누락: JWT 키, Hashids 솔트, 암호화 키, SMTP, Stripe 등.

**권장:** 전체 환경 변수 목록 보완 또는 `.env` 파일 참조.

---

### LOW（낮은 우선순위）

#### L1. 문서의 Docker 섹션 취약

README의 Docker 배포는 몇 줄뿐이며, 환경 변수 구성, 데이터베이스 초기화, 관리자 백오피스 접근 방법을 설명하지 않습니다.

**권장:** 완전한 Docker 배포 문서 보완.

---

#### L2. .editorconfig 없음

**이슈:** 프로젝트에 `.editorconfig` 파일이 없습니다. 다중 기여자 프로젝트에서 통일된 들여쓰기, 줄바꿈 설정이 중요합니다.

**권장:** 표준 `.editorconfig` 추가 — PHP 4스페이스 들여쓰기, UTF-8, LF 줄바꿈.

---

#### L3. 코드 하드코딩 기본값 집중 관리

**이슈:** `install/index.php`에 하드코딩된 기본값（DB 호스트, 포트, DB명, 관리자 사용자명）이 여러 곳에 있어 수정 시 누락 위험이 있습니다.

**권장:** 파일 상단의 상수 정의로 추출.

---

## 3. 생태 구성 완전성 평가
### .env 변수 커버리지

| 구성 영역 | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| 데이터베이스 연결 | ✓ | ✓ | ✓ |
| 감사 데이터베이스 | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| JWT 인증 | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| 전송 암호화 (AES-256-GCM) | ✓ | ✓ | ✓ |
| 필드 암호화 (AES-128-ECB) | ✓ | ✓ | ✓ |
| SMTP 메일 | ✓ | N/A | ✓ |
| Stripe 결제 | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| Twilio SMS | ✓ | N/A | ✓ |
| Firebase 푸시 | ✓ | N/A | ✓ |
| 클릭 캡차 | ✓ | N/A | ✓ |
| Sentry 모니터링 | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| 키 로테이션 | ✓ | N/A | ✓ |
| **평가** | **완전** | **완전** | **완전** |

### 설치 마법사 생성 공유 키 일관성

| 키 | service | admin | 일치 |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **평가** | **통과** | **통과** | **통과** |

---

## 4. 보안성 평가
| 검사 항목 | 상태 | 설명 |
|--------|:--:|------|
| CSRF 방어 | ✓ | Token 생성 + hash_equals 검증 |
| Session 보안 | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| 입력 검증 | ✓ | DB 이름 정규식 검증, 포트 범위 검사 |
| 비밀번호 강도 | ✓ | 최소 8자 + 문자 + 숫자/특수문자 |
| 비밀번호 해시 | ✓ | password_hash(PASSWORD_DEFAULT) |
| 키 생성 | ✓ | openssl rand 또는 random_bytes |
| SQL 주입 방어 | ✓ | PDO prepared statements |
| 오류 비식별화 | ✓ | 상세 오류는 error_log에만, 사용자는 일반 안내 표시 |
| XSS 방어 | ✓ | htmlspecialchars() 출력 이스케이프 |
| 재설치 보호 | ✓ | 기존 테이블 + .env 파일 감지 |
| 단계 강제 | ✓ | session max_step으로 단계 건너뛰기 방지 |
| 트랜잭션 래핑 | ✓ | beginTransaction/commit/rollBack |
| Docker 포트 노출 | ✗ | MySQL:3306 / Redis:6379 호스트 매핑 |
| 감사 데이터베이스 생성 | ✗ | 설치 마법사가 _audit DB 미생성 |
| **종합 점수** | **A-** | 핵심 보안 조치는 완비, Docker 구성 개선 필요 |

---

## 5. SQL 완전성
| 검사 항목 | 결과 |
|--------|------|
| 총 테이블 수 | 46장（7 wa_* + 39 erik_*）✓ |
| 엔진 | 전부 InnoDB ✓ |
| 문자셋 | 전부 utf8mb4 ✓ |
| 기본 키 유형 | BIGINT UNSIGNED（비자동 증가）✓ |
| CREATE IF NOT EXISTS | 전부 사용 ✓ |
| 파괴적 문장 존재 여부 | 없음（DROP TABLE 없음）✓ |
| 구버전 SQL 파일 | 구버전 2개 존재, 정리 필요 ⚠ |

---

## 6. 테스트 커버리지 평가
| 테스트 스위트 | 프레임워크 | 테스트 수 | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**평가:** 테스트 수 충분（362개 테스트）, CI/CD가 이중 PHP 버전 문법 검사 + 양단 단위 테스트를 커버.

---

## 7. 문서 완전성
| 문서 | 내용 | 상태 |
|------|------|:--:|
| README.md | 프로젝트 개요, 아키텍처, 빠른 시작, API 개요 | ✓ |
| README_EN.md | 영어판 README | ✓ |
| docs/architecture.md | 시스템 아키텍처 설계 | ✓ |
| docs/features.md | 12개 모듈 기능 설계 | ✓ |
| docs/api-reference.md | 135+ 엔드포인트 레퍼런스 | ✓ |
| docs/admin-design.md | 관리자 백오피스 설계 | ✓ |
| docs/supplier-api.md | 공급업체 API | ✓ |
| docs/deployment.md | 배포 체크리스트 | ✓ |
| docs/editions.md | 버전 비교 | ✓ |
| docs/diagrams/ (11 SVG) | 아키텍처/보안/비즈니스 프로세스 | ✓ |
| LICENSE 파일 | **누락** | ✗ |

---

## 8. 수정 권장 요약
### 1순위（다음 릴리스 전 수정 권장）

| # | 이슈 | 등급 |
|---|------|:--:|
| 1 | LICENSE 파일 생성（MIT） | CRITICAL |
| 2 | 구버전 SQL 파일 삭제（admin/install.sql, docs/database.sql） | HIGH |
| 3 | Docker MySQL/Redis 포트를 호스트에 노출하지 않기 | CRITICAL |
| 4 | 설치 마법사가 감사 데이터베이스 `_audit` 생성 | HIGH |

### 2순위（최근 수정 권장）

| # | 이슈 | 등급 |
|---|------|:--:|
| 5 | Docker가 관리자 백오피스（admin panel） 지원 | CRITICAL |
| 6 | Docker Compose에 Elasticsearch 서비스 추가 | HIGH |
| 7 | Dockerfile에 PHP 확장 보완（intl, xml, fileinfo） | HIGH |
| 8 | .env.example의 HASHIDS_SALT를 플레이스홀더로 변경 | MEDIUM |

### 3순위（지속 개선）

| # | 이슈 | 등급 |
|---|------|:--:|
| 9 | Docker 배포 문서 보완 | LOW |
| 10 | .editorconfig 추가 | LOW |
| 11 | 코드 내 하드코딩 기본값 정리 | LOW |
| 12 | .env 생성 함수의 구성 항목 통일 | LOW |

---

## 9. 결론
프로젝트 전체 품질은 양호하며, 핵심 설치 마법사는 이전 라운드 감사 후 보안 이슈가 모두 수정되었습니다. 코드 구성이 명확하고 모듈화 수준이 높으며 문서도 완비되어 있습니다. 주요 문제는 **Docker 배포 구성 불완전**에 집중됩니다 — 관리자 백오피스, 검색 서비스, PHP 확장이 없고 데이터베이스 포트 노출 보안 위험이 있습니다.

**총평: B+** — 기능 완전, 보안 핵심은 갖춰졌으며, Docker 생태 구성 보완이 필요합니다.
