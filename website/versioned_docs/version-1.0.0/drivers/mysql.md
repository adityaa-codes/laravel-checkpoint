---
sidebar_position: 5
---

# MySQL Driver

The `mysql` driver handles MySQL backup, restore, and PITR workflows.

## Key config

- `drivers.mysql.dump_binary`
- `drivers.mysql.mysql_binary`
- `drivers.mysql.mysqlbinlog_binary`
- `drivers.mysql.single_transaction`
- `drivers.mysql.quick`
- `drivers.mysql.skip_lock_tables`
- `drivers.mysql.output_dir`
- `drivers.mysql.output_prefix`
- `drivers.mysql.file_extension`
- `drivers.mysql.drill_command`
- `drivers.mysql.command_timeout_seconds`
- `drivers.mysql.pitr.binlog_files`

## What it supports

- Logical MySQL dumps
- Restore from the latest known artifact
- Restore from a specific file
- PITR-style workflows using configured binlog files
- Package-driven backup drill orchestration

## Requirements

The package does not create binlogs or provision an isolated drill environment. You need:

- Valid MySQL privileges
- Binlog retention aligned with your recovery window
- Worker hosts with `mysqldump`, `mysql`, and `mysqlbinlog` installed
- Restore targets allowed by the package safety config
