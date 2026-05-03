---
sidebar_position: 3
---

# Restore A Backup

Restore is where reliability proves itself. Backups are easy — restoring safely under pressure is what matters.

Do this only after you already have working backups. Run a drill before your first real restore (see [Run A Drill](./run-a-drill.md)).

## Two restore styles

`logical_restore_latest`

- use this when you want the latest available backup
- best when your restore process usually targets the newest successful artifact

`logical_restore_file`

- use this when you want one specific backup file
- best when you know the exact file or label you want to restore

Simple difference:

- `logical_restore_latest` picks the newest backup for you
- `logical_restore_file` lets you choose the exact backup yourself

## Restore the latest backup

```bash
php artisan checkpoint:enqueue logical_restore_latest
```

Use this when:

- you want the newest backup
- you do not need to pick a file manually

## Restore a specific backup

```bash
php artisan checkpoint:enqueue logical_restore_file --argument="nightly-backup.sql"
```

Use this when:

- you know the file name
- you want an older backup instead of the latest one

Example values for `--argument`:

- `nightly-backup.sql`
- `logical-export-2026-03-11.dump`

## Before you try restore

Make sure you have set:

- `CP_RESTORE_ALLOWED_ENVIRONMENTS`
- `CP_RESTORE_ALLOWED_DATABASES`
- `CP_RESTORE_REQUIRE_CONFIRMATION`

If your environment requires verified backups, make sure that is working too.

See [Restore Guardrails](../safety/restore-guardrails.md) for the full safety configuration.

## After you queue the restore

Check progress with:

```bash
php artisan checkpoint:status --limit=10
php artisan checkpoint:report --limit=10
```
