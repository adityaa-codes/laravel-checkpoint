---
sidebar_position: 2
---

# Check Health And Status

These are the 4 command views you will use most often after setup.

A healthy status shows backups AND drill results AND gate verdicts — not just backup success. Health in Checkpoint means the full reliability chain is working.

## `checkpoint:status`

```bash
php artisan checkpoint:status --limit=10
```

Use it when:

- you want to see recent runs
- you want to know whether a backup is still running

## `checkpoint:status --summary`

```bash
php artisan checkpoint:status --summary
```

Use it when:

- you want a quick operational summary
- you do not need full per-run details
- you want the latest failure reason and immediate next action without opening logs

## `checkpoint:doctor`

```bash
php artisan checkpoint:doctor
php artisan checkpoint:doctor --format=json
```

Use it when:

- setup is failing
- you want config validation feedback
- you want machine-readable health output

## `checkpoint:report`

```bash
php artisan checkpoint:report --limit=10
php artisan checkpoint:report --limit=10 --format=json
```

Use it when:

- you want a fuller operational report
- you want recent runs plus summary plus health details
- you need drill success/failure rates alongside backup metrics
