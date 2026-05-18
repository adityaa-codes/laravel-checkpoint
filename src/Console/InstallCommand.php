<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Prompt;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class InstallCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:install
        {--skip-publish : Skip publishing package config and migrations.}
        {--skip-migrate : Skip running migrations.}
        {--skip-doctor : Skip checkpoint:doctor health checks.}
        {--force : Force vendor publish overwrite.}';

    protected $description = 'Guided install for Laravel Checkpoint. Auto-detects your database and configures safety.';

    protected $aliases = ['checkpoint:do:install'];

    public function handle(): int
    {
        try {
            Prompt::interactive($this->enhancedInteractiveMode());

            $driver = $this->detectedDriver();
            $env = app()->environment();
            $isProduction = ! in_array($env, ['local', 'testing'], true);

            if ($this->enhancedInteractiveMode()) {
                intro('Laravel Checkpoint Install Wizard');

                $this->promptTable(['Setting', 'Value'], [
                    ['Database', $this->detectedDatabaseLabel()],
                    ['Driver', $driver],
                    ['Environment', $env],
                ]);
            }

            $this->applyRuntimeConfig([
                'checkpoint.driver' => $driver,
            ]);

            if (! (bool) $this->option('skip-publish')) {
                $this->publishArtifacts((bool) $this->option('force'));
            }

            if (! (bool) $this->option('skip-migrate')) {
                $this->runMigrations();
            }

            $doctor = ['ok' => null, 'failed' => 0, 'warn' => 0, 'warn_effective' => 0, 'blocker' => 0, 'warning' => 0, 'warning_effective' => 0];
            if (! (bool) $this->option('skip-doctor')) {
                $doctor = $this->runDoctor();
            }

            $this->renderSummary($driver, $doctor);

            if ($isProduction) {
                $this->promptProductionSafety();
            }

            if ($this->enhancedInteractiveMode()) {
                outro('Laravel Checkpoint installation completed.');
                note('Next: php artisan checkpoint:backup');
            }

            return $doctor['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            foreach (preg_split('/\r\n|\r|\n/', $exception->getMessage()) ?: [] as $line) {
                if (trim((string) $line) !== '') {
                    $this->promptError((string) $line);
                }
            }

            return self::FAILURE;
        }
    }

    private function detectedDriver(): string
    {
        $defaultConnection = (string) config('database.default', 'mysql');

        return match (strtolower(trim((string) config('database.connections.'.$defaultConnection.'.driver', 'mysql')))) {
            'pgsql', 'postgres', 'postgresql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            'sqlite' => 'shell',
            default => 'shell',
        };
    }

    private function detectedDatabaseLabel(): string
    {
        $driver = $this->detectedDriver();

        return match ($driver) {
            'postgres' => 'PostgreSQL',
            'mysql' => 'MySQL / MariaDB',
            'shell' => 'SQLite / Other',
            default => $driver,
        };
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

        return [
            'ok' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'fail')) === 0,
            'failed' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'fail')),
            'warn' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c) && ($c['status'] ?? null) === 'warn')),
            'warn_effective' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c)
                && ($c['status'] ?? null) === 'warn'
                && ! $this->isAdvisoryWarningForReadiness($c))),
            'blocker' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c) && (($c['severity'] ?? null) === 'blocker'))),
            'warning' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c) && (($c['severity'] ?? null) === 'warning'))),
            'warning_effective' => count(array_filter($checks, static fn (mixed $c): bool => is_array($c)
                && (($c['severity'] ?? null) === 'warning')
                && ! $this->isAdvisoryWarningForReadiness($c))),
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function isAdvisoryWarningForReadiness(array $check): bool
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            return false;
        }

        return in_array((string) ($check['code'] ?? ''), [
            'backup_drill.latest_run',
            'backup_drill.pass_rate',
            'backup_drill.trend',
            'backup_drill.playbook',
            'verification.runs',
        ], true);
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
        if ($this->enhancedInteractiveMode() && ! confirm('Production detected. Restrict restores to staging only? (recommended)', default: true)) {
            note('Restores will be allowed in this environment. Manage safety in config/checkpoint.php.');

            return;
        }

        $envPath = app()->environmentFilePath();

        if (! file_exists($envPath)) {
            note(sprintf('Could not find environment file at [%s]. Add CP_RESTORE_ALLOWED_ENVIRONMENTS=staging manually.', $envPath));

            return;
        }

        $contents = (string) file_get_contents($envPath);
        $line = 'CP_RESTORE_ALLOWED_ENVIRONMENTS=staging';

        if (str_contains($contents, 'CP_RESTORE_ALLOWED_ENVIRONMENTS=')) {
            $contents = (string) preg_replace('/^CP_RESTORE_ALLOWED_ENVIRONMENTS=.*/m', $line, $contents);
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        file_put_contents($envPath, $contents);

        note('Added CP_RESTORE_ALLOWED_ENVIRONMENTS=staging to your .env file.');
    }
}
