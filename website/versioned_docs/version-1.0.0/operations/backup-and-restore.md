---
sidebar_position: 1
---

# Backup And Restore

## Queue a backup

```bash
php artisan checkpoint:backup
```

Run synchronously:

```bash
php artisan checkpoint:backup --sync
```

## Restore

Restore operations are destructive. Use `--force` to skip confirmation prompts.

Restore latest tracked artifact:

```bash
php artisan checkpoint:restore
```

Restore a specific file:

```bash
php artisan checkpoint:restore --file="backup-file-or-label"
```

Restore to a point in time:

```bash
php artisan checkpoint:restore --pitr="2026-03-11 11:30:00"
```

Dry-run PITR readiness:

```bash
php artisan checkpoint:restore --pitr-dry-run
```

## Verification

Post-restore verification level:

```bash
php artisan checkpoint:restore --verification=strict
```

Verify an existing restore without re-executing:

```bash
php artisan checkpoint:restore --verify-only
```

## Observe the run

```bash
php artisan checkpoint:status --limit=10
php artisan checkpoint:status --summary
```

## Scheduled flows

The package can schedule:

- Logical backup
- Health checks
- Orphan recovery
- Pruning
- Backup drills
