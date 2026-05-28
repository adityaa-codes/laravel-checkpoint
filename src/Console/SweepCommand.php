<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

final class SweepCommand extends CheckpointCommand
{
    use RendersJsonOutput;

    protected $signature = 'checkpoint:sweep
                            {--format=table : Output format: table or json.}';

    protected $description = 'Mark timed-out running command runs as failed and re-dispatch stale pending runs.';

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly LogManager $logs,
        private readonly BusDispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->enhancedInteractiveMode()) {
            intro('Sweep: Running Timeout + Orphan Recovery Sweep');
        }

        $this->sweepTimedOutRuns();
        $this->sweepOrphanedRuns();

        if ($this->stringOption('format') === 'json') {
            return $this->renderJson('sweep', [
                'status' => 'completed',
            ]);
        }

        if ($this->enhancedInteractiveMode()) {
            outro('Sweep completed.');
        }

        return self::SUCCESS;
    }

    private function sweepTimedOutRuns(): void
    {
        $timeoutSeconds = max(1, (int) $this->config->get('checkpoint.queue.timeout', 3600));
        $threshold = now()->subSeconds($timeoutSeconds);
        $graceSeconds = max(0, (int) $this->config->get('checkpoint.queue.heartbeat_grace_seconds', 60));
        $heartbeatThreshold = now()->subSeconds($timeoutSeconds + $graceSeconds);

        CommandRun::query()
            ->running()
            ->where(function ($query) use ($heartbeatThreshold, $threshold): void {
                $query
                    ->where(function ($runningQuery) use ($heartbeatThreshold): void {
                        $runningQuery
                            ->whereNotNull('heartbeat_at')
                            ->where('heartbeat_at', '<', $heartbeatThreshold);
                    })
                    ->orWhere(function ($runningQuery) use ($threshold): void {
                        $runningQuery
                            ->whereNull('heartbeat_at')
                            ->where('started_at', '<', $threshold);
                    });
            })
            ->each(function (CommandRun $run) use ($timeoutSeconds): void {
                $output = 'Timed out by health check';

                $run->markAsFailed(output: $output);

                $this->events->dispatch(new BackupFailed($run, -1, $output));

                $this->logs->channel((string) $this->config->get('checkpoint.log_channel', 'stack'))
                    ->error('Sweep marked command run as failed', $this->logContext($run, [
                        'timeout_seconds' => $timeoutSeconds,
                    ]));

                $this->promptInfo($this->recoveryMessage((int) $run->getKey(), $timeoutSeconds));
            });
    }

    private function sweepOrphanedRuns(): void
    {
        $thresholdMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_threshold', 10));
        $threshold = now()->subMinutes($thresholdMinutes);
        $claimTimeoutMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_claim_timeout', 1));
        $claimExpiresBefore = now()->subMinutes($claimTimeoutMinutes);
        $claimedAt = now();
        $batchSize = max(1, (int) $this->config->get('checkpoint.queue.orphan_batch_size', 100));
        $eventMaxIds = max(1, (int) $this->config->get('checkpoint.queue.orphan_event_max_ids', 50));
        $queue = (string) $this->config->get('checkpoint.queue.name', 'checkpoint');
        $claimedRunIds = [];
        $claimedRunCount = 0;
        $oldestStaleAgeMinutes = 0;

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
                $claimedAt,
                $claimExpiresBefore,
                $threshold,
                $queue,
                &$claimedRunCount,
                &$claimedRunIds,
                &$oldestStaleAgeMinutes,
                $eventMaxIds,
                $thresholdMinutes,
            ): void {
                $staleRuns->each(function (CommandRun $staleRun) use (
                    $claimedAt,
                    $claimExpiresBefore,
                    $threshold,
                    $queue,
                    &$claimedRunCount,
                    &$claimedRunIds,
                    &$oldestStaleAgeMinutes,
                    $eventMaxIds,
                    $thresholdMinutes,
                ): void {
                    if (! $staleRun->claimForOrphanRecovery($threshold, $claimExpiresBefore, $claimedAt, refresh: false)) {
                        return;
                    }

                    try {
                        $this->bus->dispatch((new ProcessCommandRunJob($staleRun))->onQueue($queue));
                    } catch (Throwable $exception) {
                        report($exception);
                        $staleRun->releaseOrphanRecoveryClaim($claimedAt, refresh: false);

                        return;
                    }

                    $claimedRunCount++;
                    $staleAgeMinutes = max(0, (int) ($staleRun->updated_at?->diffInMinutes(now()) ?? 0));
                    $oldestStaleAgeMinutes = max($oldestStaleAgeMinutes, $staleAgeMinutes);

                    if (count($claimedRunIds) < $eventMaxIds) {
                        $claimedRunIds[] = (int) $staleRun->getKey();
                    }

                    $this->events->dispatch(new OrphanRunRedispatched($staleRun, $queue, $thresholdMinutes, $staleAgeMinutes));
                    $this->promptInfo($this->redispatchedMessage((int) $staleRun->getKey()));
                });
            });

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
    }

    private function redispatchedMessage(int $runId): string
    {
        return $this->translatedOr(
            'messages.cli.orphan_redispatched',
            sprintf('Re-dispatched orphaned run #%d.', $runId),
            ['id' => $runId],
        );
    }

    private function recoveryMessage(int $runId, int $timeoutSeconds): string
    {
        return $this->translatedOr(
            'messages.cli.sweep_failed',
            sprintf(
                'Marked run #%d as failed (timed out after %d seconds).',
                $runId,
                $timeoutSeconds,
            ),
            ['id' => $runId, 'seconds' => $timeoutSeconds],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, array $extra = []): array
    {
        return collect([
            'run_id' => $run->getKey(),
            'operation' => $run->operation,
            'driver' => $run->resolvedDriverName((string) $this->config->get('checkpoint.driver')),
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            ...$extra,
        ])->filter(static fn (mixed $value): bool => $value !== null)->all();
    }
}
