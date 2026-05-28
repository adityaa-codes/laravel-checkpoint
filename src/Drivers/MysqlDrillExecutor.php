<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class MysqlDrillExecutor
{
    public function __construct(
        private MysqlRestoreTargetValidator $restoreValidator,
        private MysqlConfiguration $config,
        private MysqlProcessRunner $processRunner,
    ) {}

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(DriverContext $context, Process $process, CommandRun $run, array $plannedMetadata): array
    {
        $drillCommand = $this->config->drillCommand();

        if ($drillCommand !== '') {
            return $this->processRunner->run($process);
        }

        return $this->executeInlineDrillValidation();
    }

    /**
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeInlineDrillValidation(): array
    {
        try {
            $target = $this->restoreValidator->latestBackupTarget();
            $size = filesize($target);

            if ($size === false || $size === 0) {
                return [
                    'output' => 'Default drill: latest backup artifact is empty or unreadable.',
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'artifact_empty',
                        ],
                    ],
                ];
            }

            $handle = fopen($target, 'r');

            if ($handle === false) {
                return [
                    'output' => 'Default drill: cannot open latest backup artifact.',
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'unreadable',
                        ],
                    ],
                ];
            }

            $head = fread($handle, 5242880);
            fclose($handle);

            if ($head === false || $head === '') {
                return [
                    'output' => 'Default drill: latest backup artifact is empty.',
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'head_empty',
                        ],
                    ],
                ];
            }

            $createTableCount = substr_count(strtoupper($head), 'CREATE TABLE');
            $insertCount = substr_count(strtoupper($head), 'INSERT INTO');

            if ($createTableCount === 0 && $insertCount === 0) {
                return [
                    'output' => sprintf(
                        'Default drill: artifact [%s] (%d bytes) does not contain recognizable SQL structure markers.',
                        basename($target),
                        $size,
                    ),
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'no_structure_markers',
                            'artifact_size_bytes' => $size,
                        ],
                    ],
                ];
            }

            return [
                'output' => sprintf(
                    'Default drill: artifact [%s] (%d bytes) contains CREATE TABLE=%d INSERT INTO=%d — structure valid.',
                    basename($target),
                    $size,
                    $createTableCount,
                    $insertCount,
                ),
                'exit_code' => 0,
                'metadata' => [
                    'drill' => [
                        'method' => 'inline_structure_check',
                        'artifact_path' => $target,
                        'result' => 'structure_valid',
                        'artifact_size_bytes' => $size,
                        'create_table_count' => $createTableCount,
                        'insert_into_count' => $insertCount,
                    ],
                ],
            ];
        } catch (ConfigurationException $e) {
            return [
                'output' => sprintf('Default drill: cannot resolve latest backup artifact — %s', $e->getMessage()),
                'exit_code' => 1,
                'metadata' => [
                    'drill' => [
                        'method' => 'inline_structure_check',
                        'result' => 'no_artifact_found',
                    ],
                ],
            ];
        }
    }
}
