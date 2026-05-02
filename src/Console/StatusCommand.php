<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

final class StatusCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--brief : Show triage-first status output with cause and next action.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.} {--policy-profile= : Override gate policy profile for CI/automation.}';

    protected $description = 'Show recent checkpoint command runs.';

    protected $aliases = ['checkpoint:do:status'];

    public function __construct(
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = $this->stringOption('format') ?? 'table';
        $agentMode = (bool) $this->option('agent');
        $summaryMode = (bool) $this->option('summary');
        $briefMode = (bool) $this->option('brief');
        $policyProfile = $this->policyProfileOverride();
        $gateDecision = $this->gatePolicyEvaluator->evaluate(
            $this->reportBuilder->healthChecks(),
            $this->reportBuilder->summary(),
            $policyProfile,
        );

        if ($this->enhancedInteractiveMode() && ! $agentMode && $format === 'table') {
            intro($summaryMode ? 'Checkpoint Status Summary' : 'Checkpoint Status: Recent Runs');
            note('What: operational view of recent runs and summary signals.');
            note('When: immediately after queueing, or during incident triage.');
            note('Next: run checkpoint:check:doctor for deeper health diagnostics.');
        }

        if (! $agentMode && ! in_array($format, ['table', 'json'], true)) {
            $this->promptError('The --format option must be table or json.');

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

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('status', [
                'mode' => 'runs',
                'limit' => $limit,
                'runs' => $runs,
                'gates' => $this->machineGateDecision($gateDecision),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $gateDecision['exit_code'];
        }

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

        return $gateDecision['exit_code'];
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    private function policyProfileOverride(): ?string
    {
        $override = $this->stringOption('policy-profile');

        if (! is_string($override)) {
            return null;
        }

        $override = trim($override);

        return $override !== '' ? $override : null;
    }

    /**
     * @param  array<string, mixed>  $gateDecision
     * @return array{profile:string,profile_source:string,verdict:string,failed_gate:string,exit_code:int}
     */
    private function machineGateDecision(array $gateDecision): array
    {
        return [
            'profile' => (string) ($gateDecision['profile'] ?? 'unknown'),
            'profile_source' => (string) ($gateDecision['profile_source'] ?? 'default'),
            'verdict' => (string) ($gateDecision['verdict'] ?? 'fail'),
            'failed_gate' => (string) ($gateDecision['failed_gate'] ?? 'policy'),
            'exit_code' => (int) ($gateDecision['exit_code'] ?? 12),
        ];
    }

    private function renderSummary(string $format, bool $agentMode, array $gateDecision): void
    {
        $summary = $this->reportBuilder->summary();
        $failedRuns24h = (int) ($summary['failed_runs_24h'] ?? 0);

        if ($agentMode) {
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

            return;
        }

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('status', [
                'mode' => 'summary',
                'summary' => $summary,
                'gates' => $this->machineGateDecision($gateDecision),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

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
        $actionNow = (string) ($latestFailedRun['next_action'] ?? 'Run php artisan checkpoint:report --limit=10 --format=json for full failure context.');

        if ($agentMode) {
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

            return;
        }

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('status', [
                'mode' => 'brief',
                'failed_runs_24h' => $failedRuns24h,
                'pending_runs' => $pendingRuns,
                'running_runs' => $runningRuns,
                'last_failed_run' => $latestFailedRun,
                'action_now' => $actionNow,
                'gates' => $this->machineGateDecision($gateDecision),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->line('Checkpoint triage (brief)');
        $this->line(sprintf('Failed (24h): %d | Pending: %d | Running: %d', $failedRuns24h, $pendingRuns, $runningRuns));
        $this->line('Last failed: '.$label);
        $this->line('Cause: '.$reason);
        $this->line('Action now: '.$actionNow);
        $this->line('Deep dive: php artisan checkpoint:report --limit=10 --format=json');
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
            return (string) $label;
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
    private function summarySuggestions(array $summary): array
    {
        $suggestions = [];

        if ((int) ($summary['failed_runs_24h'] ?? 0) > 0) {
            $suggestions[] = 'Run checkpoint:status --format=json to inspect failed runs and statuses.';
        }

        $latestFailedRun = $summary['latest_failed_run'] ?? null;

        if (is_array($latestFailedRun) && is_string($latestFailedRun['next_action'] ?? null) && trim($latestFailedRun['next_action']) !== '') {
            $suggestions[] = trim($latestFailedRun['next_action']);
        }

        if ((int) ($summary['pending_runs'] ?? 0) > 0) {
            $suggestions[] = 'Start or scale queue workers for the db-ops queue to drain pending runs.';
        }

        if ((int) ($summary['running_runs'] ?? 0) > 0) {
            $suggestions[] = 'Run checkpoint:doctor --format=json to check queue heartbeat and orphan signals.';
        }

        $playbook = $summary['backup_drill_remediation_playbook'] ?? null;

        if (is_array($playbook)) {
            $commands = $playbook['recommended_commands'] ?? [];

            if (is_array($commands)) {
                foreach ($commands as $command) {
                    if (is_string($command) && trim($command) !== '') {
                        $suggestions[] = 'Run '.$command.' to remediate drill posture.';
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
