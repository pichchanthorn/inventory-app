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

## Outcome (Phase K1–K2-D)

The P0/P1/P2 items this ADR recorded as required work have since been implemented, each on its own reviewed feature branch:

- **P0 — Webroot exposure:** closed by **K1 (web boundary hardening)**. `docker/nginx/default.conf` and `.htaccess` now deny `database/`, `tests/`, `docs/`, `docker/`, `vendor/`, all dotfiles/dot-directories, and repository metadata file types (`*.sql`, `*.md`, `*.yml`, `composer.json`, etc.), placed ahead of the PHP handler so nothing inside a denied path can be re-exposed through it. The `public/`-only restructure alternative was not taken — `config/base_url.php`'s `BASE_URL` derivation depends on the repository root being the document root, and deny rules achieve the same boundary without that rewrite.
- **P1 — Session regeneration:** closed by **K2-A (session lifecycle hardening)**. `auth/login.php` calls `session_regenerate_id(true)` immediately on successful authentication, before any authenticated session value is written; logout fully invalidates the session (data, cookie, server-side record); a password change also rotates the session ID.
- **P1 — Login rate-limiting:** closed by **K2-C (login abuse protection)**. Failed attempts are throttled per submitted email address (5 failures / rolling 10-minute window, self-expiring, no permanent lockout), checked before any password verification. The same batch also closed a username-enumeration timing oracle that the original P1 language did not anticipate: unknown-email failures now perform the same bcrypt work as known-email failures.
- **P2 — Session cookie hardening and security headers:** the cookie half was closed by **K2-B (session cookie hardening)** — `HttpOnly`, `SameSite=Lax`, and a request-scheme-derived `Secure` flag, plus `session.use_strict_mode`. The security-headers half (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`) was closed by **K2-E**; `Content-Security-Policy` was deliberately deferred (see below).

Additionally, **K2-D (privilege freshness)** addressed a related gap found during K2 verification but not originally listed here: `$_SESSION['role_id']` was set once at login and never refreshed, so a role change or password reset had no effect on an already-authenticated session. Authorization is now re-read from the database once per authenticated request, and a password change invalidates other existing sessions for that account.

**Deliberately still deferred, not overlooked:**
- **Content-Security-Policy** — the application loads Bootstrap/html5-qrcode from a CDN and uses inline `<script>` for the theme/language toggle; a real CSP needs nonces or hashes across those, which is a project rather than a hygiene fix.
- **HSTS** — both shipped deployments (Docker's `listen 80`, the documented XAMPP setup) are plain HTTP; an HSTS header on an HTTP origin is ignored by browsers and would only become appropriate once TLS is actually in front of the application.
- **Login CSRF and logout-as-POST** — not part of this ADR's original P0/P1/P2 list; evaluated separately and deferred as design decisions, not gaps.

This decision's scope boundary still holds: none of the above changed business logic, RBAC semantics, or CSRF behavior.
