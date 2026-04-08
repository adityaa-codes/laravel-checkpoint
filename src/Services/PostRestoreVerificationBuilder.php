<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

/** @internal */
final class PostRestoreVerificationBuilder
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    public function build(CommandRun $run, int $exitCode, array $metadata, ?string $restoreTarget = null): ?array
    {
        if (! $this->isRestoreOperation($run->operation)) {
            return null;
        }

        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $resolvedRestoreTarget = $this->resolvedRestoreTarget($run, $restoreAudit, $restoreTarget);
        $verifiedBackupRequired = (bool) ($restoreAudit['verified_backup_required'] ?? false);
        $verifiedSignalRunId = is_numeric($restoreAudit['verified_signal_run_id'] ?? null)
            ? (int) $restoreAudit['verified_signal_run_id']
            : null;

        $checks = [
            $this->check(
                name: 'restore_audit_recorded',
                passed: $restoreAudit !== [],
                description: 'restore guard decision metadata was persisted',
                observed: $restoreAudit !== [] ? 'recorded' : 'missing',
            ),
            $this->check(
                name: 'restore_target_recorded',
                passed: $resolvedRestoreTarget !== null,
                description: 'restore target is present for post-restore verification linkage',
                observed: $resolvedRestoreTarget ?? 'missing',
            ),
            $this->check(
                name: 'command_exit_code_zero',
                passed: $exitCode === 0,
                description: 'restore command finished with exit code 0',
                observed: $exitCode,
            ),
            $this->check(
                name: 'verified_backup_signal_linkage',
                passed: ! $verifiedBackupRequired || is_int($verifiedSignalRunId),
                description: 'verified-backup requirement is satisfied when enabled',
                observed: [
                    'required' => $verifiedBackupRequired,
                    'verified_signal_run_id' => $verifiedSignalRunId,
                ],
            ),
        ];

        $aggregate = collect($checks)->every(
            static fn (array $check): bool => $check['passed'],
        ) ? 'pass' : 'fail';

        return [
            'contract_version' => 1,
            'command_run_id' => $run->exists ? (int) $run->getKey() : null,
            'operation' => $run->operation,
            'generated_at' => now()->toIso8601String(),
            'aggregate_result' => $aggregate,
            'checks_performed' => array_column($checks, 'name'),
            'checks' => $checks,
        ];
    }

    private function isRestoreOperation(string $operation): bool
    {
        return in_array($operation, [
            'logical_restore_latest',
            'logical_restore_file',
            'pitr_restore',
            'pgbackrest_restore',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $restoreAudit
     */
    private function resolvedRestoreTarget(CommandRun $run, array $restoreAudit, ?string $restoreTarget): ?string
    {
        $candidate = $restoreAudit['target'] ?? $restoreTarget ?? $run->restore_target ?? $run->argument_text;

        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * @return array{name:string,passed:bool,status:string,description:string,observed:mixed}
     */
    private function check(string $name, bool $passed, string $description, mixed $observed): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'status' => $passed ? 'pass' : 'fail',
            'description' => $description,
            'observed' => $observed,
        ];
    }
}
