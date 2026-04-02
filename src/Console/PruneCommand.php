<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

final class PruneCommand extends Command
{
    protected $signature = 'db-ops:prune';

    protected $description = 'Prune old checkpoint runs and backup drill records.';

    public function handle(): int
    {
        if ($this->enhancedInteractiveMode()) {
            intro('Prune Checkpoint Records');
        }

        $commandRunCount = (new CommandRun)->pruneAll();
        $backupDrillCount = (new BackupDrillRun)->pruneAll();

        $message = $this->prunedMessage($commandRunCount, $backupDrillCount);

        if ($this->enhancedInteractiveMode()) {
            outro($message);
        } else {
            $this->info($message);
        }

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

    private function enhancedInteractiveMode(): bool
    {
        return $this->input !== null && $this->input->isInteractive() && ! app()->runningUnitTests();
    }
}
