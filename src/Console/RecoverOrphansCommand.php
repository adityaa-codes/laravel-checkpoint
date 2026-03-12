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
        $claimTimeoutMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_claim_timeout', 1));
        $claimExpiresBefore = now()->subMinutes($claimTimeoutMinutes);
        $claimedAt = now();
        $batchSize = max(1, (int) $this->config->get('checkpoint.queue.orphan_batch_size', 100));
        $eventMaxIds = max(1, (int) $this->config->get('checkpoint.queue.orphan_event_max_ids', 50));
        $queue = (string) $this->config->get('checkpoint.queue.name', 'db-ops');
        $claimedRunIds = [];
        $claimedRunCount = 0;
        $oldestStaleAgeMinutes = 0;
        $dispatchFailure = null;

        try {
            CommandRun::query()
                ->pending()
                ->where('updated_at', '<', $threshold)
                ->where(function ($query) use ($claimExpiresBefore): void {
                    $query
                        ->whereNull('orphan_recovery_claimed_at')
                        ->orWhere('orphan_recovery_claimed_at', '<', $claimExpiresBefore);
                })
                ->orderBy('id')
                ->chunkById($batchSize, function ($staleRuns) use (
                    &$claimedRunCount,
                    &$claimedRunIds,
                    &$oldestStaleAgeMinutes,
                    $claimedAt,
                    $claimExpiresBefore,
                    $eventMaxIds,
                    $queue,
                    $threshold,
                    $thresholdMinutes
                ): void {
                    $staleRuns->each(function (CommandRun $run) use (
                        &$claimedRunCount,
                        &$claimedRunIds,
                        &$oldestStaleAgeMinutes,
                        $claimedAt,
                        $claimExpiresBefore,
                        $eventMaxIds,
                        $queue,
                        $threshold,
                        $thresholdMinutes
                    ): void {
                        if (! $run->claimForOrphanRecovery($threshold, $claimExpiresBefore, $claimedAt, refresh: false)) {
                            return;
                        }

                        $claimedRunCount++;
                        if (count($claimedRunIds) < $eventMaxIds) {
                            $claimedRunIds[] = (int) $run->getKey();
                        }
                        $staleAgeMinutes = $this->staleAgeMinutes($run);
                        $oldestStaleAgeMinutes = max($oldestStaleAgeMinutes, $staleAgeMinutes);

                        $job = new ProcessCommandRunJob($run)
                            ->onQueue($queue);

                        $runClaimedAt = $claimedAt;

                        try {
                            $this->dispatcher->dispatch($job);
                        } catch (Throwable $exception) {
                            $claimedRunCount--;
                            if (($arrayKey = array_search((int) $run->getKey(), $claimedRunIds, true)) !== false) {
                                unset($claimedRunIds[$arrayKey]);
                                $claimedRunIds = array_values($claimedRunIds);
                            }

                            if ($runClaimedAt !== null) {
                                $run->releaseOrphanRecoveryClaim($runClaimedAt, refresh: false);
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
                });
        } catch (Throwable $exception) {
            $dispatchFailure = $exception;
        }

        if ($claimedRunCount > 0) {
            $this->events->dispatch(new QueueLagDetected(
                $queue,
                $claimedRunCount,
                $thresholdMinutes,
                $oldestStaleAgeMinutes,
                $claimedRunIds,
                $claimedRunCount > count($claimedRunIds),
            ));
        }

        if ($dispatchFailure instanceof Throwable) {
            throw $dispatchFailure;
        }

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

    private function staleAgeMinutes(CommandRun $run): int
    {
        if ($run->updated_at === null) {
            return 0;
        }

        return max(0, (int) $run->updated_at->diffInMinutes(now()));
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
            'driver' => $run->resolvedDriverName((string) config('checkpoint.driver', 'shell')),
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target ?? $run->argument_text,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
