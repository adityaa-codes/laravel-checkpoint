<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Bus::fake();
    Event::fake();

    $fakeBinDir = '/tmp/fake-mysql-bin';
    if (! is_dir($fakeBinDir)) {
        mkdir($fakeBinDir, 0755, true);
    }
    foreach (['mysqldump', 'mysql'] as $bin) {
        $path = $fakeBinDir.'/'.$bin;
        if (! file_exists($path)) {
            touch($path);
            chmod($path, 0755);
        }
    }
});

it('backup command outputs json envelope when --format=json', function (): void {
    Artisan::call('checkpoint:backup', ['--format' => 'json']);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded)->toHaveKey('version')
        ->and($decoded)->toHaveKey('surface', 'backup')
        ->and($decoded)->toHaveKey('status', 'ok')
        ->and($decoded)->toHaveKey('generated_at')
        ->and($decoded)->toHaveKey('data');
});

it('drill command outputs json envelope when --format=json', function (): void {
    Artisan::call('checkpoint:drill', ['--format' => 'json']);

    $decoded = json_decode(Artisan::output(), true);
    expect($decoded)->toHaveKey('version')
        ->and($decoded)->toHaveKey('surface', 'drill')
        ->and($decoded)->toHaveKey('status', 'ok')
        ->and($decoded)->toHaveKey('generated_at')
        ->and($decoded)->toHaveKey('data');
});
