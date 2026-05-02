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

    protected $aliases = ['checkpoint:do:backup'];

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('logical_backup');

            $message = $this->queuedMessage((int) $run->getKey());

            if ($this->enhancedInteractiveMode()) {
                \Laravel\Prompts\info($message);
                \Laravel\Prompts\note('Monitor progress: php artisan checkpoint:do:status');
            } else {
                $this->promptInfo($message);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function queuedMessage(int $runId): string
    {
        $operation = __('messages.operations.logical_backup');

        if ($operation === 'messages.operations.logical_backup') {
            $operation = 'Logical Backup';
        }

        $message = __('messages.cli.backup_queued', [
            'operation' => $operation,
            'id' => $runId,
        ]);

        if ($message === 'messages.cli.backup_queued') {
            return sprintf('Queued %s run #%d.', $operation, $runId);
        }

        return (string) $message;
    }
}
