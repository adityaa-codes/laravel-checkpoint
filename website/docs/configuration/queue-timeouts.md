---
sidebar_position: 2
---

# Queue And Timeouts

Most setup problems come from timeout mismatches.

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

## Production note

If you run multiple app servers, use a shared cache backend for locks and scheduler coordination. Redis is the usual choice.
