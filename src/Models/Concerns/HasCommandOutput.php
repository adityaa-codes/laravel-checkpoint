<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models\Concerns;

use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;

trait HasCommandOutput
{
    public function resolvedCommandOutput(): ?string
    {
        return $this->outputStore()->resolve($this);
    }

    protected function outputStore(): CommandOutputStore
    {
        return resolve(CommandOutputStore::class);
    }
}
