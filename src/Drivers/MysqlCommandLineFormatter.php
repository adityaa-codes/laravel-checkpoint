<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;

/** @internal */
final class MysqlCommandLineFormatter
{
    public function __construct(
        private readonly MysqlProcessBuilder $processBuilder,
        private readonly MysqlConfiguration $config,
    ) {}

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function format(DriverContext $context, CommandRun $run, array $plannedMetadata): string
    {
        return match ($context->operation) {
            'pitr_restore' => implode(' ; ', [
                $this->processBuilder->mysqlRestoreProcess()->getCommandLine(),
                $this->processBuilder->mysqlBinlogProcess((string) ($plannedMetadata['restore_target'] ?? $context->argument ?? ''))->getCommandLine(),
                $this->processBuilder->mysqlPitrReplayProcess()->getCommandLine(),
            ]),
            'backup_drill' => $this->config->drillCommand() !== ''
                ? $this->processBuilder->buildProcess($context, $run, $plannedMetadata)->getCommandLine()
                : '(inline structure validation)',
            'replication_sync' => implode(' ; ', array_filter([
                $this->processBuilder->mysqlReplicationDryRunProcess($plannedMetadata)->getCommandLine(),
                (bool) ($plannedMetadata['metadata']['replication']['apply_requested'] ?? false)
                    ? $this->processBuilder->mysqlReplicationApplyProcess()->getCommandLine()
                    : null,
            ])),
            default => $this->processBuilder->buildProcess($context, $run, $plannedMetadata)->getCommandLine(),
        };
    }
}
