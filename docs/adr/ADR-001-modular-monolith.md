# ADR-001: Modular Monolith as the Target Structural Style

## Status

Accepted

## Context

PCTN Inventory v1.0.0 is a single-deployable PHP application organized as one directory per feature module, with core business logic for Stock, Debt, and Purchase Orders already extracted into HTTP-agnostic `includes/*.php` functions. As the product grows (more modules, eventual API clients, eventual multi-tenancy), a structural direction is needed that avoids two failure modes: (a) letting the codebase drift into an undifferentiated "big ball of mud" where every module can reach into every other module's internals, and (b) prematurely splitting into separately-deployed services (microservices) before there is any operational or team-scaling reason to pay that complexity cost.

## Decision

PCTN will evolve as a **modular monolith**: a single deployable application, internally organized into clearly-bounded modules (Identity & Access, Product Management, Inventory, Purchasing, Sales, Customers & Debt, Cash & Money, Expenses, Reporting, Audit, Settings — see `ARCHITECTURE.md` §4), each with an identifiable set of responsibilities and a preferred direction of dependency (e.g. Sales depends on Inventory, never the reverse). Modules communicate through function calls (as they already do — `pos/index.php` calling `recordCreditSale()`), not through network calls, message queues, or separate databases.

## Consequences

- The application remains a single deployable unit — one PHP codebase, one database — which keeps operational complexity low and matches the team's actual current scale.
- Module boundaries are enforced by convention and code review discipline, not by a hard technical barrier (e.g. separate processes) — this is a real, accepted tradeoff, not an oversight.
- Extracting a module into a separately-deployed service later remains possible without a full rewrite, precisely because the boundary is already conceptually drawn — but no such extraction is planned or scheduled by this decision.
- The already-existing `includes/*.php` domain functions for Stock/Debt/PurchaseOrder are the concrete evidence this direction is already partially realized, not a theoretical target.

## Alternatives Considered

- **Microservices from the start:** rejected — no evidence of a scaling or team-boundary problem that would justify the operational cost (separate deployments, network calls where function calls suffice, distributed transaction complexity replacing the proven single-database transaction guarantees in `ARCHITECTURE.md` §14).
- **No structural direction (status quo drift):** rejected — without naming module boundaries explicitly, there is nothing to evaluate a future change against, and the risk of undifferentiated coupling grows as more modules are added.
