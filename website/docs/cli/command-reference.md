---
sidebar_position: 1
---

# Command Reference

This page explains what each command does, when to use it, and what the parameters mean.

## Command taxonomy (recommended)

- `db-ops:do:*` → operator workflow commands
- `db-ops:check:*` → validation, diagnostics, and readiness checks
- `db-ops:admin:*` → governance and maintenance operations

Primary golden path:

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

## Installation command

`db-ops:install {--preset=} {--skip-publish} {--skip-migrate} {--skip-doctor} {--smoke-backup} {--write-env} {--force}`

What it does:

- runs guided package installation
- applies an opinionated preset
- can publish config/migrations, run migrate, and run doctor checks
- can optionally run a one-backup smoke test (`--smoke-backup`) and fail install if smoke fails

Common presets:

- `minimal`
- `postgres-prod` (sets `DB_OPS_DRIVER=postgres`)
- `mysql-prod`

Examples:

```bash
php artisan db-ops:install --preset=minimal
php artisan db-ops:install --preset=postgres-prod --write-env
```

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

Non-interactive note:

- in non-interactive runs (for example CI with `--no-interaction`), missing `operation` or required `--argument` fails immediately instead of prompting

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

`db-ops:status {--limit=10} {--summary} {--brief} {--format=table} {--agent} {--policy-profile=}`

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
- `--brief`
  Shows incident-triage output (cause + action) in a compact format.
- `--format`
  Output format.
  Example values:
  - `table`
  - `json`
- `--agent`
  Emits compact JSON for automation or AI agents
- `--policy-profile`
  Overrides gate policy profile selection for CI/automation runs.

Examples:

```bash
php artisan db-ops:status
php artisan db-ops:status --limit=25
php artisan db-ops:status --summary
php artisan db-ops:status --brief
php artisan db-ops:status --format=json
php artisan db-ops:status --agent
```

`db-ops:doctor {--brief} {--format=table} {--agent} {--policy-profile=}`

What it does:

- checks package health
- validates config
- helps explain setup failures
- labels checks by severity (`blocker`, `warning`, `info`)

When to use it:

- use it when the package fails during boot, composer, or command execution

Parameters:

- `--format`
  Example values:
  - `table`
  - `json`
- `--brief`
  Shows top issues and immediate next action.
- `--agent`
  Compact machine-friendly output
- `--policy-profile`
  Overrides gate policy profile selection for CI/automation runs.
- default table output prioritizes P0/P1 checks and suppresses passing checks unless you run with `-v`

Examples:

```bash
php artisan db-ops:doctor
php artisan db-ops:doctor --brief
php artisan db-ops:doctor --format=json
php artisan db-ops:doctor --agent
```

`db-ops:report {--limit=10} {--brief} {--format=table} {--agent} {--policy-profile=}`

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
- `--brief`
  Shows incident-first summary with last failed run, cause, and action.
- `--agent`
  Compact machine-friendly output
- `--policy-profile`
  Overrides gate policy profile selection for CI/automation runs.
- default table output prioritizes P0/P1 checks and suppresses passing checks unless you run with `-v`

Examples:

```bash
php artisan db-ops:report --limit=10
php artisan db-ops:report --limit=10 --brief
php artisan db-ops:report --limit=10 --format=json
php artisan db-ops:report --limit=10 --agent
```

Agent-friendly output tips:

- use `--agent` when you want concise output for scripts and agent loops
- agent responses include `schema_version` for contract-safe parsing
- use `--format=json` when you need full structured fields for downstream parsing
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

Replication semantics (current behavior):

- execution supports only local/configured endpoint semantics
- remote/cross-host source/destination intent is rejected at runtime
- apply mode re-checks governance preflight at execution time
- in non-interactive runs (`--no-interaction`), missing source/destination fails immediately instead of prompting

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
