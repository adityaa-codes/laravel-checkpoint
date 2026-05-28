<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Support\BinaryFinder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
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
        $driver = (string) $this->config->get('checkpoint.driver');

        $binaries = $this->binariesForOperation($operation, $driver);

        if ($binaries === []) {
            return;
        }

        $missing = $this->resolveMissing($binaries);

        if ($missing === []) {
            return;
        }

        $names = Arr::join($missing, ', ');

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

        $isPitr = collect($pitrOps)->containsStrict($operation);
        $isBackup = collect($backupOps)->containsStrict($operation);
        $isRestore = collect($restoreOps)->containsStrict($operation);
        $isDrill = collect($drillOps)->containsStrict($operation);

        $defaultConn = (string) $this->config->get('database.default', 'mysql');
        $binaryPath = rtrim((string) $this->config->get('database.connections.'.$defaultConn.'.dump.dump_binary_path', ''), '/');
        $prefix = static fn (string $name): string => $binaryPath !== '' ? $binaryPath.'/'.$name : $name;

        return match ($driver) {
            'mysql' => collect([
                ($isBackup || $isDrill) ? [
                    'binary' => $prefix('mysqldump'),
                    'label' => 'mysqldump',
                ] : null,
                ($isRestore || $isDrill) ? [
                    'binary' => $prefix('mysql'),
                    'label' => 'mysql',
                ] : null,
                $isPitr ? [
                    'binary' => $prefix('mysqlbinlog'),
                    'label' => 'mysqlbinlog',
                ] : null,
            ])->filter()->values()->all(),
            'postgres' => collect([
                ($isBackup || $isDrill) ? [
                    'binary' => $prefix('pg_basebackup'),
                    'label' => 'pg_basebackup',
                ] : null,
                [
                    'binary' => $prefix('pg_dump'),
                    'label' => 'pg_dump',
                ],
                [
                    'binary' => $prefix('pg_restore'),
                    'label' => 'pg_restore',
                ],
            ])->filter()->values()->all(),
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
