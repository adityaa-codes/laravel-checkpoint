---
sidebar_position: 2
---

# Quickstart

The fastest path to a working setup.

## 1. Install

```bash
composer require adityaa-codes/laravel-checkpoint
php artisan checkpoint:install
```

The wizard auto-detects your database driver and sets `CP_DRIVER` in your `.env`.

## 2. Start a queue worker

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

The queue name must match `CP_QUEUE_NAME` (default is `checkpoint`, not `db-ops`).

## 3. Run your first backup

```bash
php artisan checkpoint:backup
```

If you don't have a queue worker running yet, use `--sync`:

```bash
php artisan checkpoint:backup --sync
```

## 4. Check that it worked

```bash
php artisan checkpoint:status
php artisan checkpoint:status --summary
php artisan checkpoint:status --health
```

## 5. Schedule regular backups

Add to `routes/console.php`:

```php
Schedule::command('checkpoint:backup')->cron('0 2 * * *');
Schedule::command('checkpoint:prune')->cron('0 3 * * 0');
Schedule::command('checkpoint:sweep')->cron('*/5 * * * *');
```

Then run the scheduler:

```bash
php artisan schedule:work
```

## 6. Prove your recovery path

Once backups work, run a drill:

```bash
php artisan checkpoint:drill
```

Read [Run A Drill](../common-tasks/run-a-drill.md) for the full workflow.

## What success looks like

- the backup job appears in `checkpoint:status`
- the summary shows no failures
- `checkpoint:status --health` passes all checks
