<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Tests\Support\OperatorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('forwards replicate journey flags to the primary replicate command', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);
    config()->set('checkpoint.replication.allowlisted_destinations', ['profile:pg-destination']);

    checkpoint_artisan('checkpoint:do:replicate --source=profile:pg-source --destination=profile:pg-destination --apply --force-overwrite --critical-table=users --critical-table=orders')
        ->expectsOutput('Queued Replication Sync run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();
    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'source' => 'profile:pg-source',
        'destination' => 'profile:pg-destination',
        'dry_run' => false,
        'apply' => true,
        'force_overwrite' => true,
        'critical_tables' => ['users', 'orders'],
    ]);
});

it('forwards check report journey command options', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('checkpoint:check:report', ['--limit' => 2, '--agent' => true]);
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report['surface'])->toBe('report')
        ->and($report['data']['limit_requested'] ?? null)->toBe(2);

    OperatorCommandTestSupport::resetTime();
});

it('forwards status journey summary options', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('checkpoint:do:status', ['--summary' => true, '--format' => 'json']);
    $status = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status['surface'])->toBe('status')
        ->and($status['mode'] ?? null)->toBe('summary');

    OperatorCommandTestSupport::resetTime();
});

it('accepts the recover-orphans batch option from the admin wrapper', function (): void {
    Date::setTestNow('2026-03-12 10:00:00');
    Bus::fake();
    config()->set('checkpoint.queue.orphan_threshold', 10);
    config()->set('checkpoint.queue.orphan_batch_size', 100);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subMinutes(30),
        'updated_at' => Date::now()->subMinutes(20),
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subMinutes(31),
        'updated_at' => Date::now()->subMinutes(21),
    ]);

    checkpoint_artisan('checkpoint:admin:recover-orphans --batch=1')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(ProcessCommandRunJob::class, 2);
    Date::setTestNow();
});

it('forwards catalog export output path from admin wrapper', function (): void {
    $outputPath = tempnam(sys_get_temp_dir(), 'checkpoint-catalog-export-');

    if ($outputPath === false) {
        throw new RuntimeException('Unable to allocate catalog export output path.');
    }

    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_full',
        'backup_type' => 'full',
        'backup_label' => '20260311-010101F',
        'artifact_path' => '/var/backups/full-20260311.tar',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
    ]);

    checkpoint_artisan(sprintf('checkpoint:admin:catalog-export --output=%s', $outputPath))
        ->expectsOutput(sprintf('Catalog export written to %s', $outputPath))
        ->assertSuccessful();

    $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['surface'] ?? null)->toBe('catalog_export')
        ->and($payload['count'] ?? null)->toBe(1);

    @unlink($outputPath);
});
