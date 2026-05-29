# Changelog

## v0.1.0 — First release

Initial release of `adityaa-codes/laravel-checkpoint`.

### What it does
- Queue-based database backups for PostgreSQL and MySQL
- Point-in-time recovery (PITR) via WAL and binlog replay
- Recovery drills with post-restore verification
- Replication engine for production-to-staging sync
- 25+ health checks across config, binaries, queue, drills, and restore posture
- 12 Artisan commands (`checkpoint:*` prefix)
- Spatie-style notification system (mail, Slack, Telegram)
- JSON output contracts for CI and automation
- Blast radius scoring for restore safety
- Gate policy profiles for CI exit codes
- Heartbeat-based orphan recovery via `checkpoint:sweep`
- Guided install wizard with auto-detection

### Requirements
- PHP 8.3+
- Laravel 12 or 13
- MySQL or PostgreSQL
