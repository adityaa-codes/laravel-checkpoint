<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Rendering;

use AdityaaCodes\LaravelCheckpoint\Rendering\Concerns\FormatsHealthData;
use Illuminate\Console\Command;

/** @internal */
final readonly class StatusTableRenderer
{
    use FormatsHealthData;

    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $gateDecision
     */
    public function renderRunsTable(Command $command, array $runs, array $gateDecision): int
    {
        $headers = ['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished'];

        $rows = collect($runs)->map(fn (array $run): array => [
            $run['id'] ?? '-',
            $run['operation'] ?? '-',
            $run['status'] ?? '-',
            $run['exit_code'] ?? '-',
            $this->formatBackupLabel($run),
            $this->formatVerificationLabel($run),
            $run['last_known_good_at'] ?? '-',
            $run['started_at'] ?? '-',
            $run['finished_at'] ?? '-',
        ])->all();

        $command->table($headers, $rows);

        return $gateDecision['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $gateDecision
     */
    public function renderSummaryTable(Command $command, array $summary, array $gateDecision): int
    {
        $rows = [
            ['Driver', $summary['driver'] ?? '-'],
            ['Last 24h', sprintf('%d succeeded, %d failed', $summary['succeeded_runs_24h'] ?? 0, $summary['failed_runs_24h'] ?? 0)],
            ['Pending', $summary['pending_runs'] ?? 0],
            ['Running', $summary['running_runs'] ?? 0],
            ['Last known good', $summary['last_known_good_at'] ?? 'never'],
            ['Drill pass rate', $this->formatDrillPassRate($summary)],
        ];

        $command->table(['Signal', 'Value'], $rows);

        return $gateDecision['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $gateDecision
     */
    public function renderBriefTable(Command $command, array $summary, array $gateDecision): int
    {
        $failedCount = (int) ($summary['failed_runs_24h'] ?? 0);
        $pendingCount = (int) ($summary['pending_runs'] ?? 0);
        $runningCount = (int) ($summary['running_runs'] ?? 0);

        $command->table(['Signal', 'Value'], [
            ['Failed (24h)', $failedCount],
            ['Pending', $pendingCount],
            ['Running', $runningCount],
            ['Verdict', $gateDecision['verdict'] ?? '-'],
            ['Top issue', $gateDecision['top_issue'] ?? '-'],
            ['Next action', $gateDecision['next_action'] ?? '-'],
        ]);

        return $gateDecision['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function formatBackupLabel(array $run): string
    {
        $label = $run['backup_label'] ?? null;

        return is_string($label) && $label !== '' ? $label : '-';
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function formatVerificationLabel(array $run): string
    {
        $state = $run['verification_state'] ?? null;

        return is_string($state) && $state !== '' ? $state : '-';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function formatDrillPassRate(array $summary): string
    {
        $rate = $summary['backup_drill_pass_rate']['pass_rate_percent'] ?? null;

        if (! is_numeric($rate)) {
            return 'no drills';
        }

        return sprintf('%.1f%%', (float) $rate);
    }
}
