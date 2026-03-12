<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'db-ops:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.} {--format=table : Output format: table or json.}';

    protected $description = 'Show recent checkpoint command runs.';

    public function __construct(
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        if ((bool) $this->option('summary')) {
            $this->renderSummary($format);

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $runs = $this->reportBuilder->recentRuns($limit);

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

    private function renderSummary(string $format): void
    {
        $summary = $this->reportBuilder->summary();

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
}
