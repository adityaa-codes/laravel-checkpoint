<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CheckpointOperation;
use Throwable;

final class DrillCommand extends CheckpointCommand
{
    use RendersJsonOutput;

    protected $signature = 'checkpoint:drill
                            {--format=table : Output format: table or json.}';

    protected $description = 'Run a backup drill.';

    public function handle(EnqueueCommandRunAction $enqueueCommandRun): int
    {
        try {
            $run = $enqueueCommandRun->execute(CheckpointOperation::Drill);

            if ($this->stringOption('format') === 'json') {
                return $this->renderJson('drill', [
                    'run_id' => (int) $run->getKey(),
                    'operation' => $run->operation,
                    'status' => $run->status->value,
                ]);
            }

            $this->promptInfo(sprintf('Queued Backup Drill run #%d.', (int) $run->getKey()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }
}
