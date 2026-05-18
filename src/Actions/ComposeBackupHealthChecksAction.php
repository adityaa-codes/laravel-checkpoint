<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;

final readonly class ComposeBackupHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
        private DispatchAlarmIfCooldownExpiredAction $dispatchAlarm,
    ) {}

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(?CommandRun $lastKnownGood = null): array
    {
        return [
            $this->lastKnownGoodCheck($lastKnownGood),
            $this->backupDurationAnomalyCheck(),
        ];
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function lastKnownGoodCheck(?CommandRun $latest = null): array
    {
        $latest ??= CommandRun::query()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->first();

        if (! $latest instanceof CommandRun || $latest->last_known_good_at === null) {
            $this->dispatchAlarm->execute(
                sprintf('backup.last_known_good:missing:%d', $this->config->obs['maxLastKnownGoodHours']),
                function (): void {
                    event(new BackupFreshnessAlarmTriggered(null, 'missing', null, $this->config->obs['maxLastKnownGoodHours']));
                },
            );

            return $this->checkRow('backup.last_known_good', 'Backups: last known good', 'warn', 'No last-known-good backup recorded', [
                'age_hours' => null,
                'threshold_hours' => $this->config->obs['maxLastKnownGoodHours'],
                'reason' => 'missing',
            ]);
        }

        $ageHours = max(0, (int) ceil($latest->last_known_good_at->diffInMinutes(now()) / 60));
        $isStale = $latest->last_known_good_at->lt(now()->subHours($this->config->obs['maxLastKnownGoodHours']));

        if ($isStale) {
            $this->dispatchAlarm->execute(
                sprintf('backup.last_known_good:stale:%d:%d', (int) $latest->getKey(), $this->config->obs['maxLastKnownGoodHours']),
                function () use ($latest, $ageHours): void {
                    event(new BackupFreshnessAlarmTriggered($latest, 'stale', $ageHours, $this->config->obs['maxLastKnownGoodHours']));
                },
            );
        }

        return $this->checkRow(
            'backup.last_known_good',
            'Backups: last known good',
            $isStale ? 'warn' : 'pass',
            sprintf('%d hours old (threshold: %d)', $ageHours, $this->config->obs['maxLastKnownGoodHours']),
            [
                'age_hours' => $ageHours,
                'threshold_hours' => $this->config->obs['maxLastKnownGoodHours'],
                'reason' => $isStale ? 'stale' : 'fresh',
            ],
        );
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function backupDurationAnomalyCheck(): array
    {
        $runs = CommandRun::query()
            ->whereNotNull('backup_type')
            ->whereNotNull('duration_seconds')
            ->where('status', 'succeeded')
            ->latest('id')
            ->limit($this->config->obs['durationMinSamples'])
            ->get();

        if ($runs->count() < $this->config->obs['durationMinSamples']) {
            return $this->checkRow('backup.duration_anomaly', 'Backups: duration anomaly', 'warn', 'Not enough successful backup samples', [
                'factor' => $this->config->obs['durationAnomalyFactor'],
                'min_samples' => $this->config->obs['durationMinSamples'],
                'sample_count' => $runs->count(),
                'reason' => 'insufficient_samples',
            ]);
        }

        $latest = $runs->first();
        $baseline = $runs->slice(1)->pluck('duration_seconds')->filter()->sort()->values();

        if (! $latest instanceof CommandRun || $baseline->isEmpty()) {
            return $this->checkRow('backup.duration_anomaly', 'Backups: duration anomaly', 'warn', 'Not enough successful backup samples', [
                'factor' => $this->config->obs['durationAnomalyFactor'],
                'min_samples' => $this->config->obs['durationMinSamples'],
                'sample_count' => $runs->count(),
                'reason' => 'insufficient_samples',
            ]);
        }

        $medianSeconds = (int) $baseline->get((int) floor(($baseline->count() - 1) / 2));
        $latestSeconds = (int) $latest->duration_seconds;

        $isAnomalous = $latestSeconds > (int) ceil($medianSeconds * $this->config->obs['durationAnomalyFactor']);

        return $this->checkRow(
            'backup.duration_anomaly',
            'Backups: duration anomaly',
            $isAnomalous ? 'warn' : 'pass',
            sprintf('latest %ds vs median %ds (factor: %.1f)', $latestSeconds, $medianSeconds, $this->config->obs['durationAnomalyFactor']),
            [
                'latest_seconds' => $latestSeconds,
                'median_seconds' => $medianSeconds,
                'factor' => $this->config->obs['durationAnomalyFactor'],
                'min_samples' => $this->config->obs['durationMinSamples'],
                'sample_count' => $runs->count(),
                'reason' => $isAnomalous ? 'anomalous' : 'normal',
            ],
        );
    }
}
