# API 개요

> 전체 인터페이스 레퍼런스（200+ 엔드포인트, 요청/응답 예시 및 오류 코드 포함）: [API 인터페이스 문서](api-reference.md)
> 온라인 디버깅: [service API 문서](http://localhost:8787/apidoc) · [admin API 문서](http://localhost:8788/apidoc)

## 공개 인터페이스

| 메서드 | 경로 | 설명 |
|------|------|------|
| GET | `/health` | 헬스 체크 |
| POST | `/api/v1/auth/register` | 사용자 가입（요청 본문 AES-256-GCM 암호화 필요） |
| POST | `/api/v1/auth/login` | 사용자 로그인（요청 본문 AES-256-GCM 암호화 필요） |
| POST | `/api/v1/auth/refresh` | Token 갱신（요청 본문 AES-256-GCM 암호화 필요） |
| POST | `/api/v1/captcha/create` | 클릭 캡차 생성（로그인/가입 전 획득） |
| GET | `/api/v1/products` | 제품 목록（카테고리/지역/키워드 필터링 지원） |
| GET | `/api/v1/products/{id}` | 제품 상세（id는 hashid 문자열） |
| GET | `/api/v1/regions` | 사용 가능한 지역 |
| GET | `/api/v1/domain/check/{domain}/{tld}` | 도메인 가용성 조회 |
| GET | `/api/v1/domain/tlds` | 등록 가능한 TLD 목록 |
| POST | `/api/v1/payments/webhook/stripe` | Stripe 콜백（서명 검증, 암호화 불필요） |

## 인증 인터페이스（Bearer Token 필요）

| 메서드 | 경로 | 설명 |
|------|------|------|
| GET | `/api/v1/user/profile` | 개인 정보 |
| PUT | `/api/v1/user/profile` | 정보 수정 |
| POST | `/api/v1/user/kyc` | 실명 인증 제출 |
| GET | `/api/v1/user/balance` | 계좌 잔액 |
| GET/POST | `/api/v1/cart` | 장바구니 |
| POST/GET | `/api/v1/orders` | 주문 |
| GET | `/api/v1/orders/{id}/payment-methods` | 사용 가능한 결제 수단 |
| POST | `/api/v1/orders/{id}/pay` | 결제 시작 |
| GET/POST | `/api/v1/resources` | 내 리소스 |
| GET | `/api/v1/resources/{id}/status` | 리소스 상태 |
| GET | `/api/v1/resources/{id}/console` | VNC 콘솔 링크 |
| GET/POST | `/api/v1/cdn/domains` | CDN 도메인 목록 / 생성（cloudflare \| cloudfront \| aliyun \| tencent） |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | CDN 도메인 상세 / 삭제 |
| POST | `/api/v1/cdn/domains/{id}/purge` | 캐시 삭제（멱등, 최대 100개 URL） |
| GET/POST | `/api/v1/tickets` | 티켓 |
| POST | `/api/v1/tickets/{id}/reply` | 티켓 답변 |
| GET/POST | `/api/v1/dns/{domain}` | DNS 관리 |
| POST | `/api/v1/supplier/apply` | 공급업체 신청 |
| GET | `/api/v1/supplier/settlements` | 공급업체 정산 기록 |
| POST | `/api/v1/supplier/withdraw` | 공급업체 출금 |

> **설명:** 모든 API 요청은 URL 경로로 버전을 지정합니다（예: `/api/v1/products`）. 인증 인터페이스와 관리자 인터페이스의 요청/응답은 모두 `EncryptionMiddleware`를 거칩니다. 클라이언트가 `X-Encrypted: 1` 요청 헤더를 설정하면 요청 본문 형식은 `{"payload": "<base64(AES-256-GCM)>"}`이며, 응답 본문도 암호화되어 `payload` 필드에 래핑됩니다. 모든 정수 ID는 API 응답에서 자동으로 12자리 Hashid 문자열로 변환되고, 요청의 Hashid 문자열은 `HashidRequestMiddleware`가 정수 ID로 자동 디코딩합니다.

## 관리자 인터페이스

| 메서드 | 경로 | 설명 |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | 운영 대시보드 |
| GET/PUT | `/admin/api/v1/users` | 사용자 관리 |
| GET/POST | `/admin/api/v1/kyc` | KYC 심사 |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | 제품 관리 |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU 생성 |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | 지역 가격 설정 |
| GET/POST | `/admin/api/v1/orders` | 주문 관리（환불 포함） |
| GET | `/admin/api/v1/orders/export` | 주문 내보내기 (.xlsx) |
| GET | `/admin/api/v1/users/export` | 사용자 내보내기 (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | 공급업체 내보내기 (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | 결제 채널 / 거래 / 대사 |
| GET/POST | `/admin/api/v1/provisioning/*` | 인도 작업 / 호스트 관리 |
| GET/PUT | `/admin/api/v1/cdn/domains` | CDN 도메인 관리（패키지 변경） |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | 서비스 제공업체 계정 자격 증명 관리（CDN/인도 공용, Encryptable 암호화） |
| GET/POST | `/admin/api/v1/suppliers/*` | 공급업체 승인 / 정산 / 출금 |
| GET/POST | `/admin/api/v1/tickets` | 티켓 배정 / 종결 |
| GET | `/admin/api/v1/reports/*` | 매출 / 지역 / 공급업체 리포트 |
| GET | `/admin/api/v1/monitor/*` | 모니터링 패널 / 리소스 메트릭 |
| GET | `/admin/api/v1/audit-logs` | 감사 로그 |
| PUT | `/admin/api/v1/system/config` | 시스템 구성 |
