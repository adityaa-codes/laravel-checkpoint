<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

final class PruneCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:prune
        {--dry-run : Preview what would be pruned without deleting records.}
        {--force : Skip confirmation prompt for non-interactive environments.}';

    protected $description = 'Prune old checkpoint runs and backup drill records.';

    public function __construct(
        private readonly CommandRun $commandRun,
        private readonly BackupDrillRun $backupDrillRun,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Prompt::interactive($this->enhancedInteractiveMode());

        $dryRun = (bool) $this->option('dry-run');

        if ($this->enhancedInteractiveMode()) {
            intro($dryRun ? 'Prune Checkpoint Records (Dry Run)' : 'Prune Checkpoint Records');
        }

        if ($dryRun) {
            $eligibleCommandRuns = $this->commandRun->prunable()->count();
            $eligibleDrillRuns = $this->backupDrillRun->prunable()->count();

            $this->promptInfo(sprintf(
                'Dry run: %d command run(s) and %d backup drill run(s) eligible for pruning.',
                $eligibleCommandRuns,
                $eligibleDrillRuns,
            ));

            return self::SUCCESS;
        }

        if ($this->enhancedInteractiveMode() && ! (bool) $this->option('force')) {
            $confirmed = confirm('Proceed with pruning? This action cannot be undone.');

            if (! $confirmed) {
                warning('Pruning cancelled.');

                return self::SUCCESS;
            }
        }

        $commandRunCount = $this->commandRun->pruneAll();
        $backupDrillCount = $this->backupDrillRun->pruneAll();

        $message = $this->prunedMessage($commandRunCount, $backupDrillCount);

        if ($this->enhancedInteractiveMode()) {
            outro($message);
        } else {
            $this->promptInfo($message);
        }

        return self::SUCCESS;
    }

    private function prunedMessage(int $commandRunCount, int $backupDrillCount): string
    {
        return $this->translatedOr(
            'messages.cli.pruned_with_drills',
            sprintf(
                'Pruned %d command run records and %d backup drill records.',
                $commandRunCount,
                $backupDrillCount,
            ),
            ['command_run_count' => $commandRunCount, 'backup_drill_count' => $backupDrillCount],
        );
    }
}
