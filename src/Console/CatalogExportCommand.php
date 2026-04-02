<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildBackupCatalogExportAction;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class CatalogExportCommand extends Command
{
    protected $signature = 'db-ops:catalog-export
        {--format=json : Output format: json or csv.}
        {--driver= : Filter by driver name. Use "none" for null values.}
        {--repository= : Filter by repository id. Use "none" for null values.}
        {--stanza= : Filter by stanza. Use "none" for null values.}
        {--window= : Filter to command runs created within the last N hours.}
        {--limit=100 : Number of catalog rows to export.}';

    protected $description = 'Export backup catalog snapshots in machine-friendly JSON or CSV formats.';

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly BuildBackupCatalogExportAction $buildCatalogExport,
        private readonly Repository $config,
        private readonly CommandJsonContract $jsonContract,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['json', 'csv'], true)) {
            $this->error('The --format option must be json or csv.');

            return self::FAILURE;
        }

        try {
            $this->validator->validate();
        } catch (\Throwable $exception) {
            if ($format === 'json') {
                $this->line(json_encode($this->jsonContract->envelope('catalog_export', [
                    'generated_at' => now()->toIso8601String(),
                    'driver' => (string) $this->config->get('checkpoint.driver'),
                    'format' => 'json',
                    'limit_requested' => null,
                    'limit' => null,
                    'filters' => [
                        'driver' => null,
                        'repository' => null,
                        'stanza' => null,
                        'window_hours' => null,
                    ],
                    'count' => 0,
                    'rows' => [],
                    'error' => [
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ],
                ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        $repositoryFilter = $this->normalizedRepositoryFilter();
        $windowHours = $this->windowHours();

        if ($repositoryFilter === null && $this->option('repository') !== null) {
            $this->error('The --repository option must be an integer or "none".');

            return self::FAILURE;
        }

        if ($windowHours === null && $this->option('window') !== null) {
            $this->error('The --window option must be a positive integer.');

            return self::FAILURE;
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        $export = $this->buildCatalogExport->execute(
            driverFilter: $this->normalizedTextFilter((string) $this->option('driver')),
            repositoryFilter: $repositoryFilter,
            stanzaFilter: $this->normalizedTextFilter((string) $this->option('stanza')),
            windowHours: $windowHours,
            limit: $effectiveLimit,
        );

        if ($format === 'json') {
            $this->line(json_encode($this->jsonContract->envelope('catalog_export', [
                'generated_at' => now()->toIso8601String(),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'format' => 'json',
                'limit_requested' => $requestedLimit,
                'limit' => $effectiveLimit,
                'filters' => $export['filters'],
                'count' => count($export['rows']),
                'rows' => $export['rows'],
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line($this->csvPayload($export['rows']));

        return self::SUCCESS;
    }

    /**
     * @return array{requested:int,effective:int}
     */
    private function recentRunLimits(): array
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = (int) $this->config->get('checkpoint.reporting.max_recent_runs', 100);

        if ($configuredCap < 1) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must be greater than zero.');
        }

        if ($configuredCap > 1000) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must not exceed 1000.');
        }

        return [
            'requested' => $requestedLimit,
            'effective' => min($requestedLimit, $configuredCap),
        ];
    }

    private function normalizedRepositoryFilter(): int|string|null
    {
        $repository = $this->option('repository');

        if ($repository === null) {
            return null;
        }

        if ($repository === 'none') {
            return 'none';
        }

        if (! is_string($repository) || ! preg_match('/^\d+$/', $repository)) {
            return null;
        }

        return (int) $repository;
    }

    private function windowHours(): ?int
    {
        $window = $this->option('window');

        if ($window === null || $window === '') {
            return null;
        }

        if (! is_string($window) || ! preg_match('/^\d+$/', $window)) {
            return null;
        }

        $hours = (int) $window;

        if ($hours < 1) {
            return null;
        }

        return $hours;
    }

    private function normalizedTextFilter(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function csvPayload(array $rows): string
    {
        $columns = [
            'command_run_id',
            'operation',
            'driver',
            'repository',
            'stanza',
            'type',
            'label',
            'path',
            'size_bytes',
            'status',
            'verification_state',
            'created_at',
            'started_at',
            'finished_at',
            'verified_at',
            'last_known_good_at',
            'latest_verification_json',
            'metadata_json',
        ];

        $lines = [$this->csvLine($columns)];

        foreach ($rows as $row) {
            $lines[] = $this->csvLine([
                $row['command_run_id'] ?? null,
                $row['operation'] ?? null,
                $row['driver'] ?? null,
                $row['repository'] ?? null,
                $row['stanza'] ?? null,
                $row['type'] ?? null,
                $row['label'] ?? null,
                $row['path'] ?? null,
                $row['size_bytes'] ?? null,
                $row['status'] ?? null,
                $row['verification_state'] ?? null,
                $row['created_at'] ?? null,
                $row['started_at'] ?? null,
                $row['finished_at'] ?? null,
                $row['verified_at'] ?? null,
                $row['last_known_good_at'] ?? null,
                json_encode($row['latest_verification'] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($row['metadata'] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function csvLine(array $values): string
    {
        return implode(',', array_map(function (mixed $value): string {
            $text = $value === null ? '' : (string) $value;

            return '"'.str_replace('"', '""', $text).'"';
        }, $values));
    }
}
