---
sidebar_position: 1
---

# Restore Guardrails

Restore is the riskiest operation Checkpoint can perform. It is blocked by default unless your config explicitly allows it.

## Gate profiles

Checkpoint uses environment-aware gate profiles that control how strictly safety and evidence rules are enforced:

| Profile | Behaviour |
|---|---|
| `local` | Warnings only. Restore allowed without confirmation. |
| `staging` | Warnings and safety gates. Restore requires confirmation. |
| `production` | Full enforcement. Restore blocked without verified backups and confirmation. |

The profile is auto-detected from `app()->environment()` and resolved through this order:

1. `--policy-profile=<name>` (highest priority)
2. `checkpoint.gates.override_profile` (config key)
3. `checkpoint.gates.environment_profile_map[APP_ENV]`
4. `checkpoint.gates.default_profile` (default: `production`)

## Current restore controls

Set in `config/checkpoint.php` under the `restore` key:

- `restore.allowed_environments` — env names where restore is permitted (env: `CP_RESTORE_ALLOWED_ENVIRONMENTS`, default: `local,testing,staging`)
- `restore.allowed_databases` — specific database names allowed as restore targets (empty = allow all)
- `restore.allow_in_ci` — bypass confirmation in CI (default: `false`)
- `restore.require_verified_backup` — only allow restore from verified backup signals (default: `false`)
- `restore.confirmation_phrase` — phrase to type before restore executes (default: `RESTORE`)
- `restore.blast_radius.enabled` — enable blast radius analysis before destructive operations (default: `true`)
- `restore.blast_radius.warn_score` — score threshold for warnings (default: 50)
- `restore.blast_radius.block_score` — score threshold for blocking (default: 80)
- `restore.verification.mode` — post-restore verification: `moderate` or `full` (default: `moderate`)

## Blast radius analysis

Before a restore runs, Checkpoint evaluates the potential impact:

- which databases and tables are in scope
- a weighted risk score based on table criticality
- `warn_score` threshold triggers a warning
- `block_score` threshold prevents execution

High-risk restores (targeting production `users` or `orders` tables) can be blocked before a single command runs.

## What this means

- restore is not your first setup step — get backups working first
- CI restore is blocked by default
- non-local environments require confirmation
- blast radius analysis can warn or block before any command executes

## Recommended approach

1. Get backups working
2. Confirm status and health output look clean
3. Run a drill to prove your restore path
4. Configure allowed environments
5. Test restore in a safe environment

## Replication controls

Replication has its own safety controls in `config/checkpoint.php`. Replication is dry-run by default. Relevant config keys under `config/checkpoint.php ... replication` if you extend the package.
