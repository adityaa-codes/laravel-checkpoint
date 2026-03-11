<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;

final class RecoverOrphansCommand extends Command
{
    protected $signature = 'db-ops:recover-orphans';

    protected $description = 'Re-dispatch stale pending checkpoint command runs.';

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $dispatcher,
        private readonly LogManager $logs,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $thresholdMinutes = max(1, (int) $this->config->get('checkpoint.queue.orphan_threshold', 10));
        $threshold = now()->subMinutes($thresholdMinutes);

        CommandRun::query()
            ->pending()
            ->where('created_at', '<', $threshold)
            ->each(function (CommandRun $run): void {
                $job = new ProcessCommandRunJob($run)
                    ->onQueue((string) $this->config->get('checkpoint.queue.name', 'db-ops'));

                $this->dispatcher->dispatch($job);

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
