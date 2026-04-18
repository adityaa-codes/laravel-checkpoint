---
name: laravel-checkpoint-package-consumer
description: Consumes Laravel Checkpoint from an application or automation context. Use when Codex needs to install the package in a Laravel app, configure supported drivers and operations, wire scheduler and queue settings, or integrate command JSON output into CI, dashboards, and alerting. Do not use for implementing new package internals or driver code.
---

# Laravel Checkpoint Package Consumer

1. Confirm the request is integration or automation consumption of existing package behavior.
2. Read `references/consumption-playbook.md` before making app-level changes.
3. Follow install order: dependency install, config and migration publish, then migration execution.
4. Select only supported drivers and map them to use case fit (`pgbackrest`, `pgdump`, `mysql`, `shell`).
5. Treat queue and scheduler settings as an operational contract. Keep timeout, retry, uniqueness, and lock-store choices compatible with deployment topology.
6. Configure restore posture before enabling restore commands in shared environments: allowlisted environments and databases, confirmation gate, and verified-backup rules.
7. Consume machine-readable command output using JSON-first surfaces (`db-ops:report`, `db-ops:doctor --format=json`, `db-ops:status --format=json`) instead of parsing table output.
8. Preserve JSON contract boundaries and payload versions when generating downstream automation code or docs.
9. Validate the integration through package-facing operator commands and expected JSON fields for the target workflow.
10. Summarize integration state in operator terms: selected driver, queue and scheduler posture, restore posture, and external prerequisites.

## Error Handling

- If the task requires a new driver or operation implementation, switch to the package-extender skill.
- If a needed field is missing from JSON output, surface package-extension work rather than fabricating derived values.
- If queue workers or scheduler are unavailable, stop and report the integration blocker explicitly.
- If configuration fails validation, correct the declared config contract rather than bypassing validators.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually confirm guidance matches current install docs, config schema, and command JSON surfaces.
