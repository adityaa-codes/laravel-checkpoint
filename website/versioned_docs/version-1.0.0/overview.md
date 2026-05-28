---
sidebar_position: 1
slug: /overview
---

# Laravel Checkpoint

Laravel Checkpoint is a Laravel package for queue-driven backup, restore, drill, reporting, and operational safety workflows.

## Drivers

The package ships three drivers:

- `postgres` — PostgreSQL backup and restore
- `mysql` — MySQL backup and restore
- `fake` — testing driver that simulates operations without touching a real database

## Commands

```
checkpoint:backup           Queue a logical backup (async by default)
checkpoint:restore          Restore from file or PITR
checkpoint:drill            Queue a recovery drill
checkpoint:replicate        Replication sync (dry-run by default)
checkpoint:sweep            Mark timed-out runs as failed, re-dispatch stale orphans
checkpoint:status           Status, health checks, summary, and reporting
checkpoint:catalog:export   Export backup catalog
checkpoint:prune            Clean old records
checkpoint:install          Guided setup
checkpoint:make-driver      Scaffold a custom driver
checkpoint:migrate-from-spatie  Migrate from spatie/laravel-backup
checkpoint:config:show      Show resolved config
```

Operations are async by default. Use `--sync` for inline execution.

## Queue

The default queue name is `checkpoint`. Workers should listen on that queue:

```bash
php artisan queue:work --queue=checkpoint
```

## Scheduling

The package registers scheduled commands for backup, drill, health check, orphan recovery, and pruning when the matching config flags are enabled.
