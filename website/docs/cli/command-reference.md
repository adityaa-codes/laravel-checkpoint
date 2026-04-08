---
sidebar_position: 1
---

# Command Reference

This page explains what each command does, when to use it, and what the parameters mean.

## Backup commands

`db-ops:enqueue {operation?} {--argument=}`

What it does:

- queues any supported operation
- asks for the operation interactively if you do not pass one

When to use it:

- use it when you need something more specific than `db-ops:enqueue-backup`
- use it for restore, PITR, and any operation that needs an argument

Rule for `--argument`:

- required for operations like `logical_restore_file` and `pitr_restore`
- not needed for operations like `logical_backup`

Parameters:

- `operation`
  The operation name.
  Example values:
  - `logical_backup`
  - `logical_restore_latest`
  - `logical_restore_file`
  - `pitr_restore`
- `--argument`
  Extra input for operations that need one.
  Example values:
  - `nightly-backup.sql`
  - `2026-03-11 11:30:00`

Examples:

```bash
php artisan db-ops:enqueue logical_backup
php artisan db-ops:enqueue logical_restore_file --argument="nightly-backup.sql"
php artisan db-ops:enqueue pitr_restore --argument="2026-03-11 11:30:00"
```

`db-ops:enqueue-backup`

What it does:

- queues a logical backup

When to use it:

- use it when you just want to run a normal backup
- this is the best first command for new users

Example:

```bash
php artisan db-ops:enqueue-backup
```

`db-ops:enqueue-drill`

What it does:

- queues a backup drill

When to use it:

- use it when backups are already working and you want restore evidence

Example:

```bash
php artisan db-ops:enqueue-drill
```

## Status and health commands

`db-ops:status {--limit=10} {--summary} {--format=table} {--agent}`

What it does:

- shows recent runs
- or shows a simple summary if you pass `--summary`

When to use it:

- use it right after queueing a job
- use `--summary` when you want the quickest health snapshot

Parameters:

- `--limit`
  Number of runs to show.
  Example values:
  - `10`
  - `25`
- `--summary`
  Shows summary output instead of individual runs.
- `--format`
  Output format.
  Example values:
  - `table`
  - `json`
- `--agent`
  Emits compact JSON for automation or AI agents

Examples:

```bash
php artisan db-ops:status
php artisan db-ops:status --limit=25
php artisan db-ops:status --summary
php artisan db-ops:status --format=json
```

`db-ops:doctor {--format=table} {--agent}`

What it does:

- checks package health
- validates config
- helps explain setup failures

When to use it:

- use it when the package fails during boot, composer, or command execution

Parameters:

- `--format`
  Example values:
  - `table`
  - `json`
- `--agent`
  Compact machine-friendly output

Examples:

```bash
php artisan db-ops:doctor
php artisan db-ops:doctor --format=json
```

`db-ops:report {--limit=10} {--format=table} {--agent}`

What it does:

- shows a fuller operational report
- includes recent runs, summary, verification, and health

When to use it:

- use it when `status` is not enough and you want a broader view

Parameters:

- `--limit`
  Example values:
  - `10`
  - `50`
- `--format`
  Example values:
  - `table`
  - `json`
- `--agent`
  Compact machine-friendly output

Examples:

```bash
php artisan db-ops:report --limit=10
php artisan db-ops:report --limit=10 --format=json
```

## Drill and PITR commands

`db-ops:record-drill`

What it does:

- stores the result of a drill run

When to use it:

- use it when a drill happened outside the package and you want to save the result

Required parameters:

- `--run-uuid`
  Example value:
  - `00000000-0000-0000-0000-000000000000`
- `--overall-result`
  Example values:
  - `pass`
  - `fail`
- `--executed-at`
  Example values:
  - `2026-03-11T10:30:00+00:00`
  - `2026-03-11 10:30:00`

Optional parameters:

- `--executed-by`
  Example values:
  - `ops-bot`
  - `aditya`
- `--marker-uuid`
- `--marker-email`
- `--marker-count`
- `--marker-result`
- `--rto-target-seconds`
- `--rto-actual-seconds`
- `--rto-result`
- `--rpo-target-seconds`
- `--rpo-actual-seconds`
- `--rpo-result`

Example:

```bash
php artisan db-ops:record-drill \
  --run-uuid="00000000-0000-0000-0000-000000000000" \
  --overall-result=pass \
  --executed-by="ops-bot" \
  --executed-at="2026-03-11T10:30:00+00:00"
```

`db-ops:pitr-readiness {target?} {--format=table} {--agent}`

What it does:

- checks whether point-in-time restore is ready

When to use it:

- use it before relying on PITR in a real incident

Parameters:

- `target`
  Optional target timestamp.
  Example values:
  - `2026-03-11 11:30:00`
  - `2026-03-11T11:30:00+00:00`
- `--format`
  Example values:
  - `table`
  - `json`
- `--agent`
  Compact machine-friendly output

Examples:

```bash
php artisan db-ops:pitr-readiness
php artisan db-ops:pitr-readiness "2026-03-11 11:30:00"
php artisan db-ops:pitr-readiness "2026-03-11 11:30:00" --format=json
```

## Maintenance commands

`db-ops:health-check`

What it does:

- marks stale running jobs as failed when health rules say they are no longer active

When to use it:

- usually scheduled automatically
- run it manually when you suspect stale running jobs

Example:

```bash
php artisan db-ops:health-check
```

`db-ops:recover-orphans`

What it does:

- tries to recover stale queued work

When to use it:

- usually scheduled automatically
- run it manually when jobs look stuck or abandoned

Example:

```bash
php artisan db-ops:recover-orphans
```

`db-ops:prune`

What it does:

- deletes old command-run and backup-drill records

When to use it:

- usually scheduled automatically
- run it manually when you want to clear old history

Example:

```bash
php artisan db-ops:prune
```

`db-ops:retention-policy {--format=table} {--limit=100} {--dry-run} {--apply}`

What it does:

- shows what retention would delete
- or deletes it if you pass `--apply`

When to use it:

- use `--dry-run` to preview
- use `--apply` only when you are ready to delete

Parameters:

- `--format`
  Example values:
  - `table`
  - `json`
- `--limit`
  Example values:
  - `100`
  - `500`
- `--dry-run`
  Preview only
- `--apply`
  Apply deletion now

Examples:

```bash
php artisan db-ops:retention-policy --dry-run
php artisan db-ops:retention-policy --apply --format=json
```

## Catalog and replication commands

`db-ops:catalog-export {--format=json} {--driver=} {--repository=} {--stanza=} {--window=} {--limit=100}`

What it does:

- exports backup catalog data

When to use it:

- use it for automation, reporting, or external audit workflows

Parameters:

- `--format`
  Example values:
  - `json`
  - `csv`
- `--driver`
  Example values:
  - `pgbackrest`
  - `pgdump`
  - `mysql`
  - `none`
- `--repository`
  Example values:
  - `1`
  - `none`
- `--stanza`
  Example values:
  - `main`
  - `none`
- `--window`
  Hours to look back.
  Example values:
  - `24`
  - `72`
- `--limit`
  Example values:
  - `100`
  - `500`

Examples:

```bash
php artisan db-ops:catalog-export --format=json
php artisan db-ops:catalog-export --format=csv --driver=pgbackrest --repository=1 --stanza=main --window=24 --limit=50
```

`db-ops:replicate {source?} {destination?} {--source=} {--destination=} {--apply} {--force-overwrite} {--critical-table=*}`

What it does:

- queues replication work
- defaults to dry-run mode

When to use it:

- use dry-run first to inspect what would happen
- use `--apply` only when you really intend to change the destination

Endpoint input formats:

- `profile:<id>`
- DSN such as `pgsql://user:pass@host/database`
- key/value pairs when your workflow uses that format

## Restore operations explained

`logical_restore_latest`

- restores the newest available backup
- use this when you want the latest safe restore point

Example:

```bash
php artisan db-ops:enqueue logical_restore_latest
```

`logical_restore_file`

- restores one specific backup file
- use this when you know exactly which file you want

Example:

```bash
php artisan db-ops:enqueue logical_restore_file --argument="nightly-backup.sql"
```

`pitr_restore`

- restores to a target time
- use this only when your driver and environment are already prepared for PITR

Example:

```bash
php artisan db-ops:enqueue pitr_restore --argument="2026-03-11 11:30:00"
```

Parameters:

- `source`
  Source endpoint.
  Example values:
  - `profile:pg-source`
  - `pgsql://user:pass@source.internal/app`
- `destination`
  Destination endpoint.
  Example values:
  - `profile:pg-destination`
  - `pgsql://user:pass@dest.internal/app`
- `--source`
  Optional source override
- `--destination`
  Optional destination override
- `--apply`
  Runs apply mode instead of dry-run
- `--force-overwrite`
  Requests overwrite behavior in apply mode
- `--critical-table`
  Repeatable list of sensitive tables.
  Example values:
  - `users`
  - `orders`

Examples:

```bash
php artisan db-ops:replicate profile:pg-source profile:pg-destination
php artisan db-ops:replicate --source=profile:pg-source --destination=profile:pg-destination --apply --force-overwrite --critical-table=users --critical-table=orders
```
