---
sidebar_position: 1
---

# Command Reference

This page documents the command surface currently registered by `LaravelCheckpointServiceProvider`.

## Command taxonomy (recommended)

- `db-ops:do:*` → operator workflow commands
- `db-ops:check:*` → validation and diagnostics
- `db-ops:admin:*` → governance and maintenance

Golden path:

```bash
php artisan db-ops:do:install --preset=postgres-prod --write-env
php artisan db-ops:do:backup
php artisan db-ops:do:status --summary
php artisan db-ops:check:doctor
php artisan db-ops:check:report
```

Journey command map:

| Journey command | Base command | Purpose |
| --- | --- | --- |
| `db-ops:do:install` | `db-ops:install` | Guided install/preset setup |
| `db-ops:do:backup` | `db-ops:enqueue-backup` | Default logical backup |
| `db-ops:do:backup:logical` | `db-ops:enqueue logical_backup` | Explicit logical backup |
| `db-ops:do:backup:full` | `db-ops:enqueue pgbackrest_backup_full` | pgBackRest full backup |
| `db-ops:do:backup:diff` | `db-ops:enqueue pgbackrest_backup_diff` | pgBackRest differential backup |
| `db-ops:do:backup:incr` | `db-ops:enqueue pgbackrest_backup_incr` | pgBackRest incremental backup |
| `db-ops:do:restore:latest` | `db-ops:enqueue logical_restore_latest` | Restore latest backup |
| `db-ops:do:restore:file {file}` | `db-ops:enqueue logical_restore_file --argument=...` | Restore selected backup file |
| `db-ops:do:restore:pitr {target}` | `db-ops:enqueue pitr_restore --argument=...` | Restore to point-in-time target |
| `db-ops:do:replicate` | `db-ops:replicate` | Replication dry-run/apply workflow |
| `db-ops:do:drill` | `db-ops:enqueue-drill` | Backup drill execution |
| `db-ops:do:status` | `db-ops:status` | Operator status/summary |
| `db-ops:check:doctor` | `db-ops:doctor` | Health/config diagnostics |
| `db-ops:check:report` | `db-ops:report` | Operational reporting |
| `db-ops:check:pitr` | `db-ops:pitr-readiness` | PITR readiness validation |
| `db-ops:check:health` | `db-ops:health-check` | Stale-running health sweep |
| `db-ops:admin:retention` | `db-ops:retention-policy` | Retention preview/apply |
| `db-ops:admin:prune` | `db-ops:prune` | History pruning |
| `db-ops:admin:recover-orphans` | `db-ops:recover-orphans` | Recover stale queue items |
| `db-ops:admin:catalog-export` | `db-ops:catalog-export` | Export backup catalog data |

## Queueing commands

`db-ops:enqueue {operation?} {--argument=}`

- generic entrypoint for catalog-backed operations
- prompts interactively when no operation is provided
- validates required arguments through `CommandRunCatalog`
- in non-interactive mode, missing operation/required argument fails immediately

`db-ops:enqueue-backup`

- queues `logical_backup`

`db-ops:enqueue-drill`

- queues `backup_drill`

`db-ops:replicate {source?} {destination?} {--source=} {--destination=} {--apply} {--force-overwrite} {--critical-table=*}`

- queues `replication_sync`
- defaults to dry-run mode
- supports `profile:<id>`, DSN, or key-value endpoint input
- execution supports only local/configured endpoint semantics
- remote/cross-host endpoint intent is rejected at runtime
- apply mode re-checks governance preflight at execution time
- in non-interactive mode, missing source/destination fails immediately

## Status and reporting

`db-ops:status {--limit=10} {--summary} {--brief} {--format=table} {--agent} {--policy-profile=}`

- recent runs or operator summary
- `--brief` renders triage-first cause/action output
- `table`, `json`, and `agent` output modes
- `--policy-profile` overrides gate policy profile selection for CI/automation runs

`db-ops:doctor {--brief} {--format=table} {--agent} {--policy-profile=}`

- health checks and config validation
- checks are severity-labeled as `blocker`, `warning`, or `info`
- `--brief` returns top issues and immediate next action
- `--policy-profile` overrides gate policy profile selection for CI/automation runs
- default table output prioritizes P0/P1 checks and suppresses passing checks unless run with `-v`

`db-ops:report {--limit=10} {--brief} {--format=table} {--agent} {--policy-profile=}`

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
2. `DB_OPS_GATE_PROFILE` / `checkpoint.gates.override_profile`
3. `checkpoint.gates.environment_profile_map[APP_ENV]`
4. `checkpoint.gates.default_profile`

`db-ops:catalog-export {--format=json} {--driver=} {--repository=} {--stanza=} {--window=} {--limit=100}`

- machine-friendly backup catalog export
- `json` or `csv`

`db-ops:pitr-readiness {target?} {--format=table} {--agent}`

- evaluates restore readiness for a target timestamp

## Maintenance commands

`db-ops:health-check`

- marks timed-out running runs as failed based on package health logic

`db-ops:recover-orphans`

- claims and recovers stale queue work

`db-ops:prune`

- prunes old command-run and backup-drill records

`db-ops:retention-policy {--format=table} {--limit=100} {--dry-run} {--apply}`

- previews or applies policy-based retention

## Drill recording

`db-ops:record-drill`

Required flags:

- `--run-uuid`
- `--overall-result`
- `--executed-at`

Optional evidence flags:

- marker UUID, email, count, result
- RTO target, actual, result
- RPO target, actual, result
- `--executed-by`
