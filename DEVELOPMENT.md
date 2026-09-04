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
