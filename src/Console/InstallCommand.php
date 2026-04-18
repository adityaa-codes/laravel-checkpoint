<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

final class InstallCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'db-ops:install
        {--preset= : Installation preset (minimal, postgres-prod, mysql-prod).}
        {--skip-publish : Skip publishing package config and migrations.}
        {--skip-migrate : Skip running migrations.}
        {--skip-doctor : Skip db-ops:doctor health checks.}
        {--write-env : Persist selected preset values into the app environment file.}
        {--force : Force vendor publish overwrite.}';

    protected $description = 'Guided install for Laravel Checkpoint with safe presets.';

    protected $aliases = ['db-ops:do:install'];

    /**
     * @var array<string, array{
     *   description:string,
     *   env:array<string,string>,
     *   config:array<string,mixed>
     * }>
     */
    private const array PRESETS = [
        'minimal' => [
            'description' => 'Local/testing shell baseline with relaxed restore verification.',
            'env' => [
                'DB_OPS_DRIVER' => 'shell',
                'DB_OPS_QUEUE_NAME' => 'db-ops',
                'DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS' => 'local,testing',
                'DB_OPS_RESTORE_REQUIRE_CONFIRMATION' => 'false',
                'DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP' => 'false',
                'DB_OPS_RESTORE_ALLOW_IN_CI' => 'true',
            ],
            'config' => [
                'checkpoint.driver' => 'shell',
                'checkpoint.queue.name' => 'db-ops',
                'checkpoint.restore.allowed_environments' => ['local', 'testing'],
                'checkpoint.restore.require_confirmation' => false,
                'checkpoint.restore.require_verified_backup' => false,
                'checkpoint.restore.allow_in_ci' => true,
            ],
        ],
        'postgres-prod' => [
            'description' => 'Production-oriented PostgreSQL preset using pgBackRest.',
            'env' => [
                'DB_OPS_DRIVER' => 'postgres',
                'DB_OPS_QUEUE_NAME' => 'db-ops',
                'DB_OPS_QUEUE_LOCK_STORE' => 'redis',
                'DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS' => 'staging',
                'DB_OPS_RESTORE_REQUIRE_CONFIRMATION' => 'true',
                'DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP' => 'true',
                'DB_OPS_RESTORE_ALLOW_IN_CI' => 'false',
            ],
            'config' => [
                'checkpoint.driver' => 'postgres',
                'checkpoint.queue.name' => 'db-ops',
                'checkpoint.queue.lock_store' => 'redis',
                'checkpoint.restore.allowed_environments' => ['staging'],
                'checkpoint.restore.require_confirmation' => true,
                'checkpoint.restore.require_verified_backup' => true,
                'checkpoint.restore.allow_in_ci' => false,
            ],
        ],
        'mysql-prod' => [
            'description' => 'Production-oriented MySQL preset using logical export and replay.',
            'env' => [
                'DB_OPS_DRIVER' => 'mysql',
                'DB_OPS_QUEUE_NAME' => 'db-ops',
                'DB_OPS_QUEUE_LOCK_STORE' => 'redis',
                'DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS' => 'staging',
                'DB_OPS_RESTORE_REQUIRE_CONFIRMATION' => 'true',
                'DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP' => 'true',
                'DB_OPS_RESTORE_ALLOW_IN_CI' => 'false',
            ],
            'config' => [
                'checkpoint.driver' => 'mysql',
                'checkpoint.queue.name' => 'db-ops',
                'checkpoint.queue.lock_store' => 'redis',
                'checkpoint.restore.allowed_environments' => ['staging'],
                'checkpoint.restore.require_confirmation' => true,
                'checkpoint.restore.require_verified_backup' => true,
                'checkpoint.restore.allow_in_ci' => false,
            ],
        ],
    ];

    public function handle(): int
    {
        try {
            if ($this->enhancedInteractiveMode()) {
                intro('Laravel Checkpoint Install Wizard');
                note('What: bootstrap config, migrations, baseline safety defaults, and health checks.');
                note('When: first-time setup or after major config resets.');
                note('Next: run db-ops:do:backup, then db-ops:do:status and db-ops:check:doctor.');
            }

            $recommendation = $this->presetRecommendation();
            $preset = $this->resolvedPreset($recommendation['preset'], $recommendation['database_driver']);
            $definition = self::PRESETS[$preset];
            $this->applyRuntimeConfig($definition['config']);
            $this->assertActiveDriverPreflight();

            if (! (bool) $this->option('skip-publish')) {
                $this->publishArtifacts((bool) $this->option('force'));
            }

            $envWritten = false;
            if ($this->shouldWriteEnv()) {
                $this->writePresetToEnv($definition['env']);
                $envWritten = true;
            }

            if (! (bool) $this->option('skip-migrate')) {
                $this->runMigrations();
            }

            $doctor = ['ok' => null, 'failed' => 0, 'warn' => 0];
            if (! (bool) $this->option('skip-doctor')) {
                $doctor = $this->runDoctor();
            }

            $this->renderSummary($preset, $envWritten, $doctor);

            if (
                ! is_string($this->option('preset'))
                && $recommendation['preset'] !== 'minimal'
                && $preset === 'minimal'
            ) {
                note(sprintf(
                    'Detected default database driver [%s]. Consider rerunning with --preset=%s for production-safe defaults.',
                    $recommendation['database_driver'],
                    $recommendation['preset'],
                ));
            }

            return $doctor['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            foreach (preg_split('/\r\n|\r|\n/', $exception->getMessage()) ?: [] as $line) {
                if (trim((string) $line) !== '') {
                    $this->promptError((string) $line);
                }
            }

            return self::FAILURE;
        }
    }

    private function resolvedPreset(string $recommendedPreset, string $databaseDriver): string
    {
        $selected = $this->option('preset');

        if (is_string($selected) && $selected !== '') {
            if (! array_key_exists($selected, self::PRESETS)) {
                throw new RuntimeException(sprintf(
                    'Unsupported preset [%s]. Allowed: %s.',
                    $selected,
                    implode(', ', array_keys(self::PRESETS)),
                ));
            }

            return $selected;
        }

        if (! $this->enhancedInteractiveMode()) {
            return 'minimal';
        }

        if ($recommendedPreset !== 'minimal') {
            note(sprintf(
                'Detected default database driver [%s]. Recommended preset: %s.',
                $databaseDriver,
                $recommendedPreset,
            ));
        }

        $presetKeys = array_keys(self::PRESETS);
        $choices = array_map(
            static fn (string $name): string => sprintf('%s - %s', $name, self::PRESETS[$name]['description']),
            $presetKeys,
        );
        $defaultChoice = array_search($recommendedPreset, $presetKeys, true);

        /** @var string $picked */
        $picked = select(
            label: 'Select an installation preset',
            options: $choices,
            default: $choices[is_int($defaultChoice) ? $defaultChoice : 0],
        );

        return trim((string) str($picked)->before(' - '));
    }

    /**
     * @return array{preset:string,database_driver:string}
     */
    private function presetRecommendation(): array
    {
        $defaultConnection = (string) config('database.default', '');
        $databaseDriver = strtolower(trim((string) config('database.connections.'.$defaultConnection.'.driver', $defaultConnection)));

        $preset = match ($databaseDriver) {
            'pgsql', 'postgres', 'postgresql' => 'postgres-prod',
            'mysql', 'mariadb' => 'mysql-prod',
            default => 'minimal',
        };

        return [
            'preset' => $preset,
            'database_driver' => $databaseDriver !== '' ? $databaseDriver : 'unknown',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function applyRuntimeConfig(array $overrides): void
    {
        foreach ($overrides as $path => $value) {
            config()->set($path, $value);
        }
    }

    private function shouldWriteEnv(): bool
    {
        if ((bool) $this->option('write-env')) {
            return true;
        }

        if (! $this->enhancedInteractiveMode()) {
            return false;
        }

        return confirm(label: 'Write preset values into your environment file?', default: true);
    }

    private function publishArtifacts(bool $force): void
    {
        $configCode = Artisan::call('vendor:publish', $this->publishParameters('laravel-checkpoint-config', $force));

        if ($configCode !== self::SUCCESS) {
            throw new RuntimeException(trim((string) Artisan::output()) ?: 'Failed publishing laravel-checkpoint-config.');
        }

        $migrationCode = Artisan::call('vendor:publish', $this->publishParameters('laravel-checkpoint-migrations', $force));

        if ($migrationCode !== self::SUCCESS) {
            throw new RuntimeException(trim((string) Artisan::output()) ?: 'Failed publishing laravel-checkpoint-migrations.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function publishParameters(string $tag, bool $force): array
    {
        $parameters = ['--tag' => $tag];

        if ($force) {
            $parameters['--force'] = true;
        }

        return $parameters;
    }

    private function runMigrations(): void
    {
        $code = Artisan::call('migrate', ['--force' => true]);

        if ($code !== self::SUCCESS) {
            throw new RuntimeException(trim((string) Artisan::output()) ?: 'Migration command failed.');
        }
    }

    private function assertActiveDriverPreflight(): void
    {
        $driver = (string) config('checkpoint.driver', 'shell');
        $requirements = match ($driver) {
            'postgres' => [
                [
                    'binary' => (string) config('checkpoint.drivers.pgbackrest.binary', 'pgbackrest'),
                    'env_key' => 'DB_OPS_PGBACKREST_BINARY',
                    'config_path' => 'checkpoint.drivers.pgbackrest.binary',
                ],
                [
                    'binary' => (string) config('checkpoint.drivers.pgdump.dump_binary', 'pg_dump'),
                    'env_key' => 'DB_OPS_PGDUMP_BINARY',
                    'config_path' => 'checkpoint.drivers.pgdump.dump_binary',
                ],
                [
                    'binary' => (string) config('checkpoint.drivers.pgdump.restore_binary', 'pg_restore'),
                    'env_key' => 'DB_OPS_PGRESTORE_BINARY',
                    'config_path' => 'checkpoint.drivers.pgdump.restore_binary',
                ],
            ],
            'pgbackrest' => [
                [
                    'binary' => (string) config('checkpoint.drivers.pgbackrest.binary', 'pgbackrest'),
                    'env_key' => 'DB_OPS_PGBACKREST_BINARY',
                    'config_path' => 'checkpoint.drivers.pgbackrest.binary',
                ],
            ],
            'pgdump' => [
                [
                    'binary' => (string) config('checkpoint.drivers.pgdump.dump_binary', 'pg_dump'),
                    'env_key' => 'DB_OPS_PGDUMP_BINARY',
                    'config_path' => 'checkpoint.drivers.pgdump.dump_binary',
                ],
                [
                    'binary' => (string) config('checkpoint.drivers.pgdump.restore_binary', 'pg_restore'),
                    'env_key' => 'DB_OPS_PGRESTORE_BINARY',
                    'config_path' => 'checkpoint.drivers.pgdump.restore_binary',
                ],
            ],
            'mysql' => [
                [
                    'binary' => (string) config('checkpoint.drivers.mysql.dump_binary', 'mysqldump'),
                    'env_key' => 'DB_OPS_MYSQL_DUMP_BINARY',
                    'config_path' => 'checkpoint.drivers.mysql.dump_binary',
                ],
                [
                    'binary' => (string) config('checkpoint.drivers.mysql.mysql_binary', 'mysql'),
                    'env_key' => 'DB_OPS_MYSQL_BINARY',
                    'config_path' => 'checkpoint.drivers.mysql.mysql_binary',
                ],
                [
                    'binary' => (string) config('checkpoint.drivers.mysql.mysqlbinlog_binary', 'mysqlbinlog'),
                    'env_key' => 'DB_OPS_MYSQL_BINLOG_BINARY',
                    'config_path' => 'checkpoint.drivers.mysql.mysqlbinlog_binary',
                ],
            ],
            default => [],
        };

        if ($requirements === []) {
            return;
        }

        $missing = [];
        $finder = new ExecutableFinder;

        foreach ($requirements as $requirement) {
            $binary = trim((string) $requirement['binary']);

            if ($binary === '') {
                $missing[] = [
                    ...$requirement,
                    'reason' => 'empty',
                ];

                continue;
            }

            $resolved = is_executable($binary) ? $binary : $finder->find($binary);

            if ($resolved === null) {
                $missing[] = [
                    ...$requirement,
                    'reason' => 'not_found',
                ];
            }
        }

        if ($missing === []) {
            return;
        }

        $lines = [sprintf('Active driver preflight failed for [%s].', $driver)];

        foreach ($missing as $entry) {
            $binary = (string) $entry['binary'];
            $envKey = (string) $entry['env_key'];
            $configPath = (string) $entry['config_path'];
            $lines[] = sprintf('- %s (%s)', $binary !== '' ? $binary : '<empty>', (string) $entry['reason']);
            $lines[] = sprintf('  command -v %s', $binary !== '' ? $binary : '<binary>');
            $lines[] = sprintf('  export %s=/absolute/path/to/%s', $envKey, $binary !== '' ? basename($binary) : '<binary>');
            $lines[] = sprintf('  # maps to %s', $configPath);
        }

        throw new RuntimeException(implode("\n", $lines));
    }

    /**
     * @return array{ok:bool|null,failed:int,warn:int}
     */
    private function runDoctor(): array
    {
        $code = Artisan::call('db-ops:doctor', ['--format' => 'json']);
        $report = json_decode((string) Artisan::output(), true);

        if (! is_array($report)) {
            return [
                'ok' => $code === self::SUCCESS,
                'failed' => $code === self::SUCCESS ? 0 : 1,
                'warn' => 0,
            ];
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $failed = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'fail'));
        $warn = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'warn'));

        return [
            'ok' => $failed === 0,
            'failed' => $failed,
            'warn' => $warn,
        ];
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function writePresetToEnv(array $entries): void
    {
        $path = app()->environmentFilePath();

        if (! file_exists($path)) {
            throw new RuntimeException(sprintf('Environment file [%s] does not exist.', $path));
        }

        $contents = (string) file_get_contents($path);

        foreach ($entries as $key => $value) {
            $contents = $this->upsertEnvLine($contents, $key, $this->formattedEnvValue($value));
        }

        file_put_contents($path, $contents);
    }

    private function upsertEnvLine(string $contents, string $key, string $value): string
    {
        $line = sprintf('%s=%s', $key, $value);
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $line, $contents, 1);
        }

        $suffix = str_ends_with($contents, "\n") ? '' : "\n";

        return $contents.$suffix.$line."\n";
    }

    private function formattedEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s/', $value) === 1) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }

    /**
     * @param  array{ok:bool|null,failed:int,warn:int}  $doctor
     */
    private function renderSummary(string $preset, bool $envWritten, array $doctor): void
    {
        $queueName = (string) config('checkpoint.queue.name', 'db-ops');
        $timeout = (int) config('checkpoint.queue.timeout', 3600);
        $doctorResult = $doctor['ok'] === null
            ? 'skipped'
            : ($doctor['failed'] > 0
                ? sprintf('failed (%d fail, %d warn)', $doctor['failed'], $doctor['warn'])
                : ($doctor['warn'] > 0
                    ? sprintf('warn (%d fail, %d warn)', $doctor['failed'], $doctor['warn'])
                    : 'passed'));

        $this->promptTable(['Step', 'Result'], [
            ['Preset applied', $preset],
            ['Driver', (string) config('checkpoint.driver', 'shell')],
            ['Environment file', $envWritten ? 'updated' : 'unchanged'],
            ['Doctor', $doctorResult],
        ]);

        note(sprintf('Queue worker: php artisan queue:work --queue=%s --timeout=%d', $queueName, $timeout));
        note('Scheduler: php artisan schedule:work (or ensure scheduler cron is active).');

        if ($this->enhancedInteractiveMode()) {
            outro('Laravel Checkpoint installation completed.');
        }
    }

}
