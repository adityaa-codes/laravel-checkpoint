<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use Illuminate\Console\Command;
use Throwable;

final class EnqueueCommand extends Command
{
    protected $signature = 'db-ops:enqueue {operation?} {--argument=}';

    protected $description = 'Queue a checkpoint command run for any supported operation.';

    public function handle(
        EnqueueCommandRunAction $enqueueCommandRun,
        CommandRunCatalog $catalog,
    ): int {
        try {
            $operation = $this->resolveOperation($catalog);
            $argument = $this->resolveArgument($catalog, $operation);

            $run = $enqueueCommandRun->execute($operation, $argument);

            $this->info($this->queuedMessage($operation, (int) $run->getKey()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveOperation(CommandRunCatalog $catalog): string
    {
        $operation = $this->argument('operation');

        if (is_string($operation) && $operation !== '') {
            return $operation;
        }

        $choices = array_keys($catalog->all());

        /** @var string $selected */
        $selected = $this->choice(
            'Which operation would you like to queue?',
            $choices,
            default: 0,
        );

        return $selected;
    }

    private function resolveArgument(CommandRunCatalog $catalog, string $operation): ?string
    {
        $argument = $this->option('argument');
        $definition = $catalog->operation($operation);
        $requiresArgument = (bool) ($definition['argument_required'] ?? false);

        if (is_string($argument) && $argument !== '') {
            return $argument;
        }

        if (! $requiresArgument) {
            return null;
        }

        /** @var string $value */
        $value = $this->ask('Enter the argument for the selected operation');

        return $value;
    }

    private function queuedMessage(string $operation, int $runId): string
    {
        $operationLabel = $this->operationLabel($operation);
        $message = __('messages.cli.backup_queued', [
            'operation' => $operationLabel,
            'id' => $runId,
        ]);

        if ($message === 'messages.cli.backup_queued') {
            return sprintf('Queued %s run #%d.', $operationLabel, $runId);
        }

        return (string) $message;
    }

    private function operationLabel(string $operation): string
    {
        $label = __('messages.operations.'.$operation);

        if ($label !== 'messages.operations.'.$operation) {
            return (string) $label;
        }

        return match ($operation) {
            'logical_backup' => 'Logical Backup',
            'logical_restore_latest' => 'Logical Restore (Latest)',
            'logical_restore_file' => 'Logical Restore (Specific File)',
            'pitr_restore' => 'PITR Restore',
            'backup_drill' => 'Backup Drill',
            'pgbackrest_check' => 'pgBackRest Check',
            'pgbackrest_info' => 'pgBackRest Info',
            'replication_sync' => 'Replication Sync',
            default => str($operation)
                ->replace('_', ' ')
                ->title()
                ->toString(),
        };
    }
}
