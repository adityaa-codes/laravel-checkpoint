<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Tests\TestCase;
use Illuminate\Testing\PendingCommand;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $parameters
 */
function checkpoint_artisan(string $command, array $parameters = []): PendingCommand
{
    /** @phpstan-ignore-next-line */
    return test()->artisan($command, $parameters);
}
