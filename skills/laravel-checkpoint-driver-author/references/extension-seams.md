# Laravel Checkpoint Driver Extension Seams

Read this file before authoring or refactoring a driver.

## Core lifecycle

- Driver contract: `src/Contracts/BackupDriver.php`
- Driver binding: `src/LaravelCheckpointServiceProvider.php`
- Queue execution path: `src/Jobs/ProcessCommandRunJob.php`
- Public enqueue path: `src/Actions/EnqueueCommandRunAction.php`

## Existing implementations to mirror

- `src/Drivers/PgDumpDriver.php`
- `src/Drivers/PgBackRestDriver.php`
- `src/Drivers/MysqlDriver.php`
- `src/Drivers/FakeDriver.php`

## Driver responsibilities

- Claim pending execution safely.
- Build planned metadata before command execution.
- Enforce `RestoreSafetyGuard` for restore operations.
- Capture and redact command output correctly.
- Persist exit code, output, and metadata on the run record.
- Emit started, completed, or failed events.

## Adjacent seams that usually change with a new driver

- `config/checkpoint.php`
- `src/Services/ConfigValidator.php`
- `src/Services/CommandLineRedactor.php`
- `src/Services/OperationalReportBuilder.php`
- `tests/Unit/*DriverTest.php`
- `tests/Feature/*`
