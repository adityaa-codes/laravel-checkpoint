<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Support\BinaryFinder;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class ValidateOperationBinaries
{
    public function __construct(
        private Repository $config,
        private BinaryFinder $binaryFinder,
    ) {}

    /**
     * @throws RuntimeException when a required binary is not found for the operation
     */
    public function validate(string $operation): void
    {
        $driver = (string) $this->config->get('checkpoint.driver', 'shell');

        $binaries = $this->binariesForOperation($operation, $driver);

        if ($binaries === []) {
            return;
        }

        $missing = $this->resolveMissing($binaries);

        if ($missing === []) {
            return;
        }

        $names = implode(', ', $missing);

        throw new RuntimeException(sprintf(
            'Operation [%s] requires binary(s) [%s] which are not available. Install the missing binary(s) and retry.',
            $operation,
            $names,
        ));
    }

    /**
     * @return list<array{binary:string,label:string}>
     */
    private function binariesForOperation(string $operation, string $driver): array
    {
        $pitrOps = ['pitr_restore', 'physical_restore'];
        $backupOps = ['logical_backup', 'logical_backup_full', 'logical_backup_incr', 'logical_backup_diff'];
        $restoreOps = ['logical_restore_file', 'logical_restore_latest'];
        $drillOps = ['backup_drill'];

        $isPitr = in_array($operation, $pitrOps, true);
        $isBackup = in_array($operation, $backupOps, true);
        $isRestore = in_array($operation, $restoreOps, true);
        $isDrill = in_array($operation, $drillOps, true);

        return match ($driver) {
            'mysql' => array_values(array_filter([
                ($isBackup || $isDrill) ? [
                    'binary' => (string) $this->config->get('checkpoint.drivers.mysql.dump_binary', 'mysqldump'),
                    'label' => 'mysqldump',
                ] : null,
                ($isRestore || $isDrill) ? [
                    'binary' => (string) $this->config->get('checkpoint.drivers.mysql.mysql_binary', 'mysql'),
                    'label' => 'mysql',
                ] : null,
                $isPitr ? [
                    'binary' => (string) $this->config->get('checkpoint.drivers.mysql.mysqlbinlog_binary', 'mysqlbinlog'),
                    'label' => 'mysqlbinlog',
                ] : null,
            ])),
            'postgres', 'pgbasebackup' => array_values(array_filter([
                ($isBackup || $isDrill) ? [
                    'binary' => (string) $this->config->get('checkpoint.drivers.pgbasebackup.binary', 'pg_basebackup'),
                    'label' => 'pg_basebackup',
                ] : null,
            ])),
            'pgdump' => [
                [
                    'binary' => (string) $this->config->get('checkpoint.drivers.pgdump.dump_binary', 'pg_dump'),
                    'label' => 'pg_dump',
                ],
                [
                    'binary' => (string) $this->config->get('checkpoint.drivers.pgdump.restore_binary', 'pg_restore'),
                    'label' => 'pg_restore',
                ],
            ],
            default => [],
        };
    }

    /**
     * @param  list<array{binary:string,label:string}>  $binaries
     * @return list<string>
     */
    private function resolveMissing(array $binaries): array
    {
        $missing = [];

        foreach ($binaries as $entry) {
            $resolution = $this->binaryFinder->resolve($entry['binary']);

            if (! $resolution['found']) {
                $missing[] = $entry['label'];
            }
        }

        return $missing;
    }
}
