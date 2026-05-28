<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class MysqlProcessRunner
{
    public function __construct(
        private CommandOutputCapture $outputCapture,
    ) {}

    /**
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function run(Process $process, ?string $input = null): array
    {
        if ($input !== null) {
            $process->setInput($input);
        }

        $captured = $this->outputCapture->captureProcess(
            $process,
            static fn (string $chunk, string $type): null => null,
        );

        return [
            'output' => $captured['output'],
            'exit_code' => $process->getExitCode() ?? -1,
            'metadata' => $captured['metadata'],
        ];
    }
}
