<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Jobs;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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

    public function __construct(public readonly CommandRun $run)
    {
        $this->onQueue(config('checkpoint.queue.name', 'db-ops'));
    }

    public function handle(): void
    {
        $this->resolveDriver()->execute($this->run->fresh() ?? $this->run);
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
                Log::channel(config('checkpoint.log_channel', 'stack'))
                    ->warning('Destructive checkpoint operation forced to a single attempt', $this->logContext($this->run, [
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

        Log::channel(config('checkpoint.log_channel', 'stack'))
            ->error('ProcessCommandRunJob failed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));
    }

    private function resolveDriver(): BackupDriver
    {
        $driver = (string) config('checkpoint.driver', 'shell');
        $class = config("checkpoint.drivers.{$driver}.class");

        if (! is_string($class) || $class === '') {
            throw new ConfigurationException(
                sprintf('Driver [%s] is not configured.', $driver),
            );
        }

        $resolved = resolve($class);

        if (! $resolved instanceof BackupDriver) {
            throw new ConfigurationException(
                sprintf('Configured driver [%s] must implement [%s].', $class, BackupDriver::class),
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, array $extra = []): array
    {
        return array_filter([
            'run_id' => $run->getKey(),
            'operation' => $run->operation,
            'driver' => $run->metadata['driver'] ?? config('checkpoint.driver', 'shell'),
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target ?? $run->argument_text,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
