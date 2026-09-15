# ADR-002: No Big-Bang Rewrite

## Status

Accepted

## Context

PCTN Inventory v1.0.0 is a plain PHP/PDO application rather than a framework-based one. A framework migration or full rewrite is a natural idea to consider when planning a multi-year commercial evolution, and was explicitly evaluated as part of the architecture baseline work behind this decision (see the prior read-only architecture audit conducted against `main` @ `e736970`).

## Decision

PCTN will evolve **incrementally**, in-place, on top of the existing codebase. No full rewrite, and no wholesale framework migration, is planned or authorized. Evolution proceeds through the staged roadmap in `ARCHITECTURE.md` §20: hardening, then extraction, then API boundary preparation, then commercial/multi-tenant capability — each stage additive to the last.

## Consequences

- Every proven correctness guarantee already in production (guarded-UPDATE stock concurrency, idempotency tokens, the Purchase Order state machine, the audit trail's structural password redaction, the multi-process concurrency test suite) is carried forward continuously rather than needing to be re-proven from zero in a new codebase.
- Evolution is slower in the short term than a rewrite might promise, but carries substantially lower risk of silently regressing a subtle invariant (e.g. lock ordering, NULL-safe uniqueness handling) that took multiple iterations to get right the first time.
- New capabilities (API, multi-tenancy, Cash & Money) are added as extensions to the existing domain layer, not as a parallel implementation that must later be reconciled with the old one.
- This decision does not forbid a future framework adoption outright — it forbids treating one as a starting assumption. A framework decision, if ever made, must be its own deliberate, separately-justified choice (see ADR-009), applied incrementally to the existing structure, not as a rewrite trigger.

## Alternatives Considered

- **Full rewrite on a modern framework:** rejected — the current codebase's domain logic for its three most business-critical modules is already correctly separated from HTTP concerns, already transaction-safe, and already covered by genuine multi-process concurrency tests; a rewrite would at best re-arrive at guarantees that already exist, at real risk of regressing them.
- **Rewrite only the "hard parts" (stock/PO concurrency) while keeping the rest:** rejected — the "hard parts" are exactly the parts already proven correct; the parts that would benefit most from rework (simple CRUD modules) are the lowest-risk, lowest-value target for a rewrite effort.
