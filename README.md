# Laravel Checkpoint

Database reliability layer for Laravel applications. Backup, restore, point-in-time recovery, replication, and recovery drills — safe, auditable, and automation-friendly.

**Checkpoint is not a backup tool.** It is a business continuity system that treats database operations as first-class, queue-driven workflows with full observability, safety gates, and operator-facing diagnostics.

## Mission

Make backup, restore, PITR, replication, and recovery-drill operations:

- **Safe** — confirmation gates in non-local environments, queue locks prevent concurrent destructives
- **Auditable** — every run persisted as a `CommandRun` model with status, timing, and output
- **Automation-friendly** — JSON output, exit codes, and CI-ready diagnostics
- **Frictionless to install** — single config file, guided wizard, zero breaking defaults

## Installation

```bash
composer require adityaa-codes/laravel-checkpoint
```

Run the guided install wizard:

```bash
php artisan checkpoint:install
```

This publishes the config file (`config/checkpoint.php`) and runs any pending migrations.

Set your driver explicitly in `.env`:

```env
CP_DRIVER=postgres
# or: mysql
```

## First Backup

1. **Configure your driver** — set `CP_DRIVER` in `.env` to match your database
2. **Start a queue worker** — Checkpoint operations run on the `checkpoint` queue:
   ```bash
   php artisan queue:work --queue=checkpoint
   ```
   **Note:** This package uses the `checkpoint` queue. Ensure a queue worker is running: `php artisan queue:work --queue=checkpoint`
3. **Run a backup**:
   ```bash
   php artisan checkpoint:backup
   ```
4. **Check status**:
   ```bash
   php artisan checkpoint:status
   ```
5. **Run health diagnostics**:
   ```bash
    php artisan checkpoint:status --health
   ```

## Key Commands

| Command | Description |
|---------|-------------|
| `checkpoint:backup` | Run a logical database backup |
| `checkpoint:drill` | Execute a recovery drill against a backup |
| `checkpoint:replicate` | Run replication sync |
| `checkpoint:sweep` | Mark timed-out runs as failed |
| `checkpoint:status` | View recent command runs |
| `checkpoint:status --health` | Run database health checks |
| `checkpoint:restore --pitr-dry-run` | Check point-in-time recovery readiness |
| `checkpoint:status --full` | Generate operational report with triage |
| `checkpoint:catalog:export` | Export the command run catalog |
| `checkpoint:prune` | Clean old backups per retention policy |
| `checkpoint:install` | Guided installation wizard |
| `checkpoint:make-driver` | Scaffold a custom backup driver |
| `checkpoint:migrate-from-spatie` | Migrate from spatie/laravel-backup |

## Safety Model

- **Environment gates** — destructive operations require explicit confirmation outside `local`
- **Queue locks** — `ShouldBeUnique` prevents concurrent backup/restore/drain operations
- **Process safety** — all shell commands use Symfony Process array arguments (no string concatenation)
- **No swallowed exceptions** — every failure is reported via `report($e)` or `logger()->error(...)`
- **No error suppression** — return values are checked, `@` is never used
- **Environment isolation** — all env vars prefixed with `CP_*`

## Testing

Checkpoint ships with a `FakeDriver` and `InteractsWithCheckpoint` trait for testable backup workflows:

```php
use AdityaaCodes\LaravelCheckpoint\Testing\InteractsWithCheckpoint;

uses(InteractsWithCheckpoint::class);

it('queues a backup', function () {
    $fake = $this->fakeDriver();

    $this->artisan('checkpoint:backup');

    $this->assertBackupQueued('backup');
    expect($fake->executedOperations())->toHaveCount(1);
});
```

The fake driver bypasses config entirely via direct container binding, so it is safe for parallel test runs.

## Supported Drivers

| Driver | Use Case |
|--------|----------|
| `postgres` | Native PostgreSQL pg_dump/pg_restore |
| `mysql` | Native MySQL mysqldump/mysql |
| `fake` | Test-only driver (no real operations) |

## Requirements

- PHP 8.3+
- Laravel 12/13
- MySQL or PostgreSQL database

## Documentation

Full documentation is available at [laravel-checkpoint.com](https://laravel-checkpoint.com).

## Security

Authorization is owned by the consuming Laravel application. This package does not enforce RBAC policies.

All environment variables for database operations use the `CP_*` prefix to avoid conflicts with Laravel's standard `DB_*` connection variables.
