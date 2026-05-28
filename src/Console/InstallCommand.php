<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class InstallCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:install
        {--skip-publish : Skip publishing package config and migrations.}
        {--skip-migrate : Skip migration prompt.}
        {--skip-doctor : Skip checkpoint:status --health health checks.}
        {--force : Force vendor publish overwrite.}';

    protected $description = 'Guided install for Laravel Checkpoint. Auto-detects your database and configures safety.';

    protected $aliases = ['checkpoint:do:install'];

    public function handle(): int
    {
        try {
            Prompt::interactive($this->enhancedInteractiveMode());

            $driver = $this->detectedDriver();
            $env = app()->environment();
            $isProduction = ! collect(['local', 'testing'])->containsStrict($env);

            if ($this->enhancedInteractiveMode()) {
                intro('Laravel Checkpoint Install Wizard');

                $this->promptTable(['Setting', 'Value'], [
                    ['Database', $this->detectedDatabaseLabel()],
                    ['Driver', $driver],
                    ['Environment', $env],
                ]);
            }

            if (! (bool) $this->option('skip-publish')) {
                $this->publishArtifacts((bool) $this->option('force'));
            }

            $this->writeEnvValues($driver);

            if (! (bool) $this->option('skip-migrate')) {
                $this->handleMigrations();
            }

            $doctor = ['ok' => null, 'failed' => 0, 'warn' => 0, 'warn_effective' => 0, 'blocker' => 0, 'warning' => 0, 'warning_effective' => 0];
            if (! (bool) $this->option('skip-doctor')) {
                $doctor = $this->runDoctor($driver);
            }

            $this->renderSummary($driver, $doctor);

            if ($isProduction) {
                $this->promptProductionSafety();
            }

            $this->detectQueueWorker();

            if ($this->enhancedInteractiveMode()) {
                outro('Done. Next: php artisan checkpoint:backup');
            }

            return $doctor['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            foreach (Str::of($exception->getMessage())->split('/\\r\\n|\\r|\\n/')->all() ?: [] as $line) {
                if (Str::trim($line) !== '') {
                    $this->promptError($line);
                }
            }

            return self::FAILURE;
        }
    }

    private function detectedDriver(): ?string
    {
        $default = config('database.default');
        $defaultConnection = is_string($default) ? $default : 'mysql';

        $dbDriver = config('database.connections.'.$defaultConnection.'.driver');

        $resolved = Str::lower(Str::trim(is_string($dbDriver) ? $dbDriver : 'mysql'));

        if (in_array($resolved, ['sqlite', 'sqlsrv'], true)) {
            $this->promptError(sprintf(
                'Checkpoint does not support the "%s" database driver. Use MySQL or PostgreSQL instead.',
                $resolved,
            ));

            throw new RuntimeException(sprintf('Unsupported database driver: %s', $resolved));
        }

        return match ($resolved) {
            'pgsql', 'postgres', 'postgresql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            default => 'mysql',
        };
    }

    private function detectedDatabaseLabel(): string
    {
        $driver = $this->detectedDriver();

        return match ($driver) {
            'postgres' => 'PostgreSQL',
            'mysql' => 'MySQL / MariaDB',
            default => 'Unknown',
        };
    }

    private function publishArtifacts(bool $force): void
    {
        $configCode = Artisan::call('vendor:publish', $this->publishParameters('checkpoint-config', $force));

        if ($configCode !== self::SUCCESS) {
            throw new RuntimeException(Str::trim(Artisan::output()) ?: 'Failed publishing checkpoint-config.');
        }

        $migrationCode = Artisan::call('vendor:publish', $this->publishParameters('checkpoint-migrations', $force));

        if ($migrationCode !== self::SUCCESS) {
            throw new RuntimeException(Str::trim(Artisan::output()) ?: 'Failed publishing checkpoint-migrations.');
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

    private function writeEnvValues(string $driver): void
    {
        $proposed = [
            'CP_DRIVER' => $driver,
            'CP_BACKUP_ARCHIVE_PASSWORD' => '',
            'CP_RESTORE_ALLOWED_ENVIRONMENTS' => 'local,testing,staging',
        ];

        if ($this->enhancedInteractiveMode()) {
            $this->promptTable(['Proposed .env Values'], array_map(
                static fn (string $key, string $value): array => [$key.'='.$value],
                array_keys($proposed),
                array_values($proposed),
            ));

            if (! confirm('Write these values to .env?', default: false)) {
                return;
            }

            $this->appendToEnv($proposed);
        } else {
            foreach ($proposed as $key => $value) {
                note(sprintf('Add %s=%s to your .env.', $key, $value));
            }
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function appendToEnv(array $values): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            note('.env file not found. Skipping.');

            return;
        }

        $contents = File::get($envPath);

        foreach ($values as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents)) {
                continue;
            }

            File::append($envPath, PHP_EOL.$key.'='.$value);
        }

        note('.env updated with new keys.');
    }

    private function handleMigrations(): void
    {
        if ($this->enhancedInteractiveMode()) {
            if (! confirm('Run migration now?', default: false)) {
                return;
            }
        }

        $this->runCheckpointMigration();
    }

    private function runCheckpointMigration(): void
    {
        $migrationDir = database_path('migrations');
        $chkptFiles = File::glob($migrationDir.'/*_create_db_ops_*_table.php');

        if ($chkptFiles === []) {
            note('No checkpoint migration files found. Skipping.');

            return;
        }

        $tempDir = $migrationDir.'/checkpoint_run';
        File::makeDirectory($tempDir, 0755, true);

        try {
            foreach ($chkptFiles as $file) {
                $filename = basename((string) $file);
                File::copy((string) $file, $tempDir.'/'.$filename);
            }

            Artisan::call('migrate', [
                '--path' => 'database/migrations/checkpoint_run',
                '--force' => true,
            ]);

            $this->promptInfo(Str::trim(Artisan::output()));
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * @return array{ok:bool|null,failed:int,warn:int,warn_effective:int,blocker:int,warning:int,warning_effective:int}
     */
    private function runDoctor(string $driver): array
    {
        $code = Artisan::call('checkpoint:status', ['--health' => true, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true);

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

        return [
            'ok' => collect($checks)->filter(static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'fail')->count() === 0,
            'failed' => collect($checks)->filter(static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'fail')->count(),
            'warn' => collect($checks)->filter(static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'warn')->count(),
            'warn_effective' => collect($checks)->filter(fn (mixed $c): bool => is_array($c)
                && ($c['status'] ?? null) === 'warn'
                && ! $this->isAdvisoryWarningForReadiness($c))->count(),
            'blocker' => collect($checks)->filter(static fn (mixed $c): bool => is_array($c) && (($c['severity'] ?? null) === 'blocker'))->count(),
            'warning' => collect($checks)->filter(static fn (mixed $c): bool => is_array($c) && (($c['severity'] ?? null) === 'warning'))->count(),
            'warning_effective' => collect($checks)->filter(fn (mixed $c): bool => is_array($c)
                && (($c['severity'] ?? null) === 'warning')
                && ! $this->isAdvisoryWarningForReadiness($c))->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function isAdvisoryWarningForReadiness(array $check): bool
    {
        if (! collect(['local', 'testing'])->containsStrict(app()->environment())) {
            return false;
        }

        return collect([
            'backup_drill.latest_run',
            'backup_drill.pass_rate',
            'backup_drill.trend',
            'backup_drill.playbook',
            'verification.runs',
        ])->containsStrict($check['code'] ?? '');
    }

    /**
     * @param  array{ok:bool|null,failed:int,warn:int,warn_effective:int,blocker:int,warning:int,warning_effective:int}  $doctor
     */
    private function renderSummary(string $driver, array $doctor): void
    {
        $doctorResult = $doctor['ok'] === null
            ? 'skipped'
            : ($doctor['failed'] > 0
                ? sprintf('failed (%d fail, %d warn)', $doctor['failed'], $doctor['warn_effective'])
                : ($doctor['warn_effective'] > 0
                    ? sprintf('warn (%d fail, %d warn)', $doctor['failed'], $doctor['warn_effective'])
                    : 'passed'));

        $this->promptTable(['Step', 'Result'], [
            ['Driver', $driver],
            ['Migrations', 'done'],
            ['Doctor', $doctorResult],
        ]);
    }

    private function promptProductionSafety(): void
    {
        note('Production environment detected. Add these to your .env:');
        note('  CP_RESTORE_ALLOWED_ENVIRONMENTS=staging');
    }

    private function detectQueueWorker(): void
    {
        $queueName = (string) config('checkpoint.queue.name', 'checkpoint');
        $output = null;
        $returnCode = null;

        exec('pgrep -f "artisan queue:work" 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0) {
            $this->promptInfo(sprintf('Queue worker detected ✓ (queue: %s)', $queueName));
        } else {
            note(sprintf('Start queue worker: php artisan queue:work --queue=%s', $queueName));
        }
    }
}
