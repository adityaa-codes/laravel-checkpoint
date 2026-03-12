<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'db-ops:prune';

    protected $description = 'Prune old checkpoint runs and backup drill records.';

    public function handle(): int
    {
        $commandRunCount = (new CommandRun)->pruneAll();
        $backupDrillCount = (new BackupDrillRun)->pruneAll();

        $this->info($this->prunedMessage($commandRunCount, $backupDrillCount));

        return self::SUCCESS;
    }

    private function prunedMessage(int $commandRunCount, int $backupDrillCount): string
    {
        $message = __('messages.cli.pruned_with_drills', [
            'command_run_count' => $commandRunCount,
            'backup_drill_count' => $backupDrillCount,
        ]);

        if ($message === 'messages.cli.pruned_with_drills') {
            return sprintf(
                'Pruned %d command run records and %d backup drill records.',
                $commandRunCount,
                $backupDrillCount,
            );
        }

        return (string) $message;
    }
}
