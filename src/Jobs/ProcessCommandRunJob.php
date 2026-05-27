<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Jobs;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\LogManager;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** @internal */
final class ProcessCommandRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private string $logChannel;

    private string $configuredDriver;

    public function __construct(public readonly CommandRun $run)
    {
        $this->onQueue(config('checkpoint.queue.name', 'db-ops'));
        $this->logChannel = (string) config('checkpoint.log_channel', 'stack');
        $this->configuredDriver = (string) config('checkpoint.driver', 'shell');
    }

    public function handle(
        BackupDriver $driver,
        ConfigRepository $config,
        Dispatcher $events,
        LogManager $logManager,
    ): void {
        $logger = $logManager->channel($this->logChannel);
        $run = $this->run->fresh() ?? $this->run;

        if ($run->status !== CommandRunStatus::Pending) {
            $logger->warning('ProcessCommandRunJob skipped duplicate delivery', $this->logContext($run, $this->configuredDriver, [
                'status' => $run->status->value,
            ]));

            return;
        }

        if (! $run->claimPendingExecution()) {
            return;
        }

        $run = $run->fresh() ?? $run;

        $context = new DriverContext(
            operation: $run->operation,
            argument: $run->argument_text,
            driverName: $run->resolvedDriverName($this->configuredDriver),
            metadata: is_array($run->metadata) ? $run->metadata : [],
            runUuid: (string) $run->getKey(),
        );

        $events->dispatch(new BackupStarted($run));

        try {
            $result = $driver->execute($context, $run);

            $run = $run->fresh() ?? $run;

            $run->forceFill([
                'command_output' => $result->output,
                'exit_code' => $result->exitCode,
            ])->save();
            $run->recordMetadata($result->metadata);

            if ($result->isSuccessful()) {
                $run->markAsSucceeded($result->exitCode, $result->output);
                $events->dispatch(new BackupCompleted($run, $result->exitCode, $result->output));

                $logger->info('Completed checkpoint operation', $this->logContext($run, $this->configuredDriver, [
                    'exit_code' => $result->exitCode,
                ]));

                return;
            }

            $run->markAsFailed($result->exitCode, $result->output);
            $events->dispatch(new BackupFailed($run, $result->exitCode, $result->output));

            $logger->error('Checkpoint operation failed', $this->logContext($run, $this->configuredDriver, [
                'exit_code' => $result->exitCode,
            ]));
        } catch (Throwable $exception) {
            $run = $run->fresh() ?? $run;

            if (! $run->status->isTerminal()) {
                $run->markAsFailed(output: $exception->getMessage());
            }

            $events->dispatch(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $logger->error('Checkpoint operation crashed', $this->logContext($run, $this->configuredDriver, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        $catalog = resolve(CommandRunCatalog::class);

        if ($catalog->isExclusive($this->run->operation)) {
            return sprintf('db-ops-exclusive:%s', $this->run->operation);
        }

        return sprintf('db-ops-run:%s', (string) $this->run->getKey());
    }

    public function uniqueFor(): int
    {
        return max(1, (int) config('checkpoint.queue.unique_for', 3660));
    }

    public function uniqueVia(): CacheRepository
    {
        $store = config('checkpoint.queue.lock_store');

        if (! is_string($store) || $store === '') {
            return Cache::store();
        }

        return Cache::store($store);
    }

    public function tries(): int
    {
        $configuredAttempts = (int) config('checkpoint.queue.max_attempts', 1);
        $catalog = resolve(CommandRunCatalog::class);

        if ($catalog->isDestructive($this->run->operation)) {
            if ($configuredAttempts > 1) {
                Log::channel($this->logChannel)
                    ->warning('Destructive checkpoint operation forced to a single attempt', $this->logContext($this->run, $this->configuredDriver, [
                        'configured_attempts' => $configuredAttempts,
                    ]));
            }

            return 1;
        }

        return max(1, $configuredAttempts);
    }

    public function failed(Throwable $exception): void
    {
        $run = $this->run->fresh() ?? $this->run;

        if (! $run->status->isTerminal()) {
            $run->markAsFailed(output: $exception->getMessage());
        }

        event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

        Log::channel($this->logChannel)
            ->error('ProcessCommandRunJob failed', $this->logContext($run, $this->configuredDriver, [
                'error' => $exception->getMessage(),
            ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, string $configuredDriver, array $extra = []): array
    {
        $restoreOps = ['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore'];

        return collect([
            'run_id' => $run->getKey(),
            'operation' => $run->operation,
            'driver' => $run->resolvedDriverName($configuredDriver),
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target ?? $run->argument_text,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            'restore_decision_event_count' => in_array($run->operation, $restoreOps, true) && $run->exists
                ? RestoreDecisionEvent::query()->where('command_run_id', (int) $run->getKey())->count()
                : null,
            ...$extra,
        ])->filter(static fn (mixed $value): bool => $value !== null)->all();
    }
}
