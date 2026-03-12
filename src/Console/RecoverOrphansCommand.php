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
        $queue = (string) $this->config->get('checkpoint.queue.name', 'db-ops');
        $staleRuns = CommandRun::query()
            ->pending()
            ->where('created_at', '<', $threshold)
            ->get();

        if ($staleRuns->isNotEmpty()) {
            $this->events->dispatch(new QueueLagDetected($queue, $staleRuns->count(), $thresholdMinutes));
        }

        $staleRuns->each(function (CommandRun $run) use ($queue, $thresholdMinutes): void {
                $job = new ProcessCommandRunJob($run)
                    ->onQueue($queue);

                $this->dispatcher->dispatch($job);
                $this->events->dispatch(new OrphanRunRedispatched($run, $thresholdMinutes));

                $this->logs->channel((string) $this->config->get('checkpoint.log_channel', 'stack'))
                    ->warning('Recover orphans re-dispatched command run', [
                        'run_id' => $run->getKey(),
                        'operation' => $run->operation,
                    ]);

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
}
