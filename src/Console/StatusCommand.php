<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Support\Str;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

final class StatusCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--brief : Show triage-first status output with cause and next action.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.} {--policy-profile= : Override gate policy profile for CI/automation.} {--watch= : Poll every N seconds until all running jobs complete.} {--watch-timeout=300 : Maximum seconds to wait when using --watch.}';

    protected $description = 'Show recent checkpoint command runs.';

    public function __construct(
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
        private readonly StatusSloBuilder $sloBuilder,
        private readonly StatusSuggestionsCollector $suggestionsCollector,
        private readonly StatusJsonBuilder $jsonBuilder,
        private readonly StatusTableDataBuilder $tableDataBuilder,
        private readonly StatusWatchPoller $watchPoller,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->handleStatus();
    }

    private function handleStatus(): int
    {
        $format = $this->stringOption('format') ?? 'table';
        $agentMode = (bool) $this->option('agent');
        $summaryMode = (bool) $this->option('summary');
        $briefMode = (bool) $this->option('brief');
        $gateDecision = $this->evaluatePolicyGate();

        if ($this->enhancedInteractiveMode() && ! $agentMode && $format === 'table') {
            intro($summaryMode ? 'Checkpoint Status Summary' : 'Checkpoint Status: Recent Runs');
            note('What: operational view of recent runs and summary signals.');
            note('When: immediately after queueing, or during incident triage.');
            note('Next: run checkpoint:doctor:health for deeper health diagnostics.');
        }

        if (! $agentMode && ! collect(['table', 'json', 'compact-json'])->containsStrict($format)) {
            $this->promptError('The --format option must be table, json, or compact-json.');

            return self::FAILURE;
        }

        if ($briefMode) {
            return $this->renderBrief($format, $agentMode, $gateDecision);
        }

        if ($summaryMode) {
            return $this->renderSummary($format, $agentMode, $gateDecision);
        }

        $limit = $this->recentRunLimit();
        $runs = $this->reportBuilder->recentRuns($limit);

        if ($agentMode) {
            return $this->renderRunsAgent($runs, $limit, $gateDecision);
        }

        if ($format === 'json' || $format === 'compact-json') {
            return $this->renderRunsJson($format, $runs, $limit, $gateDecision);
        }

        return $this->renderRunsTable($runs, $gateDecision);
    }

    private function evaluatePolicyGate(): array
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
    private function renderRunsAgent(array $runs, int $limit, array $gateDecision): int
    {
        $this->line(json_encode(
            $this->jsonBuilder->buildRunsAgentPayload($runs, $limit, $gateDecision),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsJson(string $format, array $runs, int $limit, array $gateDecision): int
    {
        $this->line(json_encode(
            $this->jsonBuilder->buildRunsJsonPayload($format, $runs, $limit, $gateDecision),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsTable(array $runs, array $gateDecision): int
    {
        $table = $this->tableDataBuilder->buildRunsTable($runs);
        $this->promptTable($table['headers'], $table['rows']);

        $watchInterval = $this->watchInterval();

        if ($watchInterval !== null) {
            return $this->pollUntilComplete($watchInterval);
        }

        return $gateDecision['exit_code'];
    }

    private function renderSummary(string $format, bool $agentMode, array $gateDecision): int
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);

        if ($agentMode) {
            $this->line(json_encode(
                $this->jsonBuilder->buildSummaryAgentPayload($summary, $failedRuns24h, $gateDecision),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $gateDecision['exit_code'];
        }

        if ($format === 'json' || $format === 'compact-json') {
            $this->line(json_encode(
                $this->jsonBuilder->buildSummaryJsonPayload($format, $summary, $gateDecision),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $gateDecision['exit_code'];
        }

        $table = $this->tableDataBuilder->buildSummaryTable($summary);
        $this->promptTable($table['headers'], $table['rows']);

        return $gateDecision['exit_code'];
    }

    private function renderBrief(string $format, bool $agentMode, array $gateDecision): int
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingRuns = (int) ($summary['pending_runs'] ?? 0);
        $runningRuns = (int) ($summary['running_runs'] ?? 0);
        $latestFailedRun = is_array($summary['latest_failed_run'] ?? null) ? $summary['latest_failed_run'] : [];
        $label = (string) ($latestFailedRun['label'] ?? '-');
        $reason = (string) ($latestFailedRun['failure_reason'] ?? 'No recent failed run reason available.');
        $actionNow = (string) ($latestFailedRun['next_action'] ?? 'Run php artisan checkpoint:doctor:report --limit=10 --format=json for full failure context.');

        if ($agentMode) {
            $this->line(json_encode(
                $this->jsonBuilder->buildBriefAgentPayload($failedRuns24h, $pendingRuns, $runningRuns, $latestFailedRun, $actionNow, $summary, $gateDecision),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $gateDecision['exit_code'];
        }

        if ($format === 'json' || $format === 'compact-json') {
            $this->line(json_encode(
                $this->jsonBuilder->buildBriefJsonPayload($format, $failedRuns24h, $pendingRuns, $runningRuns, $latestFailedRun, $actionNow, $gateDecision),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $gateDecision['exit_code'];
        }

        $this->renderBriefTable($failedRuns24h, $pendingRuns, $runningRuns, $label, $reason, $actionNow);

        return $gateDecision['exit_code'];
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
