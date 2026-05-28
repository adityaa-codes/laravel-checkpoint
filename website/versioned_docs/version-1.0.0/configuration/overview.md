---
sidebar_position: 1
---

# Configuration Overview

Configuration lives in `config/checkpoint.php`. Top-level groups:

- `user_model`, `user_name_column`, `table_prefix`
- `queue`
- `restore`
- `replication`
- `schedule`
- `driver`
- `observability`
- `reporting`
- `retention`
- `notifications`
- `output`
- `temp_dir`
- `drivers`
- `log_channel`
- `custom_operations`

## Driver selection

`checkpoint.driver` selects the active driver. Available drivers:

- `postgres`
- `mysql`
- `fake`

Each block under `checkpoint.drivers` declares the class resolved for the `BackupDriver` contract.

## Scheduling

The service provider registers scheduled commands when the matching config flags are enabled:

- `schedule.logical_backup_enabled`
- `schedule.backup_drill_enabled`
- `schedule.health_check_enabled`
- `schedule.recover_orphans_enabled`
- `schedule.prune_enabled`

Scheduled commands use `withoutOverlapping()` and `onOneServer()`. This requires a shared cache backend in clustered deployments.

## Output and temp storage

- `output.max_persisted_bytes`
- `output.storage`
- `output.filesystem.disk`
- `output.filesystem.path_prefix`
- `output.filesystem.inline_bytes`

Temporary artifacts use `temp_dir`.
