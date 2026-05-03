---
sidebar_position: 1
---

# Restore Guardrails

Restore is the riskiest operation the reliability layer can perform, so it is blocked by default unless your config explicitly allows it. Guardrails are the verification layer's safety net — they prevent accidents before they happen.

## Gate profiles

Checkpoint uses environment-aware gate profiles that determine how strictly safety and evidence rules are enforced:

| Profile | Exit codes | Behavior |
|---|---|---|
| `local` | 0, 2, 12 | Warnings only — restore allowed without confirmation |
| `staging` | 0, 2, 10, 11, 12 | Warnings + safety gates — restore requires confirmation |
| `production` | 0, 2, 10, 11, 12 | Full enforcement — restore blocked without verified backups and confirmation |

Exit code meanings:

- `0` → pass — all gates satisfied
- `2` → warning — non-blocking issues detected
- `10` → safety gate failed — restore blocked by safety rule
- `11` → evidence gate failed — restore blocked by missing evidence
- `12` → gate policy/config evaluation failed

The profile is auto-detected from `app()->environment()` and resolved through this order:

1. `--policy-profile=<name>` (highest priority)
2. `DB_OPS_GATE_PROFILE` / `checkpoint.gates.override_profile`
3. `checkpoint.gates.environment_profile_map[APP_ENV]`
4. `checkpoint.gates.default_profile`

## Blast radius analysis

Before any destructive operation runs, Checkpoint evaluates the potential impact:

- **What** — which databases and tables are in scope
- **Score** — weighted risk assessment based on table criticality
- **Thresholds** — `warn_score` triggers a warning; `block_score` prevents execution

This means high-risk restores (e.g. targeting production `users` or `orders` tables) can be blocked before a single command executes.

## Current restore controls

- `restore.allowed_environments`
- `restore.allowed_databases`
- `restore.require_confirmation`
- `restore.confirmation_phrase`
- `restore.confirmation_token`
- `restore.allow_in_ci`
- `restore.ci`
- `restore.require_verified_backup`
- `restore.blast_radius.enabled`
- `restore.blast_radius.warn_score`
- `restore.blast_radius.block_score`
- `restore.blast_radius.weights.*`

## What this means operationally

- restore is not meant to be your first setup step
- CI restore execution is blocked by default
- non-local environments usually require a verified backup
- some restore attempts can be warned or blocked before any command runs by blast radius analysis

## Recommended approach

1. get backups working first
2. confirm status and doctor output look healthy
3. run a recovery drill to prove your restore path
4. configure allowed environments and allowed databases
5. only then test restore in a safe environment

## Related replication controls

Replication can also be destructive. Current controls include:

- `replication.require_confirmation_token`
- `replication.block_in_ci`
- `replication.require_dry_run_before_apply`
- `replication.enforce_change_window`
- `replication.change_window_timezone`
- `replication.change_window_days`
- `replication.change_window_start`
- `replication.change_window_end`
- `replication.allowlisted_destinations`
- `replication.critical_tables`
