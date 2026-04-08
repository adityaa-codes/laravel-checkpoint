---
sidebar_position: 3
---

# Restore A Backup

Do this only after you already have working backups.

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
php artisan db-ops:enqueue logical_restore_latest
```

Use this when:

- you want the newest backup
- you do not need to pick a file manually

## Restore a specific backup

```bash
php artisan db-ops:enqueue logical_restore_file --argument="nightly-backup.sql"
```

Use this when:

- you know the file name
- you want an older backup instead of the latest one

Example values for `--argument`:

- `nightly-backup.sql`
- `logical-export-2026-03-11.dump`

## Before you try restore

Make sure you have set:

- `DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS`
- `DB_OPS_RESTORE_ALLOWED_DATABASES`
- `DB_OPS_RESTORE_REQUIRE_CONFIRMATION`

If your environment requires verified backups, make sure that is working too.

## After you queue the restore

Check progress with:

```bash
php artisan db-ops:status --limit=10
php artisan db-ops:report --limit=10
```
