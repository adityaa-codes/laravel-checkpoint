---
sidebar_position: 1
---

# Take Your First Backup

This is the fastest way to prove the package works.

## Step 1: queue a backup

```bash
php artisan db-ops:enqueue-backup
```

What it does:

- queues a `logical_backup` job
- stores a command run record
- returns the queued run id

## Step 2: check recent runs

```bash
php artisan db-ops:status --limit=10
```

What it does:

- shows recent checkpoint runs
- shows status such as `pending`, `running`, `succeeded`, or `failed`

## Step 3: check the summary view

```bash
php artisan db-ops:status --summary
```

What it does:

- shows a simple operator summary
- tells you if there are pending, running, or failed runs

## If the backup fails

Run:

```bash
php artisan db-ops:doctor
php artisan db-ops:report --limit=10
```

That usually tells you whether the issue is config, queue setup, or the backup command itself.
