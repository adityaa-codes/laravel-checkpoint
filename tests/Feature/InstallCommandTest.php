<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;

it('auto-detects driver and completes install', function (): void {
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
    config()->set('checkpoint.drivers.mysql.dump_binary', 'missing-mysqldump');
    config()->set('checkpoint.drivers.mysql.mysql_binary', 'missing-mysql');

    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate')
        ->assertFailed();
});

it('writes production restore safety setting to environment file', function (): void {
    $tempDirectory = sys_get_temp_dir().'/checkpoint-install-'.bin2hex(random_bytes(6));
    mkdir($tempDirectory, 0777, true);
    $envPath = $tempDirectory.'/.env';
    file_put_contents($envPath, "APP_NAME=Checkpoint\nAPP_ENV=production\n");

    $originalEnvironmentPath = app()->environmentPath();
    $originalEnvironmentFile = app()->environmentFile();

    try {
        $command = app(InstallCommand::class);
        $method = new ReflectionMethod($command, 'promptProductionSafety');
        $method->setAccessible(true);

        app()->useEnvironmentPath($tempDirectory);
        app()->loadEnvironmentFrom('.env');

        $method->invoke($command);

        $contents = (string) file_get_contents($envPath);

        expect($contents)->toContain('CP_RESTORE_ALLOWED_ENVIRONMENTS=staging');
    } finally {
        app()->useEnvironmentPath($originalEnvironmentPath);
        app()->loadEnvironmentFrom($originalEnvironmentFile);

        if (file_exists($envPath)) {
            unlink($envPath);
        }

        if (is_dir($tempDirectory)) {
            rmdir($tempDirectory);
        }
    }
});

it('builds publish parameters using checkpoint package tags', function (): void {
    $command = app(InstallCommand::class);
    $method = new ReflectionMethod($command, 'publishParameters');
    $method->setAccessible(true);

    $config = $method->invoke($command, 'checkpoint-config', false);
    $migrations = $method->invoke($command, 'checkpoint-migrations', true);

    expect($config)->toBe(['--tag' => 'checkpoint-config'])
        ->and($migrations)->toBe(['--tag' => 'checkpoint-migrations', '--force' => true]);
});
