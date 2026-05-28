---
sidebar_position: 1
---

# Take Your First Backup

## Before you start

A queue worker must be running to process the backup job. If you do not have one, skip to the `--sync` option below.

Start a worker:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

## Option 1: Queue the backup (recommended)

```bash
php artisan checkpoint:backup
```

This pushes a backup job to the queue. The worker picks it up and runs it. You get a run ID back.

## Option 2: Run inline

```bash
php artisan checkpoint:backup --sync
```

Runs the backup in the current process. Blocks until complete. Use this when you do not have a queue worker or you are testing.

## Check that it worked

```bash
php artisan checkpoint:status
php artisan checkpoint:status --summary
```

The status command shows recent runs. Each run has a status: `pending`, `running`, `succeeded`, or `failed`.

## If the backup fails

```bash
php artisan checkpoint:status --health
php artisan checkpoint:status --full
```

This tells you whether the issue is config, missing binaries, queue setup, or something else.

## Next step: run a drill

Backups are the first layer. Prove you can restore:

```bash
php artisan checkpoint:drill
```

[Run A Drill](./run-a-drill.md) covers the full verification workflow.
