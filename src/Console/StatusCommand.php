<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildBackupCatalogExportAction;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

final class StatusCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--brief : Show triage-first status output with cause and next action.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.} {--policy-profile= : Override gate policy profile for CI/automation.} {--watch= : Poll every N seconds until all running jobs complete.} {--catalog : Export backup catalog instead of showing status.} {--output= : Destination file path for catalog export.} {--driver= : Filter catalog by driver name.} {--repository= : Filter catalog by repository id.} {--stanza= : Filter catalog by stanza.} {--window= : Filter catalog to runs within last N hours.}';

    protected $description = 'Show recent checkpoint command runs or export backup catalog.';

    public function __construct(
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
        private readonly BuildBackupCatalogExportAction $buildCatalogExport,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((bool) $this->option('catalog')) {
            return $this->handleCatalogExport();
        }

        return $this->handleStatus();
    }

    private function handleCatalogExport(): int
    {
        if ($this->enhancedInteractiveMode()) {
            note('What: export catalog rows for audits, tooling, and external analysis.');
            note('When: compliance reporting and integration pipelines.');
            note('Next: feed exported data into your downstream governance/reporting systems.');
        }

        $format = $this->stringOption('format') ?? 'table';

        if ($format === 'table') {
            $format = 'json';
        }

        $validationError = $this->validateCatalogExportOptions($format);

        if ($validationError !== null) {
            return $validationError;
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        $export = $this->buildCatalogExport->execute(
            driverFilter: $this->normalizedCatalogTextFilter($this->stringOption('driver')),
            repositoryFilter: $this->normalizedCatalogRepositoryFilter(),
            stanzaFilter: $this->normalizedCatalogTextFilter($this->stringOption('stanza')),
            windowHours: $this->catalogWindowHours(),
            limit: $effectiveLimit,
        );

        if ($format === 'json') {
            return $this->renderCatalogJsonOutput($requestedLimit, $effectiveLimit, $export);
        }

        return $this->renderCatalogCsvOutput($export['rows']);
    }

    private function evaluatePolicyGate(): array
    {
        return $this->gatePolicyEvaluator->evaluate(
            $this->reportBuilder->healthChecks(),
            $this->reportBuilder->summary(),
            $this->policyProfileOverride(),
        );
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
            note('Next: run checkpoint:doctor for deeper health diagnostics.');
        }

        if (! $agentMode && ! in_array($format, ['table', 'json', 'compact-json'], true)) {
            $this->promptError('The --format option must be table, json, or compact-json.');

            return self::FAILURE;
        }

        if ($briefMode) {
            $this->renderBrief($format, $agentMode, $gateDecision);

            return $gateDecision['exit_code'];
        }

        if ($summaryMode) {
            $this->renderSummary($format, $agentMode, $gateDecision);

            return $gateDecision['exit_code'];
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

    private function validateCatalogExportOptions(string $format): ?int
    {
        if (! in_array($format, ['json', 'csv'], true)) {
            $this->promptError('With --catalog, the --format option must be json or csv.');

            return self::FAILURE;
        }

        $outputPath = $this->stringOption('output');

        if ($outputPath !== null && trim($outputPath) === '') {
            $this->promptError('The --output option must not be empty.');

            return self::FAILURE;
        }

        $repositoryFilter = $this->normalizedCatalogRepositoryFilter();
        $windowHours = $this->catalogWindowHours();

        if ($repositoryFilter === null && $this->option('repository') !== null) {
            $this->promptError('The --repository option must be an integer or "none".');

            return self::FAILURE;
        }

        if ($windowHours === null && $this->option('window') !== null) {
            $this->promptError('The --window option must be a positive integer.');

            return self::FAILURE;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $export
     */
    private function renderCatalogJsonOutput(int $requestedLimit, int $effectiveLimit, array $export): int
    {
        $payload = json_encode($this->jsonContract->envelope('catalog_export', [
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'format' => 'json',
            'limit_requested' => $requestedLimit,
            'limit' => $effectiveLimit,
            'filters' => $export['filters'],
            'count' => count($export['rows']),
            'rows' => $export['rows'],
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! $this->writeCatalogExportFile($payload)) {
            $this->line($payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderCatalogCsvOutput(array $rows): int
    {
        $payload = $this->catalogCsvPayload($rows);

        if (! $this->writeCatalogExportFile($payload)) {
            $this->line($payload);
        }

        return self::SUCCESS;
    }

    private function normalizedCatalogRepositoryFilter(): int|string|null
    {
        $repository = $this->stringOption('repository');

        if ($repository === null) {
            return null;
        }

        if ($repository === 'none') {
            return 'none';
        }

        if (! preg_match('/^\d+$/', $repository)) {
            return null;
        }

        return (int) $repository;
    }

    private function catalogWindowHours(): ?int
    {
        $window = $this->stringOption('window');

        if ($window === null || $window === '') {
            return null;
        }

        if (! preg_match('/^\d+$/', $window)) {
            return null;
        }

        $hours = (int) $window;

        if ($hours < 1) {
            return null;
        }

        return $hours;
    }

    private function normalizedCatalogTextFilter(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function writeCatalogExportFile(string $payload): bool
    {
        $outputPath = $this->stringOption('output');

        if ($outputPath === null) {
            return false;
        }

        $trimmed = trim($outputPath);

        if (file_put_contents($trimmed, $payload) === false) {
            throw new ConfigurationException(sprintf('Unable to write catalog export to [%s].', $trimmed));
        }

        $this->promptInfo(sprintf('Catalog export written to %s', $trimmed));

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function catalogCsvPayload(array $rows): string
    {
        $lines = [$this->catalogCsvLine([
            'command_run_id',
            'operation',
            'driver',
            'repository',
            'stanza',
            'type',
            'label',
            'path',
            'size_bytes',
            'status',
            'verification_state',
            'created_at',
            'started_at',
            'finished_at',
            'verified_at',
            'last_known_good_at',
            'latest_verification_json',
            'metadata_json',
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->catalogCsvLine([
                $row['command_run_id'] ?? null,
                $row['operation'] ?? null,
                $row['driver'] ?? null,
                $row['repository'] ?? null,
                $row['stanza'] ?? null,
                $row['type'] ?? null,
                $row['label'] ?? null,
                $row['path'] ?? null,
                $row['size_bytes'] ?? null,
                $row['status'] ?? null,
                $row['verification_state'] ?? null,
                $row['created_at'] ?? null,
                $row['started_at'] ?? null,
                $row['finished_at'] ?? null,
                $row['verified_at'] ?? null,
                $row['last_known_good_at'] ?? null,
                json_encode($row['latest_verification'] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($row['metadata'] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function catalogCsvLine(array $values): string
    {
        return implode(',', array_map(function (mixed $value): string {
            $text = $value === null ? '' : (string) $value;

            return '"'.str_replace('"', '""', $text).'"';
        }, $values));
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsAgent(array $runs, int $limit, array $gateDecision): int
    {
        $failedRuns = count(array_filter($runs, static fn (array $run): bool => (string) ($run['status'] ?? '') === 'failed'));
        $runCount = count($runs);
        $this->line(json_encode($this->jsonContract->envelope('status', [
            'result' => $failedRuns > 0 ? 'failed' : 'passed',
            'code' => $failedRuns > 0 ? 'status.runs.failed' : 'status.runs.ok',
            'summary' => sprintf('%d failed run(s) in the latest %d run(s).', $failedRuns, $runCount),
            'data' => [
                'mode' => 'runs',
                'limit' => $limit,
                'run_count' => $runCount,
                'failed_run_count' => $failedRuns,
                'runs' => $runs,
                'slo' => $this->runsSlo($runCount, $failedRuns),
                'gates' => $gateDecision,
            ],
            'suggestions' => $this->replicationFailureSuggestions($runs),
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsJson(string $format, array $runs, int $limit, array $gateDecision): int
    {
        $compactJson = $format === 'compact-json';
        $payload = [
            'mode' => 'runs',
            'limit' => $limit,
            'runs' => $runs,
            'gates' => $this->machineGateDecision($gateDecision),
        ];

        $payload = $compactJson
            ? $this->jsonContract->compactEnvelope('status', $payload)
            : $this->jsonContract->envelope('status', $payload);

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $gateDecision['exit_code'];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRunsTable(array $runs, array $gateDecision): int
    {
        $this->promptTable([
            'ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished',
        ], array_map(fn (array $run): array => [
            (string) $run['id'],
            (string) $run['operation'],
            $this->coloredStatus((string) $run['status']),
            $run['exit_code'] !== null ? (string) $run['exit_code'] : '-',
            (string) ($run['backup'] ?? '-'),
            $run['verification_state'] ?? '-',
            $run['last_known_good_at'] ?? '-',
            $run['started_at'] ?? '-',
            $run['finished_at'] ?? '-',
        ], $runs));

        $watchInterval = $this->watchInterval();

        if ($watchInterval !== null) {
            return $this->pollUntilComplete($watchInterval);
        }

        return $gateDecision['exit_code'];
    }

    private function renderSummary(string $format, bool $agentMode, array $gateDecision): void
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);

        if ($agentMode) {
            $this->renderSummaryAgent($summary, $failedRuns24h, $gateDecision);

            return;
        }

        if ($format === 'json' || $format === 'compact-json') {
            $this->renderSummaryJson($format, $summary, $gateDecision);

            return;
        }

        $this->renderSummaryTable($summary);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummaryAgent(array $summary, int $failedRuns24h, array $gateDecision): void
    {
        $this->line(json_encode($this->jsonContract->envelope('status', [
            'result' => $failedRuns24h > 0 ? 'partial' : 'passed',
            'code' => $failedRuns24h > 0 ? 'status.summary.degraded' : 'status.summary.ok',
            'summary' => sprintf(
                'Pending: %d, Running: %d, Failed (24h): %d.',
                (int) ($summary['pending_runs'] ?? 0),
                (int) ($summary['running_runs'] ?? 0),
                $failedRuns24h,
            ),
            'data' => [
                'mode' => 'summary',
                'summary' => $summary,
                'slo' => $this->summarySlo($summary),
                'gates' => $gateDecision,
            ],
            'suggestions' => $this->summarySuggestions($summary),
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummaryJson(string $format, array $summary, array $gateDecision): void
    {
        $compactJson = $format === 'compact-json';
        $payload = [
            'mode' => 'summary',
            'summary' => $summary,
            'gates' => $this->machineGateDecision($gateDecision),
        ];

        $payload = $compactJson
            ? $this->jsonContract->compactEnvelope('status', $payload)
            : $this->jsonContract->envelope('status', $payload);

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummaryTable(array $summary): void
    {
        $windowDays = (int) ($summary['backup_drill_pass_rate']['window_days'] ?? 30);

        $this->promptTable(['Signal', 'Value'], [
            ['Pending runs', (string) $summary['pending_runs']],
            ['Running runs', (string) $summary['running_runs']],
            ['Failed runs (24h)', (string) $summary['failed_runs_24h']],
            ['Latest failed run', $summary['latest_failed_run']['label'] ?? '-'],
            ['Latest failed reason', $summary['latest_failed_run']['failure_reason'] ?? '-'],
            ['Latest failed next action', $summary['latest_failed_run']['next_action'] ?? '-'],
            ['Last known good backup', $summary['last_known_good_backup']['label'] ?? '-'],
            ['Latest verified backup', $summary['latest_verified_backup']['label'] ?? '-'],
            ['Latest backup drill', $summary['latest_backup_drill']['label'] ?? '-'],
            ['Latest failed drill', $summary['latest_failed_backup_drill']['label'] ?? '-'],
            [sprintf('Backup drill pass rate (%dd)', $windowDays), $summary['backup_drill_pass_rate']['label'] ?? '-'],
            ['Backup drill trend', $summary['backup_drill_trend']['label'] ?? '-'],
            ['Backup drill playbook', $summary['backup_drill_remediation_playbook']['title'] ?? '-'],
            ['Latest restore run', $summary['latest_restore_run']['label'] ?? '-'],
            ['Latest restore failure', $summary['latest_restore_failure']['label'] ?? '-'],
        ]);
    }

    private function renderBrief(string $format, bool $agentMode, array $gateDecision): void
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingRuns = (int) ($summary['pending_runs'] ?? 0);
        $runningRuns = (int) ($summary['running_runs'] ?? 0);
        $latestFailedRun = is_array($summary['latest_failed_run'] ?? null) ? $summary['latest_failed_run'] : [];
        $label = (string) ($latestFailedRun['label'] ?? '-');
        $reason = (string) ($latestFailedRun['failure_reason'] ?? 'No recent failed run reason available.');
        $actionNow = (string) ($latestFailedRun['next_action'] ?? 'Run php artisan checkpoint:doctor --full --limit=10 --format=json for full failure context.');

        if ($agentMode) {
            $this->renderBriefAgent($failedRuns24h, $pendingRuns, $runningRuns, $latestFailedRun, $actionNow, $summary, $gateDecision);

            return;
        }

        if ($format === 'json' || $format === 'compact-json') {
            $this->renderBriefJson($format, $failedRuns24h, $pendingRuns, $runningRuns, $latestFailedRun, $actionNow, $gateDecision);

            return;
        }

        $this->renderBriefTable($failedRuns24h, $pendingRuns, $runningRuns, $label, $reason, $actionNow);
    }

    /**
     * @param  array<string, mixed>  $latestFailedRun
     * @param  array<string, mixed>  $summary
     */
    private function renderBriefAgent(int $failedRuns24h, int $pendingRuns, int $runningRuns, array $latestFailedRun, string $actionNow, array $summary, array $gateDecision): void
    {
        $this->line(json_encode($this->jsonContract->envelope('status', [
            'result' => $failedRuns24h > 0 ? 'partial' : 'passed',
            'code' => $failedRuns24h > 0 ? 'status.brief.degraded' : 'status.brief.ok',
            'summary' => sprintf('Failed (24h): %d, Pending: %d, Running: %d.', $failedRuns24h, $pendingRuns, $runningRuns),
            'data' => [
                'mode' => 'brief',
                'failed_runs_24h' => $failedRuns24h,
                'pending_runs' => $pendingRuns,
                'running_runs' => $runningRuns,
                'last_failed_run' => $latestFailedRun,
                'action_now' => $actionNow,
                'gates' => $gateDecision,
            ],
            'suggestions' => $this->summarySuggestions($summary),
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $latestFailedRun
     */
    private function renderBriefJson(string $format, int $failedRuns24h, int $pendingRuns, int $runningRuns, array $latestFailedRun, string $actionNow, array $gateDecision): void
    {
        $compactJson = $format === 'compact-json';
        $payload = [
            'mode' => 'brief',
            'failed_runs_24h' => $failedRuns24h,
            'pending_runs' => $pendingRuns,
            'running_runs' => $runningRuns,
            'last_failed_run' => $latestFailedRun,
            'action_now' => $actionNow,
            'gates' => $this->machineGateDecision($gateDecision),
        ];

        $payload = $compactJson
            ? $this->jsonContract->compactEnvelope('status', $payload)
            : $this->jsonContract->envelope('status', $payload);

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function renderBriefTable(int $failedRuns24h, int $pendingRuns, int $runningRuns, string $label, string $reason, string $actionNow): void
    {
        $this->line('Checkpoint triage (brief)');
        $this->line(sprintf('Failed (24h): %d | Pending: %d | Running: %d', $failedRuns24h, $pendingRuns, $runningRuns));
        $this->line('Last failed: '.$label);
        $this->line('Cause: '.$reason);
        $this->line('Action now: '.$actionNow);
        $this->line('Deep dive: php artisan checkpoint:doctor --full --limit=10 --format=json');

        if ($this->output->isVerbose()) {
            $this->renderBriefPassingRuns();
        }

        if ($this->output->isVeryVerbose()) {
            $this->renderBriefFailedOutput();
        }
    }

    private function coloredStatus(string $status): string
    {
        $label = $this->statusLabel($status);

        return match ($status) {
            'pending' => sprintf('<comment>%s</comment>', $label),
            'running' => sprintf('<info>%s</info>', $label),
            'succeeded' => sprintf('<fg=green>%s</>', $label),
            'failed' => sprintf('<error>%s</error>', $label),
            'cancelled' => sprintf('<fg=gray>%s</>', $label),
            default => $label,
        };
    }

    private function statusLabel(string $status): string
    {
        $label = __('messages.status.'.$status);

        if ($label !== 'messages.status.'.$status) {
            return $label;
        }

        return str($status)->title()->toString();
    }

    private function recentRunLimit(): int
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = (int) $this->config->get('checkpoint.reporting.max_recent_runs', 100);

        if ($configuredCap < 1) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must be greater than zero.');
        }

        if ($configuredCap > 1000) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must not exceed 1000.');
        }

        return min($requestedLimit, $configuredCap);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<string>
     */
    private function replicationFailureSuggestions(array $runs): array
    {
        $suggestions = [];

        foreach ($runs as $run) {
            $analysis = $run['replication']['failure_analysis'] ?? null;

            if (! is_array($analysis)) {
                continue;
            }

            foreach (['immediate', 'deeper'] as $bucket) {
                $candidate = $analysis['suggestions'][$bucket] ?? null;

                if (! is_array($candidate)) {
                    continue;
                }

                foreach ($candidate as $suggestion) {
                    if (! is_string($suggestion)) {
                        continue;
                    }
                    if (trim($suggestion) === '') {
                        continue;
                    }
                    $suggestions[] = trim($suggestion);
                }
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function summarySuggestions(array $summary, bool $compact = false): array
    {
        $suggestions = [];

        if ((int) ($summary['failed_runs_24h'] ?? 0) > 0) {
            $suggestions[] = $compact ? 'Inspect recent runs' : 'Run checkpoint:status --format=json to inspect failed runs and statuses.';
        }

        $latestFailedRun = $summary['latest_failed_run'] ?? null;

        if (is_array($latestFailedRun) && is_string($latestFailedRun['next_action'] ?? null) && trim($latestFailedRun['next_action']) !== '') {
            $suggestions[] = trim($latestFailedRun['next_action']);
        }

        if ((int) ($summary['pending_runs'] ?? 0) > 0) {
            $suggestions[] = $compact ? 'Scale queue workers' : 'Start or scale queue workers for the db-ops queue to drain pending runs.';
        }

        if ((int) ($summary['running_runs'] ?? 0) > 0) {
            $suggestions[] = $compact ? 'checkpoint:doctor --agent' : 'Run checkpoint:doctor --format=json to check queue heartbeat and orphan signals.';
        }

        $playbook = $summary['backup_drill_remediation_playbook'] ?? null;

        if (is_array($playbook)) {
            $commands = $playbook['recommended_commands'] ?? [];

            if (is_array($commands)) {
                foreach ($commands as $command) {
                    if (is_string($command) && trim($command) !== '') {
                        $suggestions[] = $compact ? $command : 'Run '.$command.' to remediate drill posture.';
                    }
                }
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function runsSlo(int $runCount, int $failedRuns): array
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
    private function summarySlo(array $summary): array
    {
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingRuns = (int) ($summary['pending_runs'] ?? 0);
        $runningRuns = (int) ($summary['running_runs'] ?? 0);
        $drillTarget = (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0);
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

    private function watchInterval(): ?int
    {
        $watch = $this->option('watch');

        if ($watch === null || $watch === false) {
            return null;
        }

        if ($watch === true || (is_string($watch) && trim($watch) === '')) {
            return 10;
        }

        return max(1, (int) $watch);
    }

    private function pollUntilComplete(int $intervalSeconds): int
    {
        $startTime = time();
        $maxIterations = 120;

        for ($i = 0; $i < $maxIterations; $i++) {
            $runningCount = CommandRun::query()
                ->whereIn('status', ['pending', 'running'])
                ->count();

            if ($runningCount === 0) {
                $elapsed = time() - $startTime;
                $this->promptInfo(sprintf('All jobs completed after %ds.', $elapsed));

                return self::SUCCESS;
            }

            $elapsed = time() - $startTime;

            if ($i === 0) {
                $this->promptInfo(sprintf('Waiting for %d running/pending job(s). Polling every %ds.', $runningCount, $intervalSeconds));
            } elseif ($i % 5 === 0) {
                $this->promptInfo(sprintf('Still waiting... %d job(s) remaining (elapsed: %ds, iteration: %d/%d).', $runningCount, $elapsed, $i, $maxIterations));
            }

            sleep($intervalSeconds);
        }

        $elapsed = time() - $startTime;
        $remainingJobs = CommandRun::query()->whereIn('status', ['pending', 'running'])->count();
        $this->promptWarning(sprintf('Polling timed out after %ds (%d iterations). %d job(s) still running.', $elapsed, $maxIterations, $remainingJobs));

        return self::FAILURE;
    }

    private function renderBriefPassingRuns(): void
    {
        $runs = CommandRun::query()
            ->where('status', 'succeeded')
            ->latest('id')
            ->limit(10)
            ->get();

        if ($runs->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->promptInfo('Recent passing runs:');
        $this->newLine();

        foreach ($runs as $run) {
            $this->line(sprintf('  #%d %s — %s', $run->getKey(), $run->operation, $run->finished_at?->diffForHumans() ?? '-'));
        }
    }

    private function renderBriefFailedOutput(): void
    {
        $runs = CommandRun::query()
            ->where('status', 'failed')
            ->whereNotNull('command_output')
            ->latest('id')
            ->limit(3)
            ->get();

        if ($runs->isEmpty()) {
            return;
        }

        foreach ($runs as $run) {
            $output = (string) ($run->command_output ?? '');
            $snippet = mb_substr($output, 0, 500);

            if ($snippet === '') {
                continue;
            }

            $this->newLine();
            $this->promptWarning(sprintf('--- Failed run #%d (%s) ---', $run->getKey(), $run->operation));
            $this->line($snippet);

            if (mb_strlen($output) > 500) {
                $this->line('... (truncated)');
            }
        }
    }
}
