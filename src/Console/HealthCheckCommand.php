<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class HealthCheckCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'db-ops:health-check';

    protected $description = 'Mark timed-out running command runs as failed.';

    protected $aliases = ['db-ops:check:health'];

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly LogManager $logs,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->enhancedInteractiveMode()) {
            intro('Health Check: Running Command Timeout Sweep');
            note('What: detect and fail stale running runs based on heartbeat/timeout.');
            note('When: scheduled maintenance or investigating stuck running jobs.');
            note('Next: run db-ops:admin:recover-orphans if jobs were marked stale.');
        }

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
                    ->error('Health check marked command run as failed', $this->logContext($run, [
                        'timeout_seconds' => $timeoutSeconds,
                    ]));

                $this->promptInfo($this->recoveryMessage((int) $run->getKey(), $timeoutSeconds));
            });

        if ($this->enhancedInteractiveMode()) {
            outro('Health check completed.');
        }

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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, array $extra = []): array
    {
        return array_filter([
            'run_id' => $run->getKey(),
            'operation' => $run->operation,
            'driver' => $run->resolvedDriverName((string) $this->config->get('checkpoint.driver', 'shell')),
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

}
