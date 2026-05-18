<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use Throwable;

final class DrillCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:drill';

    protected $description = 'Run a backup drill.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('backup_drill');

            $this->promptInfo(sprintf('Queued Backup Drill run #%d.', (int) $run->getKey()));

            if ($this->enhancedInteractiveMode()) {
                \Laravel\Prompts\note('Monitor progress: php artisan checkpoint:doctor --full');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }
}
