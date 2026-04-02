---
name: laravel-checkpoint-automation-consumer
description: Consumes Laravel Checkpoint command and JSON automation surfaces for dashboards, CI, alerting, and operator tooling. Use when Codex needs to read or integrate `db-ops:status`, `db-ops:doctor`, or `db-ops:report`, map health semantics, or build automation around machine-readable output. Do not use for installing the package, implementing new drivers, or changing internal restore policies.
---

# Laravel Checkpoint Automation Consumer

1. Confirm the request is about consuming package output, not changing package internals.
2. Read `references/json-surfaces.md` before wiring automation to command output.
3. Prefer the package's machine-readable commands over scraping tables. Use `db-ops:report` for one combined operational snapshot, `db-ops:doctor --format=json` for health checks, and `db-ops:status --format=json` for recent-run or summary views.
4. Treat versioned JSON output as the contract. Preserve top-level envelope fields and command-specific payload boundaries when generating downstream code or documentation.
5. Choose the narrowest command for the automation need. Prefer `report` when combining summary and health, `doctor` for pass or warn or fail checks, and `status` when the workflow specifically needs recent runs or summary snapshots.
6. Respect configured caps such as recent-run limits. Downstream tooling should detect effective limits rather than assuming the requested limit was honored.
7. Interpret health correctly. `ok` should only be treated as healthy when every emitted check passes, and warnings should remain visible to operators even when not fatal to automation.
8. Preserve restore and drill semantics in downstream consumers. Do not flatten restore audit fields, backup drill rates, or latest restore failure context into generic status text if structured data is available.
9. When documenting or generating integration code, name the originating command and payload version explicitly so future upgrades remain auditable.
10. Validate the integration by running the command that matches the target use case and comparing the resulting fields with the current package contract.

## Error Handling

- If the request depends on table output parsing, switch it to the JSON surface unless a human-only view is explicitly required.
- If a needed field is not present in the current JSON contract, stop and state that package extension work is required rather than fabricating derived data.
- If command output reflects config validation failure, treat the payload as a failure surface to integrate, not as malformed output to ignore.
- If the task starts changing how commands produce JSON, switch to package-extension or driver-author work instead of continuing as a consumer task.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually confirm the command guidance still matches the current report, doctor, and status commands.
