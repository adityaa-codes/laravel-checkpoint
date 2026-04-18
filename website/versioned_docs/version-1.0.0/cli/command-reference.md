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

`db-ops:enqueue-backup`

- queues `logical_backup`

`db-ops:enqueue-drill`

- queues `backup_drill`

`db-ops:replicate {source?} {destination?} {--source=} {--destination=} {--apply} {--force-overwrite} {--critical-table=*}`

- queues `replication_sync`
- defaults to dry-run mode
- supports `profile:<id>`, DSN, or key-value endpoint input

## Status and reporting

`db-ops:status {--limit=10} {--summary} {--format=table} {--agent}`

- recent runs or operator summary
- `table`, `json`, and `agent` output modes

`db-ops:doctor {--format=table} {--agent}`

- health checks and config validation

`db-ops:report {--limit=10} {--format=table} {--agent}`

- recent runs, summary, verification, and health payload

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
