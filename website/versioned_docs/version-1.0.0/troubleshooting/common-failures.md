---
sidebar_position: 1
---

# Common Failures

## Config validator fails during Composer or package discovery

This package validates config at boot. Inconsistent queue, timeout, or safety settings can fail during:

- `composer update`
- `package:discover`
- Application boot

Check:

- `CP_DRIVER` is set to a valid driver (`postgres`, `mysql`, or `fake`)
- Selected driver exists in `config/checkpoint.php` under `drivers`
- Driver timeout does not exceed `checkpoint.queue.timeout`
- Queue retry and uniqueness windows are coherent
- Restore and scheduler posture is valid for the current environment

## Queue worker kills long-running commands

Symptoms:

- Jobs stop mid-backup or mid-restore
- Status shows failed runs without driver-level completion

Check:

- Worker `--timeout`
- `checkpoint.queue.timeout`
- Driver `command_timeout_seconds`
- `checkpoint.queue.retry_after`

## Scheduler coordination breaks in production

Symptoms:

- Duplicate scheduled runs
- Overlap guards fail across hosts

Check:

- Shared cache backend is configured
- `checkpoint.queue.lock_store` is production-safe
- `checkpoint.schedule.without_overlapping` and `checkpoint.schedule.on_one_server` match your deployment model

## PITR readiness reports `not_ready`

Common causes:

- No last-known-good baseline
- Missing baseline artifact
- MySQL binlog chain not configured
- Configured binlog files missing
- Target timestamp is in the future or before the baseline

Use:

```bash
php artisan checkpoint:restore --pitr-dry-run
```

to inspect the failing checks.
