---
sidebar_position: 1
---

# Take Your First Backup

This is the fastest way to prove the backup layer of the reliability stack works.

## Step 1: queue a backup

```bash
php artisan checkpoint:enqueue-backup
```

What it does:

- queues a `logical_backup` job
- stores a command run record
- returns the queued run id

## Step 2: check recent runs

```bash
php artisan checkpoint:status --limit=10
```

What it does:

- shows recent reliability operation runs
- shows status such as `pending`, `running`, `succeeded`, or `failed`

## Step 3: check the summary view

```bash
php artisan checkpoint:status --summary
```

What it does:

- shows a simple operator summary
- tells you if there are pending, running, or failed runs

## If the backup fails

Run:

```bash
php artisan checkpoint:doctor
php artisan checkpoint:report --limit=10
```

That usually tells you whether the issue is config, queue setup, or the backup command itself.

## What next: run a drill

Backups are the first layer. The next step is proving you can restore — run a drill:

```bash
php artisan checkpoint:enqueue-drill
```

[Run A Drill](./run-a-drill.md) covers the full verification workflow.
