<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::partialMock();
});

it('runs in dry-run mode without errors', function (): void {
    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('get')->andReturn('{}');

    $this->artisan('checkpoint:migrate-from-spatie --dry-run --force')
        ->assertExitCode(0);
});

it('runs advisory mode when spatie is not installed', function (): void {
    $composerJsonPath = base_path('composer.json');

    if (File::exists($composerJsonPath)) {
        $original = File::get($composerJsonPath);
        File::put($composerJsonPath, json_encode(['require' => []], JSON_THROW_ON_ERROR));
    } else {
        File::put($composerJsonPath, json_encode(['require' => []], JSON_THROW_ON_ERROR));
    }

    $this->artisan('checkpoint:migrate-from-spatie --dry-run --force')
        ->assertExitCode(0);
});

it('detects spatie composer dependency when present', function (): void {
    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('exists')->with(base_path('composer.json'))->andReturn(true);
    File::shouldReceive('get')->with(base_path('composer.json'))->andReturn(json_encode(['require' => ['spatie/laravel-backup' => '^8.0']], JSON_THROW_ON_ERROR));

    $this->artisan('checkpoint:migrate-from-spatie --dry-run --force')
        ->assertExitCode(0);
});

it('shows command mapping table', function (): void {
    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('get')->andReturn('{}');

    $this->artisan('checkpoint:migrate-from-spatie --dry-run --force')
        ->expectsOutputToContain('Artisan Command Mapping')
        ->assertExitCode(0);
});

it('shows important differences gap notes', function (): void {
    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('get')->andReturn('{}');

    $this->artisan('checkpoint:migrate-from-spatie --dry-run --force')
        ->expectsOutputToContain('Important differences')
        ->assertExitCode(0);
});
