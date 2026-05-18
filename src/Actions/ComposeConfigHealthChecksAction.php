<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;

final readonly class ComposeConfigHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
    ) {}

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(): array
    {
        return [
            $this->checkRow('config.driver', 'Config: driver', 'pass', $this->config->driver, [
                'driver' => $this->config->driver,
            ]),
            $this->checkRow('config.queue_name', 'Config: queue.name', 'pass', $this->config->queueName, [
                'queue_name' => $this->config->queueName,
            ]),
            $this->checkRow('config.log_channel', 'Config: log_channel', 'pass', $this->config->logChannel, [
                'log_channel' => $this->config->logChannel,
            ]),
        ];
    }
}
