---
name: laravel-checkpoint-package-extender
description: Extends the Laravel Checkpoint package internals, especially backup and restore driver behavior. Use when Codex needs to add a new driver, extend driver operations, update package-level config validation for driver capabilities, or adjust driver-facing reporting and safety seams. Do not use for app-only installation or dashboard and CI consumption of existing command output.
---

# Laravel Checkpoint Package Extender

1. Confirm the request is package extension work and not app-level consumption.
2. Read `references/extension-playbook.md` before editing package internals.
3. Start at the package contract: keep `BackupDriver::execute(CommandRun $run)` aligned with the command run lifecycle, output capture model, and event flow.
4. Prefer extending behavior behind existing operation names before introducing new public operation names.
5. Build command invocations as argv arrays through Symfony Process. Avoid shell-concatenated command strings.
6. Preserve restore safety, command redaction, and auditable metadata whenever extending restore-capable behavior.
7. Treat config and validation as part of the extension contract. Update `config/checkpoint.php` and validation rules for every new driver capability.
8. Keep machine-readable reporting coherent by preserving command JSON contract boundaries and versioned envelopes.
9. Add the narrowest tests that prove the extension: unit tests for command builders and validation, feature tests for queue lifecycle, safety, and report output.
10. Re-check README and public command behavior so package stories stay aligned after extension work.

## Error Handling

- If the request only consumes existing command output, switch to the package-consumer skill.
- If the change cannot preserve restore protections or redaction, stop and surface the design risk instead of shipping an unsafe default.
- If requirements imply a new public contract, update docs and contract tests in the same change.
- If a requested behavior bypasses the command run lifecycle, redesign the approach to fit package execution surfaces.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually confirm guidance still matches current drivers, config validator, and command JSON surfaces.
