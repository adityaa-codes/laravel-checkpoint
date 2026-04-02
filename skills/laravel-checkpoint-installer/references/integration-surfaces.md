# Laravel Checkpoint Integration Surfaces

Read this file before performing installer work.

## Public package surfaces

- Install with Composer, then publish config and migrations, then migrate.
- Application-triggered execution flows through the facade or `LaravelCheckpoint::execute(...)`.
- Operator surfaces are the artisan commands:
  - `db-ops:enqueue-backup`
  - `db-ops:enqueue`
  - `db-ops:status`
  - `db-ops:doctor`
  - `db-ops:report`
  - `db-ops:health-check`
  - `db-ops:recover-orphans`
  - `db-ops:prune`
  - `db-ops:record-drill`

## Driver selection

- `pgbackrest`: production PostgreSQL disaster recovery and PITR.
- `pgdump`: PostgreSQL logical export and restore flows.
- `mysql`: MySQL logical export, restore, PITR replay, and drill command integration.
- `shell`: custom argv-style command templates.

## Critical config contracts

- Queue timeout must be compatible with worker timeout and greater than or equal to driver time budgets.
- Queue uniqueness and lock store must coordinate across nodes in non-local environments.
- Restore operations must satisfy environment, database, confirmation, and verified-backup rules.
- Scheduled commands assume overlap protection and single-server coordination by default.

## Files to inspect when the package changes

- `README.md`
- `config/checkpoint.php`
- `src/LaravelCheckpoint.php`
- `src/LaravelCheckpointServiceProvider.php`
- `src/Services/ConfigValidator.php`
