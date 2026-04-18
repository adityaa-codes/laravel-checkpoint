# Laravel Checkpoint Package Extension Playbook

Read this file before extending package internals.

## Primary extension seams

- Driver contract: `src/Contracts/BackupDriver.php`
- Driver bindings and wiring: `src/LaravelCheckpointServiceProvider.php`
- Queue execution path: `src/Jobs/ProcessCommandRunJob.php`
- Enqueue entrypoint: `src/Actions/EnqueueCommandRunAction.php`

## Driver and operation implementations to mirror

- `src/Drivers/PgDumpDriver.php`
- `src/Drivers/PgBackRestDriver.php`
- `src/Drivers/MysqlDriver.php`
- `src/Drivers/FakeDriver.php`

## Required package behaviors to preserve

- Pending-run claim and lifecycle transitions.
- Restore safety guard enforcement for destructive operations.
- Command output capture, storage, and command-line redaction.
- Stable operational metadata used by status, doctor, and report surfaces.
- Lifecycle events and failure visibility.

## Adjacent files that often change with extensions

- `config/checkpoint.php`
- `src/Services/ConfigValidator.php`
- `src/Services/CommandLineRedactor.php`
- `src/Services/CommandJsonContract.php`
- `src/Services/OperationalReportBuilder.php`
- `tests/Unit/*DriverTest.php`
- `tests/Feature/*Command*Test.php`
