<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Tests\Support\CommandJsonFixtureSupport;
use Illuminate\Support\Facades\Artisan;

it('exports catalog as json with default options', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--format' => 'json', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('catalog_export')
        ->and($report['format'])->toBe('json')
        ->and($report['rows'])->toBeArray();

    CommandJsonFixtureSupport::resetTime();
});

it('exports catalog as csv when format is csv', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--format' => 'csv', '--limit' => 10]);

    $output = Artisan::output();

    expect($output)->toContain('command_run_id')
        ->and($output)->toContain('operation')
        ->and($output)->toContain('driver');

    CommandJsonFixtureSupport::resetTime();
});

it('fails when catalog format is not json or csv', function (): void {
    Artisan::call('checkpoint:catalog:export', ['--format' => 'xml', '--limit' => 10]);

    expect(Artisan::output())->toContain('With --catalog, the --format option must be json or csv.');
});

it('fails when output option is empty string', function (): void {
    Artisan::call('checkpoint:catalog:export', ['--output' => '   ', '--format' => 'json']);

    expect(Artisan::output())->toContain('The --output option must not be empty.');
});

it('fails when repository option is not an integer or none', function (): void {
    Artisan::call('checkpoint:catalog:export', ['--repository' => 'abc', '--format' => 'json']);

    expect(Artisan::output())->toContain('The --repository option must be an integer or "none".');
});

it('fails when window option is not a positive integer', function (): void {
    Artisan::call('checkpoint:catalog:export', ['--window' => 'abc', '--format' => 'json']);

    expect(Artisan::output())->toContain('The --window option must be a positive integer.');
});

it('accepts repository value of none', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--repository' => 'none', '--format' => 'json', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['filters']['repository'])->toBe('none');

    CommandJsonFixtureSupport::resetTime();
});

it('accepts valid integer repository filter', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--repository' => '1', '--format' => 'json', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['filters']['repository'])->toBe(1);

    CommandJsonFixtureSupport::resetTime();
});

it('accepts valid window hours filter', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--window' => '24', '--format' => 'json', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['filters']['window_hours'])->toBe(24);

    CommandJsonFixtureSupport::resetTime();
});

it('filters catalog by driver name', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--driver' => 'pgbackrest', '--format' => 'json', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['filters']['driver'])->toBe('pgbackrest');

    CommandJsonFixtureSupport::resetTime();
});

it('filters catalog by stanza', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--stanza' => 'main', '--format' => 'json', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['filters']['stanza'])->toBe('main');

    CommandJsonFixtureSupport::resetTime();
});

it('converts table format to json', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog:export', ['--format' => 'table', '--limit' => 10]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toBeArray()
        ->and($report['surface'])->toBe('catalog_export')
        ->and($report['format'])->toBe('json');

    CommandJsonFixtureSupport::resetTime();
});
