# Laravel Checkpoint Hardening Execution Plan (Atomic)

## Objective

Evolve the package to a production-grade "final form" by closing residual
safety, correctness, operability, and scale gaps identified through
adversarial review.

This plan is intentionally atomic: each task should be independently
implementable, testable, and revertable.

## Final-Form Pass Criteria

- Restore execution enforces environment allowlist, database allowlist,
  confirmation, and verified provenance.
- Cluster coordination is safe (no local-only lock stores outside local/testing).
- Run liveness is heartbeat-based (not age-only).
- Restore decisions are audit-grade and immutable enough for incident review.
- Multi-tenant/multi-database identity is explicit in verification matching.
- Alerting is actionable and low-noise.
- Output retention is sufficient for forensics.
- Query paths are indexed and load-tested for high run volume.

Any failed criterion is a release blocker for "production-grade" status.

## Delivery Strategy

1. Ship guardrail correctness before ergonomics.
2. Land tests before strict default flips where feasible.
3. Roll out strict enforcement in staged mode (warn -> enforce) where breaking.
4. Keep each PR/task single-purpose with explicit acceptance criteria.

---

## Phase A - P0 Critical Guardrails

### A01 - Flip unsafe restore defaults
- Scope: config defaults + docs.
- Changes:
  - set `restore.allow_in_ci` default to `false`
  - set `restore.require_verified_backup` default to `true` for non-local posture
- Files:
  - `config/checkpoint.php`
  - `README.md`
- Tests:
  - `tests/Feature/ConfigValidatorCoverageTest.php`
  - `tests/Feature/RestoreSafetyGuardTest.php`
- Acceptance:
  - defaults reflect safer posture
  - tests assert expected behavior under default config

### A02 - Enable production-time config validation
- Scope: service provider bootstrap behavior.
- Changes:
  - remove production skip for `ConfigValidator` execution
  - preserve clear exception output for invalid config
- Files:
  - `src/LaravelCheckpointServiceProvider.php`
- Tests:
  - `tests/Feature/ServiceProviderCoverageTest.php`
- Acceptance:
  - invalid production-like config fails fast on boot
  - valid config boots unchanged

### A03 - Add MySQL restore TOCTOU snapshot revalidation
- Scope: mysql restore path safety parity with pgdump.
- Changes:
  - snapshot restore target during planning
  - revalidate snapshot just before restore execution
  - fail on target drift/replacement
- Files:
  - `src/Drivers/MysqlDriver.php`
- Tests:
  - `tests/Unit/MysqlDriverTest.php`
- Acceptance:
  - restore fails when target changes between plan and execution
  - restore succeeds when snapshot remains stable

### A04 - Strengthen restore verification provenance matching
- Scope: verification signal correctness.
- Changes:
  - require provenance binding for verification lookup:
    - driver
    - database identity
    - artifact fingerprint/path or backup label
    - pgBackRest stanza/repository where applicable
- Files:
  - `src/Services/RestoreSafetyGuard.php`
  - `src/Drivers/PgDumpDriver.php`
  - `src/Drivers/MysqlDriver.php`
  - `src/Drivers/PgBackRestDriver.php`
- Tests:
  - `tests/Feature/RestoreSafetyGuardTest.php`
  - `tests/Unit/PgDumpDriverTest.php`
  - `tests/Unit/MysqlDriverTest.php`
- Acceptance:
  - mismatched provenance cannot satisfy verified-backup requirement
  - matching provenance passes safely

### A05 - Enforce timeout invariants
- Scope: queue/driver timeout contract.
- Changes:
  - validate timeout relationship across queue and driver configs
  - fail config when driver timeout exceeds allowed worker budget
- Files:
  - `src/Services/ConfigValidator.php`
  - `README.md`
- Tests:
  - `tests/Feature/ConfigValidatorCoverageTest.php`
- Acceptance:
  - invalid timeout combinations are rejected with actionable messages
  - valid combinations continue to pass

### A06 - Private temp artifact strategy
- Scope: secret/output handling on disk.
- Changes:
  - add package-controlled temp directory config
  - avoid shared default temp locations for sensitive artifacts
  - enforce existence/permissions expectations
- Files:
  - `config/checkpoint.php`
  - `src/Services/ConfigValidator.php`
  - `src/Services/CommandOutputStore.php`
  - `src/Drivers/MysqlDriver.php`
  - `src/Drivers/PgBackRestDriver.php`
- Tests:
  - `tests/Unit/MysqlDriverTest.php`
  - `tests/Unit/PgBackRestDriverTest.php`
  - `tests/Unit/ShellCommandDriverTest.php`
- Acceptance:
  - temp artifacts use configured private path
  - fallback behavior is explicit and tested

### A07 - MySQL redaction parity tests
- Scope: credential leak prevention for mysql command lines.
- Changes:
  - add tests mirroring pgdump/shell redaction cases
  - extend redactor only if gaps are proven
- Files:
  - `tests/Unit/MysqlDriverTest.php`
  - (optional) `src/Services/CommandLineRedactor.php`
- Acceptance:
  - mysql command lines redact inline, flag-separated, and URI credentials

---

## Phase B - P1 Reliability and Operability

### B01 - Heartbeat-based liveness and stale-run recovery
- Files:
  - `src/Console/HealthCheckCommand.php`
  - `src/Jobs/ProcessCommandRunJob.php`
  - `src/Models/CommandRun.php` (and migration if needed)
  - `src/Services/OrphanCommandRunRecovery.php`
- Tests:
  - `tests/Feature/HealthCheckCommandTest.php`
  - `tests/Feature/ProcessCommandRunJobTest.php`
- Acceptance:
  - long-running healthy jobs are not false-failed
  - stale runs without heartbeat are reliably identified

### B02 - Scheduler lock-store safety validation
- Files:
  - `src/Services/ConfigValidator.php`
  - `README.md`
- Tests:
  - `tests/Feature/ConfigValidatorCoverageTest.php`
- Acceptance:
  - non-local environments reject unsafe scheduler lock store drivers

### B03 - PITR verification chain binding
- Files:
  - `src/Services/RestoreSafetyGuard.php`
  - `src/Drivers/MysqlDriver.php`
  - `src/Models/CommandRun.php` metadata usage
- Tests:
  - `tests/Feature/RestoreSafetyGuardTest.php`
  - `tests/Unit/MysqlDriverTest.php`
- Acceptance:
  - PITR verification must match baseline + binlog chain context

### B04 - Alert dedupe and rate limiting
- Files:
  - `src/Services/OperationalReportBuilder.php`
  - `config/checkpoint.php`
  - `README.md`
- Tests:
  - `tests/Feature/OperationalReportCommandTest.php`
- Acceptance:
  - repeated conditions generate bounded, actionable alerts

### B05 - Doctor hardening checks for restore posture
- Files:
  - `src/Console/DoctorCommand.php`
  - `src/Services/OperationalReportBuilder.php`
  - `tests/Fixtures/commands/doctor*.json`
- Tests:
  - `tests/Feature/DoctorCommandTest.php`
  - `tests/Feature/CommandJsonFixtureTest.php`
- Acceptance:
  - doctor explicitly reports unsafe restore posture in non-local environments

---

## Phase C - P2 Auditability, Scale, and Upgrade Safety

### C01 - Immutable restore decision event stream
- Files:
  - new audit model/table + migration
  - `src/Services/RestoreSafetyGuard.php`
  - `src/Jobs/ProcessCommandRunJob.php`
- Tests:
  - feature tests for append-only restore audit history
- Acceptance:
  - each restore guard decision is captured as append-only audit evidence

### C02 - Command run query/index hardening
- Files:
  - migration(s) for high-value indexes
  - `src/Services/OperationalReportBuilder.php`
  - `src/Console/StatusCommand.php`
- Tests:
  - migration + report/status behavior tests
- Acceptance:
  - hot-path report/doctor/status queries use intended indexes

### C03 - High-volume load validation suite
- Files:
  - targeted stress tests/fixtures
- Tests:
  - large-run dataset tests for health/report/recovery correctness
- Acceptance:
  - behavior remains correct and within expected query/processing bounds

### C04 - Upgrade safety and staged enforcement guide
- Files:
  - `UPGRADING.md`
  - `README.md`
  - `CHANGELOG.md` (when release-ready)
- Tests:
  - docs/fixture updates where needed
- Acceptance:
  - operators have a clear migration path for stricter defaults and validation

---

## Task Dependency Graph

- A01 -> A05, B05
- A02 -> B05
- A03 -> A04
- A04 -> B03
- A06 -> B01, C03
- A07 -> A04
- B01 -> C03
- B02 -> B05
- B03 -> C01
- B04 -> C03
- C02 -> C03

## PR Slicing Guidance (Atomic)

- PR 1: A07 (tests only, redaction parity)
- PR 2: A03 (mysql TOCTOU parity)
- PR 3: A04 (verification provenance hardening)
- PR 4: A05 (timeout invariant validation)
- PR 5: A06 (private temp strategy)
- PR 6: A01 + docs
- PR 7: A02 (prod boot validation)
- PR 8+: B/C phases one task per PR unless tightly coupled

## Verification Protocol Per Task

1. Run narrowest relevant tests for touched module(s).
2. Run broader feature tests for restore/doctor/report surfaces if affected.
3. Run full `vendor/bin/pest` before merging each phase boundary.
4. Re-run adversarial review after each phase completion.

## Explicit Non-Goals (for this plan)

- Adding new backup engines beyond existing drivers.
- Building managed control-plane/orchestrator features.
- Replacing Laravel queue/scheduler primitives with external workflow engines.
