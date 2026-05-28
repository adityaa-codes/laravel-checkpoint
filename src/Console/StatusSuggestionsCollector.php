<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Support\Str;

final class StatusSuggestionsCollector
{
    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<string>
     */
    public function replicationFailureSuggestions(array $runs): array
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
                    if (Str::trim($suggestion) === '') {
                        continue;
                    }
                    $suggestions[] = Str::trim($suggestion);
                }
            }
        }

        $suggestions = collect($suggestions)->unique()->values()->all();

        return collect($suggestions)->slice(0, 5)->all();
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    public function summarySuggestions(array $summary, bool $compact = false): array
    {
        $suggestions = [];

        if ((int) ($summary['failed_runs_24h'] ?? 0) > 0) {
            $suggestions[] = $compact ? 'Inspect recent runs' : 'Run checkpoint:status --format=json to inspect failed runs and statuses.';
        }

        $latestFailedRun = $summary['latest_failed_run'] ?? null;

        if (is_array($latestFailedRun) && is_string($latestFailedRun['next_action'] ?? null) && Str::trim($latestFailedRun['next_action']) !== '') {
            $suggestions[] = Str::trim($latestFailedRun['next_action']);
        }

        if ((int) ($summary['pending_runs'] ?? 0) > 0) {
            $suggestions[] = $compact ? 'Scale queue workers' : 'Start or scale queue workers for the checkpoint queue to drain pending runs.';
        }

        if ((int) ($summary['running_runs'] ?? 0) > 0) {
            $suggestions[] = $compact ? 'checkpoint:status --health --format=json' : 'Run checkpoint:status --health --format=json to check queue heartbeat and orphan signals.';
        }

        $playbook = $summary['backup_drill_remediation_playbook'] ?? null;

        if (is_array($playbook)) {
            $commands = $playbook['recommended_commands'] ?? [];

            if (is_array($commands)) {
                foreach ($commands as $command) {
                    if (is_string($command) && Str::trim($command) !== '') {
                        $suggestions[] = $compact ? $command : 'Run '.$command.' to remediate drill posture.';
                    }
                }
            }
        }

        $suggestions = collect($suggestions)->unique()->values()->all();

        return collect($suggestions)->slice(0, 5)->all();
    }
}
