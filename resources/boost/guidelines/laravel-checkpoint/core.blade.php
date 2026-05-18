Laravel Checkpoint — Database Reliability Layer for Laravel.
Make backup, restore, PITR, replication, and recovery-drill operations safe, auditable, and automation-friendly.

## Features
- Queue-based async database backups (PostgreSQL, MySQL/MariaDB)
- Point-in-Time Recovery (PITR) via WAL/binlog replay
- Automated recovery drills with restore verification
- Replication engine for production→staging data sync
- Multi-tier safety gates (local/staging/production profiles)
- JSON, agent, and compact-json output for humans and AI agents
- 25+ health checks (config, binaries, queue, drill, posture)
- 17 Artisan commands with `checkpoint:*` prefix

## Conventions
- All env vars use `DB_OPS_*` prefix
- `DB_OPS_TIMEOUT` (default 3600) auto-computes queue timeout chain
- Gate profiles auto-detected from `app()->environment()`
- Restore requires confirmation in non-local environments
- All shell commands use Symfony Process array args — no string concatenation

## Key commands
| Command | Purpose |
|---|---|
| `checkpoint:install` | Guided setup, auto-detects database driver |
| `checkpoint:doctor` | Health diagnostics |
| `checkpoint:status` | Recent run history with `--watch` polling |
| `checkpoint:report` | Consolidated operational report |
| `checkpoint:backup` | Run a logical backup |
| `checkpoint:drill` | Run a backup drill |
| `checkpoint:replicate` | Production→staging sync |
| `checkpoint:migrate-from-spatie` | Migrate from spatie/laravel-backup |

Everything is free and open source. No premium tiers.
