---
sidebar_position: 2
---

# Quickstart

This is the simplest path for a first working setup.

## 1. Publish the package files

```bash
php artisan vendor:publish --tag="laravel-checkpoint-config"
php artisan vendor:publish --tag="laravel-checkpoint-migrations"
php artisan migrate
```

## 2. Add the smallest useful config

Start with the `shell` driver.

```env
DB_OPS_DRIVER=shell
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_CMD_TIMEOUT=3600

DB_OPS_CMD_LOGICAL_BACKUP="/usr/local/bin/checkpoint-backup"
```

## 3. Start a queue worker

```bash
php artisan queue:work --queue=db-ops --timeout=3600
```

## 4. Queue your first backup

```bash
php artisan db-ops:enqueue-backup
```

## 5. Check that it worked

```bash
php artisan db-ops:status --limit=10
php artisan db-ops:status --summary
php artisan db-ops:doctor
```

## What success looks like

- the backup job appears in `db-ops:status`
- the summary page shows no obvious failure
- `db-ops:doctor` does not report config problems

## Do not do this first

Do not start with:

- restore
- replication
- PITR
- drills
- deep safety tuning

Get one backup working first.
