# Changelog

## [0.1.1](https://github.com/adityaa-codes/laravel-checkpoint/compare/v0.1.0...v0.1.1) (2026-05-29)


### Miscellaneous Chores

* gitignore IDE configs and tool files, clean disk bloat ([c3bffa0](https://github.com/adityaa-codes/laravel-checkpoint/commit/c3bffa0ea9cf341bdb460ba235516e373cbf4303))
* remove stale PLAN.md and rector-only-ours.php ([b09c035](https://github.com/adityaa-codes/laravel-checkpoint/commit/b09c0359330cadd19ebc931337e6717120003ff1))

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
