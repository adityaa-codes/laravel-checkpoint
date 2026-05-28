<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Support\Str;

/** @internal */
final readonly class CommandLineRedactor
{
    public function redact(string $commandLine): string
    {
        $trimmed = Str::trim($commandLine);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (Str::isMatch('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $trimmed)) {
            $parts = parse_url($trimmed);

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $host = $parts['host'];
                $port = isset($parts['port']) ? ":{$parts['port']}" : '';

                return "{$parts['scheme']}://[REDACTED]@{$host}{$port}";
            }
        }

        $patterns = [
            '/([\'"])(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret)=)([^\'"]+)\1/i',
            '/([\'"])(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret))\1\s+([\'"])([^\'"]+)\3/i',
            '/(\b(?:password|pass|passphrase|token|secret|apikey|api_key|access_key|key_secret|pgpassword)\s*=\s*)([^\s,]+)/i',
            '/(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret)=)([^\s]+)/i',
            '/(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret))\s+("[^"]*"|\'[^\']*\'|[^\s]+)/i',
            '/([A-Za-z][A-Za-z0-9+.-]*:\/\/[^:\s]+:)([^@\/\s]+)(@)/i',
        ];

        $replacements = [
            '$1$2[REDACTED]$1',
            '$1$2$1 $3[REDACTED]$3',
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1 [REDACTED]',
            '$1[REDACTED]$3',
        ];

        return (string) Str::replaceMatches($patterns, $replacements, $trimmed);
    }
}
