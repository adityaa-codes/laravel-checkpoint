---
name: laravel-checkpoint-restore-safety
description: Applies and explains Laravel Checkpoint restore guardrails for operators and integrators. Use when Codex needs to configure restore allowlists, confirmation controls, verified-backup requirements, PITR target validation, or diagnose why a restore was blocked. Do not use for generic database restore advice outside this package or for implementing a new backup driver from scratch.
---

# Laravel Checkpoint Restore Safety

1. Confirm the request is about restore posture, restore failures, PITR target validation, or restore-related operator workflows in Laravel Checkpoint.
2. Read `references/guardrails.md` before changing restore config or diagnosing blocked restores.
3. Identify the restore operation first: `logical_restore_latest`, `logical_restore_file`, `pitr_restore`, or `pgbackrest_restore`. Treat each as destructive work.
4. Check the guard chain in order: allowed environment, allowed database, confirmation, restore target validity, and verified-backup requirements. Do not skip ahead to symptoms.
5. For PITR, require a parseable datetime target and the correct baseline or provenance context. Do not treat PITR as a free-form string restore.
6. When the task is configuration work, prefer tightening the posture: narrow environment and database allowlists, keep confirmation enabled, and keep verified-backup enforcement enabled outside local or testing contexts.
7. When the task is diagnosis, map the failure back to the exact guard that blocked the run and explain the minimal change required to satisfy it.
8. Preserve auditability. If the task changes restore behavior, verify that restore decision events and restore metadata still make sense for operator review.
9. Avoid recommending bypasses unless the task explicitly asks for temporary non-production relaxation and the resulting risk is stated clearly.
10. Validate the outcome with restore-focused tests or by checking the package's restore-facing commands and stored audit context.

## Error Handling

- If the request actually needs a new backup engine or restore implementation, stop and switch to the driver-author skill.
- If the host environment is production-like and the user asks to disable all restore protections, state the risk directly instead of encoding an unsafe default silently.
- If a restore is blocked but the evidence is incomplete, inspect restore decision events and the active config before proposing changes.
- If PITR inputs are missing baseline or binlog context, treat the task as incomplete rather than improvising a replay chain.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually confirm the guidance still matches the current restore guard and validator behavior.
