---
sidebar_position: 2
---

# Check Health And Status

These are the commands you will use most often after setup.

## `checkpoint:status`

```bash
php artisan checkpoint:status --limit=10
```

Shows recent runs. Use this to see whether a backup or drill is still running.

## `checkpoint:status --summary`

```bash
php artisan checkpoint:status --summary
```

Quick operational summary. The latest failure reason and next action, without opening logs.

## `checkpoint:status --health`

```bash
php artisan checkpoint:status --health
php artisan checkpoint:status --health --format=json
```

Replaces the old `checkpoint:doctor`. Validates config, checks binary availability, reports blocker/warning/info issues.

## `checkpoint:status --full`

```bash
php artisan checkpoint:status --full
php artisan checkpoint:status --full --format=json
```

Replaces the old `checkpoint:report`. Full operational report: recent runs, summary, verification, and health details.

## Polling

Wait for running jobs to finish:

```bash
php artisan checkpoint:status --watch=5 --watch-timeout=300
```

Polls every 5 seconds until all jobs complete, up to 300 seconds.

## JSON output

All status views support `--format=json`:

```bash
php artisan checkpoint:status --format=json
php artisan checkpoint:status --health --format=json
php artisan checkpoint:status --full --format=json
```
