---
sidebar_position: 2
---

# Check Health And Status

These are the 4 command views you will use most often after setup.

## `db-ops:status`

```bash
php artisan db-ops:status --limit=10
```

Use it when:

- you want to see recent runs
- you want to know whether a backup is still running

## `db-ops:status --summary`

```bash
php artisan db-ops:status --summary
```

Use it when:

- you want a quick operational summary
- you do not need full per-run details
- you want the latest failure reason and immediate next action without opening logs

## `db-ops:doctor`

```bash
php artisan db-ops:doctor
php artisan db-ops:doctor --format=json
```

Use it when:

- setup is failing
- you want config validation feedback
- you want machine-readable health output

## `db-ops:report`

```bash
php artisan db-ops:report --limit=10
php artisan db-ops:report --limit=10 --format=json
```

Use it when:

- you want a fuller operational report
- you want recent runs plus summary plus health details
