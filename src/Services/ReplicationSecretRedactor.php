<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

/** @internal */
final class ReplicationSecretRedactor
{
    public function redact(string $input): string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $trimmed) === 1) {
            $parts = parse_url($trimmed);

            if (is_array($parts) && isset($parts['scheme']) && isset($parts['host'])) {
                return sprintf('%s://[REDACTED]@%s', $parts['scheme'], $parts['host']);
            }
        }

        return (string) preg_replace(
            '/\b(password|pass|passphrase|secret|token|apikey|api_key)\s*=\s*([^,\s]+)/i',
            '$1=[REDACTED]',
            $trimmed,
        );
    }
}
