# Laravel Checkpoint — Development Plan

## Identity

Laravel Checkpoint is a **Database Reliability Layer** for Laravel — a business continuity
system, not a backup tool. It provides queue-based backup, restore, PITR, recovery drills,
and multi-tier safety gates.

Core philosophy: **"Schrödinger's Backup"** — the condition of any backup is unknown until
you try to restore it. Checkpoint exists to make recovery proven, not assumed.

## Architecture

### Driver Layer
- `BackupDriver` interface: single `execute(CommandRun): void` contract
- Drivers: `MysqlDriver`, `PgDumpDriver`, `PgBackRestDriver`, `ShellCommandDriver`, `FakeDriver`
- Config-driven resolution: `config('checkpoint.drivers.{name}.class')`
- All shell commands use Symfony Process array args — no string concatenation

### Runtime Safety
- Queue orchestration via `EnqueueCommandRunAction` → `ProcessCommandRunJob`
- `ShouldBeUnique` prevents concurrent destructive operations
- `RestoreSafetyGuard` with environment allowlists, database allowlists, confirmation
- Scheduler `withoutOverlapping()` + `onOneServer()` for clustered deployments

### Persistence
- `CommandRun` model with MassPrunable, status lifecycle (Pending→Running→Succeeded/Failed)
- `BackupDrillRun` model with RTO/RPO tracking
- Dedicated `db-ops` queue with timeout/retry invariants

### Observability
- `checkpoint:doctor` — binary/config health checks
- `checkpoint:status` — run status with table/json/agent output
- `checkpoint:report` — operational summary with trends and alarms
- Events: `BackupQueued`, `BackupStarted`, `BackupCompleted`, `BackupFailed`, `BackupDrillCompleted`

### CLI Surface
`checkpoint:install`, `checkpoint:doctor`, `checkpoint:status`, `checkpoint:report`,
`checkpoint:backup`, `checkpoint:restore`, `checkpoint:drill`, `checkpoint:prune`,
`checkpoint:recover-orphans`, `checkpoint:health-check`, `checkpoint:migrate-from-spatie`,
`checkpoint:catalog-export`, `checkpoint:record-drill`, `checkpoint:test`

Output modes: `table` (human), `json` (full envelope), `agent` (compact), `compact-json` (null-stripped).

## Current State

| Area | Status |
|------|--------|
| Driver pattern (MySQL, PgDump, PgBackRest, Shell, Fake) | Complete |
| Queue orchestration with safety guards | Complete |
| Restore guardrails (allowlists, confirmation, verification) | Complete |
| Structured metadata & audit trail | Complete |
| Health checks, orphan recovery, pruning | Complete |
| Config validation & doctor diagnostics | Complete |
| Test coverage (90%+, Pest, PHPStan level max, Pint) | Complete |
| Documentation website | Complete |
| Scheduler hardening | Complete |
| pgBackRest typed config & remote repos | Complete |
| Logical export workflow (`pg_dump -Fd -j`) | Complete |

## Implementation Phases

### Phase 1: Preflight Strictness (P0)

**Problem:** Install preflight requires ALL binaries for a driver, even for basic use.
MySQL backup-only users get blocked on `mysqlbinlog`.

**Tasks:**
- Split preflight into `required` vs `optional` binary tiers per driver
- `mysql`: required=`[mysqldump, mysql]`, optional=`[mysqlbinlog]`
- `postgres`: required=`[pg_dump, pg_restore]`, optional=`[pgbackrest]`
- Add `--allow-missing-optionals` flag to `checkpoint:install`
- Add per-operation binary validation at queue time (not install time)
- Update `checkpoint:doctor` to separate required from optional binary status

### Phase 2: Output Quality (P1)

**Problem:** JSON output includes null/empty fields for inapplicable data. A fresh install
returns 40+ lines of null drill fields. Brief mode uses prose instead of copy-paste commands.

**Tasks:**
- Strip null-valued keys from JSON output by default
- Add `?verbose=1` to `--agent` mode (compact by default, verbose on request)
- Collapse drill fields into a single `drill: {status: "no_data"}` when no drills exist
- Shorten `--brief` suggestions to copy-paste-able commands
- Add `--format=compact-json`

### Phase 3: Config Simplification (P1)

**Problem:** Config is 370 lines. Timeout chain requires 4 env vars with invariant math.
PHP placeholder for backup command blocks real use until replaced.

**Tasks:**
- Add `DB_OPS_DRIVER_AUTO_DETECT` mode (selects driver from `DB_CONNECTION`)
- Simplify timeout chain: single `DB_OPS_TIMEOUT` that auto-computes 4 dependent values
- Replace PHP placeholder with explicit "not configured" error at queue time
- Add `checkpoint:config:validate` for timeout ratio checks

### Phase 4: Streaming Backup Pipeline (P1)

**Problem:** `pgdump`/`mysql` drivers write to local disk first. A 500GB DB on a 50GB
instance is impossible. `pgbackrest` already streams to S3; other drivers lack parity.

**Design:** Pipe `pg_dump`/`mysqldump` stdout → ring buffer → `Storage::writeStream()` →
multipart upload to S3/R2/GCS. Zero bytes on local disk.

**Tasks:**
- Add `artifact` config section (disk, path_prefix, chunk_bytes, stream_buffer)
- Create `StreamArtifactToDisk` service
- Update `PgDumpDriver` to use `--file=-` (stdout) when remote disk configured
- Update `MysqlDriver` to use stdout streaming
- Update `ShellCommandDriver` to support `{artifact_stream}` placeholder
- Register artifact location in `CommandRun.metadata.artifact`
- Add `checkpoint:artifact:list` command
- Test with S3, R2, local disks; verify 5GB dump streams with ≤64MB memory

### Phase 5: Tiered Verification Ladder (P1)

**Problem:** Existing verification is a governance check (did restore get approved?), not a
data-integrity check (is the backup valid?). Full restore verification is 2× disk + hours.

**Five-tier ladder:**
- **Tier 1** — Archive structure check (1s, 0B): `pg_restore -l` or `head`+pattern match
- **Tier 2** — Schema-only dry-run (5s, ~1MB): restores DDL only to scratch DB
- **Tier 3** — Row-count metadata capture (1s, 0B): compare backup-time counts to dump sizes
- **Tier 4** — Full streaming parse (minutes, 0B): parse every byte to `/dev/null`
- **Tier 5** — Isolated restore + assertions (hours, 1× DB size): full round-trip verification

**Tasks:**
- Create `VerifyArtifactIntegrity` action (orchestrates Tiers 1-4)
- Implement each tier per driver (pgdump, mysql, pgbackrest, shell)
- Add `checkpoint:verify {artifact?} {--tier=}` command
- Add verification status to `checkpoint:status --summary` output
- Add `tier` column to `VerificationRun` model

### Phase 6: Migration Squashing (P2)

**Tasks:**
- Create squashed migration combining all incremental migrations
- Keep existing incremental migrations for upgrade path
- Publish squashed migration for fresh installs, incremental for upgrades

### Phase 7: CLI Refinements (P2)

**Tasks:**
- Add `checkpoint:test` — smoke command (install + migrate + backup + doctor), ideal for CI
- Add `checkpoint:quick-backup` — convenience wrapper (enqueue + work + status)
- Add verbosity control to `--brief` (-v shows passing, -vv shows raw output)
- Support `--watch` flag on `checkpoint:status` for polling
- Add bash/zsh completion stub

### Phase 8: Spatie Migration Completion (P2)

**Tasks:**
- Detect and suggest Slack/Discord webhook migration
- Map Spatie retention tiers to checkpoint equivalents
- Offer to comment out Spatie schedule entries and append checkpoint equivalents
- Auto-run `checkpoint:install --preset=minimal` after migration

### Phase 9: Driver Documentation (P2)

**Tasks:**
- Add "Production Notes" to each driver page (upstream tool warnings)
- Add "When to upgrade from pgdump to pgbackrest" guide
- Document database-specific dump options supported

### Phase 10: MySQL Support (P1)

Complete MySQL driver with feature parity for backup, restore, PITR, and drills.

**Operation mapping:**
- `logical_backup` → `mysqldump ... > artifact.sql`
- `logical_restore_file` → `mysql < artifact.sql`
- `logical_restore_latest` → resolve latest artifact, then `mysql`
- `pitr_restore` → base restore + `mysqlbinlog --stop-datetime=... | mysql`
- `backup_drill` → configurable MySQL restore validation

**Tasks:**
1. Define MySQL config schema in `config/checkpoint.php`
2. Add config validation for MySQL keys
3. Implement `MysqlDriver` skeleton with full execute lifecycle parity
4. Implement logical backup command builder (`mysqldump` argv, no shell interpolation)
5. Implement logical restore command builders
6. Implement PITR command builder (bounded binlog replay)
7. Integrate backup drill semantics
8. Extend doctor/health reporting for MySQL
9. Add/expand tests (unit: command builders, feature: enqueue + guardrails + reports)
10. Update docs with MySQL setup, PITR requirements, security notes

### Phase 11: Hardening — Safety Guardrails (P0)

**Tasks:**
- Flip unsafe restore defaults: `allow_in_ci=false`, `require_verified_backup=true` (non-local)
- Enable production-time config validation (fail fast on invalid config)
- Add MySQL restore TOCTOU snapshot revalidation (parity with pgdump)
- Strengthen restore verification provenance matching (driver, db identity, artifact fingerprint)
- Enforce timeout invariants (driver timeout ≤ worker budget)
- Private temp artifact strategy (package-controlled temp dir, not shared defaults)
- Add MySQL redaction parity tests (inline, flag-separated, URI credentials)

### Phase 12: Hardening — Reliability (P1)

**Tasks:**
- Heartbeat-based liveness for long-running jobs
- Scheduler lock-store safety validation (reject unsafe drivers in non-local)
- PITR verification chain binding (baseline + binlog context)
- Alert dedupe and rate limiting
- Doctor hardening checks for unsafe restore posture

### Phase 13: Hardening — Auditability & Scale (P2)

**Tasks:**
- Immutable restore decision event stream (append-only audit)
- Command run query/index hardening for hot report/status paths
- High-volume load validation suite
- Upgrade safety and staged enforcement guide
- Add missing indexes for `verified_at`, restore-failure lookups, drill rolling windows
- Add drill retention configuration and pruning
- Secret handling: ensure pgBackRest secrets never appear in process argv
- Constrain pgDump restore inputs to managed artifact roots
- Structured machine health contracts (stable check IDs, split prose from machine fields)

### Phase 14: Notification Extensibility (P3)

**Tasks:**
- Document custom notification channel via `CheckpointChannel` contract
- Add Slack and Discord webhook providers
- Support per-event channel routing (`backup.failed→[mail,slack]`)

### Phase 15: Polish (P3)

**Tasks:**
- Add queue name collision detection (warn if `db-ops` ≈ `default`)
- Add `checkpoint:restore:dry-run` command
- Update Spatie migration gap notes for file backup loss
- Add confirmation phrase vs. token guidance in docs
- pgdump directory format: enumerate `.dat.gz` files in Tier 1 verification

## Priority Summary

| Phase | Priority | Impact |
|-------|----------|--------|
| 1. Preflight strictness | **P0** | Unblocks mysql/postgres presets for basic use |
| 10. MySQL support | **P1** | Full MySQL parity |
| 2. Output quality | **P1** | 60% reduction in JSON noise |
| 3. Config simplification | **P1** | Cuts required env vars 7→3 |
| 4. Streaming pipeline | **P1** | Unlocks large-DB backups |
| 5. Tiered verification | **P1** | Proven backup health |
| 11. Hardening — guardrails | **P0** | Production safety defaults |
| 12. Hardening — reliability | **P1** | Operability at scale |
| 6. Migration squashing | **P2** | Fresh installs get 1 migration |
| 7. CLI refinements | **P2** | `checkpoint:test` for CI |
| 8. Spatie migration | **P2** | Complete migration coverage |
| 9. Driver docs | **P2** | Upstream tool docs links |
| 13. Hardening — auditability | **P2** | Immutable audit trail |
| 14. Notifications | **P3** | Slack/Discord support |
| 15. Polish | **P3** | Dry-run, directory verification |

## Reference

### MySQL Guidelines
- PITR depends on binary logging being enabled and accessible
- Typical PITR flow: restore baseline backup, then replay binlogs with `mysqlbinlog` to bounded target
- `mysqldump` is useful for logical backups but can be slow at scale
- Design should keep extension points for physical/enterprise backup tooling
- References: https://dev.mysql.com/doc/refman/8.0/en/backup-and-recovery.html

### PostgreSQL Guidelines
- `pg_dump` is not the right choice for regular production backups
- Directory format (`-Fd`) is the only format supporting parallel dumps with `-j`
- pgBackRest is the recommended production path for large PostgreSQL systems
- References: https://www.postgresql.org/docs/current/app-pgdump.html

### Laravel Integration Guidelines
- All queue operations use dedicated `db-ops` queue
- `retry_after` must exceed worker timeout to prevent double-processing
- `withoutOverlapping()` + `onOneServer()` for clustered scheduler safety
- `afterCommit()` dispatch pattern ensures DB write before queue message

### Design Rules
- Keep operation names stable; swap implementation by driver
- Build commands as argv arrays (Symfony Process), not shell-concatenated strings
- Route all restore operations through `RestoreSafetyGuard`
- Persist structured metadata for auditability
- Keep output capture consistent with existing drivers
- Ensure secret redaction for CLI arguments and logged command lines
- Fail fast on invalid config with precise error messages
- Keep compatibility with queue uniqueness and scheduler overlap protections

### Common Mistakes to Avoid
- Treating PITR as "run mysqlbinlog" without a known baseline restore point
- Replaying binlogs without strict stop boundary
- Assuming path inputs are safe (missing traversal/symlink defenses)
- Marking runs succeeded when command output indicates partial failure
- Forgetting to update doctor/report checks after adding new required config
- Updating docs after code lands instead of in the same change
- Omitting tests for lock/queue uniqueness behavior under new driver mode
- Hardcoded shell chains — use Symfony Process with proper I/O streams
- Silent failures in health reporting — gracefully handle absence of data
- Assumed tenancy architecture — provide abstract interfaces for tenant resolution

### Development Workflow
1. Pick an atomic task from this plan
2. Create feature branch: `feat/short-description` or `fix/short-description`
3. Write test first (Pest `it()` block)
4. Implement following architecture rules
5. Run `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`
6. Commit using Conventional Commits: `feat(scope):` / `fix(scope):`
   - Scopes: `core`, `cli`, `driver`, `config`, `test`, `docs`

### Quality Gates
- **Pre-commit:** `vendor/bin/pint` + `vendor/bin/pest --stop-on-failure`
- **CI:** PHP 8.3–8.5 × Laravel 12–13 × prefer-stable/prefer-lowest (12 jobs)
- **Pre-release:** Full matrix green + PHPStan level max + no `@` suppression +
  no swallowed exceptions + no methods >50 lines

### Hard No's
- No facades in services/actions — constructor DI only
- No shell string concatenation — Symfony Process array args only
- No swallowed exceptions — always `report($e)` or `logger()->error()`
- No error suppression (`@`) — check return values
- No `config()->set()` in validators — validation reports, never mutates
- No `(new Model)->method()` — resolve from container or receive via DI
- No comments in source files — code is self-documenting
