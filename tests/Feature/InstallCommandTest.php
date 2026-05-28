<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;

it('uses configured driver and completes install', function (): void {
    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Driver')
        ->assertSuccessful();
});

it('publishes config and migrations', function (): void {
    checkpoint_artisan('checkpoint:install --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Driver')
        ->expectsOutputToContain('Migrations')
        ->assertSuccessful();
});

it('completes install when doctor has no hard failures', function (): void {
    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate')
        ->expectsOutputToContain('Doctor')
        ->assertSuccessful();
});

it('renders install summary in non-interactive mode', function (): void {
    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate --skip-doctor --no-interaction')
        ->expectsOutputToContain('Driver')
        ->assertSuccessful();
});

it('supports the do install command alias', function (): void {
    checkpoint_artisan('checkpoint:do:install --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Driver')
        ->assertSuccessful();
});

it('fails when doctor has failures', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.driver', 'mysql');
    config()->set('database.connections.mysql.dump.dump_binary_path', '/nonexistent-binary-path');

    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate')
        ->assertFailed();
});

it('outputs production restore safety instructions', function (): void {
    $originalEnv = app()['env'];
    app()['env'] = 'production';

    try {
        $exitCode = Artisan::call('checkpoint:install', [
            '--skip-publish' => true,
            '--skip-migrate' => true,
            '--skip-doctor' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('CP_RESTORE_ALLOWED_ENVIRONMENTS=staging');
    } finally {
        app()['env'] = $originalEnv;
    }
});

it('outputs driver persistence instructions', function (): void {
    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('CP_DRIVER=')
        ->assertSuccessful();
});

it('builds publish parameters using checkpoint package tags', function (): void {
    $command = app(InstallCommand::class);
    $method = new ReflectionMethod($command, 'publishParameters');

    $config = $method->invoke($command, 'checkpoint-config', false);
    $migrations = $method->invoke($command, 'checkpoint-migrations', true);

    expect($config)->toBe(['--tag' => 'checkpoint-config'])
        ->and($migrations)->toBe(['--tag' => 'checkpoint-migrations', '--force' => true]);
});
