<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final readonly class DrillTrendAnalyzer
{
    /**
     * @return array<string, mixed>
     */
    public function drillTrendPayload(int $windowDays): array
    {
        $windowStart = now()->subDays($windowDays);
        $runs = BackupDrillRun::query()
            ->where('executed_at', '>=', $windowStart)
            ->latest('executed_at')
            ->orderByDesc('id')
            ->get(['id', 'run_uuid', 'overall_result', 'executed_at']);

        $total = $runs->count();
        $latestResult = null;
        $latestRunUuid = null;
        $latestExecutedAt = null;
        $streakType = null;
        $streakLength = 0;
        $recentResults = [];
        $recentOutcomes = [];

        foreach ($runs as $index => $run) {
            $result = str((string) $run->overall_result)->lower()->value() === 'pass' ? 'pass' : 'fail';

            if ($index === 0) {
                $latestResult = $result;
                $latestRunUuid = $run->run_uuid;
                $latestExecutedAt = $run->executed_at->format('Y-m-d H:i:s');
                $streakType = $result;
            }

            if ($streakType !== null && $result === $streakType) {
                $streakLength++;
            }

            if (count($recentResults) < 5) {
                $recentResults[] = $result;
                $recentOutcomes[] = [
                    'run_uuid' => $run->run_uuid,
                    'result' => $result,
                    'executed_at' => $run->executed_at->format('Y-m-d H:i:s'),
                ];
            }
        }

        $passing = collect($recentResults)->filter(static fn (string $result): bool => $result === 'pass')->count();
        $failing = count($recentResults) - $passing;
        $trajectory = $this->drillTrajectory($recentResults);
        $status = match (true) {
            $total === 0 => 'insufficient_data',
            $latestResult === 'fail' && $streakLength >= 2 => 'degrading',
            $latestResult === 'pass' && $streakLength >= 2 => 'improving',
            default => 'stable',
        };

        return [
            'window_days' => $windowDays,
            'sample_size' => $total,
            'latest_result' => $latestResult,
            'latest_run_uuid' => $latestRunUuid,
            'latest_executed_at' => $latestExecutedAt,
            'streak' => [
                'type' => $streakType,
                'length' => $streakLength,
            ],
            'recent' => [
                'results' => $recentResults,
                'passing' => $passing,
                'failing' => $failing,
                'outcomes' => $recentOutcomes,
            ],
            'trajectory' => $trajectory,
            'status' => $status,
            'label' => $this->drillTrendLabel($status, $streakType, $streakLength, $total),
        ];
    }

    /**
     * @return array{latest:?BackupDrillRun,latest_failed:?BackupDrillRun,total:int,passing:int}
     */
    public function backupDrillSummary(Carbon $windowStart): array
    {
        $latest = BackupDrillRun::query()->recent()->first();
        $latestFailed = BackupDrillRun::query()->where('overall_result', 'fail')->recent()->first();
        $counts = BackupDrillRun::query()
            ->where('executed_at', '>=', $windowStart)
            ->toBase()
            ->selectRaw(
                'COUNT(*) as total,
                 SUM(CASE WHEN overall_result = ? THEN 1 ELSE 0 END) as passing',
                ['pass'],
            )
            ->first();

        return [
            'latest' => $latest instanceof BackupDrillRun ? $latest : null,
            'latest_failed' => $latestFailed instanceof BackupDrillRun ? $latestFailed : null,
            'total' => $counts === null ? 0 : (int) $counts->total,
            'passing' => $counts === null ? 0 : (int) $counts->passing,
        ];
    }

    /**
     * @param  list<string>  $recentResults
     */
    private function drillTrajectory(array $recentResults): string
    {
        if (count($recentResults) < 2) {
            return 'insufficient_data';
        }

        $latestTwo = collect($recentResults)->slice(0, 2)->all();

        if ($latestTwo === ['pass', 'pass']) {
            return 'improving';
        }

        if ($latestTwo === ['fail', 'fail']) {
            return 'degrading';
        }

        return 'stable';
    }

    private function drillTrendLabel(string $status, ?string $streakType, int $streakLength, int $sampleSize): string
    {
        if ($sampleSize === 0) {
            return 'No drills in window';
        }

        if ($streakType === null) {
            return "Trend {$status} (n={$sampleSize})";
        }

        return Str::ucfirst($status).' ('.str($streakType)->upper()->value()." streak x{$streakLength}, n={$sampleSize})";
    }
}
