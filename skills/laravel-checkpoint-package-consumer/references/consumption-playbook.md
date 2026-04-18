# Laravel Checkpoint Package Consumption Playbook

Read this file before integrating the package in an app or automation system.

## Integration surfaces

- Package install and publish flow (Composer, config, migrations).
- App-triggered execution through facade or `LaravelCheckpoint::execute(...)`.
- Operator commands:
  - `db-ops:enqueue-backup`
  - `db-ops:enqueue`
  - `db-ops:status`
  - `db-ops:doctor`
  - `db-ops:report`
  - `db-ops:health-check`
  - `db-ops:recover-orphans`
  - `db-ops:prune`
  - `db-ops:record-drill`

## Driver selection guidance

- `pgbackrest`: PostgreSQL disaster recovery and PITR.
- `pgdump`: PostgreSQL logical export and restore.
- `mysql`: MySQL logical backup, restore, and replay.
- `shell`: custom command templates using argv-style inputs.

## JSON automation surfaces

- Prefer `db-ops:report` for combined operational snapshots.
- Use `db-ops:doctor --format=json` for health-only checks.
- Use `db-ops:status --format=json` for recent-run or summary-focused consumers.
- Respect effective caps and preserve command envelope versions.

## Files to inspect when consumption contracts drift

- `README.md`
- `config/checkpoint.php`
- `src/LaravelCheckpoint.php`
- `src/Console/StatusCommand.php`
- `src/Console/DoctorCommand.php`
- `src/Console/ReportCommand.php`
- `src/Services/ConfigValidator.php`
- `src/Services/CommandJsonContract.php`
