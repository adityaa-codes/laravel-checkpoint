<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final readonly class ComposeBackupDrillHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
        private BuildDrillRemediationPlaybookAction $buildDrillRemediationPlaybook,
        private DispatchAlarmIfCooldownExpiredAction $dispatchAlarm,
    ) {}

    /**
     * @param  array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}  $drillSummary
     * @param  array<string, mixed>  $drillTrend
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(array $drillSummary, int $windowDays, array $drillTrend): array
    {
        return [
            $this->backupDrillFreshnessCheck($drillSummary['latest']),
            $this->backupDrillPassRateCheck($drillSummary),
            $this->backupDrillTrendCheck($drillTrend),
            $this->backupDrillPlaybookCheck($drillSummary, $windowDays, $drillTrend),
        ];
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillFreshnessCheck(?BackupDrillRun $latest): array
    {
        if (! $latest instanceof BackupDrillRun) {
            $this->dispatchAlarm->execute(
                sprintf('backup_drill.latest_run:missing:%d', $this->config->obs['maxDrillAgeDays']),
                function (): void {
                    event(new BackupDrillFreshnessAlarmTriggered(null, 'missing', null, $this->config->obs['maxDrillAgeDays']));
                },
            );

            return $this->checkRow('backup_drill.latest_run', 'Backup drills: latest run', 'warn', 'No backup drill recorded', [
                'run_uuid' => null,
                'overall_result' => null,
                'age_days' => null,
                'threshold_days' => $this->config->obs['maxDrillAgeDays'],
                'reason' => 'missing',
            ]);
        }

        $ageDays = max(0, (int) ceil($latest->executed_at->diffInHours(now()) / 24));
        $isStale = $latest->executed_at->lt(now()->subDays($this->config->obs['maxDrillAgeDays']));

        if ($isStale) {
            $this->dispatchAlarm->execute(
                sprintf('backup_drill.latest_run:stale:%s:%d', $latest->run_uuid, $this->config->obs['maxDrillAgeDays']),
                function () use ($latest, $ageDays): void {
                    event(new BackupDrillFreshnessAlarmTriggered($latest, 'stale', $ageDays, $this->config->obs['maxDrillAgeDays']));
                },
            );
        }

        return $this->checkRow(
            'backup_drill.latest_run',
            'Backup drills: latest run',
            $isStale ? 'warn' : 'pass',
            sprintf('%s %d days old (%s)', Str::upper($latest->overall_result), $ageDays, $latest->run_uuid),
            [
                'run_uuid' => $latest->run_uuid,
                'overall_result' => $latest->overall_result,
                'age_days' => $ageDays,
                'threshold_days' => $this->config->obs['maxDrillAgeDays'],
                'reason' => $isStale ? 'stale' : 'fresh',
            ],
        );
    }

    /**
     * @param  array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}  $summary
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillPassRateCheck(array $summary): array
    {
        $total = $summary['total'];
        $passing = $summary['passing'];
        $latest = $summary['latest'];

        if ($total < 1) {
            $this->dispatchAlarm->execute(
                sprintf('backup_drill.pass_rate:missing:%d:%.1f', $this->config->obs['drillWindowDays'], $this->config->obs['drillMinPassRate']),
                function () use ($latest): void {
                    event(new BackupDrillPassRateAlarmTriggered($this->config->obs['drillWindowDays'], 0, 0, 0.0, $this->config->obs['drillMinPassRate'], $latest));
                },
            );

            return $this->checkRow('backup_drill.pass_rate', 'Backup drills: pass rate', 'warn', sprintf('No backup drills in the last %d days', $this->config->obs['drillWindowDays']), [
                'window_days' => $this->config->obs['drillWindowDays'],
                'total' => 0,
                'passing' => 0,
                'pass_rate_percent' => 0.0,
                'threshold_percent' => $this->config->obs['drillMinPassRate'],
                'latest_run_uuid' => $latest?->run_uuid,
                'reason' => 'missing',
            ]);
        }

        $percent = round(($passing / $total) * 100, 1);
        $isBelowThreshold = $percent < $this->config->obs['drillMinPassRate'];

        if ($isBelowThreshold) {
            $latestId = $latest?->getKey();
            $this->dispatchAlarm->execute(
                sprintf('backup_drill.pass_rate:stale:%s:%d:%.1f', $latestId !== null ? (string) $latestId : 'none', $this->config->obs['drillWindowDays'], $this->config->obs['drillMinPassRate']),
                function () use ($passing, $total, $percent, $latest): void {
                    event(new BackupDrillPassRateAlarmTriggered($this->config->obs['drillWindowDays'], $passing, $total, $percent, $this->config->obs['drillMinPassRate'], $latest));
                },
            );
        }

        return $this->checkRow(
            'backup_drill.pass_rate',
            'Backup drills: pass rate',
            $isBelowThreshold ? 'warn' : 'pass',
            sprintf('%d/%d passed in the last %d days (%.1f%%, threshold: %.1f%%)', $passing, $total, $this->config->obs['drillWindowDays'], $percent, $this->config->obs['drillMinPassRate']),
            [
                'window_days' => $this->config->obs['drillWindowDays'],
                'total' => $total,
                'passing' => $passing,
                'pass_rate_percent' => $percent,
                'threshold_percent' => $this->config->obs['drillMinPassRate'],
                'latest_run_uuid' => $latest?->run_uuid,
                'reason' => $isBelowThreshold ? 'below_threshold' : 'healthy',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $trend
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
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
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function backupDrillPlaybookCheck(array $summary, int $windowDays, array $trend): array
    {
        $playbook = $this->buildDrillRemediationPlaybook->execute(
            latestRun: $summary['latest'],
            windowDays: $windowDays,
            total: $summary['total'],
            passing: $summary['passing'],
            minimumPassRatePercent: max(0.0, min(100.0, $this->config->obs['drillMinPassRate'])),
            maxAgeDays: max(1, $this->config->obs['maxDrillAgeDays']),
            trend: $trend,
        );

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
}
