---
sidebar_position: 1
---

# Command Reference

This page documents the command surface currently registered by `LaravelCheckpointServiceProvider`.

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
