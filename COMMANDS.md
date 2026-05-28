# Commands

## Available Commands

| # | Command | File | Purpose |
|---|---------|------|---------|
| 1 | `checkpoint:backup` | `BackupCommand.php` | Run a logical backup |
| 2 | `checkpoint:drill` | `DrillCommand.php` | Run a backup recovery drill |
| 3 | `checkpoint:replicate` | `ReplicateCommand.php` | Run replication sync |
| 4 | `checkpoint:sweep` | `SweepCommand.php` | Mark timed-out runs as failed |
| 5 | `checkpoint:status` | `StatusCommand.php` | Recent command runs (--watch, --summary, --brief) |
| 6 | `checkpoint:catalog:export` | `CatalogExportCommand.php` | Export command run catalog |
| 7 | `checkpoint:prune` | `PruneCommand.php` | Clean up old backups per retention policy |
| 8 | `checkpoint:migrate-from-spatie` | `MigrateFromSpatieCommand.php` | Migrate from spatie/laravel-backup |
| 9 | `checkpoint:install` | `InstallCommand.php` | Guided install wizard |
| 10 | `checkpoint:doctor:health` | `DoctorHealthCommand.php` | Database health checks (binaries, config, backup freshness) |
| 11 | `checkpoint:doctor:pitr` | `DoctorPitrCommand.php` | Point-in-time recovery readiness checks |
| 12 | `checkpoint:doctor:report` | `DoctorReportCommand.php` | Operational report with triage |
| 13 | `checkpoint:make-driver` | `MakeDriverCommand.php` | Scaffold a custom backup driver |

## Planned Commands

| Command | Purpose |
|---------|---------|
| `checkpoint:restore` | Run a logical restore |
| `checkpoint:test` | Verify package configuration |
| `checkpoint:config:show` | Show resolved configuration values |
