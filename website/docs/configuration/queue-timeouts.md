---
sidebar_position: 2
---

# Queue And Timeouts

Most setup problems come from timeout mismatches. Correct timeout wiring prevents killed workers during critical reliability operations — a mid-restore timeout can leave your database in an inconsistent state.

## Simple rule

Your command timeout must never be longer than your queue timeout.

Good example:

```env
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_CMD_TIMEOUT=3600
```

## Worker example

Your queue worker should match the package config:

```bash
php artisan queue:work --queue=db-ops --timeout=3600
```

## Common mistake

Bad example:

```env
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_CMD_TIMEOUT=7200
```

That can make `composer update`, `package:discover`, or app boot fail because the package blocks unsafe settings.

## Reliability impact

Timeout mismatches during restore operations are especially dangerous:

- a restore killed mid-execution leaves a partial or corrupt database
- recovery drill timeouts produce false-negative results, undermining confidence
- PITR operations interrupted by timeout can break the WAL/binlog replay chain

Always verify your timeout chain with:

```bash
php artisan checkpoint:doctor
```

## Production note

If you run multiple app servers, use a shared cache backend for locks and scheduler coordination. Redis is the usual choice.
