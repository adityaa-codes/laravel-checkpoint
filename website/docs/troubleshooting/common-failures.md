---
sidebar_position: 1
---

# Common Failures

## Config validation fails during Composer or package discovery

Bad config fails early. Checkpoint validates config at boot, so failures happen during:

- `composer update`
- `package:discover`
- application boot

Check:

- `CP_DRIVER` is set to `postgres` or `mysql`
- driver `command_timeout_seconds` does not exceed `queue.timeout`
- queue timeout and uniqueness windows are coherent

Most common fix: reduce `command_timeout_seconds` to match or stay below `queue.timeout`.

## Queue worker kills long-running operations

Symptoms: jobs stop mid-backup or mid-restore. Status shows failed runs with no driver-level completion.

Check:

- worker `--timeout` matches `queue.timeout`
- driver `command_timeout_seconds` is not shorter than expected operation time
- `queue.unique_for` exceeds `queue.timeout`

## Scheduler coordination breaks in production

Symptoms: duplicate scheduled runs. Overlap guards fail across hosts.

Check:

- shared cache backend is configured (Redis)
- `queue.lock_store` is production-safe

## PITR readiness reports not ready

```bash
php artisan checkpoint:restore --pitr-dry-run
```

Common causes:

- no last-known-good baseline
- missing baseline artifact
- MySQL binlog chain not configured
- configured binlog files missing
- target timestamp is in the future or before the baseline

## Drill fails but backup succeeds

Symptoms: `checkpoint:status` shows backup runs as `succeeded` but drills show `failed`.

Common causes:

- restore command is misconfigured for your driver
- restore target environment is not in `allowed_environments`
- blast radius analysis blocks the drill restore
- disk space insufficient for restore artifact

Check:

```bash
php artisan checkpoint:status --health
php artisan checkpoint:status --full
```

## Gate blocks restore

Symptoms: restore job fails before any command executes. Failure reason mentions safety gate or evidence gate.

Check:

- `checkpoint:status --health` output for gate verdicts
- `CP_RESTORE_ALLOWED_ENVIRONMENTS` includes your current environment
- blast radius score exceeds configured thresholds
- `restore.require_verified_backup` is set and no verified backup exists

See [Restore Guardrails](../safety/restore-guardrails.md).

## Verification check fails

Symptoms: health checks show verification or evidence checks as failed. Report shows missing drill evidence.

Common causes:

- no drills have run in the current window
- last drill failed and evidence is stale
- required verification markers missing

Fix: run a drill.

```bash
php artisan checkpoint:drill
```
