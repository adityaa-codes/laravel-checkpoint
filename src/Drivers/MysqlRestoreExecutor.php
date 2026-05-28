<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class MysqlRestoreExecutor
{
    public function __construct(
        private MysqlRestoreTargetValidator $restoreValidator,
        private MysqlProcessBuilder $processBuilder,
        private MysqlProcessRunner $processRunner,
    ) {}

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function resolveProcess(DriverContext $context, CommandRun $run, array $plannedMetadata): Process
    {
        $plannedTarget = $plannedMetadata['restore_target'] ?? null;

        if (is_string($plannedTarget) && trim($plannedTarget) !== '') {
            $snapshot = $plannedMetadata['restore_target_snapshot'] ?? null;
            $this->restoreValidator->validatedRestoreTarget(
                $plannedTarget,
                $context->operation,
                is_array($snapshot) ? $snapshot : null,
            );
        } else {
            match ($context->operation) {
                'logical_restore_file' => $this->restoreValidator->restorePathFromArgument($context, $run),
                'logical_restore_latest' => $this->restoreValidator->latestBackupTarget(),
                default => throw ConfigurationException::unsupportedOperation($context->operation, 'mysql restore'),
            };
        }

        return $this->processBuilder->mysqlRestoreProcess();
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(Process $process, CommandRun $run, array $plannedMetadata): array
    {
        $restoreTarget = (string) ($plannedMetadata['restore_target'] ?? '');

        if ($restoreTarget === '') {
            throw new ConfigurationException('Unable to resolve mysql restore target.');
        }

        $contents = is_file($restoreTarget) ? file_get_contents($restoreTarget) : false;

        if (! is_string($contents)) {
            throw new ConfigurationException(sprintf('Unable to read mysql restore target [%s].', $restoreTarget));
        }

        return $this->processRunner->run($process, $contents);
    }
}
