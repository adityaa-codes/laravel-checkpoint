---
name: laravel-checkpoint-installer
description: Installs and configures the Laravel Checkpoint package for application teams. Use when Codex needs to add the package to a Laravel app, publish config or migrations, choose a supported driver, wire queue and schedule settings, or verify the package is production-safe. Do not use for authoring new drivers, changing restore policy internals, or extending report payloads inside the package itself.
---

# Laravel Checkpoint Installer

1. Confirm the host project is a Laravel application that intends to consume this package rather than modify its internals.
2. Read `references/integration-surfaces.md` before changing config, env, scheduler, or operational commands.
3. Install the package dependency first, then publish config and migrations, then migrate. Do not invent a custom install sequence.
4. Choose the driver explicitly from the package's supported set. Prefer `pgbackrest` for PostgreSQL disaster recovery, `pgdump` for PostgreSQL logical export flows, `mysql` for MySQL logical export and binlog replay flows, and `shell` only for legacy or custom command templates.
5. Configure queue settings as a contract, not as independent knobs. Keep queue timeout, retry, uniqueness, lock store, orphan recovery, and heartbeat values aligned with the package's documented invariants.
6. Configure restore posture before enabling restore commands in shared or non-local environments. Require allowlisted environments, allowlisted databases where possible, confirmation, and verified-backup enforcement.
7. Wire the Laravel scheduler to run the package commands the package expects. Preserve overlap and one-server protections unless the host deployment model clearly requires otherwise.
8. Expose the package to the application through the supported public surfaces only: the facade, the public wrapper class, and the artisan commands. Do not bind directly to internal services unless the task explicitly needs package extension work.
9. Validate the installation with the narrowest checks available in the host app. Prefer config inspection, migration status, and package commands such as status, doctor, and report before broader app test runs.
10. Summarize the final integration in operator terms: chosen driver, queue name, restore posture, scheduler coverage, and any remaining infrastructure prerequisites.

## Error Handling

- If the host app lacks queue workers or scheduler execution, stop and report that the package cannot operate safely without them.
- If config validation fails, fix the declared contract rather than suppressing the validator.
- If the task requires a new backup engine or unsupported operation, stop using this skill and switch to the driver-author or package-extension path.
- If the environment is non-local and uses unsafe cache or lock stores, surface that as a release blocker rather than a warning-only cleanup item.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually confirm that the installation guidance still matches the package's current README, config, and public commands.
