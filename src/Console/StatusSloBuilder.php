<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

final class StatusSloBuilder
{
    /**
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    public function runsSlo(int $runCount, int $failedRuns): array
    {
        $failureRate = $runCount > 0 ? round(($failedRuns / $runCount) * 100, 2) : 0.0;
        $indicators = [
            [
                'name' => 'failed_runs',
                'target' => 0,
                'current' => $failedRuns,
                'status' => $failedRuns > 0 ? 'fail' : 'pass',
                'unit' => 'runs',
            ],
            [
                'name' => 'failure_rate',
                'target' => 0,
                'current' => $failureRate,
                'status' => $failedRuns > 0 ? 'fail' : 'pass',
                'unit' => 'percent',
            ],
        ];

        return [
            'window' => sprintf('latest_%d_runs', $runCount),
            'indicators' => $indicators,
            'overall_status' => $failedRuns > 0 ? 'fail' : 'pass',
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    public function summarySlo(array $summary): array
    {
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingRuns = (int) ($summary['pending_runs'] ?? 0);
        $runningRuns = (int) ($summary['running_runs'] ?? 0);
        $drillTarget = (float) config('checkpoint.observability.backup_drill_min_pass_rate', 100.0);
        $drillCurrent = $summary['backup_drill_pass_rate']['pass_rate_percent'] ?? null;
        $drillCurrentValue = is_numeric($drillCurrent) ? (float) $drillCurrent : 0.0;
        $drillWindowDays = (int) ($summary['backup_drill_pass_rate']['window_days'] ?? 30);
        $drillStatus = is_numeric($drillCurrent) && $drillCurrentValue >= $drillTarget ? 'pass' : 'warn';
        $indicators = [
            [
                'name' => 'failed_runs_24h',
                'target' => 0,
                'current' => $failedRuns24h,
                'status' => $failedRuns24h > 0 ? 'fail' : 'pass',
                'unit' => 'runs',
            ],
            [
                'name' => 'pending_runs',
                'target' => 0,
                'current' => $pendingRuns,
                'status' => $pendingRuns > 0 ? 'warn' : 'pass',
                'unit' => 'runs',
            ],
            [
                'name' => 'running_runs',
                'target' => 0,
                'current' => $runningRuns,
                'status' => $runningRuns > 0 ? 'warn' : 'pass',
                'unit' => 'runs',
            ],
            [
                'name' => 'backup_drill_pass_rate',
                'target' => $drillTarget,
                'current' => round($drillCurrentValue, 2),
                'status' => $drillStatus,
                'unit' => 'percent',
            ],
        ];

        return [
            'window' => sprintf('24h_runs+%dd_drills', $drillWindowDays),
            'indicators' => $indicators,
            'overall_status' => $this->overallSloStatus($indicators),
        ];
    }

    /**
     * @param  list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>  $indicators
     */
    private function overallSloStatus(array $indicators): string
    {
        $indicators = collect($indicators);

        if ($indicators->contains('status', 'fail')) {
            return 'fail';
        }

        if ($indicators->contains('status', 'warn')) {
            return 'warn';
        }

        return 'pass';
    }
}
