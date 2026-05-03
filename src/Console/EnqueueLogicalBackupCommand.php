<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Throwable;

final class EnqueueLogicalBackupCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:enqueue-backup';

    protected $description = 'Queue a logical backup command run.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('logical_backup');

            $message = $this->queuedMessage((int) $run->getKey());

            if ($this->enhancedInteractiveMode()) {
                \Laravel\Prompts\info($message);
                \Laravel\Prompts\note('Monitor progress: php artisan checkpoint:status');
            } else {
                $this->promptInfo($message);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function queuedMessage(int $runId): string
    {
        $operation = $this->translatedOr('messages.operations.logical_backup', 'Logical Backup');

        return $this->translatedOr(
            'messages.cli.backup_queued',
            sprintf('Queued %s run #%d.', $operation, $runId),
            ['operation' => $operation, 'id' => $runId],
        );
    }
}
