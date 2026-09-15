# ADR-007: Concurrency and Idempotency Invariants Are Architecture Law

## Status

Accepted

## Context

PCTN's stock, debt, and purchase-order correctness depends entirely on a small set of concurrency and idempotency mechanisms, confirmed present throughout `includes/stock.php`, `includes/debt.php`, and `includes/purchase_order.php`: guarded UPDATEs (`WHERE current_stock >= ?`), `SELECT ... FOR UPDATE` row locks taken before any decision is made, a deterministic product-row-before-batch-row lock ordering, `SELECT ... FOR UPDATE`-based reference-counter sequencing, and idempotency tokens claimed as the first statement inside the transaction they protect. These mechanisms are independently verified by a genuine multi-process concurrency test suite. As the codebase evolves (extraction, API boundary, eventual multi-tenancy), there is real risk that a well-intentioned refactor could subtly weaken one of these mechanisms — e.g. by moving a check out of a guarded UPDATE's WHERE clause into a separate PHP-side `if`, which looks equivalent in a single-request test but is not equivalent under real concurrent load.

## Decision

The concurrency and idempotency mechanisms listed in `ARCHITECTURE.md` §14 are declared **architecture invariants**: they must be preserved, unweakened, through every future change, including any refactor, Application Service extraction, API boundary work, or eventual framework migration. Specifically and non-negotiably:

- A guard condition that currently lives inside an `UPDATE ... WHERE` clause must never be moved to a separate, unguarded read-then-write.
- Lock ordering (product row before batch row) must never be reversed by a new code path.
- An idempotency token claim must remain the first statement inside the transaction it protects.
- No monetary or quantity invariant currently enforced by both an application-level guard and a database CHECK constraint may have either layer removed.

Any change that would touch one of these mechanisms requires explicit verification (an updated or new concurrency test demonstrating the invariant still holds under genuine multi-process contention), not just a passing single-threaded test.

## Consequences

- Future engineers (including Claude Code, per the engineering rules in `ARCHITECTURE.md` §22) have an explicit, checkable list of what "don't break this" means concretely, rather than a vague instruction to "be careful with concurrency."
- Any pull request touching `includes/stock.php`, `includes/debt.php`, or `includes/purchase_order.php` should be reviewed against this list specifically.
- This does not freeze these files from ever changing — it constrains *how* they may change, not *whether*.

## Alternatives Considered

- **Trust code review alone, without a written invariant list:** rejected — the whole point of documenting these mechanisms is that they are subtle enough to be broken by a change that looks correct in isolation; an explicit list is cheap insurance against exactly that failure mode.
- **Add a static-analysis rule to mechanically enforce these invariants:** not rejected, but out of scope for this decision — a worthwhile future addition to CI (§16), not a substitute for the invariant being documented and understood first.
