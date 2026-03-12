<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Throwable;

/** @internal */
final class FakeDriver implements BackupDriver
{
    /**
     * @var list<CommandRun>
     */
    private array $calls = [];

    /**
     * @var array<string, array{type: 'success'|'fail'|'throw', exit_code?: int, output?: string, throwable?: Throwable}>
     */
    private array $outcomes = [];

    public function succeed(string $operation, int $exitCode = 0, string $output = 'ok'): self
    {
        $this->outcomes[$operation] = [
            'type' => 'success',
            'exit_code' => $exitCode,
            'output' => $output,
        ];

        return $this;
    }

    public function fail(string $operation, int $exitCode = 1, string $output = 'failed'): self
    {
        $this->outcomes[$operation] = [
            'type' => 'fail',
            'exit_code' => $exitCode,
            'output' => $output,
        ];

        return $this;
    }

    public function throw(string $operation, Throwable $throwable): self
    {
        $this->outcomes[$operation] = [
            'type' => 'throw',
            'throwable' => $throwable,
        ];

        return $this;
    }

    public function execute(CommandRun $run): void
    {
        $outcome = $this->outcomes[$run->operation] ?? [
            'type' => 'success',
            'exit_code' => 0,
            'output' => 'ok',
        ];

        if (! $run->claimPendingExecution()) {
            return;
        }

        $this->calls[] = $run;

        event(new BackupStarted($run));

        if ($outcome['type'] === 'throw') {
            if (! isset($outcome['throwable'])) {
                throw new \LogicException('Throw outcomes must provide a throwable instance.');
            }

            $throwable = $outcome['throwable'];
            $run->markAsFailed(output: $throwable->getMessage());
            event(new BackupFailed($run, -1, $throwable->getMessage(), $throwable));

            throw $throwable;
        }

        $exitCode = $outcome['exit_code'] ?? 0;
        $output = $outcome['output'] ?? '';

        if ($outcome['type'] === 'fail') {
            $run->markAsFailed($exitCode, $output);
            event(new BackupFailed($run, $exitCode, $output));

            return;
        }

        $run->markAsSucceeded($exitCode, $output);
        event(new BackupCompleted($run, $exitCode, $output));
    }

    /**
     * @return list<CommandRun>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
