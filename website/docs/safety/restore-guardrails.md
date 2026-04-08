---
sidebar_position: 1
---

# Restore Guardrails

Restore is the riskiest thing this package can do, so it is blocked by default unless your config allows it.

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
- some restore attempts can be warned or blocked before any command runs

## Recommended approach

1. get backups working first
2. confirm status and doctor output look healthy
3. configure allowed environments and allowed databases
4. only then test restore in a safe environment

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
