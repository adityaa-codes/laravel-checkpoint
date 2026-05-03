Laravel Checkpoint — Database Reliability Layer for Laravel.

## What this package does
- Queue-based async database backups (PostgreSQL, MySQL/MariaDB)
- Point-in-Time Recovery (PITR) via WAL/binlog replay
- Automated recovery drills with restore verification
- Replication engine for production→staging data sync
- Multi-tier safety gates (local/staging/production profiles)
- json/agent/compact-json output for humans and AI agents
- Health checks: 25+ signals (config, binaries, queue, drill, posture)

## CLI surface (17 commands)
- checkpoint:install — guided setup with presets (minimal, mysql-prod, postgres-prod)
- checkpoint:doctor — health diagnostics (config, binaries, queue, safety)
- checkpoint:status — recent run history, --watch polling, -v/-vv verbosity
- checkpoint:report — consolidated operational report with gates
- checkpoint:enqueue — queue any supported operation
- checkpoint:enqueue-backup / checkpoint:enqueue-drill — convenience shortcuts
- checkpoint:replicate — replication sync with governance gates
- checkpoint:migrate-from-spatie — one-click migration from spatie/laravel-backup
- checkpoint:health-check — mark timed-out runs as failed
- checkpoint:pitr-readiness — PITR readiness audit
- checkpoint:prune / checkpoint:recover-orphans / checkpoint:retention-policy
- checkpoint:catalog-export — backup catalog as JSON/CSV
- checkpoint:record-drill — manual drill record
- checkpoint:test — CI smoke pipeline

## Architecture rules
- Commands are thin entry points → delegate to services/actions
- Drivers implement `BackupDriver` (one method: `execute(CommandRun $run): void`)
- Config-driven driver resolution: `config('checkpoint.drivers.{name}.class')`
- Models use atomic state transitions (`whereKey()->where()->update()`)
- Services receive dependencies via constructor DI (interfaces, not facades)
- No facades in services/actions; `config()`/`app()` only in providers/commands
- All process commands use Symfony Process with array args (no shell injection)

## Code conventions
- `declare(strict_types=1)` on every file
- `final class` / `final readonly class` on all classes
- PHP 8.3+ features: native enums, readonly, constructor promotion, match()
- No comments in source files (code is self-documenting)
- Never `@file_put_contents`, `@fopen`, `@unlink` — check return values
- Never `catch (Throwable)` without logging
- Methods never exceed 50 lines

## Testing
- Pest only — `it()` and `expect()`, no PHPUnit-style
- SQLite `:memory:` via Orchestra Testbench
- `checkpoint_artisan()` test helper for Artisan commands
- Frozen-time deterministic JSON fixtures in `tests/Fixtures/command-json/`
- Arch tests enforce: strict_types, final, no dd/dump, contracts are interfaces
- Every command: happy path + failure path + edge case
- Every driver: execute success + execute failure + metadata correctness

## Config conventions
- All env vars use `DB_OPS_*` prefix
- `DB_OPS_TIMEOUT` (default 3600) auto-computes queue.timeout, retry_after, unique_for
- Gate profiles: local/staging/production with safety+evidence gate rules
- Auto-detect driver from `DB_CONNECTION` when `DB_OPS_DRIVER` is unset

## Free vs Pro boundary
- Open source: backup, restore, prune, health, status, reporting, shell/pgdump/mysql drivers
- Pro: PITR, automated drills, replication, pgBackRest driver, tiered retention
- Pro gates via `class_exists(ProServiceProvider::class)` — no hard references
