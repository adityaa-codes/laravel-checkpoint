---
sidebar_position: 1
---

# Common Failures

## Config validator fails during Composer or package discovery

This package validates config at boot. Any inconsistent queue, timeout, or safety settings can fail during:

- `composer update`
- `package:discover`
- application boot

Check:

- selected driver exists in `checkpoint.drivers`
- driver timeout does not exceed `checkpoint.queue.timeout`
- queue retry and uniqueness windows are coherent
- restore and scheduler posture is valid for the current environment

## Queue worker kills long-running commands

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
php artisan db-ops:pitr-readiness --format=json
```

to inspect the failing checks directly.
