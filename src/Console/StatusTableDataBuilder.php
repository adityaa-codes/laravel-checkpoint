<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Str;

final class StatusTableDataBuilder
{
    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    public function buildRunsTable(array $runs): array
    {
        return [
            'headers' => ['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished'],
            'rows' => collect($runs)->map(fn (array $run): array => [
                (string) $run['id'],
                (string) $run['operation'],
                $this->coloredStatus((string) $run['status']),
                $run['exit_code'] !== null ? (string) $run['exit_code'] : '-',
                (string) ($run['backup'] ?? '-'),
                $run['verification_state'] ?? '-',
                $run['last_known_good_at'] ?? '-',
                $run['started_at'] ?? '-',
                $run['finished_at'] ?? '-',
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    public function buildSummaryTable(array $summary): array
    {
        $windowDays = (int) ($summary['backup_drill_pass_rate']['window_days'] ?? 30);

        return [
            'headers' => ['Signal', 'Value'],
            'rows' => [
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
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function buildBriefTableLines(int $failedRuns24h, int $pendingRuns, int $runningRuns, string $label, string $reason, string $actionNow): array
    {
        return [
            'Checkpoint triage (brief)',
            sprintf('Failed (24h): %d | Pending: %d | Running: %d', $failedRuns24h, $pendingRuns, $runningRuns),
            'Last failed: '.$label,
            'Cause: '.$reason,
            'Action now: '.$actionNow,
            'Deep dive: php artisan checkpoint:status --full --limit=10 --format=json',
        ];
    }

    /**
     * @return list<string>
     */
    public function buildPassingRunLines(): array
    {
        $runs = CommandRun::query()
            ->where('status', 'succeeded')
            ->latest('id')
            ->limit(10)
            ->get();

        if ($runs->isEmpty()) {
            return [];
        }

        $lines = [];

        foreach ($runs as $run) {
            $lines[] = sprintf('  #%d %s — %s', $run->getKey(), $run->operation, $run->finished_at?->diffForHumans() ?? '-');
        }

        return $lines;
    }

    /**
     * @return list<array{runId:int,operation:string,output:string}>
     */
    public function buildFailedOutputSnippets(): array
    {
        $runs = CommandRun::query()
            ->where('status', 'failed')
            ->whereNotNull('command_output')
            ->latest('id')
            ->limit(3)
            ->get();

        $snippets = [];

        foreach ($runs as $run) {
            $output = (string) ($run->command_output ?? '');
            $snippet = Str::substr($output, 0, 500);

            if ($snippet === '') {
                continue;
            }

            $snippets[] = [
                'runId' => (int) $run->getKey(),
                'operation' => (string) $run->operation,
                'output' => $snippet,
                'truncated' => Str::length($output) > 500,
            ];
        }

        return $snippets;
    }

    public function coloredStatus(string $status): string
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

    public function statusLabel(string $status): string
    {
        $label = __('messages.status.'.$status);

        if ($label !== 'messages.status.'.$status) {
            return $label;
        }

        return str($status)->title()->toString();
    }
}
