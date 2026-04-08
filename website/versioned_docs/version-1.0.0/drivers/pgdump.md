---
sidebar_position: 4
---

# pgDump Driver

The `pgdump` driver provides PostgreSQL logical export and restore workflows.

## Key config

- `drivers.pgdump.dump_binary`
- `drivers.pgdump.restore_binary`
- `drivers.pgdump.format`
- `drivers.pgdump.jobs`
- `drivers.pgdump.compress_level`
- `drivers.pgdump.output_dir`
- `drivers.pgdump.output_prefix`
- `drivers.pgdump.file_extension`
- `drivers.pgdump.clean`
- `drivers.pgdump.create`
- `drivers.pgdump.command_timeout_seconds`

## Typical fit

Use `pgdump` when you need:

- logical exports for archive or migration workflows
- schema or data portability
- operator-visible logical artifacts

Use `pgbackrest` instead when the primary concern is disaster recovery and repository-managed PostgreSQL backups.
