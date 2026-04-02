<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\intro;

final class ReportCommand extends Command
{
    protected $signature = 'db-ops:report {--limit=10 : Number of recent runs to include.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Show checkpoint operational report (table by default, json/agent supported).';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly Repository $config,
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $agentMode = (bool) $this->option('agent');
        $format = (string) $this->option('format');
        $outputMode = $agentMode ? 'agent' : $this->normalizedOutputMode($format);

        if ($this->enhancedInteractiveMode() && $outputMode === 'table') {
            intro('Checkpoint Operational Report');
        }

        if ($outputMode === '') {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        try {
            $this->validator->validate();
            $reportPayload = $this->reportBuilder->reportPayload($effectiveLimit);
            $exitCode = self::SUCCESS;
        } catch (\Throwable $exception) {
            $reportPayload = [
                'recent_runs' => [],
                'summary' => $this->emptySummary(),
                'breakdown' => $this->emptyBreakdown(),
                'verification' => $this->emptyVerificationSummary(),
                'health' => [
                    'ok' => false,
                    'checks' => [[
                        'code' => 'config.validation',
                        'check' => 'Config validation',
                        'status' => 'fail',
                        'notes' => $exception->getMessage(),
                        'data' => [
                            'exception' => $exception::class,
                        ],
                    ]],
                ],
            ];
            $exitCode = self::FAILURE;
        }

        if ($outputMode === 'agent') {
            $this->line(json_encode($this->jsonContract->envelope('report', $this->agentReportPayload(
                requestedLimit: $requestedLimit,
                effectiveLimit: $effectiveLimit,
                reportPayload: $reportPayload,
            )), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($outputMode === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('report', [
                'generated_at' => now()->toIso8601String(),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'limit_requested' => $requestedLimit,
                'limit' => $effectiveLimit,
                'recent_runs' => $reportPayload['recent_runs'],
                'summary' => $reportPayload['summary'],
                'breakdown' => $reportPayload['breakdown'],
                'verification' => $reportPayload['verification'],
                'health' => $reportPayload['health'],
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderTableReport($reportPayload, $requestedLimit, $effectiveLimit);
        }

        return $exitCode;
    }

    /**
     * @return array{requested:int,effective:int}
     */
    private function recentRunLimits(): array
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = max(1, (int) $this->config->get('checkpoint.reporting.max_recent_runs', 100));

        return [
            'requested' => $requestedLimit,
            'effective' => min($requestedLimit, $configuredCap),
        ];
    }

    private function normalizedOutputMode(string $format): string
    {
        return in_array($format, ['table', 'json'], true) ? $format : '';
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
                'recommended_commands' => ['db-ops:enqueue-drill'],
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
    private function agentReportPayload(int $requestedLimit, int $effectiveLimit, array $reportPayload): array
    {
        $checks = $reportPayload['health']['checks'];
        $failedChecks = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnChecks = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $failedRuns = count(array_filter($reportPayload['recent_runs'], static fn (array $run): bool => (string) ($run['status'] ?? '') === 'failed'));

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
                'generated_at' => now()->toIso8601String(),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'limit_requested' => $requestedLimit,
                'limit' => $effectiveLimit,
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
            'suggestions' => $this->reportSuggestions($checks, $failedRuns),
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
     * @return list<string>
     */
    private function reportSuggestions(array $checks, int $failedRuns): array
    {
        $suggestions = [];

        if ($failedRuns > 0) {
            $suggestions[] = 'Inspect failed runs in data.recent_runs and rerun impacted operation with corrected inputs.';
        }

        foreach ($checks as $check) {
            if ((string) $check['status'] === 'pass') {
                continue;
            }

            $code = (string) $check['code'];

            if ($code === 'config.validation') {
                $suggestions[] = 'Resolve config validation failures before executing queue operations.';
            } elseif ($code === 'queue.orphaned_runs') {
                $suggestions[] = 'Run db-ops:recover-orphans and verify worker heartbeat settings.';
            } elseif (str_starts_with($code, 'backup_drill.')) {
                $suggestions[] = 'Run a backup drill and track pass-rate/freshness health signals.';

                $playbookCommands = $check['data']['recommended_commands'] ?? null;

                if (is_array($playbookCommands)) {
                    foreach ($playbookCommands as $command) {
                        if (is_string($command) && trim($command) !== '') {
                            $suggestions[] = 'Run '.$command.' to execute the drill remediation playbook.';
                        }
                    }
                }
            } elseif ($code === 'backup.last_known_good') {
                $suggestions[] = 'Queue a successful backup to refresh the last-known-good signal.';
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderTableReport(array $reportPayload, int $requestedLimit, int $effectiveLimit): void
    {
        $this->table(['Field', 'Value'], [
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
            ['Latest restore post-verification', (string) (($reportPayload['summary']['latest_restore_run']['post_restore_verification']['aggregate_result'] ?? '-') ?? '-')],
            ['Verification runs', (string) ($reportPayload['verification']['total_runs'] ?? 0)],
            ['Verification failed', (string) ($reportPayload['verification']['failed_runs'] ?? 0)],
            ['Verification health', (string) ($reportPayload['verification']['health_status'] ?? 'warn')],
        ]);

        $recentRuns = $reportPayload['recent_runs'];

        if ($recentRuns !== []) {
            $this->table(['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Started', 'Finished'], array_map(
                static fn (array $run): array => [
                    (string) ($run['id'] ?? '-'),
                    (string) ($run['operation'] ?? '-'),
                    (string) ($run['status'] ?? '-'),
                    isset($run['exit_code']) && $run['exit_code'] !== null ? (string) $run['exit_code'] : '-',
                    (string) ($run['backup'] ?? '-'),
                    (string) ($run['verification_state'] ?? '-'),
                    (string) ($run['started_at'] ?? '-'),
                    (string) ($run['finished_at'] ?? '-'),
                ],
                $recentRuns,
            ));
        }

        $this->table(['Check', 'Status', 'Notes'], array_map(
            static fn (array $check): array => [
                (string) ($check['check'] ?? ''),
                (string) ($check['status'] ?? ''),
                (string) ($check['notes'] ?? ''),
            ],
            $reportPayload['health']['checks'],
        ));
    }

    private function enhancedInteractiveMode(): bool
    {
        return $this->input !== null && $this->input->isInteractive() && ! app()->runningUnitTests();
    }

    /**
     * @param  list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>  $indicators
     */
    private function overallSloStatus(array $indicators): string
    {
        foreach ($indicators as $indicator) {
            if ($indicator['status'] === 'fail') {
                return 'fail';
            }
        }

        foreach ($indicators as $indicator) {
            if ($indicator['status'] === 'warn') {
                return 'warn';
            }
        }

        return 'pass';
    }
}
