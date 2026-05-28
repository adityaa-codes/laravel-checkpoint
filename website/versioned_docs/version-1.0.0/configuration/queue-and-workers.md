---
sidebar_position: 2
---

# Queue And Workers

Laravel Checkpoint is queue-first. Most failures that look like driver bugs are actually worker-budget or lock-store mismatches.

## Queue settings

The queue config block exposes:

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

The config validator enforces timing relationships:

- Each driver timeout must be less than or equal to `checkpoint.queue.timeout`
- Queue uniqueness and retry windows must remain internally consistent
- Heartbeat settings must fit inside the worker budget

Keep this relationship:

```text
driver timeout <= queue timeout < queue retry_after <= unique_for
```

## Worker alignment

Your worker process must match the package config:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

Set `CP_QUEUE_NAME` to change the default queue (`checkpoint`).

If the worker timeout is shorter than the package timeout, the worker will kill jobs before the driver finishes.

## Production lock-store guidance

For non-local environments:

- Use a shared cache backend such as Redis
- Avoid `array` or `file` lock stores
- Keep scheduler overlap and `onOneServer()` coordination on a shared backend

This matters for:

- Exclusive runs
- Duplicate job suppression
- Scheduled backup and drill overlap prevention
- Orphan recovery in multi-node environments
