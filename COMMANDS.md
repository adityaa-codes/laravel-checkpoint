# Commands

| # | Command | File | Lines | Purpose |
|---|---------|------|-------|---------|
| 1 | `checkpoint:backup` | `BackupCommand.php` | — | Run a logical backup |
| 2 | `checkpoint:drill` | `DrillCommand.php` | — | Run a backup recovery drill |
| 3 | `checkpoint:restore` | `RestoreCommand.php` | — | Run a logical restore |
| 4 | `checkpoint:replicate` | `ReplicateCommand.php` | — | Run replication sync |
| 5 | `checkpoint:status` | `StatusCommand.php` | — | Recent command runs (--watch, --summary, --brief) |
| 6 | `checkpoint:doctor` | `DoctorCommand.php` | — | Health checks (binaries, config, backup freshness, drills) |
| 7 | `checkpoint:report` | `ReportCommand.php` | — | Operational report with triage |
| 8 | `checkpoint:prune` | `PruneCommand.php` | — | Clean up old backups per retention policy |
| 9 | `checkpoint:enqueue` | `EnqueueCommand.php` | — | Enqueue an operation to the queue |
| 10 | `checkpoint:install` | `InstallCommand.php` | — | Guided install wizard |
| 11 | `checkpoint:migrate-from-spatie` | `MigrateFromSpatieCommand.php` | — | Migrate from spatie/laravel-backup |
| 12 | `checkpoint:make-driver` | `MakeDriverCommand.php` | — | Scaffold a custom backup driver |
| 13 | `checkpoint:health-check` | `HealthCheckCommand.php` | — | Sweeper: mark timed-out runs as failed |
| 14 | `checkpoint:recover-orphans` | `RecoverOrphansCommand.php` | — | Mark stale pending runs as failed |
| 15 | `checkpoint:record-drill` | `RecordDrillRunCommand.php` | — | Record a drill run result |
| 16 | `checkpoint:pitr-readiness` | `PitrReadinessCommand.php` | — | Point-in-time recovery readiness |
| 17 | `checkpoint:catalog-export` | `CatalogExportCommand.php` | — | Export command run catalog |
| 18 | `checkpoint:retention-policy` | `RetentionPolicyCommand.php` | — | Show/validate retention policy |
| 19 | `checkpoint:test` | `TestCommand.php` | — | Verify package configuration |
