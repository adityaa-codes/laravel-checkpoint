<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Rendering\DoctorJsonRenderer;
use AdityaaCodes\LaravelCheckpoint\Rendering\DoctorTableRenderer;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\GateDecision;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

use function Laravel\Prompts\intro;

final class StatusCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--brief : Show triage-first status output with cause and next action.} {--format=table : Output format: table or json.} {--policy-profile= : Override gate policy profile for CI/automation.} {--watch= : Poll every N seconds until all running jobs complete.} {--watch-timeout=300 : Maximum seconds to wait when using --watch.} {--health : Show health checks (was doctor:health).} {--full : Show full operational report (was doctor:report).}';

    protected $description = 'Show checkpoint status, health, or full operational report.';

    public function __construct(
        private readonly Repository $config,
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
        private readonly StatusJsonBuilder $jsonBuilder,
        private readonly StatusTableDataBuilder $tableDataBuilder,
        private readonly StatusWatchPoller $watchPoller,
        private readonly DoctorTableRenderer $tableRenderer,
        private readonly DoctorJsonRenderer $jsonRenderer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requestedModes = collect(['health', 'full', 'summary', 'watch'])
            ->filter(fn (string $mode): bool => $mode === 'watch'
                ? $this->stringOption('watch') !== null
                : (bool) $this->option($mode))
            ->count();

        if ($requestedModes > 1) {
            $this->promptError('The --health, --full, --summary, and --watch options are mutually exclusive.');

            return self::FAILURE;
        }

        if ((bool) $this->option('health')) {
            return $this->handleHealth();
        }

        if ((bool) $this->option('full')) {
            return $this->handleFullReport();
        }

        return $this->handleStatus();
    }

    private function handleHealth(): int
    {
        $format = $this->stringOption('format') ?? 'table';
        $briefMode = (bool) $this->option('brief');

        if ($this->enhancedInteractiveMode() && $format === 'table') {
            intro('Checkpoint Doctor: Health Checks');
        }

        try {
            $checks = $this->reportBuilder->healthChecks();
            $gateDecision = $this->gatePolicyEvaluator->evaluate($checks, $this->reportBuilder->summary(), $this->policyProfileOverride());
        } catch (\Throwable $exception) {
            report($exception);

            $checks = [[
                'code' => 'health.error',
                'check' => 'Health check execution',
                'status' => 'fail',
                'severity' => 'blocker',
                'notes' => $exception->getMessage(),
                'data' => ['exception' => $exception::class],
            ]];
            $gateDecision = new GateDecision('unknown', 'default', 'fail', 'policy', 12);
        }

        if ($format === 'json') {
            $this->line($this->jsonRenderer->jsonReport($checks, $briefMode, $gateDecision, false));

            return $gateDecision->exitCode;
        }

        $this->tableRenderer->renderHealthTable($this, $checks, $briefMode);

        return $gateDecision->exitCode;
    }

    private function handleFullReport(): int
    {
        $format = $this->stringOption('format') ?? 'table';
        $briefMode = (bool) $this->option('brief');
        $policyProfile = $this->policyProfileOverride();

        if ($this->enhancedInteractiveMode() && $format === 'table') {
            intro('Checkpoint Operational Report');
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        $reportPayload = $this->buildReportPayload($effectiveLimit);

        $gateDecision = $this->gatePolicyEvaluator->evaluate(
            $reportPayload['health']['checks'],
            $reportPayload['summary'],
            $policyProfile,
        );

        if ($format === 'json') {
            $this->line($this->jsonRenderer->renderReportJsonOutput($reportPayload, $requestedLimit, $effectiveLimit, $briefMode, $gateDecision, false));
        } else {
            $this->tableRenderer->renderReportTableReport($this, $reportPayload, $requestedLimit, $effectiveLimit, $briefMode);
        }

        return $gateDecision->exitCode;
    }

    /**
     * @return array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}
     */
    private function buildReportPayload(int $effectiveLimit): array
    {
        try {
            return $this->reportBuilder->reportPayload($effectiveLimit);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'recent_runs' => [],
                'summary' => $this->reportEmptySummary(),
                'breakdown' => $this->reportEmptyBreakdown(),
                'verification' => $this->reportEmptyVerificationSummary(),
                'health' => [
                    'ok' => false,
                    'checks' => [[
                        'code' => 'report.error',
                        'check' => 'Report execution',
                        'status' => 'fail',
                        'notes' => $exception->getMessage(),
                        'data' => ['exception' => $exception::class],
                    ]],
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptySummary(): array
    {
        $windowDays = max(1, (int) $this->config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30));
        $drillPassRate = $this->reportEmptyDrillPassRate($windowDays);

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
            'backup_drill_trend' => $this->reportEmptyDrillTrend($windowDays),
            'backup_drill_remediation_playbook' => $this->reportEmptyDrillRemediationPlaybook($windowDays),
            'latest_restore_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'target' => null, 'audit' => null],
            'latest_restore_failure' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'target' => null],
            'latest_failed_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptyDrillPassRate(int $windowDays): array
    {
        return [
            'label' => '-',
            'window_days' => $windowDays,
            'total' => 0,
            'passing' => 0,
            'pass_rate_percent' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptyDrillTrend(int $windowDays): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptyDrillRemediationPlaybook(int $windowDays): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reportEmptyBreakdown(): array
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
    private function reportEmptyVerificationSummary(): array
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

    private function handleStatus(): int
    {
        $format = $this->stringOption('format') ?? 'table';
        $summaryMode = (bool) $this->option('summary');
        $briefMode = (bool) $this->option('brief');
        $gateDecision = $this->evaluatePolicyGate();

        if ($this->enhancedInteractiveMode() && $format === 'table') {
            intro($summaryMode ? 'Checkpoint Status Summary' : 'Checkpoint Status: Recent Runs');
        }

        if (! collect(['table', 'json'])->containsStrict($format)) {
            $this->promptError('The --format option must be table or json.');

            return self::FAILURE;
        }

        if ($briefMode) {
            return $this->renderBrief($format, $gateDecision);
        }

        if ($summaryMode) {
            return $this->renderSummary($format, $gateDecision);
        }

        $limit = $this->recentRunLimit();
        $runs = $this->reportBuilder->recentRuns($limit);

        if ($format === 'json') {
            return $this->renderRunsJson($format, $runs, $limit, $gateDecision);
        }

        return $this->renderRunsTable($runs, $gateDecision);
    }

    private function evaluatePolicyGate(): GateDecision
    {
        return $this->gatePolicyEvaluator->evaluate(
            $this->reportBuilder->healthChecks(),
            $this->reportBuilder->summary(),
            $this->policyProfileOverride(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsJson(string $format, array $runs, int $limit, GateDecision $gateDecision): int
    {
        $this->line(json_encode(
            $this->jsonBuilder->buildRunsJsonPayload($format, $runs, $limit, $gateDecision),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $gateDecision->exitCode;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsTable(array $runs, GateDecision $gateDecision): int
    {
        $table = $this->tableDataBuilder->buildRunsTable($runs);
        $this->promptTable($table['headers'], $table['rows']);

        $watchInterval = $this->watchInterval();

        if ($watchInterval !== null) {
            return $this->pollUntilComplete($watchInterval);
        }

        return $gateDecision->exitCode;
    }

    private function renderSummary(string $format, GateDecision $gateDecision): int
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);

        if ($format === 'json') {
            $this->line(json_encode(
                $this->jsonBuilder->buildSummaryJsonPayload($format, $summary, $gateDecision),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $gateDecision->exitCode;
        }

        $table = $this->tableDataBuilder->buildSummaryTable($summary);
        $this->promptTable($table['headers'], $table['rows']);

        return $gateDecision->exitCode;
    }

    private function renderBrief(string $format, GateDecision $gateDecision): int
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingRuns = (int) ($summary['pending_runs'] ?? 0);
        $runningRuns = (int) ($summary['running_runs'] ?? 0);
        $latestFailedRun = is_array($summary['latest_failed_run'] ?? null) ? $summary['latest_failed_run'] : [];
        $label = (string) ($latestFailedRun['label'] ?? '-');
        $reason = (string) ($latestFailedRun['failure_reason'] ?? 'No recent failed run reason available.');
        $actionNow = (string) ($latestFailedRun['next_action'] ?? 'Run php artisan checkpoint:status --full --limit=10 --format=json for full failure context.');

        if ($format === 'json') {
            $this->line(json_encode(
                $this->jsonBuilder->buildBriefJsonPayload($format, $failedRuns24h, $pendingRuns, $runningRuns, $latestFailedRun, $actionNow, $gateDecision),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $gateDecision->exitCode;
        }

        $this->renderBriefTable($failedRuns24h, $pendingRuns, $runningRuns, $label, $reason, $actionNow);

        return $gateDecision->exitCode;
    }

    private function renderBriefTable(int $failedRuns24h, int $pendingRuns, int $runningRuns, string $label, string $reason, string $actionNow): void
    {
        $lines = $this->tableDataBuilder->buildBriefTableLines($failedRuns24h, $pendingRuns, $runningRuns, $label, $reason, $actionNow);

        foreach ($lines as $line) {
            $this->line($line);
        }

        if ($this->output->isVerbose()) {
            $passingLines = $this->tableDataBuilder->buildPassingRunLines();

            if ($passingLines !== []) {
                $this->newLine();
                $this->promptInfo('Recent passing runs:');
                $this->newLine();

                foreach ($passingLines as $passingLine) {
                    $this->line($passingLine);
                }
            }
        }

        if ($this->output->isVeryVerbose()) {
            foreach ($this->tableDataBuilder->buildFailedOutputSnippets() as $snippet) {
                $this->newLine();
                $this->promptWarning(sprintf('--- Failed run #%d (%s) ---', $snippet['runId'], $snippet['operation']));
                $this->line($snippet['output']);

                if ($snippet['truncated']) {
                    $this->line('... (truncated)');
                }
            }
        }
    }

    private function recentRunLimit(): int
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = (int) config('checkpoint.reporting.max_recent_runs', 100);

        if ($configuredCap < 1) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must be greater than zero.');
        }

        if ($configuredCap > 1000) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must not exceed 1000.');
        }

        return min($requestedLimit, $configuredCap);
    }

    private function watchInterval(): ?int
    {
        $watch = $this->option('watch');

        if ($watch === null || $watch === false) {
            return null;
        }

        if ($watch === true || (is_string($watch) && Str::trim($watch) === '')) {
            return 10;
        }

        return max(1, (int) $watch);
    }

    private function pollUntilComplete(int $intervalSeconds): int
    {
        $startTime = time();
        $timeout = (int) $this->option('watch-timeout');
        $backoff = $this->watchPoller->initialBackoff($intervalSeconds);
        $iteration = 0;

        while (true) {
            $result = $this->watchPoller->poll($intervalSeconds, $timeout, $startTime, $iteration, $backoff);

            if ($result === null) {
                return self::FAILURE;
            }

            if (($result['completed'] ?? false) === true) {
                $this->promptInfo(sprintf('All jobs completed after %ds.', $result['elapsed']));

                return self::SUCCESS;
            }

            if (($result['timedOut'] ?? false) === true) {
                $this->promptWarning(sprintf('Watch timed out after %ds. %d job(s) still running.', $result['elapsed'], $result['remainingJobs']));

                return self::FAILURE;
            }

            if (($result['isFirstIteration'] ?? false) === true) {
                $this->promptInfo(sprintf('Waiting for %d running/pending job(s). Initial poll interval: %ds, timeout: %ds.', $result['runningCount'], $intervalSeconds, $timeout));
            } elseif (($result['isMilestoneIteration'] ?? false) === true) {
                $this->promptWarning(sprintf('Still waiting... %d job(s) remaining (elapsed: %ds, backoff: %ds).', $result['runningCount'], $result['elapsed'], $backoff));
            }

            $backoff = (int) ($result['nextBackoff'] ?? $backoff);
            $iteration = (int) ($result['nextIteration'] ?? $iteration);
        }
    }
}
