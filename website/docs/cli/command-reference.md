---
sidebar_position: 1
---

# Command Reference

Checkpoint has 12 commands. Every destructive operation is queued by default. Pass `--sync` to run inline when a queue worker is not available.

## Backup

`checkpoint:backup {--sync} {--format=table}`

Queues a logical backup. The job runs on the configured queue (`CP_QUEUE_NAME`, default `checkpoint`). A queue worker must be running unless you use `--sync`.

```bash
php artisan checkpoint:backup
php artisan checkpoint:backup --sync
```

## Restore

`checkpoint:restore {--file=} {--pitr=} {--pitr-dry-run} {--target=} {--verification=moderate} {--verify-only} {--sync} {--force} {--format=table}`

Restore a backup. The default verification mode is `moderate`. Use `--verification=full` for a deeper post-restore check.

```bash
php artisan checkpoint:restore --sync
php artisan checkpoint:restore --file="/path/to/backup.dump" --sync
```

Point-in-time recovery:

```bash
php artisan checkpoint:restore --pitr="2026-03-11 11:30:00" --sync
```

Check PITR readiness without restoring:

```bash
php artisan checkpoint:restore --pitr-dry-run
```

Verify the last restore without re-running it:

```bash
php artisan checkpoint:restore --verify-only
```

## Drills

`checkpoint:drill {--format=table}`

Queues a recovery drill. A drill exercises the full restore path and records evidence.

```bash
php artisan checkpoint:drill
```

## Status & Health

`checkpoint:status {--limit=10} {--summary} {--brief} {--format=table} {--policy-profile=} {--watch=} {--watch-timeout=300} {--health} {--full}`

Show recent runs:

```bash
php artisan checkpoint:status
php artisan checkpoint:status --limit=25
```

Quick operator summary:

```bash
php artisan checkpoint:status --summary
```

Health checks (replaces the old `checkpoint:doctor`):

```bash
php artisan checkpoint:status --health
```

Full operational report (replaces the old `checkpoint:report`):

```bash
php artisan checkpoint:status --full
```

Poll until running jobs complete:

```bash
php artisan checkpoint:status --watch=5 --watch-timeout=300
```

JSON output:

```bash
php artisan checkpoint:status --format=json
```

## Maintenance

`checkpoint:prune {--dry-run} {--force} {--format=table}`

Deletes old command-run and drill records beyond the retention window. Preview with `--dry-run` before running for real.

```bash
php artisan checkpoint:prune --dry-run
php artisan checkpoint:prune --force
```

`checkpoint:sweep {--format=table}`

Marks timed-out running jobs as failed and re-dispatches stale pending runs. Usually scheduled every 5 minutes. The sweep also handles orphan recovery (the old `checkpoint:recover-orphans` behaviour).

```bash
php artisan checkpoint:sweep
```

`checkpoint:catalog:export {--output=} {--driver=} {--repository=} {--stanza=} {--window=} {--format=json} {--limit=10}`

Exports the backup catalog for audits and tooling.

```bash
php artisan checkpoint:catalog:export
php artisan checkpoint:catalog:export --output=/tmp/catalog.json
```

## Replication

`checkpoint:replicate {source?} {destination?} {--source=} {--destination=} {--apply} {--force-overwrite} {--critical-table=*} {--format=table}`

Replicates data between endpoints. Dry-run by default. Pass `--apply` to execute.

```bash
php artisan checkpoint:replicate
php artisan checkpoint:replicate --source=profile:pg-source --destination=profile:pg-destination
php artisan checkpoint:replicate --apply --critical-table=users --critical-table=orders
```

## Setup

`checkpoint:install {--skip-publish} {--skip-migrate} {--skip-doctor} {--force}`

Guided install. Auto-detects your database driver. Publishes config and migrations. Runs health checks.

```bash
php artisan checkpoint:install
```

`checkpoint:make-driver {name}`

Scaffolds a custom backup driver.

```bash
php artisan checkpoint:make-driver MyCustomDriver
```

`checkpoint:migrate-from-spatie {--dry-run} {--force} {--remove-spatie}`

Imports backup history from spatie/laravel-backup.

```bash
php artisan checkpoint:migrate-from-spatie --dry-run
php artisan checkpoint:migrate-from-spatie --force --remove-spatie
```

`checkpoint:config:show {--key=}`

Shows the full resolved Checkpoint configuration or a single key.

```bash
php artisan checkpoint:config:show
php artisan checkpoint:config:show --key=checkpoint.driver
```

## Old command names (don't exist anymore)

| Old command | New equivalent |
|---|---|
| `checkpoint:enqueue` | Not needed. Each command queues by default. |
| `checkpoint:enqueue-backup` | `checkpoint:backup` |
| `checkpoint:enqueue-drill` | `checkpoint:drill` |
| `checkpoint:doctor` | `checkpoint:status --health` |
| `checkpoint:report` | `checkpoint:status --full` |
| `checkpoint:pitr-readiness` | `checkpoint:restore --pitr-dry-run` |
| `checkpoint:retention-policy` | `checkpoint:prune` |
| `checkpoint:recover-orphans` | `checkpoint:sweep` (handles orphan recovery) |
| `checkpoint:record-drill` | Removed. Drills are queued via `checkpoint:drill`. |
| `checkpoint:catalog-export` | `checkpoint:catalog:export` |
