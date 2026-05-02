<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;

it('throws when filesystem persistence fails during inline persist', function (): void {
    config()->set('checkpoint.output.storage', 'filesystem');
    config()->set('checkpoint.output.filesystem.disk', 'failing');
    config()->set('checkpoint.output.filesystem.path_prefix', 'checkpoint/test-output');
    config()->set('checkpoint.output.filesystem.inline_bytes', 0);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->once()->andReturnFalse();

    $factory = Mockery::mock(FilesystemFactory::class);
    $factory->shouldReceive('disk')->with('failing')->andReturn($filesystem);

    $store = new CommandOutputStore(config(), $factory, resolve(CommandOutputCapture::class));

    expect(fn () => $store->persist($run, 'payload'))
        ->toThrow(
            ConfigurationException::class,
            sprintf('Unable to persist command output to [failing:checkpoint/test-output/command-run-%d.log].', $run->getKey()),
        );
});

it('throws when appending a capture chunk to an invalid temp path', function (): void {
    $store = resolve(CommandOutputStore::class);

    expect(fn () => $store->appendCaptureChunk([
        'disk' => 'local',
        'path' => 'checkpoint/invalid.log',
        'temp_path' => sys_get_temp_dir(),
    ], 'chunk'))
        ->toThrow(ConfigurationException::class, 'Unable to append command output to temporary storage.');
});

it('throws when finishing capture with a missing temporary file', function (): void {
    $store = resolve(CommandOutputStore::class);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn () => $store->finishCapture($run, 'captured', [
        'disk' => 'local',
        'path' => 'checkpoint/missing.log',
        'temp_path' => sys_get_temp_dir().'/checkpoint-missing-'.uniqid('', true),
    ]))->toThrow(ConfigurationException::class, 'Unable to open temporary command output storage.');
});
