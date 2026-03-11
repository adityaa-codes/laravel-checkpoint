# Changelog

All notable changes to `laravel-checkpoint` will be documented in this file.

## v1.0.0 - 2026-03-11

### Added

- Core persistence for command runs and backup drill runs
- `CommandRun` and `BackupDrillRun` models with factories and status enum
- `CommandRunCatalog` with built-in operations, runtime extension, and config merge support
- Backup lifecycle events and backup drill completion event
- `BackupDriver` contract with shell and fake driver implementations
- Queue orchestration via `EnqueueCommandRunAction` and `ProcessCommandRunJob`
- Artisan commands for enqueueing, status, drill recording, health checks, orphan recovery, pruning, and doctor checks
- Config validation and package health diagnostics
- Package testing helpers and architecture coverage

### Changed

- Replaced package-skeleton placeholder usage examples with package-specific runtime and extension documentation

### Tooling

- Added Pest, PHPStan, Pint, Rector, Infection, CI workflows, release automation, and Dependabot configuration
- Added DDEV-based local development support for PHP and Composer workflows
