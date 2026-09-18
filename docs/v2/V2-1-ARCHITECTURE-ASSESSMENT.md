# V2-1 Architecture Assessment

**Baseline:** `main` @ `eb47fb4` (V2-0 Discovery merged, PR #92)
**Type:** Architecture discovery/decision only. No code, schema, test, or CI file changed.
**Relationship to prior art:** PCTN already has a substantial, accepted architecture baseline — `ARCHITECTURE.md` (23 sections) and 10 ADRs (`docs/adr/`), written before V2-0. This document does **not** repeat that baseline. It validates it against V2-0's concrete findings, re-verifies its factual claims against current code (some predate K1–K2-E and the P3-B/K-series work), and answers the one question the existing baseline deliberately leaves open at low granularity: **what is the smallest first V2 batch, and does anything in V2-0 change the existing architectural direction.**

**How to read this document:** every claim is **Fact** (cited file/line or command output), **Inference** (a conclusion drawn from facts), or **Recommendation** (a judgment call). Where this document's conclusion matches an existing ADR, that is stated as agreement-with-evidence, not re-derived from scratch.

---

## 1. Executive Summary

**Fact, re-verified today:** `ADR-001` (modular monolith), `ADR-002` (no rewrite), `ADR-003` (Application Service direction), `ADR-005` (API boundary rule), `ADR-007` (concurrency invariants), `ADR-009` (framework deferred) already commit PCTN to almost exactly the direction this assessment would independently recommend. This document's job was therefore to **stress-test that direction against V2-0's specific requirements**, not invent a new one — and it holds up. No requirement identified in V2-0 needs a different architecture, an API, or a frontend framework.

**Inference:** the single highest-leverage architecture finding in this pass is that V2-0's top-priority requirement — a debt/payment audit trail — requires **zero schema change and zero new mechanism**. `audit_log.entity_type` is an unconstrained `VARCHAR(30)` (verified, `database/schema.sql:578`), not an enum; `includes/debt.php`'s two mutating functions already run inside their own transactions with an idempotency-token claim as the first statement, exactly the shape `logAudit()` calls already take in `includes/stock.php` (3 call sites) and `includes/purchase_order.php` (7 call sites). Adding two calls closes V2-0's highest-impact finding with the existing mechanism, unchanged.

**Recommendation:** continue Option A (server-rendered PHP, improve internal architecture) for the full V2 horizon this assessment can see evidence for. Do not introduce an API or a frontend framework now. Begin the Application Service extraction ADR-003 already named as a prerequisite — starting with Category/Unit/Supplier, the three modules both this assessment and the existing ADR-005 independently identify as blocking any future API work.

---

## 2. Current Architecture

### 2.1 Real layers today (Fact)

| Layer | Where it lives | Evidence |
|---|---|---|
| Presentation | Page scripts (`*/index.php`, etc.) + `includes/header.php`/`footer.php`/`receipt_view.php`/`transaction_detail.php` | HTML emitted inline in the same file that handles the request |
| Business logic — **extracted** | `includes/stock.php`, `includes/debt.php`, `includes/purchase_order.php`, `includes/currency.php` | HTTP-agnostic: PDO + primitives in, arrays/exceptions out, own transaction boundary. Confirmed no `$_POST`/`$_SESSION`/`echo` inside any of these four files |
| Business logic — **inline in presentation** | `category/index.php`, `unit/index.php`, `supplier/index.php`, `product/index.php`, `user/index.php`, `customer/index.php` (customer identity CRUD, not the debt logic) | `INSERT`/`UPDATE`/`DELETE` and `logAudit()` calls sit directly in the page script — re-verified today: `category/index.php:29,65,90` |
| Persistence | Inline PDO calls, no repository/ORM abstraction, in every layer above | No data-mapper or query-builder anywhere in `composer.json`'s dependency tree (verified: zero runtime dependencies) |
| Cross-cutting: auth/session | `includes/auth_check.php`, `config/session.php`, `includes/session_lifecycle.php`, `includes/login_throttle.php` | Re-reads role from DB once per request (K2-D); this is the strongest, most recently-hardened boundary in the app |
| Cross-cutting: CSRF | `includes/csrf.php` | One function pair, called at 20/21 mutating handlers (all but `auth/login.php`, a known, deliberate V1 deferral) |
| Cross-cutting: audit | `includes/audit.php` | `logAudit()` + a per-entity allowlist snapshot builder (`userAuditSnapshot()`); append-only, no `UPDATE`/`DELETE` path exists against `audit_log` anywhere |

This matches `ARCHITECTURE.md` §5's own "honest mapping to what exists today" almost exactly — re-verified as still accurate on current `main`, not stale.

### 2.2 Shared services that already exist (Fact)
`includes/stock.php` (guarded concurrency, FEFO, idempotency, reference generation), `includes/debt.php` (credit sale + payment, idempotency), `includes/purchase_order.php` (full PO state machine, 7 functions), `includes/purchase_order_prefill.php` (pure function, no DB access — the cleanest example in the codebase of a testable domain helper), `includes/audit.php`, `includes/backup.php`, `includes/currency.php`, `includes/sortable.php` (one shared allowlist-based `ORDER BY` helper, 38 lines, used by 6 list pages), `includes/validation.php` (one function only — `isNonNegativeIntegerString`).

### 2.3 Boundaries that are already good and should remain (Fact + Recommendation)
- **The Application Service boundary for Stock/Debt/PurchaseOrder.** Every mutation for these three modules is one function call from the page script into `includes/`; the function owns its own transaction end-to-end. This is the exact shape ADR-003 wants everywhere and is the reason ADR-005 can say Stock/Debt/PO are "already API-ready."
- **The concurrency/idempotency mechanism set** (ADR-007): guarded `UPDATE`s, `SELECT … FOR UPDATE` lock ordering, idempotency-token-first-statement, reference-counter row locking. Independently verified this session by a full, fresh test run today (§15) and by direct code reading — unchanged since the K-series security work, which never touched these files.
- **The audit-snapshot allowlist pattern** (`userAuditSnapshot()` and its siblings): a structural, not conventional, guarantee that a sensitive column can never leak into an audit row. This is a real architectural asset, not just a coding convention — it is enforced by what the function is physically able to read, not by remembering to `unset()` a field.
- **Session/RBAC hardening (K1–K2-E).** Independently re-audited before V1 closed; nothing in V2-0's findings or this assessment touches it.

---

## 3. Constraints

**Fact**, each re-verified against current code, not assumed from prior documents:

- **Server-rendered PHP, no build step.** No `package.json`, no bundler, no npm dependency anywhere in the repo.
- **`BASE_URL`/document-root coupling.** `config/base_url.php` derives `BASE_URL` by subtracting the document root from the app path — this is *why* K1 used deny-rules instead of a `public/`-only restructure, and it constrains any future routing change (a real API layer would need to decide whether it lives under the same document root or a separate path/subdomain).
- **Two deployment targets, both HTTP-only today:** XAMPP/Apache (`.htaccess`) and Docker Compose (Nginx + PHP-FPM). Any architecture change must keep working on both, or the change must explicitly retire one — not decided by this document.
- **Migration convention:** `database/migrations/*.sql`, sequentially numbered (currently through `019`), applied by hand or by the test suite's `SchemaBuilder`; no migration framework/tool dependency. Confirmed still the live convention (K2-D's migration 019 followed it exactly).
- **Zero runtime PHP dependencies** (`composer.json` — `phpunit/phpunit` is `require-dev` only). Any architecture option that assumes a framework or ORM is a new dependency, not an extension of an existing one.
- **CSRF is session-form-token-based**, not a bearer-token/API-key scheme — ADR-005 already names this as the specific thing that would need a parallel (not replacement) mechanism for any future API client.
- **Authentication is session-only.** No token/API-key mechanism exists (Fact) — a prerequisite ADR-005 already names for any API.
- **Testing constraints:** `tests/bootstrap.php` hard-refuses to run unless `APP_ENV=test` and the target DB name contains "test" — this is a safety rail on the current in-process + real-MySQL testing model, and any architecture change that moves persistence behind a different interface (a repository layer, a different DB engine for a specific module) would need an equivalent safety rail re-established, not assumed to still apply.
- **The Concurrency suite requires genuinely separate OS processes** (`tests/Concurrency/*_race.php` workers via `proc_open`), not simulated concurrency. This is a real, valuable, but also real-cost testing pattern — any move to a queue/async model would need to decide how (or whether) this pattern still applies.
- **No pagination mechanism exists anywhere** (Fact — `grep` for `LIMIT.*OFFSET`/`OFFSET` across the whole app returns zero matches). This is a genuinely new mechanism to add, not an extension of a partial one.
- **No frontend framework, no client-side state management, no client-side router** — every "app-like" behavior (live search, toasts, theme, offcanvas nav) is hand-written vanilla JS reading server-rendered DOM, verified in `includes/footer.php`.

---

## 4. V2 Requirement Fit

Each V2-0 requirement, classified against the current architecture. Classification legend: **Works** (current architecture, no new mechanism needed) / **Targeted refactor** (existing mechanism extended, no new layer) / **Architectural extension** (a genuinely new, currently-absent mechanism, but still within the modular-monolith/server-rendered model) / **Different architecture** (would require abandoning the current model).

| V2-0 Requirement | Classification | Evidence |
|---|---|---|
| Debt/payment audit trail | **Works** | `logAudit()` already exists, `audit_log.entity_type` is unconstrained, `includes/debt.php`'s two functions already own their own transactions. Adding `logAudit()` calls inside them is the same pattern already proven at 10 other call sites. |
| Audit-log filter/search/pagination | **Architectural extension** | The read side (`audit/index.php`) is a single unfiltered query; no pagination mechanism exists anywhere in the app (§3) to extend. This needs a new, but architecturally unremarkable, `WHERE`+`LIMIT/OFFSET` mechanism — no new layer, no new dependency. |
| Server-side pagination/search (Products, Customers, POS pickers) | **Architectural extension**, reusing an existing partial pattern | `initLiveSearch()` (`includes/footer.php`) already does a debounced server round-trip and DOM-fragment swap for Category/Unit/Supplier/Product list pages — the *client* half of this pattern already exists. The *server* half (an actual `LIMIT/OFFSET`-bound query instead of "return everything, client filters") does not. This is the same new mechanism as the row above, applied to more call sites. |
| POS data-loading improvement | **Targeted refactor** | POS's product/customer pickers reuse `.product-search-*`/`renderSearchMenu()`, the *same* client component `initLiveSearch()` already complements elsewhere — POS simply never switched from "embed the full table as JSON" to "search-as-you-type against the server." This is a call-site change to an existing pattern, not a new one. |
| Self-service or lower-friction password reset | **Architectural extension** | No email/SMS-sending capability exists anywhere in the codebase today (Fact — no mail library, no `mail()`/SMTP config found). A genuinely new outbound-notification capability, even for the smallest version of this feature (an emailed reset link), is required. A lower-friction *Admin-assisted* version (e.g., a copyable reset link instead of a shown password) is a **Targeted refactor** of the existing `user/index.php` reset action instead. |
| Account deactivation | **Targeted refactor**, needs one schema column | No `active`/`status` column exists on `users` (Fact, re-confirmed this session). Adding one nullable/defaulted column plus a check in `includes/auth_check.php`'s existing per-request freshness read (K2-D) is a small, additive change to a mechanism that already re-reads the row every request — the check literally has nowhere new to go, it already runs there. |
| Debt aging / collections view | **Works**, reporting-only | `customer_debts.due_date`/`balance`/`status` are already real, generated/queryable columns (Fact). An aging report is a new read-only query and view, no new write-side mechanism, no schema change. |
| Scheduled backup | **Architectural extension**, but outside the app's own request/response model | `includes/backup.php::streamDatabaseBackup()` already exists and is already reused by both the manual UI trigger and the test suite's restore verification. "Scheduled" means invoking it from something other than an HTTP request (a cron entry, a CLI wrapper) — a real new invocation path, but it calls the *existing* function unchanged. |
| More professional UX/UI | **Works / Targeted refactor**, mostly presentation-layer | V2-0 found the presentation layer already has strong, consistent patterns (toasts, mobile nav, theming, EN/KM localization) — most improvements identified are extending existing components (pagination controls, filters) to more pages, not a new rendering model. |
| Future productization readiness | **No action needed at V2 architecture layer** | Per V2-0 §8 and this task's own instruction, multi-tenancy/isolation is explicitly out of scope for V2. The one architecturally relevant fact: no table has a tenant/business-scoping column today (Fact), so any future multi-tenant work is a schema-and-query-layer change, not a presentation or API-layer one — noted for awareness, not sized here. |

**Inference:** every single V2-0 requirement fits inside the current modular-monolith, server-rendered model. Nothing found in V2-0 crosses into "Different architecture." This is the central finding of this assessment.

---

## 5. Architecture Options

**Recommendation up front, per the task's instruction not to pick by intuition:** the requirement-fit table in §4 is itself the evidence. Every requirement is Works/Targeted-refactor/Architectural-extension *within* Option A. Options B and C are evaluated below for completeness and to make the rejection evidenced, not assumed.

| Dimension | A. Continue server-rendered PHP, improve internal architecture | B. PHP backend + API + modern frontend | C. Hybrid/partial frontend modernization |
|---|---|---|---|
| UX capability for §4's requirements | **Sufficient** — every requirement classified Works/Targeted-refactor/Architectural-extension without a new rendering model | Sufficient, but no requirement in §4 needs it | Sufficient for the same reason as A; adds capability nothing in §4 asks for |
| Implementation complexity | Low–Medium — extends existing, proven patterns (`initLiveSearch`, `logAudit`, `auth_check.php`'s freshness read) | High — new API surface, new auth scheme (ADR-005 already names token auth as a prerequisite), new build pipeline, new deploy artifact | Medium — a build step and a client framework are introduced, but only for select pages |
| Migration risk | Low — additive changes to files already covered by the existing test suite | High — CSRF (§3) does not translate to a JSON API without a parallel mechanism; Category/Unit/Supplier are not yet Application-Service-extracted (ADR-005's own precondition), so an API today would either duplicate their inline logic or block on that extraction first | Medium — two rendering models coexisting (server HTML + client framework islands) is a real, if bounded, source of state-sync bugs |
| Maintenance cost | Low — one language, one deploy artifact, already-mature conventions | Higher — two codebases/skillsets (PHP + frontend framework/build tooling) to keep in sync | Medium — a build step and a second toolchain, applied narrowly |
| Testing impact | None to Low — extends the existing Unit/Integration/Http/Concurrency/Schema/Backup suite (494/3,458 passing today, re-verified) | High — a new API-contract test layer is needed in addition to the existing suite, not instead of it (ADR-007's invariants still apply underneath) | Medium — new component-level tests for whichever pages adopt a framework, on top of the existing suite |
| Deployment impact | None — same two targets (XAMPP, Docker), unchanged | High — a build/bundle step, a separate frontend deploy artifact or SSR concern, and `BASE_URL`'s document-root coupling (§3) needs an explicit decision | Medium — a build step for the adopting pages only |
| Learning/operational complexity | None — same stack the project already runs | High — the project has zero prior frontend-framework/build-tooling history to build on | Medium |
| Compatibility with current code | Full — this *is* the current code | Partial — Stock/Debt/PO logic ports with "comparatively little change" (ADR-009's own claim, verified true by their HTTP-agnostic shape); Category/Unit/Supplier and all presentation logic does not | Full for untouched pages; new work for adopted pages |
| Preserves existing business logic | Fully, unchanged | Fully *if* ADR-005's rule is followed (API calls the same Application Services); at real risk of a second, divergent business-rule path if it isn't | Fully, unchanged |
| Future productization readiness | Neutral — a schema/query-layer concern (§4), not addressed or blocked by any of these three options | Marginally better *if* multi-tenancy is ever pursued (a real API is a natural fit for a future SaaS control plane) — but ADR-004/§8 explicitly do not schedule multi-tenancy, so this benefit is speculative today | Neutral |

**Conclusion (Recommendation, matching ADR-001/002/009's existing decisions with fresh evidence):** **Option A.** No V2-0 requirement crosses the threshold that would justify B's cost, and C's cost is not justified either, since C's only advantage over A (richer client-side interactivity) is not something any §4 requirement actually needs — every "needs a live update" requirement (search-as-you-type, filters, pagination) is already the exact shape `initLiveSearch()` proves this stack handles today.

---

## 6. API Assessment

**Is an API needed now? No (Recommendation, agreeing with and re-evidencing ADR-005).** Nothing in §4 requires a non-browser client. V2-0 did not identify a mobile-app, third-party-integration, or headless-consumer requirement.

**Where would an API add value first, if/when one is built (Inference, extending ADR-005's own claim):** Reporting is the most decoupled candidate — it is already read-only, already separated from every write-path business rule, and V2-0 named "future analytics/export" as a plausible future consumer. This agrees with V2-0 §9's own conclusion, reached independently there.

**What does not need an API:** everything in §4's Works/Targeted-refactor rows — none of them are "a new client needs this data," they are "the existing browser client needs a better version of a query it already makes."

**Risks of introducing an API too early (Fact-grounded):**
- CSRF does not translate to a JSON API (§3) — building one now means either inventing an auth scheme under time pressure or leaving new API routes less protected than the existing form-based ones.
- Category/Unit/Supplier are not yet Application-Service-extracted (§2.1) — an API built today would either expose their inline logic through a new, hastily-extracted path (rushed, higher regression risk) or simply not cover those modules (an inconsistent, confusing API surface).
- ADR-005 already names both of these as the reason to sequence extraction *before* API work — this assessment finds nothing in V2-0 that changes that sequencing.

---

## 7. Frontend Assessment

**Can vanilla JS + server-rendered HTML handle V2's requirements? Yes (Fact-grounded, per §4).** Every requirement that sounds like it needs "a frontend framework" — live filtering, pagination, search-as-you-type — is architecturally identical to the existing, working `initLiveSearch()` pattern (`includes/footer.php:80-115`): debounce, `fetch()`, swap a DOM fragment via `DOMParser`, `history.replaceState`. This is not a coincidence; it is the same shape a framework's own data-fetching pattern would produce, already implemented without one.

**What would actually justify a frontend framework (Recommendation — a bar, not a current need):** a requirement with genuinely complex, deeply nested, or highly interdependent client-side state that a DOM-fragment swap cannot reasonably express — for example, a POS cart with live multi-currency conversion, per-line discounts, and tax calculation all recomputing against each other in real time might cross that bar. Nothing in V2-0's requirement list does. If such a requirement appears later, it should be evaluated against its own concrete complexity at that time, not adopted preemptively — this is the same posture ADR-009 already takes toward a backend framework.

**Is a hybrid approach sufficient? Yes, but not by adopting a framework for a subset of pages — by extending the existing hybrid the app already has** (server-rendered HTML + targeted vanilla-JS enhancement, already true of POS/Stock In/Out's barcode scanning and every list page's live search). "Hybrid" in the sense of "add a React island to two pages" is Option C, rejected in §5 for cost without matching benefit.

**Frontend migration risks, named for completeness even though not recommended now (Fact + Inference):** `BASE_URL`'s document-root coupling (§3) would need resolving for any client-side router; the current CSRF token is embedded per-rendered-form (`csrf_field()`) — a client framework fetching JSON would need the token delivered and refreshed differently; and the project has zero prior build-tooling history, meaning even a narrow adoption starts operational complexity from zero, not from an existing partial investment.

---

## 8. Business Logic / Domain Boundaries

**Already strong (Fact, re-verified this session, matching ADR-003/ADR-005's existing findings):**
- `includes/stock.php`, `includes/debt.php`, `includes/purchase_order.php` — HTTP-agnostic, transaction-owning, already what ADR-005 calls "API-ready." Confirmed unchanged and still accurate today.
- `includes/purchase_order_prefill.php` — a pure function (no `$pdo` parameter at all for its core logic), the single cleanest example of a directly-unit-testable domain helper in the codebase.
- `includes/audit.php`'s allowlist pattern — a boundary enforced by what the function can physically read, not by convention.

**Too coupled to HTTP/UI (Fact, re-verified):**
- `category/index.php`, `unit/index.php`, `supplier/index.php` — `INSERT`/`UPDATE`/`DELETE` and `logAudit()` calls sit directly in the page script (`category/index.php:29,65,90` cited above). This is precisely what ADR-003 already names as the acknowledged, not-yet-addressed debt.
- `product/index.php` — the largest page script (628 lines, per V2-0), mixing product-identity CRUD (inline) with rendering.
- `user/index.php` — role/password mutation logic inline, not extracted (a real consideration given account-deactivation, §4, will touch this exact file).

**Services that should be extracted before any API/frontend separation (Recommendation, agreeing with ADR-003/ADR-005's existing sequencing, made concrete for V2):**
1. Category/Unit/Supplier CRUD → `includes/catalog.php` (or three small files matching the existing one-concern-per-file convention) — smallest, most mechanical extraction; directly named by both ADR-003 and ADR-005 as the prerequisite for any future API.
2. Product CRUD → its own Application Service — larger than #1, lower urgency, since no V2-0 requirement specifically depends on it.
3. `includes/validation.php` — currently one function; V2-0 already flagged this as a code-organization observation. Centralizing per-controller inline validation is lower-priority than #1/#2 because no §4 requirement is blocked on it, but it becomes relevant the moment #1/#2 happen, since extraction is the natural point to also centralize the validation those controllers currently do inline.

**Protected, as directed by this task and already codified in ADR-007/§21 — not touched, not re-derived here, just re-confirmed present:** guarded stock updates/row locks, idempotency, reference-counter concurrency, audit snapshot allowlists, debt/payment correctness, RBAC/CSRF/session hardening. All independently verified intact by the fresh full-suite run in §15.

---

## 9. Recommended Target Architecture

**This matches `ARCHITECTURE.md` §5's existing target almost exactly — restated here only because the task requires it, not as a new proposal:**

```text
Browser (server-rendered HTML + vanilla JS enhancement)
        ↓
Page / Handler layer            (today: */index.php — thin for Stock/Debt/PO,
                                  still thick for Category/Unit/Supplier/Product/User)
        ↓
Application Services            (includes/stock.php, debt.php, purchase_order.php —
                                  extend to catalog/product/user per §8)
        ↓
Domain Rules                    (currently intermixed with Application Services —
                                  remains so; no evidence in V2-0 justifies separating
                                  this into its own layer yet, per ARCHITECTURE.md §5's
                                  own already-correct reasoning)
        ↓
Persistence (PDO, no ORM)
        ↓
MySQL / MariaDB
```

**No API layer is included**, per §6's evidence — nothing in V2-0 justifies one now. If one is added later, it inserts between "Browser" and "Application Services" exactly as `ARCHITECTURE.md` §12 already diagrams, calling the same Application Services, never touching the database directly (ADR-005's non-negotiable rule) — that diagram does not need restating here since it is unchanged by this assessment's findings.

---

## 10. Migration Strategy

V1 keeps working throughout — every step below is additive, per ADR-002.

**Can be changed incrementally (dependency order, Recommendation):**
1. Debt/payment audit logging (§4) — additive `logAudit()` calls inside existing transactions. No dependency on anything else in this list.
2. Account-deactivation column + `auth_check.php` check (§4) — one migration, one additive check in an already-existing per-request read path.
3. Debt-aging report — new read-only query/view. No dependency on 1–2.
4. Pagination mechanism (generic `LIMIT/OFFSET` + a shared helper, symmetric to `includes/sortable.php`'s existing allowlist pattern) — built once, then applied to Audit Log, then Products/Customers/POS pickers in that order (Audit Log first because it has the most acute, code-comment-acknowledged pain; POS last because it is the highest-traffic, least-tolerant-of-regression page).
5. Category/Unit/Supplier Application Service extraction (§8) — should happen *after* the pagination mechanism exists, so the extraction can incorporate paginated queries from the start rather than being touched twice.
6. Scheduled backup invocation (cron/CLI wrapper around the existing `streamDatabaseBackup()`) — independent of everything else above.

**Should remain untouched in this V2 pass (Recommendation, per this task's protected-assets list and ADR-021):** everything in §2.3/§8's "protected" list; the CSRF/session/RBAC mechanism; the PO/Stock/Debt transaction and locking code itself (only *new* logging calls are added around it, never inside its guarded sections).

**Rollback strategy (Recommendation, matching the pattern every K-series batch in this project's own history already used):** each item above is one small, reviewed, independently-mergeable change with its own test coverage, on its own branch — exactly the discipline `DEVELOPMENT.md`'s K1–K2-E history already demonstrates repeatedly. A regression in any one item reverts that item's single commit/PR; nothing else in the list depends on it having landed, except #5 depending on #4 as noted.

**How this avoids a big-bang rewrite:** by construction — every item is additive to a file/mechanism that already exists and is already tested, matching ADR-002's decision exactly.

---

## 11. Technology Decision

- **Recommended architectural direction:** continue the modular monolith (ADR-001), continue the no-rewrite posture (ADR-002), continue the Application Service extraction direction (ADR-003) — starting concretely with Category/Unit/Supplier, sequenced as in §10.
- **Frontend framework justified now?** **No.** §7's evidence: every V2-0 requirement is already the same shape as a pattern (`initLiveSearch()`) that works today without one.
- **API justified now?** **No.** §6's evidence: no non-browser client requirement exists in V2-0; the two named prerequisites (token auth, Application Service extraction of Category/Unit/Supplier) are not yet met.
- **Should PHP remain the backend?** **Yes.** No requirement in §4 approaches a limit plain PHP/PDO cannot serve; `ARCHITECTURE.md`/ADR-009's framework-neutral posture remains correct — nothing in V2-0 changes that calculus.
- **Deferred (Recommendation, explicit per this task's instruction to separate "decide now" from "not now"):** any specific API framework/endpoint design (ADR-005 already defers this); any specific backend framework (ADR-009 already defers this); the Domain-Rules-as-a-separate-layer question (§9) — revisit only if a rule needs reuse across more call sites than one Application Service function currently serves.

---

## 12. First V2 Implementation Batch

**Recommendation.** Smallest, most measurable, most reversible, and highest-evidenced-value candidate: **the debt/payment audit trail (§4, §10 item 1).**

- **Small:** two `logAudit()` calls added inside two already-transaction-owning functions (`recordCreditSale()`, `recordDebtPayment()` in `includes/debt.php`) — no new file, no schema change, no new layer.
- **Measurable:** before, `grep -c logAudit includes/debt.php` = 0; after, > 0, and every debt/payment action appears in `audit/index.php`'s existing list with no changes to that page.
- **Reversible:** removing the two calls reverts to current behavior exactly; nothing else depends on them.
- **Useful:** closes V2-0's own highest-ranked business pain point (§6.1 of V2-0), independently re-confirmed as the top finding in this assessment's §1.
- **Test impact:** extends the existing pattern already proven for Stock/PurchaseOrder audit coverage (`tests/Http/` already has precedent for asserting an `audit_log` row exists after a mutation) — no new test *layer*, just new test cases in the existing Integration/HTTP suites.
- **Explicitly not bundled with pagination or account-deactivation** — those are real, evidenced next items (§10), but bundling them would make the first batch larger and less reversible than it needs to be for a "first" batch.

---

## 13. Decisions Deferred

Per §11 and consistent with the existing ADRs — not re-litigated here:

- Specific API framework, endpoint design, and token-auth scheme (ADR-005).
- Specific backend framework, if any is ever adopted (ADR-009).
- Whether Domain Rules ever become a separately-invoked layer from Application Services (`ARCHITECTURE.md` §5's own stated condition: only if a rule needs reuse beyond one service function).
- Multi-tenancy, per this task's explicit instruction and ADR-004/§8's existing "positioned, not scheduled" stance.
- Physical directory restructuring of `includes/`/module boundaries (`ARCHITECTURE.md` §4's own caveat: conceptual grouping, not a rename mandate).
- The exact design of self-service password reset (email delivery mechanism, token expiry policy) — sized as an Architectural Extension in §4, not designed here.

---

## 14. Risks

- **Extraction risk (Category/Unit/Supplier → Application Service, §8/§10 item 5):** the lowest-risk item on the list architecturally, but the one most likely to be done carelessly *because* it looks mechanical — each of the three modules' delete paths has its own FK-constraint-check nuance (V2-0 confirmed this pattern exists consistently); extraction must preserve each module's exact existing error-handling per this task's "preserve business semantics" instruction, verified by keeping (or extending) that module's existing test coverage, not assumed safe because the diff looks small.
- **Pagination mechanism risk:** introducing `LIMIT/OFFSET` against tables with concurrent inserts (`audit_log`, `stock_transactions`) can produce duplicate/skipped rows across pages under high write concurrency if not paired with a stable sort key — the existing `sortOrderBy()` allowlist pattern should be extended to guarantee a deterministic tiebreaker (e.g., always include `id` in the `ORDER BY`), not assumed automatically safe.
- **Sequencing risk:** if Category/Unit/Supplier extraction (§10 item 5) happens *before* the pagination mechanism (§10 item 4) despite this document's recommended order, the extraction would need to be touched a second time — a cost-of-reordering risk, not a correctness risk.
- **Scope-creep risk on the first batch (§12):** the debt/payment audit trail is trivial to over-scope into "also add debt-aging, also add pagination" — this assessment explicitly recommends against bundling, per §12's own reasoning.

---

## 15. Validation

- **Full regression suite, run fresh for this assessment:** `APP_ENV=test vendor/bin/phpunit` → `OK (494 tests, 3458 assertions)` — matches the count already recorded in V2-0 and the prior K-QA re-audit, confirming no drift since V2-0 was written.
- **Files changed by this assessment:** `docs/v2/V2-1-ARCHITECTURE-ASSESSMENT.md` only (new file). No application, schema, migration, test, or CI file modified.
- **`git diff --check`:** run and reported in the final delivery message.
- **Contradiction review:** this document's conclusions were checked against `ARCHITECTURE.md` and all 10 ADRs before being finalized; every point of agreement is stated as such rather than re-derived as if novel, and no contradiction with the existing accepted ADRs was found.
