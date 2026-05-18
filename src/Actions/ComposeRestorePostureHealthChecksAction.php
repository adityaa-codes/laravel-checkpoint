<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;

final readonly class ComposeRestorePostureHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
    ) {}

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(?CommandRun $latestRestoreRun = null): array
    {
        return [
            $this->restoreEnvironmentPostureCheck(),
            $this->restoreDatabasePostureCheck(),
            $this->restoreCiBypassPostureCheck(),
            $this->restoreVerifiedBackupPostureCheck(),
            $this->restorePostVerificationCheck($latestRestoreRun),
        ];
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function restoreEnvironmentPostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.environments', 'Restore posture: environments', 'pass', 'not enforced in local/testing', [
                'environment' => $this->config->environment,
                'allowed_environments' => $this->config->restore['allowedEnvironments'],
                'reason' => 'local_or_testing',
            ]);
        }

        if ($this->config->restore['allowedEnvironments'] === []) {
            return $this->checkRow('restore.posture.environments', 'Restore posture: environments', 'warn', 'checkpoint.restore.allowed_environments is empty in non-local environment', [
                'environment' => $this->config->environment,
                'allowed_environments' => [],
                'reason' => 'allowlist_missing',
            ]);
        }

        $currentEnvironmentAllowed = in_array($this->config->environment, $this->config->restore['allowedEnvironments'], true);

        return $this->checkRow(
            'restore.posture.environments',
            'Restore posture: environments',
            $currentEnvironmentAllowed ? 'warn' : 'pass',
            $currentEnvironmentAllowed
                ? sprintf('current environment [%s] is allowlisted for restores', $this->config->environment)
                : sprintf('current environment [%s] is blocked by restore allowlist', $this->config->environment),
            [
                'environment' => $this->config->environment,
                'allowed_environments' => $this->config->restore['allowedEnvironments'],
                'current_environment_allowed' => $currentEnvironmentAllowed,
                'reason' => $currentEnvironmentAllowed ? 'current_environment_allowlisted' : 'current_environment_blocked',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function restoreDatabasePostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.databases', 'Restore posture: databases', 'pass', 'not enforced in local/testing', [
                'environment' => $this->config->environment,
                'database' => $this->config->currentDatabaseName,
                'allowed_databases' => $this->config->restore['allowedDatabases'],
                'reason' => 'local_or_testing',
            ]);
        }

        if ($this->config->restore['allowedDatabases'] === []) {
            return $this->checkRow('restore.posture.databases', 'Restore posture: databases', 'warn', 'checkpoint.restore.allowed_databases is empty in non-local environment', [
                'environment' => $this->config->environment,
                'database' => $this->config->currentDatabaseName,
                'allowed_databases' => [],
                'reason' => 'allowlist_missing',
            ]);
        }

        $databaseAllowlisted = $this->config->currentDatabaseName !== '' && in_array($this->config->currentDatabaseName, $this->config->restore['allowedDatabases'], true);

        return $this->checkRow(
            'restore.posture.databases',
            'Restore posture: databases',
            $databaseAllowlisted ? 'warn' : 'pass',
            $databaseAllowlisted
                ? sprintf('current database [%s] is allowlisted for restores', $this->config->currentDatabaseName)
                : 'current database is not allowlisted for restores',
            [
                'environment' => $this->config->environment,
                'database' => $this->config->currentDatabaseName,
                'allowed_databases' => $this->config->restore['allowedDatabases'],
                'current_database_allowlisted' => $databaseAllowlisted,
                'reason' => $databaseAllowlisted ? 'current_database_allowlisted' : 'current_database_blocked',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function restoreCiBypassPostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.ci_bypass', 'Restore posture: CI bypass', 'pass', 'not enforced in local/testing', [
                'environment' => $this->config->environment,
                'allow_in_ci' => $this->config->restore['allowInCi'],
                'reason' => 'local_or_testing',
            ]);
        }

        return $this->checkRow(
            'restore.posture.ci_bypass',
            'Restore posture: CI bypass',
            $this->config->restore['allowInCi'] ? 'warn' : 'pass',
            $this->config->restore['allowInCi'] ? 'restore confirmation bypass in CI is enabled' : 'restore confirmation bypass in CI is disabled',
            [
                'environment' => $this->config->environment,
                'allow_in_ci' => $this->config->restore['allowInCi'],
                'reason' => $this->config->restore['allowInCi'] ? 'ci_bypass_enabled' : 'ci_bypass_disabled',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function restoreVerifiedBackupPostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.verified_backup', 'Restore posture: verified backup', 'pass', 'not enforced in local/testing', [
                'environment' => $this->config->environment,
                'require_verified_backup' => $this->config->restore['requireVerifiedBackup'],
                'reason' => 'local_or_testing',
            ]);
        }

        return $this->checkRow(
            'restore.posture.verified_backup',
            'Restore posture: verified backup',
            $this->config->restore['requireVerifiedBackup'] ? 'pass' : 'warn',
            $this->config->restore['requireVerifiedBackup'] ? 'verified backup requirement is enabled' : 'verified backup requirement is disabled',
            [
                'environment' => $this->config->environment,
                'require_verified_backup' => $this->config->restore['requireVerifiedBackup'],
                'reason' => $this->config->restore['requireVerifiedBackup'] ? 'verified_backup_required' : 'verified_backup_not_required',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function restorePostVerificationCheck(?CommandRun $latestRestoreRun): array
    {
        if (! $latestRestoreRun instanceof CommandRun) {
            return $this->checkRow(
                'restore.post_verification',
                'Restore posture: post-restore verification',
                'warn',
                'No restore run available for post-restore verification evaluation',
                [
                    'latest_restore_run_id' => null,
                    'aggregate_result' => null,
                    'reason' => 'missing_restore_run',
                ],
            );
        }

        $summary = $latestRestoreRun->restorePostVerificationSummary();
        $aggregateResult = $summary['aggregate_result'];
        $status = $aggregateResult === 'pass' ? 'pass' : 'warn';

        return $this->checkRow(
            'restore.post_verification',
            'Restore posture: post-restore verification',
            $status,
            is_string($aggregateResult)
                ? sprintf('latest restore post-verification result: %s', $aggregateResult)
                : 'latest restore run has no post-restore verification payload',
            [
                'latest_restore_run_id' => (int) $latestRestoreRun->getKey(),
                'operation' => $latestRestoreRun->operation,
                'aggregate_result' => $aggregateResult,
                'post_restore_verification' => $this->postRestoreVerificationPayload($latestRestoreRun),
                'reason' => $aggregateResult === null ? 'signal_missing' : ($aggregateResult === 'pass' ? 'healthy' : 'check_failed'),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function postRestoreVerificationPayload(CommandRun $run): ?array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $postVerification = is_array($restoreAudit['post_restore_verification'] ?? null)
            ? $restoreAudit['post_restore_verification']
            : [];
        $summary = $run->restorePostVerificationSummary();

        if ($summary['aggregate_result'] !== null) {
            $postVerification['aggregate_result'] = $summary['aggregate_result'];
        }

        return $postVerification !== [] ? $postVerification : null;
    }

    private function nonLocalEnvironment(): bool
    {
        return ! in_array($this->config->environment, ['local', 'testing'], true);
    }
}
