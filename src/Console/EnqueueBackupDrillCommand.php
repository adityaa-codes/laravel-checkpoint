<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

final class EnqueueBackupDrillCommand extends Command
{
    protected $signature = 'db-ops:enqueue-drill';

    protected $description = 'Queue a backup drill command run.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            if ($this->enhancedInteractiveMode()) {
                intro('Queue Backup Drill');
            }

            $run = $enqueueCommandRun->execute('backup_drill');

            $message = $this->queuedMessage((int) $run->getKey());

            if ($this->enhancedInteractiveMode()) {
                outro($message);
            } else {
                $this->info($message);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

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

    private function enhancedInteractiveMode(): bool
    {
        return $this->input !== null && $this->input->isInteractive() && ! app()->runningUnitTests();
    }
}
