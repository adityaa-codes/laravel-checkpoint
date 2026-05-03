<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class EnqueueLogicalBackupCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:enqueue-backup
                            {--sync : Run the backup inline instead of queueing.}';

    protected $description = 'Queue a logical backup command run.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute('logical_backup');

            if ($this->option('sync')) {
                $queueName = (string) config('checkpoint.queue.name', 'db-ops');

                $exitCode = Artisan::call('queue:work', [
                    '--queue' => $queueName,
                    '--once' => true,
                    '--tries' => 1,
                ]);

                $run = $run->fresh() ?? $run;

                $message = $exitCode === 0
                    ? sprintf('Sync backup run #%d completed.', (int) $run->getKey())
                    : sprintf(
                        'Sync backup run #%d failed (status: %s, exit code: %d).%s',
                        (int) $run->getKey(),
                        $run->status->value,
                        $exitCode,
                        is_string($run->command_output) && $run->command_output !== ''
                            ? sprintf(' Output: %s', mb_substr($run->command_output, 0, 200))
                            : '',
                    );

                if ($this->enhancedInteractiveMode()) {
                    $exitCode === 0 ? \Laravel\Prompts\info($message) : \Laravel\Prompts\error($message);
                    \Laravel\Prompts\note(sprintf('Run status: %s', $run->status->value));
                } else {
                    $this->promptInfo($message);
                }

                return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
            }

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
