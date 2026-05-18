<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

final class ReportCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:report {--limit=10 : Number of recent runs to include.} {--brief : Show triage-first report output with cause and next action.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.} {--policy-profile= : Override gate policy profile for CI/automation.}';

    protected $description = 'Show checkpoint operational report (table by default, json/agent supported).';

    public function __construct(
        private readonly Repository $config,
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $agentMode = (bool) $this->option('agent');
        $briefMode = (bool) $this->option('brief');
        $format = $this->stringOption('format') ?? 'table';
        $policyProfile = $this->policyProfileOverride();
        $outputMode = $agentMode ? 'agent' : $this->normalizedOutputMode($format);

        if ($this->enhancedInteractiveMode() && $outputMode === 'table') {
            intro('Checkpoint Operational Report');
            note('What: consolidated operational report across runs, health, and verification.');
            note('When: handoff reporting, audits, and broader operational review.');
            note('Next: use checkpoint:doctor to troubleshoot failing checks from this report.');
        }

        if ($outputMode === '') {
            $this->promptError('The --format option must be table, json, or compact-json.');

            return self::FAILURE;
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        try {
            $reportPayload = $this->reportBuilder->reportPayload($effectiveLimit);
        } catch (\Throwable $exception) {
            report($exception);

            $reportPayload = [
                'recent_runs' => [],
                'summary' => $this->emptySummary(),
                'breakdown' => $this->emptyBreakdown(),
                'verification' => $this->emptyVerificationSummary(),
                'health' => [
                    'ok' => false,
                    'checks' => [[
                        'code' => 'report.error',
                        'check' => 'Report execution',
                        'status' => 'fail',
                        'notes' => $exception->getMessage(),
                        'data' => [
                            'exception' => $exception::class,
                        ],
                    ]],
                ],
            ];
        }

        $gateDecision = $this->gatePolicyEvaluator->evaluate(
            is_array($reportPayload['health']['checks'] ?? null) ? $reportPayload['health']['checks'] : [],
            is_array($reportPayload['summary'] ?? null) ? $reportPayload['summary'] : [],
            $policyProfile,
        );
        $exitCode = $gateDecision['exit_code'];

        if ($outputMode === 'agent') {
            $this->line(json_encode($this->jsonContract->envelope('report', $this->agentReportPayload(
                requestedLimit: $requestedLimit,
                effectiveLimit: $effectiveLimit,
                reportPayload: $reportPayload,
                briefMode: $briefMode,
                gateDecision: $gateDecision,
            )), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($outputMode === 'json' || $outputMode === 'compact-json') {
            $compactJson = $outputMode === 'compact-json';
            $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
                ? $reportPayload['summary']['latest_failed_run']
                : ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null];

            $reportPayloadData = [
                'mode' => $briefMode ? 'brief' : 'full',
                'generated_at' => now()->toIso8601String(),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'limit_requested' => $requestedLimit,
                'limit' => $effectiveLimit,
                'gates' => $this->machineGateDecision($gateDecision),
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

            $this->line(json_encode($reportPayloadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderTableReport($reportPayload, $requestedLimit, $effectiveLimit, $briefMode);
        }

        return $exitCode;
    }

    private function normalizedOutputMode(string $format): string
    {
        return in_array($format, ['table', 'json', 'compact-json'], true) ? $format : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        $windowDays = max(1, (int) $this->config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30));
        $drillPassRate = [
            'label' => '-',
            'window_days' => $windowDays,
            'total' => 0,
            'passing' => 0,
            'pass_rate_percent' => null,
        ];

        return [
            'pending_runs' => 0,
            'running_runs' => 0,
            'failed_runs_24h' => 0,
            'last_known_good_backup' => ['label' => '-', 'timestamp' => null, 'operation' => null],
            'latest_verified_backup' => ['label' => '-', 'timestamp' => null, 'operation' => null],
            'latest_backup_drill' => ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null],
            'latest_failed_backup_drill' => ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null],
            'backup_drill_pass_rate' => $drillPassRate,
            'backup_drill_pass_rate_30d' => $drillPassRate,
            'backup_drill_trend' => [
                'label' => '-',
                'window_days' => $windowDays,
                'sample_size' => 0,
                'latest_result' => null,
                'latest_run_uuid' => null,
                'latest_executed_at' => null,
                'streak' => ['type' => null, 'length' => 0],
                'recent' => ['results' => [], 'passing' => 0, 'failing' => 0, 'outcomes' => []],
                'trajectory' => 'insufficient_data',
                'status' => 'insufficient_data',
            ],
            'backup_drill_remediation_playbook' => [
                'signature' => 'drill.missing_run',
                'severity' => 'critical',
                'title' => 'No backup drill evidence available',
                'summary' => 'No backup drill run is recorded. Schedule and record a drill run before relying on restore readiness.',
                'recommended_commands' => ['checkpoint:drill'],
                'steps' => [],
                'evidence' => [
                    'window_days' => $windowDays,
                    'total' => 0,
                    'passing' => 0,
                    'pass_rate_percent' => 0.0,
                    'minimum_pass_rate_percent' => (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0),
                    'latest_result' => null,
                    'latest_run_uuid' => null,
                    'latest_age_days' => null,
                    'max_age_days' => max(1, (int) $this->config->get('checkpoint.observability.max_backup_drill_age_days', 30)),
                    'trend_status' => 'insufficient_data',
                    'trend_trajectory' => 'insufficient_data',
                ],
            ],
            'latest_restore_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'target' => null, 'audit' => null],
            'latest_restore_failure' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'target' => null],
            'latest_failed_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyBreakdown(): array
    {
        return [
            'window' => [
                'failed_runs_hours' => 24,
            ],
            'totals' => [
                'groups' => 0,
                'runs' => 0,
                'failed_runs_24h' => 0,
            ],
            'by_target' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyVerificationSummary(): array
    {
        return [
            'total_runs' => 0,
            'verified_runs' => 0,
            'failed_runs' => 0,
            'success_rate_percent' => null,
            'health_status' => 'warn',
            'latest' => [
                'id' => null,
                'command_run_id' => null,
                'verification_type' => null,
                'status' => null,
                'verified_at' => null,
            ],
        ];
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     * @return array<string,mixed>
     */
    private function agentReportPayload(int $requestedLimit, int $effectiveLimit, array $reportPayload, bool $briefMode, array $gateDecision): array
    {
        $checks = $reportPayload['health']['checks'];
        $failedChecks = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnChecks = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $failedRuns = count(array_filter($reportPayload['recent_runs'], static fn (array $run): bool => (string) ($run['status'] ?? '') === 'failed'));
        $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
            ? $reportPayload['summary']['latest_failed_run']
            : ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null];

        $result = $failedChecks > 0 ? 'failed' : (($warnChecks > 0 || $failedRuns > 0) ? 'partial' : 'passed');
        $code = $failedChecks > 0 ? 'report.health.failed' : (($warnChecks > 0 || $failedRuns > 0) ? 'report.health.warn' : 'report.health.ok');

        return [
            'result' => $result,
            'code' => $code,
            'summary' => sprintf(
                'Recent failed runs: %d; health checks: %d fail, %d warn.',
                $failedRuns,
                $failedChecks,
                $warnChecks,
            ),
            'data' => [
                'mode' => $briefMode ? 'brief' : 'full',
                'generated_at' => now()->toIso8601String(),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'limit_requested' => $requestedLimit,
                'limit' => $effectiveLimit,
                'gates' => $gateDecision,
                'last_failed_run' => $lastFailedRun,
                'recent_runs' => $reportPayload['recent_runs'],
                'summary' => $reportPayload['summary'],
                'breakdown' => $reportPayload['breakdown'],
                'verification' => $reportPayload['verification'],
                'health' => $reportPayload['health'],
                'slo' => $this->sloPayload(
                    reportPayload: $reportPayload,
                    effectiveLimit: $effectiveLimit,
                    failedRuns: $failedRuns,
                    failedChecks: $failedChecks,
                    warnChecks: $warnChecks,
                ),
            ],
            'suggestions' => $this->reportSuggestions($checks, $failedRuns, $lastFailedRun, compact: $briefMode),
        ];
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function sloPayload(array $reportPayload, int $effectiveLimit, int $failedRuns, int $failedChecks, int $warnChecks): array
    {
        $runCount = count($reportPayload['recent_runs']);
        $failureRate = $runCount > 0 ? round(($failedRuns / $runCount) * 100, 2) : 0.0;
        $drillTarget = (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0);
        $drillCurrent = $reportPayload['summary']['backup_drill_pass_rate']['pass_rate_percent'] ?? null;
        $drillCurrentValue = is_numeric($drillCurrent) ? (float) $drillCurrent : 0.0;
        $drillWindowDays = (int) ($reportPayload['summary']['backup_drill_pass_rate']['window_days'] ?? 30);
        $drillStatus = is_numeric($drillCurrent) && $drillCurrentValue >= $drillTarget ? 'pass' : 'warn';
        $verificationStatus = (string) ($reportPayload['verification']['health_status'] ?? 'warn');
        $verificationFailed = is_numeric($reportPayload['verification']['failed_runs'] ?? null) ? (int) $reportPayload['verification']['failed_runs'] : 0;
        $verificationSuccessRate = is_numeric($reportPayload['verification']['success_rate_percent'] ?? null) ? (float) $reportPayload['verification']['success_rate_percent'] : 0.0;
        $failedTargets24h = 0;

        if (is_numeric($reportPayload['breakdown']['totals']['failed_runs_24h'] ?? null)) {
            $failedTargets24h = (int) $reportPayload['breakdown']['totals']['failed_runs_24h'];
        }

        $indicators = [
            [
                'name' => 'failed_runs',
                'target' => 0,
                'current' => $failedRuns,
                'status' => $failedRuns > 0 ? 'fail' : 'pass',
                'unit' => 'runs',
            ],
            [
                'name' => 'failed_checks',
                'target' => 0,
                'current' => $failedChecks,
                'status' => $failedChecks > 0 ? 'fail' : 'pass',
                'unit' => 'checks',
            ],
            [
                'name' => 'warning_checks',
                'target' => 0,
                'current' => $warnChecks,
                'status' => $warnChecks > 0 ? 'warn' : 'pass',
                'unit' => 'checks',
            ],
            [
                'name' => 'recent_failure_rate',
                'target' => 0,
                'current' => $failureRate,
                'status' => $failedRuns > 0 ? 'fail' : 'pass',
                'unit' => 'percent',
            ],
            [
                'name' => 'backup_drill_pass_rate',
                'target' => $drillTarget,
                'current' => round($drillCurrentValue, 2),
                'status' => $drillStatus,
                'unit' => 'percent',
            ],
            [
                'name' => 'verification_failed_runs',
                'target' => 0,
                'current' => $verificationFailed,
                'status' => $verificationStatus === 'pass' ? 'pass' : 'warn',
                'unit' => 'runs',
            ],
            [
                'name' => 'verification_success_rate',
                'target' => 100,
                'current' => round($verificationSuccessRate, 2),
                'status' => $verificationStatus === 'pass' ? 'pass' : 'warn',
                'unit' => 'percent',
            ],
            [
                'name' => 'failed_runs_24h_by_target',
                'target' => 0,
                'current' => $failedTargets24h,
                'status' => $failedTargets24h > 0 ? 'fail' : 'pass',
                'unit' => 'runs',
            ],
        ];

        return [
            'window' => sprintf('latest_%d_runs+%dd_drills', $effectiveLimit, $drillWindowDays),
            'indicators' => $indicators,
            'overall_status' => $this->overallSloStatus($indicators),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @param  array{label?:string,timestamp?:string|null,operation?:string|null,status?:string|null,exit_code?:int|null,failure_reason?:string|null,next_action?:string|null}  $lastFailedRun
     * @return list<string>
     */
    private function reportSuggestions(array $checks, int $failedRuns, array $lastFailedRun, bool $compact = false): array
    {
        $suggestions = [];
        $checks = $this->prioritizeChecks($checks);

        if ($failedRuns > 0) {
            $suggestions[] = $compact ? 'Inspect failed runs' : 'Inspect failed runs in data.recent_runs and rerun impacted operation with corrected inputs.';
        }

        if (is_string($lastFailedRun['next_action'] ?? null) && trim($lastFailedRun['next_action']) !== '') {
            $suggestions[] = trim((string) $lastFailedRun['next_action']);
        }

        foreach ($checks as $check) {
            if ((string) $check['status'] === 'pass') {
                continue;
            }

            $code = (string) $check['code'];

            if ($code === 'queue.orphaned_runs') {
                $suggestions[] = $compact ? 'checkpoint:recover-orphans' : 'Run checkpoint:recover-orphans and verify worker heartbeat settings.';
            } elseif (str_starts_with($code, 'backup_drill.')) {
                $suggestions[] = $compact ? 'checkpoint:drill' : 'Run a backup drill and track pass-rate/freshness health signals.';

                $playbookCommands = $check['data']['recommended_commands'] ?? null;

                if (is_array($playbookCommands)) {
                    foreach ($playbookCommands as $command) {
                        if (is_string($command) && trim($command) !== '') {
                            $suggestions[] = $compact ? $command : 'Run '.$command.' to execute the drill remediation playbook.';
                        }
                    }
                }
            } elseif ($code === 'backup.last_known_good') {
                $suggestions[] = $compact ? 'Queue a backup' : 'Queue a successful backup to refresh the last-known-good signal.';
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderTableReport(array $reportPayload, int $requestedLimit, int $effectiveLimit, bool $briefMode): void
    {
        if ($briefMode) {
            $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
                ? $reportPayload['summary']['latest_failed_run']
                : [];
            $failedChecks = count(array_filter($reportPayload['health']['checks'], static fn (array $check): bool => (string) $check['status'] === 'fail'));
            $warnChecks = count(array_filter($reportPayload['health']['checks'], static fn (array $check): bool => (string) $check['status'] === 'warn'));
            $suppressedLowerPriority = count(array_filter($reportPayload['health']['checks'], static fn (array $check): bool => (string) $check['status'] === 'pass'));
            $reason = (string) ($lastFailedRun['failure_reason'] ?? 'No recent failed run reason available.');
            $actionNow = (string) ($lastFailedRun['next_action'] ?? 'Run php artisan checkpoint:report --limit=10 --format=json for full failure context.');

            $this->line('Checkpoint report (brief)');
            $this->line(sprintf(
                'Failed runs (24h): %d | Health checks: %d fail, %d warn',
                (int) ($reportPayload['summary']['failed_runs_24h'] ?? 0),
                $failedChecks,
                $warnChecks,
            ));
            $this->line(sprintf('P0: %d | P1: %d | Suppressed lower-priority: %d', $failedChecks, $warnChecks, $suppressedLowerPriority));
            $this->line('Last failed: '.(string) ($lastFailedRun['label'] ?? '-'));
            $this->line('Cause: '.$reason);
            $this->line('Action now: '.$actionNow);
            $this->line('Deep dive: php artisan checkpoint:report --limit=10 --format=json');

            return;
        }

        $this->promptTable(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Limit requested', (string) $requestedLimit],
            ['Limit applied', (string) $effectiveLimit],
            ['Recent runs returned', (string) count($reportPayload['recent_runs'])],
            ['Health OK', $reportPayload['health']['ok'] ? 'yes' : 'no'],
            ['Pending runs', (string) ($reportPayload['summary']['pending_runs'] ?? 0)],
            ['Running runs', (string) ($reportPayload['summary']['running_runs'] ?? 0)],
            ['Failed runs (24h)', (string) ($reportPayload['summary']['failed_runs_24h'] ?? 0)],
            ['Last known good backup', (string) ($reportPayload['summary']['last_known_good_backup']['label'] ?? '-')],
            ['Latest verified backup', (string) ($reportPayload['summary']['latest_verified_backup']['label'] ?? '-')],
            ['Latest backup drill', (string) ($reportPayload['summary']['latest_backup_drill']['label'] ?? '-')],
            ['Latest failed drill', (string) ($reportPayload['summary']['latest_failed_backup_drill']['label'] ?? '-')],
            ['Drill remediation playbook', (string) ($reportPayload['summary']['backup_drill_remediation_playbook']['title'] ?? '-')],
            ['Latest restore run', (string) ($reportPayload['summary']['latest_restore_run']['label'] ?? '-')],
            ['Latest restore failure', (string) ($reportPayload['summary']['latest_restore_failure']['label'] ?? '-')],
            ['Latest restore post-verification', (string) ($reportPayload['summary']['latest_restore_run']['post_restore_verification']['aggregate_result'] ?? '-')],
            ['Verification runs', (string) ($reportPayload['verification']['total_runs'] ?? 0)],
            ['Verification failed', (string) ($reportPayload['verification']['failed_runs'] ?? 0)],
            ['Verification health', (string) ($reportPayload['verification']['health_status'] ?? 'warn')],
        ]);

        $recentRuns = $reportPayload['recent_runs'];

        if ($recentRuns !== []) {
            $this->promptTable(['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Started', 'Finished'], array_map(
                static fn (array $run): array => [
                    (string) ($run['id'] ?? '-'),
                    (string) ($run['operation'] ?? '-'),
                    (string) ($run['status'] ?? '-'),
                    $run['exit_code'] !== null ? (string) $run['exit_code'] : '-',
                    (string) ($run['backup'] ?? '-'),
                    (string) ($run['verification_state'] ?? '-'),
                    (string) ($run['started_at'] ?? '-'),
                    (string) ($run['finished_at'] ?? '-'),
                ],
                $recentRuns,
            ));
        }

        $orderedChecks = $this->orderedChecksForDisplay($reportPayload['health']['checks']);
        $visibleChecks = $this->shouldCollapsePassingChecks()
            ? array_values(array_filter($orderedChecks, static fn (array $check): bool => (string) ($check['status'] ?? '') !== 'pass'))
            : $orderedChecks;

        $this->promptTable(['Check', 'Status', 'Priority', 'Notes'], array_map(
            fn (array $check): array => [
                (string) ($check['check'] ?? '-'),
                (string) ($check['status'] ?? '-'),
                $this->priorityLabel((string) ($check['status'] ?? 'pass')),
                (string) ($check['notes'] ?? '-'),
            ],
            $visibleChecks,
        ));

        if ($this->shouldCollapsePassingChecks()) {
            $suppressedPassChecks = count(array_filter($orderedChecks, static fn (array $check): bool => (string) ($check['status'] ?? '') === 'pass'));

            if ($suppressedPassChecks > 0) {
                $this->line(sprintf('Suppressed %d passing checks (P2/P3). Re-run with -v for full detail.', $suppressedPassChecks));
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function prioritizeChecks(array $checks): array
    {
        usort($checks, static function (array $left, array $right): int {
            $rank = ['fail' => 0, 'warn' => 1, 'pass' => 2];

            return ($rank[(string) $left['status']] ?? 3) <=> ($rank[(string) $right['status']] ?? 3);
        });

        return $checks;
    }
}
