<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Throwable;

final class DrillCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:drill';

    protected $description = 'Run a backup drill.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('backup_drill');

            $this->promptInfo(sprintf('Queued Backup Drill run #%d.', (int) $run->getKey()));

            if ($this->enhancedInteractiveMode()) {
                \Laravel\Prompts\note('Monitor progress: php artisan checkpoint:report');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }
}
