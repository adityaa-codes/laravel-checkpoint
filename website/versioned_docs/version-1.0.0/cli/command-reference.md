---
sidebar_position: 1
---

# Command Reference

This page documents the command surface currently registered by `LaravelCheckpointServiceProvider`.

Golden path:

```bash
php artisan checkpoint:install --preset=postgres-prod --write-env
php artisan checkpoint:enqueue-backup
php artisan checkpoint:status --summary
php artisan checkpoint:doctor
php artisan checkpoint:report
```

Command surface:

## Queueing commands

`checkpoint:enqueue {operation?} {--argument=}`

- generic entrypoint for catalog-backed operations
- prompts interactively when no operation is provided
- validates required arguments through `CommandRunCatalog`
- in non-interactive mode, missing operation/required argument fails immediately

`checkpoint:enqueue-backup`

- queues `logical_backup`

`checkpoint:enqueue-drill`

- queues `backup_drill`

`checkpoint:replicate {source?} {destination?} {--source=} {--destination=} {--apply} {--force-overwrite} {--critical-table=*}`

- queues `replication_sync`
- defaults to dry-run mode
- supports `profile:<id>`, DSN, or key-value endpoint input
- execution supports only local/configured endpoint semantics
- remote/cross-host endpoint intent is rejected at runtime
- apply mode re-checks governance preflight at execution time
- in non-interactive mode, missing source/destination fails immediately

## Status and reporting

`checkpoint:status {--limit=10} {--summary} {--brief} {--format=table} {--agent} {--policy-profile=}`

- recent runs or operator summary
- `--brief` renders triage-first cause/action output
- `table`, `json`, and `agent` output modes
- `--policy-profile` overrides gate policy profile selection for CI/automation runs

`checkpoint:doctor {--brief} {--format=table} {--agent} {--policy-profile=}`

- health checks and config validation
- checks are severity-labeled as `blocker`, `warning`, or `info`
- `--brief` returns top issues and immediate next action
- `--policy-profile` overrides gate policy profile selection for CI/automation runs
- default table output prioritizes P0/P1 checks and suppresses passing checks unless run with `-v`

`checkpoint:report {--limit=10} {--brief} {--format=table} {--agent} {--policy-profile=}`

- recent runs, summary, verification, and health payload
- includes explicit `last_failed_run` in JSON/agent payloads
- `--policy-profile` overrides gate policy profile selection for CI/automation runs
- default table output prioritizes P0/P1 checks and suppresses passing checks unless run with `-v`

Agent-friendly tips:

- use `--agent` for concise output in scripts and agent loops
- agent responses include `schema_version` for contract-safe parsing
- use `--format=json` when you need full structured fields
- for test output, run `vendor/bin/pest --compact`; PAO hooks in automatically when an agent is detected

## Exit code semantics (policy-gated)

Operational commands evaluate safety/evidence gates and return deterministic exit codes:

- `0` → pass
- `2` → warning-only policy result (when enabled by profile policy)
- `10` → safety gate failed
- `11` → evidence gate failed
- `12` → gate policy/config evaluation failed

Policy profile resolution order:

1. `--policy-profile=<name>` (highest priority)
2. `CP_GATE_PROFILE` / `checkpoint.gates.override_profile`
3. `checkpoint.gates.environment_profile_map[APP_ENV]`
4. `checkpoint.gates.default_profile`

`checkpoint:catalog-export {--format=json} {--driver=} {--repository=} {--stanza=} {--window=} {--limit=100}`

- machine-friendly backup catalog export
- `json` or `csv`

`checkpoint:pitr-readiness {target?} {--format=table} {--agent}`

- evaluates restore readiness for a target timestamp

## Maintenance commands

`checkpoint:health-check`

- marks timed-out running runs as failed based on package health logic

`checkpoint:recover-orphans`

- claims and recovers stale queue work

`checkpoint:prune`

- prunes old command-run and backup-drill records

`checkpoint:retention-policy {--format=table} {--limit=100} {--dry-run} {--apply}`

- previews or applies policy-based retention

## Drill recording

`checkpoint:record-drill`

Required flags:

- `--run-uuid`
- `--overall-result`
- `--executed-at`

Optional evidence flags:

- marker UUID, email, count, result
- RTO target, actual, result
- RPO target, actual, result
- `--executed-by`
