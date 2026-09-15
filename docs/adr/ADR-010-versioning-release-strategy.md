# ADR-010: Versioning and Release Strategy

## Status

Accepted

## Context

PCTN has reached v1.0.0. Prior to this, feature work was tracked as named phases (P1, P2, P3-A, ...) recorded in `DEVELOPMENT.md`, each independently branched, tested, and merged. As the project continues, both phase-based engineering tracking and a formal release version number need to coexist without being conflated with each other.

## Decision

PCTN adopts semantic versioning for releases:

```
v1.0.0  = stable baseline
v1.0.x  = patches / bug/security fixes
v1.x.0  = backward-compatible features
v2.0.0  = breaking product/architecture changes
```

**Project phases and semantic versions are different concepts and are tracked separately.** `DEVELOPMENT.md` remains the authoritative record of phase-level engineering history (what was built, when, with what test results). `README.md` and this architecture document remain the authoritative record of release/version state. A phase's completion does not automatically imply a version bump, and a version bump does not automatically imply a phase boundary — e.g. multiple phases may ship within one `v1.x.0` release, or a single security patch (`v1.0.x`) may involve no named phase at all.

A `v2.0.0` is reserved for breaking changes — most likely triggered by multi-tenancy (ADR-004) or an API boundary change (ADR-005) that alters existing behavior incompatibly, should either ever reach implementation.

## Consequences

- Contributors and users have a predictable way to reason about compatibility (semantic version) independent of engineering process detail (phase names).
- `DEVELOPMENT.md`'s existing per-phase documentation convention (status, branch, commits, PR number, test results) continues unchanged — this decision does not alter that document's structure or purpose.
- Future ADRs or architecture updates that represent a genuine breaking change to the product (not just to internal code structure) should explicitly flag themselves as `v2.0.0`-triggering, so the distinction stays meaningful over time rather than eroding.

## Alternatives Considered

- **Use phase names as the version scheme (e.g. "P3-B release"):** rejected — phase names describe engineering work, not public compatibility guarantees; conflating them would make it unclear to any external consumer (or future multi-tenant customer) what compatibility to expect between releases.
- **Skip formal versioning entirely (rolling `main`-is-latest model):** rejected — now that v1.0.0 has been tagged as a stable baseline, a rolling model would make it impossible to reference a stable point for support, rollback, or compatibility discussions going forward.
