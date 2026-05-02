<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Throwable;

final class EnqueueBackupDrillCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:enqueue-drill';

    protected $description = 'Queue a backup drill command run.';

    protected $aliases = ['checkpoint:do:drill'];

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('backup_drill');

            $message = $this->queuedMessage((int) $run->getKey());

            if ($this->enhancedInteractiveMode()) {
                \Laravel\Prompts\info($message);
                \Laravel\Prompts\note('Monitor progress: php artisan checkpoint:check:report');
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
        $operation = __('messages.operations.backup_drill');

        if ($operation === 'messages.operations.backup_drill') {
            $operation = 'Backup Drill';
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
