# ADR-003: Application Service Direction for Business Logic

## Status

Accepted

## Context

Business logic in PCTN is unevenly extracted today: `includes/stock.php`, `includes/debt.php`, and `includes/purchase_order.php` already expose HTTP-agnostic, transaction-owning functions (`recordStockIn()`, `recordCreditSale()`, `createPurchaseOrder()`, etc.) that page scripts call into. Category, Unit, and Supplier CRUD, by contrast, still perform their `INSERT`/`UPDATE`/`logAudit()` calls directly inline inside their page scripts. As more modules are added (P3-B, future Cash & Money, future Expenses), a consistent rule is needed for where business logic should live.

## Decision

Going forward, **HTTP/page/API handlers should not contain core business rules.** A handler's responsibility is limited to: authenticating/authorizing the request, translating request input into primitive arguments, calling exactly one Application Service function, and translating that function's result or thrown exception into a response. The Application Service function owns the transaction, the business-rule enforcement, and the persistence call, exactly as `createPurchaseOrder()` already does today.

This is recorded as the **target direction for new and refactored code**, not a mandate to immediately refactor Category/Unit/Supplier CRUD or any other currently-inline module. Extraction of existing inline modules happens opportunistically or as part of a dedicated Stage 2 effort (`ARCHITECTURE.md` §20), never as an incidental side effect of an unrelated task.

## Consequences

- New business mutations (P3-B and beyond) should be written as Application Service functions in `includes/` from the start, following the existing `stock.php`/`purchase_order.php` pattern, rather than inline in a page script.
- Existing inline modules (Category/Unit/Supplier) remain as-is until a dedicated extraction effort addresses them — this decision does not retroactively flag them as broken, only as not-yet-aligned with the target.
- A future API layer (ADR-005) can call the same Application Service functions the web UI already calls, with no duplication of business logic, precisely because this separation is enforced going forward.

## Alternatives Considered

- **Immediately refactor every inline module to match this pattern:** rejected as out of scope for this decision — it is a structural direction, not a work order; doing so without a specific driving need would violate the "smallest correct change" engineering rule (`ARCHITECTURE.md` §22).
- **Allow business logic in handlers when "simple enough":** rejected — a per-case judgment call erodes the rule over time; the existing Category/Unit/Supplier modules are exactly the kind of "simple enough" logic that was allowed to stay inline historically, and are now the acknowledged debt this decision aims to stop growing further.
