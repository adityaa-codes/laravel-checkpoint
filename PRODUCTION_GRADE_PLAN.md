# Production-Grade Implementation Plan

## Purpose

Harden `laravel-checkpoint` from a functional package into a production-grade
backup orchestration package that is safe for large and long-running database
operations, including very large PostgreSQL clusters.

This plan is based on:

- current package implementation
- Laravel 12 queue and scheduler guidance
- PostgreSQL current `pg_dump` guidance
- current pgBackRest documentation

## Status Legend

- `[x]` complete
- `[-]` partial
- `[ ]` not started
- `[!]` risk or known production gap

## Current Status Snapshot

### What Exists Today

- `[x]` queue-based command-run orchestration
- `[x]` shell driver abstraction
- `[x]` command catalog and command/event model
- `[x]` health-check, orphan recovery, prune, doctor commands
- `[x]` good automated quality baseline: Pest, Pint, PHPStan, Rector
- `[x]` test coverage at `90.0%`

### Current Production Gaps

- `[ ]` queue timeout / retry contract is now enforced in code, but production worker guidance still needs wider operator rollout
- `[x]` backup execution now has first-class pgBackRest and pgDump drivers alongside the shell escape hatch
- `[ ]` scheduler overlap and cluster guards are now implemented
- `[!]` there is no structured backup state, retention state, or verification model beyond raw command output
- `[!]` no object storage / multi-repository / encryption-first config model yet
- `[x]` huge-database logical export strategy now exists through the dedicated `pgdump` driver

## Key Learnings

### Laravel 12 Operational Learnings

1. Queue `retry_after` must be longer than the worker timeout.
   If the timeout exceeds `retry_after`, the same backup job can be attempted twice.

2. Unique jobs are only as strong as the cache lock backend.
   For production, uniqueness and overlap control should use a distributed cache backend such as Redis, not ad hoc defaults.

3. Scheduled jobs that should only run once per cluster need both overlap and cluster guards.
   `withoutOverlapping()` prevents local duplication; `onOneServer()` prevents multi-node duplication.

### PostgreSQL Learnings

1. `pg_dump` is not the preferred regular production backup mechanism for huge databases.
   It remains useful for logical exports and migrations, but not as the primary disaster-recovery path for large clusters.

2. If logical dumps are needed at scale, PostgreSQL’s directory format is the right mode.
   It is the only format that supports parallel dumps with `-j`.

### pgBackRest Learnings

1. pgBackRest is the right production-grade path for large PostgreSQL systems.
   It is designed for full, differential, and incremental backups; large datasets; and parallel operations.

2. pgBackRest exposes the production controls this package currently lacks:
   - `process-max`
   - `resume`
   - `start-fast`
   - retention policies
   - verification and integrity checks
   - multiple repositories
   - object-store backends
   - encryption / TLS verification settings

3. The package should model pgBackRest as structured configuration, not just string templates.

## Production Strategy

### Strategy 1: Make Job Execution Correct Before Making It Bigger

First fix queue correctness, job uniqueness, retry semantics, and scheduler overlap.
This removes the risk of duplicate destructive operations before adding more power.

### Strategy 2: Promote pgBackRest to a First-Class Driver

Keep `ShellCommandDriver` for generic escape hatches, but make a structured
`PgBackRestDriver` the recommended production driver for PostgreSQL.

### Strategy 3: Separate Logical Export Workflows From DR Workflows

Treat logical dump/export as a distinct workflow:

- schema/data export
- tenant export
- migration support
- audit/archive copy

Treat disaster recovery and PITR as pgBackRest-native workflows.

### Strategy 4: Persist Structured Operational State

Do not rely on raw command output as the main source of truth.
Store structured metadata for:

- backup set identity
- backup type
- repository
- verification status
- retention state
- restore target
- throughput / duration
- tool exit status

### Strategy 5: Build for Clustered Production From the Start

Assume:

- multiple queue workers
- multiple app nodes
- long-running operations
- Redis or equivalent shared cache
- remote repositories and object storage

## Implementation Methods

### Method A: Safe Rollout by Layers

Implement in this order:

1. execution correctness
2. pgBackRest driver and structured config
3. scheduler / locking hardening
4. observability and verification
5. scale and storage features
6. performance / chaos / large-dataset validation

### Method B: Introduce New Driver, Do Not Mutate the Shell Driver Into Everything

`ShellCommandDriver` should remain minimal and generic.
Production PostgreSQL features should live in a dedicated driver with typed config.

### Method C: Encode Production Rules in Tests and Doctor Checks

Every production assumption should be enforced by:

- config validation
- doctor output
- architecture or feature tests

### Method D: Prefer Explicit Failure Over Silent Misconfiguration

Bad production config should fail early:

- invalid timeout/retry ratios
- missing distributed lock backend
- missing pgBackRest binary
- missing repository config
- unsafe restore config

## Target Architecture

### Driver Layer

- `ShellCommandDriver`
  - generic fallback
  - best for custom operations and non-Postgres environments

- `PgBackRestDriver`
  - production PostgreSQL driver
  - structured commands for backup, restore, check, info, verify

- future optional:
  - `PgDumpDriver`
  - `SnapshotDriver`
  - `MySqlDumpDriver`

### Runtime Safety Layer

- distributed unique locks via Redis-capable cache store
- scheduler overlap protection
- restore-operation safety gates
- explicit job timeout / retry modeling

### Persistence Layer

- `CommandRun` remains orchestration record
- add structured backup-report state, either:
  - new columns on `command_runs`, or
  - dedicated `backup_run_reports` table

### Operator Layer

- doctor checks
- verification status reporting
- stale backup alerting hooks
- restore readiness checks

## Phase Plan

### Phase 0: Queue and Scheduler Correctness

Goal:
Make current execution safe for long-running production jobs.

Status:
- `[x]` complete

Tasks:

- `[x]` validate that `checkpoint.queue.retry_after` is greater than `checkpoint.queue.timeout`
- `[x]` fail doctor/config validation when timeout and retry settings are unsafe
- `[x]` add explicit config docs for worker `--timeout` alignment
- `[x]` support `uniqueFor` on `ProcessCommandRunJob`
- `[x]` support `uniqueVia()` with configurable cache store
- `[x]` document Redis as the recommended production lock backend
- `[x]` add scheduler `withoutOverlapping()` for backup, prune, health, orphan recovery
- `[x]` add scheduler `onOneServer()` for clustered production deployments
- `[x]` add tests for invalid timeout/retry config
- `[x]` add tests for scheduled overlap/cluster protection

Acceptance:

- long-running jobs cannot be re-processed due to bad timeout settings
- scheduled backup flows cannot double-run in a multi-node deployment

### Phase 1: First-Class pgBackRest Driver

Goal:
Replace free-form PostgreSQL production backup handling with structured pgBackRest behavior.

Status:
- `[x]` complete

Tasks:

- `[x]` add `PgBackRestDriver`
- `[x]` define typed config for:
  - `[x]` stanza
  - `[x]` repo
  - `[x]` backup type: full/diff/incr
  - `[x]` `process-max`
  - `[x]` `resume`
  - `[x]` `start-fast`
  - `[x]` `backup-standby`
  - `[x]` `checksum-page`
  - `[x]` verify/check/info options
- `[x]` add operations to catalog for:
  - `[x]` `pgbackrest_backup_full`
  - `[x]` `pgbackrest_backup_diff`
  - `[x]` `pgbackrest_backup_incr`
  - `[x]` `pgbackrest_restore`
  - `[x]` `pgbackrest_verify`
  - `[x]` `pgbackrest_check`
  - `[x]` `pgbackrest_info`
- `[x]` add typed placeholder-free command building
- `[x]` add structured parsing for `info` and `check` output where possible
- `[x]` add tests for command construction, backup type selection, and failure behavior
- `[x]` add doctor checks for pgBackRest binary presence and config completeness

Acceptance:

- PostgreSQL production backups no longer depend on raw template strings
- pgBackRest becomes the recommended production driver

### Phase 2: Huge-Database Logical Export Strategy

Goal:
Support logical export as a separate large-database workflow, not as the main DR system.

Status:
- `[x]` complete

Tasks:

- `[x]` add optional `PgDumpDriver`
- `[x]` support directory format export configuration
- `[x]` support parallel job count for logical dumps
- `[x]` support compression and output target rules
- `[x]` add explicit operator guidance that logical dumps are not the primary DR strategy
- `[x]` add catalog operations for logical export/import where appropriate
- `[x]` add tests for large-export command building

Acceptance:

- huge logical dumps use `pg_dump -Fd -j`
- logical export has a clear purpose distinct from backup/restore/PITR

### Phase 3: Structured Backup Metadata and Verification

Goal:
Turn command runs into actionable backup records.

Status:
- `[ ]` not started

Tasks:

- `[ ]` decide persistence model:
  - `[ ]` extend `command_runs`
  - `[ ]` or add `backup_run_reports`
- `[ ]` persist backup type, repo, label, stanza, verification state, and restore target
- `[ ]` persist backup size, duration, throughput, and completion timestamps
- `[ ]` persist verification status from pgBackRest check/verify flows
- `[ ]` expose last-known-good backup state in queries and status command
- `[ ]` add tests for metadata persistence and query behavior

Acceptance:

- operators can determine backup health without reading raw command output

### Phase 4: Storage, Security, and Repository Hardening

Goal:
Make the package safe for real repositories and remote storage.

Status:
- `[ ]` not started

Tasks:

- `[ ]` add config support for multiple repositories
- `[ ]` add config support for S3/object-store repositories
- `[ ]` add config support for repo encryption and TLS verification
- `[ ]` redact secrets from stored command lines and logs
- `[ ]` add doctor checks for required repo settings
- `[ ]` add tests proving sensitive config never lands in persisted command lines

Acceptance:

- package can operate against secure remote repositories without leaking credentials

### Phase 5: Restore Safety and Production Guardrails

Goal:
Reduce blast radius for destructive operations.

Status:
- `[ ]` not started

Tasks:

- `[ ]` make restore operations require explicit confirmation strategy in non-CI contexts
- `[ ]` add restore target validation for PITR-style operations
- `[ ]` require a valid pre-restore verification signal before restore when configured
- `[ ]` add environment-level restore safety flags
- `[ ]` add guardrails for restoring into the wrong database or environment
- `[ ]` add failure-path tests for all restore blocks

Acceptance:

- destructive restore flows cannot execute casually or with incomplete state

### Phase 6: Observability and Operations

Goal:
Make backup health visible and operable at scale.

Status:
- `[ ]` not started

Tasks:

- `[ ]` add machine-readable `doctor` output mode
- `[ ]` add structured log context for run ids, repo, stanza, type, duration
- `[ ]` add events or hooks for stale backup alarms
- `[ ]` add age-of-last-success checks
- `[ ]` add backup duration anomaly checks
- `[ ]` add queue lag and orphan metrics hooks
- `[ ]` add operator-facing status summary command

Acceptance:

- the package can be monitored and alerted on in production

### Phase 7: Performance, Scale, and Failure Testing

Goal:
Prove the package is safe under production stress.

Status:
- `[ ]` not started

Tasks:

- `[ ]` add large-dataset execution tests where feasible
- `[ ]` add concurrency tests for unique backup operations
- `[ ]` add tests for duplicate dispatch under multiple workers
- `[ ]` add timeout / crash / partial-failure recovery tests
- `[ ]` add resume-flow tests for pgBackRest backup retries
- `[ ]` document operational load-testing procedure

Acceptance:

- package behavior is proven under long-running and partially failing workloads

## Comprehensive Task List

### Immediate Priority

- `[ ]` fix timeout vs retry contract
- `[ ]` add Redis-backed unique lock strategy
- `[ ]` harden scheduler overlap and cluster behavior
- `[ ]` scaffold `PgBackRestDriver`
- `[ ]` add typed pgBackRest config

### Near-Term Production Milestones

- `[ ]` add structured backup metadata
- `[ ]` add verify/check/info parsing and reporting
- `[ ]` add remote repository and encryption support
- `[ ]` add restore safety gates
- `[ ]` add machine-readable doctor output

### Scale Milestones

- `[ ]` add huge-database logical export mode with `pg_dump -Fd -j`
- `[ ]` add multi-repo support
- `[ ]` add long-run stress and concurrency tests
- `[ ]` add operational guidance for large clusters

## Recommended Next Implementation Step

Start with **Phase 0** and execute these in one slice:

1. fix queue timeout / `retry_after` validation
2. add doctor failures for unsafe queue settings
3. add scheduler `withoutOverlapping()` and `onOneServer()`
4. add Redis-backed uniqueness support to `ProcessCommandRunJob`

Then start **Phase 1** immediately after with the `PgBackRestDriver` scaffold.

## Source Notes

Primary references used for this plan:

- Laravel 12 queues: <https://laravel.com/docs/12.x/queues>
- Laravel 12 scheduling: <https://laravel.com/docs/12.x/scheduling>
- PostgreSQL current `pg_dump` docs: <https://www.postgresql.org/docs/current/app-pgdump.html>
- pgBackRest site: <https://pgbackrest.org/>
- pgBackRest command reference: <https://pgbackrest.org/command.html>
- pgBackRest user guide: <https://pgbackrest.org/user-guide.html>
