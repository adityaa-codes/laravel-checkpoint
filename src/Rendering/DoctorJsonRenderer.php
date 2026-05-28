<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Rendering;

use AdityaaCodes\LaravelCheckpoint\Rendering\Concerns\FormatsHealthData;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\GateDecision;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/** @internal */
final readonly class DoctorJsonRenderer
{
    use FormatsHealthData;

    public function __construct(
        private Repository $config,
        private CommandJsonContract $jsonContract,
    ) {}

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    public function jsonReport(array $checks, bool $briefMode, GateDecision $gateDecision, bool $compactJson = false): string
    {
        $checks = $this->withSeverity($checks);

        if (! $briefMode) {
            $report = [
                'ok' => $this->healthOk($checks),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'generated_at' => now()->toIso8601String(),
                'gates' => [
                    'profile' => $gateDecision->profile,
                    'profile_source' => $gateDecision->profileSource,
                    'verdict' => $gateDecision->verdict,
                    'failed_gate' => $gateDecision->failedGate,
                    'exit_code' => $gateDecision->exitCode,
                ],
                'checks' => $this->mapChecksJson($checks),
            ];
            $report = $compactJson
                ? $this->jsonContract->compactEnvelope('doctor', $report)
                : $this->jsonContract->envelope('doctor', $report);

            return Js::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $report = [
            'ok' => $this->healthOk($checks),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'generated_at' => now()->toIso8601String(),
            'mode' => 'brief',
            'gates' => [
                'profile' => $gateDecision->profile,
                'profile_source' => $gateDecision->profileSource,
                'verdict' => $gateDecision->verdict,
                'failed_gate' => $gateDecision->failedGate,
                'exit_code' => $gateDecision->exitCode,
            ],
            'totals' => $this->severityTotals($checks),
            'checks' => $this->mapChecksJson($this->topIssues($checks, 3)),
            'next_actions' => collect($this->compactSuggestionsFromChecks($checks))->slice(0, 3)->all(),
        ];
        $report = $compactJson
            ? $this->jsonContract->compactEnvelope('doctor', $report)
            : $this->jsonContract->envelope('doctor', $report);

        return Js::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    public function renderReportJsonOutput(array $reportPayload, int $requestedLimit, int $effectiveLimit, bool $briefMode, GateDecision $gateDecision, bool $compactJson): string
    {
        $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
            ? $reportPayload['summary']['latest_failed_run']
            : ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null];

        $reportPayloadData = [
            'mode' => $briefMode ? 'brief' : 'full',
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'limit_requested' => $requestedLimit,
            'limit' => $effectiveLimit,
            'gates' => [
                'profile' => $gateDecision->profile,
                'profile_source' => $gateDecision->profileSource,
                'verdict' => $gateDecision->verdict,
                'failed_gate' => $gateDecision->failedGate,
                'exit_code' => $gateDecision->exitCode,
            ],
            'last_failed_run' => $lastFailedRun,
            'recent_runs' => $reportPayload['recent_runs'],
            'summary' => $reportPayload['summary'],
            'breakdown' => $reportPayload['breakdown'],
            'verification' => $reportPayload['verification'],
            'health' => $reportPayload['health'],
        ];

        $reportPayloadData = $compactJson
            ? $this->jsonContract->compactEnvelope('report', $reportPayloadData)
            : $this->jsonContract->envelope('report', $reportPayloadData);

        return Js::encode($reportPayloadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    private function mapChecksJson(array $checks): array
    {
        return collect($checks)->map(fn (array $check): array => [
            'code' => $check['code'],
            'check' => $check['check'],
            'status' => $check['status'],
            'severity' => $check['severity'],
            'notes' => $check['notes'],
            'data' => $check['data'],
        ])->all();
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<string>
     */
    private function compactSuggestionsFromChecks(array $checks): array
    {
        $suggestions = [];

        foreach ($checks as $check) {
            if ($check['status'] === 'pass') {
                continue;
            }

            $code = $check['code'];
            $suggestions[] = match (true) {
                $code === 'queue.orphaned_runs' => 'checkpoint:recover-orphans',
                Str::startsWith($code, 'backup_drill.') => 'checkpoint:drill',
                $code === 'backup.last_known_good' => 'Queue a backup',
                $code === 'backup.duration_anomaly' => 'Inspect backup duration history',
                $code === 'restore.post_verification' => 'Inspect restore verification results',
                $code === 'verification.runs' => 'Inspect verification runs',
                $code === 'restore.posture.environments' => 'Review restore policy',
                $code === 'restore.posture.databases' => 'Review database posture',
                $code === 'restore.posture.ci_bypass' => 'Review CI configuration',
                $code === 'restore.posture.verified_backup' => 'Enable verified backups',
                $code === 'config.backup_command' => 'Set backup command in config',
                Str::startsWith($code, 'driver.binary.') => sprintf('Install %s', str($code)->after('driver.binary.')->replace('.', ' ')->toString()),
                Str::startsWith($code, 'binary.') => sprintf('Install %s', str($code)->after('binary.')->replace('.', ' ')->toString()),
                default => 'Review check output',
            };
        }

        return collect($suggestions)->unique()->values()->all();
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function healthOk(array $checks): bool
    {
        return collect($checks)->every(static fn (array $check): bool => $check['status'] === 'pass');
    }
}
