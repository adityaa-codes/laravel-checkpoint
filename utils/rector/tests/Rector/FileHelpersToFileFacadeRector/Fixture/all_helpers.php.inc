<?php

declare(strict_types=1);

function processFile(string $path): string
{
    if (file_exists($path)) {
        return file_get_contents($path);
    }
    return '';
}

function writeOutput(string $path, string $data): void
{
    file_put_contents($path, $data);
}

function cleanup(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

function ensureDirectory(string $path): bool
{
    return is_dir($path);
}
-----
<?php

declare(strict_types=1);

function processFile(string $path): string
{
    if (\Illuminate\Support\Facades\File::exists($path)) {
        return \Illuminate\Support\Facades\File::get($path);
    }
    return '';
}

function writeOutput(string $path, string $data): void
{
    \Illuminate\Support\Facades\File::put($path, $data);
}

function cleanup(string $path): void
{
    if (\Illuminate\Support\Facades\File::isFile($path)) {
        \Illuminate\Support\Facades\File::delete($path);
    }
}

function ensureDirectory(string $path): bool
{
    return \Illuminate\Support\Facades\File::isDirectory($path);
}
