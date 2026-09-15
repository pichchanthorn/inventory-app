# PCTN Inventory — Professional Architecture Blueprint v1.0

**Baseline:** `main` @ `e7369709f0392c572ceb563ab3b4f2a751c90f38` (tag `v1.0.0`)
**Status:** Accepted architecture direction as of v1.0.0
**Scope:** This document is architecture and direction. It does not authorize, schedule, or imply any immediate code change. Sections describing target/future state are explicitly marked as such and must never be read as a work order.

---

## 1. Executive Summary

PCTN Inventory v1.0.0 is a plain PHP 8.1+/PDO/MySQL server-rendered application built as one directory per feature module, backed by a small set of shared `includes/*.php` files that already carry the application's most business-critical logic (stock mutation, debt/credit sales, purchase orders, currency conversion, audit logging) in a form that is HTTP-agnostic today: these functions take a `PDO` handle and primitive arguments, return plain arrays, and throw typed exceptions — they do not read `$_POST`, `$_SESSION`, or emit HTML.

**Strengths, confirmed by direct inspection of `main`:** every stock/debt/purchase-order mutation is protected by either a guarded `UPDATE ... WHERE <invariant still holds>` or a `SELECT ... FOR UPDATE` row lock taken before any decision is made; every mutating form is CSRF-protected; every write path checks role-based authorization server-side; entity mutations are audited with a structurally-enforced (not conventional) exclusion of sensitive fields; and the automated test suite spans Unit, Integration, HTTP, genuine multi-process Concurrency, Schema, and Backup/Restore layers, gated in CI against a real MySQL service.

**Major architectural debt:** the reference Nginx/Docker deployment configuration serves the entire repository — including `database/schema.sql`, `database/seed.sql`, `.git/`, and test fixtures — as static files, because there is no `public/`-only web root. Login does not regenerate the session ID and has no brute-force protection. There is no tenant/business-scoping concept anywhere in the schema (correct for a single-shop v1.0.0, a real gap for the stated commercial-growth goal). Several simple CRUD modules (Category/Unit/Supplier) still embed their logic directly in page scripts rather than in a shared, testable function, unlike Stock/Debt/PurchaseOrder.

**Target direction:** incremental hardening and extraction — never a rewrite. The application evolves through five recognizable stages: **Hardened Modular Monolith → Application-oriented Monolith → API-ready Platform → Commercial Multi-Tenant Product**, detailed in §20. At every stage, the existing `includes/*.php` domain layer is the foundation being built on, not something being replaced.

**Why incremental evolution is preferred, and why a rewrite is not justified:** the domain logic for the three most business-critical modules (stock, debt, purchase orders) is already correctly separated from HTTP concerns, already transaction-safe, and already covered by a test suite that includes genuine multi-process concurrency verification — a level of proven correctness that is expensive to build and trivial to accidentally discard in a rewrite. A rewrite would, at best, re-arrive at guarantees this codebase already has, while carrying real risk of silently regressing a subtle invariant (lock ordering, NULL-safe uniqueness handling, idempotency-token placement) that took multiple iterations to get right the first time. No framework decision is made or implied by this document (§18).

---

## 2. Current Architecture

This section documents only what exists in `main` today. Nothing here is aspirational.

**PHP application structure:** one directory per feature module (`product/`, `purchase-order/`, `pos/`, `stock-in/`, `stock-out/`, `stock-adjustment/`, `customer/`, `supplier/`, `category/`, `unit/`, `user/`, `settings/`, `audit/`, `stock-report/`, `stock-alert/`, `auth/`), each containing one or more page scripts (`index.php`, and where needed `create.php`/`edit.php`/`view.php`/`receive.php`). There is no router, no front controller, no template engine — each page script is requested directly by its own URL path and renders its own HTML inline.

**Page scripts:** each authenticated page begins by requiring `includes/auth_check.php` (session check, role gate), branches on `$_SERVER['REQUEST_METHOD']` to handle POST actions (each preceded by `csrf_verify()` and a `canWrite()`/`isAdmin()` check), and ends by rendering HTML directly in the same file via `includes/header.php`/`includes/footer.php`.

**`includes/`:** the shared layer. Confirmed contents: `auth_check.php` (session/role gate), `csrf.php` (synchronizer-token CSRF), `lang.php` (i18n, `__()`), `sortable.php` (whitelisted dynamic `ORDER BY`), `validation.php` (small pure input-format checks), `currency.php` (single-point USD/KHR conversion, `resolvePriceField()`), `audit.php` (`logAudit()`, `userAuditSnapshot()`), `stock.php` (Stock In/Out/Adjustment, batch/FEFO, idempotency tokens, reference-counter sequencing), `debt.php` (credit sales, debt payments), `purchase_order.php` (full PO lifecycle), `backup.php` (pure-PHP streaming backup), `stock_alert.php` (`lowStockTier()`), `header.php`/`footer.php`, `receipt_view.php`, `transaction_detail.php`.

**Database layer:** raw PDO, prepared statements only (`PDO::ATTR_EMULATE_PREPARES => false`, `config/db.php`), no ORM, no query builder, no repository abstraction. Every domain function in `includes/` opens and owns its own transaction end to end.

**Authentication:** session-based (`$_SESSION['user_id']`), `password_hash()`/`password_verify()`, no self-service password reset, self-registration disabled by default (env-flag gated, defaults registrants to Viewer).

**Authorization:** three roles (Admin/User/Viewer) seeded in the `roles` table; `includes/auth_check.php` exposes `isAdmin()`, `isViewer()`, `canWrite()` (`!isViewer()`), used consistently (confirmed: 23 files call `canWrite()`, 12 call `isAdmin()`) as the single source of truth for every write-gate in the application.

**CSRF:** synchronizer-token pattern (`includes/csrf.php`), one token per session, verified with `hash_equals()` before any mutating handler runs. Confirmed present on every mutating POST handler sampled (25 `csrf_verify()` call sites).

**Business/domain logic:** concentrated in `includes/stock.php`, `includes/debt.php`, `includes/purchase_order.php` — HTTP-agnostic functions, each owning a full transaction, each throwing typed exceptions on business-rule violation (`StockConflictException`, `PurchaseOrderNotCancellableException`, `DebtOverpaymentException`, etc.). Simpler single-table CRUD (Category/Unit/Supplier) is still written inline in the page script rather than extracted.

**Transactions:** every mutating domain function follows `beginTransaction() → ... → commit()`, with a `catch (Throwable) { rollBack(); throw; }` wrapper. Stock/debt/PO invariants are enforced via guarded `UPDATE`s (`WHERE current_stock >= ?`) or `SELECT ... FOR UPDATE` row locks taken before any decision is made — never a separate unguarded read-then-write.

**Testing:** PHPUnit 11, six suites (Unit/Integration/Http/Concurrency/Schema/Backup) defined in `phpunit.xml.dist`. Concurrency tests launch genuinely separate OS processes via `proc_open()`. `tests/bootstrap.php` refuses to run unless `APP_ENV=test` **and** `DB_DATABASE` contains `"test"`.

**CI:** GitHub Actions (`.github/workflows/tests.yml`), real MySQL 8.4 service container, three provisioned test databases, PHP 8.4, runs the full suite on every push/PR to `main`. No lint/static-analysis stage, no deployment stage.

**Backup/restore:** pure-PHP streaming backup (`includes/backup.php`), no `mysqldump`/shell dependency, generated columns correctly excluded from restore INSERTs, covered by a dedicated integration test against its own test database.

**Deployment/Docker:** three-service Docker Compose (`app` PHP-FPM 8.4, `nginx` alpine reverse proxy, `mysql` 8.4.10), both `app` and `nginx` bind-mount the entire repository root. This is a local-development convenience configuration, not a production-ready one (see §13, §17).

---

## 3. Current Module Map

| Module | Status | Entry points | Notes |
|---|---|---|---|
| Identity & Access | **Existing** | `auth/login.php`, `auth/logout.php`, `auth/register.php`, `user/index.php`, `profile.php` | Session-based, 3 roles |
| Products | **Existing** | `product/index.php` | Single-file CRUD |
| Categories | **Existing** | `category/index.php` | Inline CRUD, not extracted |
| Units | **Existing** | `unit/index.php` | Inline CRUD, not extracted |
| Suppliers | **Existing** | `supplier/index.php` | Inline CRUD, not extracted |
| Inventory | **Existing** | `stock-in/`, `stock-out/`, `stock-adjustment/index.php` | Extracted to `includes/stock.php` |
| Stock Transactions | **Existing** | (shared table, no dedicated page beyond view/report) | `stock_transactions`/`stock_transaction_items` |
| Batches / FEFO | **Existing** | surfaced through Stock In/Out/Adjustment UI | Opt-in per product (`track_batches`) |
| POS / Sales | **Existing** | `pos/index.php`, `pos/receipt.php` | Cash + credit sale paths |
| Customers | **Existing** | `customer/index.php`, `customer/view.php` | — |
| Debt | **Existing** | `customer/view.php` (payments) | `includes/debt.php` |
| Purchase Orders | **Existing** (P1–P3-A complete) | `purchase-order/{index,create,edit,view,receive}.php` | Full lifecycle: draft→ordered→partially_received/received, plus cancel |
| Reporting | **Existing** | `stock-report/index.php`, `dashboard.php`, `stock-alert/index.php` | Read-only |
| Audit | **Existing** | `audit/index.php` | `includes/audit.php` |
| Settings | **Existing** | `settings/index.php` | Single global row (`app_settings`, `id=1`) |
| Backup | **Existing** | triggered from `settings/index.php` | `includes/backup.php` |
| Low Stock → Assisted PO (P3-B) | **Planned, not yet implemented** | — | See §23 (P3-B Position) |
| Cash & Money Management | **Future, not implemented** | — | §11 |
| Expenses | **Future, not implemented** | — | §4 |
| Multi-Tenancy | **Future, not implemented** | — | §8 |
| REST API | **Future, not implemented** | — | §12 |

---

## 4. Target Module Architecture

This is a **target** grouping of responsibility, not a proposed directory rename or an implementation instruction.

```text
PCTN
├── Identity & Access        (users, roles, sessions, future tenant membership)
├── Product Management       (products, categories, units, batches)
├── Inventory                (stock transactions, FEFO, stock alerts)
├── Purchasing               (suppliers, purchase orders — already the most mature module)
├── Sales                    (POS, sale transactions)
├── Customers & Debt         (customers, credit sales, debt payments)
├── Cash & Money             (future — §11)
├── Expenses                 (future — not yet designed in any form)
├── Reporting                (stock report, dashboard, low-stock alerts)
├── Audit                    (audit_log, append-only)
└── Settings                 (app-wide and, in future, per-tenant configuration)
```

**Responsibilities and boundaries:**
- **Identity & Access** owns authentication and role membership; every other module depends on it for "who is acting," never the reverse.
- **Product Management** owns product/category/unit/supplier-relationship identity; it does not own stock quantities (that is Inventory's responsibility) — `products.current_stock` is a cache maintained *by* Inventory operations, not by Product Management itself.
- **Inventory** owns all stock quantity truth (`current_stock`, `product_batches.qty_on_hand`) and the FEFO consumption rule. Sales and Purchasing both *call into* Inventory to move stock; neither duplicates stock-mutation logic.
- **Purchasing** owns the Purchase Order lifecycle and supplier relationships; it calls into Inventory to receive stock, exactly as it does today (`receivePurchaseOrder()` calling `insertStockInTransaction()`).
- **Sales** owns POS/sale-recording and calls into Inventory (stock out) and Customers & Debt (credit sales) — exactly the existing `pos/index.php` → `recordStockOut()`/`recordCreditSale()` relationship.
- **Customers & Debt** owns customer identity, debt balances, and payment history.
- **Cash & Money** (future) would own cash-account balances derived from transactions originating in Sales, Purchasing, Debt, and Expenses — it does not originate its own business events, only records their cash effect (§11).
- **Expenses** (future, not yet designed at all) would be a new transaction source feeding Cash & Money.
- **Reporting** and **Audit** are read-only consumers of every other module's data; they must never be a source of business-rule enforcement.
- **Settings** owns app-wide (today) / per-tenant (future) configuration; it must never contain business logic itself.

This grouping is a **conceptual boundary for reasoning about responsibility**, not a mandate to physically restructure the `includes/`/page-script directory layout in the near term. Physical reorganization, if it ever happens, is a Stage 2/3 concern (§20), decided separately and only when justified by actual pain, not by this diagram alone.

---

## 5. Target Layered Architecture

```text
Clients
   ↓
HTTP / API Layer
   ↓
Application Services
   ↓
Domain Rules
   ↓
Infrastructure / Database
   ↓
MySQL / MariaDB
```

**Honest mapping to what exists today:**

- **Clients:** today, exactly one client — the server-rendered browser UI. No other client exists.
- **HTTP / API Layer:** today, this is the page scripts themselves (HTML-shaped request handling). No JSON API layer exists.
- **Application Services:** **partially exists today.** `includes/stock.php`, `includes/debt.php`, and `includes/purchase_order.php` already have the shape of this layer — PDO/primitives in, arrays/exceptions out, no HTTP awareness. Category/Unit/Supplier CRUD does **not** yet have an equivalent extracted layer; that logic still lives inside the HTTP/page layer above.
- **Domain Rules:** today, intermixed with Application Services rather than a separately-named layer — e.g., "stock cannot go negative" is enforced inside the same function that owns the transaction, not in a separately-invoked domain object. This is an appropriate simplification at the application's current complexity, not a defect.
- **Infrastructure / Database:** today, direct PDO calls inside each Application Service function — no repository/data-mapper abstraction exists.
- **MySQL/MariaDB:** exists as described in §7.

**This document does not claim these layers are already fully separated.** The target is to make the boundary between "HTTP / API Layer" and "Application Services" explicit and consistent (today it is explicit and consistent for three modules, and absent for several smaller ones), and, at a later stage, to make "Domain Rules" a distinguishable concern from "Application Services" only if and when that separation is actually justified by real complexity growth (e.g., a rule that must be reused across more call sites than a single Application Service function currently serves).

---

## 6. Application Service Direction

**Target rule (future, not a request for immediate refactoring):** HTTP/page/API handlers should not contain core business rules. A handler's job is to translate a request into a call to an Application Service and translate that service's result (or exception) back into a response — nothing more.

**Illustrative example of the target shape:**

```text
PurchaseOrderController        (future — today this role is played by purchase-order/index.php)
        ↓
CreatePurchaseOrder            (already exists today as createPurchaseOrder() in includes/purchase_order.php)
        ↓
PurchaseOrder domain rules      (already enforced inside createPurchaseOrder()'s own transaction today)
        ↓
Persistence                     (already PDO inside the same function today)
```

**This is explicitly a target/illustrative shape, not a request to create a `PurchaseOrderController` class or rename `purchase-order/index.php`.** The Purchase Order module already satisfies the *intent* of this diagram today: `purchase-order/index.php` is a thin dispatcher, and `createPurchaseOrder()` already is the Application Service plus Domain Rules plus Persistence, combined in one HTTP-agnostic function. The future direction is to bring the remaining, still-inline modules (Category/Unit/Supplier, and any new module built without following this pattern) up to this same standard — not to further decompose the modules that already meet it.

---

## 7. Database Architecture

**Current relational model (confirmed from `database/schema.sql` and 17 sequential migrations, `001`–`017`):** consistently normalized (3NF), with ENUMs used only for small, stable, code-referenced state machines (`stock_transactions.type`, `purchase_orders.status`, `customer_debts.status`).

**Migrations:** additive, numbered, sequential; `database/schema.sql` is kept as the cumulative fresh-install target reflecting all 17 migrations. No destructive migration exists in the current history. `tests/Schema/MigrationIntegrityTest.php` verifies migrations-applied-in-sequence produce the same structure as `schema.sql`.

**FK constraints:** deliberately asymmetric cascade behavior based on business meaning, not defaulted uniformly — e.g. `purchase_order_items.purchase_order_id` is `ON DELETE CASCADE` (line items are meaningless without their header), while `purchase_order_items.product_id` and `stock_transaction_items.product_id` have no `ON DELETE` clause (default `RESTRICT` — a product with transaction history cannot be deleted out from under it), and `customer_debts.customer_id` deliberately has no `ON DELETE SET NULL` (a debt must never lose its "who owes this" link).

**Transactions:** every mutating domain function owns one transaction end to end (§2). Lock ordering is documented and consistent within `includes/stock.php`: product-row lock is always acquired before any batch-row lock.

**Generated columns:** used only where a value is a pure function of the same row — `purchase_order_items.subtotal` (`ordered_qty * unit_cost`), `customer_debts.balance`/`.status`. Deliberately **not** used for `purchase_orders.status` (which must aggregate across child `purchase_order_items` rows — a generated column cannot express that).

**Audit data:** `audit_log` (polymorphic `entity_type`+`entity_id`, `before_snapshot`/`after_snapshot` JSON, entity-level not field-level), see §15.

**Reference counters:** `reference_counters` table, one row per counter key (`stock_transactions`, `customer_debts`, `purchase_orders`), advanced via `SELECT ... FOR UPDATE` + `UPDATE` inside the caller's own transaction — replacing a documented prior `SELECT COUNT(*) + 1` race.

**Idempotency keys:** `idempotency_keys` table, a single `UNIQUE(token)` constraint is the entire mechanism, claimed as the first statement inside the transaction it protects.

**Money precision:** every monetary column is `DECIMAL(10,2)` — confirmed across the entire schema (`cost_price`, `sale_price`, `unit_cost`, `unit_price`, `subtotal`, `cash_received`, `total_amount`, `paid_amount`, `balance`). No floating-point column is used for stored money anywhere. `includes/currency.php`'s `resolvePriceField()` is the single point where a KHR-entered amount is converted and rounded before it ever reaches a `DECIMAL` column — the underlying data model is USD-only.

### Database invariants that must not be weakened

1. Every stock-decreasing write must remain a guarded `UPDATE ... WHERE current_stock >= ?` (or the batch-level equivalent, `qty_on_hand >= ?`) — never a separate SELECT-then-UPDATE.
2. `products.current_stock` must remain equal to `SUM(product_batches.qty_on_hand)` for any `track_batches=1` product — no code path may update one without the other in the same transaction.
3. `purchase_order_items.received_qty` must never exceed `ordered_qty` (enforced today both by the guarded UPDATE and by the `chk_po_items_received_not_over_ordered` CHECK constraint) — both layers must be preserved.
4. `customer_debts.paid_amount` must never exceed `total_amount` (guarded UPDATE + CHECK constraint, same dual-layer principle).
5. Reference numbers must remain generated only via `SELECT ... FOR UPDATE` on `reference_counters` inside the issuing transaction — never via `COUNT(*)` or an application-generated random/sequential value outside a lock.
6. An idempotency token must remain claimed as the *first* statement inside the transaction it protects, so a rollback releases the claim.
7. No monetary column may become a floating-point type.
8. Generated columns (`subtotal`, `balance`, `status`) must remain generated, never converted to application-maintained cache columns.
9. `audit_log` must remain append-only — no UPDATE/DELETE code path against it may ever be introduced.

---

## 8. Multi-Tenancy Strategy (FUTURE architecture only — not implemented)

**This entire section describes a target concept only. No multi-tenancy exists today, none is being implemented by this document, and no schema change is authorized by this section.**

**Target concept:**

```text
Business / Tenant
       ↓
Users
Products
Suppliers
Customers
Sales
Purchasing
Cash
Expenses
Settings
```

**Tenant ownership:** every core entity (Products, Suppliers, Customers, Sales, Purchase Orders, Cash accounts, Expenses, Settings) would belong to exactly one Business/Tenant. A `business_id` (or `tenant_id`) foreign key would be added to each of these tables.

**`business_id` / `tenant_id` concept:** a single new top-level entity (`businesses`), with every existing table gaining a scoping column referencing it. Users would gain a membership relationship to one or more businesses (exact shape — single business per user vs. many-to-many — is an open decision, not made by this document).

**Tenant isolation:** every query in every Application Service would need to filter by the acting user's current business context; every unique constraint currently global (`products.sku UNIQUE`, `users.email UNIQUE`) would need to become scoped (`UNIQUE(business_id, sku)`) unless a deliberate cross-tenant uniqueness reason exists (e.g. `users.email` might remain globally unique if one login identity can belong to multiple businesses).

**Authorization boundaries:** role checks (`canWrite()`/`isAdmin()`) would need to become business-scoped — an Admin of Business A must never be able to act as Admin of Business B.

**Cross-tenant access prevention:** every read and write path would need a business-scope check as a *first-class, structural* requirement, not an optional filter — the same discipline this codebase already applies to RBAC (server-side, not just hidden UI) would need to apply identically to tenant scoping.

**Migration implications:** this is schema-wide, not a single migration. Reference counters (`reference_counters.counter_key`) would need to become per-business (or the reference-number format would need to accommodate cross-business uniqueness differently). `app_settings` would need to become a per-business table rather than a singleton row.

**Why this is NOT being implemented now:** the current v1.0.0 scope is a single shop (PCTN). No second-tenant requirement has been evidenced. Implementing tenant-scoping speculatively, before a real second business exists to validate the design against, risks building the wrong shape (e.g. guessing at the users↔businesses cardinality) and re-touching every table twice. This is deliberately deferred to Stage 4/5 of the roadmap (§20), to be designed against a real requirement, not a hypothetical one.

---

## 9. Identity & RBAC

**Current (existing, unchanged by this document):** three roles — Admin, User, Viewer — seeded in the `roles` table, gated via `includes/auth_check.php`'s `isAdmin()`/`isViewer()`/`canWrite()`.

**Future direction (not implemented, no role change is made by this document):**

```text
Owner
Manager
Cashier
Viewer
```

This future role set is intended to map more naturally onto a commercial multi-shop product than the current generic Admin/User/Viewer split — e.g. "Owner" as the business-level super-role (maps conceptually to today's Admin, but scoped to one business under multi-tenancy), "Manager" as a write-capable role without full Owner privileges (today's User), "Cashier" as a narrowly-scoped POS-only write role (does not exist today — today's User role can write everywhere `canWrite()` is checked), "Viewer" unchanged.

**This document does not implement, rename, or migrate any role.** The mapping above is recorded so that a future role-model change has a documented target to work toward, deliberately deferred until it is actually undertaken (see ADR context notes — no ADR in this set schedules this work).

---

## 10. Sales + Inventory Transaction Architecture

**Current, confirmed:** a sale (`pos/index.php`) already flows conceptually as:

```text
Sale
 ↓
Inventory mutation      (recordStockOut() / FEFO batch consumption)
 ↓
Cash OR Receivable      (cash_received captured directly on the sale row,
                          OR a customer_debts row for a credit sale)
 ↓
Audit                   (not yet applied to every sale event directly —
                          stock_transactions itself is treated as inherently
                          append-only/permanent, per existing schema comments,
                          rather than additionally audit_log-logged)
```

**Future direction:** this conceptual chain is the correct shape to preserve and extend as Cash & Money (§11) and Expenses are added — a Sale's cash effect should become a Cash & Money transaction (`SALE` type) rather than only a `stock_transactions.cash_received` value, once that module exists. This is not being implemented now.

**Preserved, non-negotiable:** the existing transaction and concurrency guarantees described in §14 apply to every step of this chain today and must continue to apply as it is extended — a future Cash & Money integration must not introduce a second, competing transaction boundary around the same sale event; it must be added *inside* the sale's existing transaction (the same way `recordCreditSale()` already adds the debt-creation step inside the sale's own transaction today), not as a separate, eventually-consistent step.

---

## 11. Cash & Money Architecture (FUTURE — not implemented)

**This section describes a target module. No Cash & Money module exists today. This document does not implement it.**

Framed deliberately as **Cash & Money Management**, not a consumer-style "wallet" — this is a business cash-position ledger, not a stored-value account a customer holds.

**Target structure:**

```text
Cash Accounts
├── Shop Cash
├── Bank
└── Other Accounts
```

**Potential transaction types (future):**

```text
SALE
DEBT_PAYMENT
EXPENSE
OWNER_DEPOSIT
OWNER_WITHDRAWAL
TRANSFER
OPENING_BALANCE
```

**Balance definition (future — transaction-derived, never a stored/editable field):**

```text
Opening Balance
+ Sales
+ Debt Payments
+ Owner Deposits
- Expenses
- Owner Withdrawals
± Transfers
= Current Balance
```

**Explicit rule:** a Cash Account's balance must be **computed from its transaction history**, following the exact same architectural principle this codebase already applies to `products.current_stock` (a maintained cache, but one that is only ever changed by a guarded, transactional mutation — never a free-form "set balance to X" write) and to `customer_debts.balance` (a `GENERATED ALWAYS` column, a pure function of `total_amount`/`paid_amount`). **A Cash Account balance must never be directly, manually editable** through any future UI or API — every change must be the result of recording a typed transaction (one of the types above), mirroring the "store the line, derive/guard the aggregate" pattern already proven throughout this codebase (§7).

**Relationship to existing modules:** `SALE` and `DEBT_PAYMENT` transaction types would be produced as a *side effect* of the existing Sales/Debt modules' own transactions (§10) — Cash & Money would be a consumer of those events, not a duplicate source of truth for them. `EXPENSE` would be a new event source (§4) with no existing analog today.

**This document does not implement any table, migration, or code for this module.**

---

## 12. API Strategy (FUTURE — not implemented)

**This section describes a target. No REST API exists today, and none is created by this document.**

```text
Web UI ─┐
POS ────┼── API / HTTP Layer
Mobile ─┘
             ↓
      Application Services
             ↓
          Domain
             ↓
            DB
```

**Non-negotiable target rule:** the API layer must call into Application Services exactly as the existing page scripts already do for Stock/Debt/Purchase Orders today — it must **never** manipulate database tables directly. This is not a new constraint invented for the API; it is the same rule the current codebase already follows (every page script routes mutation through `includes/*.php`, never raw `INSERT`/`UPDATE` inline for stock/debt/PO — the exception today is the still-inline Category/Unit/Supplier CRUD, which is exactly why §6's Application Service extraction should happen *before*, not after, an API is built on top of those modules).

**What is already API-ready today (evidenced, not aspirational):** `createPurchaseOrder()`, `submitPurchaseOrder()`, `receivePurchaseOrder()`, `cancelPurchaseOrder()`, `recordStockIn()`, `recordStockOut()`, `adjustStock()`, `recordCreditSale()`, `recordDebtPayment()` — all already HTTP-agnostic today, callable from any future API controller with no changes.

**What is not yet API-ready:** Category/Unit/Supplier CRUD (still page-embedded); authentication (session-based only — an API serving non-browser clients would need a token/API-key strategy, addressed in §13); CSRF (a synchronizer token in a hidden form field does not translate directly to a JSON API — a different mechanism, or reliance on token-based auth instead of session+CSRF, would be needed for API routes specifically).

**This document does not select an API framework, does not define endpoint routes, and does not implement any API code.**

---

## 13. Authentication & Security Architecture

**Current (existing):**
- Session authentication (`$_SESSION['user_id']`, `session_start()` at the top of every entry point via `auth_check.php`/`lang.php`/`index.php`/`logout.php`).
- CSRF: synchronizer token pattern, `hash_equals()` comparison, verified before every mutating handler.
- RBAC: `isAdmin()`/`isViewer()`/`canWrite()`, centralized in `auth_check.php`, checked server-side on every write path sampled.
- Password handling: `password_hash()`/`password_verify()` with `PASSWORD_DEFAULT`; passwords structurally excluded from audit snapshots (`userAuditSnapshot()`'s explicit field allowlist).

**Future API authentication strategy (not implemented):** a token/API-key mechanism for non-browser clients, designed to coexist with (not replace) the existing session-based web UI authentication. No specific scheme (JWT, opaque token, OAuth2) is selected by this document.

**Future tenant isolation (not implemented):** see §8 — every future auth check would need a business-scope dimension in addition to role.

**Identified findings, precise and deployment-context-specific:**

- **P0 — Repository-wide webroot exposure risk.** The reference `docker/nginx/default.conf` has only a `location /` (`try_files`) and a `location ~ \.php$` block; combined with `docker-compose.yml` bind-mounting the entire repository (`.:/var/www`) as the web root for both `app` and `nginx`, this configuration serves `database/schema.sql`, `database/seed.sql`, `.git/`, `composer.json`, `RECOVERY.md`, and every test fixture as directly downloadable static files. This is a deployment-configuration finding, not an application-code defect — the fix is a `public/`-only web root, not a change to any PHP business logic.
- **P1 — Session regeneration.** No call to `session_regenerate_id()` exists anywhere in the codebase (confirmed by search). Login does not rotate the session identifier after establishing an authenticated session, which is the standard defense against session fixation.
- **P1 — Login brute-force / rate-limit protection.** `auth/login.php` performs `password_verify()` with no attempt counting, delay, or lockout of any kind.
- **P2 — Session cookie hardening / security headers.** No explicit `session_set_cookie_params()`/`ini_set()` for `httponly`/`secure`/`samesite`, and no `X-Frame-Options`/`Content-Security-Policy`/`X-Content-Type-Options`/`Strict-Transport-Security` headers are set anywhere in application code or the Nginx reference config.

**Secret management:** database credentials flow through `getenv()` in `config/db.php` with local-development-friendly defaults; no secret is hardcoded in application PHP. The Docker Compose reference file does contain a plaintext development database password — acceptable for local development, not acceptable to carry into a production compose file unmodified.

**Production deployment requirements (target, not implemented by this document):** a `public/`-only web root; HTTPS termination at the reverse proxy; environment secrets injected by the hosting platform, never committed; the database reachable only from the application tier, not host-exposed; session regeneration and login rate-limiting in place before any internet-facing deployment.

---

## 14. Concurrency & Idempotency Invariants

**This section is architecture law, not a suggestion.** Every item below is confirmed present in `main` today and must be preserved by any future change, including any refactor, extraction, or framework migration.

- **Transactions:** every stock/debt/purchase-order mutation is a single `beginTransaction()`→`commit()`/`rollBack()` unit, owned end to end by one function.
- **`SELECT ... FOR UPDATE`:** used to serialize any decision that depends on a row's current state before it is mutated — the product row before any batch decision (Stock In/Out), the `purchase_orders` row before any status transition (Submit/Receive/Cancel), the `reference_counters` row before issuing a number.
- **Guarded UPDATEs:** the primary concurrency mechanism for simple numeric invariants — `UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND current_stock >= ?`, `UPDATE purchase_order_items SET received_qty = received_qty + ? WHERE ... AND received_qty + ? <= ordered_qty`, `UPDATE customer_debts SET paid_amount = paid_amount + ? WHERE ... AND paid_amount + ? <= total_amount`. The guard condition lives in the same statement as the write, under the database's own row lock — never a separate SELECT-then-check in application code.
- **Deterministic lock ordering:** product-row lock always before batch-row lock, consistently, across every function in `includes/stock.php` — documented and cross-referenced in code comments specifically to prevent a future addition from introducing a reversed-order deadlock.
- **Reference counter locking:** `SELECT next_value FROM reference_counters WHERE counter_key = ? FOR UPDATE` then `UPDATE`, inside the issuing transaction.
- **Idempotency tokens:** claimed via a single `INSERT` into `idempotency_keys` (protected by a `UNIQUE(token)` constraint), always as the *first* statement inside the transaction it protects, so a rollback releases the claim for a legitimate retry.
- **Unique constraints:** used as defense-in-depth backstops behind application-level concurrency control, never as the sole mechanism (e.g. `product_batches`' identity `UNIQUE` constraint cannot help when `batch_number`/`expiry_date` are `NULL`, so the product-row lock is the real guarantee there — documented explicitly in code).
- **CAS/state protection:** optimistic-locking pattern (`UPDATE ... WHERE current_value = ?`) used for Stock Adjustment and Batch Adjustment, doubling as duplicate-submission protection where no idempotency token is used, because a resubmitted request's "expected" value no longer matches after the first commit.

**Future changes must not weaken any of the above.** Specifically: no future refactor may replace a guarded UPDATE with a SELECT-then-UPDATE; no future extraction may move a mutation across a transaction boundary such that it is no longer atomic with the check that protects it; no future API layer may bypass an existing idempotency-token or CAS mechanism in the name of "simplifying" the interface.

---

## 15. Audit Architecture

**Current (existing), structured as:**

```text
Actor        → audit_log.user_id
Action       → audit_log.action (create | update | delete)
Entity       → audit_log.entity_type + entity_id
Before       → audit_log.before_snapshot (JSON, entity-level)
After        → audit_log.after_snapshot (JSON, entity-level)
Timestamp    → audit_log.created_at
```

**Append-only intent:** `audit_log` has no UPDATE/DELETE code path against it anywhere in the application — this is a designed invariant (documented in `schema.sql`'s own comment: "this table must never become editable through the app, not even for Admin") and must remain true through every future change.

**Transaction coupling:** `logAudit()` is always called inside the same transaction as the mutation it records — a failed audit write rolls back the mutation with it, rather than allowing a mutation to succeed with a silently-missing audit trail.

**Password/secret redaction:** enforced structurally, not conventionally — `userAuditSnapshot()` is a literal field allowlist that never reads `$row['password']`, so a future column added to `users` is excluded by default and requires a deliberate edit to ever appear in a snapshot.

**Future coverage verification (not performed by this document):** whether every mutation path in the application is currently audited was not exhaustively verified as part of this architecture document (it was partially sampled in the prior audit) — a full coverage pass is a legitimate future verification task, not something this document asserts as complete or incomplete.

---

## 16. Testing Architecture

**Current test categories (existing):**

- **Unit** (`tests/Unit/`) — pure functions (currency conversion, low-stock tier classification, validation helpers, localization parity, backup failure messages).
- **Integration** (`tests/Integration/`) — domain functions against a real test database (stock, sales, debt, purchase order lifecycle phases, reference generation, idempotency, batch adjustment).
- **HTTP** (`tests/Http/`) — full request/response round-trips against a real `php -S` server (authorization, CSRF, FEFO integration, purchase order HTTP flows, batch UI).
- **Concurrency** (`tests/Concurrency/`) — genuine multi-process races via `proc_open()` worker scripts.
- **Schema** (`tests/Schema/`) — migration-sequence-vs-`schema.sql` integrity.
- **Backup/Restore** (`tests/Backup/`) — full dump/restore round-trip verification.

**Future expectation for any new module (including P3-B and beyond):** every new business mutation must ship with Integration test coverage at minimum, HTTP test coverage where a new user-facing form/action is introduced, and Concurrency test coverage where the new mutation touches a resource another mutation can race against (stock, a shared counter, a shared balance). This is not a new rule — it is the pattern this codebase has already followed for every phase through P3-A, made explicit here.

**Concurrency tests must remain genuine multi-process tests** wherever a real database-level race is being verified — an in-process-only "simulate concurrency" test (e.g. calling two functions sequentially and asserting an exception) does not exercise the actual row-lock/guarded-UPDATE mechanism under real contention and must not be substituted for the existing `proc_open()`-based worker-script pattern for any invariant listed in §14.

---

## 17. Deployment Architecture

**Target (future — not implemented by this document):**

```text
Internet
   ↓
Reverse Proxy        (HTTPS termination)
   ↓
Public Web Root       (only index.php, assets/, uploads/ — nothing else)
   ↓
PHP Application        (config/, includes/, every module directory —
                         OUTSIDE the web-servable tree)
   ↓
Private Database       (reachable only from the application tier)
```

**Current (existing), for comparison:** Nginx `root` is the entire repository; there is no `public/`-only boundary (§13, P0 finding).

**Target requirements:**
- **Public webroot:** contains only what a browser must be able to fetch directly — entry scripts and static assets. Every `.php` file that is a page script would still be reachable (that's the application), but non-PHP files (SQL, markdown, `.git`, config) must not be.
- **Private source/config:** `config/`, `database/`, `tests/`, documentation files live outside the web-servable tree, or are explicitly blocked from being served as static files if a single-webroot layout is retained for a given hosting constraint.
- **Database isolation:** the database must be reachable only from the application tier's network, never host-exposed on a public interface.
- **HTTPS:** terminated at the reverse proxy for any non-local deployment.
- **Environment secrets:** injected by the hosting platform/orchestrator, never committed to the repository.
- **Production Docker requirements:** a production Compose/Dockerfile variant would differ from the current development-convenience `docker-compose.yml` (no host-exposed database port, no plaintext credentials in the compose file itself, a `public/`-scoped Nginx root).

**This document does not implement any of the above.** It records the target so that Stage 1 of the roadmap (§20) has a concrete, agreed destination.

---

## 18. Framework Strategy

**Decision: framework selection is deferred.** No framework is chosen by this document, and none should be inferred from any diagram in this document (the layered diagrams in §5/§6/§12 are framework-agnostic architecture concepts, not a specific framework's terminology).

**Possible future candidates** (recorded for awareness only, not evaluated or ranked here): Laravel, ASP.NET Core, NestJS, Spring Boot, Django/FastAPI.

**Architecture must remain framework-neutral** until a deliberate, separately-justified decision is made — this document's entire purpose is to describe a target that is reachable regardless of which framework (if any) is eventually chosen, or whether the application remains plain PHP indefinitely. See ADR-009.

---

## 19. Versioning & Releases

```text
v1.0.0  = stable baseline
v1.0.x  = patches / bug/security fixes
v1.x.0  = backward-compatible features
v2.0.0  = breaking product/architecture changes
```

**Project phases (P1, P2, P3-A, P3-B, ...) and semantic versions are different concepts and must not be conflated.** A phase name describes a unit of engineering work tracked in `DEVELOPMENT.md`; a semantic version describes the public compatibility contract of a released artifact. Multiple phases may land within a single `v1.x.0` release; a single phase's completion does not by itself require a version bump, and a version bump does not by itself imply a phase boundary. `DEVELOPMENT.md` remains the authoritative phase history; this document and `README.md` remain the authoritative version/release record.

---

## 20. Migration Roadmap

```text
v1.0.0
 ↓
Architecture Baseline              (this document + ADRs — documentation only)
 ↓
Security / Deployment Hardening    (§13 P0/P1 items: public/ webroot,
                                     session regeneration, login rate-limit)
 ↓
P3-B                                (Low Stock → Assisted Draft PO —
                                     proceeds independently, see §23)
 ↓
v1.x evolution                      (continued incremental features on the
                                     current architecture, no framework change)
 ↓
Application Service extraction      (Category/Unit/Supplier CRUD brought up
                                     to the same standard as Stock/Debt/PO)
 ↓
API boundary                        (§12 — token auth strategy decided,
                                     first API-ready modules identified)
 ↓
Commercial architecture             (Cash & Money §11, Expenses, role model §9)
 ↓
Multi-tenancy                       (§8 — only once a real second-business
                                     requirement exists)
 ↓
V2                                  (whatever combination of the above,
                                     plus any breaking change, actually
                                     warrants a major version)
```

**This roadmap does not prescribe unnecessary rewrites at any step.** Each stage is additive to what exists; no stage requires discarding a prior stage's work. A stage may be skipped, reordered, or deferred indefinitely if the business need that would justify it never materializes (multi-tenancy in particular, per §8, is not scheduled — it is positioned).

---

## 21. What Must NOT Be Rewritten

The following are proven, evidenced, load-bearing mechanisms. They must be preserved through every stage of the roadmap above, including any eventual framework adoption:

- Stock concurrency (guarded UPDATEs, FEFO batch consumption under row lock).
- Idempotency (token-claim-inside-protected-transaction pattern).
- Purchase Order state machine (status re-validated under lock at the moment of every write).
- Transaction ownership (one domain function, one transaction, end to end).
- Reference-counter row locking.
- Audit semantics (append-only, transaction-coupled, structural password redaction).
- Money precision (`DECIMAL` columns, single-point currency conversion).
- FK integrity (deliberate, business-reasoned cascade behavior).
- CSRF (synchronizer token, server-side verification before every mutation).
- RBAC (centralized `canWrite()`/`isAdmin()`, server-side enforced).
- Backup/restore (pure-PHP, shell-free, generated-column-aware).
- The regression test suite (Unit/Integration/Http/Concurrency/Schema/Backup) — any migration effort must carry equivalent coverage forward before the old implementation is retired, never after.

---

## 22. Claude Code Engineering Rules

These rules govern how future engineering work (by Claude Code or any contributor) is expected to proceed against this architecture:

1. Inspect before modifying — read the current, real code and tests before proposing or making a change; never assume prior documentation is still accurate without checking.
2. Smallest correct change — implement exactly what a task requires, nothing broader.
3. No unrelated refactor — a bug fix or feature does not carry incidental cleanup of unrelated code along with it.
4. No framework migration without explicit approval — §18's deferral stands until a human decision changes it.
5. No database redesign without architecture approval — schema changes beyond an additive migration require the same deliberate review this document itself received.
6. Never weaken concurrency controls — §14's invariants are non-negotiable.
7. Never weaken idempotency — §14's invariants are non-negotiable.
8. Business mutations require appropriate tests — §16's expectations apply to every new mutation, no exceptions.
9. Security-sensitive changes require verification — a change touching auth, CSRF, RBAC, or session handling must be verified (tested and reasoned about explicitly), not merely asserted correct.
10. Never commit/push/merge on the user's behalf — all Git operations remain the user's own action unless explicitly and separately authorized for a specific task.

---

## 23. P3-B Position

**P3-B — Low Stock → Assisted Draft Purchase Order — may proceed after this architecture baseline is documented and accepted.**

P3-B does **not** require:
- a framework migration,
- multi-tenancy,
- a REST API,
- a large refactor.

P3-B should continue using the existing Purchase Order application/domain functions (`createPurchaseOrder()`, unmodified) and must preserve every concurrency/idempotency behavior described in §14. This document introduces no new constraint on P3-B beyond what its own prior, independently-produced implementation plan already established; it exists to confirm that nothing in this architecture baseline changes that plan.

---

*End of PCTN Inventory Architecture Blueprint v1.0. See `docs/adr/` for the individual Architecture Decision Records this blueprint is backed by.*
