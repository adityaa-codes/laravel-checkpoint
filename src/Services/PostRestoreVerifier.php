<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/** @internal */
final readonly class PostRestoreVerifier
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $db,
    ) {}

    /**
     * @param  Closure(string, int, int): void|null  $progressCallback
     * @return array<string, mixed>
     */
    public function verify(CommandRun $run, ?Closure $progressCallback = null): array
    {
        $snapshot = $this->snapshotFromMetadata($run);

        if ($snapshot === null) {
            $result = $this->buildResult('skipped', [], []);

            $this->persistResult($run, $result);

            return $result;
        }

        $tables = $this->resolveTables();
        $mode = $this->config->get('checkpoint.restore.verification.mode', 'moderate');
        $total = count($tables);

        $tableResults = [];
        $allMatch = true;

        foreach ($tables as $index => $table) {
            if ($progressCallback !== null) {
                $progressCallback($table, $index + 1, $total);
            }

            $previous = $snapshot[$table] ?? null;
            $currentCount = $this->tableRowCount($table);
            $previousCount = is_array($previous) ? $previous['count'] : null;
            $countsMatch = is_int($previousCount) && $currentCount === $previousCount;

            $currentChecksum = null;
            $checksumMatch = true;

            if ($mode === 'full') {
                $currentChecksum = $this->tableChecksum($table);
                $previousChecksum = is_array($previous) ? ($previous['checksum'] ?? null) : null;
                $checksumMatch = $currentChecksum === $previousChecksum;
            }

            $tableMatch = $countsMatch && $checksumMatch;

            if (! $tableMatch) {
                $allMatch = false;
            }

            $tableResults[] = [
                'table' => $table,
                'previous_count' => $previousCount,
                'current_count' => $currentCount,
                'counts_match' => $countsMatch,
                'previous_checksum' => $mode === 'full'
                    ? (is_array($previous) ? ($previous['checksum'] ?? null) : null)
                    : null,
                'current_checksum' => $currentChecksum,
                'checksum_match' => $mode === 'full' ? $checksumMatch : null,
                'match' => $tableMatch,
            ];
        }

        $result = $this->buildResult(
            $allMatch ? 'verified' : 'mismatch',
            $tables,
            $tableResults,
        );

        $this->persistResult($run, $result);

        return $result;
    }

    /**
     * @return array<string, array{count: int, checksum?: string}>
     */
    public function capturePreRestoreSnapshot(CommandRun $run): array
    {
        $tables = $this->resolveTables();
        $mode = $this->config->get('checkpoint.restore.verification.mode', 'moderate');

        $snapshot = [];

        foreach ($tables as $table) {
            $entry = [
                'count' => $this->tableRowCount($table),
            ];

            if ($mode === 'full') {
                $entry['checksum'] = $this->tableChecksum($table);
            }

            $snapshot[$table] = $entry;
        }

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $metadata['pre_restore_snapshot'] = $snapshot;
        $run->forceFill(['metadata' => $metadata])->save();

        return $snapshot;
    }

    /**
     * @return array<int, string>
     */
    private function resolveTables(): array
    {
        $configured = $this->config->get('checkpoint.restore.verification.tables', ['*']);

        if (! is_array($configured) || $configured === []) {
            return [];
        }

        if ($configured === ['*']) {
            return $this->allUserTables();
        }

        return collect($configured)
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function allUserTables(): array
    {
        $driverName = $this->db->connection()->getDriverName();
        $prefix = $this->config->get('checkpoint.table_prefix', 'db_ops_');

        $tables = match ($driverName) {
            'mysql' => $this->mysqlTableNames($this->db->connection()->getDatabaseName()),
            'pgsql' => $this->pgsqlTableNames(),
            'sqlite' => $this->sqliteTableNames(),
            default => [],
        };

        return collect($tables)
            ->filter(fn (string $table): bool => ! str_starts_with($table, $prefix))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function mysqlTableNames(string $database): array
    {
        $rows = $this->db->connection()
            ->select('SHOW TABLES');

        $key = 'Tables_in_'.$database;

        return collect($rows)
            ->pluck($key)
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function pgsqlTableNames(): array
    {
        $rows = $this->db->connection()
            ->select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')");

        return collect($rows)
            ->pluck('tablename')
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function sqliteTableNames(): array
    {
        $rows = $this->db->connection()
            ->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        return collect($rows)
            ->pluck('name')
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->values()
            ->all();
    }

    private function tableRowCount(string $table): int
    {
        $result = $this->db->connection()
            ->table($table)
            ->count();

        return $result;
    }

    private function tableChecksum(string $table): string
    {
        $driverName = $this->db->connection()->getDriverName();

        return match ($driverName) {
            'mysql' => $this->mysqlChecksum($table),
            default => $this->phpChecksum($table),
        };
    }

    private function mysqlChecksum(string $table): string
    {
        $result = $this->db->connection()
            ->select('CHECKSUM TABLE '.$table);

        return (string) ($result[0]->Checksum ?? hash('sha256', ''));
    }

    private function phpChecksum(string $table): string
    {
        $rows = $this->db->connection()
            ->table($table)
            ->get();

        $hashes = $rows
            ->map(fn ($row): string => hash('sha256', serialize((array) $row)))
            ->sort()
            ->values();

        return hash('sha256', $hashes->implode(''));
    }

    /**
     * @return array<string, array{count: int, checksum?: string}>|null
     */
    private function snapshotFromMetadata(CommandRun $run): ?array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $snapshot = $metadata['pre_restore_snapshot'] ?? null;

        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        return $snapshot;
    }

    /**
     * @param  array<int, string>  $tables
     * @param  array<int, array<string, mixed>>  $tableResults
     * @return array<string, mixed>
     */
    private function buildResult(string $aggregate, array $tables, array $tableResults): array
    {
        $mode = $this->config->get('checkpoint.restore.verification.mode', 'moderate');

        return [
            'contract_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'mode' => $mode,
            'aggregate_result' => $aggregate,
            'tables_verified' => $tables,
            'results' => $tableResults,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistResult(CommandRun $run, array $result): void
    {
        $aggregate = $result['aggregate_result'];
        $verificationState = $aggregate === 'verified' ? 'verified' : 'mismatch';

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];

        $restoreAudit['post_restore_verification'] = [
            'aggregate_result' => $aggregate,
            'mode' => $result['mode'],
            'tables_checked' => count($result['results']),
            'mismatches' => array_values(array_filter(
                $result['results'],
                static fn (array $r): bool => ! $r['match'],
            )),
        ];

        $metadata['restore_audit'] = $restoreAudit;

        $run->recordMetadata([
            'verification_state' => $verificationState,
            'metadata' => $metadata,
            'verified_at' => $aggregate === 'verified' ? now() : $run->verified_at,
        ]);
    }
}
