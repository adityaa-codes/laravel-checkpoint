---
sidebar_position: 5
---

# MySQL Driver

The `mysql` driver is built around `mysqldump`, `mysql`, and `mysqlbinlog`.

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

- logical MySQL dumps
- restore from the latest known artifact
- restore from a specific file
- PITR-style workflows using configured binlog files
- package-driven backup drill orchestration

## Operational expectation

The package does not create binlogs or provision an isolated drill environment for you. You still need:

- valid MySQL privileges
- binlog retention aligned with your recovery window
- worker hosts with `mysqldump`, `mysql`, and `mysqlbinlog`
- restore targets allowed by the package safety config
