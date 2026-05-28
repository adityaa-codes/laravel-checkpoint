---
sidebar_position: 1
---

# Restore Guardrails

Restore safety is a first-class concern. The validator runs at package boot, and restore operations remain gated by dedicated config.

## Environment variable

`CP_RESTORE_ALLOWED_ENVIRONMENTS` controls which environments permit restores. Set it to a comma-separated list:

```env
CP_RESTORE_ALLOWED_ENVIRONMENTS=local,staging
```

## Config controls

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

## Operational rules

- Restores are gated by environment, confirmation, and verified-backup checks
- CI restore execution is blocked by default
- Non-local posture defaults to requiring verified backups
- Blast-radius scoring can warn or block before the driver is invoked

## Replication controls

Replication is also treated as destructive. Controls include:

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
