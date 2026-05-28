<?php

declare(strict_types=1);

function makeDir(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function findLogs(string $pattern): array
{
    return glob($pattern) ?: [];
}

function getFileName(string $path): string
{
    return basename($path);
}

function moveFile(string $src, string $dst): void
{
    if (is_file($src)) {
        rename($src, $dst);
    }
}
-----
<?php

declare(strict_types=1);

function makeDir(string $path): void
{
    if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
        \Illuminate\Support\Facades\File::makeDirectory($path, 0755, true);
    }
}

function findLogs(string $pattern): array
{
    return \Illuminate\Support\Facades\File::glob($pattern) ?: [];
}

function getFileName(string $path): string
{
    return \Illuminate\Support\Facades\File::basename($path);
}

function moveFile(string $src, string $dst): void
{
    if (\Illuminate\Support\Facades\File::isFile($src)) {
        \Illuminate\Support\Facades\File::move($src, $dst);
    }
}
