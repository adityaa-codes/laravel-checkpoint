<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use Illuminate\Console\Command;
use Throwable;

class EnqueueLogicalBackupCommand extends Command
{
    protected $signature = 'db-ops:enqueue-backup';

    protected $description = 'Queue a logical backup command run.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('logical_backup');

            $this->info($this->queuedMessage((int) $run->getKey()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

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
