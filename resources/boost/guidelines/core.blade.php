Laravel Checkpoint is a database reliability layer for Laravel. It provides queued backup, restore, point-in-time recovery, replication, and recovery drills. All operations run on the `checkpoint` queue by default.

## Conventions
- All env vars use `CP_*` prefix (CP_DRIVER, CP_QUEUE_NAME, CP_BACKUP_ARCHIVE_PASSWORD, etc.)
- Queue name defaults to `checkpoint`, configurable via CP_QUEUE_NAME
- No facades in services/actions — constructor DI. `config()`/`app()` only in commands and providers
- All shell commands use Symfony Process array args — never string concatenation
- No `@` suppression, no swallowed exceptions — always `report($e)` or `logger()->error(...)`
- Single config file: `config/checkpoint.php`

## Commands

| Command | Purpose |
|---|---|
| `checkpoint:backup` | Queue a logical backup. `--sync` to run immediately |
| `checkpoint:restore` | Restore from file or point-in-time |
| `checkpoint:drill` | Queue a recovery drill against the latest backup |
| `checkpoint:replicate` | Production-to-staging sync. Dry-run by default, `--apply` to execute |
| `checkpoint:status` | Recent runs. `--health` for diagnostics, `--full` for full report |
| `checkpoint:sweep` | Mark timed-out runs as failed |
| `checkpoint:prune` | Clean old command run records |
| `checkpoint:install` | Guided setup wizard |
| `checkpoint:migrate-from-spatie` | Interactive migration from spatie/laravel-backup |
| `checkpoint:catalog:export` | Export command run catalog as JSON or CSV |
| `checkpoint:config:show` | Show resolved configuration values |
| `checkpoint:make-driver` | Scaffold a custom backup driver |

## Quality

```bash
vendor/bin/pint            # auto-fix style (Laravel Pint)
vendor/bin/phpstan analyse # static analysis (level max)
vendor/bin/pest            # test suite (Pest only)
```
