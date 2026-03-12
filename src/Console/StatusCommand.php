<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class StatusCommand extends Command
{
    protected $signature = 'db-ops:status {--limit=10} {--summary : Show an operator-facing summary instead of recent runs.}';

    protected $description = 'Show recent checkpoint command runs.';

    public function handle(): int
    {
        if ((bool) $this->option('summary')) {
            $this->renderSummary();

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $runs = CommandRun::query()
            ->latest('id')
            ->limit($limit)
            ->get();

        $this->table(
            ['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished'],
            $runs->map(fn (CommandRun $run): array => [
                (string) $run->getKey(),
                $run->operation,
                $this->coloredStatus((string) $run->status->value),
                $run->exit_code !== null ? (string) $run->exit_code : '-',
                $this->backupSummary($run),
                $run->verification_state ?? '-',
                $run->last_known_good_at?->format('Y-m-d H:i:s') ?? '-',
                $run->started_at?->format('Y-m-d H:i:s') ?? '-',
                $run->finished_at?->format('Y-m-d H:i:s') ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function renderSummary(): void
    {
        $lastKnownGood = CommandRun::query()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->first();

        $latestVerified = CommandRun::query()
            ->where('verification_state', 'verified')
            ->latest('verified_at')
            ->latest('id')
            ->first();

        $latestRestoreFailure = CommandRun::query()
            ->where('status', 'failed')
            ->whereIn('operation', ['logical_restore_file', 'logical_restore_latest', 'pitr_restore'])
            ->latest('finished_at')
            ->latest('id')
            ->first();

        $this->table(
            ['Signal', 'Value'],
            [
                ['Pending runs', (string) CommandRun::query()->pending()->count()],
                ['Running runs', (string) CommandRun::query()->running()->count()],
                ['Failed runs (24h)', (string) CommandRun::query()->failed()->where('created_at', '>=', now()->subDay())->count()],
                ['Last known good backup', $this->summaryRunValue($lastKnownGood, 'last_known_good_at')],
                ['Latest verified backup', $this->summaryRunValue($latestVerified, 'verified_at')],
                ['Latest restore failure', $this->restoreFailureSummary($latestRestoreFailure)],
            ],
        );
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

    private function backupSummary(CommandRun $run): string
    {
        $parts = array_filter([
            $run->backup_type,
            $run->backup_label,
        ]);

        if ($parts === []) {
            return '-';
        }

        return implode(':', $parts);
    }

    private function summaryRunValue(?CommandRun $run, string $timestampField): string
    {
        if (! $run instanceof CommandRun) {
            return '-';
        }

        /** @var Carbon|null $timestamp */
        $timestamp = $run->{$timestampField};

        $summary = $this->backupSummary($run);

        if ($summary === '-') {
            $summary = $run->operation;
        }

        if (! $timestamp instanceof Carbon) {
            return $summary;
        }

        return sprintf('%s at %s', $summary, $timestamp->format('Y-m-d H:i:s'));
    }

    private function restoreFailureSummary(?CommandRun $run): string
    {
        if (! $run instanceof CommandRun) {
            return '-';
        }

        $target = $run->restore_target ?? $run->argument_text;
        $summary = $run->operation;

        if (is_string($target) && $target !== '') {
            $summary .= sprintf(' (%s)', $target);
        }

        if (! $run->finished_at instanceof Carbon) {
            return $summary;
        }

        return sprintf('%s at %s', $summary, $run->finished_at->format('Y-m-d H:i:s'));
    }
}
