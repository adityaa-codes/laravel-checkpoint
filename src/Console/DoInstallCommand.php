<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoInstallCommand extends Command
{
    protected $signature = 'db-ops:do:install
        {--preset= : Installation preset (minimal, postgres-prod, mysql-prod).}
        {--skip-publish : Skip publishing package config and migrations.}
        {--skip-migrate : Skip running migrations.}
        {--skip-doctor : Skip db-ops:doctor health checks.}
        {--write-env : Persist selected preset values into the app environment file.}
        {--force : Force vendor publish overwrite.}';

    protected $description = 'Journey command: install and bootstrap Laravel Checkpoint.';

    public function handle(): int
    {
        $parameters = array_filter([
            '--preset' => $this->option('preset'),
            '--skip-publish' => (bool) $this->option('skip-publish') ? true : null,
            '--skip-migrate' => (bool) $this->option('skip-migrate') ? true : null,
            '--skip-doctor' => (bool) $this->option('skip-doctor') ? true : null,
            '--write-env' => (bool) $this->option('write-env') ? true : null,
            '--force' => (bool) $this->option('force') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('db-ops:install', $parameters);
    }
}
