<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecoverOrphansCommand extends Command
{
    protected $signature = 'db-ops:recover-orphans';

    protected $description = 'Re-dispatch stale pending checkpoint command runs.';

    public function handle(): int
    {
        $thresholdMinutes = max(1, (int) config('checkpoint.queue.orphan_threshold', 10));
        $threshold = now()->subMinutes($thresholdMinutes);

        CommandRun::query()
            ->pending()
            ->where('created_at', '<', $threshold)
            ->each(function (CommandRun $run): void {
                ProcessCommandRunJob::dispatch($run)
                    ->onQueue(config('checkpoint.queue.name', 'db-ops'));

                Log::channel(config('checkpoint.log_channel', 'stack'))
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
