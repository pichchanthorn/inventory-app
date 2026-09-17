# Development Log

This file records how this project was built, the decisions behind it, and
the problems solved along the way — for anyone (including future-you)
picking this codebase back up.

---

## Phase 1 — Foundation

Built the starter structure: session-based Login/Register, Dashboard, and
a fully working **Categories** module (list/search/create/edit/delete)
used as the template for every other module.

**Design decision:** a dark, terminal/scanner-inspired visual identity
(`assets/style.css`) — deliberately not a copy of the classroom demo's
styling, so the work is clearly original.

**Data decision:** sample data (categories, suppliers, products) uses an
original "mobile accessories shop" theme, not the classroom demo's data —
same reasoning: keep the submission clearly the student's own.

---

## Phase 2 — Completing the modules

Built out the remaining CRUD modules by copying the Categories pattern:
**Units, Suppliers, Products** (with category/supplier/unit foreign keys
and auto-calculated margin %).

Then the more advanced modules, each writing to two tables inside a single
DB transaction (`stock_transactions` + `stock_transaction_items`):

- **Stock In** — multi-line receiving form, increases `current_stock`
- **Stock Out** — multi-line issuing form, decreases `current_stock` with
  an availability check before committing
- **Stock Adjustments** — sets an exact stock count with a required reason
- **Stock Reports** — overview/log/by-product tabs + CSV export

Added **Profile** (name/email/password, avatar upload with server-side
MIME + size validation).

---

## Phase 3 — Local deployment issues (and fixes)

**Problem:** MySQL wouldn't start in XAMPP — logs showed it was reading
data files from a `C:\laragon\...` path instead of its own, because two
separate local server stacks (XAMPP and Laragon) were installed and
conflicting.
**Fix:** clean reinstall of XAMPP to the default `C:\xampp` path, kept
fully separate from the Laragon installation.

**Problem:** after deploying to `htdocs\inventory-app\` (a subfolder, not
the web root), every internal link/redirect returned `404 Not Found`.
**Root cause:** all links and `header('Location: ...')` redirects were
hardcoded as root-absolute paths (e.g. `/auth/login.php`), which only work
when the app _is_ the web root.
**Fix:** added `config/base_url.php`, which computes the app's real URL
prefix at runtime by diffing `$_SERVER['DOCUMENT_ROOT']` against the
script's own folder. Every link/redirect now goes through
`BASE_URL . '/path'` instead of a hardcoded `/path`. This makes the app
portable — it works whether it's deployed at the domain root or in any
subfolder, with no code changes needed.

---

## Phase 4 — Polish

- Added a **Light / Dark / System** theme toggle (persisted in
  `localStorage`, applied via a `body.theme-light` CSS override block, with
  an anti-flash inline script in `<head>`).
- Added `database/seed.sql` — optional sample rows so a fresh install isn't
  completely empty.
- Wrote `README.md` with setup instructions and screenshots.

---

## Phase 5 — Access control (Admin vs. User)

**Decision point:** should staff self-register, or should the business
owner (Admin) create accounts for them?

Considered two approaches:

1. _Company email-domain restriction_ — rejected: assumes the business
   already has a custom email domain (many small businesses don't), and
   still can't stop anyone with that domain from self-registering with the
   wrong access level.
2. _Admin creates every account_ — **chosen.** Matches how real internal
   business tools (POS systems, accounting software) work: the owner
   decides who gets in and at what access level, from day one. No
   dependency on company infrastructure.

**Implemented:**

- `roles` table (`Admin` / `User` / `Viewer`) already existed in the schema
  but was never enforced — added an `isAdmin()` helper in
  `includes/auth_check.php` and gated every `delete` action (Categories,
  Units, Suppliers, Products) behind it **server-side**, not just hiding
  the button in the UI.
- New Admin-only `user/index.php` page: lists all accounts, lets an Admin
  change any other user's role. An Admin **cannot** change their own role
  (prevents accidental self-lockout).
- New **"+ Add user"** flow: Admin sets name/email/temporary password/role
  directly. Added a `must_change_password` column — new accounts are
  forced to `profile.php` to set their own password before using the rest
  of the app, then the flag clears automatically.
- `auth/register.php` (public self-registration) was **left in place** but
  flagged with a comment — a deliberate open decision, not forgotten.

---

## Phase 6 — Viewer enforcement, concurrency safety, and POS

**Viewer enforcement:** Phase 5 gated *delete* actions behind `isAdmin()`,
but the `Viewer` role itself still wasn't enforced anywhere else — a Viewer
could create and edit records exactly like a `User`. Added a `canWrite()`
helper (`!isViewer()`) in `includes/auth_check.php` and gated every
create/update path — server-side, not just hidden buttons — across all
8 write-capable modules: `product`, `category`, `unit`, `supplier`,
`stock-in`, `stock-out`, `stock-adjustment`, and later `pos`. Public
self-registration (`auth/register.php`) was also switched to default new
accounts to `Viewer` instead of `User`, so an anonymous signup gets
read-only access until an Admin explicitly upgrades them.

**Concurrency safety:** Stock In/Out/Adjustment previously updated
`current_stock` with a plain read-then-write, which isn't safe under
concurrent requests (two simultaneous Stock Outs can both read the same
starting quantity and push stock negative). Extracted all stock-mutating
logic into a shared `includes/stock.php`, rebuilt around atomic guarded
`UPDATE` statements — Stock Out uses a decrement guard
(`UPDATE ... WHERE current_stock >= ?`), Adjustment uses an optimistic lock
(`UPDATE ... WHERE current_stock = ?`) — and both detect a lost race via
`rowCount() === 0`, throwing a `StockConflictException` instead of silently
corrupting stock. A database-level `CHECK (current_stock >= 0)` constraint
was added as a final safety net. Verified against real concurrent-process
races (multiple simultaneous callers), not just code review.

**POS MVP:** Added a Point of Sale module (`pos/index.php`) that reuses the
same `recordStockOut()` path as Stock Out — a sale is stock leaving, just
like a manual Stock Out, so it gets the same locking guarantees — tagged
with a new `sale` transaction type
(`database/migrations/001_add_sale_transaction_type.sql`) rather than a
parallel code path. Cart entry, display-only cash received / change due,
and a printable receipt.

---

## Phase E — Reporting Integration, Price Validation, and Duplicate-Sale Prevention

A follow-up audit of the shipped POS module found three gaps: `sale`
transactions were invisible to the dashboard movement chart and
stock-report KPIs (both only queried `in`/`out`/`adjustment`); there was
no server-side floor on `unit_price` in POS or Stock Out; and a browser
refresh right after checkout could resubmit the sale. Fixed by widening
the reporting queries to include `sale`, rejecting negative unit prices
server-side in `resolvePriceField()` (zero remains valid — a free/
promotional line), and switching POS's success path to Post/Redirect/Get
with the receipt held in a one-time session flash, so a refresh
re-fetches the GET instead of resubmitting the POST.

---

## Phase F — UI/UX Polish and Khmer/English Localization

Added full bilingual support: `includes/lang.php` reads `$_SESSION['lang']`
(defaulting to `en`, switchable via a `?lang=` query param) and loads the
matching `lang/en.php` or `lang/km.php` translation table, with every
user-facing string in the app routed through a `__($key)` helper rather
than hardcoded text. `localizedDate()` additionally localizes the
month-name portion of a formatted date into Khmer (PHP's own `date()` has
no built-in Khmer locale), leaving every other date component and
English-locale output as a pure pass-through.

Alongside localization, a series of UI/UX polish passes improved Khmer
typography and product-modal sizing, consolidated role badges, added a
search loading state, fixed a validation gap in the modal-based edit
forms, localized the brand/title/theme labels/dates/close-button labels
throughout the app, corrected dark-theme alert styling, and polished the
login/register screens (autofill theming, a password-strength meter).

---

## Phase H — POS Cash/Change Persistence and Receipt Lookup

`stock_transactions.cash_received` (`database/migrations/002_add_pos_cash_received.sql`)
persists the cash tendered for a POS sale, so a receipt can be reopened
later and still show what the customer paid and was given in change —
previously this was display-only at the moment of sale and lost
afterward. `change_due` is deliberately not stored alongside it; it is
always derived at read time as cash received minus the line-item total,
the same way every other total in the app is computed from line items
rather than cached. `pos/receipt.php` adds a standalone, read-only
lookup for any past sale's receipt by reference number, reusing the same
receipt markup POS's own post-checkout flash renders
(`includes/receipt_view.php`), so the two views can never drift apart. A
`NULL` `cash_received` on an older, pre-migration row is rendered as "not
recorded," never as a false $0.00.

---

## Phase I — Reference-Number Concurrency & Idempotency Hardening

A follow-up hardening pass, prompted by real duplicate-submission and
race-condition risk in the concurrency-sensitive write paths added since
Phase 6: two users (or one user's double-click, browser retry, or two
open tabs) hitting the same form at close to the same moment.

**I2-B1 — POS idempotency.** Added `idempotency_keys` (`token UNIQUE`)
and `claimIdempotencyToken()` in `includes/stock.php`: one `INSERT`
against the unique constraint, claimed as the first statement inside the
caller's own transaction, so a rolled-back attempt releases its claim
automatically and only a committed sale permanently consumes its token.
`recordStockOut()` and `recordCreditSale()` both gained an optional
trailing `$idempotencyToken` parameter, and `pos/index.php` wires a
fresh per-form-render hidden token through both its cash and credit
paths.

**I3-A — Reference-number generation race fixed.** `nextStockReference()`
/`nextDebtReference()` previously computed the next "PREFIX-000123"
number via a plain `SELECT COUNT(*) + 1`, with nothing holding a lock
between that read and the later `INSERT` — two concurrent transactions
could read the same count and race to insert the same reference, failing
one caller's otherwise-legitimate operation with an uncaught duplicate-key
error. Replaced with `database/migrations/012_add_reference_counters.sql`
(one row per counter, seeded from existing row counts) and
`nextReferenceSequence()` in `includes/stock.php`:
`SELECT next_value FROM reference_counters WHERE counter_key = ? FOR UPDATE`
+ `UPDATE`, run inside the caller's own transaction — the row lock
serializes concurrent callers instead of letting them race, the same
principle already used by `idempotency_keys`. STI/STO/ADJ/SAL still share
one counter (`stock_transactions`), DBT keeps its own
(`customer_debts`), and the "PREFIX-000123" format is unchanged. A
rolled-back transaction's counter increment rolls back with it, so a
failed attempt never permanently consumes a number. Verified against
real concurrent-process testing (10 independent OS processes racing for
the same counter, plus a 9-process mixed Stock In/Out/Adjustment batch):
fully unique, consecutive references with zero errors and zero
duplicates.

**I3-B — Idempotency extended to Stock In and Debt Payment.**
`recordStockIn()` and `recordDebtPayment()` had no duplicate-submission
protection of their own — the `stock_transactions.reference` UNIQUE
constraint didn't catch a duplicate (each gets its own valid reference),
and the debt overpayment guard only coincidentally blocked a duplicate
when it would exceed `total_amount`. Reused the existing I2-B1
`idempotency_keys`/`claimIdempotencyToken()` mechanism unchanged — no new
table, no new migration — giving both functions the same optional
trailing `$idempotencyToken` parameter `recordStockOut()`/
`recordCreditSale()` already had. `stock-in/index.php` and
`customer/view.php`'s payment modal each render a fresh per-form token
and handle `IdempotencyConflictException` with a localized (English +
Khmer) duplicate-submission message. Verified against true
concurrent-process testing (8 independent OS processes racing for the
same token, for both Stock In and Debt Payment): exactly one success and
seven correctly-rejected duplicates each, with zero errors.

**SIO-01 — Standalone Stock Out idempotency.** `recordStockOut()` had
accepted an optional `$idempotencyToken` since I2-B1 (for POS's own use
of it), but the standalone Stock Out page never passed one — a duplicate
manual submission with enough stock to succeed twice could silently
record two independent removals for one physical event. Closed the same
way as I3-B: a fresh per-form token on `stock-out/index.php`, passed
through to `recordStockOut()`, same duplicate-submission handling.
Verified the same way (8 concurrent OS processes, one success and seven
correctly-rejected duplicates, zero errors), plus a regression check
confirming POS, Stock In, and Debt Payment were unaffected.

All four are covered by dedicated automated tests
(`tests/Integration/ReferenceTest.php`, `tests/Integration/IdempotencyTest.php`,
`tests/Concurrency/ConcurrencyTest.php`), part of the PHPUnit suite Phase
J1 (below) later formalized into the project's standing regression
coverage.

**Remaining / optional hardening:** Stock Adjustment (`adjustStock()`)
has no explicit idempotency token, unlike the four paths above. This is
not currently a demonstrated data-integrity defect — Adjustment already
has its own optimistic-lock protection (`UPDATE ... WHERE current_stock
= ?`, from Phase 6's concurrency-safety work), so a stale-page duplicate
submission is independently rejected as a `StockConflictException`
rather than silently double-applied. Treated as optional future
hardening for consistency with the other four paths' UX (a specific
"looks like a duplicate" message instead of a generic conflict error),
not as an unfinished I3-A/I3-B requirement.

---

## Business Invoice & Business Settings

The shared receipt/invoice partial (`includes/receipt_view.php`) was
extended into a printable, professional-looking business invoice, used
both by a fresh POS checkout and by the past-sale receipt lookup:

- **Business identity** — shop name, address, phone, and email, plus the
  app logo, rendered in the invoice header. These four fields
  (`app_settings.business_name` / `business_address` / `business_phone` /
  `business_email`, added in
  `database/migrations/013_add_business_settings.sql`) are nullable and
  independent of any one shop; an Admin sets them from Settings, and a
  blank field is simply omitted from the printed invoice rather than
  showing an empty line.
- **Package information** — a dedicated column shows each line item's
  `package_size` (e.g. "50kg"), falling back to an em dash when not set.
- **Payment status and due date** — for a credit sale, the invoice shows
  a payment-status badge (Paid / Partially Paid / Unpaid, driven by the
  same generated `customer_debts.status` column the Customers/Debts page
  reads) and, when set, the debt's due date. For a cash sale it always
  shows Paid.
- **Customer/debt information** — a credit sale's invoice additionally
  shows the customer's name and phone, the linked debt's own reference
  number, and the amount paid/remaining balance so far, in place of the
  cash-received/change-due lines a cash sale shows instead.
- **Print-friendly layout** — a dedicated Print Receipt button
  (`window.print()`) and print-only CSS rules keep the on-screen action
  buttons out of the printed page.

---

## Phase J — Production Reliability & Quality Hardening

A dedicated phase focused on verifying — not redesigning — the
application's core reliability guarantees: automated regression coverage,
continuous integration, and backup/restore recoverability, plus a manual
smoke check of the running application.

### J1 — Automated Regression Testing

Added a PHPUnit test suite (`tests/`, `composer.json`, `phpunit.xml.dist`)
covering the business-critical paths identified as highest-priority for
this application: stock in/out/adjustment integrity and negative-stock
prevention, transaction rollback, POS cash sales, idempotency protection,
customer debt creation/payment (including overpayment rejection), RBAC
and CSRF enforcement driven over real HTTP requests, reference-number
generation, and migration/schema integrity — each of the concurrency-
sensitive behaviors (stock-out races, debt-payment races, reference-number
generation races) verified using genuinely separate OS processes and
database connections, not simulated sequential calls. The suite runs
against a dedicated, disposable test database selected purely via
environment variables that `config/db.php` already supported, with a
hard safety guard that refuses to run unless the target database name is
explicitly test-scoped. At the end of J1, the suite comprised 65 tests
and 227 assertions, all passing.

### J2 — GitHub Actions CI

Added `.github/workflows/tests.yml`, the project's first GitHub Actions
workflow: it runs on pull requests targeting `main` and on pushes to
`main`, provisioning a disposable MySQL 8.4 service container (matching
the version already used by `docker-compose.yml`) and a scoped,
CI-local database user before running the full PHPUnit suite. No
production secrets or credentials are used anywhere in the workflow. An
initial version of the workflow was missing the database-provisioning
step for the scratch database J3's restore test needs (see below); this
was identified from an actual CI run and corrected in a follow-up commit.

### J3 — Backup & Restore Verification

Added `tests/Backup/BackupRestoreTest.php`, which exercises the real,
unmodified production backup function
(`includes/backup.php::streamDatabaseBackup()`) end to end: seeds a
representative dataset, captures a real backup, restores it into a
completely separate disposable database via the real `mysql` CLI client
(not a simulated import), and verifies the restored data — including
Khmer text, decimal/money precision, generated columns, foreign-key
relationships, `AUTO_INCREMENT` continuity, and that a database `CHECK`
constraint is still actually enforced post-restore, not merely present.
This added 2 tests and 79 assertions, bringing the full suite to **67
tests and 306 assertions, all passing** locally. A companion runbook,
`RECOVERY.md`, documents the manual recovery procedure for this
application's actual deployment model (a local machine, not a managed
cloud host).

The CI workflow initially failed the first time these tests ran on
GitHub Actions: the provisioning step created and granted access to the
two databases J1 already needed, but not the third, separate database
J3's restore test uses as its disposable restore target. This was
diagnosed from the actual CI error and fixed by extending the same
provisioning step to also create and grant that third database — a
two-line change to `.github/workflows/tests.yml`, with no change to any
test or application code.

### J4 — Local Smoke Verification

A manual-style smoke check of the application's business-critical pages
and flows (authentication, dashboard, product catalog, Stock In/Out/
Adjustment forms, POS, Customers & Debts, Stock Reports, User Management,
Settings, Audit Log, Profile, the Khmer/English language toggle, and the
availability of the backup action) — run against a freshly seeded
instance of the current codebase to confirm every page loads without a
PHP or SQL error and shows real data, rather than exercising the
automated test suite again. This was a smoke check, not a full audit,
and not a substitute for verifying the application on a real deployment
target directly.

---

## Current Engineering Status

PCTN Inventory V1 is a working small-business inventory, POS, and
customer-debt management system, built and hardened incrementally on a
plain PHP + MySQL/MariaDB architecture — no framework migration is
planned. Current engineering priorities, in order, are: accurate stock,
accurate sales, accurate debt/payment records, accurate invoices,
auditability, backup/recovery, security, and maintainability. Phase J
work verified the reliability side of that list (automated regression
coverage, CI, and backup/restore recoverability); the two known,
non-blocking gaps that remain are tracked below under Known Limitations.

---

## Known limitations / possible next steps

- [x] ~~Decide whether to restrict or remove public self-registration now
      that Admin-created accounts exist.~~ **Resolved** — self-registration
      is now disabled by default, gated behind a `SELF_REGISTRATION_ENABLED`
      env var. `auth/register.php` redirects to `login.php` (both GET and
      POST) instead of creating an account whenever the flag is off, and
      the "Register" link on `login.php` only renders when it's on. Left
      in place rather than removed, in case a future deployment (e.g. a
      second shop location) wants it back — re-enabling then needs only the
      env var, not a code change.
- [ ] No pagination on list pages yet (fine at current data volume; would
      matter at scale).
- [x] ~~`Viewer` role exists in the schema but has no read-only enforcement
      yet — currently behaves the same as `User`.~~ **Resolved in Phase 6**
      — enforced via `canWrite()`/`isViewer()` across all 8 write-capable
      modules.
- [x] ~~No automated tests — all verification so far has been manual /
      scripted `curl` and direct-PHP checks during development sessions,
      not a committed test suite.~~ **Resolved in Phase J1/J3** — a
      committed PHPUnit suite (`tests/`) now covers stock, POS, debt,
      RBAC/CSRF, reference-generation, migration/schema, and
      backup/restore behavior against a disposable test database, wired
      into GitHub Actions CI (Phase J2). See "Phase J — Production
      Reliability & Quality Hardening" above.
- [ ] Backup failure/error handling — `settings/index.php`'s backup
      action calls `includes/backup.php::streamDatabaseBackup()` with no
      `try`/`catch` around it, unlike every other mutating action in the
      app. A failure here would produce a truncated download with no
      on-screen explanation, not data loss or corruption. **Severity:
      LOW, deferred** — not a blocker to using the backup feature today.
- [ ] Backup audit logging — creating a database backup does not call
      `logAudit()`, so there is currently no audit-trail record of who
      exported a full copy of the database and when, unlike every other
      sensitive Admin action in the app. **Severity: MEDIUM, deferred**
      — a real accountability gap, not a data-safety or correctness
      issue; does not affect the backup feature's own reliability.

---

## Phase K4-6 — Batch-Specific Stock Adjustment (Product Batch & Expiry Management)

The Product Batch & Expiry Management feature (batch/expiry tracking on
Stock In, FEFO consumption on Stock Out/POS, and the invariant
`products.current_stock = SUM(product_batches.qty_on_hand)`) had one
remaining gap after its earlier phases: a `track_batches=1` product's
Stock Adjustment could only be rejected outright (no way to correct a
single batch's count without going through Stock In/Out). K4-6 closed
that gap in two implementation phases plus an independent final QA
pass.

**K4-6-1 — Batch-specific Stock Adjustment backend.** Added
`batchAdjustStock()` to `includes/stock.php`: for a tracked product, it
targets exactly one `product_batches` row by id (Target semantics,
matching the existing untracked `adjustStock()` convention), locks the
product row before the batch row (the same order every other
batch-touching function in that file already uses, so no new deadlock
risk), and synchronizes `products.current_stock` by the exact delta. A
caller-supplied `expectedQty` — read once, before the mutating
transaction begins — guards against a stale or duplicate submission via
direct comparison against the freshly locked value, deliberately instead
of an idempotency token (the CAS check already makes a duplicate/
resubmitted request fail safely on its own). No row is written to
`stock_transaction_item_batches` for an adjustment in either direction —
that table's `CHECK (qty > 0)` cannot represent a decrease — so batch
identity is instead recorded in the transaction's own note text plus a
queryable `audit_log` entry (`entity_type='product_batch'`), reusing
existing infrastructure rather than adding a new ledger shape. Opening-
balance batches remain adjustable without their `origin`/
`source_transaction_id` ever changing. Covered by 38 Integration tests
(`tests/Integration/StockBatchAdjustmentTest.php`) and 6 new OS-process
concurrency scenarios in `tests/Concurrency/ConcurrencyTest.php` (same
batch, different batches, vs. Stock In, vs. Stock Out, vs. POS, and
batch-A-vs-batch-B). Merged via PR #74.

**K4-6-2 — Batch-specific Stock Adjustment UI + HTTP integration.**
Extended `stock-adjustment/index.php` with a batch-selection flow for
tracked products, branching server-side on the product's own
`track_batches` column (never a client-supplied flag) so the existing
untracked flow stays byte-for-byte unchanged. Batch data is embedded
server-rendered (`BATCHES_BY_PRODUCT`, the same no-AJAX pattern the page's
`PRODUCTS` array already used) — selection posts `product_batches.id`
only, never `batch_number`/`expiry_date`, and the submitted `expected_qty`
is captured once, at batch-selection time, from that same server-rendered
snapshot rather than being re-read at submit time, preserving the CAS
guard `batchAdjustStock()` depends on. Target quantity and `expected_qty`
are both validated as true non-negative integers server-side — a value
like `"5.7"` is rejected outright, never silently truncated to `5`. New
`lang/en.php`/`lang/km.php` keys cover the batch selector, the empty-state
message, and the new UI-level validation errors, with full EN/KM parity.
`tests/Http/StockAdjustmentBatchTest.php` (18 tests) drives the real page
over real HTTP, covering the untracked legacy path, every tracked
scenario (increase/decrease/zero/no-op, expired batch, opening-balance
batch), rejection cases (missing/wrong-product batch id, negative/non-
integer/stale quantities), Viewer RBAC, and CSRF — each rejection
additionally asserted to cause no product/batch mutation, no audit row,
and no reference-number burn. Manually verified across 360×800/390×844/
412×915/1366×768 × English/Khmer × light/dark with no horizontal overflow.
Merged via PR #75.

**K4-6-3 — Final QA (independent verification pass).** A read-only audit
of the complete K4-6 feature (and a re-verification of K1–K4-5's own
areas) against `origin/main` at `10ac0a5`:

- Full automated suite: **221 tests / 1,434 assertions**, run twice,
  identical both times.
- Concurrency suite: **29 tests / 524 assertions**, run three times,
  identical every time — no deadlocks, no flakiness.
- The `current_stock = SUM(qty_on_hand)` invariant was verified both by
  inspecting the live database's full accumulated history (zero
  violations found) and by a complete live functional pass exercising
  Stock In (tracked batch/expiry capture), Stock Out FEFO (earliest-
  expiry-first, multi-batch consumption), POS cash and credit FEFO, Track
  Batches enablement (existing stock and zero stock), and the full
  batch-specific Stock Adjustment flow (increase, decrease, zero, no-op,
  expired batch, opening-balance batch, and rejection of a negative
  target, a non-integer target, and a batch id belonging to another
  product) — the invariant held exactly in every case.
- RBAC/CSRF, the audit trail (`entity_type='product_batch'` rows with
  correct before/after snapshots, none created for any rejected
  attempt), reporting/transaction-detail rendering, and currency/business
  behavior were all reverified and found unaffected.
- Browser QA passed at all four required viewports (360×800, 390×844,
  412×915, 1366×768) in both English and Khmer and both light and dark
  themes, with no horizontal overflow or clipping.
- All temporary QA data was created and fully cleaned up against an
  isolated copy of the working database; the repository working tree was
  confirmed unchanged (no production, test, or schema file was modified
  by this QA pass).
- **P0 = 0, P1 = 0.**

**Deferred, non-blocking (P3):**

- The Audit Log page (`audit/index.php`) does not yet have a friendly
  label or entity-name display for `entity_type='product_batch'` rows —
  it currently shows the raw string and a blank name column. The
  underlying audit data itself is complete and correct (before/after
  snapshots, actor, timestamp); this is a cosmetic display gap only,
  intentionally left for a separate follow-up rather than folded into
  K4-6.
- The Stock Adjustment page's "Apply Adjustment" button is not wrapped in
  a `canWrite()` UI guard, so a Viewer sees it — this predates the entire
  K-series (confirmed via `git log`/`git show` against history well
  before Phase K1). Purely a UI-visibility inconsistency: the server-side
  `canWrite()` gate in the POST handler is unaffected and continues to
  reject any resulting write.

**K4-6 Product Batch & Expiry MVP = COMPLETE.**
**K4-6-3 Final QA = PASS.**

Which feature area to take on next is a separate roadmap decision, to be
made at a future feature-selection/audit checkpoint — not decided or
started as part of this entry.

## Low Stock Alert / Reorder Management

**Status: COMPLETE.**

**Implementation:**

- Feature branch: `feature/low-stock-reorder`.
- Implementation commit: `7ce7a26` ("feat: add low stock reorder
  management").
- Merged via PR #77.
- Main merge commit: `9b8f13f`.

**Scope:**

- Added `products.reorder_quantity` (nullable, purely informational —
  never enforced, never affects classification).
- Added migration `015_add_reorder_quantity.sql`.
- Added a non-negative CHECK constraint on `min_stock` (closing a
  pre-existing gap) alongside the new `reorder_quantity` CHECK.
- Added a derived CRITICAL / LOW / NORMAL severity classification
  (`lowStockTier()` in `includes/stock_alert.php`), a strict refinement
  of the pre-existing `current_stock <= min_stock` signal: CRITICAL when
  `current_stock = 0`, LOW when `0 < current_stock <= min_stock`, NORMAL
  otherwise. `min_stock = 0` does not disable alerting.
- Added a dedicated, read-only Low Stock / Reorder page
  (`stock-alert/index.php`) listing CRITICAL and LOW products only,
  CRITICAL sorted before LOW.
- Added a Stock In quick-link/preselection from the new page.
- Updated the Dashboard Low Stock KPI to link to the new page (its
  underlying count query is unchanged).
- Preserved the existing Product list `?filter=low_stock` behavior.
- Updated the Product list and Stock Report severity badges to the new
  three-tier presentation.
- Added full English/Khmer localization for all new UI text.
- Added shared server-side true-integer validation
  (`includes/validation.php`), extracted from the existing Stock
  Adjustment validation helper and reused by the Product form.
- Preserved the tracked/untracked inventory invariant
  (`current_stock = SUM(product_batches.qty_on_hand)`) — the feature is
  purely read-derived and never writes to `product_batches` or any
  transaction table.
- No alerts table, no persisted/acknowledged alerts, no alert history.
- No email/SMS/push notifications.

**QA (L2 — Final QA):**

- **L2 Final QA = PASS.**
- Full automated suite: **269 tests / 2,412 assertions**, run twice,
  identical both times.
- Concurrency suite: **29 tests / 524 assertions**.
- Migration/schema verification: PASS (migration 015 replays cleanly;
  `schema.sql` matches; both new CHECK constraints and the
  `reorder_quantity` column confirmed present on the live database).
- Business-rule boundary verification: PASS (all 6 required
  CRITICAL/LOW/NORMAL boundary cases confirmed, including
  `min_stock = 0` and zero-stock-always-CRITICAL; `reorder_quantity`
  confirmed to have no effect on classification at NULL/0/positive
  values; tracked vs. untracked products at identical stock/threshold
  classified identically).
- RBAC/CSRF: PASS (Viewer can view the new page but not modify
  thresholds; invalid/negative/decimal/non-numeric threshold submissions
  rejected server-side with no mutation; missing CSRF token rejected
  with HTTP 403).
- English/Khmer localization: PASS (full key parity, no hardcoded
  strings, all new keys translated).
- Browser QA performed at 360×800, 390×844, 412×915, and 1366×768.
- Light/dark themes and English/Khmer both verified at each viewport,
  with no horizontal overflow.
- **P0 = 0, P1 = 0, P2 = 0, P3 = 0** — no findings.
- All temporary QA data was created against the working database and
  fully cleaned up afterward; baseline row counts confirmed restored.
- Working tree confirmed unchanged throughout (no production, test, or
  schema file modified by this QA pass).

**Deferred / out of scope (not part of this feature):**

- Email/SMS/push notifications.
- Alert history.
- Alert acknowledgement/dismissal.
- Purchase orders.
- Automatic purchasing.
- Forecasting.
- AI/ML-based reorder suggestions.
- PWA/native app delivery.

**Low Stock Alert / Reorder Management = COMPLETE.**
**L2 Final QA = PASS.**


The feature area taken on next was Purchase Orders — see the three
phases below.

---

## Purchase Order Management — Phase P1 (Schema / Draft-Only Core)

**Status: COMPLETE / MERGED.**

- Feature branch: `feature/p1-purchase-order-core`.
- Implementation commit: `f729838` ("feat: add purchase order schema and
  draft-only PO core").
- Merged via PR #80. Main merge commit: `2283319`.
- Migration `016_add_purchase_orders.sql` adds `purchase_orders` and
  `purchase_order_items`.
- `purchase_orders.status` is a plain `ENUM('draft','ordered',
  'partially_received','received','cancelled')` column (not a generated
  column, since later phases need to aggregate across
  `purchase_order_items` rows, which a generated column cannot do) —
  deliberately carrying the full future lifecycle even though this phase
  only ever writes `draft`, to avoid a later destructive `ALTER ...
  MODIFY ENUM` once receiving/cancellation shipped.
- `purchase_order_items.subtotal` is a generated column
  (`ordered_qty * unit_cost`), matching the existing
  "store the line, derive the aggregate" pattern already used elsewhere
  in the schema (no stored header total on `purchase_orders` either —
  always summed from items at read time).
- Own reference-counter key (`'purchase_orders'`, format `PUR-000123`),
  independent of the existing `'stock_transactions'`/`'customer_debts'`
  counters, using the same `nextReferenceSequence()` row-lock mechanism.
- Scope: create / view / edit / delete a **draft** Purchase Order only.
  No submit, receive, or cancel — `updatePurchaseOrder()`/
  `deletePurchaseOrder()` both re-validate `status = 'draft'` under a
  row lock at the moment of write, never trusting an earlier read.
- Full English/Khmer localization; RBAC (`canWrite()`) and CSRF on every
  mutating action; idempotency token support on create, reusing the
  existing `claimIdempotencyToken()` mechanism.
- Verified via the project's standard Integration/HTTP/Concurrency test
  layers (reference-generation and full-creation races run as genuinely
  separate OS processes) plus a full regression run, all green, before
  merge.

**Deferred to later phases (by design):** submit, receive, cancel,
amend-after-submit, any status other than `draft`.

---

## Purchase Order Management — Phase P2 (Receiving / Stock In Integration)

**Status: COMPLETE / MERGED.**

- Feature branch: `feature/p2-po-receiving`.
- Implementation commit: `759ddb4` ("Add Purchase Order receiving /
  Stock In integration").
- Merged via PR #81. Main merge commit: `976c371`.
- Migration `017_add_purchase_order_receipts.sql` adds
  `purchase_order_receipts` (links each `purchase_order_items` row to
  the `stock_transaction_items` row it produced; `UNIQUE` on
  `stock_transaction_item_id`, `CHECK (qty > 0)`, no uniqueness on
  `purchase_order_item_id` since one line can accumulate receipts across
  several partial deliveries).
- `submitPurchaseOrder()`: `draft → ordered`. No idempotency token
  needed — it never mutates inventory, so a duplicate submit attempt is
  naturally rejected (status already `ordered`) with zero side effect.
- `receivePurchaseOrder()`: full or partial receiving from `ordered`/
  `partially_received`, owning one transaction end to end — claims a
  Receive idempotency token first, locks the `purchase_orders` row
  (`SELECT ... FOR UPDATE`), guards `received_qty` with a single atomic
  `UPDATE ... WHERE received_qty + ? <= ordered_qty` (the database guard
  itself is the concurrency mechanism, not a PHP-side pre-check), calls
  the existing `insertStockInTransaction()` directly (never duplicates
  Stock In logic, never calls `recordStockIn()`), builds an explicit
  ordered `$receiptPlan` so the PO-item ↔ `stock_transaction_item`
  mapping is deterministic rather than inferred from row order, then
  recomputes `purchase_orders.status` from a fresh read of every line.
- Audit: one `purchase_order` row per Submit; exactly one per successful
  Receive, **including** a `partially_received → partially_received`
  event where the aggregate status doesn't change — a receiving event is
  auditable independent of whether the header status moved.
- Batch/expiry receiving reuses the existing Stock In batch architecture
  unchanged; receiving cost defaults to the PO's own quoted `unit_cost`
  but remains editable at receipt time.
- Post-merge verification (independent read-only pass against `main`)
  confirmed: full regression **359 tests / 2,904 assertions**;
  concurrency suite **34 tests / 613 assertions**; PO-domain lock
  ordering (`purchase_orders` → `purchase_order_items` →
  products/batches) verified with no reverse-lock/deadlock risk; RBAC,
  CSRF, and receipt-linkage mapping all confirmed correct against the
  live database, not merely by test assertions.

**Deferred to later phases (by design):** cancellation, low-stock → PO
integration, notifications, forecasting, COGS/weighted-average costing,
supplier portal, multi-warehouse, PWA, a dedicated receiving queue,
bulk multi-PO receiving.

---

## Purchase Order Management — Phase P3-A (Cancellation)

**Status: COMPLETE / MERGED.**

- Feature branch: `feature/p3a-po-cancellation`.
- Implementation commit: `d355e62` ("feat: add purchase order
  cancellation").
- Merged via PR #82. Main merge commit: `ad066ee`.
- No new migration — the `cancelled` status value already existed in
  `purchase_orders.status`'s ENUM since P1, specifically anticipating
  this phase.
- `cancelPurchaseOrder(PDO $pdo, int $poId, int $userId, ?string $reason
  = null): void` added to `includes/purchase_order.php`, the same shape
  as `submitPurchaseOrder()`: one short transaction, `SELECT ... FOR
  UPDATE` on the `purchase_orders` row (the identical lock
  `receivePurchaseOrder()` takes as its own first mutating step, so a
  concurrent Cancel/Receive or Cancel/Cancel pair always resolves to
  exactly one winner with zero partial mutation), allows only `ordered`
  and `partially_received` as source statuses, and touches **only** the
  `purchase_orders` row — `purchase_order_items`, `purchase_order_
  receipts`, `products`/`current_stock`, and `stock_transactions` are
  never written. A `draft` PO is still removed via the existing
  `deletePurchaseOrder()` (a draft has no receiving history worth
  preserving); Cancel deliberately does not accept `draft` as a source
  status, to avoid two competing removal paths for the same state.
- No idempotency token needed, by the same reasoning as Submit — Cancel
  never mutates inventory, so a duplicate/replayed request is naturally
  rejected (`PurchaseOrderNotCancellableException`) with zero side
  effect.
- An optional cancellation reason is accepted but is **audit-only** —
  it is never written to any column (no `cancel_reason` field was
  added) and never appended to the PO's own `note`; it appears only
  inside the audit row's `after` snapshot, on the same principle as
  Receive's own `received_this_event` audit data.
- New Cancel action/button added to `purchase-order/index.php` and
  `view.php`, gated by the existing `canWrite()`/CSRF conventions,
  visible only for `ordered`/`partially_received` status — the existing
  `cancelled` status badge/filter/localization, already present since
  P1, needed no changes.

**Final QA (independent verification pass, this session):**

- **Final QA = PASS.**
- Integration (`tests/Integration/PurchaseOrderCancellationTest.php`):
  **18 tests / 31 assertions** — covers every allowed/forbidden
  transition, exactly-one-audit-row-per-cancel, and explicit
  before/after snapshot comparisons proving stock, `received_qty`,
  `purchase_order_receipts`, and `purchase_order_items` are all left
  byte-for-byte unchanged by a partially-received cancellation.
- HTTP (`tests/Http/PurchaseOrderCancellationHttpTest.php`): **12 tests
  / 40 assertions**; the full `tests/Http/` directory (every consumer of
  the shared HTTP test base class): **128 tests / 629 assertions**.
- Concurrency: the two dedicated Cancel scenarios (Cancel vs. Receive,
  Cancel vs. Cancel, both against genuinely separate OS processes) —
  **2 tests / 23 assertions**, run 3 times with identical results; full
  concurrency suite: **36 tests / 636 assertions**.
- Full regression suite: **391 tests / 3,006 assertions**.
- `git diff --check`: clean.
- RBAC/CSRF: Viewer cannot cancel (direct POST with a valid CSRF token
  still rejected server-side, not merely a hidden button); missing/
  invalid CSRF rejected with HTTP 403 and zero mutation; a GET with
  `action=cancel` in the query string never mutates; a tampered/
  nonexistent PO id is rejected safely.
- **Known limitation (environment, not an application defect):** the
  Windows/XAMPP PHP built-in-server HTTP test suite intermittently times
  out under PHPUnit on that specific platform. This was investigated
  extensively (stdout/stderr pipe → file → `NUL`-device redirection;
  cumulative request-count, test-identity, and page-render-repetition
  isolation experiments) without ever reproducing on Linux and without
  any experiment result implicating P3-A's own application logic — the
  evidence is most consistent with a probabilistic, platform-specific
  factor outside this codebase's control (most plausibly antivirus/
  real-time-scanning interference or a Windows-specific PHP built-in-
  server socket-teardown timing characteristic). No test assertion was
  weakened and no sleep/retry/timeout workaround was introduced to mask
  it. Documented here as a known test-infrastructure limitation, tracked
  separately from P3-A's own correctness.

**Deferred / out of scope (not part of this feature):** Low Stock → PO
integration, automatic PO creation/submission, a Dashboard PO KPI,
notifications, forecasting, supplier portal, multi-warehouse, PWA,
PO amendment/edit-after-submit.

**Purchase Order Management (P1 → P3-A) = COMPLETE.**

The next planned phase is **P3-B — Low Stock → Assisted Draft Purchase
Order** (read-only planning only as of this entry; not yet implemented,
not yet branched).

---

## Security Hardening — Phases K1 through K2-E

**Status: K1, K2-A, K2-B, K2-C, K2-D COMPLETE / MERGED. K2-E COMPLETE (pending review).**

Addresses the P0/P1/P2 gaps recorded in `docs/adr/ADR-008-security-production-deployment.md`, one small reviewed batch at a time, each on its own feature branch with an explicit scope boundary and a full regression run before merge. See that ADR's own "Outcome" section for the fuller mapping.

- **K1 — Web boundary hardening.** `docker/nginx/default.conf` and
  `.htaccess` deny `database/`, `tests/`, `docs/`, `docker/`, `vendor/`,
  dotfiles/dot-directories, and repository metadata file types
  (`*.sql`, `*.md`, `*.yml`, `composer.json`), placed ahead of the PHP
  handler. `audit/` deliberately left reachable — it is a real
  Admin-only application route, not repository metadata. No
  `public/`-only restructure — `config/base_url.php`'s `BASE_URL`
  derivation depends on the repository root being the document root.
- **K2-A — Session lifecycle hardening.** `session_regenerate_id(true)`
  on successful login (closes session fixation); full logout
  invalidation (session data, cookie, server-side record); session
  rotation on a self-service password change.
- **K2-B — Session cookie hardening.** `config/session.php` centralizes
  `HttpOnly`, `SameSite=Lax`, and a `Secure` flag derived from the
  actual request scheme (`$_SERVER['HTTPS']` /
  `X-Forwarded-Proto`) rather than hard-coded — both shipped
  deployments are plain HTTP today, so an unconditional `Secure` would
  have broken login outright. Also enables
  `session.use_strict_mode`.
- **K2-C — Login abuse protection.** New `login_attempts` table +
  `includes/login_throttle.php`: 5 failed attempts per submitted email
  within a rolling 10-minute window, checked (and refused) **before**
  any password verification, self-expiring, no permanent lockout. Also
  closed an unintended username-enumeration timing oracle — an unknown
  email previously skipped `password_verify()` entirely and answered
  ~210x faster than a real account; it now performs one real verify
  against a fixed dummy hash.
- **K2-D — Privilege freshness & session invalidation.** Previously
  `$_SESSION['role_id']` was set once at login and never refreshed, so
  demoting an Admin (or resetting their password) had no effect on an
  already-authenticated session. `includes/auth_check.php` now
  re-reads `role_id`, `must_change_password`, and the new
  `users.password_changed_at` (migration `019`) once per authenticated
  request; a mismatch or a missing user row tears the session down the
  same way logout does. A password change now invalidates every other
  session for that account while preserving the existing
  stay-signed-in behavior for the session that made the change.
- **K2-E — Residual security & production hygiene.** `config/db.php` no
  longer renders the raw PDO exception (host/user/database/SQLSTATE) to
  an unauthenticated visitor on a connection failure — it logs the real
  error and shows one generic sentence. The Docker image
  (`docker/php/php.ini`) now ships `display_errors=Off`,
  `log_errors=On` (to container stderr), and `expose_php=Off` — the
  bare `php:8.4-fpm` base image otherwise runs on PHP's compiled
  defaults (`display_errors=1`, `log_errors=0`), which is the opposite
  of what a production deployment needs. `docker/nginx/default.conf`
  and `.htaccess` both gained `X-Frame-Options: SAMEORIGIN`,
  `X-Content-Type-Options: nosniff`, and `Referrer-Policy: same-origin`.
  `Content-Security-Policy` and `HSTS` were deliberately NOT added —
  see ADR-008's Outcome section for why.

**Deliberately deferred, not overlooked (candidates for a later phase,
not V1):** Content-Security-Policy (needs nonces/hashes for the
CDN scripts and inline theme/language toggle), HSTS (both deployments
are plain HTTP today), login CSRF and converting logout from GET to
POST, idle/absolute session timeout, `password_needs_rehash()`.

Current security-focused automated coverage: `AuthorizationTest`,
`BackupAuditTest`, `CsrfTest`, `LoginThrottleTest`,
`PrivilegeFreshnessTest`, `SessionCookieAttributesTest`,
`SessionLifecycleTest` — all part of the standard `tests/Http/` suite
run before every merge in this series.
