<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class EnqueueBackupDrillCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'db-ops:enqueue-drill';

    protected $description = 'Queue a backup drill command run.';

    protected $aliases = ['db-ops:do:drill'];

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            if ($this->enhancedInteractiveMode()) {
                intro('Queue Backup Drill');
                note('What: enqueue one restore drill evidence run.');
                note('When: periodic restore-readiness validation.');
                note('Next: run db-ops:check:report to review drill posture and trend.');
            }

            $run = $enqueueCommandRun->execute('backup_drill');

            $message = $this->queuedMessage((int) $run->getKey());

            if ($this->enhancedInteractiveMode()) {
                outro($message);
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
