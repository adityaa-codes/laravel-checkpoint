<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Log\LogManager;
use Throwable;

final class RecoverOrphansCommand extends Command
{
    protected $signature = 'db-ops:recover-orphans';

    protected $description = 'Re-dispatch stale pending checkpoint command runs.';

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $dispatcher,
        private readonly EventDispatcher $events,
        private readonly LogManager $logs,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $thresholdMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_threshold', 10));
        $threshold = now()->subMinutes($thresholdMinutes);
        $claimedAt = now();
        $queue = (string) $this->config->get('checkpoint.queue.name', 'db-ops');
        $staleRuns = CommandRun::query()
            ->pending()
            ->where('updated_at', '<', $threshold)
            ->get();

        $claimedRuns = $staleRuns->filter(
            fn (CommandRun $run): bool => $run->claimForOrphanRecovery($threshold, $claimedAt),
        )->values();

        if ($claimedRuns->isNotEmpty()) {
            $this->events->dispatch(new QueueLagDetected(
                $queue,
                $claimedRuns->count(),
                $thresholdMinutes,
                $this->oldestStaleAgeMinutes($claimedRuns),
                $claimedRuns->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            ));
        }

        $claimedRuns->each(function (CommandRun $run) use ($queue, $threshold, $thresholdMinutes): void {
                $job = new ProcessCommandRunJob($run)
                    ->onQueue($queue);

                $staleAgeMinutes = $this->staleAgeMinutes($run);
                $claimedAt = $run->updated_at;

                try {
                    $this->dispatcher->dispatch($job);
                } catch (Throwable $exception) {
                    if ($claimedAt !== null) {
                        $run->releaseOrphanRecoveryClaim($claimedAt, $threshold->copy()->subSecond());
                    }

                    throw $exception;
                }

                $this->events->dispatch(new OrphanRunRedispatched($run, $queue, $thresholdMinutes, $staleAgeMinutes));

                $this->logs->channel((string) $this->config->get('checkpoint.log_channel', 'stack'))
                    ->warning('Recover orphans re-dispatched command run', $this->logContext($run, [
                        'queue' => $queue,
                        'threshold_minutes' => $thresholdMinutes,
                        'stale_age_minutes' => $staleAgeMinutes,
                    ]));

                $this->line($this->redispatchedMessage((int) $run->getKey()));
            });

        return self::SUCCESS;
    }

    private function redispatchedMessage(int $runId): string
    {
        $message = __('messages.cli.orphan_redispatched', [
            'id' => $runId,
        ]);

        if ($message === 'messages.cli.orphan_redispatched') {
            return sprintf('Re-dispatched orphaned run #%d.', $runId);
        }

        return (string) $message;
    }

    /**
     * @param  Collection<int, CommandRun>  $runs
     */
    private function oldestStaleAgeMinutes(Collection $runs): int
    {
        return (int) $runs
            ->map(fn (CommandRun $run): int => $this->staleAgeMinutes($run))
            ->max();
    }

    private function staleAgeMinutes(CommandRun $run): int
    {
        if ($run->created_at === null) {
            return 0;
        }

        return max(0, (int) $run->created_at->diffInMinutes(now()));
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
