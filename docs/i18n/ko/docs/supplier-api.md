# 공급업체 API 문서 v1

## 개요

공급업체 기능은 두 가지 API를 제공합니다:

| 유형 | 인증 방식 | 접두사 | 상태 |
|------|---------|------|------|
| **내부 API** | 사용자 Bearer Token | `/api/supplier/` | 사용 가능 |
| **외부 API** | API Key (`sk_xxx`) | `/api/supplier/external/` | 사용 가능 |

**Base URL**: `https://api.example.com`

**버전 관리**: HTTP 헤더 `X-Api-Version: v1`로 지정. 누락 시 기본 `v1`, 지원하지 않는 버전은 `400` 반환. `/api/*`와 `/admin/api/*` 경로에만 적용되며, `VersionMiddleware`가 일괄 처리.

---

## 내부 API（현재 사용 가능）

내부 API는 플랫폼의 다른 인터페이스와 동일한 사용자 Bearer Token 인증을 사용하며, 로그인한 공급업체 사용자가 클라이언트/프론트엔드에서 호출하기에 적합합니다.

### 인증

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

사용자는 먼저 `/api/auth/login`으로 로그인해 Token을 받아야 하며, 계정 역할이 `supplier`여야 합니다（관리자가 공급업체 신청을 승인한 후 설정됨）.

---

### 응답 형식

#### 성공 응답

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### 페이지네이션 응답

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### 오류 응답

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | 설명 |
|------|------|
| 0 | 성공 |
| 400 | 요청 파라미터 오류 / 지원하지 않는 API 버전 |
| 401 | 미로그인 또는 Token 만료 |
| 403 | 접근 권한 없음（비공급업체 역할 / 비밀번호 재확인 실패） |
| 404 | 리소스 없음 |
| 422 | 파라미터 검증 실패 |
| 429 | 요청 빈도 초과 |

---

### 엔드포인트

#### 1. 공급업체 입점

```
POST /api/supplier/apply
```

공급업체 신청. 사용자당 한 번만 신청 가능.

**요청 본문**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| 파라미터 | 타입 | 필수 | 설명 |
|------|------|------|------|
| company_name | string | 예 | 회사명 |
| contact_name | string | 예 | 담당자 이름 |
| contact_phone | string | 예 | 연락처 |
| contact_email | string | 예 | 연락 이메일 |
| settlement_method | string | 아니요 | 정산 방식, 기본 `bank` |

**응답**: 공급업체 객체, 상태 `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> 민감 필드（담당자 이름, 전화, 이메일）는 데이터베이스에 암호화 저장, API 반환 시 부분 마스킹.

**오류**:

| code | 시나리오 |
|------|------|
| 422 | 이미 공급업체 신청 제출함 |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. 상품 관리

##### 할당된 상품 조회

```
GET /api/supplier/products
```

**Query 파라미터**:

| 파라미터 | 타입 | 필수 | 설명 |
|------|------|------|------|
| page | int | 아니요 | 페이지 번호, 기본 1 |

**응답**: 페이지네이션 목록, 각 항목에 상품 정보와 커미션 비율 포함

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### 상품 추가

```
POST /api/supplier/products
```

기존 상품을 현재 공급업체에 연결.

**요청 본문**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| 파라미터 | 타입 | 필수 | 설명 |
|------|------|------|------|
| product_id | string | 예 | 상품 ID（Hashid） |
| commission_rate | float | 아니요 | 커미션 비율, 기본 0.1 |

**응답**: 생성된 SupplierProduct 객체

**오류**:

| code | 시나리오 |
|------|------|
| 422 | 이미 해당 공급업체에 할당된 상품 |

##### 상품 제거

```
DELETE /api/supplier/products/{id}
```

상품과 공급업체의 연결 해제.

**응답**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. 정산 관리

##### 정산서 목록 조회

```
GET /api/supplier/settlements
```

**응답**: 현재 공급업체의 모든 정산서, 생성 시간 역순

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| 필드 | 설명 |
|------|------|
| total_sales | 기간 내 완료된 주문의 총 매출액 |
| commission | 플랫폼 커미션 총액 |
| payable | 공급업체 지급액（total_sales - commission） |
| status | `pending` / `paid` |

---

#### 4. 출금

##### 출금 신청

```
POST /api/supplier/withdraw
```

> 이 작업은 비밀번호 재확인（`confirm_password` 필드）이 필요하며, `ConfirmationMiddleware`가 검증.
> 5회 실패 시 15분 잠금.

**요청 본문**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| 파라미터 | 타입 | 필수 | 설명 |
|------|------|------|------|
| amount | string | 예 | 출금액（문자열로 부동소수점 정밀도 문제 방지） |
| confirm_password | string | 예 | 사용자 로그인 비밀번호（재확인） |
| account_info | object | 예 | 수취 계좌 정보 |
| account_info.method | string | 예 | 출금 방식：`bank_transfer` / `alipay` / `wechat` |

**출금 가능 잔액 계산**: 완료된 모든 정산서 `payable` 합 - 처리 중인 모든 출금 `amount` 합

**응답**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**오류**:

| code | 시나리오 |
|------|------|
| 422 | 출금 가능 잔액 부족 |
| 403 | 비밀번호 재확인 실패 |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### 내부 API 엔드포인트 요약

| 메서드 | 경로 | 인증 | 비밀번호 재확인 | 설명 |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | 공급업체 신청 |
| GET | `/api/supplier/products` | Token | - | 할당된 상품 조회 |
| POST | `/api/supplier/products` | Token | - | 상품 연결 추가 |
| DELETE | `/api/supplier/products/{id}` | Token | - | 상품 연결 제거 |
| GET | `/api/supplier/settlements` | Token | - | 정산서 조회 |
| POST | `/api/supplier/withdraw` | Token | 필요 | 출금 신청 |

---

## 외부 API（설계 명세, 구현 예정）

외부 API는 공급업체가 프로그래밍 방식으로 주문, 리소스, 정산을 관리할 수 있게 합니다. 모든 요청은 API Key 인증 필요.

**Base URL**: `https://api.example.com/api`

### 인증

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

API Key는 플랫폼 관리자가 관리 백오피스 `공급업체 관리 → API Keys`에서 생성.

**보안 요구사항**:
- HTTPS로만 접근
- API Key는 생성 시 한 번만 표시되며 잘 보관할 것
- 서버 IP 화이트리스트 등록 권장

---

### 응답 형식

내부 API와 동일하며, 추적용 `request_id` 추가:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### 엔드포인트

#### 1. 주문 관리

##### 주문 목록 조회

```
GET /api/supplier/orders
```

**Query 파라미터**:

| 파라미터 | 타입 | 필수 | 설명 |
|------|------|------|------|
| page | int | 아니요 | 페이지 번호, 기본 1 |
| page_size | int | 아니요 | 페이지당 수량, 기본 20, 최대 50 |
| status | string | 아니요 | 상태 필터: pending/paid/completed/refunded |
| from | date | 아니요 | 시작 날짜 YYYY-MM-DD |
| to | date | 아니요 | 종료 날짜 YYYY-MM-DD |

##### 주문 상세 조회

```
GET /api/supplier/orders/{id}
```

---

#### 2. 리소스 관리

##### 리소스 목록 조회

```
GET /api/supplier/resources
```

**Query 파라미터**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### 리소스 상태 조회

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. 정산 관리

##### 정산서 목록 조회

```
GET /api/supplier/settlements
```

##### 정산서 상세 조회

```
GET /api/supplier/settlements/{id}
```

---

#### 4. 출금

##### 출금 신청

```
POST /api/supplier/withdraw
```

##### 출금 기록

```
GET /api/supplier/withdraws
```

---

#### 5. 상품 관리

##### 내 상품 조회

```
GET /api/supplier/products
```

##### 상품 상장 신청 제출

```
POST /api/supplier/products
```

---

### 외부 API 엔드포인트 요약

| 메서드 | 경로 | 설명 |
|------|------|------|
| GET | `/api/supplier/orders` | 주문 목록 |
| GET | `/api/supplier/orders/{id}` | 주문 상세 |
| GET | `/api/supplier/resources` | 리소스 목록 |
| GET | `/api/supplier/resources/{id}/status` | 리소스 상태 |
| GET | `/api/supplier/settlements` | 정산서 목록 |
| GET | `/api/supplier/settlements/{id}` | 정산서 상세 |
| POST | `/api/supplier/withdraw` | 출금 신청 |
| GET | `/api/supplier/withdraws` | 출금 기록 |
| GET | `/api/supplier/products` | 상품 목록 |
| POST | `/api/supplier/products` | 상품 제출 |

---

## Webhook（플랫폼 이벤트 수신）

공급업체는 Webhook URL을 등록해 실시간 이벤트를 수신할 수 있습니다. 관리 백오피스에서 구성.

### 이벤트 유형

| 이벤트 | 트리거 시점 |
|------|----------|
| `order.paid` | 사용자 결제 완료 |
| `order.refunded` | 주문 환불됨 |
| `resource.provisioned` | 리소스 개통 완료 |
| `resource.expiring` | 리소스 만료 임박 (7일 이내) |
| `resource.destroyed` | 리소스 파기됨 |
| `settlement.created` | 정산서 생성됨 |
| `withdrawal.approved` | 출금 승인됨 |

### Webhook 요청 형식

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**서명 검증**: `HMAC-SHA256(payload, webhook_secret)`

---

## 빈도 제한

| 엔드포인트 | 제한 |
|------|------|
| 내부 API | 60 req/min 사용자당（기본） |
| 내부 API 로그인 | 5 req/min |
| 외부 API | 120 req/min API Key당（`supplier_api` 규칙, `RateLimitMiddleware` 경유 적용） |
| 외부 API 출금 | 10 req/min（권장값, `config/security.php`에서 조정 가능） |

> 외부 API 빈도 제한 규칙은 `config/security.php`의 `rate_limits.supplier_api`에 정의되어 있으며,
> `RateLimitMiddleware`가 `/api/supplier/external/*` 경로에 대해 일괄 실행（원자 INCR 카운트,
> Redis 불가 시 방출）.

빈도 제한 헤더:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK 예시

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// 공급업체 신청
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// 정산서 조회
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// 출금 신청
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
    'X-Api-Version': 'v1',
}

# 할당된 상품 조회
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# 출금 신청
resp = requests.post('https://api.example.com/api/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## 오류 처리 권장사항

1. **429 빈도 제한**: `Retry-After`초 대기 후 재시도
2. **401 미인증**: Token 유효성/만료 여부 확인
3. **403 금지**: 계정 역할이 `supplier`인지 확인；비밀번호 재확인 실패는 잠금 해제 대기
4. **422 검증 실패**: `message` 필드 기준으로 요청 파라미터 수정
5. **5xx 서버 오류**: 지수 백오프 재시도 (1s -> 5s -> 25s)

---

## 관리 백오피스 엔드포인트 참조

관리자가 공급업체를 관리하는 관련 엔드포인트（백오피스 전용, Admin 역할 필요）:

| 메서드 | 경로 | 설명 |
|------|------|------|
| GET | `/admin/api/suppliers` | 공급업체 목록（status 필터 지원） |
| GET | `/admin/api/suppliers/export` | 공급업체 Excel 내보내기 |
| POST | `/admin/api/suppliers/{id}/approve` | 공급업체 승인 |
| POST | `/admin/api/suppliers/{id}/settle` | 정산서 생성 |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | 출금 승인 |
| GET | `/admin/api/suppliers/{id}/api-keys` | 공급업체 API Key 목록 조회 |
| POST | `/admin/api/suppliers/{id}/api-keys` | API Key 생성（원본 Key 한 번만 반환） |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | API Key 폐기 |
