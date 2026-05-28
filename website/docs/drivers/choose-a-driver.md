---
sidebar_position: 1
---

# Choose A Driver

Pick the driver that matches your database engine.

## Available drivers

| Driver | Backup | Restore | PITR | Drills | Replication |
|---|---|---|---|---|---|
| `postgres` | Yes | Yes | Yes (WAL) | Yes | Yes |
| `mysql` | Yes | Yes | Yes (binlog) | Yes | Yes |
| `fake` | Test only | Test only | — | — | — |

## `postgres`

Uses `pg_dump` and `pg_restore` for logical operations. Supports physical base backups via `pg_basebackup` and WAL-based PITR.

Binary paths come from Laravel's `config/database.php` connections under the `dump.dump_binary_path` key, same convention as spatie/laravel-backup.

Set `CP_DRIVER=postgres` in your `.env`.

## `mysql`

Uses `mysqldump` for logical backups and `mysql` for restore. Supports binlog-based point-in-time recovery.

Binary paths come from Laravel's `config/database.php` connections under the `dump.dump_binary_path` key.

Set `CP_DRIVER=mysql` in your `.env`.

## `fake`

Returns controlled output without touching any real binary. Use for testing and CI.

Set `CP_DRIVER=fake` in your `.env` (or in your test environment config).
