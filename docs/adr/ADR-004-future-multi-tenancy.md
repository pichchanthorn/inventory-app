# ADR-004: Future Multi-Tenancy Is Positioned, Not Scheduled

## Status

Accepted

## Context

PCTN's stated long-term goal is to eventually support multiple businesses/shops on shared infrastructure. Today, no tenant/business-scoping concept exists anywhere in the schema — every table (`products`, `customers`, `suppliers`, `stock_transactions`, `purchase_orders`, `audit_log`, `users`) is globally scoped, and `app_settings` is a hardcoded singleton row (`id=1`). This is confirmed correct and deliberate for the current single-shop (PCTN) scope, evidenced directly by an in-code schema comment stating the singleton design is "the smallest structure that lets a different shop reuse this same software later... without building a multi-tenant system now."

## Decision

Multi-tenancy is recorded as a **future architectural target** (`ARCHITECTURE.md` §8), including the conceptual shape (`Business/Tenant → Users, Products, Suppliers, Customers, Sales, Purchasing, Cash, Expenses, Settings`) and the known migration implications (schema-wide `business_id` addition, re-scoping of global unique constraints, per-business reference counters and settings). **It is explicitly not scheduled, and no implementation work — including any schema change — is authorized by this decision.**

Multi-tenancy implementation should begin only once a real second-business requirement exists to validate the design against (e.g. an actual second shop wanting to use the software), not speculatively.

## Consequences

- No `tenant_id`/`business_id` column, table, or migration exists as a result of this decision.
- When multi-tenancy is eventually pursued, it has a documented starting design to work from rather than starting from zero — reducing the risk of a rushed, under-designed first attempt.
- The current single-tenant schema and queries remain unchanged and fully supported in the meantime — no code is written today "in anticipation of" tenant scoping, avoiding premature abstraction with no validating use case.
- The difficulty of retrofitting tenant scoping later is assessed as moderate (not trivial, not severe) in `ARCHITECTURE.md` §8 — favorable because the domain layer already takes explicit parameters rather than reading global state implicitly; unfavorable because it is genuinely schema-wide (roughly 15 core tables plus every global unique constraint).

## Alternatives Considered

- **Add `business_id` to every table now, defaulted to a single hardcoded PCTN row, "for future-proofing":** rejected — this is speculative schema complexity with no current consumer, adds a WHERE-clause dimension to every existing query for zero present benefit, and risks guessing the wrong cardinality (e.g. single-business-per-user vs. many-to-many) before any real second-tenant requirement exists to validate against.
- **Design and implement multi-tenancy immediately as part of this architecture baseline:** rejected — explicitly out of scope per the task instructions this ADR set was produced under, and premature for the reason above.
