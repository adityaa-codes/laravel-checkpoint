---
sidebar_position: 1
---

# Common Failures

## Config validator fails during Composer or package discovery

This package validates config during app boot. That means bad config can fail during:

- `composer update`
- `package:discover`
- application boot

Check:

- selected driver exists in `checkpoint.drivers`
- driver timeout does not exceed `checkpoint.queue.timeout`
- queue retry and uniqueness windows are coherent
- restore and scheduler posture is valid for the current environment

Most common example:

```env
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_CMD_TIMEOUT=7200
```

Fix it by making the command timeout less than or equal to the queue timeout.

## Queue worker kills long-running operations

Symptoms:

- jobs stop mid-backup or mid-restore
- status shows failed runs without driver-level completion

Check:

- worker `--timeout`
- `checkpoint.queue.timeout`
- driver `command_timeout_seconds`
- `checkpoint.queue.retry_after`

## Scheduler coordination breaks in production

Symptoms:

- duplicate scheduled runs
- overlap guards fail across hosts

Check:

- shared cache backend is configured
- `checkpoint.queue.lock_store` is production-safe
- `checkpoint.schedule.without_overlapping` and `checkpoint.schedule.on_one_server` match your deployment model

## PITR readiness reports `not_ready`

Common causes:

- no last-known-good baseline
- missing baseline artifact
- MySQL binlog chain not configured
- configured binlog files missing
- target timestamp is in the future or before the baseline

Use:

```bash
php artisan checkpoint:pitr-readiness --format=json
```

to inspect the failing checks directly.

## Drill fails but backup succeeds

Symptoms:

- `checkpoint:status` shows backup runs as `succeeded`
- drill runs show `failed` or partial completion

Common causes:

- restore command template is missing or misconfigured
- restore target environment is not in `allowed_environments`
- blast radius analysis blocks the drill restore
- disk space insufficient for restore artifact

Check:

```bash
php artisan checkpoint:doctor
php artisan checkpoint:report --limit=10
```

## Gate blocks restore

Symptoms:

- restore job fails before any command executes
- failure reason references "safety gate" or "evidence gate"

Check:

- `checkpoint:doctor` output for gate verdicts
- `DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS` includes your current environment
- `DB_OPS_RESTORE_REQUIRE_CONFIRMATION` is set appropriately for your profile
- blast radius score exceeds configured thresholds

See [Restore Guardrails](../safety/restore-guardrails.md) for gate configuration.

## Verification check fails

Symptoms:

- `checkpoint:doctor` shows verification or evidence checks as failed
- report output shows missing drill evidence

Common causes:

- no drills have been run in the current retention window
- last drill failed and evidence is stale
- required verification markers are not configured

Fix:

```bash
php artisan checkpoint:enqueue-drill
```

Run a drill and check the results to restore verification health.
