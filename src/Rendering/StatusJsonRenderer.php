<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Rendering;

use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use Illuminate\Console\Command;
use Illuminate\Support\Js;

/** @internal */
final readonly class StatusJsonRenderer
{
    public function __construct(
        private CommandJsonContract $jsonContract,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $gateDecision
     */
    public function renderRunsJson(Command $command, string $format, array $runs, int $limit, array $gateDecision): int
    {
        $compact = $format === 'compact-json';
        $payload = [
            'limit' => $limit,
            'count' => count($runs),
            'runs' => $runs,
            'gates' => $gateDecision,
        ];

        $envelope = $compact
            ? $this->jsonContract->compactEnvelope('status', $payload)
            : $this->jsonContract->envelope('status', $payload);

        $command->line(Js::encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $gateDecision
     */
    public function renderSummaryJson(Command $command, string $format, array $summary, array $gateDecision): int
    {
        $compact = $format === 'compact-json';
        $payload = [
            'summary' => $summary,
            'slo' => $this->summarySlo($summary),
            'gates' => $gateDecision,
        ];

        $envelope = $compact
            ? $this->jsonContract->compactEnvelope('status', $payload)
            : $this->jsonContract->envelope('status', $payload);

        $command->line(Js::encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $gateDecision
     */
    public function renderBriefJson(Command $command, string $format, array $summary, array $gateDecision): int
    {
        $compact = $format === 'compact-json';
        $payload = [
            'mode' => 'brief',
            'summary' => $summary,
            'gates' => $gateDecision,
        ];

        $envelope = $compact
            ? $this->jsonContract->compactEnvelope('status', $payload)
            : $this->jsonContract->envelope('status', $payload);

        $command->line(Js::encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function summarySlo(array $summary): array
    {
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingRuns = (int) ($summary['pending_runs'] ?? 0);
        $runningRuns = (int) ($summary['running_runs'] ?? 0);
        $drillTarget = (float) ($summary['drill_target'] ?? 100.0);
        $drillCurrent = $summary['backup_drill_pass_rate']['pass_rate_percent'] ?? null;
        $drillCurrentValue = is_numeric($drillCurrent) ? (float) $drillCurrent : 0.0;
        $drillWindowDays = (int) ($summary['backup_drill_pass_rate']['window_days'] ?? 30);
        $drillStatus = is_numeric($drillCurrent) && $drillCurrentValue >= $drillTarget ? 'pass' : 'warn';

        $indicators = [
            ['name' => 'failed_runs_24h', 'target' => 0, 'current' => $failedRuns24h, 'status' => $failedRuns24h > 0 ? 'fail' : 'pass', 'unit' => 'runs'],
            ['name' => 'pending_runs', 'target' => 0, 'current' => $pendingRuns, 'status' => $pendingRuns > 0 ? 'warn' : 'pass', 'unit' => 'runs'],
            ['name' => 'running_runs', 'target' => 0, 'current' => $runningRuns, 'status' => $runningRuns > 0 ? 'warn' : 'pass', 'unit' => 'runs'],
            ['name' => 'backup_drill_pass_rate', 'target' => $drillTarget, 'current' => round($drillCurrentValue, 2), 'status' => $drillStatus, 'unit' => 'percent'],
        ];

        return [
            'window' => sprintf('24h_runs+%dd_drills', $drillWindowDays),
            'indicators' => $indicators,
            'overall_status' => $failedRuns24h > 0 ? 'fail' : ($pendingRuns > 0 || $runningRuns > 0 ? 'warn' : 'pass'),
        ];
    }
}
