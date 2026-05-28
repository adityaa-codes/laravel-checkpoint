---
sidebar_position: 3
---

# Restore A Backup

Restore is where reliability proves itself. Do this after you have working backups. Run a drill first (see [Run A Drill](./run-a-drill.md)).

## Restore the latest backup

```bash
php artisan checkpoint:restore --sync
```

Without `--file`, the driver restores the newest backup it finds.

## Restore a specific backup file

```bash
php artisan checkpoint:restore --file="/path/to/backup.dump" --sync
```

Bring your own file path. The driver validates it exists before proceeding.

## Point-in-time recovery

```bash
php artisan checkpoint:restore --pitr="2026-03-11 11:30:00" --sync
```

PITR requires WAL archiving (Postgres) or binlog config (MySQL). Check readiness first:

```bash
php artisan checkpoint:restore --pitr-dry-run
```

## Verification

The default verification mode is `moderate`. For a deeper check after restore:

```bash
php artisan checkpoint:restore --sync --verification=full
```

Verify a previous restore without re-running it:

```bash
php artisan checkpoint:restore --verify-only
```

## Safety

Restore is blocked unless your current environment is in `CP_RESTORE_ALLOWED_ENVIRONMENTS` (defaults to `local,testing,staging`). You will be prompted to type the confirmation phrase (`RESTORE`) before execution. Pass `--force` to skip the prompt in automation contexts.

See [Restore Guardrails](../safety/restore-guardrails.md) for the full safety configuration.

## Check progress

```bash
php artisan checkpoint:status --limit=10
php artisan checkpoint:status --full
```
