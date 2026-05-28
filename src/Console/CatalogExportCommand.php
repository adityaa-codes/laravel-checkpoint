<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildBackupCatalogExportAction;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

final class CatalogExportCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:catalog:export {--output=} {--driver=} {--repository=} {--stanza=} {--window=} {--format=json} {--limit=10}';

    protected $description = 'Export backup catalog for audits, tooling, and external analysis.';

    public function __construct(
        private readonly BuildBackupCatalogExportAction $buildCatalogExport,
        private readonly CommandJsonContract $jsonContract,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = $this->stringOption('format') ?? 'json';

        if ($format === 'table') {
            $format = 'json';
        }

        $validationError = $this->validateCatalogExportOptions($format);

        if ($validationError !== null) {
            return $validationError;
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        $export = $this->buildCatalogExport->execute(
            driverFilter: $this->normalizedCatalogTextFilter($this->stringOption('driver')),
            repositoryFilter: $this->normalizedCatalogRepositoryFilter(),
            stanzaFilter: $this->normalizedCatalogTextFilter($this->stringOption('stanza')),
            windowHours: $this->catalogWindowHours(),
            limit: $effectiveLimit,
        );

        if ($format === 'json') {
            return $this->renderCatalogJsonOutput($requestedLimit, $effectiveLimit, $export);
        }

        return $this->renderCatalogCsvOutput($export['rows']);
    }

    private function validateCatalogExportOptions(string $format): ?int
    {
        if (! collect(['json', 'csv'])->containsStrict($format)) {
            $this->promptError('With --catalog, the --format option must be json or csv.');

            return self::FAILURE;
        }

        $outputPath = $this->stringOption('output');

        if ($outputPath !== null && Str::trim($outputPath) === '') {
            $this->promptError('The --output option must not be empty.');

            return self::FAILURE;
        }

        $repositoryFilter = $this->normalizedCatalogRepositoryFilter();
        $windowHours = $this->catalogWindowHours();

        if ($repositoryFilter === null && $this->option('repository') !== null) {
            $this->promptError('The --repository option must be an integer or "none".');

            return self::FAILURE;
        }

        if ($windowHours === null && $this->option('window') !== null) {
            $this->promptError('The --window option must be a positive integer.');

            return self::FAILURE;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $export
     */
    private function renderCatalogJsonOutput(int $requestedLimit, int $effectiveLimit, array $export): int
    {
        $payload = Js::encode($this->jsonContract->envelope('catalog_export', [
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'format' => 'json',
            'limit_requested' => $requestedLimit,
            'limit' => $effectiveLimit,
            'filters' => $export['filters'],
            'count' => count($export['rows']),
            'rows' => $export['rows'],
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! $this->writeCatalogExportFile($payload)) {
            $this->line($payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderCatalogCsvOutput(array $rows): int
    {
        $payload = $this->catalogCsvPayload($rows);

        if (! $this->writeCatalogExportFile($payload)) {
            $this->line($payload);
        }

        return self::SUCCESS;
    }

    private function normalizedCatalogRepositoryFilter(): int|string|null
    {
        $repository = $this->stringOption('repository');

        if ($repository === null) {
            return null;
        }

        if ($repository === 'none') {
            return 'none';
        }

        if (! Str::isMatch('/^\d+$/', $repository)) {
            return null;
        }

        return (int) $repository;
    }

    private function catalogWindowHours(): ?int
    {
        $window = $this->stringOption('window');

        if ($window === null || $window === '') {
            return null;
        }

        if (! Str::isMatch('/^\d+$/', $window)) {
            return null;
        }

        $hours = (int) $window;

        if ($hours < 1) {
            return null;
        }

        return $hours;
    }

    private function normalizedCatalogTextFilter(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = Str::trim($value);

        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function writeCatalogExportFile(string $payload): bool
    {
        $outputPath = $this->stringOption('output');

        if ($outputPath === null) {
            return false;
        }

        $trimmed = Str::trim($outputPath);

        if (File::put($trimmed, $payload) === false) {
            throw new ConfigurationException(sprintf('Unable to write catalog export to [%s].', $trimmed));
        }

        $this->promptInfo(sprintf('Catalog export written to %s', $trimmed));

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function catalogCsvPayload(array $rows): string
    {
        $lines = [$this->catalogCsvLine([
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
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->catalogCsvLine([
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
                Js::encode($row['latest_verification'] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                Js::encode($row['metadata'] ?? null, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }

        return Arr::join($lines, PHP_EOL);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function catalogCsvLine(array $values): string
    {
        return Arr::join(collect($values)->map(function (mixed $value): string {
            $text = $value === null ? '' : (string) $value;

            return '"'.Str::replace('"', '""', $text).'"';
        })->all(), ',');
    }
}
