<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\Support\BinaryFinder;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final readonly class ComposeBinaryHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
        private BinaryFinder $binaryFinder,
    ) {}

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(): array
    {
        return [
            $this->configuredBinaryRow(
                code: 'binary.pg_dump',
                label: 'Binary: pg_dump',
                binary: 'pg_dump',
                configPath: 'system.path',
                envKey: 'PATH',
                driver: $this->config->driver,
                required: false,
                includeRemediation: false,
            ),
            $this->configuredBinaryRow(
                code: 'binary.pg_basebackup',
                label: 'Binary: pg_basebackup',
                binary: $this->config->bin['pgbasebackup'],
                configPath: 'database.connections.pgsql.dump.dump_binary_path',
                envKey: 'N/A (from database config)',
                driver: $this->config->driver,
                required: false,
                includeRemediation: false,
            ),
            $this->configuredBinaryRow(
                code: 'binary.gzip',
                label: 'Binary: gzip',
                binary: 'gzip',
                configPath: 'system.path',
                envKey: 'PATH',
                driver: $this->config->driver,
                required: false,
                includeRemediation: false,
            ),
            ...$this->activeDriverBinaryChecks(),
        ];
    }

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    private function activeDriverBinaryChecks(): array
    {
        return match ($this->config->driver) {
            'postgres' => [
                $this->configuredBinaryRow(
                    code: 'driver.binary.postgres.pgbasebackup',
                    label: 'Driver binary: pg_basebackup',
                    binary: $this->config->bin['pgbasebackup'],
                    configPath: 'database.connections.pgsql.dump.dump_binary_path',
                    envKey: 'N/A (from database config)',
                    driver: $this->config->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.postgres.pgdump',
                    label: 'Driver binary: pg_dump',
                    binary: $this->config->bin['pgdump_dump'],
                    configPath: 'database.connections.pgsql.dump.dump_binary_path',
                    envKey: 'N/A (from database config)',
                    driver: $this->config->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.postgres.pgrestore',
                    label: 'Driver binary: pg_restore',
                    binary: $this->config->bin['pgdump_restore'],
                    configPath: 'database.connections.pgsql.dump.dump_binary_path',
                    envKey: 'N/A (from database config)',
                    driver: $this->config->driver,
                ),
            ],
            'mysql' => [
                $this->configuredBinaryRow(
                    code: 'driver.binary.mysql.dump',
                    label: 'Driver binary: mysqldump',
                    binary: $this->config->bin['mysqldump'],
                    configPath: 'database.connections.mysql.dump.dump_binary_path',
                    envKey: 'N/A (from database config)',
                    driver: $this->config->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.mysql.mysql',
                    label: 'Driver binary: mysql',
                    binary: $this->config->bin['mysql'],
                    configPath: 'database.connections.mysql.dump.dump_binary_path',
                    envKey: 'N/A (from database config)',
                    driver: $this->config->driver,
                ),
                $this->configuredBinaryRow(
                    code: 'driver.binary.mysql.binlog',
                    label: 'Driver binary: mysqlbinlog',
                    binary: $this->config->bin['mysqlbinlog'],
                    configPath: 'database.connections.mysql.dump.dump_binary_path',
                    envKey: 'N/A (from database config)',
                    driver: $this->config->driver,
                ),
            ],
            default => $this->driverBinaryChecksFromConfig(),
        };
    }

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    private function driverBinaryChecksFromConfig(): array
    {
        $checks = [];

        foreach ($this->config->driverBinaries as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $label = (string) ($entry['label'] ?? '');
            $binary = (string) ($entry['binary'] ?? '');
            if ($code === '') {
                continue;
            }
            if ($label === '') {
                continue;
            }
            if ($binary === '') {
                continue;
            }

            $checks[] = $this->configuredBinaryRow(
                code: sprintf('driver.binary.%s.%s', $this->config->driver, $code),
                label: sprintf('Driver binary: %s', $label),
                binary: $binary,
                configPath: sprintf('checkpoint.drivers.%s.health_binaries.%s.binary', $this->config->driver, $code),
                envKey: sprintf('CP_%s_BINARY', Str::upper($code)),
                driver: $this->config->driver,
            );
        }

        return $checks;
    }

    /**
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function configuredBinaryRow(
        string $code,
        string $label,
        string $binary,
        string $configPath,
        string $envKey,
        string $driver,
        bool $required = true,
        bool $includeRemediation = true,
    ): array {
        $trimmedBinary = Str::trim($binary);
        $remediationCommands = $includeRemediation
            ? [
                sprintf('command -v %s', $trimmedBinary !== '' ? $trimmedBinary : '<binary>'),
                sprintf('export %s=/absolute/path/to/%s', $envKey, $trimmedBinary !== '' ? File::basename($trimmedBinary) : '<binary>'),
                'php artisan checkpoint:status --health --format=json',
            ]
            : [];

        $data = [
            'binary' => $trimmedBinary,
            'required' => $required,
            'found' => false,
            'path' => null,
        ];

        if ($includeRemediation) {
            $data['driver'] = $driver;
            $data['config_path'] = $configPath;
            $data['env_key'] = $envKey;
        }

        if ($trimmedBinary === '') {
            $data['reason'] = 'empty';
            if ($includeRemediation) {
                $data['remediation_commands'] = $remediationCommands;
            }

            return $this->checkRow($code, $label, $required ? 'fail' : 'warn', 'Binary is empty', $data);
        }

        $resolution = $this->binaryFinder->resolve($trimmedBinary);
        $path = $resolution['path'];

        if ($path === null) {
            $data['reason'] = 'not_found';
            if ($includeRemediation) {
                $data['remediation_commands'] = $remediationCommands;
            }

            return $this->checkRow($code, $label, $required ? 'fail' : 'warn', sprintf('%s not found on PATH', $trimmedBinary), $data);
        }

        $data['found'] = true;
        $data['path'] = $path;
        $data['reason'] = null;

        if ($includeRemediation) {
            $data['remediation_commands'] = $remediationCommands;
        }

        return $this->checkRow($code, $label, 'pass', $path, $data);
    }
}
