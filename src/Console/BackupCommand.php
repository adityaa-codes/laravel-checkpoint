<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CheckpointOperation;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class BackupCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:backup
                            {--sync : Run the backup inline instead of queueing.}';

    protected $description = 'Run a logical backup.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute(CheckpointOperation::Backup);

            if ($this->option('sync')) {
                Bus::dispatchSync(new ProcessCommandRunJob($run));
                $run->refresh();

                $message = $run->status->value === 'succeeded'
                    ? sprintf('Sync backup run #%d completed.', (int) $run->getKey())
                    : sprintf(
                        'Sync backup run #%d failed (status: %s, exit code: %d).',
                        (int) $run->getKey(),
                        $run->status->value,
                        $run->exit_code ?? -1,
                    );

                $this->promptInfo($message);

                return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
            }

            $this->promptInfo($this->queuedMessage((int) $run->getKey()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function queuedMessage(int $runId): string
    {
        return sprintf('Queued Logical Backup run #%d.', $runId);
    }
}
