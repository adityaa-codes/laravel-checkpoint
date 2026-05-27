<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverResult;
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

    public function execute(DriverContext $context, CommandRun $run): DriverResult
    {
        $this->calls[] = $run;

        $outcome = $this->outcomes[$context->operation] ?? [
            'type' => 'success',
            'exit_code' => 0,
            'output' => 'ok',
        ];

        if ($outcome['type'] === 'throw') {
            if (! isset($outcome['throwable'])) {
                throw new \LogicException('Throw outcomes must provide a throwable instance.');
            }

            throw $outcome['throwable'];
        }

        $exitCode = $outcome['exit_code'] ?? 0;
        $output = $outcome['output'] ?? '';

        if ($outcome['type'] === 'fail') {
            $context->result = DriverResult::failure($output, $exitCode);

            return $context->result;
        }

        $context->result = DriverResult::success($output, $exitCode);

        return $context->result;
    }

    /**
     * @return list<CommandRun>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function recordCall(CommandRun $run): void
    {
        $this->calls[] = $run;
    }
}
