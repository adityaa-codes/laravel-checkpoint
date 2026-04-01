<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

/** @internal */
final class CommandLineRedactor
{
    public function redact(string $commandLine): string
    {
        $patterns = [
            '/([\'"])(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret)=)([^\'"]+)\1/i',
            '/([\'"])(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret))\1\s+([\'"])([^\'"]+)\3/i',
            '/(\b(?:password|pass|passphrase|token|secret|apikey|api_key|access_key|key_secret|pgpassword)=)([^\s]+)/i',
            '/(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret)=)([^\s]+)/i',
            '/(--(?:password|pass|passphrase|token|secret|apikey|api-key|access-key|key-secret|cipher-pass|s3-key-secret))\s+("[^"]*"|\'[^\']*\'|[^\s]+)/i',
            '/((?:postgres|postgresql|pgsql|mysql|mariadb):\/\/[^:\s]+:)([^@\/\s]+)(@)/i',
        ];

        $replacements = [
            '$1$2[REDACTED]$1',
            '$1$2$1 $3[REDACTED]$3',
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1 [REDACTED]',
            '$1[REDACTED]$3',
        ];

        return (string) preg_replace($patterns, $replacements, $commandLine);
    }
}
