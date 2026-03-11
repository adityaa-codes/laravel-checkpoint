<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'db-ops:status {--limit=10}';

    protected $description = 'Show recent checkpoint command runs.';

    public function handle(): int
    {
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
}
