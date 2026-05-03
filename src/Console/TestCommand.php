<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class TestCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:test
        {--preset=minimal : Installation preset (minimal, postgres-prod, mysql-prod).}
        {--timeout=3600 : Queue work timeout in seconds.}
        {--force : Force vendor publish overwrite during install.}';

    protected $description = 'Run a full smoke pipeline (install, doctor, backup) for CI validation.';

    public function handle(): int
    {
        try {
            $preset = (string) ($this->option('preset') ?: 'minimal');
            $timeout = max(1, (int) ($this->option('timeout') ?: 3600));
            $force = (bool) $this->option('force');

            if ($this->enhancedInteractiveMode()) {
                intro('Checkpoint Smoke Test');
                note('What: validate install, health checks, and backup execution.');
                note('When: CI pipelines, deployment validation, troubleshooting.');
            }

            $install = $this->runInstall($preset, $force);
            $doctor = $this->runDoctor();
            $smoke = $this->runBackupSmoke($timeout);

            $pipeline = [
                ['step' => 'Install', 'result' => $install['ok'] ? 'passed' : 'failed'],
                ['step' => 'Doctor', 'result' => $doctor['ok'] ? 'passed' : sprintf('%d fail, %d warn', $doctor['failed'], $doctor['warn'])],
                ['step' => 'Backup smoke', 'result' => $smoke['label']],
            ];

            $this->renderPipelineTable($pipeline);

            $allOk = $install['ok'] && $doctor['ok'] && ! $smoke['should_fail'];

            if ($this->enhancedInteractiveMode()) {
                if ($allOk) {
                    info('All pipeline steps passed.');
                } else {
                    note('Some pipeline steps failed. Inspect the output above for details.');
                }

                outro('Checkpoint test completed.');
            }

            return $allOk ? self::SUCCESS : self::FAILURE;
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

    /**
     * @return array{ok:bool}
     */
    private function runInstall(string $preset, bool $force): array
    {
        $parameters = ['--preset' => $preset, '--skip-publish' => true, '--skip-doctor' => true, '--no-interaction' => true];

        if ($force) {
            $parameters['--force'] = true;
        }

        $code = Artisan::call('checkpoint:install', $parameters);

        if ($code !== self::SUCCESS) {
            $output = trim((string) Artisan::output());

            throw new RuntimeException(sprintf('Install step failed (exit %d).%s', $code, $output !== '' ? ' '.mb_substr($output, 0, 200) : ''));
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,failed:int,warn:int}
     */
    private function runDoctor(): array
    {
        $code = Artisan::call('checkpoint:doctor', ['--format' => 'json', '--no-interaction' => true]);
        $report = json_decode((string) Artisan::output(), true);

        if (! is_array($report)) {
            if ($code !== self::SUCCESS) {
                throw new RuntimeException(sprintf('Doctor step failed (exit %d).', $code));
            }

            return ['ok' => true, 'failed' => 0, 'warn' => 0];
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $failed = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'fail'));
        $warn = count(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'warn'));

        if ($failed > 0) {
            $failures = array_map(
                fn (array $check): string => sprintf('  %s: %s', $check['code'], $check['notes']),
                array_values(array_filter($checks, static fn (mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'fail')),
            );

            throw new RuntimeException(sprintf("Doctor step has %d failure(s):\n%s", $failed, implode("\n", $failures)));
        }

        return ['ok' => true, 'failed' => 0, 'warn' => $warn];
    }

    /**
     * @return array{executed:bool,ok:?bool,label:string,should_fail:bool}
     */
    private function runBackupSmoke(int $timeout): array
    {
        $enqueueCode = Artisan::call('checkpoint:enqueue-backup');

        if ($enqueueCode !== self::SUCCESS) {
            $output = trim((string) Artisan::output());

            return [
                'executed' => true,
                'ok' => false,
                'label' => 'failed (enqueue)'.($output !== '' ? ': '.mb_substr($output, 0, 140) : ''),
                'should_fail' => true,
            ];
        }

        $queueName = (string) config('checkpoint.queue.name', 'db-ops');

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
                'label' => 'failed (no run recorded)',
                'should_fail' => true,
            ];
        }

        if ((string) $latestRun->status->value === 'succeeded') {
            return [
                'executed' => true,
                'ok' => true,
                'label' => sprintf('passed (#%d)', (int) $latestRun->getKey()),
                'should_fail' => false,
            ];
        }

        $reason = trim((string) (strtok((string) ($latestRun->command_output ?? ''), "\n") ?: ''));
        $reason = $reason !== '' ? $reason : sprintf('exit code %d', $latestRun->exit_code ?? -1);

        return [
            'executed' => true,
            'ok' => false,
            'label' => sprintf('failed (#%d: %s)', (int) $latestRun->getKey(), mb_substr($reason, 0, 140)),
            'should_fail' => true,
        ];
    }

    /**
     * @param  list<array{step:string,result:string}>  $pipeline
     */
    private function renderPipelineTable(array $pipeline): void
    {
        $rows = [];
        foreach ($pipeline as $step) {
            $result = $step['result'];
            $suffix = str_contains($result, 'failed') || str_contains($result, 'fail') ? ' ✗' : ' ✓';
            $rows[] = [$step['step'], $result.$suffix];
        }

        $this->promptTable(['Step', 'Result'], $rows);
    }
}
