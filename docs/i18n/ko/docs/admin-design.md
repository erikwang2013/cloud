# Admin 패널 설계 문서

## 개요

`admin/`은 Layui 기반 관리 대시보드를 제공하는 독립 webman v2.1 인스턴스입니다. `service/` 백엔드와 별개로 실행되며, MySQL 데이터베이스와 erikwang2013 패키지 7개만 공유합니다.

## 아키텍처

```
┌─────────────────────────────────────────────────┐
│                  Admin Panel                     │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │ Controller│  │  Model   │  │   Bootstrap   │ │
│  │ (Layui)  │  │(Eloquent)│  │(worker start) │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │             │               │          │
│  ┌────┴─────────────┴───────────────┴─────────┐ │
│  │         7 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season │ Poster     │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

### 모듈 의존성 맵

![모듈 의존성 맵](diagrams/module-dependency.svg)

## 디렉터리 구조

```
admin/
├── app/
│   ├── bootstrap/       # 프로세스별 시작 부트스트랩
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 컨트롤러 54개 (Base/Crud + 엔티티별 CRUD)
│   │   ├── Base.php     # hashids_encode_ids 적용 json()
│   │   ├── Crud.php     # hashids 디코드 포함 Select/Insert/Update/Delete/Export
│   │   ├── DashboardController.php  # 대시보드 데이터 API (사용자 통계 + 추세)
│   │   ├── AccountController.php    # 로그인/로그아웃/프로필/비밀번호
│   │   ├── AdminController.php      # Admin CRUD + 역할
│   │   ├── RoleController.php       # 역할 CRUD + 규칙 트리
│   │   └── ...
│   ├── model/            # Eloquent 모델 44개（36개는 service 무접두사 비즈니스 테이블 매핑 + alerts（install.sql 정의）+ wa_* 관리 테이블 7개）
│   │   ├── Base.php     # Snowflake PK + Encryptable 지원
│   │   ├── Admin.php    # Encryptable: password, email, mobile
│   │   ├── User.php     # Encryptable: 6개 필드 + Searchable trait
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # WafMiddleware + AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # 공개 API (plugin\admin\api)
│   └── Auth.php          # canAccess() ACL
├── config/
│   ├── plugin/erikwang2013/  # 플러그인 구성 7개
│   ├── hashids.php       # Hashids 커넥션 (main + alternative)
│   └── encryption.php    # 암호화 구성 (master key, cipher)
├── tests/                # PHPUnit 11 테스트 스위트 (286 tests, 962 assertions)
│   ├── HashidsTest.php   # 21 tests
│   ├── BaseJsonTest.php  # 13 tests
│   ├── CrudHashidsTest.php # 14 tests
│   ├── TreeTest.php      # 19 tests
│   ├── AccessControlMiddlewareTest.php # 7 tests（401/403/방출）
│   ├── AdminControllersTest.php        # 컨트롤러 리플렉션 회귀 48개
│   ├── UtilTest.php      # 17 tests
│   ├── DictTest.php      # 5 tests
│   ├── ExcelExportTest.php # 4 tests
│   ├── LayuiTest.php     # 5 tests
│   └── support/          # RequestMock, TestableCrud
├── install.sql           # DDL (bigint unsigned PK, auto-increment 없음)
└── phpunit.xml
```

## 패키지 통합 상세

### 1. Snowflake (분산 기본 키)

**구성**: `config/plugin/erikwang2013/snowflake-php/app.php`
**부트스트랩**: `app/bootstrap/SnowflakeBootstrap.php`

```php
// Model Base::boot() — creating 이벤트
static::creating(function (Model $model) {
    if (empty($model->getKey())) {
        $snowflake = Container::instance()->get(Snowflake::class);
        $model->setAttribute($model->getKeyName(), $snowflake->id());
    }
});
```

- 64비트 ID: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`
- Epoch: 2024-01-01 (최대 수명 ~69년)
- Base 모델에 `$incrementing = false`, `$keyType = 'int'`
- 모든 PK/FK 컬럼: `bigint unsigned NOT NULL`

### 2. Hashids (ID 난독화)

**구성**: `config/hashids.php` + `config/plugin/erikwang2013/hashids/app.php`

**인코딩 경로** (응답):
- `Base::json()`이 `hashids_encode_ids($data)` 재귀 호출
- `id`, `*_id`, `*_ids` 이름의 양의 정수 필드 → hashid 문자열
- `Crud::formatNormal()`도 인코딩 적용 (코드 리뷰에서 수정)

**디코딩 경로** (요청):
- `Crud::selectInput()`: WHERE 절의 `id`/`*_id` hashid 문자열 디코드
- `Crud::updateInput()`: `$request->post()`에서 기본 키 디코드
- `Crud::deleteInput()`: `$request->post()`의 PK 배열 디코드
- `AdminController::update()`: `updateInput()` 반환값 직접 사용 (중복 제거됨)
- `RoleController::select()`/`rules()`: `$request->get('id')` 디코드

**헬퍼 함수** (`app/functions.php`):
- `hashids_encode(int $id): string`
- `hashids_decode(string $hash): int` — 실패 시 0 반환
- `hashids_encode_ids(array $data): array` — 재귀, `is_numeric()` 문자열 처리

### 3. Encryptable (DB 필드 암호화)

**구성**: `config/plugin/erikwang2013/encryptable/app.php`
**부트스트랩**: `app/bootstrap/EncryptableBootstrap.php`

Eloquent `CastsAttributes` 인터페이스 사용:
- `get()`: DB 읽기 시 AES 복호화
- `set()`: DB 쓰기 시 AES 암호화

**암호화 필드**:
| 모델 | 필드 |
|-------|--------|
| Admin | password, email, mobile |
| User | password, email, mobile, token, last_ip, join_ip |

**중요 규칙**: 항상 모델 인스턴스 `save()` 사용, Query Builder `update()` 금지. `Admin::where(...)->update(...)`는 Eloquent 캐스트를 우회해 원문을 저장합니다. 코드 리뷰 중 `AccountController`에서 수정됨.

**비밀번호 레이어링**: 비밀번호는 먼저 bcrypt 해시되고 (`insertInput`/`updateInput`), 그 해시가 `save()` 시 Encryptable 캐스트로 AES 암호화. 읽기 시: AES 복호화 → bcrypt 해시 → `password_verify()`.

### 4. Encryption (API 전송)

**구성**: `config/encryption.php`
**부트스트랩**: `app/bootstrap/EncryptionBootstrap.php`

API 레벨 요청/응답 암호화용 (AES-256-GCM). 제공 기능:
- `encrypt_data(string $plaintext): string`
- `decrypt_data(string $ciphertext): string`

`ENCRYPTION_MASTER_KEY` 미설정 시 명확한 메시지와 함께 `RuntimeException` 발생.

### 5. Webman-Scout (Elasticsearch)

**구성**: `config/plugin/erikwang2013/webman-scout/app.php` + `command.php`

User 모델이 `Searchable` trait 사용:
```php
class User extends Base
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
```

### 6. Season (국가 국기)

**구성**: `config/plugin/erikwang2013/season/app.php`

전역 헬퍼: `country_season_flag(string $code): string`
- `country_season_flag('CN')` → 🇨🇳
- `country_season_flag('US')` → 🇺🇸

`CountrySeason` 클래스를 통해 지역화된 시즌 이름도 제공.

### 7. Poster-PHP (클릭 캡차)

**구성**: `config/poster.php` + `config/plugin/erikwang2013/poster/app.php`
**부트스트랩**: `config/plugin/erikwang2013/poster/bootstrap.php` → `CaptchaPlugin`

로그인과 가입을 위한 클릭 기반 캡차 검증 제공:

```
Client                         Server
──────                         ──────
POST /api/captcha/create
  Header: X-Api-Version: v1
  → CaptchaService::create()
    → captcha_create('click')
      → ClickCaptcha::generate()
        → GD가 무작위 배치된 n개의 중국어 단어 이미지 렌더링
        → targets + key를 Redis/File 스토리지에 저장
      ← {key, image (base64), target_count, expires_in}

POST /api/auth/login
  Header: X-Api-Version: v1
  (with captcha_key + captcha_points)
  → AuthController::verifyCaptcha()
    → CaptchaService::verify(key, [[x1,y1], [x2,y2], ...])
      → captcha_verify(key, 'click', points)
        → CaptchaManager가 유클리드 거리 ≤ 18px 허용 오차 검사
      ← true/false
```

**보안 기능**:
- 일회용 키: 검증 성공 후 삭제
- 브루트포스 방지: 키당 최대 3회 실패, 이후 삭제
- 300초 TTL (`CAPTCHA_TTL`로 설정 가능)
- 클릭 허용 오차: 18px 반경 (설정 가능)
- 난이도 레벨: easy (대상 2개), medium (3), hard (4)
- 스토리지: Redis → 파일 폴백 자동 감지, `CAPTCHA_STORAGE`로 설정 가능

**래퍼**: `Common\Captcha\CaptchaService`가 `config/poster.php`의 커스텀 구성을 로드, `create()` (보안을 위해 응답에서 targets 제거)와 `verify()` 메서드 제공. `AuthController::register()`와 `AuthController::login()`에서 사용.

### 8. ConfirmationMiddleware (비밀번호 재검증)

**구성**: `config/route.php`의 라우트 그룹 미들웨어

비밀번호 재입력을 요구해 파괴적/민감 작업을 보호. 민감 라우트 엔드포인트 12곳에 미들웨어로 적용:

```
Client                              Server
──────                              ──────
POST /api/orders/{id}/pay
  Header: X-Api-Version: v1
  (with confirm_password field)
    → ConfirmationMiddleware::process()
      → userId 존재 확인 (없으면 401)
      → Redis 잠금 키 확인 (잠금 상태면 429)
      → 비밀번호 비어있지 않음 검증 (없으면 422)
      → User::find() + Hash::check()로 bcrypt 검증
      → 실패 시:
        → Redis INCR confirm_failed:{userId} 카운터
        → 카운트 ≥ 5면 SETEX confirm_lock:{userId} 900s
        → AuditLogger::record('confirm_failed', ...)
        → 403 반환
      → 성공 시:
        → DEL confirm_failed:{userId} 카운터
        → AuditLogger::record('confirm_success', ...)
        → $next($request) 호출
```

**민감 사용자 엔드포인트** (Auth + Confirmation):
| 메서드 | 경로 | 작업 |
|--------|------|-----------|
| POST | `/api/orders/{id}/pay` | 결제 시작 |
| POST | `/api/supplier/withdraw` | 출금 신청 |
| DELETE | `/api/dns/{domain}/records/{id}` | DNS 레코드 삭제 |

**민감 관리자 엔드포인트** (Auth + AdminRole + Confirmation):
| 메서드 | 경로 | 작업 |
|--------|------|-----------|
| DELETE | `/admin/api/products/{id}` | 상품 삭제 |
| POST | `/admin/api/orders/{id}/refund` | 주문 환불 |
| POST | `/admin/api/provisioning/resources/{id}/destroy` | 리소스 파기 |
| POST | `/admin/api/kyc/{id}/approve` | KYC 승인 |
| POST | `/admin/api/kyc/{id}/reject` | KYC 거부 |
| POST | `/admin/api/suppliers/{id}/approve` | 공급업체 승인 |
| POST | `/admin/api/suppliers/{id}/settle` | 정산서 생성 |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | 출금 승인 |
| PUT | `/admin/api/system/config` | 시스템 구성 업데이트 |

API 버전은 URL 경로가 아닌 `X-Api-Version` 헤더로 전달 (기본: `v1`).

**보안 기능**:
- `Hash::check()`로 bcrypt 비밀번호 검증
- 빈도 제한: 실패 5회 시 15분 잠금 (900s TTL)
- 잠금은 Redis 키로 사용자별 적용 (`confirm_lock:{userId}`, `confirm_failed:{userId}`)
- 성공 시 실패 카운터 리셋
- 모든 시도가 감사 DB에 기록 (성공, 실패, 잠금)
- `verifyPassword()`는 protected 메서드로, 익명 서브클래스 오버라이드로 테스트 가능

**테스트 가능성**: `ConfirmationMiddlewareTest` (11 tests)는 `verifyPassword()`를 고정 부울 반환으로 오버라이드한 익명 서브클래스를 사용해 Eloquent/DB 의존성 회피. 커버리지: 401 미인증, 422 비밀번호 누락/공백, 403 잘못된 비밀번호, 성공 패스스루, 빈도 제한 키 형식, 잠금 키 형식, 최대 실패 임계값 경계 (4→잠금 없음, 5→잠금, 6→잠금).

## ACL 시스템

### 컨트롤러 레벨

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // 로그인 생략
protected $noNeedAuth = ['select'];                         // 인증 생략
```

`api/Auth::canAccess()`가 `ReflectionClass`로 검사.

**AccessControlMiddleware 응답**（`middleware/AccessControl.php`）：
- 미로그인（`noNeedLogin` 외）→ **HTTP 401**, body는 로그인 페이지 이동 스크립트
- 로그인했지만 권한 부족 → **HTTP 403** 오류 페이지（상태 코드 403, 더 이상 500 아님）
- 방출 목록 내（로그인 페이지/캡차 등）→ 정상 방출

### 역할 기반

- 역할은 `rules` 보유 (쉼표 구분 규칙 ID 또는 슈퍼 관리자용 `*`)
- 규칙은 `wa_rules`에 `{Controller}@{action}` 키로 저장
- `api/Auth::canAccess()`가 역할의 rules에 대해 `$controller@$action` 키 해석
- 슈퍼 관리자 (`rules = '*'`)는 모든 검사 우회

### 데이터 범위

```php
protected $dataLimit = null;     // 제한 없음
protected $dataLimit = 'auth';   // 관리자 본인 + 하위 항목 데이터만
protected $dataLimit = 'personal'; // 관리자 본인 데이터만
protected $dataLimitField = 'admin_id';
```

## 코드 리뷰 발견 사항 (수정됨)

초기 커밋 리뷰 중 다음 항목을 발견하고 수정했습니다:

### 치명적
1. **AccountController의 Encryptable 우회**: `password()`와 `update()`가 `Admin::where()->update()` 사용 → Eloquent 캐스트 우회 → 암호화 컬럼에 원문 저장. `Admin::find()->save()`로 수정.
2. **Crud::formatNormal() ID 미인코딩**: 전역 `json()` 호출로 `hashids_encode_ids()` 미적용. 수정됨.

### 중요
3. **hashids_encode_ids 엄격한 `is_int`**: PDO의 큰 bigint 값이 PHP 문자열로 도착. `is_numeric()` + 정수 확인으로 변경.
4. **AdminController 중복 ID 디코드**: `update()`가 동일 PK를 두 번 디코드. 중복 제거, `insert()`의 루프 변수 섀도잉 수정.
5. **AccountController::update()의 죽은 비밀번호 코드**: password 필드가 허용 목록에 없음. 제거.
6. **하드코딩된 MySQL 드라이버**: `config('database.default')`로 변경.

## Excel 내보내기

### 아키텍처

Excel 내보내기는 PhpSpreadsheet ^2.0으로 서버 측 .xlsx 파일을 생성. CRUD 메커니즘이 두 개이므로 admin 패널에는 두 개의 별도 내보내기 경로가 있습니다:

```
내보내기 요청 (현재 테이블 필터 포함)
  ├── Crud 기반 컨트롤러 (User, Admin, Role 등)
  │     → Crud::export()
  │       → selectInput()이 쿼리 파싱 재사용 (hashids 디코드, WHERE, ORDER)
  │       → doSelect()가 Eloquent 쿼리 생성
  │       → 10,000행 상한
  │       → 결과 데이터에 hashids_encode_ids() 적용
  │       → ExcelExport::export()가 .xlsx 생성
  │
  └── TableController (wa_dict, wa_rules 등 일반 테이블)
        → TableController::export()
          → 테이블 스키마 + 요청 파라미터로 쿼리 생성
          → hashids_encode_ids() 적용
          → ExcelExport::export()가 .xlsx 생성
```

### ExcelExport 유틸리티 (`app/common/ExcelExport.php`)

PhpSpreadsheet용 플루언트 래퍼:

- `setColumns(array $columns)` — 컬럼 순서 정의
- `setLabels(array $labels)` — 사람이 읽을 수 있는 컬럼 헤더 설정
- `addRow(array $row)` / `addRows(array $rows)` — 데이터 채우기
- `save(string $title): string` — .xlsx를 `runtime/exports/`에 쓰고 파일 경로 반환
- 정적 헬퍼: `ExcelExport::export($title, $columns, $data, $labels)` — 원샷 내보내기
- `Worksheet::getColumnDimension()`으로 컬럼 자동 크기 조정

### Crud::export()

```php
public function export(Request $request): Response
{
    [$where, $format, $limit, $field, $order] = $this->selectInput($request);
    $query = $this->doSelect($where, $field, $order);
    $maxRows = 10000;
    $total = min($query->count(), $maxRows);
    $items = $query->limit($maxRows)->get();
    if (method_exists($this, 'afterQuery')) {
        $items = call_user_func([$this, 'afterQuery'], $items);
    }
    $data = array_map(fn($item) => ...toArray(), $items->toArray());
    $data = hashids_encode_ids($data);
    // 테이블 스키마 주석에서 컬럼 라벨 도출
    $path = ExcelExport::export($table, $columns, $data, $labels);
    return response()->download($path, $table . '_' . date('YmdHis') . '.xlsx');
}
```

모든 Crud 기반 컨트롤러 (Admin, User, Role 등)가 `export()`를 자동 상속.

### 프론트엔드 연결

- Layui 내장 `"exports"` 툴바 항목 (클라이언트 측 CSV)을 커스텀 `{title: "导出", layEvent: "export"}` 버튼으로 교체
- `export` 이벤트 핸들러가 `window.exportExcel()` 호출 — 현재 테이블 필터 파라미터 수집 후 다운로드 URL 오픈
- `Layui::buildTable()`가 모든 CRUD 페이지에 커스텀 내보내기 버튼이 있는 툴바 생성

### Service Admin API 내보내기

service 백엔드 (`service/`)도 자체 `Common\ExcelExport` 래퍼로 Excel 내보내기 제공:

| 엔드포인트 | 컨트롤러 | 내보내는 데이터 |
|----------|-----------|---------------|
| `GET /admin/api/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

모든 API 엔드포인트는 `X-Api-Version` 헤더 필요 (기본: `v1`).

충돌 방지를 위해 내보내기 라우트는 `/{id}` 파라미터 라우트보다 먼저 배치.

## Service Admin API — 확장 기능

### Admin API 엔드포인트 (Service 레이어)

모든 admin REST 엔드포인트는 `/admin/api` 접두사가 있고 `AdminRoleMiddleware` 필요.

| 그룹 | 엔드포인트 | 컨트롤러 |
|-------|-----------|------------|
| Dashboard | `GET /dashboard`, `/kyc`, `POST /kyc/{id}/approve`, `/reject` | `Admin\DashboardController` |
| Users | `GET /users`, `/users/export`, `/users/{id}`, `PUT /users/{id}/status` | `Admin\UserController` |
| Products | `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}`, `POST /products/{id}/skus`, `PUT /skus/{id}`, `POST /skus/{id}/region-price` | `Admin\ProductController` |
| 상품 가져오기/내보내기 | `GET /products/export` (CSV), `POST /products/import` (CSV upsert) | `Admin\ImportExportController` |
| Orders | `GET /orders`, `/orders/export`, `/orders/{id}`, `POST /orders/{id}/refund` | `Admin\OrderController` |
| Invoices | `GET /invoices`, `POST /invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Payments | `GET /payments/channels`, `PUT /payments/channels/{id}`, `GET /payments/transactions`, `/reconcile` | `Admin\PaymentController` |
| Provisioning | `GET /provisioning/tasks`, `POST /tasks/{id}/retry`, `POST /resources/{id}/upgrade`, `/destroy`, `GET /hosts` | `Provisioning\TaskController` |
| Provider APIs | `GET /providers`, `POST /providers`, `PUT /providers/{id}`, `DELETE /providers/{id}` | `Admin\ProviderApiController` |
| Suppliers | `GET /suppliers`, `/suppliers/export`, `POST /suppliers/{id}/approve`, `/settle`, `/withdraws/{id}/approve` | `Admin\SupplierController` |
| 공급업체 API Key | `GET /suppliers/{id}/api-keys`, `POST /suppliers/{id}/api-keys`, `DELETE /suppliers/api-keys/{id}` | `Admin\SupplierController` |
| Tickets | `GET /tickets`, `POST /tickets/{id}/assign`, `/close` | `Ticket\TicketController` |
| Coupons | `GET /coupons`, `POST /coupons`, `DELETE /coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET /webhooks`, `POST /webhooks`, `DELETE /webhooks`, `POST /webhooks/test` | `Admin\WebhookController` |
| Domains | `GET /domains/tlds`, `POST /domains/tlds`, `PUT /domains/tlds/{id}`, `DELETE /domains/tlds/{id}`, `GET /domains/zones`, `/transfers`, `POST /transfers/{id}/approve` | `Admin\DomainController` |
| Notifications | `GET /notifications/templates`, `PUT /notifications/templates/{id}`, `GET /notifications/log` | `Admin\NotificationController` |
| Help Articles | `GET /help`, `POST /help`, `PUT /help/{id}`, `DELETE /help/{id}` | `Admin\HelpController` |
| Reports | `GET /reports/revenue`, `/supplier`, `/region` | `Report\ReportController` |
| Monitoring | `GET /monitor/dashboard`, `/resources/{id}` | `Monitor\MonitorController` |
| Audit | `GET /audit-logs` | `Admin\SystemController` |
| System Config | `PUT /system/config` | `Admin\SystemController` |

### 대시보드 데이터 (Service 레이어)

`Admin\DashboardController::index()`가 실제 운영 지표 제공:

```php
[
    'today_stats' => [todayOrders, todayRevenue, newUsers, activeResources],
    'revenue_trend_30d' => [...],   // 최근 30일 일별 매출
    'region_distribution' => [...],  // 지역별 활성 리소스
    'pending_orders' => ...,         // 결제 대기 주문
    'pending_kyc' => ...,            // 검토 대기 KYC 제출물
    'open_tickets' => ...,           // 열림 또는 진행 중 티켓
]
```

### Admin 패널 대시보드 뷰 (`app/view/index/dashboard.html`)

- **애니메이션 통계 카드 8개**: 오늘/이번 주/이번 달/전체 사용자 + 오늘 주문 + 오늘 매출 + 결제 대기 주문 + 활성 리소스 — 각각 Layui `count` 모듈로 카운트업 애니메이션
- **ECharts 차트 3개**:
  1. 7일 사용자 가입 추세 — 영역 라인 차트
  2. 30일 사용자 가입 추세 — 막대 차트
  3. 사용자 요약 — 도넛/파이 차트 (오늘 / 이번 주 / 이번 달)
- **시스템 정보 테이블**: PHP/Workerman/Webman/Admin/MySQL/OS 버전으로 동적 채움
- **툴바**: PDF 내보내기 및 새로고침 버튼
- 모든 데이터는 `/app/admin/dashboard/data`에서 AJAX로 가져옴

### 라우트

```
Route::any('/app/admin/dashboard/data', [DashboardController::class, 'index']);
```

명시적으로 등록된 라우트 외에, `admin/config/route.php`는 `app/controller/`의 각 컨트롤러 공개 메서드에 대해 `/app/admin/{snake_case_controller}/{action}` 라우트를 자동 등록（예: `/app/admin/order_item/index`）, URL과 메뉴가 사용하는 snake_case 컨트롤러 이름과 일치；`/app/admin`과 `/app/admin/index`는 백오피스 홈/로그인 페이지 진입점（미로그인 시 로그인 뷰 렌더링）；일치하지 않는 요청은 일괄 404 반환.

## PDF 내보내기

대시보드 페이지의 클라이언트 측 PDF 생성:

- **html2canvas 1.4.1** (CDN)로 대시보드 DOM을 캔버스로 캡처
- **jsPDF 2.5.1** (CDN)로 다운로드 가능한 A4 PDF 생성
- 통계 카드와 ECharts 차트 (캔버스 요소로 렌더링) 캡처
- PDF에 제목, 타임스탬프, 브랜딩 포함
- 대시보드 툴바의 "Export PDF" 버튼으로 트리거

```
Dashboard DOM → html2canvas screenshot → jsPDF document → browser download
```

### 구현

```javascript
// In dashboard.html
html2canvas(document.querySelector('.layui-body'), {scale: 2}).then(canvas => {
    const pdf = new jsPDF('p', 'mm', 'a4');
    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 10, 10, 190, 0);
    pdf.save('dashboard_' + new Date().toISOString().slice(0, 10) + '.pdf');
});
```

## 테스트 스위트

```
PHPUnit 11.5 | 67 tests | 124 assertions
```

### HashidsTest (21 tests)
- encode/decode 왕복 (0에서 PHP_INT_MAX까지)
- 결정적 인코딩
- 잘못된/빈 문자열 처리
- `hashids_encode_ids` 필드 패턴 (`id`, `*_id`, `*_ids`)
- 0/음수 건너뛰기, 숫자 문자열 지원
- 중첩 배열 재귀, 비-ID 필드 보존

### BaseJsonTest (13 tests)
- `json()`/`success()`/`fail()`이 hashids 인코딩 적용
- 중첩 객체 인코딩
- Snowflake 크기 ID 처리
- 비-ID 필드 보존
- 0 처리
- 응답 구조 검증

### CrudHashidsTest (14 tests)
- `selectInput`: `id`/`*_id` WHERE 필드의 hashid 디코드
- `selectInput`: 숫자 문자열/원시 int 패스스루
- `updateInput`: hashid PK 디코드
- `updateInput`: 숫자 문자열 PK를 int로 캐스트
- `deleteInput`: 배치 ID 디코드, 혼합 타입
- `deleteInput`: 빈 배열, 단일 ID 처리

## 데이터베이스 마이그레이션 시스템

### 아키텍처

`service/`와 `admin/` 인스턴스 모두 `illuminate/database` Schema Builder 기반 독립 마이그레이션 시스템 보유. 각 인스턴스는 `config/command.php`를 통해 Symfony Console 명령을 등록하며, webman 콘솔 러너가 검색 가능.

```
php webman migrate          # 대기 중인 마이그레이션 실행
php webman migrate:rollback # 마지막 배치 롤백
php webman migrate:status   # 마이그레이션 상태 표시
```

### MigrationRunner (`service/support/MigrationRunner.php`, `admin/app/common/MigrationRunner.php`)

두 인스턴스가 공유하는 핵심 엔진:

- **`ensureTable()`** — 첫 실행 시 `migrations` 추적 테이블 생성 (id, migration name, batch number)
- **`migrate()`** — `database/migrations/`에서 마이그레이션 파일 스캔, 대기 중인 `up()` 메서드 실행, 배치 기록
- **`rollback()`** — 각 마이그레이션의 `down()`을 역순 호출해 마지막 배치 롤백
- **`status()`** — 모든 마이그레이션과 배치 번호 나열
- **`resolve()`** — 파일에서 마이그레이션 클래스 인스턴스화

### 마이그레이션 베이스 클래스 (`service/support/Migration.php`, `admin/app/common/Migration.php`)

```php
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
```

각 마이그레이션 파일은 `Migration`을 상속한 클래스를 반환하며 타임스탬프 접두사 파일명 사용（예: `2024_01_01_000001_create_initial_schema.php`）.

### Service 마이그레이션

**디렉터리**: `service/database/migrations/` — 마이그레이션 파일 38개（테이블명 erik_ 접두사 없음, admin 모델이 직접 매핑）

| 마이그레이션 | 테이블 |
|-----------|--------|
| `0001_create_users_tables` | users, user_profiles, user_kyc, user_balance, user_balance_log, user_addresses, refresh_tokens |
| `0002_create_product_tables` | product_categories, regions, products, product_skus, product_regions, product_images, product_attributes, product_reviews |
| `0003_create_order_tables` | carts, orders, order_items, order_timeline, order_invoices, refunds |
| `0004_create_payment_tables` | payment_channels, payment_transactions, payment_reconcile |
| `0005_create_provisioning_tables` | resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis |
| `0006_create_host_tables` | host_machines, ip_pools, ip_allocations, disks, disk_resizes |
| `0007_create_supplier_tables` | suppliers, supplier_products, supplier_settlements, supplier_withdraws |
| `0008_create_domain_tables` | domain_tlds, domain_transfers, dns_zones, dns_records |
| `0009_create_ticket_notification_tables` | tickets, ticket_messages, notifications, notification_templates |
| `0010_create_audit_table` | audit_logs |
| `0011_create_kvm_service_tables` | network_services, firewall_services, switch_services |
| `2024_01_01_000001_create_initial_schema` | `Capsule::unprepared()`로 `docs/database.sql` 실행, `down()`에서 전체 삭제 |
| `2025_05_16_000002_add_fcm_token_to_users` | users에 `fcm_token`, `fcm_platform` 컬럼 + 인덱스 추가 |
| `2026_08_26_000003_widen_encrypted_columns` | users.phone / user_addresses.phone / suppliers.contact_phone VARCHAR(20)→VARCHAR(255)（Encryptable 암호문 길이） |

### Admin 마이그레이션

**디렉터리**: `admin/database/migrations/` — 마이그레이션 파일 1개

| 마이그레이션 | 설명 |
|-----------|-------------|
| `2024_01_01_000001_create_admin_schema` | `Capsule::unprepared()`로 `admin/install.sql` 실행 — 시드 데이터 포함 wa_* 테이블 생성 |

### 콘솔 명령 등록

**`service/config/command.php`**:
```php
return [
    \App\Command\MigrateCommand::class,
    \App\Command\MigrateRollbackCommand::class,
    \App\Command\MigrateStatusCommand::class,
];
```

**`admin/config/command.php`** — `app\command` 네임스페이스에서 동일 패턴.

## Stripe 프로덕션 연동

### 아키텍처

가짜 `random_bytes()` 결제 ID를 `stripe/stripe-php` ^15.0 기반 실제 Stripe API 연동으로 교체.

**파일**: `service/app/payment/service/channels/StripeChannel.php`

```
Client-side                    Server-side                    Stripe API
───────────                    ───────────                    ──────────
Select Stripe at checkout
  → POST /orders/{id}/pay
    → StripeChannel::createPaymentIntent()
      → StripeClient->paymentIntents->create(amount, currency)
        ← {id, client_secret}
      → pi_xxx를 transaction_no로 저장
      ← client_secret 반환
  → Stripe.js confirmCardPayment(client_secret)
    ← Stripe가 결제 확인
      → POST /payments/webhook/stripe
        → StripeChannel::handleWebhook()
          → Webhook::constructEvent(payload, signature, secret)
          → 멱등성 검증 (pending 아닌 트랜잭션 건너뛰기)
          → 주문 상태 업데이트, 트랜잭션 레코드 생성
```

### PaymentIntent 생성

```php
public function createPaymentIntent(Order $order): array
{
    $intent = $this->stripe()->paymentIntents->create([
        'amount'   => (int) round($order->total * 100),  // cents
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id, 'order_no' => $order->order_no],
    ]);
    return [
        'transaction_no' => $intent->id,          // pi_xxxxxxxxxxxxx
        'client_secret'  => $intent->client_secret, // pi_xxx_secret_yyy
    ];
}
```

- `$this->stripe()`가 env의 `STRIPE_SECRET_KEY`로 `\Stripe\StripeClient` 지연 초기화
- env 변수 미설정 시 `$this->channel->api_key_encrypted`로 폴백 (Encryptable로 복호화)
- 금액을 센트로 변환: `(int) round($order->total * 100)`

### Webhook 서명 검증

```php
public function handleWebhook(string $payload, string $signature): void
{
    $event = \Stripe\Webhook::constructEvent(
        $payload, $signature, $this->channel->webhook_secret_encrypted
    );
    // 멱등성: 이미 처리된 트랜잭션이면 건너뛰기
    $existing = Transaction::where('transaction_no', $event->id)->first();
    if ($existing && $existing->status !== 'pending') return;
    
    match ($event->type) {
        'payment_intent.succeeded' => $this->confirmPayment($event),
        'payment_intent.payment_failed' => $this->failPayment($event),
        default => null,
    };
}
```

- `Webhook::constructEvent()`로 Stripe 서명 헤더 검증
- **멱등성 가드**: `transaction_no`로 중복 webhook 전달 확인
- 성공/실패 이벤트 타입 모두 지원

## Twilio SMS 연동

### 아키텍처

`error_log()` 스텁을 `twilio/sdk` ^8.0 기반 실제 SMS 전송으로 교체.

**파일**: `service/app/notification/queue/SmsSender.php`

### 메시지 전송

```php
public function consume(): void
{
    $client = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );
    $message = $client->messages->create(
        $this->notification->recipient_phone,
        ['from' => getenv('TWILIO_PHONE_NUMBER'), 'body' => $this->notification->body]
    );
    $this->notification->provider_message_id = $message->sid;
}
```

### 오류 처리

- `Twilio\Exceptions\RestException` 캐치 — Twilio 오류 코드와 메시지 포착
- `send_status = 'failed'`인 실패 Notification 레코드 생성
- 전송 추적용 `provider_message_id` (Twilio SID) 기록
- Twilio 자격증명 미설정 시 `error_log()`로 폴백 (dev 모드)

### 구성

Env 변수: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER`

## FCM 푸시 연동

### 아키텍처

`error_log()` 스텁을 `kreait/firebase-php` ^7.0 기반 실제 푸시 전송으로 교체.

**파일**: `service/app/notification/queue/PushSender.php`

### 디바이스 토큰 저장

마이그레이션으로 `users` 테이블에 추가:
- `fcm_token VARCHAR(512) DEFAULT NULL` — 디바이스 등록 토큰
- `fcm_platform VARCHAR(16) DEFAULT NULL` — `ios` / `android` / `web`
- `INDEX idx_fcm_token (fcm_token)` — 토큰 조회

User 모델: `fcm_token`과 `fcm_platform`을 `$fillable`에 추가.

### 푸시 전송

```php
public function consume(): void
{
    $factory = new \Kreait\Firebase\Factory();
    if ($credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH')) {
        $factory = $factory->withServiceAccount($credentialsPath);
    }
    $messaging = $factory->createMessaging();
    
    $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget(
        'token', $this->user->fcm_token
    )->withNotification([
        'title' => $this->notification->title,
        'body'  => $this->notification->body,
    ]);
    
    $result = $messaging->send($message);
}
```

### 토큰 정리

- `Kreait\Firebase\Exception\Messaging\InvalidToken` 캐치 — 사용자 `fcm_token` null로 설정
- `Kreait\Firebase\Exception\Messaging\NotFound` 캐치 — 미등록 토큰 제거
- Firebase 자격증명 미설정 시 `error_log()`로 폴백 (dev 모드)

### 구성

Env 변수: `FIREBASE_CREDENTIALS_PATH` (서비스 계정 JSON), `FCM_SERVER_KEY` (레거시)

## 비즈니스 흐름 다이어그램

### 주문 → 결제 → 프로비저닝 (핵심 비즈니스 흐름)

![주문 결제 프로비저닝 흐름](diagrams/order-payment-provisioning.svg)

### 이벤트 기반 프로비저닝 상세

![이벤트 기반 프로비저닝](diagrams/provisioning-detail.svg)

### 알림 디스패치

![알림 디스패치](diagrams/notification-dispatch.svg)

### 공급업체 라이프사이클

![공급업체 라이프사이클](diagrams/supplier-lifecycle.svg)

### 티켓 라이프사이클

![티켓 라이프사이클](diagrams/ticket-lifecycle.svg)

## Service 레이어 테스트 스위트

### 개요

```
PHPUnit 10.5 | 295 tests | 455 assertions
```

**디렉터리**: `service/tests/` — 7개 모듈에 걸친 테스트 파일 12개

**구성**: `service/phpunit.xml` — 단일 `unit` 테스트 스위트, `app/`과 `common/` 소스 커버

### 테스트 부트스트랩

`service/tests/bootstrap.php`는 Composer autoload를 로드하고 테스트 대상 코드가 필요한 전역 헬퍼 2개 정의:

- `request_id()` — 고유 요청 ID 문자열 반환
- `now()` — 현재 `DateTime` 객체 반환

중요 학습: `Webman\Config`는 테스트 컨텍스트에서 로드 불가 — `loadFromDir()`가 `route.php`를 트리거하고, 이는 null 상태에서 `Route::addRoute()`를 호출하기 때문. 테스트는 Config를 완전히 우회 — `HashidServiceTest`는 `new Hashids()` 직접 사용, `ResponseTest`는 로컬 헬퍼 메서드 사용.

### 테스트 파일

| 파일 | 테스트 | 커버리지 |
|------|-------|----------|
| `Captcha/CaptchaServiceTest.php` | 9 | create 구조, 난이도 레벨, verify 통과/실패, 일회용, 고유 키 |
| `Confirmation/ConfirmationMiddlewareTest.php` | 11 | 인증 필수, 비밀번호 누락, 잘못된 비밀번호, 성공 패스스루, 빈도 제한 키 형식, 잠금 키 형식, 최대 실패 임계값 |
| `Common/HashidServiceTest.php` | 17 | encode/decode 왕복, 결정성, 솔트 격리, 재귀 ID 워크 |
| `Common/ResponseTest.php` | 16 | success/error/페이지네이션 구조, request_id 일관성, HTTP 오류 코드 |
| `Common/SnowflakeTest.php` | 6 | 타임스탬프 순서, 고유성, bigint 범위, 초기화 패턴 |
| `Common/ValidatorTest.php` | 22 | required(), email(), minLength() 검증 규칙 |
| `Common/LogSanitizerTest.php` | 34 | PII 비식별화, 중첩 배열, 대소문자 무시 매칭, 민감 필드 유형 20종 |
| `Payment/StripeChannelTest.php` | 19 | 채널 구성, 금액 계산, webhook 서명, 멱등성 |
| `Payment/PaymentRouterTest.php` | 10 | 채널 필터링, 금액 제약, 통화/지역 지원, 수수료 계산 |
| `Notification/NotificationDispatcherTest.php` | 8 | 템플릿 렌더링, 채널 라우팅, 비활성 사용자 건너뛰기 |
| `Provisioning/ProviderFactoryTest.php` | 12 | register, create, createFromResource, 오류 케이스 |
| `Provisioning/RetryLogicTest.php` | 12 | 지수 백오프, 최대 재시도, 상태 전환, 호스트 선택 |
| `ClientPlatform/ClientPlatformMiddlewareTest.php` | 13 | 유효 플랫폼, 누락/기본 헤더, 미지원 플랫폼, 대소문자 무시, 비-API 건너뛰기, admin 라우트, 하위 접근 |
| `Security/WafMiddlewareTest.php` | 37 | SQLi (4), XSS (6), CMDi (4), 파일 포함 (3), 헤더 주입/CRLF (2), SSRF (5), NoSQL 주입 (4), 오픈 리다이렉트 (2), 안전 패스스루 (5), URL 스캔, UA 스캔 |
| `Version/VersionMiddlewareTest.php` | 6 | 유효 버전, 누락 버전 기본값, 미지원 버전 400, 비-API 건너뛰기, admin API 검증, 오류 응답 헤더 |

### 테스트 인프라

- `tests/TestCase.php` — PHPUnit TestCase 상속 베이스 클래스
- `tests/Support/RequestMock.php` — 생성자 주입 파라미터로 모의 요청

## CI/CD 파이프라인

### 아키텍처

`.github/workflows/ci.yml`의 GitHub Actions 워크플로.

**트리거**: `main` 푸시, `main` 풀 리퀘스트

### 잡

| 잡 | 전략 | 설명 |
|-----|----------|-------------|
| `syntax` | PHP 8.2 | admin/와 service/의 모든 `.php` 파일 `php -l` 린트 |
| `admin-tests` | PHP 8.2, 8.3 | `composer install -d admin` → `admin/vendor/bin/phpunit` |
| `service-tests` | PHP 8.2, 8.3 | `composer install -d service` → `service/vendor/bin/phpunit` |
| `composer-validate` | PHP 8.2 | 두 composer.json 파일에 `composer validate --strict` |

### PHP 버전 매트릭스

두 테스트 잡 모두 `shivammathur/setup-php@v2`로 PHP 8.2와 8.3에서 실행.

### 현재 상태

4개 잡 모두 통과: 총 243 tests (67 admin + 176 service), 400 assertions, 두 PHP 버전 모두 그린.

## 데이터베이스 엔티티 관계

![데이터베이스 엔티티 관계](diagrams/database-er.svg)

## 핵심 설계 결정

1. **독립 인스턴스**: admin/은 service/ 내 플러그인이 아닌 자체 webman 인스턴스로 실행. admin 트래픽과 장애를 고객용 API에서 격리.

2. **Encryptable + 비밀번호 해시**: 비밀번호는 먼저 bcrypt 해시 후 AES 암호화. Encryptable 캐스트는 Eloquent 레벨 (해시 위)에서 동작하므로 레이어링은: `input → bcrypt hash → model attribute set → Encryptable::set() encrypts → DB`. 읽기 시: `DB → Encryptable::get() decrypts → bcrypt hash → password_verify()`.

3. **컨트롤러 경계의 Hashids**: 인코딩/디코딩은 HTTP 경계 (컨트롤러)에서 발생하며 모델/ORM 레벨이 아님. 모델을 DB 독립적으로 유지하고 hashids를 순수 표현 관심사로 만듦.

4. **컨테이너 기반 서비스 해석**: 서비스 (Snowflake, HashidsManager, EncryptionManager)는 worker 시작 시 Bootstrap 클래스를 통해 싱글턴으로 등록. `\support\Container::instance()`의 컨테이너 해석은 지연 인스턴스화 사용 — 서비스는 첫 접근 시에만 생성.

## 확장 기능 (2026-05-20)

### Service Admin API — 신규 엔드포인트

| 그룹 | 엔드포인트 | 컨트롤러 |
|-------|-----------|------------|
| Invoices | `GET /admin/api/invoices`, `POST .../invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Provider APIs | `GET/POST /admin/api/providers`, `PUT/DELETE .../providers/{id}` | `Admin\ProviderApiController` |
| 공급업체 API Key | `GET/POST /admin/api/suppliers/{id}/api-keys`, `DELETE .../api-keys/{id}` | `Admin\SupplierController` |
| Coupons | `GET/POST /admin/api/coupons`, `DELETE .../coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET/POST/DELETE /admin/api/webhooks`, `POST .../webhooks/test` | `Admin\WebhookController` |
| 상품 가져오기/내보내기 | `GET /admin/api/products/export`, `POST .../products/import` | `Admin\ImportExportController` |
| 도메인 관리 | `GET/POST/PUT/DELETE /admin/api/domains/tlds`, `GET .../zones`, `GET .../transfers`, `POST .../transfers/{id}/approve` | `Admin\DomainController` |
| 알림 템플릿 | `GET /admin/api/notifications/templates`, `PUT .../templates/{id}`, `GET .../log` | `Admin\NotificationController` |
| 도움말 문서 | `GET/POST /admin/api/help`, `PUT/DELETE .../help/{id}` | `Admin\HelpController` |

### 신규 미들웨어

| 미들웨어 | 용도 |
|------------|---------|
| `VersionMiddleware` | X-Api-Version 헤더에서 API 버전 읽고 검증 |
| `RateLimitMiddleware` | Redis 토큰 버킷 빈도 제한（기본 60req/min, 로그인 5req/min） |
| `GeoBlockMiddleware` | MaxMind GeoIP2 지역 차단 |
| `MaintenanceMiddleware` | 유지보수 모드（환경 변수 스위치 + IP 화이트리스트） |
| `ClientPlatformMiddleware` | 클라이언트 플랫폼 식별（X-Client-Platform 헤더）, 플랫폼 8종 지원 |
| `SupplierApiKeyMiddleware` | 공급업체 외부 API 인증（sk_xxx Key SHA256 검증） |
| `WafMiddleware` (admin) | Admin 패널 WAF 미들웨어, 8종 45+ 규칙 + 요청 크기 제한 + Content-Type 검증 |

### 예약 작업

| 일정 | 작업 | 용도 |
|----------|------|---------|
| `13 */4 * * *` | ExchangeRateSync | 환율 업데이트 |
| `37 2 * * *` | PaymentReconcile | 일일 결제 대조 |
| `17 4 * * 1` | SupplierSettlement | 주간 공급업체 정산 |
| `23 6 * * *` | ExpirationCheck | 리소스/도메인 만료 확인 + 알림 |
| `43 7 * * *` | SslCertificateCheck | SSL 인증서 만료 확인 + 알림 |
| `*/5 * * * *` | CollectMetrics | 리소스 지표 수집 |
| `*/30 * * * *` | CheckExpirations | 리소스 만료 확인 |

### CLI 명령

| 명령 | 용도 |
|---------|---------|
| `php webman migrate` | 대기 중인 마이그레이션 실행 |
| `php webman migrate:rollback` | 마지막 배치 롤백 |
| `php webman migrate:status` | 마이그레이션 상태 조회 |
| `php webman db:backup` | DB를 SQL 파일로 백업（선택 --s3 업로드） |

### 추가된 DB 마이그레이션 (2026-05-20)

| 마이그레이션 | 테이블/컬럼 |
|-----------|---------------|
| 000003 | users + totp_secret, totp_enabled |
| 000004 | coupons, user_coupons |
| 000005 | help_articles, supplier_api_keys |
| 000006 | roles, permissions, role_permission + 시드 데이터 |
| 000007 | users + email_verified_at, email_verify_token |
| 000008 | users + last_login_ip, last_login_at |

## 문서 인덱스

### 핵심 문서

| 문서 | 경로 | 설명 |
|----------|------|-------------|
| 아키텍처 설계 문서 | `docs/architecture.md` | 시스템 아키텍처, 컴포넌트 관계, 미들웨어 파이프라인, 보안 계층, 데이터 아키텍처, 배포 토폴로지 |
| 기능 설계 문서 | `docs/features.md` | 21개 모듈 상세 기능 설계, 흐름도, 데이터 모델, 상호작용 설명 포함 |
| API 인터페이스 문서 | `docs/api-reference.md` | 엔드포인트 200+ 전체 참조, 모듈별 그룹핑, 요청/응답 예시, 오류 코드 포함 |
| API 온라인 문서 (service) | `http://localhost:8787/apidoc` | hg/apidoc 자동 생성, 기능별 그룹핑, 온라인 디버그 지원 |
| API 온라인 문서 (admin) | `http://localhost:8788/apidoc` | hg/apidoc 자동 생성, 컨트롤러 54개 기능 그룹 13개 |
| 시스템 설계 명세 | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` | 전체 아키텍처, 데이터 모델, API 설계, 보안 전략 |
| 관리 백오피스 설계 | `docs/admin-design.md` | Admin 패널 아키텍처, 패키지 연동, ACL 권한, 테스트 스위트 |
| 공급업체 API 문서 | `docs/supplier-api.md` | 공급업체 API 참조（내부 API + 외부 API）, SDK 예시 |
| 배포 체크리스트 | `docs/deployment.md` | 서버 구성, 환경 변수, DB 마이그레이션, Nginx, HTTPS, 예약 작업 |

### 구현 계획

| 문서 | 경로 | 설명 |
|----------|------|-------------|
| Phase 0 — 기초 프레임워크 | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase0.md` | 프로젝트 골격, 디렉터리 구조, 핵심 인프라 |
| Phase 1 — 사용자와 쇼핑몰 | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase1.md` | 사용자 인증, 상품 관리, 장바구니, 주문 |
| Phase 2 — 리소스와 공급업체 | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase2.md` | 리소스 개통, DNS, 공급업체 입점 |
| Phase 3 — 클라이언트와 인도 | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase3.md` | Flutter 클라이언트, 멀티 플랫폼 적응, CI/CD |

### 도구와 리소스

| 문서 | 경로 | 설명 |
|----------|------|-------------|
| API 스모크 테스트 | `docs/api-test.sh` | curl 기반 API 엔드포인트 자동화 테스트 스크립트 |
| DB DDL | `docs/database.sql` | 데이터베이스 테이블 생성 문 |

## 최종 테스트 통계

```
OK (362 tests, 579 assertions)
Test files: 22
```
- Admin: 67 tests, 124 assertions
- Service: 295 tests, 455 assertions
