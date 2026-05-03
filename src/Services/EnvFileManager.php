<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

final readonly class EnvFileManager
{
    public function formattedValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s/', $value) === 1) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public function writeEntries(string $contents, array $entries): string
    {
        foreach ($entries as $key => $value) {
            $line = sprintf('%s=%s', $key, $this->formattedValue($value));
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $suffix = str_ends_with($contents, "\n") ? '' : "\n";
                $contents .= $suffix.$line."\n";
            }
        }

        return $contents;
    }
}
