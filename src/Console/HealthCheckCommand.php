<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class HealthCheckCommand extends Command
{
    protected $signature = 'db-ops:health-check';

    protected $description = 'Mark timed-out running command runs as failed.';

    public function handle(): int
    {
        $timeoutSeconds = max(1, (int) config('checkpoint.queue.timeout', 3600));
        $threshold = now()->subSeconds($timeoutSeconds);

        CommandRun::query()
            ->running()
            ->where('started_at', '<', $threshold)
            ->each(function (CommandRun $run) use ($timeoutSeconds): void {
                $output = 'Timed out by health check';

                $run->markAsFailed(output: $output);

                event(new BackupFailed($run, -1, $output));

                Log::channel(config('checkpoint.log_channel', 'stack'))
                    ->error('Health check marked command run as failed', [
                        'run_id' => $run->getKey(),
                        'operation' => $run->operation,
                        'timeout_seconds' => $timeoutSeconds,
                    ]);

                $this->line($this->recoveryMessage((int) $run->getKey(), $timeoutSeconds));
            });

        return self::SUCCESS;
    }

    private function recoveryMessage(int $runId, int $timeoutSeconds): string
    {
        $message = __('messages.cli.health_check_failed', [
            'id' => $runId,
            'seconds' => $timeoutSeconds,
        ]);

        if ($message === 'messages.cli.health_check_failed') {
            return sprintf(
                'Marked run #%d as failed (timed out after %d seconds).',
                $runId,
                $timeoutSeconds,
            );
        }

        return (string) $message;
    }
}
