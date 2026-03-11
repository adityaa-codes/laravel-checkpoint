<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Policies;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

final class CommandRunPolicy
{
    public function viewAny(object $user): bool
    {
        return true;
    }

    public function view(object $user, CommandRun $commandRun): bool
    {
        return true;
    }

    public function create(object $user): bool
    {
        return true;
    }
}
