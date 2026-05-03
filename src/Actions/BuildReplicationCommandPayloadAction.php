<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
use Illuminate\Contracts\Config\Repository;

final readonly class BuildReplicationCommandPayloadAction
{
    public function __construct(
        private ReplicationEndpointParser $parser,
        private Repository $config,
    ) {}

    /**
     * @param  array<int, string>  $criticalTables
     * @return array{source:string,destination:string,dry_run:bool,force_overwrite:bool,critical_tables:array<int,string>}
     */
    public function execute(
        string $source,
        string $destination,
        bool $apply,
        bool $forceOverwrite,
        array $criticalTables = [],
    ): array {
        $normalizedSource = trim($source);
        $normalizedDestination = trim($destination);

        $this->assertEndpoint($normalizedSource, 'source');
        $this->assertEndpoint($normalizedDestination, 'destination');

        return [
            'source' => $normalizedSource,
            'destination' => $normalizedDestination,
            'dry_run' => ! $apply,
            'force_overwrite' => $forceOverwrite,
            'critical_tables' => $this->resolveCriticalTables($criticalTables),
        ];
    }

    private function assertEndpoint(string $input, string $role): void
    {
        if ($input === '') {
            throw new CheckpointArgumentException(sprintf('Replication %s endpoint is required.', $role));
        }

        $parsed = $this->parser->parse($input);

        if ($parsed->kind->value === 'prompt') {
            throw new CheckpointArgumentException(sprintf('Replication %s endpoint is required.', $role));
        }
    }

    /**
     * @param  array<mixed>  $tables
     * @return array<int, string>
     */
    private function resolveCriticalTables(array $tables): array
    {
        $normalizedOptionTables = $this->normalizeCriticalTables($tables);

        if ($normalizedOptionTables !== []) {
            return $normalizedOptionTables;
        }

        $configTables = $this->config->get('checkpoint.replication.critical_tables', []);

        if (! is_array($configTables)) {
            throw new CheckpointArgumentException('checkpoint.replication.critical_tables must be an array of non-empty strings.');
        }

        return $this->normalizeCriticalTables($configTables);
    }

    /**
     * @param  array<mixed>  $tables
     * @return array<int, string>
     */
    private function normalizeCriticalTables(array $tables): array
    {
        $normalized = [];

        foreach ($tables as $table) {
            if (! is_string($table)) {
                throw new CheckpointArgumentException('Critical tables must be non-empty strings.');
            }

            $value = trim($table);

            if ($value === '') {
                throw new CheckpointArgumentException('Critical tables must be non-empty strings.');
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }
}
