# ADR-009: Framework Selection Is Deferred

## Status

Accepted

## Context

PCTN Inventory is plain PHP/PDO with no framework. As the product's ambitions grow (API clients, multi-tenancy, a larger contributor base), the question of whether to adopt a framework (Laravel, ASP.NET Core, NestJS, Spring Boot, Django/FastAPI, or others) will naturally recur. Choosing prematurely — before the shape of the actual future requirements (team size, hosting constraints, existing team language expertise, specific integration needs) is known — risks locking in a decision that doesn't fit the eventual reality, and risks becoming the justification for an unnecessary rewrite (see ADR-002).

## Decision

No framework is selected at this time. The architecture described in `ARCHITECTURE.md` (modular monolith, layered target, Application Service direction) is deliberately expressed in framework-neutral terms specifically so that it remains valid guidance regardless of whether, or which, framework is eventually chosen — or if the application remains plain PHP indefinitely, which remains an entirely acceptable outcome.

Framework selection, if it ever happens, must be its own separately-justified decision, made against concrete, evidenced requirements at that time, not inferred from any diagram in the architecture baseline.

## Consequences

- Every stage of the migration roadmap (`ARCHITECTURE.md` §20) remains achievable without a framework decision blocking progress.
- Terms used in this architecture baseline ("Application Service," "Domain Rules," "API layer") are generic software-architecture concepts, not a specific framework's class names — no future engineer should read them as an implicit instruction to introduce a specific framework's conventions.
- If a framework is eventually chosen, the existing `includes/*.php` Application Service functions are expected to port with comparatively little change, since they are already framework-agnostic (PDO + primitives in, arrays/exceptions out) — this was a deliberate design property of this codebase already, not something this decision creates.

## Alternatives Considered

- **Select a framework now to guide future structure:** rejected — no evidenced requirement currently distinguishes between candidate frameworks, and committing early would bias the architecture toward one framework's conventions without justification, contrary to the framework-neutral target this document establishes.
- **Rule out ever adopting a framework:** not decided either way — this ADR defers the decision, it does not foreclose it.
