---
sidebar_position: 2
---

# Queue And Timeouts

Timeout mismatches are the most common setup problem. A mid-restore timeout can leave your database in an inconsistent state.

## Simple rule

Your command timeout must not be longer than your queue timeout.

The command timeout is set per-driver in `config/checkpoint.php`:

```php
'drivers' => [
    'postgres' => [
        'command_timeout_seconds' => 7200, // max runtime for pg_dump/pg_restore
    ],
    'mysql' => [
        'command_timeout_seconds' => 7200, // max runtime for mysqldump/mysql
    ],
],
```

The queue timeout is also in `config/checkpoint.php`:

```php
'queue' => [
    'timeout' => 3600, // default Laravel queue job timeout
    'unique_for' => 3660,
],
```

## Worker configuration

Your worker timeout must match the config:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

If `CP_QUEUE_NAME` is set, use that queue name instead:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

## Good setup

```php
// config/checkpoint.php
'queue' => [
    'timeout' => 3600,
    'unique_for' => 3660,
],
// per-driver
'command_timeout_seconds' => 3600,
```

Worker:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

## Bad setup (will fail boot validation)

```php
'queue' => [
    'timeout' => 3600,
],
'command_timeout_seconds' => 7200, // exceeds queue timeout
```

The package validates this at boot. Composer operations and app boot can fail if the relationship is wrong.

## Verify your timeout chain

```bash
php artisan checkpoint:status --health
```

This checks timeout coherence and reports problems.

## Production note

If you run multiple app servers, use a shared cache backend like Redis for lock coordination.
