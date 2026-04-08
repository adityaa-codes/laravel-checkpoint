---
sidebar_position: 1
---

# Restore Guardrails

Restore safety is a first-class package concern. The validator runs at package boot, and restore operations remain gated by dedicated restore config.

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

- restores are not intended to be casual operator actions
- CI restore execution is blocked by default
- non-local posture defaults to requiring verified backups
- blast-radius scoring can warn or block before the driver is invoked

## Related replication controls

Replication is treated as destructive workflow governance too. Current controls include:

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
