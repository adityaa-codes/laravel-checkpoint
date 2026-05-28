<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresPitrHandler implements PostgresSelfExecutingHandler
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresLogicalRestoreHandler $logicalRestore,
        private readonly PostgresRestoreTargetResolver $targetResolver,
        private readonly PostgresSnapshotService $snapshotService,
        private readonly Filesystem $filesystem,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'pitr_restore';
    }

    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        return new Process(['true']);
    }

    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        $targetTime = trim((string) ($plannedMetadata['restore_target'] ?? $run->argument_text ?? ''));
        $baseTarget = (string) ($plannedMetadata['pitr_base_target'] ?? '');
        $walDir = $this->config->pitrWalDirectory;

        return sprintf(
            '%s ; sleep 2 ; echo "recovery_target_time = \'%s\'" >> %s/postgresql.auto.conf',
            $this->logicalRestore->displayCommandLine($run, [
                'restore_target' => $baseTarget !== '' ? $baseTarget : 'LATEST_LOGICAL_BACKUP',
            ]),
            $targetTime !== '' ? $targetTime : 'TARGET_TIME',
            $walDir !== '' ? $walDir : 'PGDATA',
        );
    }

    public function plannedMetadata(CommandRun $run): array
    {
        $targetTime = trim((string) $run->argument_text);
        $baseTarget = $this->resolveBaselineTarget();

        return [
            'restore_target' => $targetTime,
            'pitr_base_target' => $baseTarget,
            'pitr_base_target_snapshot' => $baseTarget !== ''
                ? $this->snapshotService->safeSnapshot($baseTarget, $this->config->format)
                : null,
            'metadata' => [
                'driver' => 'postgres',
                'format' => $this->config->format->value,
                'restore_mode' => 'pitr',
                'wal_directory' => $this->config->pitrWalDirectory,
                'restore_command' => $this->config->pitrRestoreCommand,
                'recovery_target_action' => $this->config->pitrRecoveryTargetAction,
            ],
        ];
    }

    public function execute(CommandRun $run, array $plannedMetadata): array
    {
        $targetTime = trim((string) ($plannedMetadata['restore_target'] ?? $run->argument_text ?? ''));
        $baseTarget = (string) ($plannedMetadata['pitr_base_target'] ?? '');

        if ($targetTime === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        if ($baseTarget === '') {
            throw new ConfigurationException('pitr_restore requires a baseline logical backup artifact.');
        }

        $logicalPlannedMetadata = [
            'restore_target' => $baseTarget,
            'restore_target_snapshot' => $plannedMetadata['pitr_base_target_snapshot'] ?? null,
        ];

        $restoreProcess = $this->logicalRestore->buildProcess($run, $logicalPlannedMetadata);
        $restoreProcess->setTimeout($this->config->commandTimeoutSeconds);
        $restoreProcess->run();

        $baselineOutput = $restoreProcess->getOutput().$restoreProcess->getErrorOutput();
        $baselineExitCode = $restoreProcess->getExitCode() ?? -1;

        if ($baselineExitCode !== 0) {
            return [
                'output' => "[pitr_restore:baseline]\n{$baselineOutput}",
                'exit_code' => $baselineExitCode,
                'metadata' => [
                    ...$plannedMetadata['metadata'] ?? [],
                    'pitr' => [
                        'failed_step' => 'baseline_restore',
                        'target_time' => $targetTime,
                    ],
                ],
            ];
        }

        $walDir = $this->config->pitrWalDirectory;
        $restoreCommand = $this->config->pitrRestoreCommand;
        $recoveryTargetAction = $this->config->pitrRecoveryTargetAction;

        $autoConfPath = ($walDir !== '' ? rtrim($walDir, '/') : '/var/lib/postgresql/data').'/postgresql.auto.conf';

        $lines = [];
        if ($restoreCommand !== '') {
            $lines[] = "restore_command = '{$restoreCommand}'";
        }
        $lines[] = "recovery_target_time = '{$targetTime}'";
        $lines[] = "recovery_target_action = '{$recoveryTargetAction}'";
        $lines[] = 'recovery_target_inclusive = true';
        $recoveryConfig = implode("\n", $lines)."\n";

        $this->filesystem->put($autoConfPath, $recoveryConfig);

        $walInstructions = "Recovery configuration written to: {$autoConfPath}\n"
            ."--- postgresql.auto.conf ---\n"
            .$recoveryConfig
            ."--- end ---\n"
            ."To complete PITR, start or restart the PostgreSQL server.\n"
            ."The server will replay WAL files and stop at {$targetTime}.\n"
            ."After recovery, the server will {$recoveryTargetAction}.";

        return [
            'output' => "[pitr_restore:baseline]\n{$baselineOutput}\n[pitr_restore:wal_config]\n{$walInstructions}",
            'exit_code' => 0,
            'metadata' => [
                ...$plannedMetadata['metadata'] ?? [],
                'pitr' => [
                    'failed_step' => null,
                    'target_time' => $targetTime,
                    'baseline_artifact_path' => $baseTarget,
                    'wal_directory' => $walDir,
                    'recovery_config_path' => $autoConfPath,
                ],
            ],
        ];
    }

    private function resolveBaselineTarget(): string
    {
        try {
            return $this->targetResolver->latestBackupTarget($this->config->format);
        } catch (ConfigurationException) {
            return '';
        }
    }
}
