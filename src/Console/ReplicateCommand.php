<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildReplicationCommandPayloadAction;
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

final class ReplicateCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:replicate
        {source? : Source endpoint (profile:<id>, DSN, or key=value pairs)}
        {destination? : Destination endpoint (profile:<id>, DSN, or key=value pairs)}
        {--source= : Source endpoint override}
        {--destination= : Destination endpoint override}
        {--apply : Queue apply mode. Without this flag, replication runs in dry-run mode.}
        {--force-overwrite : Request overwrite behavior for apply mode.}
        {--critical-table=* : Critical table names to guard overwrite. Repeat option for multiple tables.}';

    protected $description = 'Queue a replication sync run with conservative defaults.';

    public function handle(
        EnqueueCommandRunAction $enqueueCommandRun,
        BuildReplicationCommandPayloadAction $buildPayload,
    ): int {
        try {
            if ($this->enhancedInteractiveMode()) {
                intro('Replication Sync Wizard');
                note('Default mode is dry-run. Use apply mode only after validation.');
                note('What: queue replication diff/apply workflow between endpoints.');
                note('When: controlled data sync or migration scenarios.');
                note('Next: run checkpoint:status to monitor replication execution.');
            }

            $applyRequested = (bool) $this->option('apply');
            $forceOverwriteRequested = (bool) $this->option('force-overwrite');

            if ($this->enhancedInteractiveMode() && $applyRequested) {
                warning('Apply mode can overwrite destination data.');

                if (! confirm('Continue with apply mode?', default: false)) {
                    return self::FAILURE;
                }
            }

            $payload = $buildPayload->execute(
                source: $this->resolveEndpoint('source'),
                destination: $this->resolveEndpoint('destination'),
                apply: $applyRequested,
                forceOverwrite: $forceOverwriteRequested,
                criticalTables: $this->criticalTableOptions(),
            );

            if ($this->enhancedInteractiveMode()) {
                $this->promptTable(['Field', 'Value'], [
                    ['Mode', $payload['dry_run'] ? 'dry-run' : 'apply'],
                    ['Force overwrite', $payload['force_overwrite'] ? 'yes' : 'no'],
                    ['Critical tables', collect($payload['critical_tables'])->join(', ') ?: '-'],
                    ['Source', $this->safeEndpointLabel($payload['source'])],
                    ['Destination', $this->safeEndpointLabel($payload['destination'])],
                ]);
            }

            $run = $enqueueCommandRun->execute(
                'replication_sync',
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            $message = sprintf('Queued Replication Sync run #%d.', (int) $run->getKey());

            if ($this->enhancedInteractiveMode()) {
                outro($message);
            } else {
                $this->promptInfo($message);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveEndpoint(string $role): string
    {
        $option = $this->option($role);

        if (is_string($option) && Str::trim($option) !== '') {
            return Str::trim($option);
        }

        $argument = $this->argument($role);

        if (is_string($argument) && Str::trim($argument) !== '') {
            return Str::trim($argument);
        }

        if ($this->input !== null && ! $this->input->isInteractive()) {
            throw new CheckpointArgumentException(sprintf(
                'Replication %s endpoint is required in non-interactive mode. Pass --%s=... or the positional %s argument.',
                $role,
                $role,
                $role,
            ));
        }

        return Str::trim((string) $this->secret(sprintf('Enter %s replication endpoint', $role)));
    }

    /**
     * @return array<mixed>
     */
    private function criticalTableOptions(): array
    {
        $criticalTables = $this->option('critical-table');

        return is_array($criticalTables) ? $criticalTables : [];
    }

    private function safeEndpointLabel(string $endpoint): string
    {
        if ($endpoint === '') {
            return '-';
        }

        if (Str::startsWith($endpoint, 'profile:')) {
            return $endpoint;
        }

        if (Str::contains($endpoint, '://')) {
            $parts = parse_url($endpoint);

            if (is_array($parts) && isset($parts['scheme'])) {
                $host = $parts['host'] ?? 'host';
                $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '';

                return sprintf('%s://%s%s', $parts['scheme'], $host, $path);
            }
        }

        if (Str::contains($endpoint, '=')) {
            return 'kv-pairs(redacted)';
        }

        return '[redacted]';
    }
}
