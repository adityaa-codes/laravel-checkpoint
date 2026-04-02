---
name: laravel-checkpoint-driver-author
description: Authors or updates Laravel Checkpoint backup drivers and their surrounding package seams. Use when Codex needs to add a new engine-specific driver, extend operation handling inside a driver, wire driver config, preserve output capture and redaction, or add driver-focused tests. Do not use for app-level package installation, generic backup advice, or consuming existing JSON command outputs.
---

# Laravel Checkpoint Driver Author

1. Confirm the task is package extension work centered on a driver or driver-adjacent seam.
2. Read `references/extension-seams.md` before changing driver code, config, or tests.
3. Start from the contract. Implement `BackupDriver::execute(CommandRun $run)` and preserve the package lifecycle: claim pending work, plan metadata, enforce restore safety where applicable, execute the process, persist output, mark terminal state, and emit events.
4. Keep operation names stable unless the task explicitly extends the public catalog. Prefer mapping engine-specific behavior behind existing operations before adding new operation names.
5. Build commands as argv arrays through Symfony Process. Do not switch to shell-concatenated command strings.
6. Preserve safety surfaces around restore flows, output storage, temporary files, path validation, and command-line redaction.
7. Add or update config schema and validator rules for every new driver capability. Treat configuration as part of the driver contract, not as incidental plumbing.
8. Keep metadata and reporting useful for operators. Persist artifact paths, labels, replay bounds, repository identity, or other engine-specific evidence needed by restore checks and operational reports.
9. Add tests in the narrowest layer that proves the behavior: unit tests for command builders and path logic, feature tests for queueing, safety, and report integration.
10. Re-read the package README and public tests before finalizing so new driver behavior does not drift from the package's public story.

## Error Handling

- If the task only needs a custom operation definition and not a new engine implementation, switch to the custom-operations path instead of forcing a driver change.
- If the requested behavior cannot preserve restore safety or command redaction, stop and surface the design problem before writing code.
- If a new driver would bypass the output capture or command run model, redesign it to fit the package lifecycle rather than treating the package as a thin command wrapper.
- If the task changes public behavior, update docs and contract tests in the same change.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually verify the skill still reflects the current driver implementations and package boundaries.
