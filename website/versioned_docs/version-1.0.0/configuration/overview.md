---
sidebar_position: 1
---

# Configuration Overview

The package configuration lives in `config/checkpoint.php`. The current top-level groups are:

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

`checkpoint.driver` selects the active backup driver. The package currently ships:

- `shell`
- `pgbackrest`
- `pgdump`
- `mysql`

Each driver block in `checkpoint.drivers` also declares the class that will be resolved for the `BackupDriver` contract.

## Scheduling

The service provider registers scheduled commands when the matching config switches are enabled:

- `schedule.logical_backup_enabled`
- `schedule.backup_drill_enabled`
- `schedule.health_check_enabled`
- `schedule.recover_orphans_enabled`
- `schedule.prune_enabled`

By default, scheduled commands also opt into:

- `withoutOverlapping()`
- `onOneServer()`

That means clustered deployments need a shared cache backend and a safe lock store.

## Output and temp storage

The package supports persisted output limits and optional filesystem-backed command output storage:

- `output.max_persisted_bytes`
- `output.storage`
- `output.filesystem.disk`
- `output.filesystem.path_prefix`
- `output.filesystem.inline_bytes`

Temporary package artifacts use `temp_dir`.
