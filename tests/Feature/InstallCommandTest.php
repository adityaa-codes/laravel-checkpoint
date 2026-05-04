<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;

it('applies the minimal preset to runtime config', function (): void {
    checkpoint_artisan('checkpoint:install --preset=minimal --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Preset applied')
        ->assertSuccessful();

    expect(config('checkpoint.driver'))->toBe('shell')
        ->and(config('checkpoint.drivers.shell.commands.logical_backup'))->toBe('php -r if(!is_dir($argv[1]))mkdir($argv[1],0777,true);touch($argv[2]); {backup_dir} {output}')
        ->and(config('checkpoint.restore.require_verified_backup'))->toBeFalse()
        ->and(config('checkpoint.restore.allow_in_ci'))->toBeTrue();
});

it('completes install when doctor has no hard failures', function (): void {
    checkpoint_artisan('checkpoint:install --preset=minimal --skip-publish --skip-migrate')
        ->expectsOutputToContain('Doctor')
        ->expectsOutputToContain('Readiness')
        ->assertSuccessful();
});

it('renders install summary in non-interactive mode', function (): void {
    checkpoint_artisan('checkpoint:install --preset=minimal --skip-publish --skip-migrate --skip-doctor --no-interaction')
        ->expectsOutputToContain('Preset applied')
        ->expectsOutputToContain('Driver')
        ->expectsOutputToContain('Smoke backup')
        ->assertSuccessful();
});

it('supports the do install command alias', function (): void {
    checkpoint_artisan('checkpoint:do:install --preset=minimal --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Preset applied')
        ->assertSuccessful();
});

it('fails fast when required active driver binaries are missing', function (): void {
    config()->set('checkpoint.drivers.pgdump.dump_binary', 'missing-pg-dump-binary');

    checkpoint_artisan('checkpoint:install --preset=postgres-prod --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Active driver preflight failed')
        ->assertFailed();
});

it('fails when an unknown install preset is requested', function (): void {
    checkpoint_artisan('checkpoint:install --preset=unknown-preset --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Unsupported preset')
        ->assertFailed();
});

it('writes preset values into the configured environment file', function (): void {
    $tempDirectory = sys_get_temp_dir().'/checkpoint-install-'.bin2hex(random_bytes(6));
    mkdir($tempDirectory, 0777, true);
    $envPath = $tempDirectory.'/.env';
    file_put_contents($envPath, "APP_NAME=Checkpoint\nCP_DRIVER=shell\n");

    $originalEnvironmentPath = app()->environmentPath();
    $originalEnvironmentFile = app()->environmentFile();

    app()->useEnvironmentPath($tempDirectory);
    app()->loadEnvironmentFrom('.env');

    try {
        config()->set('checkpoint.drivers.mysql.dump_binary', PHP_BINARY);
        config()->set('checkpoint.drivers.mysql.mysql_binary', PHP_BINARY);
        config()->set('checkpoint.drivers.mysql.mysqlbinlog_binary', PHP_BINARY);

        checkpoint_artisan('checkpoint:install --preset=mysql-prod --skip-publish --skip-migrate --skip-doctor --write-env')
            ->assertSuccessful();

        $contents = (string) file_get_contents($envPath);

        expect($contents)->toContain('CP_DRIVER=mysql')
            ->toContain('CP_RESTORE_ALLOWED_ENVIRONMENTS=staging');
    } finally {
        app()->useEnvironmentPath($originalEnvironmentPath);
        app()->loadEnvironmentFrom($originalEnvironmentFile);

        @unlink($envPath);
        @rmdir($tempDirectory);
    }
});

it('fails readiness when missing active driver binaries cause blockers', function (): void {
    config()->set('checkpoint.drivers.pgbasebackup.binary', 'missing-pgbasebackup-xyz');

    checkpoint_artisan('checkpoint:install --preset=postgres-prod --skip-publish --skip-migrate')
        ->assertFailed();
});

it('fails smoke backup when migrations are skipped', function (): void {
    checkpoint_artisan('checkpoint:install --preset=minimal --skip-publish --skip-migrate --skip-doctor --smoke-backup')
        ->expectsOutputToContain('Smoke backup')
        ->expectsOutputToContain('not-ready (smoke backup failed)')
        ->assertFailed();
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
