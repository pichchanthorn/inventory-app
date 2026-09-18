# V2-0 Discovery

**Baseline:** `main` @ `c45b9a0` (post-Documentation-Sync, immediately after V1 closure)
**Author role for this pass:** Product Manager + UX Architect + Software Architect (discovery only)
**Type:** Discovery / analysis. No application behavior changed. No V2 implementation started.

**How to read this document:** every factual claim is either a **Verified** statement (backed by a cited file/line or a command run against the repository) or explicitly marked **Assumption**/**Hypothesis**. Where two existing documents in the repository disagree, both are quoted and the conflict is left unresolved for the owner, per the task's evidence rules.

---

## 1. Executive Summary

PCTN Inventory V1 is a genuinely complete, well-engineered single-shop system: plain PHP 8.1+/PDO/MySQL, server-rendered Bootstrap 5 UI, no framework, no separate frontend. It is not a prototype — it has real concurrency safety (guarded `UPDATE`s, `SELECT … FOR UPDATE` locks), idempotency protection on every money-moving action, a structurally-enforced audit-snapshot allowlist, CSRF on every mutating form but one, and a five-batch session/auth security hardening program (K1–K2-E) that was independently re-audited before V1 closed. The test suite (494 tests / 3,458 assertions, verified by a fresh run today — see §12) backs nearly every business-critical path.

The product itself, however, was built feature-by-feature over many phases without a single UX pass, and it shows in specific, evidenced ways rather than as a vague "needs polish": the Admin Audit Log has no filter, search, or pagination and is capped at 200 rows (`audit/index.php:14-18`, the developer's own comment calls this "explicitly future-phase"); money movements (debt creation, debt payment) are **not** written to the audit trail at all (`includes/debt.php` contains zero `logAudit()` calls — verified); every list page (Products, POS's product/customer pickers) ships its **entire** table to the browser as inline JSON with no pagination or server-side search (`pos/index.php` embeds `PRODUCTS = <?= json_encode($products) ?>` for the full product table); and the sidebar carries 14 top-level links across 5 sections with no collapse, search, or "favorites" — all present today, none paginated or filtered.

This report does not recommend a rewrite, a new frontend framework, or multi-tenancy. It maps what exists, names the friction with evidence, and hands V2-1 a bounded list of architecture questions — deliberately not a stack decision.

---

## 2. V1 Current-State Summary

**Verified, from direct repository inspection.**

### 2.1 Technology
- PHP ≥ 8.1 (`composer.json:6`), MySQL/MariaDB, PDO with `ATTR_EMULATE_PREPARES => false` (`config/db.php`).
- Server-rendered HTML per request; no client-side router, no build step, no bundler.
- Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 + Chart.js 4.4.4, all loaded from a CDN (`includes/header.php:32-35`) — no npm/webpack anywhere in the repo.
- Vanilla JavaScript only, split between a shared block in `includes/footer.php` (toasts, live-search debounce, theme toggle, submit-button lock, bfcache fixes) and per-page `<script>` blocks (POS's cart/scanner logic, Dashboard's Chart.js wiring).
- `html5-qrcode@2.3.8` for barcode scanning, loaded only on the three pages that use it (Stock In, Stock Out, POS).
- Two deployment targets, both documented and both HTTP-only today: XAMPP/Apache (`.htaccess`) and Docker Compose (Nginx + PHP-FPM + MySQL, `docker-compose.yml`, `docker/`).

### 2.2 Modules (verified directory listing)
`auth/` (login, logout, register), `category/`, `unit/`, `supplier/`, `product/`, `purchase-order/` (create, edit, index, receive, view), `stock-in/`, `stock-out/`, `stock-adjustment/`, `stock-alert/`, `stock-report/`, `stock-transaction/` (shared detail view), `pos/` (index, receipt), `customer/` (index, view), `user/`, `settings/`, `audit/`, plus root `dashboard.php` and `profile.php`. Shared logic lives in `includes/` (19 files: `auth_check.php`, `csrf.php`, `session_lifecycle.php`, `login_throttle.php`, `stock.php`, `debt.php`, `purchase_order.php`, `purchase_order_prefill.php`, `audit.php`, `backup.php`, `currency.php`, `validation.php`, `sortable.php`, `stock_alert.php`, `receipt_view.php`, `transaction_detail.php`, `header.php`/`footer.php`/`lang.php`).

### 2.3 Data model (verified `database/schema.sql`)
19 tables: `users`, `roles`, `categories`, `units`, `suppliers`, `products`, `product_batches`, `stock_transactions`, `stock_transaction_items`, `stock_transaction_item_batches`, `customers`, `customer_debts`, `customer_debt_payments`, `purchase_orders`, `purchase_order_items`, `purchase_order_receipts`, `audit_log`, `login_attempts`, `idempotency_keys`, `reference_counters`, `app_settings`. Single database, no tenant/business-scoping column anywhere (confirmed by schema read — no `shop_id`/`tenant_id`/`business_id` column exists on any table).

### 2.4 Security & reliability posture (verified via this session's own prior K-QA re-audit and a fresh test run today)
- RBAC: `isAdmin()`/`isViewer()`/`canWrite()` in `includes/auth_check.php`, re-read from the database once per authenticated request since K2-D (not cached in a stale session value).
- CSRF: synchronizer token pattern (`includes/csrf.php`), verified on 20 POST handlers — **every** mutating handler except `auth/login.php` (see §5.4).
- Session hardening (K2-A/B/C/D): rotation on login, full teardown on logout, `HttpOnly`/`SameSite=Lax`/scheme-derived `Secure`, 5-in-10-minute login throttle with a closed timing oracle, and per-request privilege freshness.
- Residual hygiene (K2-E): generic DB-failure messages, `X-Frame-Options`/`X-Content-Type-Options`/`Referrer-Policy` headers, Docker `display_errors=Off`.
- **Verified today:** `APP_ENV=test vendor/bin/phpunit` → `OK (494 tests, 3458 assertions)`.

### 2.5 Documentation state — an unresolved conflict worth surfacing, not silently fixed
`README.md`'s own "Future Roadmap" section (`README.md:565-614`) already lists Business Analytics, Remote Owner Management, and Productization as forward-looking categories — this document should be read as building directly on that section, not duplicating it. However, that same section's "Near-term / known gaps" (`README.md:571-578`) states *"Stock Reports does not yet have the mobile card-layout treatment that Products/…/Customers already have."* **This is now false**: a repository-wide check today (`grep -rl "table-cards-mobile"`) shows `stock-report/index.php` already carries that class. This is a second, independent instance of the documentation-drift pattern already found and partially corrected in the V1 Documentation Sync (`DEVELOPMENT.md`, PR #91) — that sync touched `DEVELOPMENT.md` only and did not reach `README.md`'s Future Roadmap section. **Flagging, not editing** — this report does not modify `README.md`, per the task's file-change restriction.

---

## 3. User Roles & Personas

**Verified role mechanics; persona framing below is Product-Manager inference from the workflows the code supports, explicitly labeled.**

| Role | Verified capability | Where enforced |
|---|---|---|
| **Admin** (`role_id=1`) | Everything User can do, plus Users, Settings, Audit Log, database backup, delete on Category/Unit/Supplier/Product, PO cancel | `isAdmin()`, 17 call sites |
| **User** (`role_id=2`) | Full day-to-day operations: POS, Stock In/Out/Adjustment, Purchase Orders (create/edit/submit/receive), Customers & Debts, own profile | `canWrite()`, 46 call sites — default role for self-registration when enabled |
| **Viewer** (`role_id=3`) | Read-only everywhere `canWrite()` gates a write; confirmed server-side (a Viewer's direct POST to a write action is rejected even with a valid CSRF token — this was independently verified live during the K-QA re-audit, not merely inferred from the gate's presence) | `isViewer()` inside `canWrite()` |

**Personas (Hypothesis, grounded in the workflows the code actually supports — not verified against real users):**
- **The Owner (Admin).** Runs the shop, is the only role that can create staff accounts, change prices/settings, see the Audit Log, and pull a backup. The single-owner assumption is structural: `user/index.php` blocks self-role-change (`user_err_self_role`), so there must always be at least one other Admin to change any Admin's role — plausible for a true single-owner shop, a real constraint the moment a second Admin/manager is introduced.
- **The Cashier/Staff (User).** Lives in POS and Stock In/Out day-to-day. The mobile bottom-navigation (`includes/footer.php:20-46`) and the sticky action button on POS/Stock In/Out (`main.has-sticky-action`) are direct evidence the product was built assuming this role works from a phone at a counter, not a desk.
- **A read-only party (Viewer).** The registration form (`auth/register.php`) defaults self-registered accounts to Viewer — evidence the Viewer role was designed for a lower-trust, easy-to-grant account (a family member checking stock, a bookkeeper, or a trial account), not a technical necessity.

---

## 4. Core Business Workflows

Each workflow is mapped as requested: Actor · Trigger · Main steps · Key decisions · Failure/rejection points · Business data · UX friction · V2 improvement area. Friction/improvement items are labeled **[Evidence]** when backed by code inspection or **[Hypothesis]** when inferred without direct UI-testing evidence.

### 4.1 Login / account access
- **Actor:** any account holder. **Trigger:** unauthenticated visit to any page → redirect to `auth/login.php`.
- **Main steps:** email + password → server-side throttle check (before any password hashing) → `password_verify()` → session rotated → redirect to `dashboard.php`.
- **Decisions:** must-change-password flag forces a redirect to `profile.php` before anything else is usable.
- **Failure points:** wrong credentials (generic message, same for unknown vs. known email — the K2-C timing-oracle fix is deliberately why the message and timing are identical); 6th failure in 10 minutes is silently refused before the password is even checked.
- **Business data:** none beyond identity.
- **UX friction [Evidence]:** the login form carries no CSRF token (`auth/login.php` — confirmed zero `csrf` references), the one exception to CSRF coverage in the whole app; this was a deliberate, documented K2-A/K2-C deferral, not an oversight, but it is a real, named gap a V2 pass should close alongside any auth UI change.
- **V2 improvement area:** password-reset self-service does not exist at all (verified: no "forgot password" route anywhere; the only reset path is Admin-initiated in `user/index.php`) — for a shop that plans to add staff, an Admin-mediated reset for every forgotten password is a real support burden.

### 4.2 Product management
- **Actor:** User/Admin. **Trigger:** Products page, or "create on the fly" from Stock In.
- **Main steps:** create/edit product (name, SKU/barcode, category, unit, supplier, cost/sale price, min stock, reorder quantity, optional batch/expiry tracking toggle) → list with live search.
- **Decisions:** `track_batches` toggle changes the product's entire stock-mutation code path (FEFO batch consumption vs. simple guarded decrement) — a one-way-feeling switch in the UI for a structurally significant decision.
- **Failure points:** negative price rejected server-side (`PriceConversionException`); delete blocked if the product has transaction history (`1451` FK-constraint check, confirmed pattern used consistently across Category/Unit/Supplier/Product/Customer).
- **Business data:** the master catalog every other workflow depends on.
- **UX friction [Evidence]:** the entire product table is sent to the browser as JSON for POS's client-side search (`pos/index.php`: `const PRODUCTS = <?= json_encode($products) ?>`) — the list page itself uses a debounced server round-trip (`initLiveSearch()`), but POS does not; at a few hundred SKUs this is fine, at a few thousand it is a real payload-size and search-latency problem.
- **V2 improvement area:** no barcode generation/printing (only barcode *scanning*, and only on 3 pages per README's own roadmap) — a shop with unlabeled stock has no way to produce a scannable label from inside the app.

### 4.3 Stock receiving
Two distinct paths exist — this is a real product-shape fact, not a redundancy to remove without checking with the owner first.
- **Path A — Direct Stock In** (`stock-in/index.php`): no PO required, multi-line, supplier optional, immediate stock increase.
- **Path B — Purchase Order receiving** (`purchase-order/receive.php`): only for a PO in `ordered`/`partially_received` status, partial receiving supported, each receipt line links back to its PO line (`purchase_order_receipts`) so "how much of this order has arrived" stays reconstructible across multiple deliveries.
- **Decision:** which path to use is entirely left to the user's judgment — there is no in-app guidance steering a receiving event toward "did this come from a PO?"
- **Failure points:** guarded `UPDATE`s under row locks prevent two simultaneous receipts from double-counting; over-receiving beyond a PO line's ordered quantity is rejected.
- **UX friction [Hypothesis]:** two entry points for what is operationally "stock arrived" could confuse a new staff member about which one to use for a given delivery — flagged as a hypothesis because no in-app copy or empty-state text was found steering the choice either way.
- **V2 improvement area:** no supplier-returns flow exists (verified: no `type` value for "return" in the `stock_transactions.type` ENUM — only `in`/`out`/`adjustment`/`sale`).

### 4.4 Stock adjustment
- **Actor:** User/Admin. **Trigger:** a physical count disagrees with the system.
- **Main steps:** pick a product (or a specific batch, if `track_batches`), enter the new counted quantity, enter a reason, save.
- **Decisions:** batch-specific vs. whole-product adjustment, gated by `track_batches`.
- **Failure points:** optimistic-lock check against the expected pre-adjustment quantity (rejects if someone else changed stock in between reading and saving).
- **UX friction [Evidence]:** `stock-adjustment/index.php` has no `<table>` at all (verified) — there is no on-page history of past adjustments for that product; a user must go to Stock Reports to see it, a real context-switch for a workflow that is inherently "what did I just change."
- **V2 improvement area:** a required, longer reason field (or a small fixed reason taxonomy — damage/expiry/count-error/theft) would materially improve the audit trail's usefulness for a shop that wants to explain shrinkage.

### 4.5 Stock out / non-sale movement
- **Actor:** User/Admin. **Trigger:** stock leaves for a reason other than a sale (spoilage, internal use, sample).
- **Main steps:** near-identical UI to Stock In, multi-line, guarded decrement (`current_stock >= qty` in the same `UPDATE`).
- **Failure points:** insufficient stock rejected server-side with `rowCount() === 0` detection.
- **UX friction [Evidence]:** the reason for a non-sale Stock Out is a free-text `note` field with no required categorization — the same shrinkage-explainability gap as §4.4.

### 4.6 POS cash sale
- **Actor:** cashier (User role in practice). **Trigger:** a walk-in customer buys something.
- **Main steps:** search/scan products into the cart → enter cash received → change computed client-side, re-verified server-side → guarded stock decrement + `stock_transactions` row in one transaction.
- **Decisions:** cash vs. credit is a radio toggle that swaps the visible form fields via plain JS (`updatePaymentMethodUI()`), not a separate page.
- **Failure points:** cash received less than total is rejected server-side even if the client-side display somehow said otherwise; an idempotency token prevents a double-submit from recording the sale twice.
- **Business data:** the highest-frequency transaction in the whole system.
- **UX friction [Evidence]:** every product in the catalog is loaded into page JS for the search box (§4.2). A live running subtotal (`#posSubtotal`) and change-due (`updateChange()`) do update client-side as lines are edited — verified in `pos/index.php`'s `updateSubtotal()` — with an explicit code comment confirming this is "purely a live display convenience for the cashier" and the server independently recomputes and never trusts it. This is a correctly-designed pattern, not a gap.
- **V2 improvement area:** no "hold sale" / park-and-resume capability (verified: no session/DB structure for a suspended in-progress sale) — a common POS need when a customer needs to step away mid-purchase.

### 4.7 POS credit sale
- **Actor:** cashier. **Trigger:** a regular customer buys on credit (the README confirms this is a real, expected pattern for this shop type — farmers paying after harvest).
- **Main steps:** same cart as cash, but choose "credit," then either pick an existing customer (searchable combobox) or create one inline (name + phone, no address/ID required), set an optional due date.
- **Decisions:** existing vs. new customer is a radio toggle, matching the cash/credit pattern; due date is optional and explicitly designed to stay `NULL`-friendly (`customer_debts.due_date` comment: *"farmers often can't commit to an exact date"*) — a genuine, evidenced business-domain accommodation, not an oversight.
- **Failure points:** a missing customer name/selection is rejected before the sale is recorded.
- **Business data:** creates both a `stock_transactions` row and a linked `customer_debts` row atomically.
- **UX friction [Hypothesis]:** creating a new customer inline captures only name and phone — no way to record an address or ID for a larger credit line, which may or may not matter depending on how large individual debts get (flagged as a business question in §13, not asserted as a defect).

### 4.8 Customer debt (viewing/managing)
- **Actor:** User/Admin/Viewer (read). **Trigger:** a shop staff member checks who owes what.
- **Main steps:** `customer/index.php` lists customers with outstanding balance and overdue flag; `customer/view.php` shows one customer's full debt history and payment ledger.
- **Failure points:** none at the read layer.
- **Business data:** `balance`/`status` are **database-generated columns** (`customer_debts.balance GENERATED ALWAYS AS (total_amount - paid_amount) STORED`) — verified, and a genuinely strong design choice that eliminates an entire class of "cached total drifted from reality" bugs.
- **UX friction [Evidence]:** no debt-aging view (30/60/90 days) exists anywhere — the dashboard's only debt signal is a single "overdue count" number (`dashboard.php:17-23`); README's own Future Roadmap already names "Debt analytics (aging, collection rate)" as a future idea, so this is a confirmed, already-acknowledged gap, not a new discovery.

### 4.9 Debt payment
- **Actor:** User/Admin. **Trigger:** a customer pays some or all of an open debt.
- **Main steps:** enter amount, date, optional note, against a specific `customer_debts` row.
- **Failure points:** overpayment beyond the current balance rejected server-side (`customer_err_overpayment`); idempotency token prevents double-recording a payment on retry.
- **UX friction [Evidence — significant]:** `includes/debt.php` contains **zero** calls to `logAudit()` (verified by grep, cross-checked against `includes/stock.php` which has 3 and `includes/purchase_order.php` which has 7). Every debt creation and every debt payment is invisible to the Admin Audit Log page. The money itself is still traceable — `customer_debt_payments.created_by` and `customer_debts.created_by`/`updated_by` are real, populated FK columns — but there is no unified, filterable "who touched money and when" view an owner could open. This is the single highest-impact accountability gap found in this discovery.

### 4.10 Invoice / receipt lookup
- **Actor:** anyone with access to a reference number (own-role-scoped). **Trigger:** need to reprint or review a past sale.
- **Main steps:** `pos/receipt.php?ref=…` renders the same shared `includes/receipt_view.php` template used at point-of-sale, so the printed/viewed receipt can never drift from what a live sale shows.
- **Business data:** reads real business identity from `app_settings` (name/address/phone/email) — a genuine, if partial, white-label foundation (see §8).
- **UX friction [Evidence]:** print is `window.print()` on the current document (verified — no PDF generation, no email-the-receipt option); fine for a physical shop counter, a real gap the moment remote/delivery sales matter.

### 4.11 Low-stock monitoring
- **Actor:** User/Admin. **Trigger:** proactive check, or the Dashboard's Low Stock card.
- **Main steps:** `stock-alert/index.php` groups low/critical products by supplier (a P3-B feature, per `DEVELOPMENT.md`), each group offers a "Create Draft PO" action that prefills a new Purchase Order from that supplier's flagged products.
- **Decisions:** critical vs. low tier is threshold-driven per product (`min_stock`), not a single global number.
- **UX friction [Evidence]:** this is one of the stronger workflows in the product — the Low-Stock → Draft-PO handoff (`purchase_order_prefill.php`) is a genuinely well-designed piece of connective tissue between two modules that could easily have stayed disconnected. Noted as a strength, not a problem.
- **V2 improvement area:** no reorder-point automation beyond the manual "create draft" click (no scheduled email/SMS alert — README's own Future Roadmap already names this as "Alerts for low stock" under Remote Owner Management).

### 4.12 Purchase order flow
- **Actor:** User (create/edit/submit/receive) / Admin (cancel). **Trigger:** planned restock, or the Low-Stock handoff above.
- **Main steps:** `draft → ordered → partially_received/received`, or `cancelled` from `ordered`/`partially_received`.
- **Decisions:** a draft can be freely edited/deleted; once `ordered`, only receiving or cancellation are possible — no "amend a submitted PO" path exists (verified: `updatePurchaseOrder()` re-validates `status = 'draft'` under a row lock).
- **Failure points:** cancelling a PO with real receipts already recorded is still permitted (verified in K1-era concurrency testing) — this preserves the already-received stock and its audit trail rather than trying to reverse it, a deliberate and correct design choice, but worth the owner knowing explicitly: **cancel does not mean "undo."**
- **Business data:** `purchase_order_items.subtotal` is a generated column (`ordered_qty * unit_cost`), same anti-drift pattern as customer debts.
- **UX friction [Hypothesis]:** no amend-after-submit path means a mid-order correction (wrong quantity noticed after calling the supplier) requires cancel-and-recreate, discarding the paper trail of the original intent — a real operational question for the owner in §13, not asserted as wrong without knowing how often this actually happens in practice.

### 4.13 Reporting
- **Actor:** any role (read). **Trigger:** periodic review.
- **Main steps:** `stock-report/index.php` — movement totals by type (in/out/adjustment/sale), a transaction log.
- **UX friction [Evidence]:** no date-range picker was found in the reviewed markup (the Dashboard's own movement chart is hard-coded to "last 7 days," `dashboard.php:33`); no export to CSV/Excel/PDF anywhere in the app (the only "export" capability at all is the full-database `.sql` backup, which is not a reporting tool). README's own roadmap already names "Sales analytics," "Gross profit/margin analytics," and "Fast/slow-moving identification" as future ideas — this discovery confirms none of them exist today.

### 4.14 User administration
- **Actor:** Admin only. **Trigger:** hire/departure, forgotten password, role change.
- **Main steps:** create (name/email/temp password/role), reset password (shows the new temp password once, in-session, never stored), change role, self-role-change blocked.
- **Failure points:** demoting/promoting takes effect on the demoted/promoted user's very next request (K2-D privilege freshness) — verified live during the K-QA re-audit, a real strength most small PHP apps of this shape don't have.
- **UX friction [Evidence]:** no account deactivation exists — only role change. A departing staff member's account can be demoted to Viewer but not disabled/archived; their login history/name persists indefinitely with no "inactive" state (verified: no `active`/`status` column on `users` — confirmed during the K2-D inspection this session performed).

### 4.15 Audit trail
- **Actor:** Admin only (read). **Trigger:** investigating a change.
- **Main steps:** a flat, newest-first list of the last 200 rows, each expandable via `<details>` to a before/after JSON diff.
- **UX friction [Evidence — significant, already self-acknowledged in code]:** `audit/index.php:14-18`'s own comment: *"LIMIT 200: the minimum growth-safety measure for Phase 1 - no filtering/search/pagination yet (explicitly future-phase)."* No filter by actor, entity type, date range, or action. Combined with §4.9's finding (money movements entirely absent from this table), the Audit Log today answers "what changed on master data recently" and nothing about "who moved money."

### 4.16 Settings / backup
- **Actor:** Admin only. **Trigger:** exchange-rate update, business-identity edit, periodic backup.
- **Main steps:** three independent forms on one page — USD→KHR rate, business identity (name/phone/address/email), and a one-click full-database `.sql` download.
- **Failure points:** backup failure now shows a safe generic message and still logs the real error (Phase J5, verified in `includes/backup.php`); a successful backup is now audited (`action=create, entity=backup`) — both fixed same-day in commit `54a2fe8`, confirmed during the Documentation Sync pass.
- **UX friction [Evidence]:** backup is manual and on-demand only — README's own "Production Readiness" section already states this explicitly (*"Automated, scheduled backups with restore testing"* is listed under "Would need to be added for production"). Settings has no other business configuration: no tax rate, no receipt footer text, no default low-stock threshold, no logo upload (verified: `uploads/` contains only `avatars/`, no `logo/` or equivalent directory) — the invoice/receipt shows business text fields but never an uploaded shop logo.

---

## 5. Current UX/UI Findings

Discovery only — no files edited, per instruction. Findings are evidence-based unless marked Hypothesis.

### 5.1 Navigation [Evidence]
The sidebar (`includes/header.php:67-98`) carries **14 top-level links** across 5 sections (Overview, Catalog, Operation, Reports, Administration, Account) with no search, no collapse, and no "recently used" affordance. This is not inherently wrong for the current module count, but it will not scale gracefully if V2 adds modules (multi-location, expanded reporting, etc.) without a navigation redesign.

### 5.2 Information hierarchy [Evidence]
The Dashboard (`dashboard.php`) shows exactly 5 KPI cards (Products, Units in stock, Inventory value, Low Stock, Outstanding Debts) plus 2 charts (7-day movement, category distribution) — no role-based customization (an Admin and a Viewer see an identical dashboard), no date-range control, no "recent activity" feed, no Purchase-Order KPI (already flagged as deferred in `DEVELOPMENT.md`'s Low Stock Alert section).

### 5.3 POS [Evidence]
Genuinely strong mobile engineering: sticky submit button that floats above the mobile bottom-nav (`main.has-sticky-action`), a barcode-scan modal, a debounced product/customer search-select component reused consistently. The one confirmed scaling risk is the full-catalog JSON payload (§4.2, §4.6).

### 5.4 Forms [Evidence]
Consistent conventions across the app: every mutating form carries a CSRF token (`csrf_field()`, 39 call sites) except the login form (§4.1); every submit button self-disables with a spinner on submission (`includes/footer.php:132-146`) to prevent double-submit; server-side re-validation always exists behind client-side checks (confirmed across POS, Stock In/Out, PO, Debt Payment).

### 5.5 Tables [Evidence]
17 of 19 pages with a `<table>` use the shared `table-cards-mobile` responsive treatment (§2.5) — the two that don't (`includes/receipt_view.php`, `includes/transaction_detail.php`) are compact embedded line-item views, not master lists, so this is a reasonable, not-a-gap distinction. No table anywhere supports column sorting beyond the existing `sortOrderBy()` allowlist mechanism (`includes/sortable.php`) used on a handful of list pages (Category/Supplier/Unit/Product/PO/Stock Report) — Customers, Audit Log, and POS's own recent-sales list have no sort control at all.

### 5.6 Search/select controls [Evidence]
A single, well-built pattern (`.product-search-*` classes, `renderSearchMenu()` in `includes/footer.php`) is reused for product and customer pickers across POS and elsewhere — genuinely good component reuse. It is entirely client-side against the full dataset (§4.2), which is the scaling boundary of an otherwise good pattern, not a design flaw at current scale.

### 5.7 Modals [Evidence]
Used sparingly and appropriately — the barcode-scan modal (`pos/index.php`) is the only modal-dialog pattern found in the reviewed files; most flows use full-page forms or `<details>` (Audit Log's before/after view) rather than modals, which is a reasonable, low-complexity choice for a server-rendered app.

### 5.8 Alerts/errors [Evidence]
Standardized on a shared top-right toast (`showToast()`, `includes/footer.php:59-78`) — the code comment confirms this replaced an older top-of-page `<div class="alert">` pattern app-wide, a real, completed UX improvement already in V1.

### 5.9 Empty states [Evidence]
Present and reasonably designed on at least Dashboard (`dashboard_movement_empty`, with a call-to-action link to Stock In), POS's recent-sales list, Category/Product lists (`product_empty`), and Audit Log (`audit_empty`) — each uses a muted icon + short message pattern consistently.

### 5.10 Validation messages [Evidence]
Server-side validation exists per-controller (inline in each `index.php`), not centralized — `includes/validation.php` contains exactly **one** shared function (`isNonNegativeIntegerString`). This is a code-organization observation relevant to §9's architecture questions, not necessarily a user-facing UX problem today (messages themselves are consistently translated and specific, e.g. `pos_err_insufficient_cash`, `customer_err_overpayment`).

### 5.11 Mobile responsiveness [Evidence]
A real, sustained engineering investment: offcanvas sidebar below 768px, a dedicated bottom-navigation bar, sticky action buttons, bfcache-safe JS (explicit `pageshow` handlers for both the disabled-submit-button and offcanvas-drawer states — `includes/footer.php:148-175`). This is one of the product's clearest strengths and should be explicitly preserved, not "modernized away," in any V2 frontend discussion.

### 5.12 Khmer/English localization [Evidence]
Two complete, actively-maintained translation files (`lang/en.php`, `lang/km.php`, 554/553 lines) plus a bilingual README (`README.md`/`README.km.md`). Session-based instant toggle, no page-specific missing-translation issues found in the files sampled. A real, mature strength — not a gap.

### 5.13 Light/dark themes [Evidence]
A working theme toggle (`toggleTheme()`) persisted via `localStorage`, with a pre-paint inline script (`includes/header.php:22-26`) to avoid a flash-of-wrong-theme on load — a level of polish above what a "plain PHP app" is usually assumed to have.

### 5.14 Print/receipt experience [Evidence]
Covered in §4.10. Functional for the counter use case; no digital-delivery option.

### 5.15 Consistency of components [Evidence]
High, for a codebase built incrementally across many phases — the shared `includes/*.php` partials (header/footer/receipt_view/transaction_detail) and consistent class naming (`badge-stock`, `dash-stat-card`, `product-search-*`) indicate deliberate reuse discipline, not copy-paste drift. The main inconsistency found is **coverage**, not style: not every list has sort/filter/pagination, not every money-moving action is audited.

### 5.16 Accessibility/usability basics [Evidence, partial]
Positive signals found: `aria-label`s on icon-only buttons (hamburger, offcanvas close, stat-card links), `aria-live="polite"` on the toast container, `role="img"`+`aria-label` on chart canvases. **Not verified** in this pass: color-contrast ratios, full keyboard-navigation traversal, or screen-reader testing — none of these were tested live, and this report does not claim they were.

### 5.17 Repeated interactions / unnecessary clicks [Hypothesis, partially evidenced]
- Language toggle and theme toggle are both sidebar links requiring a full page reload for language (a `?lang=` GET) versus an instant client-side toggle for theme — an inconsistency in interaction cost for two conceptually similar "preference" toggles. **[Evidence]** for the mechanism difference; **[Hypothesis]** that this is felt as friction by real users.
- No "quick add" for a brand-new product from within POS or Stock In — creating a product requires leaving the current flow to visit Products first. **[Evidence]** — no such inline-create control was found in `pos/index.php` or `stock-in/index.php`.

---

## 6. Business Pain Points

Ranked by evidenced impact, not personal preference:

1. **Financial audit-trail gap (High).** §4.9 — debt creation and debt payment are invisible to the Audit Log. For a shop whose core secondary business is credit sales to farmers, this is the single largest accountability gap in the product.
2. **Audit Log has no filter/search/pagination and is capped at 200 rows (High, already self-acknowledged in code).** §4.15. As transaction volume grows, the cap alone means old changes silently fall off the visible list.
3. **No pagination or server-side search on any list, and POS ships the full catalog as inline JSON (Medium today, High at scale).** §4.2, §4.6. Already named generically in `DEVELOPMENT.md`'s Known Limitations ("No pagination on list pages yet — fine at current data volume; would matter at scale") — this discovery adds the specific POS/full-JSON evidence.
4. **No debt-aging or collections view (Medium, already on README's own roadmap).** §4.8.
5. **No self-service password reset (Medium).** §4.1 — every forgotten password is an Admin support ticket.
6. **No account deactivation, only role change (Medium).** §4.14 — departing staff can be demoted but never fully disabled/archived.
7. **No amend-after-submit for a Purchase Order (Low-Medium, needs owner input — §13).** §4.12.
8. **Manual, on-demand-only backup (Medium, already on README's own roadmap).** §4.16.
9. **No business logo on the invoice, only text fields (Low, relevant mainly for §8 productization).** §4.10, §4.16.

---

## 7. V2 Product Requirements

Grouped per instruction. Business reason given for every Must/Should item. This is a requirement **inventory**, not a committed backlog — sequencing is a V2 planning decision, not made here.

### Must Have
- **Financial audit trail for debt/payment actions.** *Reason:* §4.9 is the highest-impact accountability gap found; a credit-sale business needs "who created/modified this debt, who recorded this payment" answerable the same way "who changed this product's price" already is.
- **Audit Log filtering (date range, actor, entity type, action) and real pagination past 200 rows.** *Reason:* the current cap means historical accountability silently degrades as the shop grows — already named as a known gap in the code's own comments.
- **Self-service password reset (or, at minimum, a lower-friction Admin-assisted reset than the current in-session-only display).** *Reason:* every forgotten password currently blocks that person until an Admin is available; this becomes a real operational bottleneck the moment staff count grows beyond "Admin is always nearby."

### Should Have
- **Server-side pagination/search for Products, Customers, and the POS product/customer pickers.** *Reason:* directly evidenced current scaling ceiling (§4.2, §4.6); the existing `initLiveSearch()` pattern on list pages is a proven, reusable foundation — this is extending an existing pattern, not inventing one.
- **Debt-aging report (30/60/90-day buckets) and a collections view.** *Reason:* already named on the product's own Future Roadmap (`README.md`); directly serves the credit-sale business model this shop actually runs.
- **Account deactivation (not just role demotion).** *Reason:* §4.14 — a real, missing operational state for staff turnover.
- **Purchase Order amendment or a lighter-weight "revise draft from a submitted PO" path.** *Reason:* §4.12's cancel-and-recreate is a workable but paper-trail-losing workaround; needs owner confirmation of how often this actually happens (see §13) before committing to a specific design.
- **Scheduled/automated backups with a documented restore-verification cadence.** *Reason:* already explicitly named in README's own "Production Readiness" section as a pre-production requirement; V1 delivered the manual mechanism, not the automation.

### Could Have
- **Business logo upload for the invoice/receipt.** *Reason:* low effort relative to the existing `app_settings`/`receipt_view.php` foundation (§4.10, §4.16); mainly a professionalism/productization signal (§8), not an operational blocker.
- **Inline "quick add product" from POS/Stock In.** *Reason:* removes a context-switch (§5.17); moderate effort, not blocking any workflow today.
- **Barcode scanning extended to Stock Out and POS.** *Reason:* already on README's own Near-Term roadmap; the scanning pattern already exists and is proven on Stock In.
- **"Hold sale" / park-and-resume in POS.** *Reason:* common POS need (§4.6); needs owner confirmation this actually happens often enough at this shop to justify the schema/UX work.

### Future / Not Yet
- Business analytics suite (sales trends, margin, turnover, fast/slow movers) — already named on README's own roadmap; explicitly a larger, separate effort.
- Remote owner dashboard / proactive alerts (email/SMS for low stock or overdue debts) — already named on README's own roadmap; requires an outbound-notification capability the app has never had.
- Multi-shop/multi-branch, multi-tenancy, SaaS/subscription billing — already named on README's own roadmap under "Productization," and explicitly excluded from this V2-0 pass per this task's own instructions.
- CSV/PDF export for reports — no existing foundation to build on; a genuinely new capability, not an extension of anything in V1.

---

## 8. Future Productization Requirements

Per instruction: separating **"needs to be designed now"** (a decision or a small compatibility-preserving choice made during V2 design, without building the feature) from **"does not need to be implemented now."**

| Area | Current V1 state (Verified) | Needs deciding now? | Does NOT need building now |
|---|---|---|---|
| **Shop/business identity** | `app_settings` singleton row: name/address/phone/email, no logo (§4.16) | Whether V2's data model keeps this a singleton or is designed so a future multi-shop model wouldn't require a breaking migration — **a schema-shape decision**, not a UI feature | Multi-shop UI, shop switcher |
| **Configuration** | Only exchange rate + business identity are configurable (§4.16); everything else (thresholds, tax, receipt text) is either hard-coded or per-product | Whether V2 introduces a general key/value or structured settings table now, even with only today's settings using it | Any specific new setting beyond what's already listed as a requirement above |
| **Roles/permissions** | Fixed 3-role enum (`roles` table has exactly Admin/User/Viewer rows) | Whether the `roles` table shape (currently just `id`, `name`) is extended toward a permissions-matrix model now, since that is a schema decision with migration cost later | A full custom-permissions UI |
| **User isolation / data boundaries** | None — single database, no scoping column on any table (Verified) | **Explicitly out of scope per this task's instructions.** Not a "decide now" item — the instructions say do not implement or design multi-tenancy in V2-0/V2-1 planning beyond noting the constraint exists | Everything related to multi-tenancy |
| **Upgradeability** | `database/migrations/` (19 files, sequentially numbered) + `database/schema.sql` kept in sync (Verified pattern, consistent through K2-D's migration 019) | Whether this pattern is documented as the permanent migration convention for V2 (it already works; formalizing it costs little) | A migration-tooling framework (Phinx, Laravel migrations, etc.) |
| **Installation/deployment** | Two documented paths (XAMPP, Docker); K1 web-boundary hardening applies to both (Verified) | Whether a V2 UX/architecture change (e.g., any API boundary from §9) preserves both deployment paths or requires re-documenting one | Any new deployment target (managed cloud, k8s, etc.) |
| **Feature configuration** | None — no feature-flag mechanism beyond the one existing `SELF_REGISTRATION_ENABLED` env var (Verified, `auth/register.php`) | Whether a small, general feature-flag convention is worth establishing now given V2 will likely add several optional capabilities (analytics, remote alerts) | A full flag-management UI |
| **Auditability** | Master-data CRUD only; money movements excluded (§4.9, §6.1) | **Yes — this is a V2 Must Have (§7), not deferred** | N/A |
| **Backup/recovery** | Manual, on-demand, already audited (§4.16) | Whether the scheduling mechanism (cron? in-app?) is decided now so the manual trigger code can be reused rather than rewritten | The actual automation/scheduler implementation |
| **Localization** | Two languages, mature (§5.12) | Nothing — already a strength; no decision needed | Additional languages beyond EN/KM |
| **Reporting** | Fixed, non-exportable, no date range (§4.13) | Whether reports are designed with a future export/API boundary in mind (even if export isn't built yet) | The analytics suite itself (§7, Future/Not Yet) |
| **Extensibility** | No plugin/module system; all modules are first-party, tightly coupled to `includes/*.php` | This is squarely a V2-1 architecture question (§9), not a V2-0 product decision | Any actual plugin mechanism |

---

## 9. Architecture Constraints & Questions

**Constraints (Verified):**
- `config/base_url.php` derives `BASE_URL` by subtracting the document root from the app path — this is why K1's web-boundary hardening used deny-rules instead of a `public/`-only restructure, and it constrains any future change to the document-root layout.
- Business logic already lives in HTTP-agnostic `includes/*.php` functions (confirmed pattern: `includes/stock.php`, `includes/debt.php`, `includes/purchase_order.php` take a `PDO` handle and primitive arguments, return arrays, throw typed exceptions — they do not read `$_POST`/`$_SESSION` or emit HTML). This is the single most important architectural fact for V2-1: **an API boundary, if ever introduced, has a real seam to grow from — it is not starting from zero.**
- Validation is not centralized (`includes/validation.php` has one function; the rest is inline per-controller) — a real technical-debt item for any future shared API layer.
- No caching layer, no queue, no background job runner exists anywhere in the stack (Verified — no cron entry, no job table, no Redis/queue dependency in `composer.json`).
- The full test suite (494 tests / 3,458 assertions) is a real asset for any refactor — it already proves correctness of the concurrency-sensitive paths (`tests/Concurrency/`) that would be the riskiest to disturb.

**Questions V2-1 must answer (not answered here, per instruction not to choose a stack):**
1. Can the current server-rendered PHP architecture meet the UX requirements in §7 (server-side pagination, live filtering) without a client-side framework, or does a specific requirement (e.g., a genuinely reactive POS cart) cross a real threshold vanilla JS can't reasonably serve?
2. Where would an API boundary actually add value first — Reporting (§4.13, likely to need export/analytics consumers) is architecturally the most decoupled candidate today, since it is already read-only and already separated from write-path business logic.
3. Which shared services should be extracted from `includes/*.php` into more clearly-bounded modules before any API is built on top of them — validation (currently one function) is the clearest candidate named in this discovery.
4. What does centralizing validation cost, and does it change any currently-passing test's behavior (a real regression risk to scope explicitly before touching it)?
5. What technical debt actually blocks future evolution, versus technical debt that is merely inelegant but low-risk? (Example: the inline-per-controller validation is inelegant; the lack of any pagination is closer to blocking.)
6. What must remain stable through any V2 architecture change? At minimum: the guarded-`UPDATE`/`FOR UPDATE` concurrency model, the idempotency-token mechanism, and the audit-snapshot allowlist pattern — all proven correct by the existing Concurrency test suite and none should be casually rewritten.
7. What would make a frontend separation (a real SPA, or partial client-side rendering) worthwhile, given the current server-rendered app already has toasts, live search, sticky actions, and offcanvas navigation working well without one?
8. What migration risk exists if a future frontend is introduced later — specifically, would it require duplicating the validation/business-rule logic client-side, and does that argue for centralizing validation server-side *first*, independent of any frontend decision?

---

## 10. Preserve / Redesign / Add / Defer

**Preserve exactly (do not change business semantics):**
- Guarded-`UPDATE`/row-lock concurrency model (`includes/stock.php`, `includes/debt.php`, `includes/purchase_order.php`).
- Idempotency-token mechanism on every money-moving action.
- Audit-snapshot field-allowlist pattern (`userAuditSnapshot()` and its siblings) — the exact mechanism that makes password-leakage into an audit row structurally impossible.
- Two-path stock receiving (direct Stock In vs. PO receiving) — a real product-shape decision, not an accidental duplication (confirm with owner before touching, per §13).
- The PO cancel-preserves-receipts behavior (§4.12) — cancel is not undo, by design.
- Session/auth hardening (K1–K2-E) in its entirety — already independently re-audited; any V2 UI change must not regress it.
- Bilingual EN/KM localization mechanism and content.
- Mobile-specific UX investment (offcanvas, bottom-nav, sticky actions, bfcache handling).

**Redesign without changing business semantics:**
- Navigation information architecture (§5.1) if V2 adds modules — the underlying routes/permissions stay the same, only the presentation layer changes.
- Audit Log UI (§4.15, §6.2) — add filter/search/pagination; the underlying `audit_log` table and write pattern stay the same.
- List-page data loading for Products/Customers/POS pickers (§6.3) — move from full-payload to paginated/server-searched; the underlying queries and business rules stay the same.
- Dashboard layout (§5.2) — add role-awareness, date-range control, recent-activity feed; the underlying KPI calculations stay the same.

**Add (new capability, not present in V1):**
- Debt/payment audit trail (§6.1, §7 Must Have).
- Self-service or lower-friction password reset (§7 Must Have).
- Debt-aging/collections report (§7 Should Have).
- Account deactivation (§7 Should Have).
- Scheduled backup automation (§7 Should Have).

**Defer (explicitly not V2-0/V2-1 scope):**
- Multi-tenancy, multi-shop, SaaS billing (per task instruction).
- Any specific frontend framework choice (per task instruction — V2-1's job).
- Business-analytics suite, remote-owner alerting (already named as Future on README's own roadmap; larger than a single V2 phase).
- Barcode label generation/printing.
- CSV/PDF export.

---

## 11. V2-0 Scope Boundary

This document is discovery only. It does not:
- Change any application file, schema, migration, test, or CI workflow (verified — see §Validation below).
- Choose a frontend framework, decide on an API layer, or commit to any specific technology.
- Implement multi-tenancy, permissions redesign, or any new feature.
- Create a V2 implementation branch.

It **does** hand V2-1 (Architecture Assessment) a bounded set of questions (§9) and V2-planning a requirement inventory (§7) grounded in verified evidence from the current `main`.

---

## 12. Proposed Inputs for V2-1 Architecture Assessment

1. This document's §9 (Architecture Constraints & Questions) as the starting question list.
2. §7's Must/Should Have items as the requirements V2-1 should architecturally accommodate, without assuming any particular implementation.
3. The existing test suite as the regression baseline for any refactor: **verified today, fresh run** — `APP_ENV=test vendor/bin/phpunit` → `OK (494 tests, 3458 assertions)`.
4. The existing `ARCHITECTURE.md` (already in the repository, `docs/adr/`), which V2-1 should treat as prior art to update, not replace — note its own already-known staleness on K1–K2-E items, tracked separately (this is the same conflict already surfaced during the V1 K-QA re-audit and Documentation Sync; V2-1 should close it as part of its own work rather than this document attempting to).
5. The two-path stock-receiving question, the PO-amendment question, and the new-customer-fields question from §13 below — all need an owner answer before V2-1 can size the corresponding architecture work.

---

## 13. Open Questions Requiring Owner Input

These cannot be answered from the repository alone and are not assumed:

1. **Stock receiving:** Is having both Direct Stock In and PO Receiving intentional and actively used as two distinct real-world scenarios, or has one become vestigial? (§4.3)
2. **Purchase Order amendment:** How often, in practice, does a submitted PO need correction badly enough that cancel-and-recreate is a real pain point versus a rare edge case? (§4.12, §7)
3. **New-customer credit fields:** Does the business ever need more than name + phone for a credit customer (address, ID, credit limit)? (§4.7)
4. **Backup automation:** Is there an existing off-site/scheduled backup process today outside the app (e.g., a host-level cron), making in-app scheduling redundant, or is the manual in-app download the only backup that currently exists? (§4.16, §7)
5. **Multi-Admin reality:** Is there currently more than one Admin account in practical use, given `user_err_self_role` requires a second Admin to change any Admin's role? (§3)
6. **POS "hold sale":** Does the shop counter ever need to pause a sale mid-transaction, or is every sale completed start-to-finish without interruption in practice? (§4.6, §7)
7. **Productization timeline:** Is there any near-term (not V2, but V3+) intent to actually offer this to other shops, which would affect how much "needs to be designed now" (§8) should be front-loaded versus genuinely deferred?

---

## Validation

- **Files changed by this discovery:** `docs/v2/V2-0-DISCOVERY.md` only (new file). No application, schema, migration, test, or CI file modified.
- **`git diff --check`:** run and reported in the final delivery message.
- **Contradiction review:** performed — the one internal tension found (README.md's "Stock Reports" gap claim vs. current `table-cards-mobile` coverage) is surfaced in §2.5 as a reported conflict, not silently resolved, and not edited.
