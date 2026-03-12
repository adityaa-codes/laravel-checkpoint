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

function checkpoint_fixture_path(string $fixture): string
{
    return __DIR__.'/Fixtures/'.$fixture;
}

/**
 * @return mixed
 */
function checkpoint_normalize_fixture_value(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(checkpoint_normalize_fixture_value(...), $value);
    }

    ksort($value);

    foreach ($value as $key => $item) {
        $value[$key] = checkpoint_normalize_fixture_value($item);
    }

    return $value;
}

function checkpoint_assert_matches_fixture(array $payload, string $fixture): void
{
    $expected = json_decode((string) file_get_contents(checkpoint_fixture_path($fixture)), true, 512, JSON_THROW_ON_ERROR);

    expect(checkpoint_normalize_fixture_value($payload))
        ->toBe(checkpoint_normalize_fixture_value($expected));
}
