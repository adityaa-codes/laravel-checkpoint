---
sidebar_position: 1
---

# Configuration Overview

The package configuration lives in `config/checkpoint.php`. These are the top-level groups:

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

`checkpoint.driver` selects the active backup driver. Available drivers:

- `shell`
- `pgbackrest`
- `pgdump`
- `mysql`

Each block under `checkpoint.drivers` also declares the class resolved for the `BackupDriver` contract.

Shell driver prerequisite:

- define command templates for every operation you plan to run; if a template is missing, execution fails with a configuration error.
- guided install `--preset=minimal` seeds `logical_backup` with a local bootstrap placeholder command; replace it with your real backup command before relying on artifacts.

## Scheduling

The service provider registers scheduled commands when the matching config flags are enabled:

- `schedule.logical_backup_enabled`
- `schedule.backup_drill_enabled`
- `schedule.health_check_enabled`
- `schedule.recover_orphans_enabled`
- `schedule.prune_enabled`

By default, scheduled commands also use:

- `withoutOverlapping()`
- `onOneServer()`

In clustered deployments, this requires a shared cache backend and a safe lock store.

## Output and temp storage

You can limit persisted output and optionally store command output on a filesystem:

- `output.max_persisted_bytes`
- `output.storage`
- `output.filesystem.disk`
- `output.filesystem.path_prefix`
- `output.filesystem.inline_bytes`

Temporary package artifacts use `temp_dir`.
