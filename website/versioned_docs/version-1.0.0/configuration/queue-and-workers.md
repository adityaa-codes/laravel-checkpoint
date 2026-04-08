---
sidebar_position: 2
---

# Queue And Workers

Laravel Checkpoint is queue-first. Most failures that look like driver bugs are actually worker-budget or lock-store mismatches.

## Queue settings

The queue config block currently exposes:

- `connection`
- `name`
- `max_attempts`
- `retry_after`
- `timeout`
- `orphan_threshold`
- `orphan_claim_timeout`
- `orphan_batch_size`
- `orphan_event_max_ids`
- `heartbeat_interval_seconds`
- `heartbeat_grace_seconds`
- `unique_for`
- `lock_store`

## Required invariants

The config validator enforces important timing relationships:

- each driver timeout must be less than or equal to `checkpoint.queue.timeout`
- queue uniqueness and retry windows must remain internally consistent
- heartbeat settings must fit inside the worker budget

In practice, keep this relationship:

```text
driver timeout <= queue timeout < queue retry_after <= unique_for
```

## Worker alignment

Your actual worker process still has to match the package config:

```bash
php artisan queue:work --queue=db-ops --timeout=3600
```

If the worker timeout is shorter than the package timeout, the worker will kill jobs before the driver finishes.

## Production lock-store guidance

For non-local environments:

- use a shared cache backend such as Redis
- avoid `array` or `file` lock stores
- keep scheduler overlap and `onOneServer()` coordination on a shared backend

This matters for:

- exclusive runs
- duplicate job suppression
- scheduled backup and drill overlap prevention
- orphan recovery in multi-node environments
