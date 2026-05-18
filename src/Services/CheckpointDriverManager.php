<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Support\Manager;

final class CheckpointDriverManager extends Manager
{
    public function getDefaultDriver(): ?string
    {
        return (string) $this->config->get('checkpoint.driver', 'shell');
    }

    protected function createMysqlDriver(): BackupDriver
    {
        return $this->resolveConfigurableDriver('mysql', MysqlDriver::class);
    }

    protected function createPostgresDriver(): BackupDriver
    {
        return $this->resolveConfigurableDriver('postgres', PostgresDriver::class);
    }

    protected function createShellDriver(): BackupDriver
    {
        return $this->resolveConfigurableDriver('shell', ShellCommandDriver::class);
    }

    /**
     * @throws ConfigurationException
     */
    protected function createDriver($driver): BackupDriver
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $method = 'create'.str($driver)->studly()->toString().'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        $class = $this->config->get("checkpoint.drivers.{$driver}.class");

        if (is_string($class) && $class !== '') {
            return $this->resolveAndValidate($class);
        }

        throw new ConfigurationException(
            sprintf('Driver [%s] is not configured. Use extend() to register a custom driver or set checkpoint.drivers.%1$s.class in config.', $driver),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function callCustomCreator($driver): BackupDriver
    {
        $config = $this->config->get("checkpoint.drivers.{$driver}", []);

        if (! is_array($config)) {
            $config = [];
        }

        $resolved = $this->customCreators[$driver]($this->container, $config);

        if (! $resolved instanceof BackupDriver) {
            throw new ConfigurationException(
                sprintf('Custom driver [%s] must implement [%s].', $driver, BackupDriver::class),
            );
        }

        return $resolved;
    }

    /**
     * @throws ConfigurationException
     */
    private function resolveConfigurableDriver(string $driver, string $defaultClass): BackupDriver
    {
        $class = $this->config->get("checkpoint.drivers.{$driver}.class")
            ?? $defaultClass;

        return $this->resolveAndValidate($class);
    }

    /**
     * @throws ConfigurationException
     */
    private function resolveAndValidate(string $class): BackupDriver
    {
        $resolved = $this->container->make($class);

        if (! $resolved instanceof BackupDriver) {
            throw new ConfigurationException(
                sprintf('Configured driver [%s] must implement [%s].', $class, BackupDriver::class),
            );
        }

        return $resolved;
    }
}
