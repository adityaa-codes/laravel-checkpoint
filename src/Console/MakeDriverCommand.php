<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

final class MakeDriverCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:make-driver
                            {name : The driver name (e.g. MyCustomDriver)}';

    protected $description = 'Create a custom Checkpoint backup driver.';

    public function handle(): int
    {
        $raw = $this->argument('name');
        $name = is_string($raw) ? Str::studly(Str::trim($raw)) : '';

        if ($name === '') {
            $name = text(
                label: 'Driver class name',
                placeholder: 'MyCustomDriver',
                required: true,
            );
        }

        $name = Str::studly($name);

        if (! Str::endsWith($name, 'Driver')) {
            $name .= 'Driver';
        }

        $namespace = 'App\\Drivers';
        $directory = app_path('Drivers');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $path = $directory.'/'.$name.'.php';

        if (File::exists($path)) {
            $this->promptError(sprintf('Driver [%s] already exists at [%s].', $name, $path));

            return self::FAILURE;
        }

        $stub = File::get(__DIR__.'/../../stubs/driver.phpstub');

        $content = Str::replace(
            ['{{ namespace }}', '{{ class }}', '{{ dummyCommand }}'],
            [$namespace, $name, 'echo "backup complete"'],
            $stub,
        );

        File::put($path, $content);

        $driverKey = Str::snake(Str::replaceEnd('Driver', '', $name));

        $this->promptInfo(sprintf('Driver [%s] created at [%s].', $name, $path));

        $this->line('');
        $this->line('  <fg=green>Next step — register in AppServiceProvider::boot():</>');
        $this->line('');
        $this->line(sprintf('  app(\\%s\\Services\\CheckpointDriverManager::class)', 'AdityaaCodes\\LaravelCheckpoint'));
        $this->line(sprintf("      ->extend('%s', fn (): \\%s\\%s => new \\%s\\%s);", $driverKey, $namespace, $name, $namespace, $name));

        return self::SUCCESS;
    }
}
