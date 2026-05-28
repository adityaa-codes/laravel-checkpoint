---
sidebar_position: 1
---

# Command Reference

All commands registered by the package.

## Queueing commands

### `checkpoint:backup {--sync} {--format=table}`

Queues a logical backup. Async by default. Use `--sync` for inline execution.

### `checkpoint:restore {--file=} {--pitr=} {--pitr-dry-run} {--target=} {--verification=moderate} {--verify-only} {--sync} {--force} {--format=table}`

Restores from file or PITR. Use `--file` to specify a backup file, or `--pitr` for a point-in-time target. `--pitr-dry-run` evaluates readiness without executing. `--verification` controls post-restore verification level. `--verify-only` runs verification on an existing restore.

### `checkpoint:drill {--format=table}`

Queues a recovery drill.

### `checkpoint:replicate {source?} {destination?} {--source=} {--destination=} {--apply} {--force-overwrite} {--critical-table=*} {--format=table}`

Replication sync. Dry-run by default. Pass `--apply` to execute. `--force-overwrite` skips confirmation prompts.

## Status and reporting

### `checkpoint:status {--limit=10} {--summary} {--brief} {--format=table} {--policy-profile=} {--watch=} {--watch-timeout=300} {--health} {--full}`

Status, health checks, summary, and reporting. Use `--health` for a doctor-style health check. `--watch` polls at an interval. `--full` includes all details.

## Catalog

### `checkpoint:catalog:export {--output=} {--driver=} {--repository=} {--stanza=} {--window=} {--format=json} {--limit=10}`

Exports the backup catalog. Default format is JSON.

## Maintenance

### `checkpoint:sweep`

Marks timed-out runs as failed and re-dispatches stale orphans.

### `checkpoint:prune {--dry-run} {--force} {--format=table}`

Cleans old command-run and backup-drill records. Preview with `--dry-run`.

## Setup

### `checkpoint:install {--skip-publish} {--skip-migrate} {--skip-doctor} {--force}`

Guided installation. Publishes config and migrations, runs the doctor check.

### `checkpoint:make-driver`

Scaffolds a custom driver class.

### `checkpoint:migrate-from-spatie {--dry-run} {--force} {--remove-spatie}`

Migrates backup configuration from spatie/laravel-backup.

### `checkpoint:config:show {--key=}`

Shows resolved package configuration. Pass `--key` for a specific config path.
