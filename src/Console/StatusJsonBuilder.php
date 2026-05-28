<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\GateDecision;

final class StatusJsonBuilder
{
    public function __construct(
        private readonly CommandJsonContract $jsonContract,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    public function buildRunsJsonPayload(string $format, array $runs, int $limit, GateDecision $gateDecision): array
    {
        $payload = [
            'mode' => 'runs',
            'limit' => $limit,
            'runs' => $runs,
            'gates' => [
                'profile' => $gateDecision->profile,
                'profile_source' => $gateDecision->profileSource,
                'verdict' => $gateDecision->verdict,
                'failed_gate' => $gateDecision->failedGate,
                'exit_code' => $gateDecision->exitCode,
            ],
        ];

        return $this->jsonContract->envelope('status', $payload);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function buildSummaryJsonPayload(string $format, array $summary, GateDecision $gateDecision): array
    {
        $payload = [
            'mode' => 'summary',
            'summary' => $summary,
            'gates' => [
                'profile' => $gateDecision->profile,
                'profile_source' => $gateDecision->profileSource,
                'verdict' => $gateDecision->verdict,
                'failed_gate' => $gateDecision->failedGate,
                'exit_code' => $gateDecision->exitCode,
            ],
        ];

        return $this->jsonContract->envelope('status', $payload);
    }

    /**
     * @param  array<string, mixed>  $latestFailedRun
     * @return array<string, mixed>
     */
    public function buildBriefJsonPayload(string $format, int $failedRuns24h, int $pendingRuns, int $runningRuns, array $latestFailedRun, string $actionNow, GateDecision $gateDecision): array
    {
        $payload = [
            'mode' => 'brief',
            'failed_runs_24h' => $failedRuns24h,
            'pending_runs' => $pendingRuns,
            'running_runs' => $runningRuns,
            'last_failed_run' => $latestFailedRun,
            'action_now' => $actionNow,
            'gates' => [
                'profile' => $gateDecision->profile,
                'profile_source' => $gateDecision->profileSource,
                'verdict' => $gateDecision->verdict,
                'failed_gate' => $gateDecision->failedGate,
                'exit_code' => $gateDecision->exitCode,
            ],
        ];

        return $this->jsonContract->envelope('status', $payload);
    }
}
