# CloudPlatform 팀 플랜

> 버전: 2026-08-17（v2）｜v1은 멀티 에이전트 파이프라인으로 작성（PASS_WITH_FIXES）；v2는 Phase 0-2 실제 실행 결과를 기반으로 Lead가 업데이트
> 근거: v1 + Phase 0-2 전체 커밋（git 111 commits）+ 2인 검토 기록 + 실측 테스트 기준

## 1. 현황 개요（2026-08-17）

### 1.1 단계 완료도

| 단계 | 상태 | 핵심 산출물 |
|------|------|----------|
| Phase 0 안정화 | ✅ 4/4 | 인보이스 실제 렌더링, 알림 템플릿 6종, 대사 명시적 unverified, CSP 헤더/환경 템플릿 |
| Phase 1 단기 | ✅ 8/8 | 장바구니 수량 변경, 리뷰 상태 통일, 대사 실제화（Stripe 리포트+일별）、환불 조건 검증（72h/5일+멱등성+TOCTOU 인덱스）、공급업체 7종 webhook、Feature Flags 연결+관리자 측、문서 동기화、실제 테스트 |
| Phase 2 중기 | ✅ 8/8 | 자금 가드 4항목, service/admin 테스트 부채, install.sql 31개 테이블, RbacMiddleware 57개 라우트 마운트, admin 이미지 포함+nginx 8788+CI 양단, audit 회귀+login 전체 체인 |
| Phase 3 장기 | ✅ 9/9 | 게이트웨이+통합 빈도 제한（P4.1）、다중 통화 전체 체인（P4.2）、HarmonyOS 엔지니어링+CI（P4.3）、ES 도입（P4.4）、관찰 항목 소화（P4.5）、문서 이탈 4항목（P3.1）、권한 수렴（P3.2）、주문 멱등 키（P3.3）、공급업체 평점 검증（P3.4）、i18n 7개 언어（P3.6）；reviewer-gate 독립 재검토 전부 approve |

### 1.2 품질 기준（실측, 커밋 후 직렬 검증）

- service 스위트: **568 tests / 1279 assertions**, 10 skip（전부 DB 환경 갭）
- admin 스위트: **255 tests / 887 assertions**, 1 skip（DB 쓰기 경로）
- CI 6 job: PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check /（docker 관련）
- 자금/보안 전부 2인 검토（security-auditor + reviewer 독립 결론 일치）；git 작업 단위별 커밋, 작업 트리 클린
- 부수 수확: 9개 Encryptable 모델 자격증명 직렬화 숨김（P1/P2 전수 점검）

## 2. 잔여 및 리스크 목록（2026-08-17 재검토）

### 2.1 배포 차단 항목（우선순위 높음）

- **DB_PASSWORD 환경 갭**: service/.env가 빈 문자열 → 전체 DB 엔드포인트 500, 9+1개 skip 테스트의 근본 원인. 코드 문제가 아니라 운영에서 실값을 채워야 함（루트 .env.example에 이미 템플릿 있음）
- **HarmonyOS 프로젝트 스캐폴딩 부재**: apps/harmonyos에 .ets 3개뿐（LoginPage/AuthManager/ApiClient）, hvigor/DevEco 전체 프로젝트 구성 부재 → 빌드 불가; CI harmonyos-check가 정직하게 오류 보고（exit 1）

### 2.2 문서-코드 이탈（P1 미해결 4항목）

- GET /api/orders status 필터 미구현
- WebSocket 푸시 이벤트 누락（websocket_push 관련 문서에 선언 있음）
- ticket.updated 트리거 범위 불명확
- product_attributes 죽은 스키마（사용하는 코드 없음）

### 2.3 자금/보안 관찰 항목（2인 검토 기록, low 등급）

- **주문 멱등 키 없음**: 동일 cart 반복 제출 시 이중 주문 생성 가능（medium, 일정 편성 권장）
- 공급업체 평점이 주문 귀속/상태 검증 안 함
- fee bcmath 절단（5번째 소수 자리, 적게 수취 방향 <0.0001/건；라우팅과 일치하여 대사 편차 없음）
- WAF multipart 대용량 본문이 여전히 raw로 읽음（json 시나리오는 $input이 커버, multipart는 추가 방어면）
- user_coupons 고유 제약 없음（의미상 1사용자 다주문 다행 허용, 관찰）
- nginx-admin에 CSP 미적용（admin은 인라인 스크립트 포함 Layui 프론트, 현상 유지）

### 2.4 권한 모델 불일치（P2 신규 발견, 수렴 필요）

- DB-only 권한 식별자 6개 / Rbac-only 19개 / 역할 배정 차이（support/supplier）
- AdminRoleMiddleware가 finance를 제외하는데 Rbac.php에는 finance 역할 정의됨

### 2.5 기타

- i18n 신규 언어 파일이 영어 원문 그대로（T6）, 7개 언어 미완료
- HarmonyOS CI 구조 검사는 스캐폴딩 보완 후 실제 hvigor 빌드로 업그레이드 예정

## 3. 로드맵

우선순위 원칙（불변）: **자금/보안 > 인도 신뢰성 > 핵심 비즈니스 클로저 > 경험과 확장**.

### Phase 3 — 잔여 마무리（1개월）

**목표**: 전체 이탈과 관찰 항목 종결, 배포 재현 가능（DB 전체 체인 테스트 실측 그린）.

| 작업 | 관련 | 역할 | 의존 |
|------|------|------|------|
| 문서-코드 이탈 4항목 마무리（orders status 필터 구현 / WebSocket 푸시 연결 / ticket.updated 수정 / product_attributes 삭제 또는 실구현） | Order、WebSocket、Ticket、Product、docs | coder + researcher | 없음 |
| 권한 모델 수렴（DB/Rbac 차이 정렬 + 역할 시드 + AdminRoleMiddleware 재검토） | Rbac、install.sql、admin | coder + security-auditor | 없음 |
| 주문 멱등 키（cart→order 이중 주문 방지） | OrderService | coder | 없음（자금류 2인 검토） |
| 공급업체 평점 주문 귀속/상태 검증 | Supplier、Review | coder | 없음 |
| DB_PASSWORD 운영 연결 + 10개 skip 테스트 실측 | 운영、tests | security-auditor | 운영 협조 |
| i18n 7개 언어 번역 보완 | i18n 파일 | coder | 없음 |

**수락 기준**: 이탈 4항목 종결; 권한 매트릭스 DB/코드 일치; 멱등 키 테스트; DB 전체 체인 테스트 실측 그린; i18n 최소 중/영 사용 가능.

### Phase 4 — 아키텍처 진화（1-3개월）

**목표**: 4계층 아키텍처 완성, 다단말 다통화 성장 지원.

| 작업 | 관련 | 역할 | 의존 |
|------|------|------|------|
| 독립 API 게이트웨이 + 통합 빈도 제한 마운트（graphql 갭 포함） | gateway、route | architect + coder | P3 |
| 다중 통화 전체 체인 일관성（fee 반올림 전략 포함） | Payment、Billing | architect + performance-engineer | 상동 |
| HarmonyOS 엔지니어링: 스캐폴딩 + CI 실제 빌드 + 로그인 연동 | apps/harmonyos | mobile-dev | 없음 |
| ES 감사 도입, 우회 방안 대체 | docker、Product 검색 | coder | 없음 |
| 관찰 항목 일괄 소화（WAF multipart / user_coupons 제약 / 공급업체 webhook 엔드투엔드） | Security、Order、Supplier | coder + tester | 없음 |

**수락 기준**: k6로 빈도 제한 전 라우트 적용 검증; 다중 통화 정산 제로 오차; HarmonyOS 패키지 CI 통과; ES 검색 실제 사용 가능.

## 4. 팀 분업

고정 코어: Lead(planner) / architect / coder / tester / reviewer / researcher
필요 시 투입: mobile-dev / security-architect / security-auditor / performance-engineer

| 단계 | 투입 역할 | 설명 |
|------|----------|------|
| P3 | coder（주력）、researcher、security-auditor | 마무리 중심; 권한/멱등 2인 검토 |
| P4 | architect、coder、mobile-dev、performance-engineer | 아키텍처 진화; security-architect 상주 자문 |

협업 방식은 불변: CLAUDE.md 파이프라인（architect→coder→tester→reviewer）, P3/P4 내부 작업은 fan-out 병렬; **자금/보안 작업은 2인 검토 의무화**; 각 단계 종료 시 본 문서 업데이트（본 v2는 Lead가 직접 작성, 파이프라인 미경유, 재검토 가능）.

## 5. 리스크 추적 방식

- 본 목록은 단계 종료 시마다 롤링 업데이트; 신규 발견（P2의 권한 모델 불일치, 주문 멱등 등）은 즉시 병합
- 알려진 저우선순위 항목（공급업체 webhook 엔드투엔드, multipart body）은 P4 소화 배치에 포함, 목록 밖 확산 없음

## 6. 주요 근거 출처

- 커밋: git log（111 commits, Phase 0-2 작업 단위 그룹핑）
- 테스트 기준: service/admin 스위트 실측 출력
- 검토 기록: P1/P2 2인 검토 메시지（자금 가드, logout/WAF, RBAC, audit 회귀）
- 문서: v1（docs/team-plan.md 히스토리）、docs/audit-report-2026-08-06-v3.md、docs/api-reference.md
