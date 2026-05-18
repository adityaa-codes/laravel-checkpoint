<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Actions\ComposeBackupDrillHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeBackupHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeBinaryHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeConfigHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeDatabaseTableHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeQueueHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeRestorePostureHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeVerificationHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;

final readonly class HealthCheckComposer
{
    public function __construct(
        private HealthCheckConfig $config,
        private ComposeConfigHealthChecksAction $configChecks,
        private ComposeBinaryHealthChecksAction $binaryChecks,
        private ComposeDatabaseTableHealthChecksAction $tableChecks,
        private ComposeQueueHealthChecksAction $queueChecks,
        private ComposeRestorePostureHealthChecksAction $restorePostureChecks,
        private ComposeBackupHealthChecksAction $backupChecks,
        private ComposeBackupDrillHealthChecksAction $drillChecks,
        private ComposeVerificationHealthChecksAction $verificationChecks,
    ) {}

    /**
     * @param  array{command_run_counts:array{pending_runs:int,running_runs:int,failed_runs_24h:int},drill_window_days:int,drill_summary:array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int},last_known_good:?CommandRun,latest_verified:?CommandRun,latest_restore_failure:?CommandRun,latest_restore_run:?CommandRun,latest_failed_run:?CommandRun}  $snapshot
     * @param  array<string, mixed>  $drillTrend
     * @param  array<string, mixed>  $verificationSummary
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function healthChecksFromSnapshot(array $snapshot, array $drillTrend, array $verificationSummary): array
    {
        return [
            ...$this->configChecks->execute(),
            ...$this->binaryChecks->execute(),
            ...$this->tableChecks->execute(),
            ...$this->queueChecks->execute(),
            ...$this->restorePostureChecks->execute($snapshot['latest_restore_run']),
            ...$this->backupChecks->execute($snapshot['last_known_good']),
            ...$this->drillChecks->execute($snapshot['drill_summary'], $snapshot['drill_window_days'], $drillTrend),
            ...$this->verificationChecks->execute($verificationSummary),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    public function healthOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (collect(['warn', 'fail'])->contains($check['status'])) {
                return false;
            }
        }

        return true;
    }
}
