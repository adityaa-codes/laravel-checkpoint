<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

final class MakeDriverCommand extends Command
{
    use UsesLaravelPrompts;

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

        if (! str_ends_with($name, 'Driver')) {
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
        $this->line('  <fg=green>Next step — add to config/checkpoint.php:</>');
        $this->line('');
        $this->line(sprintf("  'driver' => '%s',", $driverKey));
        $this->line("  'drivers' => [");
        $this->line(sprintf("      '%s' => [", $driverKey));
        $this->line(sprintf("          'class' => \\%s\\%s::class,", $namespace, $name));
        $this->line("          'health_binaries' => [");
        $this->line("              // ['code' => 'mybinary', 'label' => 'mybinary', 'binary' => '/usr/bin/mybinary'],");
        $this->line('          ],');
        $this->line('      ],');
        $this->line('  ],');

        return self::SUCCESS;
    }
}
