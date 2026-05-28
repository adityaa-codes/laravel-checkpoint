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
- 12 Artisan commands with `checkpoint:*` prefix

## Conventions
- All env vars use `CP_*` prefix
- Gate profiles auto-detected from `app()->environment()`
- Restore requires confirmation in non-local environments
- All shell commands use Symfony Process array args — no string concatenation

## Key commands
| Command | Purpose |
|---|---|
| `checkpoint:install` | Guided setup, auto-detects database driver |
| `checkpoint:status --health` | Health diagnostics |
| `checkpoint:status` | Recent run history with `--watch` polling |
| `checkpoint:status --full` | Consolidated operational report |
| `checkpoint:backup` | Run a logical backup |
| `checkpoint:drill` | Run a backup drill |
| `checkpoint:replicate` | Production→staging sync |
| `checkpoint:migrate-from-spatie` | Migrate from spatie/laravel-backup |

Everything is free and open source. No premium tiers.
