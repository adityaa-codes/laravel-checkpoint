# Laravel Checkpoint — AI Agent Code Guidelines

Laravel Checkpoint is a database reliability layer for Laravel. It handles backup, restore, point-in-time recovery, replication, and recovery drills. All operations are queued by default and run on the `checkpoint` queue.

## Conventions

- All env vars use `CP_*` prefix (CP_DRIVER, CP_QUEUE_NAME, CP_BACKUP_ARCHIVE_PASSWORD, etc.)
- Queue name defaults to `checkpoint`, configurable via CP_QUEUE_NAME
- Queue worker: `php artisan queue:work --queue=checkpoint`
- Restore requires confirmation in non-local environments
- All shell commands use Symfony Process array args — no string concatenation
- No facades in services/actions — constructor DI only
- No `@` suppression, no swallowed exceptions
- Config lives in a single file: `config/checkpoint.php`

## Commands

| Command | Purpose |
|---|---|
| `checkpoint:backup` | Queue a logical backup (async). Use `--sync` to run immediately. |
| `checkpoint:restore` | Restore from file or point-in-time. Requires confirmation outside local. |
| `checkpoint:drill` | Queue a recovery drill against the latest backup. |
| `checkpoint:replicate` | Production-to-staging sync. Dry-run by default, `--apply` to execute. |
| `checkpoint:status` | Recent runs. `--health` for diagnostics, `--full` for full report. |
| `checkpoint:sweep` | Mark timed-out runs as failed. Re-dispatch stale orphans. |
| `checkpoint:prune` | Clean old command run records per retention policy. |
| `checkpoint:install` | Guided setup wizard. Auto-detects database driver. |
| `checkpoint:migrate-from-spatie` | Interactive migration guide from spatie/laravel-backup. |
| `checkpoint:catalog:export` | Export command run catalog as JSON or CSV. |
| `checkpoint:config:show` | Show resolved configuration values. |
| `checkpoint:make-driver` | Scaffold a custom backup driver. |

## Testing

```bash
vendor/bin/pint            # auto-fix style (Laravel Pint)
vendor/bin/phpstan analyse # static analysis (level max)
vendor/bin/pest            # test suite (Pest only)
```

Use `InteractsWithCheckpoint` trait and `FakeDriver` for testing backup workflows.
