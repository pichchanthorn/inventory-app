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

**Phase E — stabilization:** A follow-up audit of the shipped POS module
found three gaps: `sale` transactions were invisible to the dashboard
movement chart and stock-report KPIs (both only queried `in`/`out`/
`adjustment`); there was no server-side floor on `unit_price` in POS or
Stock Out; and a browser refresh right after checkout could resubmit the
sale. Fixed by widening the reporting queries to include `sale`, rejecting
negative unit prices server-side (zero remains valid), and switching POS's
success path to Post/Redirect/Get with the receipt held in a one-time
session flash, so a refresh re-fetches the GET instead of resubmitting the
POST.

---

## Known limitations / possible next steps

- [ ] Decide whether to restrict or remove public self-registration now
      that Admin-created accounts exist.
- [ ] No pagination on list pages yet (fine at current data volume; would
      matter at scale).
- [x] ~~`Viewer` role exists in the schema but has no read-only enforcement
      yet — currently behaves the same as `User`.~~ **Resolved in Phase 6**
      — enforced via `canWrite()`/`isViewer()` across all 8 write-capable
      modules.
- [ ] No automated tests — all verification so far has been manual /
      scripted `curl` and direct-PHP checks during development sessions,
      not a committed test suite.
