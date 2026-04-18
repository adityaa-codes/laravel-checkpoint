<?php

declare(strict_types=1);

it('applies the minimal preset to runtime config', function (): void {
    checkpoint_artisan('db-ops:install --preset=minimal --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Preset applied')
        ->assertSuccessful();

    expect(config('checkpoint.driver'))->toBe('shell')
        ->and(config('checkpoint.restore.require_verified_backup'))->toBeFalse()
        ->and(config('checkpoint.restore.allow_in_ci'))->toBeTrue();
});

it('completes install when doctor has no hard failures', function (): void {
    checkpoint_artisan('db-ops:install --preset=minimal --skip-publish --skip-migrate')
        ->expectsOutputToContain('Doctor')
        ->assertSuccessful();
});

it('supports the do install command alias', function (): void {
    checkpoint_artisan('db-ops:do:install --preset=minimal --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Preset applied')
        ->assertSuccessful();
});

it('fails fast when active driver binaries are missing', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.binary', 'missing-pgbackrest-binary');

    checkpoint_artisan('db-ops:install --preset=postgres-prod --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Active driver preflight failed')
        ->expectsOutputToContain('command -v missing-pgbackrest-binary')
        ->expectsOutputToContain('DB_OPS_PGBACKREST_BINARY')
        ->assertFailed();
});

it('fails when an unknown install preset is requested', function (): void {
    checkpoint_artisan('db-ops:install --preset=unknown-preset --skip-publish --skip-migrate --skip-doctor')
        ->expectsOutputToContain('Unsupported preset')
        ->assertFailed();
});

it('writes preset values into the configured environment file', function (): void {
    $tempDirectory = sys_get_temp_dir().'/checkpoint-install-'.bin2hex(random_bytes(6));
    mkdir($tempDirectory, 0777, true);
    $envPath = $tempDirectory.'/.env';
    file_put_contents($envPath, "APP_NAME=Checkpoint\nDB_OPS_DRIVER=shell\n");

    $originalEnvironmentPath = app()->environmentPath();
    $originalEnvironmentFile = app()->environmentFile();

    app()->useEnvironmentPath($tempDirectory);
    app()->loadEnvironmentFrom('.env');

    try {
        config()->set('checkpoint.drivers.mysql.dump_binary', PHP_BINARY);
        config()->set('checkpoint.drivers.mysql.mysql_binary', PHP_BINARY);
        config()->set('checkpoint.drivers.mysql.mysqlbinlog_binary', PHP_BINARY);

        checkpoint_artisan('db-ops:install --preset=mysql-prod --skip-publish --skip-migrate --skip-doctor --write-env')
            ->assertSuccessful();

        $contents = (string) file_get_contents($envPath);

        expect($contents)->toContain('DB_OPS_DRIVER=mysql')
            ->toContain('DB_OPS_QUEUE_LOCK_STORE=redis')
            ->toContain('DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS=staging')
            ->toContain('DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP=true');
    } finally {
        app()->useEnvironmentPath($originalEnvironmentPath);
        app()->loadEnvironmentFrom($originalEnvironmentFile);

        @unlink($envPath);
        @rmdir($tempDirectory);
    }
});
