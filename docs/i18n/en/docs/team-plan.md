# CloudPlatform Team Plan

> Version: 2026-08-17 (v2) | v1 compiled by the multi-agent pipeline (PASS_WITH_FIXES); v2 updated by Lead based on actual Phase 0-2 execution results
> Basis: v1 + all Phase 0-2 commits (git 111 commits) + two-person review records + measured test baseline

## 1. Current Status Overview (2026-08-17)

### 1.1 Phase Completion

| Phase | Status | Key Deliverables |
|------|------|----------|
| Phase 0 Stop-the-bleeding | ✅ 4/4 | Real invoice rendering, 6 notification template types, explicit unverified reconciliation, CSP headers/env templates |
| Phase 1 Near-term | ✅ 8/8 | Cart quantity change, unified review status, real reconciliation (Stripe reports + per-day), refund condition validation (72h/5 days + idempotency + TOCTOU index), 7 supplier webhook types, Feature Flags wiring + admin UI, doc sync, real tests |
| Phase 2 Mid-term | ✅ 8/8 | 4 fund guards, service/admin test debt, install.sql 31 tables, RbacMiddleware mounted on 57 routes, admin in image + nginx 8788 + CI dual-side, audit regression + full login chain |
| Phase 3 Long-term | ✅ 9/9 | Gateway + unified rate limiting (P4.1), full multi-currency chain (P4.2), HarmonyOS engineering + CI (P4.3), ES rollout (P4.4), observation items resolved (P4.5), 4 doc divergences (P3.1), permission convergence (P3.2), order idempotency keys (P3.3), supplier rating validation (P3.4), i18n 7 languages (P3.6); reviewer-gate independent review all approved |

### 1.2 Quality Baseline (measured, serial verification after commit)

- service suite: **568 tests / 1279 assertions**, 10 skipped (all due to DB environment gaps)
- admin suite: **255 tests / 887 assertions**, 1 skipped (DB write path)
- CI 6 jobs: PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check / (docker-related)
- Funds/security all reviewed by two people (security-auditor + reviewer reached independent, consistent conclusions); git commits grouped by task, working tree clean
- Bonus: 9 Encryptable model credential serialization hidden (P1/P2 full audit)

## 2. Remaining Items and Risk List (2026-08-17 review)

### 2.1 Deployment-Blocking Items (high priority)

- **DB_PASSWORD environment gap**: service/.env has empty string → all DB endpoints return 500, root cause of 9+1 skipped tests. Not a code issue; requires ops to fill in the value (root .env.example already has the template)
- **HarmonyOS project scaffolding missing**: apps/harmonyos has only 3 .ets files (LoginPage/AuthManager/ApiClient), missing all hvigor/DevEco project config → cannot build; CI harmonyos-check honestly reports failure (exit 1)

### 2.2 Doc-Code Divergences (P1 unresolved, 4 items)

- GET /api/v1/orders status filter not implemented
- WebSocket push events missing (websocket_push related docs declare them)
- ticket.updated trigger scope unclear
- product_attributes dead schema (no code uses it)

### 2.3 Funds/Security Observation Items (two-person review records, low level)

- **Orders have no idempotency key**: repeated submission of the same cart can create duplicate orders (medium, suggest scheduling)
- Supplier rating does not validate order ownership/status
- fee bcmath truncation (5th decimal, short-collects <0.0001/transaction; consistent with routing, no reconciliation deviation)
- WAF multipart large bodies still read raw (json scenarios covered by $input, multipart is an extra defense surface)
- user_coupons has no unique constraint (semantics allow one user multiple orders multiple lines, observe)
- nginx-admin lacks CSP (admin is a Layui frontend with inline scripts, keep as-is)

### 2.4 Permission Model Inconsistencies (P2 new findings, to converge)

- 6 DB-only permission identifiers / 19 Rbac-only / role assignment differences (support/supplier)
- AdminRoleMiddleware excludes finance while Rbac.php defines a finance role

### 2.5 Other

- New i18n language files are English originals (T6), 7 languages not complete
- HarmonyOS CI structure check to be upgraded to a real hvigor build once scaffolding is complete

## 3. Roadmap

Priority principle (unchanged): **funds/security > delivery reliability > core business closure > experience and extension**.

### Phase 3 — Remaining Closure (1 month)

**Goal**: close all divergences and observation items, deployment reproducible (full DB-chain tests run green).

| Task | Involves | Role | Dependencies |
|------|------|------|------|
| Close the 4 doc-code divergences (orders status filter implementation / WebSocket push wiring / ticket.updated fix / delete or implement product_attributes) | Order, WebSocket, Ticket, Product, docs | coder + researcher | None |
| Permission model convergence (DB/Rbac alignment + role seeds + AdminRoleMiddleware review) | Rbac, install.sql, admin | coder + security-auditor | None |
| Order idempotency keys (cart→order duplicate prevention) | OrderService | coder | None (funds-related, two-person review) |
| Supplier rating validates order ownership/status | Supplier, Review | coder | None |
| DB_PASSWORD ops connectivity + run the 10 skipped tests | ops, tests | security-auditor | ops cooperation |
| Complete i18n 7-language translations | i18n files | coder | None |

**Acceptance**: 4 divergences closed; permission matrix DB/code consistent; idempotency key tests; full DB-chain tests run green; i18n at least Chinese and English usable.

### Phase 4 — Architecture Evolution (1-3 months)

**Goal**: four-layer architecture takes shape, supporting multi-client multi-currency growth.

| Task | Involves | Role | Dependencies |
|------|------|------|------|
| Standalone API gateway + unified rate limiting mount (incl. graphql gap) | gateway, route | architect + coder | P3 |
| Full multi-currency chain consistency (incl. fee rounding strategy) | Payment, Billing | architect + performance-engineer | same as above |
| HarmonyOS engineering: scaffolding + CI real build + login integration | apps/harmonyos | mobile-dev | None |
| ES audit rollout, replacing the workaround | docker, Product search | coder | None |
| Batch-resolve observation items (WAF multipart / user_coupons constraints / supplier webhook end-to-end) | Security, Order, Supplier | coder + tester | None |

**Acceptance**: k6 verifies rate limiting effective on all routes; multi-currency accounting zero error; HarmonyOS package passes CI; ES search truly usable.

## 4. Team Structure

Fixed core: Lead(planner) / architect / coder / tester / reviewer / researcher
On-demand: mobile-dev / security-architect / security-auditor / performance-engineer

| Phase | Roles Brought In | Description |
|------|----------|------|
| P3 | coder (main), researcher, security-auditor | Closure-focused; permissions/idempotency two-person review |
| P4 | architect, coder, mobile-dev, performance-engineer | Architecture evolution; security-architect as resident advisor |

Collaboration model unchanged: CLAUDE.md pipeline (architect→coder→tester→reviewer), P3/P4 internal tasks fan out in parallel; **funds/security tasks require mandatory two-person review**; this document is updated at the end of each phase (this v2 was compiled directly by Lead, not through the pipeline, reviewable).

## 5. Risk Tracking Approach

- This list rolls forward at the end of each phase; new findings (such as P2's permission model inconsistency, order idempotency) are merged in immediately
- Known low-priority items (supplier webhook end-to-end, multipart body) are already in the P4 resolution batch, not spread outside the list

## 6. Key Evidence Sources

- Commits: git log (111 commits, Phase 0-2 grouped by task)
- Test baseline: measured output of service/admin suites
- Review records: P1/P2 two-person review messages (fund guards, logout/WAF, RBAC, audit regression)
- Docs: v1 (docs/team-plan.md history), docs/audit-report-2026-08-06-v3.md, docs/api-reference.md
