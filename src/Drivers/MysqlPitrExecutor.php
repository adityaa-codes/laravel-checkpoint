<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Illuminate\Filesystem\Filesystem;

/** @internal */
final readonly class MysqlPitrExecutor
{
    public function __construct(
        private MysqlConfiguration $config,
        private MysqlProcessBuilder $processBuilder,
        private Filesystem $filesystem,
        private MysqlProcessRunner $processRunner,
    ) {}

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(DriverContext $context, CommandRun $run, array $plannedMetadata): array
    {
        $baseTarget = (string) ($plannedMetadata['pitr_base_target'] ?? '');
        $targetTime = trim((string) ($plannedMetadata['restore_target'] ?? $context->argument ?? ''));

        if ($baseTarget === '') {
            throw new ConfigurationException('pitr_restore requires a baseline logical backup artifact.');
        }

        if ($targetTime === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        $baseContents = is_file($baseTarget) ? file_get_contents($baseTarget) : false;

        if (! is_string($baseContents)) {
            throw new ConfigurationException(sprintf('Unable to read mysql PITR baseline [%s].', $baseTarget));
        }

        $restoreBaseline = $this->processRunner->run(
            $this->processBuilder->mysqlRestoreProcess(),
            $baseContents,
        );

        if ($restoreBaseline['exit_code'] !== 0) {
            return [
                'output' => "[pitr_restore:baseline]\n".$restoreBaseline['output'],
                'exit_code' => $restoreBaseline['exit_code'],
                'metadata' => [
                    ...$restoreBaseline['metadata'],
                    'pitr' => [
                        'failed_step' => 'baseline_restore',
                        'target_time' => $targetTime,
                    ],
                ],
            ];
        }

        $binlogOutputPath = tempnam($this->config->tempDir(), 'checkpoint-mysql-pitr-');

        if ($binlogOutputPath === false) {
            throw new ConfigurationException('Unable to allocate temporary mysql PITR binlog output path.');
        }

        $binlogReplay = $this->processRunner->run(
            $this->processBuilder->mysqlBinlogProcess($targetTime, $binlogOutputPath),
        );

        if ($binlogReplay['exit_code'] !== 0) {
            is_file($binlogOutputPath) && $this->filesystem->delete($binlogOutputPath);

            return [
                'output' => "[pitr_restore:baseline]\n".$restoreBaseline['output']
                    ."\n[pitr_restore:binlog]\n".$binlogReplay['output'],
                'exit_code' => $binlogReplay['exit_code'],
                'metadata' => [
                    ...$restoreBaseline['metadata'],
                    ...$binlogReplay['metadata'],
                    'pitr' => [
                        'failed_step' => 'binlog_extract',
                        'target_time' => $targetTime,
                        'baseline_artifact_path' => $baseTarget,
                        'binlog_files' => $this->config->pitrBinlogFiles(),
                    ],
                ],
            ];
        }

        $binlogSql = is_file($binlogOutputPath) ? file_get_contents($binlogOutputPath) : false;
        is_file($binlogOutputPath) && $this->filesystem->delete($binlogOutputPath);

        if (! is_string($binlogSql)) {
            throw new ConfigurationException('Unable to read extracted mysql PITR binlog SQL.');
        }

        $applyReplay = $this->processRunner->run(
            $this->processBuilder->mysqlPitrReplayProcess(),
            $binlogSql,
        );

        return [
            'output' => "[pitr_restore:baseline]\n".$restoreBaseline['output']
                ."\n[pitr_restore:binlog]\n".$binlogReplay['output']
                ."\n[pitr_restore:apply]\n".$applyReplay['output'],
            'exit_code' => $applyReplay['exit_code'],
            'metadata' => [
                ...$restoreBaseline['metadata'],
                ...$binlogReplay['metadata'],
                ...$applyReplay['metadata'],
                'pitr' => [
                    'failed_step' => $applyReplay['exit_code'] === 0 ? null : 'binlog_apply',
                    'target_time' => $targetTime,
                    'baseline_artifact_path' => $baseTarget,
                    'binlog_files' => $this->config->pitrBinlogFiles(),
                ],
            ],
        ];
    }
}
