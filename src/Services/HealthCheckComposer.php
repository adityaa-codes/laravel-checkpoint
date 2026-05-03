<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildDrillRemediationPlaybookAction;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use AdityaaCodes\LaravelCheckpoint\Support\BinaryFinder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

final readonly class HealthCheckComposer
{
    public function __construct(
        private DatabaseManager $database,
        private BuildDrillRemediationPlaybookAction $buildDrillRemediationPlaybook,
        private string $driver,
        private string $queueName,
        private string $logChannel,
        private string $pgbackrestStanza,
        private int $pgbackrestRepo,
        private array $pgbackrestRepositories,
        private int $pgbackrestProcessMax,
        private string $pgbackrestBinary,
        private int $orphanThreshold,
        private int $drillWindowDays,
        private float $backupDrillMinPassRate,
        private int $maxBackupDrillAgeDays,
        private int $maxLastKnownGoodAgeHours,
        private int $backupDurationMinSamples,
        private float $backupDurationAnomalyFactor,
        private int $alertCooldownSeconds,
        private ?string $lockStore,
        private array $allowedEnvironments,
        private array $allowedDatabases,
        private bool $allowInCi,
        private bool $requireVerifiedBackup,
        private string $environment,
        private string $currentDatabaseName,
        private string $pgdumpDumpBinary,
        private string $pgdumpRestoreBinary,
        private string $mysqlDumpBinary,
        private string $mysqlBinary,
        private string $mysqlBinlogBinary,
        private BinaryFinder $binaryFinder,
    ) {}

    /**
     * @param  array{command_run_counts:array{pending_runs:int,running_runs:int,failed_runs_24h:int},drill_window_days:int,drill_summary:array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int},last_known_good:?CommandRun,latest_verified:?CommandRun,latest_restore_failure:?CommandRun,latest_restore_run:?CommandRun,latest_failed_run:?CommandRun}  $snapshot
     * @param  array<string, mixed>  $drillTrend
     * @param  array<string, mixed>  $verificationSummary
     * @return list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>
     */
    public function healthChecksFromSnapshot(array $snapshot, array $drillTrend, array $verificationSummary): array
    {
        $rows = [
            $this->checkRow('config.driver', 'Config: driver', 'pass', $this->driver, [
                'driver' => $this->driver,
            ]),
            $this->checkRow('config.queue_name', 'Config: queue.name', 'pass', $this->queueName, [
                'queue_name' => $this->queueName,
            ]),
            $this->checkRow('config.log_channel', 'Config: log_channel', 'pass', $this->logChannel, [
                'log_channel' => $this->logChannel,
            ]),
            $this->checkRow('config.pgbackrest_stanza', 'Config: pgbackrest.stanza', 'pass', $this->pgbackrestStanza, [
                'stanza' => $this->pgbackrestStanza,
            ]),
            $this->checkRow('config.pgbackrest_repo', 'Config: pgbackrest.repo', 'pass', (string) $this->pgbackrestRepo, [
                'repository' => $this->pgbackrestRepo,
            ]),
            $this->checkRow('config.pgbackrest_repositories', 'Config: pgbackrest.repositories', 'pass', (string) count($this->pgbackrestRepositories), [
                'repository_count' => count($this->pgbackrestRepositories),
            ]),
            $this->checkRow('config.pgbackrest_process_max', 'Config: pgbackrest.process_max', 'pass', (string) $this->pgbackrestProcessMax, [
                'process_max' => $this->pgbackrestProcessMax,
            ]),
            $this->selectedPgBackRestRepositoryRow(),
            $this->selectedPgBackRestTargetRow(),
            $this->selectedPgBackRestTlsRow(),
            $this->selectedPgBackRestEncryptionRow(),
            $this->configuredBinaryRow(
                code: 'binary.pg_dump',
                label: 'Binary: pg_dump',
                binary: 'pg_dump',
                configPath: 'system.path',
                envKey: 'PATH',
                driver: $this->driver,
                required: false,
                includeRemediation: false,
            ),
            $this->configuredBinaryRow(
                code: 'binary.pgbackrest',
                label: 'Binary: pgBackRest',
                binary: $this->pgbackrestBinary,
                configPath: 'checkpoint.drivers.pgbackrest.binary',
                envKey: 'DB_OPS_PGBACKREST_BINARY',
                driver: $this->driver,
                required: $this->driver === 'pgbackrest',
                includeRemediation: false,
            ),
            $this->configuredBinaryRow(
                code: 'binary.gzip',
                label: 'Binary: gzip',
                binary: 'gzip',
                configPath: 'system.path',
                envKey: 'PATH',
                driver: $this->driver,
                required: false,
                includeRemediation: false,
            ),
            ...$this->activeDriverBinaryChecks(),
            $this->tableRow('command_runs', (new CommandRun)->getTable()),
            $this->tableRow('backup_drill_runs', (new BackupDrillRun)->getTable()),
            $this->tableRow('verification_runs', (new VerificationRun)->getTable()),
            $this->checkRow(
                'queue.worker_visibility',
                'Queue: '.$this->queueName,
                'warn',
                'Cannot verify queue without running worker',
                ['queue_name' => $this->queueName]
            ),
        ];

        $orphanedRunsCount = $this->orphanedRunsCount();
        $rows[] = $this->checkRow(
            'queue.orphaned_runs',
            'Orphaned runs',
            $orphanedRunsCount > 0 ? 'warn' : 'pass',
            sprintf('%d pending runs beyond threshold', $orphanedRunsCount),
            [
                'orphaned_run_count' => $orphanedRunsCount,
                'threshold_minutes' => $this->orphanThreshold,
            ],
        );
        $rows[] = $this->restoreEnvironmentPostureCheck();
        $rows[] = $this->restoreDatabasePostureCheck();
        $rows[] = $this->restoreCiBypassPostureCheck();
        $rows[] = $this->restoreVerifiedBackupPostureCheck();
        $rows[] = $this->restorePostVerificationCheck($snapshot['latest_restore_run']);
        $rows[] = $this->lastKnownGoodCheck($snapshot['last_known_good']);
        $rows[] = $this->backupDurationAnomalyCheck();
        $backupDrillSummary = $snapshot['drill_summary'];
        $rows[] = $this->backupDrillFreshnessCheck($backupDrillSummary['latest']);
        $rows[] = $this->backupDrillPassRateCheck($backupDrillSummary);
        $rows[] = $this->backupDrillTrendCheck($drillTrend);
        $rows[] = $this->backupDrillPlaybookCheck($backupDrillSummary, $snapshot['drill_window_days'], $drillTrend);
        $rows[] = $this->verificationHealthCheck($verificationSummary);

        return $rows;
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    public function healthOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (in_array($check['status'], ['warn', 'fail'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function restoreEnvironmentPostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.environments', 'Restore posture: environments', 'pass', 'not enforced in local/testing', [
                'environment' => $this->environment,
                'allowed_environments' => $this->allowedEnvironments,
                'reason' => 'local_or_testing',
            ]);
        }

        if ($this->allowedEnvironments === []) {
            return $this->checkRow('restore.posture.environments', 'Restore posture: environments', 'warn', 'checkpoint.restore.allowed_environments is empty in non-local environment', [
                'environment' => $this->environment,
                'allowed_environments' => [],
                'reason' => 'allowlist_missing',
            ]);
        }

        $currentEnvironmentAllowed = in_array($this->environment, $this->allowedEnvironments, true);

        return $this->checkRow(
            'restore.posture.environments',
            'Restore posture: environments',
            $currentEnvironmentAllowed ? 'warn' : 'pass',
            $currentEnvironmentAllowed
                ? sprintf('current environment [%s] is allowlisted for restores', $this->environment)
                : sprintf('current environment [%s] is blocked by restore allowlist', $this->environment),
            [
                'environment' => $this->environment,
                'allowed_environments' => $this->allowedEnvironments,
                'current_environment_allowed' => $currentEnvironmentAllowed,
                'reason' => $currentEnvironmentAllowed ? 'current_environment_allowlisted' : 'current_environment_blocked',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function restoreDatabasePostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.databases', 'Restore posture: databases', 'pass', 'not enforced in local/testing', [
                'environment' => $this->environment,
                'database' => $this->currentDatabaseName,
                'allowed_databases' => $this->allowedDatabases,
                'reason' => 'local_or_testing',
            ]);
        }

        if ($this->allowedDatabases === []) {
            return $this->checkRow('restore.posture.databases', 'Restore posture: databases', 'warn', 'checkpoint.restore.allowed_databases is empty in non-local environment', [
                'environment' => $this->environment,
                'database' => $this->currentDatabaseName,
                'allowed_databases' => [],
                'reason' => 'allowlist_missing',
            ]);
        }

        $databaseAllowlisted = $this->currentDatabaseName !== '' && in_array($this->currentDatabaseName, $this->allowedDatabases, true);

        return $this->checkRow(
            'restore.posture.databases',
            'Restore posture: databases',
            $databaseAllowlisted ? 'warn' : 'pass',
            $databaseAllowlisted
                ? sprintf('current database [%s] is allowlisted for restores', $this->currentDatabaseName)
                : 'current database is not allowlisted for restores',
            [
                'environment' => $this->environment,
                'database' => $this->currentDatabaseName,
                'allowed_databases' => $this->allowedDatabases,
                'current_database_allowlisted' => $databaseAllowlisted,
                'reason' => $databaseAllowlisted ? 'current_database_allowlisted' : 'current_database_blocked',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function restoreCiBypassPostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.ci_bypass', 'Restore posture: CI bypass', 'pass', 'not enforced in local/testing', [
                'environment' => $this->environment,
                'allow_in_ci' => $this->allowInCi,
                'reason' => 'local_or_testing',
            ]);
        }

        return $this->checkRow(
            'restore.posture.ci_bypass',
            'Restore posture: CI bypass',
            $this->allowInCi ? 'warn' : 'pass',
            $this->allowInCi ? 'restore confirmation bypass in CI is enabled' : 'restore confirmation bypass in CI is disabled',
            [
                'environment' => $this->environment,
                'allow_in_ci' => $this->allowInCi,
                'reason' => $this->allowInCi ? 'ci_bypass_enabled' : 'ci_bypass_disabled',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function restoreVerifiedBackupPostureCheck(): array
    {
        if (! $this->nonLocalEnvironment()) {
            return $this->checkRow('restore.posture.verified_backup', 'Restore posture: verified backup', 'pass', 'not enforced in local/testing', [
                'environment' => $this->environment,
                'require_verified_backup' => $this->requireVerifiedBackup,
                'reason' => 'local_or_testing',
            ]);
        }

        return $this->checkRow(
            'restore.posture.verified_backup',
            'Restore posture: verified backup',
            $this->requireVerifiedBackup ? 'pass' : 'warn',
            $this->requireVerifiedBackup ? 'verified backup requirement is enabled' : 'verified backup requirement is disabled',
            [
                'environment' => $this->environment,
                'require_verified_backup' => $this->requireVerifiedBackup,
                'reason' => $this->requireVerifiedBackup ? 'verified_backup_required' : 'verified_backup_not_required',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
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
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function lastKnownGoodCheck(?CommandRun $latest = null): array
    {
        $latest ??= CommandRun::query()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->first();

        if (! $latest instanceof CommandRun || $latest->last_known_good_at === null) {
            $this->dispatchWithCooldown(
                sprintf('backup.last_known_good:missing:%d', $this->maxLastKnownGoodAgeHours),
                function (): void {
                    event(new BackupFreshnessAlarmTriggered(null, 'missing', null, $this->maxLastKnownGoodAgeHours));
                },
            );

            return $this->checkRow('backup.last_known_good', 'Backups: last known good', 'warn', 'No last-known-good backup recorded', [
                'age_hours' => null,
                'threshold_hours' => $this->maxLastKnownGoodAgeHours,
                'reason' => 'missing',
            ]);
        }

        $ageHours = max(0, (int) ceil($latest->last_known_good_at->diffInMinutes(now()) / 60));
        $isStale = $latest->last_known_good_at->lt(now()->subHours($this->maxLastKnownGoodAgeHours));

        if ($isStale) {
            $this->dispatchWithCooldown(
                sprintf('backup.last_known_good:stale:%d:%d', (int) $latest->getKey(), $this->maxLastKnownGoodAgeHours),
                function () use ($latest, $ageHours): void {
                    event(new BackupFreshnessAlarmTriggered($latest, 'stale', $ageHours, $this->maxLastKnownGoodAgeHours));
                },
            );
        }

        return $this->checkRow(
            'backup.last_known_good',
            'Backups: last known good',
            $isStale ? 'warn' : 'pass',
            sprintf('%d hours old (threshold: %d)', $ageHours, $this->maxLastKnownGoodAgeHours),
            [
                'age_hours' => $ageHours,
                'threshold_hours' => $this->maxLastKnownGoodAgeHours,
                'reason' => $isStale ? 'stale' : 'fresh',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDurationAnomalyCheck(): array
    {
        $runs = CommandRun::query()
            ->whereNotNull('backup_type')
            ->whereNotNull('duration_seconds')
            ->where('status', 'succeeded')
            ->latest('id')
            ->limit($this->backupDurationMinSamples)
            ->get();

        if ($runs->count() < $this->backupDurationMinSamples) {
            return $this->checkRow('backup.duration_anomaly', 'Backups: duration anomaly', 'warn', 'Not enough successful backup samples', [
                'factor' => $this->backupDurationAnomalyFactor,
                'min_samples' => $this->backupDurationMinSamples,
                'sample_count' => $runs->count(),
                'reason' => 'insufficient_samples',
            ]);
        }

        $latest = $runs->first();
        $baseline = $runs->slice(1)->pluck('duration_seconds')->filter()->sort()->values();

        if (! $latest instanceof CommandRun || $baseline->isEmpty()) {
            return $this->checkRow('backup.duration_anomaly', 'Backups: duration anomaly', 'warn', 'Not enough successful backup samples', [
                'factor' => $this->backupDurationAnomalyFactor,
                'min_samples' => $this->backupDurationMinSamples,
                'sample_count' => $runs->count(),
                'reason' => 'insufficient_samples',
            ]);
        }

        $medianSeconds = (int) $baseline->get((int) floor(($baseline->count() - 1) / 2));
        $latestSeconds = (int) $latest->duration_seconds;

        $isAnomalous = $latestSeconds > (int) ceil($medianSeconds * $this->backupDurationAnomalyFactor);

        return $this->checkRow(
            'backup.duration_anomaly',
            'Backups: duration anomaly',
            $isAnomalous ? 'warn' : 'pass',
            sprintf('latest %ds vs median %ds (factor: %.1f)', $latestSeconds, $medianSeconds, $this->backupDurationAnomalyFactor),
            [
                'latest_seconds' => $latestSeconds,
                'median_seconds' => $medianSeconds,
                'factor' => $this->backupDurationAnomalyFactor,
                'min_samples' => $this->backupDurationMinSamples,
                'sample_count' => $runs->count(),
                'reason' => $isAnomalous ? 'anomalous' : 'normal',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillFreshnessCheck(?BackupDrillRun $latest): array
    {
        if (! $latest instanceof BackupDrillRun) {
            $this->dispatchWithCooldown(
                sprintf('backup_drill.latest_run:missing:%d', $this->maxBackupDrillAgeDays),
                function (): void {
                    event(new BackupDrillFreshnessAlarmTriggered(null, 'missing', null, $this->maxBackupDrillAgeDays));
                },
            );

            return $this->checkRow('backup_drill.latest_run', 'Backup drills: latest run', 'warn', 'No backup drill recorded', [
                'run_uuid' => null,
                'overall_result' => null,
                'age_days' => null,
                'threshold_days' => $this->maxBackupDrillAgeDays,
                'reason' => 'missing',
            ]);
        }

        $ageDays = max(0, (int) ceil($latest->executed_at->diffInHours(now()) / 24));
        $isStale = $latest->executed_at->lt(now()->subDays($this->maxBackupDrillAgeDays));

        if ($isStale) {
            $this->dispatchWithCooldown(
                sprintf('backup_drill.latest_run:stale:%s:%d', $latest->run_uuid, $this->maxBackupDrillAgeDays),
                function () use ($latest, $ageDays): void {
                    event(new BackupDrillFreshnessAlarmTriggered($latest, 'stale', $ageDays, $this->maxBackupDrillAgeDays));
                },
            );
        }

        return $this->checkRow(
            'backup_drill.latest_run',
            'Backup drills: latest run',
            $isStale ? 'warn' : 'pass',
            sprintf('%s %d days old (%s)', strtoupper((string) $latest->overall_result), $ageDays, $latest->run_uuid),
            [
                'run_uuid' => $latest->run_uuid,
                'overall_result' => $latest->overall_result,
                'age_days' => $ageDays,
                'threshold_days' => $this->maxBackupDrillAgeDays,
                'reason' => $isStale ? 'stale' : 'fresh',
            ],
        );
    }

    /**
     * @param  array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}  $summary
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillPassRateCheck(array $summary): array
    {
        $total = $summary['total'];
        $passing = $summary['passing'];
        $latest = $summary['latest'];

        if ($total < 1) {
            $this->dispatchWithCooldown(
                sprintf('backup_drill.pass_rate:missing:%d:%.1f', $this->drillWindowDays, $this->backupDrillMinPassRate),
                function () use ($latest): void {
                    event(new BackupDrillPassRateAlarmTriggered($this->drillWindowDays, 0, 0, 0.0, $this->backupDrillMinPassRate, $latest));
                },
            );

            return $this->checkRow('backup_drill.pass_rate', 'Backup drills: pass rate', 'warn', sprintf('No backup drills in the last %d days', $this->drillWindowDays), [
                'window_days' => $this->drillWindowDays,
                'total' => 0,
                'passing' => 0,
                'pass_rate_percent' => 0.0,
                'threshold_percent' => $this->backupDrillMinPassRate,
                'latest_run_uuid' => $latest?->run_uuid,
                'reason' => 'missing',
            ]);
        }

        $percent = round(($passing / $total) * 100, 1);
        $isBelowThreshold = $percent < $this->backupDrillMinPassRate;

        if ($isBelowThreshold) {
            $latestId = $latest?->getKey();
            $this->dispatchWithCooldown(
                sprintf('backup_drill.pass_rate:stale:%s:%d:%.1f', $latestId !== null ? (string) $latestId : 'none', $this->drillWindowDays, $this->backupDrillMinPassRate),
                function () use ($passing, $total, $percent, $latest): void {
                    event(new BackupDrillPassRateAlarmTriggered($this->drillWindowDays, $passing, $total, $percent, $this->backupDrillMinPassRate, $latest));
                },
            );
        }

        return $this->checkRow(
            'backup_drill.pass_rate',
            'Backup drills: pass rate',
            $isBelowThreshold ? 'warn' : 'pass',
            sprintf('%d/%d passed in the last %d days (%.1f%%, threshold: %.1f%%)', $passing, $total, $this->drillWindowDays, $percent, $this->backupDrillMinPassRate),
            [
                'window_days' => $this->drillWindowDays,
                'total' => $total,
                'passing' => $passing,
                'pass_rate_percent' => $percent,
                'threshold_percent' => $this->backupDrillMinPassRate,
                'latest_run_uuid' => $latest?->run_uuid,
                'reason' => $isBelowThreshold ? 'below_threshold' : 'healthy',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $trend
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillTrendCheck(array $trend): array
    {
        $status = match ((string) Arr::get($trend, 'status', 'insufficient_data')) {
            'degrading', 'insufficient_data' => 'warn',
            default => 'pass',
        };

        return $this->checkRow(
            'backup_drill.trend',
            'Backup drills: trend',
            $status,
            (string) Arr::get($trend, 'label', 'No trend available'),
            $trend,
        );
    }

    /**
     * @param  array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}  $summary
     * @param  array<string, mixed>  $trend
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillPlaybookCheck(array $summary, int $windowDays, array $trend): array
    {
        $playbook = $this->backupDrillRemediationPlaybook($summary, $windowDays, $trend);
        $status = match ($playbook['severity']) {
            'critical' => 'warn',
            'warn' => 'warn',
            default => 'pass',
        };

        return $this->checkRow(
            'backup_drill.playbook',
            'Backup drills: remediation playbook',
            $status,
            $playbook['title'],
            $playbook,
        );
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function verificationHealthCheck(array $verificationSummary): array
    {
        $total = (int) ($verificationSummary['total_runs'] ?? 0);
        $failed = (int) ($verificationSummary['failed_runs'] ?? 0);

        if ($total < 1) {
            return $this->checkRow(
                'verification.runs',
                'Verification: runs',
                'warn',
                'No persisted verification runs yet',
                [
                    'total_runs' => 0,
                    'verified_runs' => 0,
                    'failed_runs' => 0,
                    'health_status' => 'warn',
                    'reason' => 'missing',
                ],
            );
        }

        $status = $failed > 0 ? 'warn' : 'pass';

        return $this->checkRow(
            'verification.runs',
            'Verification: runs',
            $status,
            sprintf('%d total (%d verified, %d failed)', $total, (int) ($verificationSummary['verified_runs'] ?? 0), $failed),
            [
                'total_runs' => $total,
                'verified_runs' => (int) ($verificationSummary['verified_runs'] ?? 0),
                'failed_runs' => $failed,
                'success_rate_percent' => $verificationSummary['success_rate_percent'] ?? null,
                'health_status' => $verificationSummary['health_status'] ?? ($failed > 0 ? 'warn' : 'pass'),
                'latest' => is_array($verificationSummary['latest'] ?? null) ? $verificationSummary['latest'] : null,
                'reason' => $failed > 0 ? 'failed_runs_present' : 'healthy',
            ],
        );
    }

    /**
     * @param  array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}  $summary
     * @param  array<string,mixed>  $trend
     * @return array{signature:string,severity:'info'|'warn'|'critical',title:string,summary:string,recommended_commands:list<string>,steps:list<string>,evidence:array<string,mixed>}
     */
    private function backupDrillRemediationPlaybook(array $summary, int $windowDays, array $trend): array
    {
        return $this->buildDrillRemediationPlaybook->execute(
            latestRun: $summary['latest'],
            windowDays: $windowDays,
            total: $summary['total'],
            passing: $summary['passing'],
            minimumPassRatePercent: max(0.0, min(100.0, $this->backupDrillMinPassRate)),
            maxAgeDays: max(1, $this->maxBackupDrillAgeDays),
            trend: $trend,
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

    private function orphanedRunsCount(): int
    {
        return CommandRun::query()
            ->pending()
            ->where('updated_at', '<', now()->subMinutes($this->orphanThreshold))
            ->count();
    }

    private function nonLocalEnvironment(): bool
    {
        return ! in_array($this->environment, ['local', 'testing'], true);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function pgBackRestRepositories(): array
    {
        return $this->pgbackrestRepositories;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedPgBackRestRepository(): array
    {
        $repository = $this->pgbackrestRepositories[$this->pgbackrestRepo] ?? [];

        return is_array($repository) ? $repository : [];
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestRepositoryRow(): array
    {
        $type = (string) ($this->selectedPgBackRestRepository()['type'] ?? 'unknown');

        return $this->checkRow('repo.pgbackrest_active', 'Repo: pgbackrest.active', 'pass', sprintf('repo%d (%s)', $this->pgbackrestRepo, $type), [
            'repository' => $this->pgbackrestRepo,
            'type' => $type,
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestTargetRow(): array
    {
        $repository = $this->selectedPgBackRestRepository();
        $type = (string) ($repository['type'] ?? 'unknown');

        if ($type === 's3') {
            $s3 = is_array($repository['s3'] ?? null) ? $repository['s3'] : [];

            return $this->checkRow('repo.pgbackrest_target', 'Repo: pgbackrest.target', 'pass', sprintf('s3://%s via %s', (string) ($s3['bucket'] ?? '-'), (string) ($s3['endpoint'] ?? '-')), [
                'type' => 's3',
                'bucket' => (string) ($s3['bucket'] ?? '-'),
                'endpoint' => (string) ($s3['endpoint'] ?? '-'),
            ]);
        }

        return $this->checkRow('repo.pgbackrest_target', 'Repo: pgbackrest.target', 'pass', (string) ($repository['path'] ?? '-'), [
            'type' => $type,
            'path' => (string) ($repository['path'] ?? '-'),
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestTlsRow(): array
    {
        $tls = $this->selectedPgBackRestRepository()['tls'] ?? [];
        $tls = is_array($tls) ? $tls : [];
        $verify = (bool) ($tls['verify'] ?? true);
        $caFile = $tls['ca_file'] ?? null;
        $notes = $verify ? 'verify enabled' : 'verify disabled';

        if (is_string($caFile) && trim($caFile) !== '') {
            $notes .= sprintf(' (ca: %s)', $caFile);
        }

        return $this->checkRow('repo.pgbackrest_tls', 'Repo: pgbackrest.tls', $verify ? 'pass' : 'warn', $notes, [
            'verify' => $verify,
            'ca_file' => is_string($caFile) && trim($caFile) !== '' ? $caFile : null,
        ]);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function selectedPgBackRestEncryptionRow(): array
    {
        $encryption = $this->selectedPgBackRestRepository()['encryption'] ?? [];
        $encryption = is_array($encryption) ? $encryption : [];
        $enabled = (bool) ($encryption['enabled'] ?? false);
        $cipherType = (string) ($encryption['cipher_type'] ?? 'unknown');

        return $this->checkRow('repo.pgbackrest_encryption', 'Repo: pgbackrest.encryption', $enabled ? 'pass' : 'warn', $enabled ? sprintf('enabled (%s)', $cipherType) : 'disabled', [
            'enabled' => $enabled,
            'cipher_type' => $cipherType,
        ]);
    }

    /**
     * @return list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>
     */
    private function activeDriverBinaryChecks(): array
    {
        return match ($this->driver) {
            'postgres' => [
                $this->configuredBinaryRow(
                    code: 'driver.binary.postgres.pgbackrest',
                    label: 'Driver binary: pgbackrest',
                    binary: $this->pgbackrestBinary,
                    configPath: 'checkpoint.drivers.pgbackrest.binary',
                    envKey: 'DB_OPS_PGBACKREST_BINARY',
                    driver: $this->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.postgres.pgdump',
                    label: 'Driver binary: pg_dump',
                    binary: $this->pgdumpDumpBinary,
                    configPath: 'checkpoint.drivers.pgdump.dump_binary',
                    envKey: 'DB_OPS_PGDUMP_BINARY',
                    driver: $this->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.postgres.pgrestore',
                    label: 'Driver binary: pg_restore',
                    binary: $this->pgdumpRestoreBinary,
                    configPath: 'checkpoint.drivers.pgdump.restore_binary',
                    envKey: 'DB_OPS_PGRESTORE_BINARY',
                    driver: $this->driver,
                ),
            ],
            'pgbackrest' => [
                $this->configuredBinaryRow(
                    code: 'driver.binary.pgbackrest',
                    label: 'Driver binary: pgbackrest',
                    binary: $this->pgbackrestBinary,
                    configPath: 'checkpoint.drivers.pgbackrest.binary',
                    envKey: 'DB_OPS_PGBACKREST_BINARY',
                    driver: $this->driver,
                ),
            ],
            'pgdump' => [
                $this->configuredBinaryRow(
                    code: 'driver.binary.pgdump.dump',
                    label: 'Driver binary: pg_dump',
                    binary: $this->pgdumpDumpBinary,
                    configPath: 'checkpoint.drivers.pgdump.dump_binary',
                    envKey: 'DB_OPS_PGDUMP_BINARY',
                    driver: $this->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.pgdump.restore',
                    label: 'Driver binary: pg_restore',
                    binary: $this->pgdumpRestoreBinary,
                    configPath: 'checkpoint.drivers.pgdump.restore_binary',
                    envKey: 'DB_OPS_PGRESTORE_BINARY',
                    driver: $this->driver,
                ),
            ],
            'mysql' => [
                $this->configuredBinaryRow(
                    code: 'driver.binary.mysql.dump',
                    label: 'Driver binary: mysqldump',
                    binary: $this->mysqlDumpBinary,
                    configPath: 'checkpoint.drivers.mysql.dump_binary',
                    envKey: 'DB_OPS_MYSQL_DUMP_BINARY',
                    driver: $this->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.mysql.mysql',
                    label: 'Driver binary: mysql',
                    binary: $this->mysqlBinary,
                    configPath: 'checkpoint.drivers.mysql.mysql_binary',
                    envKey: 'DB_OPS_MYSQL_BINARY',
                    driver: $this->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.mysql.binlog',
                    label: 'Driver binary: mysqlbinlog',
                    binary: $this->mysqlBinlogBinary,
                    configPath: 'checkpoint.drivers.mysql.mysqlbinlog_binary',
                    envKey: 'DB_OPS_MYSQL_BINLOG_BINARY',
                    driver: $this->driver,
                ),
            ],
            default => [],
        };
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function configuredBinaryRow(
        string $code,
        string $label,
        string $binary,
        string $configPath,
        string $envKey,
        string $driver,
        bool $required = true,
        bool $includeRemediation = true,
    ): array {
        $trimmedBinary = trim($binary);
        $remediationCommands = $includeRemediation
            ? [
                sprintf('command -v %s', $trimmedBinary !== '' ? $trimmedBinary : '<binary>'),
                sprintf('export %s=/absolute/path/to/%s', $envKey, $trimmedBinary !== '' ? basename($trimmedBinary) : '<binary>'),
                'php artisan checkpoint:doctor --format=json',
            ]
            : [];

        $data = [
            'binary' => $trimmedBinary,
            'required' => $required,
            'found' => false,
            'path' => null,
        ];

        if ($includeRemediation) {
            $data['driver'] = $driver;
            $data['config_path'] = $configPath;
            $data['env_key'] = $envKey;
        }

        if ($trimmedBinary === '') {
            $data['reason'] = 'empty';
            if ($includeRemediation) {
                $data['remediation_commands'] = $remediationCommands;
            }

            return $this->checkRow($code, $label, $required ? 'fail' : 'warn', 'Binary is empty', $data);
        }

        $resolution = $this->binaryFinder->resolve($trimmedBinary);
        $path = $resolution['path'];

        if ($path === null) {
            $data['reason'] = 'not_found';
            if ($includeRemediation) {
                $data['remediation_commands'] = $remediationCommands;
            }

            return $this->checkRow($code, $label, $required ? 'fail' : 'warn', sprintf('%s not found on PATH', $trimmedBinary), $data);
        }

        $data['found'] = true;
        $data['path'] = $path;
        $data['reason'] = null;

        if ($includeRemediation) {
            $data['remediation_commands'] = $remediationCommands;
        }

        return $this->checkRow($code, $label, 'pass', $path, $data);
    }

    /**
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function tableRow(string $label, string $table): array
    {
        $connection = $this->database->connection();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return $this->checkRow('db.'.$label.'_table', 'DB: '.$label.' table', 'fail', 'Table not found', [
                'table' => $table,
                'exists' => false,
                'row_count' => null,
            ]);
        }

        try {
            $count = $connection->table($table)->count();
        } catch (QueryException) {
            $count = 0;
        }

        return $this->checkRow('db.'.$label.'_table', 'DB: '.$label.' table', 'pass', sprintf('%d rows', $count), [
            'table' => $table,
            'exists' => true,
            'row_count' => $count,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}
     */
    private function checkRow(string $code, string $check, string $status, string $notes, array $data = []): array
    {
        return [
            'code' => $code,
            'check' => $check,
            'status' => $status,
            'severity' => $this->severityForStatus($status),
            'notes' => $notes,
            'data' => $data,
        ];
    }

    private function severityForStatus(string $status): string
    {
        return match ($status) {
            'fail' => 'blocker',
            'warn' => 'warning',
            default => 'info',
        };
    }

    private function dispatchWithCooldown(string $key, callable $dispatch): void
    {
        if ($this->alertCooldownSeconds === 0) {
            $dispatch();

            return;
        }

        $cacheKey = 'checkpoint:alert-cooldown:'.sha1($key);
        $cache = is_string($this->lockStore) && $this->lockStore !== '' ? Cache::store($this->lockStore) : Cache::store();

        if (! $cache->add($cacheKey, now()->timestamp, $this->alertCooldownSeconds)) {
            return;
        }

        $dispatch();
    }
}
