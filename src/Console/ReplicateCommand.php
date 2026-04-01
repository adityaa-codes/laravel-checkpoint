<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildReplicationCommandPayloadAction;
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use Illuminate\Console\Command;
use Throwable;

final class ReplicateCommand extends Command
{
    protected $signature = 'db-ops:replicate
        {source? : Source endpoint (profile:<id>, DSN, or key=value pairs)}
        {destination? : Destination endpoint (profile:<id>, DSN, or key=value pairs)}
        {--source= : Source endpoint override}
        {--destination= : Destination endpoint override}
        {--apply : Queue apply mode. Without this flag, replication runs in dry-run mode.}
        {--force-overwrite : Request overwrite behavior for apply mode.}
        {--critical-table=* : Critical table names to guard overwrite. Repeat option for multiple tables.}';

    protected $description = 'Queue a replication sync run with conservative defaults.';

    public function handle(
        EnqueueCommandRunAction $enqueueCommandRun,
        BuildReplicationCommandPayloadAction $buildPayload,
    ): int {
        try {
            $payload = $buildPayload->execute(
                source: $this->resolveEndpoint('source'),
                destination: $this->resolveEndpoint('destination'),
                apply: (bool) $this->option('apply'),
                forceOverwrite: (bool) $this->option('force-overwrite'),
                criticalTables: $this->criticalTableOptions(),
            );

            $run = $enqueueCommandRun->execute(
                'replication_sync',
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            $this->info($this->queuedMessage((int) $run->getKey()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveEndpoint(string $role): string
    {
        $option = $this->option($role);

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $argument = $this->argument($role);

        if (is_string($argument) && trim($argument) !== '') {
            return trim($argument);
        }

        return trim((string) $this->secret(sprintf('Enter %s replication endpoint', $role)));
    }

    /**
     * @return array<mixed>
     */
    private function criticalTableOptions(): array
    {
        $criticalTables = $this->option('critical-table');

        return is_array($criticalTables) ? $criticalTables : [];
    }

    private function queuedMessage(int $runId): string
    {
        $operation = __('messages.operations.replication_sync');

        if ($operation === 'messages.operations.replication_sync') {
            $operation = 'Replication Sync';
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
