<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class EnqueueCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:enqueue {operation?} {--argument=}';

    protected $description = 'Queue a checkpoint command run for any supported operation.';

    protected $aliases = ['checkpoint:do:enqueue'];

    public function handle(
        EnqueueCommandRunAction $enqueueCommandRun,
        CommandRunCatalog $catalog,
    ): int {
        try {
            $operation = $this->resolveOperation($catalog);
            $argument = $this->resolveArgument($catalog, $operation);

            $run = $enqueueCommandRun->execute($operation, $argument);

            $message = $this->queuedMessage($operation, (int) $run->getKey());

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

    private function resolveOperation(CommandRunCatalog $catalog): string
    {
        $operation = $this->argument('operation');

        if (is_string($operation) && $operation !== '') {
            return $operation;
        }

        $choices = array_keys($catalog->all());

        if ($this->input !== null && ! $this->input->isInteractive()) {
            throw new InvalidArgumentException(
                'Operation is required in non-interactive mode. Pass it as checkpoint:enqueue <operation>.',
            );
        }

        if (app()->runningUnitTests()) {
            /** @var string $selected */
            $selected = $this->choice(
                'Which operation would you like to queue?',
                $choices,
                default: 0,
            );

            return $selected;
        }

        /** @var string $selected */
        $selected = (string) select(
            label: 'Which operation would you like to queue?',
            options: $choices,
            default: $choices[0] ?? '',
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

        if ($this->input !== null && ! $this->input->isInteractive()) {
            throw new InvalidArgumentException(sprintf(
                'Operation [%s] requires --argument in non-interactive mode.',
                $operation,
            ));
        }

        /** @var string $value */
        $value = app()->runningUnitTests()
            ? (string) $this->ask('Enter the argument for the selected operation')
            : (string) text(label: 'Enter the argument for the selected operation', required: true);

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
