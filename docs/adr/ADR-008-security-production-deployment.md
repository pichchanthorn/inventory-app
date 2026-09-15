# ADR-008: Security and Production Deployment Requirements

## Status

Accepted

## Context

An architecture audit of `main` @ `e736970` identified specific, evidenced security and deployment gaps: the reference Nginx/Docker configuration serves the entire repository (including `database/schema.sql`, `database/seed.sql`, `.git/`) as static files because there is no `public/`-only web root (P0); login does not call `session_regenerate_id()` (P1, session-fixation exposure); login has no brute-force/rate-limit protection (P1); and there are no explicit session-cookie hardening flags or security headers (P2). None of these are application-domain-logic defects — they are deployment-configuration and auth-hardening gaps.

## Decision

These findings are recorded as required work for any production/internet-facing deployment, prioritized P0 first:

- **P0 — Webroot exposure:** any production deployment must use a `public/`-only web root (or an equivalent Nginx configuration that blocks non-PHP static file serving of `config/`, `includes/`, `database/`, `tests/`, and dotfiles) before going live.
- **P1 — Session regeneration:** `session_regenerate_id(true)` must be called immediately after successful authentication, before any further code path.
- **P1 — Login rate-limiting:** a failed-attempt counter (per-email and/or per-IP) with a backoff/lockout window must be added to the login path.
- **P2 — Session cookie hardening and security headers:** `httponly`/`secure`/`samesite` cookie flags and standard security headers (`X-Frame-Options`, `Content-Security-Policy`, `X-Content-Type-Options`) should be added.

This decision does not implement any of the above — it commits to them as required, sequenced work (Stage 1 of `ARCHITECTURE.md` §20), separate from and prior to any feature work that assumes an internet-facing deployment.

## Consequences

- The current single-shop, likely-LAN-adjacent deployment of v1.0.0 is not immediately blocked by this decision — these are deployment-context-dependent requirements, most urgent the moment the application becomes reachable from the public internet.
- Any future commercial/multi-tenant deployment (ADR-004) is explicitly gated on these items being resolved first — multi-tenancy without P0/P1 resolved would multiply the exposure across multiple businesses' data.
- This decision does not authorize any change to business logic, RBAC semantics, or CSRF behavior — it is scoped strictly to session/deployment hardening.

## Alternatives Considered

- **Defer all of these until immediately before a production launch:** rejected — recording them now, as an accepted decision, ensures they are treated as required Stage 1 work rather than being rediscovered (or forgotten) later under launch time pressure.
- **Treat the webroot exposure as low-priority because "it's just source code, not data":** rejected — `database/seed.sql` and `database/schema.sql` are directly exposed by the current reference config, and schema/seed exposure is a real information-disclosure risk, independent of whether production seed data contains anything sensitive today.
