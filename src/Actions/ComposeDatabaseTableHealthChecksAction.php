<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final readonly class ComposeDatabaseTableHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
        private DatabaseManager $database,
    ) {}

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(): array
    {
        return [
            $this->tableRow('command_runs', $this->config->commandRunsTable),
            $this->tableRow('backup_drill_runs', $this->config->backupDrillRunsTable),
            $this->tableRow('verification_runs', $this->config->verificationRunsTable),
        ];
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function tableRow(string $label, string $table): array
    {
        $connection = $this->database->connection();

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return $this->checkRow('db.'.$label.'_table', 'DB: '.$label.' table', 'fail', 'Table not found', [
                'table' => $table,
                'exists' => false,
                'row_count' => null,
            ]);
        }

        try {
            $count = $connection->table($table)->count();
        } catch (QueryException) {
            $count = 0;
        }

        return $this->checkRow('db.'.$label.'_table', 'DB: '.$label.' table', 'pass', sprintf('%d rows', $count), [
            'table' => $table,
            'exists' => true,
            'row_count' => $count,
        ]);
    }
}
