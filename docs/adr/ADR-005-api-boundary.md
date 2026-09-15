# ADR-005: Future API Boundary Design

## Status

Accepted

## Context

PCTN currently has exactly one client: the server-rendered browser UI. No REST or other API exists. The stated long-term goal includes supporting additional future clients (e.g. mobile, third-party integrations). The existing Application Service functions for Stock, Debt, and Purchase Orders are already HTTP-agnostic and therefore already callable from a future API layer with no changes — but authentication (session-based only) and CSRF (form-token-based) do not translate directly to a non-browser API client, and several modules (Category/Unit/Supplier) do not yet have an extracted Application Service layer to call into.

## Decision

A future API layer (`ARCHITECTURE.md` §12) will sit alongside the existing web UI, calling into the same Application Service functions the web UI already uses — it will **never** manipulate database tables directly, and will never duplicate business logic that already exists in `includes/`. Authentication for the API will use a token/API-key mechanism designed to coexist with, not replace, the existing session-based web authentication. No specific token scheme, API framework, or endpoint design is chosen by this decision.

**No API is implemented by this decision.** This ADR records the boundary rule an eventual API must follow, so that if API work begins opportunistically or is done by a different engineer later, it starts from an agreed constraint rather than reinventing the boundary question.

## Consequences

- When an API is eventually built, Purchase Orders, Stock, and Debt/Sales can be exposed with comparatively little rework, since their domain logic is already isolated (ADR-003 already establishes this pattern going forward for everything else).
- Category/Unit/Supplier (and any other still-inline module at the time) would need Application Service extraction (ADR-003) before being safely exposed via API — attempting to expose them as-is would either duplicate their inline logic into a new API handler or require the extraction to happen anyway, just later and under more time pressure.
- The CSRF/session-auth mechanism protecting the current web UI (`ARCHITECTURE.md` §13) is not weakened or replaced by this decision — it continues to protect the existing browser-based mutation paths exactly as today; a new, separate mechanism is added for API routes specifically, not substituted in place of it.

## Alternatives Considered

- **Build a thin API now that directly queries the database for convenience:** rejected — this would immediately violate the one non-negotiable rule this ADR exists to establish, and would create a second, divergent source of business-rule enforcement outside `includes/`.
- **Wait until every module is Application-Service-extracted before designing any API boundary rule:** rejected — the rule itself (call services, never tables directly) can and should be agreed now, independent of how much of the codebase currently satisfies it, so that new work already conforms rather than needing correction later.
