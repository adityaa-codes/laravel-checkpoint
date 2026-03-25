# MySQL Support Implementation Plan (Atomic Tasks)

## Goal
Add first-class **MySQL** support with feature parity for existing PostgreSQL-oriented workflows:
- logical backup
- PITR restore
- logical restore (latest + file)
- backup drills
- existing command UX / queueing / reporting / guardrails

## Scope
- Add a dedicated MySQL driver (`mysql`) with explicit config.
- Preserve existing operations (`logical_backup`, `logical_restore_latest`, `logical_restore_file`, `pitr_restore`, `backup_drill`) so command surfaces remain stable.
- Keep existing safety model (`RestoreSafetyGuard`, queue uniqueness, output capture, redaction, doctor checks).
- Add docs + tests for MySQL behavior and failure modes.

## Out of Scope (for initial rollout)
- Multi-host orchestration or managed-service snapshots.
- Auto-provisioning temporary MySQL instances for drills.
- Non-MySQL engines (MariaDB-specific behavior can be follow-up if needed).

## Research Summary (Sources in `reference.md`)
- `mysqldump` is valid for logical backup but not ideal for very large datasets.
- PITR requires binary logs and typically: restore base backup, then replay binlogs with `mysqlbinlog` up to target time/position.
- Laravel package quality expectations emphasize config safety, testing, deterministic scheduling/queues, and explicit exception behavior.

## Proposed Technical Strategy
- Introduce `MysqlDriver` implementing `BackupDriver`.
- Keep operation names unchanged; map operations internally to MySQL command builders.
- Use `mysqldump` for logical backups, `mysql` client for logical restore.
- Implement PITR command path using `mysqlbinlog` replay up to target datetime.
- Persist structured metadata for:
  - dump artifact path
  - binlog source / replay bounds
  - verification signal linkage
- Extend config validator and doctor checks with MySQL-specific validation.
- Reuse existing command output capture and command redaction pipeline.

## Operation Mapping (Parity Contract)
- `logical_backup` -> `mysqldump ... > artifact.sql` (or `--result-file`)
- `logical_restore_file` -> `mysql < artifact.sql`
- `logical_restore_latest` -> resolve latest validated artifact, then `mysql < artifact.sql`
- `pitr_restore` -> base restore + `mysqlbinlog --stop-datetime=... | mysql`
- `backup_drill` -> configurable drill command for MySQL restore validation (non-prod target)

## Atomic Task Breakdown

### T01 - Define MySQL config schema
- Files: `config/checkpoint.php`, docs env section
- Add `drivers.mysql` with explicit keys (binaries, output dir/prefix, binlog options, timeouts, extra args)
- Acceptance: config has safe defaults and no closures

### T02 - Add config validation for MySQL
- Files: `src/Services/ConfigValidator.php` + tests
- Validate binary names, output paths, timeout bounds, replay mode and required PITR fields
- Acceptance: invalid config fails with actionable error messages

### T03 - Implement `MysqlDriver` skeleton
- Files: `src/Drivers/MysqlDriver.php`
- Implement `execute()` lifecycle parity with existing drivers:
  - claim pending
  - planned metadata
  - restore safety guard
  - command execution
  - output capture
  - success/failure events
- Acceptance: driver compiles, wired through `checkpoint.driver=mysql`

### T04 - Implement logical backup command builder
- Build deterministic `mysqldump` argv (no shell interpolation)
- Persist artifact metadata and last-known-good marker
- Acceptance: backup command run succeeds in tests and stores artifact metadata

### T05 - Implement logical restore command builders
- Support restore from explicit file and latest validated artifact
- Reuse artifact path hardening/snapshot checks pattern from `PgDumpDriver`
- Acceptance: restore target resolution is deterministic and path-safe

### T06 - Implement PITR command builder
- Add base restore + binlog replay flow (`mysqlbinlog` bounded replay)
- Require parseable datetime target and required binlog settings
- Acceptance: PITR path validates target and emits bounded replay metadata

### T07 - Integrate backup drill semantics
- Ensure `backup_drill` operation works under mysql driver config
- Preserve existing command/event/reporting semantics
- Acceptance: drill run records and reporting remain contract-compatible

### T08 - Extend doctor/health reporting for MySQL
- Files: `OperationalReportBuilder`, `DoctorCommand` data checks (if needed)
- Add MySQL-specific readiness checks (binary availability config, PITR preconditions)
- Acceptance: doctor output explains missing prerequisites clearly

### T09 - Extend translations and operation labels (if needed)
- Files: `lang/*`
- Ensure user-facing labels/messages remain accurate for mysql mode
- Acceptance: no untranslated/misleading PG-specific labels in mysql path

### T10 - Add/expand tests for MySQL support
- Unit: command builders, metadata, validator branches
- Feature: enqueue + processing + guardrails + doctor/report outputs
- Acceptance: regression-safe tests for happy path + key failure paths

### T11 - Update README documentation
- Add MySQL setup examples, PITR requirements, do/don't snippets
- Include security notes for credentials and logs
- Acceptance: operators can configure mysql mode without code reading

### T12 - CI coverage and compatibility review
- Ensure test matrix still passes under supported Laravel versions
- Acceptance: full test suite green, no contract regressions

## Dependency Graph
- T01 -> T02 -> T03
- T03 -> T04, T05, T06, T07
- T04/T05/T06 -> T08/T10
- T01/T02/T03/T04/T05/T06/T07/T08/T10 -> T11 -> T12

## Definition of Done
- `mysql` driver supports backup, restore, PITR, drills with existing command surfaces.
- Restore safety and verification policies remain enforced.
- Config, doctor, and docs clearly express prerequisites and constraints.
- Tests cover success, invalid config, unsafe restore, and PITR boundary behavior.

