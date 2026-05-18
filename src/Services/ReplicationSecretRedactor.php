<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Support\DsnPattern;

/** @internal */
final readonly class ReplicationSecretRedactor
{
    public function redact(string $input): string
    {
        $trimmed = str($input)->trim()->value();

        if ($trimmed === '') {
            return $trimmed;
        }

        if (preg_match(DsnPattern::REGEX, $trimmed) === 1) {
            $parts = parse_url($trimmed);

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return "{$parts['scheme']}://[REDACTED]@{$parts['host']}";
            }
        }

        return (string) preg_replace(
            '/\b(password|pass|passphrase|secret|token|apikey|api_key)\s*=\s*([^,\s]+)/i',
            '$1=[REDACTED]',
            $trimmed,
        );
    }
}
