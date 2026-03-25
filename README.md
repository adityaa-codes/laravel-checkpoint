# Laravel Checkpoint

[![Latest Version on Packagist](https://img.shields.io/packagist/v/adityaa-codes/laravel-checkpoint.svg?style=flat-square)](https://packagist.org/packages/adityaa-codes/laravel-checkpoint)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/adityaa-codes/laravel-checkpoint/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/adityaa-codes/laravel-checkpoint/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/adityaa-codes/laravel-checkpoint/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/adityaa-codes/laravel-checkpoint/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/adityaa-codes/laravel-checkpoint.svg?style=flat-square)](https://packagist.org/packages/adityaa-codes/laravel-checkpoint)

Core Laravel package for database checkpoint, backup, restore, drill recording, and operational safety workflows.

## Installation

Install the package:

```bash
composer require adityaa-codes/laravel-checkpoint
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag="laravel-checkpoint-config"
php artisan vendor:publish --tag="laravel-checkpoint-migrations"
php artisan migrate
```

## Configuration

Important config groups in `config/checkpoint.php`:

- `user_model`, `user_name_column`, `table_prefix`
- `queue.connection`, `queue.name`, `queue.max_attempts`, `queue.retry_after`, `queue.timeout`, `queue.unique_for`, `queue.lock_store`, `queue.orphan_threshold`, `queue.orphan_claim_timeout`, `queue.orphan_batch_size`, `queue.orphan_event_max_ids`, `queue.heartbeat_interval_seconds`, `queue.heartbeat_grace_seconds`
- `schedule.logical_backup_*`, `schedule.health_check_enabled`, `schedule.recover_orphans_enabled`, `schedule.prune_enabled`, `schedule.without_overlapping`, `schedule.overlap_expires_at`, `schedule.on_one_server`, `schedule.prune_keep_*`
- `driver`, `drivers.shell.*`, `drivers.pgbackrest.*`, `drivers.pgdump.*`, `drivers.mysql.*`
- `reporting.max_recent_runs`, `output.max_persisted_bytes`
- `log_channel`
- `custom_operations`

Common environment variables:

```env
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_QUEUE_LOCK_STORE=redis
DB_OPS_QUEUE_ORPHAN_THRESHOLD=10
DB_OPS_QUEUE_ORPHAN_CLAIM_TIMEOUT=61
DB_OPS_QUEUE_ORPHAN_BATCH_SIZE=100
DB_OPS_QUEUE_ORPHAN_EVENT_MAX_IDS=50
DB_OPS_QUEUE_HEARTBEAT_INTERVAL_SECONDS=30
DB_OPS_QUEUE_HEARTBEAT_GRACE_SECONDS=60
DB_OPS_OUTPUT_MAX_PERSISTED_BYTES=65536
DB_OPS_PRUNE_KEEP_DAYS=90
DB_OPS_PRUNE_KEEP_FAILED_DAYS=365
DB_OPS_PRUNE_KEEP_BACKUP_DRILL_DAYS=365
DB_OPS_LOG_CHANNEL=stack
DB_OPS_ALERT_COOLDOWN_SECONDS=300

DB_OPS_CMD_LOGICAL_BACKUP=
DB_OPS_CMD_RESTORE_LATEST=
DB_OPS_CMD_RESTORE_FILE=
DB_OPS_CMD_PITR_RESTORE=
DB_OPS_CMD_BACKUP_DRILL=
DB_OPS_CMD_PGBACKREST_CHECK=
DB_OPS_CMD_PGBACKREST_INFO=

DB_OPS_PGBACKREST_BINARY=pgbackrest
DB_OPS_PGBACKREST_STANZA=main
DB_OPS_PGBACKREST_REPO=1
DB_OPS_PGBACKREST_REPO1_TYPE=posix
DB_OPS_PGBACKREST_REPO1_PATH=/var/lib/pgbackrest/repo1
DB_OPS_PGBACKREST_REPO1_TLS_VERIFY=true
DB_OPS_PGBACKREST_REPO1_ENCRYPTION_ENABLED=false
DB_OPS_PGDUMP_BINARY=pg_dump
DB_OPS_PGRESTORE_BINARY=pg_restore
DB_OPS_PGDUMP_FORMAT=directory
DB_OPS_PGDUMP_JOBS=4
DB_OPS_PGDUMP_COMPRESS_LEVEL=6
DB_OPS_PGDUMP_OUTPUT_DIR=/var/app/checkpoint/logical-exports
DB_OPS_TEMP_DIR=/var/app/checkpoint/tmp
DB_OPS_MYSQL_DUMP_BINARY=mysqldump
DB_OPS_MYSQL_BINARY=mysql
DB_OPS_MYSQL_BINLOG_BINARY=mysqlbinlog
DB_OPS_MYSQL_OUTPUT_DIR=/var/app/checkpoint/mysql/logical-exports
DB_OPS_MYSQL_OUTPUT_PREFIX=mysql-export
DB_OPS_MYSQL_FILE_EXTENSION=sql
DB_OPS_MYSQL_PITR_BINLOG_FILES=/var/lib/mysql/binlog.000123,/var/lib/mysql/binlog.000124
DB_OPS_MYSQL_DRILL_COMMAND=
DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS=staging
DB_OPS_RESTORE_ALLOWED_DATABASES=checkpoint_shadow
DB_OPS_RESTORE_REQUIRE_CONFIRMATION=true
DB_OPS_RESTORE_CONFIRMATION_PHRASE=RESTORE
DB_OPS_RESTORE_CONFIRMATION=
DB_OPS_RESTORE_ALLOW_IN_CI=true
DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP=true
```

Command templates are configured as space-delimited argv-style strings and are parsed into Symfony Process argument arrays. User input is validated before it reaches the driver.

### Production Queue And Locking Guidance

For production, the package assumes long-running jobs and shared infrastructure.

- `DB_OPS_QUEUE_RETRY_AFTER` must be greater than `DB_OPS_QUEUE_TIMEOUT`
- `DB_OPS_QUEUE_UNIQUE_FOR` must be greater than or equal to `DB_OPS_QUEUE_RETRY_AFTER`
- `DB_OPS_QUEUE_ORPHAN_CLAIM_TIMEOUT` should be greater than or equal to `ceil(DB_OPS_QUEUE_RETRY_AFTER / 60)`
- `DB_OPS_QUEUE_HEARTBEAT_INTERVAL_SECONDS` must be lower than `DB_OPS_QUEUE_TIMEOUT`
- `DB_OPS_QUEUE_LOCK_STORE` should point at a shared lock backend, typically `redis`
- scheduled commands are configured to use `withoutOverlapping()` and `onOneServer()` by default

Recommended production values:

```env
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_QUEUE_ORPHAN_THRESHOLD=10
DB_OPS_QUEUE_ORPHAN_CLAIM_TIMEOUT=61
DB_OPS_QUEUE_ORPHAN_BATCH_SIZE=100
DB_OPS_QUEUE_ORPHAN_EVENT_MAX_IDS=50
DB_OPS_QUEUE_HEARTBEAT_INTERVAL_SECONDS=30
DB_OPS_QUEUE_HEARTBEAT_GRACE_SECONDS=60
DB_OPS_QUEUE_LOCK_STORE=redis
DB_OPS_SCHEDULE_WITHOUT_OVERLAPPING=true
DB_OPS_SCHEDULE_OVERLAP_EXPIRES_AT=180
DB_OPS_SCHEDULE_ON_ONE_SERVER=true
```

Worker alignment matters:

- the Laravel queue worker `--timeout` should be a few seconds shorter than `DB_OPS_QUEUE_RETRY_AFTER`
- orphan recovery claims should last at least as long as the queue redelivery window so stale queued work is not re-enqueued prematurely
- long-running jobs now refresh heartbeat markers while command output streams, and `db-ops:health-check` uses heartbeat freshness (with `DB_OPS_QUEUE_HEARTBEAT_GRACE_SECONDS`) before failing running work
- this package validates the config contract, but your worker process must still be started with a compatible timeout
- each driver timeout (`DB_OPS_CMD_TIMEOUT`, `DB_OPS_PGBACKREST_TIMEOUT`, `DB_OPS_PGDUMP_TIMEOUT`, `DB_OPS_MYSQL_TIMEOUT`) must be less than or equal to `DB_OPS_QUEUE_TIMEOUT` so queued jobs are not killed mid-command

Example worker command:

```bash
php artisan queue:work --queue=db-ops --timeout=3600
```

Recommended production cache config:

- use Redis for queue uniqueness and scheduler overlap locks
- avoid local-only cache drivers for multi-node deployments, because they cannot coordinate uniqueness or `onOneServer()` safely across hosts
- non-local environments reject `array` and `file` lock stores during config validation because they are not safe for production uniqueness or clustered scheduling
- non-local environments also reject `cache.default` stores that use `array` or `file` when scheduled guardrails (`withoutOverlapping` / `onOneServer`) are enabled
- the package test suite includes shared-cache lock coverage for duplicate job suppression, but production still requires a real shared cache backend across nodes

## Usage

Queue a run through the facade:

```php
use AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint;

$run = LaravelCheckpoint::execute('logical_backup');
```

Queue a run through the action directly:

```php
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;

$run = app(EnqueueCommandRunAction::class)->execute('logical_restore_file', 'nightly.sql');
```

Artisan commands:

```bash
php artisan db-ops:enqueue-backup
php artisan db-ops:enqueue logical_backup
php artisan db-ops:status --limit=10
php artisan db-ops:status --summary
php artisan db-ops:status --format=json
php artisan db-ops:status --summary --format=json
php artisan db-ops:record-drill --run-uuid=... --overall-result=pass --executed-at=2026-03-11T10:30:00+00:00
php artisan db-ops:health-check
php artisan db-ops:recover-orphans
php artisan db-ops:prune
php artisan db-ops:doctor
php artisan db-ops:doctor --format=json
php artisan db-ops:report --limit=10
```

## Driver Customization

The shipped shell driver is PostgreSQL-oriented, but the contract is database-engine agnostic. You can override command templates for another engine.

Example MySQL dump command:

```env
DB_OPS_CMD_LOGICAL_BACKUP="mysqldump --single-transaction {db}"
```

If you need a custom implementation, bind `AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver` to your own driver class and point `checkpoint.driver` / `checkpoint.drivers` at it.

### MySQL Driver Strategy

Use the bundled `mysql` driver when your backup flow is based on
`mysqldump` logical exports and optional binlog replay:

```env
DB_OPS_DRIVER=mysql
DB_OPS_MYSQL_DUMP_BINARY=mysqldump
DB_OPS_MYSQL_BINARY=mysql
DB_OPS_MYSQL_BINLOG_BINARY=mysqlbinlog
DB_OPS_MYSQL_OUTPUT_DIR=/var/app/checkpoint/mysql/logical-exports
DB_OPS_MYSQL_OUTPUT_PREFIX=mysql-export
DB_OPS_MYSQL_FILE_EXTENSION=sql
DB_OPS_MYSQL_SINGLE_TRANSACTION=true
DB_OPS_MYSQL_QUICK=true
DB_OPS_MYSQL_SKIP_LOCK_TABLES=true
DB_OPS_MYSQL_PITR_BINLOG_FILES=/var/lib/mysql/binlog.000123,/var/lib/mysql/binlog.000124
DB_OPS_MYSQL_DRILL_COMMAND="/usr/local/bin/checkpoint-mysql-drill --db={db} --backup-dir={backup_dir}"
```

Expected binaries and runtime dependencies:

- `mysqldump`, `mysql`, and `mysqlbinlog` available on worker PATH (or set explicit binary paths)
- database user privileges sufficient for logical dump + restore workflows
- binlog retention and access aligned with your PITR target window
- restore safety settings configured (`restore.allowed_environments`, `restore.allowed_databases`, confirmation controls)

Operational behavior:

- `logical_backup` writes a SQL artifact to `drivers.mysql.output_dir`
- `logical_restore_latest` restores the newest successful tracked MySQL logical export (or newest matching file in `output_dir` when tracking is unavailable)
- `logical_restore_file` resolves relative names inside `output_dir` and rejects restore paths outside the configured directory
- `pitr_restore` expects a restore target timestamp argument, restores the latest logical export baseline, then extracts/replays configured binlogs up to that target
- `backup_drill` requires `drivers.mysql.drill_command`; the package executes your drill command but does not provision an isolated drill environment for you

PITR workflow expectation: treat logical backup + binlogs as one recovery chain. A PITR run is only as good as the baseline export and the completeness/order of `pitr.binlog_files`.

### PostgreSQL Driver Strategy

For PostgreSQL production environments, use the drivers with different roles:

- `pgbackrest`: primary disaster-recovery and PITR workflow
- `pgdump`: optional logical export workflow for large databases, schema export, selective restore preparation, and migration/archive use cases
- `shell`: escape hatch for legacy or custom commands

Logical exports are not the primary DR strategy for huge PostgreSQL systems. The recommended production path is:

1. use `pgbackrest` for regular backup, restore, and verification
2. use `pgdump` only when you specifically need a logical export artifact

### pgBackRest Repository Hardening

The `pgbackrest` driver now models repositories explicitly instead of treating
repo selection as a bare integer. Configure the active repo with
`DB_OPS_PGBACKREST_REPO` and then define repo-specific settings under
`drivers.pgbackrest.repositories`.

Supported repository types:

- `posix`: local or mounted filesystem path via `path`
- `s3`: remote object storage via bucket, endpoint, region, access key, and secret

Repository hardening fields:

- `tls.verify` and optional `tls.ca_file`
- `encryption.enabled`, `encryption.cipher_type`, and `encryption.passphrase`

Example remote repo env:

```env
DB_OPS_DRIVER=pgbackrest
DB_OPS_PGBACKREST_REPO=1
DB_OPS_PGBACKREST_REPO1_TYPE=s3
DB_OPS_PGBACKREST_REPO1_S3_BUCKET=checkpoint-backups
DB_OPS_PGBACKREST_REPO1_S3_ENDPOINT=s3.example.com
DB_OPS_PGBACKREST_REPO1_S3_REGION=ap-south-1
DB_OPS_PGBACKREST_REPO1_S3_KEY=...
DB_OPS_PGBACKREST_REPO1_S3_SECRET=...
DB_OPS_PGBACKREST_REPO1_TLS_VERIFY=true
DB_OPS_PGBACKREST_REPO1_TLS_CA_FILE=/etc/ssl/checkpoint.pem
DB_OPS_PGBACKREST_REPO1_ENCRYPTION_ENABLED=true
DB_OPS_PGBACKREST_REPO1_ENCRYPTION_CIPHER=aes-256-cbc
DB_OPS_PGBACKREST_REPO1_ENCRYPTION_PASSPHRASE=...
```

Operational notes:

- `db-ops:doctor` reports the active repo target, TLS verification state, and encryption mode
- persisted `command_line` values redact S3 keys, S3 secrets, and cipher passphrases
- shell and `pgdump` command lines also redact inline credentials, separated secret flags, and connection-URI passwords before persistence and logging
- temporary package artifacts (output capture streams, pgBackRest secret config files, mysql PITR binlog extraction files) are created under `DB_OPS_TEMP_DIR` rather than shared system temp paths
- doctor output never prints raw repository secrets
- observability alarms are deduplicated for `DB_OPS_ALERT_COOLDOWN_SECONDS` to reduce repeated pages for the same condition

### Restore Safety Guardrails

Restore operations are now gated by explicit safety config before any shell,
`pg_restore`, or pgBackRest restore command runs.

Safety controls:

- `restore.allowed_environments`: allowlist for environments where restore commands may run
- `restore.allowed_databases`: optional allowlist for database names that may receive restore traffic
- `restore.require_confirmation`: requires `restore.confirmation_token` to match `restore.confirmation_phrase` outside CI
- `restore.allow_in_ci`: defaults to `false`; when explicitly enabled it lets CI bypass the confirmation token
- `restore.require_verified_backup`: defaults to `true` outside `local`/`testing` (and `false` in local/testing), requiring a prior verified restore signal before restore execution

Example guarded restore env:

```env
DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS=staging
DB_OPS_RESTORE_ALLOWED_DATABASES=checkpoint_shadow
DB_OPS_RESTORE_REQUIRE_CONFIRMATION=true
DB_OPS_RESTORE_CONFIRMATION_PHRASE=RESTORE
DB_OPS_RESTORE_CONFIRMATION=RESTORE
DB_OPS_RESTORE_ALLOW_IN_CI=false
DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP=true
```

Behavior notes:

- `logical_restore_file`, `logical_restore_latest`, `pitr_restore`, and `pgbackrest_restore` all use the same guard service
- `pitr_restore` now rejects invalid restore target timestamps before command execution
- restore commands fail early when the current environment or target database is not allowlisted
- when verified-backup enforcement is enabled, `pgdump` restores require a matching `last_known_good_at` signal for the selected artifact, and pgBackRest restores require a successful verified `check` or `verify` run for the selected stanza/repository
- restore runs persist `metadata.restore_audit` with the evaluated environment, database, target, confirmation path, and any matched verified-backup signal
- `db-ops:status --format=json` mirrors both recent-run and summary views for automation use
- `db-ops:status --format=json` includes top-level `version` and `surface=status` fields for contract-safe consumers

### Observability Notes

Operational surfaces now include:

- `db-ops:report` for one combined machine-readable operational snapshot
- `db-ops:doctor --format=json` for machine-readable health checks
- all machine-readable command payloads now expose a top-level `version` and `surface`
- `db-ops:doctor` freshness warnings for stale last-known-good backups
- `db-ops:doctor` duration anomaly warnings for unusually slow backup runs
- `db-ops:doctor` backup drill freshness and pass-rate warnings
- `db-ops:doctor` restore posture warnings in non-local environments when restore allowlists are broad, CI bypass is enabled, or verified-backup enforcement is disabled
- structured log context across drivers, queue job failures, and health checks
- orphan recovery events for queue lag and redispatched stale runs

`db-ops:status` now exposes restore-specific operator context:

- recent-run JSON payloads include `restore_target` and `restore_audit` for restore operations
- `db-ops:status --summary` includes `latest_restore_run` alongside the existing latest restore failure signal
- `latest_restore_run.audit` gives automation consumers the persisted restore guard decision that was in effect when the run started

`db-ops:status` also surfaces backup drill analytics:

- `latest_backup_drill` and `latest_failed_backup_drill` identify the most recent drill outcomes
- `backup_drill_pass_rate` summarizes recent drill reliability for automation consumers
- `backup_drill_pass_rate_30d` remains available as a compatibility alias
- the table summary mirrors those signals for operators without requiring JSON parsing

`db-ops:report` is the preferred automation surface when you want one payload
instead of stitching together multiple commands. It combines:

- `recent_runs`
- `summary`
- `health.ok` plus `health.checks`
- a top-level `version`, `generated_at`, and active `driver`

Report notes:

- `health.ok` is only `true` when every emitted health check is `pass`
- `summary.backup_drill_pass_rate.window_days` follows the same configurable drill pass-rate window as `db-ops:doctor`
- `db-ops:doctor --format=json` uses the same health semantics as `db-ops:report`: `ok` is only `true` when every emitted health check is `pass`
- `db-ops:status` emits JSON contract version `1`, `db-ops:doctor` emits `3`, and `db-ops:report` emits `2`
- `db-ops:report` includes both `limit_requested` and effective `limit` so automation can detect capped history responses
- future JSON contract changes should stay additive within a version; breaking shape changes should increment the top-level `version`

Reporting limits are configurable:

- `reporting.max_recent_runs`: hard cap applied to `db-ops:status --format=json` and `db-ops:report`
- `DB_OPS_REPORTING_MAX_RECENT_RUNS`: env override for the same cap

Command output storage limits are configurable:

- `output.max_persisted_bytes`: hard cap applied to persisted `command_output`
- `DB_OPS_OUTPUT_MAX_PERSISTED_BYTES`: env override for the same cap
- drivers stream output through a bounded capture buffer, so large command output no longer needs to be fully materialized in PHP just to persist run diagnostics
- truncated runs expose `metadata.output_capture` with `truncated`, `original_bytes`, `persisted_bytes`, and the configured cap
- `output.storage=filesystem` moves the bounded command output artifact off-row while keeping an inline preview in `command_output`
- `output.filesystem.disk`, `output.filesystem.path_prefix`, and `output.filesystem.inline_bytes` control external storage placement and preview size
- `CommandRun::resolvedCommandOutput()` returns the externalized artifact when filesystem storage is enabled
- backup events continue to emit the inline preview in their `output` payload; use `event->run->resolvedCommandOutput()` when listeners need the externalized artifact

Backup drill observability thresholds are configurable:

- `observability.max_backup_drill_age_days`: warns when the newest drill is older than this threshold
- `observability.backup_drill_pass_rate_window_days`: rolling window used for drill pass-rate evaluation
- `observability.backup_drill_min_pass_rate`: minimum acceptable drill pass rate percentage before `doctor` warns

Retention notes:

- `db-ops:prune` now deletes expired `command_runs` and expired `backup_drill_runs`
- `schedule.prune_keep_backup_drill_days` controls backup drill retention
- drill retention must be greater than or equal to both `observability.max_backup_drill_age_days` and `observability.backup_drill_pass_rate_window_days`, so pruning cannot erase the package's own drill health window

Structured log fields include `run_id`, `driver`, `backup_type`,
`restore_target`, `repository`, `stanza`, and `duration_seconds` when those
values are known for the current run.

Event hooks now include:

- `BackupFreshnessAlarmTriggered` when `db-ops:doctor` detects a missing or stale last-known-good backup
- `BackupDrillFreshnessAlarmTriggered` when no drill exists or the newest drill is older than the configured age threshold
- `BackupDrillPassRateAlarmTriggered` when no drills exist in the pass-rate window or the rolling pass rate drops below the configured threshold
- `QueueLagDetected` when `db-ops:recover-orphans` finds stale pending work, including oldest stale age, total claimed backlog count, and a bounded sample of affected run ids
- `OrphanRunRedispatched` for each stale pending run that gets re-queued, including queue and stale age context

For orphan recovery, "stale age" is based on the run's last pending heartbeat
(`updated_at`), not raw row creation time. Redispatch coordination uses a
separate `orphan_recovery_claimed_at` lease so operator diagnostics and lag
events continue to reflect stuck pending work instead of lease timestamps.
`QueueLagDetected::staleRunIds` is intentionally capped by
`DB_OPS_QUEUE_ORPHAN_EVENT_MAX_IDS`; use `staleRunCount` as the authoritative
total and treat the ids array as a sample for debugging.
Observable event payloads expose a `version` field so downstream consumers can
branch safely if new fields are added later. Current payload version: `1`.

Wire these events to your application listeners, metrics pipeline, or alerting
provider to turn them into actual pages, notifications, or dashboards.

### Operational Load Testing

Use a non-production queue and database clone when validating long-running or
partially failing workloads:

1. enqueue a burst of non-destructive work such as `pgbackrest_info`,
   `pgbackrest_check`, and `logical_backup`, plus at least two concurrent
   exclusive backup requests
2. run workers with the same timeout model used in production and confirm that
   duplicate exclusive work is skipped rather than re-executed
3. let a subset of runs age past `DB_OPS_QUEUE_ORPHAN_THRESHOLD`, then run
   `php artisan db-ops:recover-orphans` and verify the lag events, stale-age
   payloads, and re-dispatch logs
4. simulate a timed-out worker and verify `php artisan db-ops:health-check`
   marks the run failed with an alertable `BackupFailed` event
5. run `php artisan db-ops:status --summary` and `php artisan db-ops:doctor
   --format=json` to confirm backlog, freshness, and anomaly signals are still
   coherent under load
6. for pgBackRest backup retries, confirm retry command lines still include
   `--resume` and `--start-fast` before declaring the environment ready

### Upgrade Staging Guidance

For existing deployments, stage hardening controls instead of enabling every strict gate in one release:

1. deploy schema updates first (`vendor:publish` migrations + `php artisan migrate`)
2. baseline outputs with `db-ops:status --summary`, `db-ops:doctor --format=json`, and `db-ops:report --limit=10`
3. enforce restore environment/database allowlists and confirmation requirements
4. keep verified-backup enforcement temporarily disabled only until verified backup signals are stable, then enable it
5. verify restore attempts are writing append-only `db_ops_restore_decision_events` and that status/report restore audit fields remain coherent

See `UPGRADING.md` for the detailed staged-enforcement sequence and migration safety checks.

### pgDump Large-Export Configuration

The bundled `pgdump` driver defaults to PostgreSQL directory format so large exports use parallel dump jobs:

```env
DB_OPS_DRIVER=pgdump
DB_OPS_PGDUMP_FORMAT=directory
DB_OPS_PGDUMP_JOBS=8
DB_OPS_PGDUMP_COMPRESS_LEVEL=3
DB_OPS_PGDUMP_OUTPUT_DIR=/var/app/checkpoint/logical-exports
DB_OPS_PGDUMP_OUTPUT_PREFIX=huge-export
```

Behavior notes:

- directory format exports use `pg_dump --format=directory --jobs=<n>`
- parallel dump jobs are only valid for directory format
- restore commands use `pg_restore`
- `logical_restore_latest` prefers the newest tracked successful logical export and only falls back to scanning the configured output directory when tracking metadata is missing or stale
- `logical_restore_file` resolves relative export names inside the configured output directory
- restore targets are snapshotted and revalidated immediately before `pg_restore` argv is built so swapped files or mutated directory exports are rejected

## Extending The Catalog

You can extend available operations at runtime or through config.

Runtime extension:

```php
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;

$catalog = app(CommandRunCatalog::class);

$catalog->extend('tenant_backup', [
    'label' => 'Tenant Backup',
    'argument_required' => true,
    'argument_hint' => 'tenant id',
    'argument_validator' => static fn (?string $value): bool => $value !== null && ctype_digit($value),
    'destructive' => false,
    'exclusive' => true,
]);
```

Config extension:

```php
'custom_operations' => [
    'tenant_backup' => [
        'label' => 'Tenant Backup',
        'argument_required' => true,
        'argument_hint' => 'tenant id',
        'argument_validator' => static fn (?string $value): bool => $value !== null && ctype_digit($value),
        'destructive' => false,
        'exclusive' => true,
    ],
],
```

## Public API

Supported extension points:

- `AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver`
- `AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction`
- `AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog`
- `AdityaaCodes\LaravelCheckpoint\Models\CommandRun`
- `AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun`
- `AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus`
- `AdityaaCodes\LaravelCheckpoint\Testing\InteractsWithCheckpoint`
- All event classes in `AdityaaCodes\LaravelCheckpoint\Events`

Internal implementation details such as drivers, queue jobs, and config validation are marked `@internal` and should not be depended on directly.

## Testing

Run the focused package checks:

```bash
composer test
composer analyse
composer format
```

In this repository, PHP and Composer commands are run through DDEV:

```bash
ddev exec vendor/bin/pest
ddev exec vendor/bin/phpstan analyse
```

For coverage in DDEV:

```bash
ddev exec env XDEBUG_MODE=coverage vendor/bin/pest --coverage
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See the contributing guide in `CONTRIBUTING.md`.

## Security

See `SECURITY.md` for reporting instructions.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
