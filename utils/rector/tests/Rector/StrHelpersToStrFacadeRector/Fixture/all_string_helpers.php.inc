<?php

declare(strict_types=1);

function formatName(string $name): string
{
    $trimmed = trim($name);
    $lower = strtolower($trimmed);
    $slug = str_replace(' ', '-', $lower);
    $len = strlen($slug);
    return ucfirst($slug);
}

function excerpt(string $text, int $max): string
{
    $text = trim($text);
    if (mb_strlen($text) > $max) {
        return mb_substr($text, 0, $max) . '...';
    }
    return $text;
}
-----
<?php

declare(strict_types=1);

function formatName(string $name): string
{
    $trimmed = \Illuminate\Support\Str::trim($name);
    $lower = \Illuminate\Support\Str::lower($trimmed);
    $slug = \Illuminate\Support\Str::replace(' ', '-', $lower);
    $len = \Illuminate\Support\Str::length($slug);
    return \Illuminate\Support\Str::ucfirst($slug);
}

function excerpt(string $text, int $max): string
{
    $text = \Illuminate\Support\Str::trim($text);
    if (\Illuminate\Support\Str::length($text) > $max) {
        return \Illuminate\Support\Str::substr($text, 0, $max) . '...';
    }
    return $text;
}
