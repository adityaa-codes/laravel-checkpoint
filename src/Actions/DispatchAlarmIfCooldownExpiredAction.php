<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;
use Illuminate\Support\Facades\Cache;

final readonly class DispatchAlarmIfCooldownExpiredAction
{
    public function __construct(
        private HealthCheckConfig $config,
    ) {}

    public function execute(string $key, callable $dispatch): void
    {
        if ($this->config->obs['alertCooldownSeconds'] === 0) {
            $dispatch();

            return;
        }

        $cacheKey = 'checkpoint:alert-cooldown:'.sha1($key);
        $cache = is_string($this->config->lockStore) && $this->config->lockStore !== '' ? Cache::store($this->config->lockStore) : Cache::store();

        if (! $cache->add($cacheKey, now()->timestamp, $this->config->obs['alertCooldownSeconds'])) {
            return;
        }

        $dispatch();
    }
}
