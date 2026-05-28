<?php

declare(strict_types=1);

it('fails when --health and --full are combined', function (): void {
    checkpoint_artisan('checkpoint:status', ['--health' => true, '--full' => true])
        ->assertExitCode(1);
});

it('fails when --health and --summary are combined', function (): void {
    checkpoint_artisan('checkpoint:status', ['--health' => true, '--summary' => true])
        ->assertExitCode(1);
});

it('fails when --summary and --full are combined', function (): void {
    checkpoint_artisan('checkpoint:status', ['--summary' => true, '--full' => true])
        ->assertExitCode(1);
});

it('succeeds when --brief combined with --health (modifier not a mode)', function (): void {
    checkpoint_artisan('checkpoint:status', ['--brief' => true, '--health' => true])
        ->assertExitCode(0);
});

it('succeeds when --brief combined with --full (modifier not a mode)', function (): void {
    checkpoint_artisan('checkpoint:status', ['--brief' => true, '--full' => true])
        ->assertExitCode(0);
});

it('succeeds when --brief combined with --summary (modifier not a mode)', function (): void {
    checkpoint_artisan('checkpoint:status', ['--brief' => true, '--summary' => true])
        ->assertExitCode(0);
});

it('succeeds when only one mode is used', function (): void {
    checkpoint_artisan('checkpoint:status', ['--limit' => 1])
        ->assertExitCode(0);
});
