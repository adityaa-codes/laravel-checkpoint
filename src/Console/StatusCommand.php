<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'db-ops:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Show recent checkpoint command runs.';

    public function __construct(
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
        private readonly \Illuminate\Contracts\Config\Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');
        $agentMode = (bool) $this->option('agent');

        if (! $agentMode && ! in_array($format, ['table', 'json'], true)) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        if ((bool) $this->option('summary')) {
            $this->renderSummary($format, $agentMode);

            return self::SUCCESS;
        }

        $limit = $this->recentRunLimit();
        $runs = $this->reportBuilder->recentRuns($limit);

        if ($agentMode) {
            $failedRuns = count(array_filter($runs, static fn (array $run): bool => (string) ($run['status'] ?? '') === 'failed'));
            $this->line(json_encode($this->jsonContract->envelope('status', [
                'result' => $failedRuns > 0 ? 'failed' : 'passed',
                'code' => $failedRuns > 0 ? 'status.runs.failed' : 'status.runs.ok',
                'summary' => sprintf('%d failed run(s) in the latest %d run(s).', $failedRuns, count($runs)),
                'data' => [
                    'mode' => 'runs',
                    'limit' => $limit,
                    'run_count' => count($runs),
                    'failed_run_count' => $failedRuns,
                    'runs' => $runs,
                ],
                'suggestions' => $this->replicationFailureSuggestions($runs),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('status', [
                'mode' => 'runs',
                'limit' => $limit,
                'runs' => $runs,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table([
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

        return self::SUCCESS;
    }

    private function renderSummary(string $format, bool $agentMode): void
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
                ],
                'suggestions' => $this->summarySuggestions($summary),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('status', [
                'mode' => 'summary',
                'summary' => $summary,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $windowDays = (int) ($summary['backup_drill_pass_rate']['window_days'] ?? 30);

        $this->table(['Signal', 'Value'], [
            ['Pending runs', (string) $summary['pending_runs']],
            ['Running runs', (string) $summary['running_runs']],
            ['Failed runs (24h)', (string) $summary['failed_runs_24h']],
            ['Last known good backup', $summary['last_known_good_backup']['label'] ?? '-'],
            ['Latest verified backup', $summary['latest_verified_backup']['label'] ?? '-'],
            ['Latest backup drill', $summary['latest_backup_drill']['label'] ?? '-'],
            ['Latest failed drill', $summary['latest_failed_backup_drill']['label'] ?? '-'],
            [sprintf('Backup drill pass rate (%dd)', $windowDays), $summary['backup_drill_pass_rate']['label'] ?? '-'],
            ['Latest restore run', $summary['latest_restore_run']['label'] ?? '-'],
            ['Latest restore failure', $summary['latest_restore_failure']['label'] ?? '-'],
        ]);
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
                    if (! is_string($suggestion) || trim($suggestion) === '') {
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
            $suggestions[] = 'Run db-ops:status --format=json to inspect failed runs and statuses.';
        }

        if ((int) ($summary['pending_runs'] ?? 0) > 0) {
            $suggestions[] = 'Start or scale queue workers for the db-ops queue to drain pending runs.';
        }

        if ((int) ($summary['running_runs'] ?? 0) > 0) {
            $suggestions[] = 'Run db-ops:doctor --format=json to check queue heartbeat and orphan signals.';
        }

        return $suggestions;
    }
}
