# AGENTs.md

Guidelines for humans and AI agents contributing to `adityaa-codes/laravel-checkpoint`.

## Mission

Make backup/restore operations safe, auditable, and automation-friendly for Laravel applications, with defaults that are trustworthy in production.

## Core package strategy

1. **Safety before convenience**: destructive operations (restore/replication apply) must remain guarded.
2. **Evidence over assumption**: “backup succeeded” is not enough; keep restore verification and drill evidence visible.
3. **Operator + agent dual UX**:
   - human CLI stays concise and triage-first
   - `--agent` output stays deterministic, parseable, and versioned
4. **Additive contracts**: evolve JSON payloads without breaking existing consumers.
5. **Production install path**: production consumers install via Composer package release, not local path repositories.

## Production-grade implementation rules

1. **Composer-first production flow**
   - use `composer require adityaa-codes/laravel-checkpoint`
   - local path repositories are testing/dev-only
2. **Explicit readiness semantics**
   - keep readiness labels clear (`dev-only`, `staging-ready`, `prod-ready`, `not-ready`)
   - avoid ambiguous “green” language when restore evidence is missing
3. **Deterministic machine contract**
   - maintain `surface`, `version`, and `schema_version` (for agent envelopes)
   - include a compact canonical block for fast automation decisions
4. **Severity and actionability**
   - include clear top issue + immediate action in brief/agent outputs
   - reduce warning noise in default operator surfaces
5. **Environment-agnostic operation**
   - avoid coupling package behavior to any specific local tooling stack
   - document generic remediation that works across local and CI environments

## Known pitfalls to avoid

1. Local path repositories can fail when referenced paths are not available in the current runtime environment.
2. Stale local package copies can make command availability appear inconsistent.
3. Output can become too verbose; preserve concise triage defaults.

## Contribution checklist for hardening work

1. Update code + tests together for any output-contract change.
2. Keep agent output additive; do not remove existing keys without versioned migration.
3. Update docs when changing install behavior, command semantics, or output meaning.
4. Validate with package tests and at least one real consuming Laravel app flow.
