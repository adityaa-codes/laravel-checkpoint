---
sidebar_position: 1
---

# Backup And Restore

The package exposes queue-first backup and restore workflows through `checkpoint:enqueue`, `checkpoint:enqueue-backup`, and driver-specific operations.

## Queue a logical backup

```bash
php artisan checkpoint:enqueue-backup
php artisan checkpoint:enqueue logical_backup
```

## Queue a restore

Restore operations are destructive and exclusive in the operation catalog.

Latest tracked artifact:

```bash
php artisan checkpoint:enqueue logical_restore_latest
```

Specific artifact:

```bash
php artisan checkpoint:enqueue logical_restore_file --argument="backup-file-or-label"
```

## Observe the run

```bash
php artisan checkpoint:status --limit=10
php artisan checkpoint:status --summary
php artisan checkpoint:report --limit=10
```

## Scheduled flows

The package can schedule:

- logical backup
- health checks
- orphan recovery
- pruning

Backup drills are also schedulable when enabled.
