<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
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

    private const string MINIMAL_LOGICAL_BACKUP_PLACEHOLDER = 'php -r if(!is_dir($argv[1]))mkdir($argv[1],0777,true);touch($argv[2]); {backup_dir} {output}';

    protected $signature = 'checkpoint:install
        {--preset= : Installation preset (minimal, postgres-prod, mysql-prod).}
        {--skip-publish : Skip publishing package config and migrations.}
        {--skip-migrate : Skip running migrations.}
        {--skip-doctor : Skip checkpoint:doctor health checks.}
        {--smoke-backup : Queue and process one logical backup smoke run after install.}
        {--write-env : Persist selected preset values into the app environment file.}
        {--force : Force vendor publish overwrite.}';

    protected $description = 'Guided install for Laravel Checkpoint with safe presets.';

    protected $aliases = ['checkpoint:do:install'];

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
                'DB_OPS_CMD_LOGICAL_BACKUP' => self::MINIMAL_LOGICAL_BACKUP_PLACEHOLDER,
                'DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS' => 'local,testing',
                'DB_OPS_RESTORE_REQUIRE_CONFIRMATION' => 'false',
                'DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP' => 'false',
                'DB_OPS_RESTORE_ALLOW_IN_CI' => 'true',
            ],
            'config' => [
                'checkpoint.driver' => 'shell',
                'checkpoint.queue.name' => 'db-ops',
                'checkpoint.drivers.shell.commands.logical_backup' => self::MINIMAL_LOGICAL_BACKUP_PLACEHOLDER,
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
                note('Next: run checkpoint:do:backup, then checkpoint:do:status and checkpoint:check:doctor.');
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

            $doctor = ['ok' => null, 'failed' => 0, 'warn' => 0, 'warn_effective' => 0, 'blocker' => 0, 'warning' => 0, 'warning_effective' => 0];
            if (! (bool) $this->option('skip-doctor')) {
                $doctor = $this->runDoctor();
            }

            $smoke = ['executed' => false, 'ok' => null, 'label' => 'not requested', 'should_fail' => false];
            if ((bool) $this->option('smoke-backup')) {
                $smoke = $this->runSmokeBackup((bool) $this->option('skip-migrate'));
            }

            $readiness = $this->readinessAssessment($preset, $doctor, $smoke);
            $this->renderSummary($preset, $envWritten, $doctor, $readiness, $smoke);

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

            return $readiness['should_fail'] ? self::FAILURE : self::SUCCESS;
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
        $configCode = Artisan::call('vendor:publish', $this->publishParameters('checkpoint-config', $force));

        if ($configCode !== self::SUCCESS) {
            throw new RuntimeException(trim((string) Artisan::output()) ?: 'Failed publishing checkpoint-config.');
        }

        $migrationCode = Artisan::call('vendor:publish', $this->publishParameters('checkpoint-migrations', $force));

        if ($migrationCode !== self::SUCCESS) {
            throw new RuntimeException(trim((string) Artisan::output()) ?: 'Failed publishing checkpoint-migrations.');
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
     * @return array{ok:bool|null,failed:int,warn:int,warn_effective:int,blocker:int,warning:int,warning_effective:int}
     */
    private function runDoctor(): array
    {
        $code = Artisan::call('checkpoint:doctor', ['--format' => 'json']);
        $report = json_decode((string) Artisan::output(), true);

        if (! is_array($report)) {
            return [
                'ok' => $code === self::SUCCESS,
                'failed' => $code === self::SUCCESS ? 0 : 1,
                'warn' => 0,
                'warn_effective' => 0,
                'blocker' => $code === self::SUCCESS ? 0 : 1,
                'warning' => 0,
                'warning_effective' => 0,
            ];
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $failed = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'fail'));
        $warn = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'warn'));
        $warnEffective = count(array_filter($checks, fn (mixed $check): bool => is_array($check)
            && ($check['status'] ?? null) === 'warn'
            && ! $this->isAdvisoryWarningForReadiness($check)));
        $blocker = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && (($check['severity'] ?? null) === 'blocker')));
        $warning = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && (($check['severity'] ?? null) === 'warning')));
        $warningEffective = count(array_filter($checks, fn (mixed $check): bool => is_array($check)
            && (($check['severity'] ?? null) === 'warning')
            && ! $this->isAdvisoryWarningForReadiness($check)));

        return [
            'ok' => $failed === 0,
            'failed' => $failed,
            'warn' => $warn,
            'warn_effective' => $warnEffective,
            'blocker' => $blocker,
            'warning' => $warning,
            'warning_effective' => $warningEffective,
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function isAdvisoryWarningForReadiness(array $check): bool
    {
        $environment = app()->environment();

        if (! in_array($environment, ['local', 'testing'], true)) {
            return false;
        }

        $code = (string) ($check['code'] ?? '');

        return in_array($code, [
            'queue.worker_visibility',
            'restore.post_verification',
            'backup.last_known_good',
            'backup.duration_anomaly',
            'backup_drill.latest_run',
            'backup_drill.pass_rate',
            'backup_drill.trend',
            'backup_drill.playbook',
            'verification.runs',
        ], true);
    }

    /**
     * @param  array{ok:bool|null,failed:int,warn:int,warn_effective:int,blocker:int,warning:int,warning_effective:int}  $doctor
     * @param  array{executed:bool,ok:bool|null,label:string,should_fail:bool}  $smoke
     * @return array{label:string,should_fail:bool}
     */
    private function readinessAssessment(string $preset, array $doctor, array $smoke): array
    {
        if ($smoke['should_fail']) {
            return [
                'label' => 'not-ready (smoke backup failed)',
                'should_fail' => true,
            ];
        }

        if ($doctor['ok'] === null) {
            return [
                'label' => 'unknown (doctor skipped)',
                'should_fail' => false,
            ];
        }

        if ($preset === 'minimal') {
            return [
                'label' => 'dev-only',
                'should_fail' => $doctor['failed'] > 0,
            ];
        }

        if ($doctor['blocker'] > 0) {
            return [
                'label' => sprintf('not-ready (%d blocker, %d warning)', $doctor['blocker'], $doctor['warning']),
                'should_fail' => true,
            ];
        }

        if ($doctor['warning_effective'] > 0) {
            return [
                'label' => sprintf('staging-ready (%d warning)', $doctor['warning_effective']),
                'should_fail' => false,
            ];
        }

        return [
            'label' => 'prod-ready',
            'should_fail' => false,
        ];
    }

    /**
     * @return array{executed:bool,ok:bool|null,label:string,should_fail:bool}
     */
    private function runSmokeBackup(bool $skipMigrate): array
    {
        if ($skipMigrate) {
            return [
                'executed' => true,
                'ok' => false,
                'label' => 'failed (requires migrated command run tables; remove --skip-migrate)',
                'should_fail' => true,
            ];
        }

        $enqueueCode = Artisan::call('checkpoint:enqueue-backup');

        if ($enqueueCode !== self::SUCCESS) {
            $output = trim((string) Artisan::output());

            return [
                'executed' => true,
                'ok' => false,
                'label' => 'failed (could not enqueue backup'.($output !== '' ? ': '.mb_substr($output, 0, 140) : '').')',
                'should_fail' => true,
            ];
        }

        $queueName = (string) config('checkpoint.queue.name', 'db-ops');
        $timeout = max(1, (int) config('checkpoint.queue.timeout', 3600));

        Artisan::call('queue:work', [
            '--queue' => $queueName,
            '--once' => true,
            '--timeout' => $timeout,
            '--tries' => 1,
        ]);

        $latestRun = CommandRun::query()->latest('id')->first();

        if (! $latestRun instanceof CommandRun) {
            return [
                'executed' => true,
                'ok' => false,
                'label' => 'failed (no smoke run was recorded)',
                'should_fail' => true,
            ];
        }

        if ((string) $latestRun->status->value === 'succeeded') {
            return [
                'executed' => true,
                'ok' => true,
                'label' => sprintf('passed (run #%d succeeded)', (int) $latestRun->getKey()),
                'should_fail' => false,
            ];
        }

        $reason = trim((string) (strtok((string) ($latestRun->command_output ?? ''), "\n") ?: ''));

        if ($reason === '' && $latestRun->exit_code !== null) {
            $reason = sprintf('exit code %d', $latestRun->exit_code);
        }

        if ($reason === '') {
            $reason = 'unknown failure';
        }

        return [
            'executed' => true,
            'ok' => false,
            'label' => sprintf('failed (run #%d: %s)', (int) $latestRun->getKey(), mb_substr($reason, 0, 140)),
            'should_fail' => true,
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
     * @param  array{ok:bool|null,failed:int,warn:int,warn_effective:int,blocker:int,warning:int,warning_effective:int}  $doctor
     * @param  array{label:string,should_fail:bool}  $readiness
     * @param  array{executed:bool,ok:bool|null,label:string,should_fail:bool}  $smoke
     */
    private function renderSummary(string $preset, bool $envWritten, array $doctor, array $readiness, array $smoke): void
    {
        $queueName = (string) config('checkpoint.queue.name', 'db-ops');
        $timeout = (int) config('checkpoint.queue.timeout', 3600);
        $doctorResult = $doctor['ok'] === null
            ? 'skipped'
            : ($doctor['failed'] > 0
                ? sprintf('failed (%d fail, %d warn)', $doctor['failed'], $doctor['warn_effective'])
                : ($doctor['warn_effective'] > 0
                    ? sprintf('warn (%d fail, %d warn)', $doctor['failed'], $doctor['warn_effective'])
                    : 'passed'));

        $this->promptTable(['Step', 'Result'], [
            ['Preset applied', $preset],
            ['Driver', (string) config('checkpoint.driver', 'shell')],
            ['Environment file', $envWritten ? 'updated' : 'unchanged'],
            ['Doctor', $doctorResult],
            ['Smoke backup', $smoke['label']],
            ['Readiness', $readiness['label']],
        ]);

        note(sprintf('Queue worker: php artisan queue:work --queue=%s --timeout=%d', $queueName, $timeout));
        note('Scheduler: php artisan schedule:work (or ensure scheduler cron is active).');

        if ($this->enhancedInteractiveMode()) {
            outro('Laravel Checkpoint installation completed.');
        }
    }
}
