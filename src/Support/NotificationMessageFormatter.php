<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

final class NotificationMessageFormatter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{canonical:array<string,mixed>,slack_text:string,telegram_text:string}
     */
    public function format(array $payload): array
    {
        $eventKey = (string) ($payload['event_key'] ?? 'unknown');
        $level = strtoupper((string) ($payload['level'] ?? 'INFO'));
        $occurredAt = (string) ($payload['occurred_at'] ?? now()->toIso8601String());
        $eventData = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $summary = $this->summary($eventKey, $eventData);
        $context = $this->context($eventData);
        $actions = $this->actions($eventKey, $level, $eventData);

        $canonical = [
            'event_key' => $eventKey,
            'level' => strtolower($level),
            'occurred_at' => $occurredAt,
            'summary' => $summary,
            'context' => $context,
            'actions' => $actions,
        ];

        return [
            'canonical' => $canonical,
            'slack_text' => $this->slackText($canonical),
            'telegram_text' => $this->telegramText($canonical),
        ];
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function summary(string $eventKey, array $eventData): string
    {
        $run = is_array($eventData['run'] ?? null) ? $eventData['run'] : [];
        $operation = (string) ($run['operation'] ?? 'operation');

        return match ($eventKey) {
            'backup.failed' => sprintf('Backup operation `%s` failed.', $operation),
            'backup.freshness_alarm' => 'Backup freshness threshold breached.',
            'backup_drill.freshness_alarm' => 'Backup drill freshness threshold breached.',
            'backup_drill.pass_rate_alarm' => 'Backup drill pass-rate threshold breached.',
            'queue.lag_detected' => 'Queue lag detected for checkpoint operations.',
            'queue.orphan_redispatched' => 'Orphaned run was redispatched.',
            default => sprintf('Checkpoint event `%s` received.', $eventKey),
        };
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function context(array $eventData): array
    {
        $run = is_array($eventData['run'] ?? null) ? $eventData['run'] : [];
        $remediation = is_array($eventData['remediation'] ?? null) ? $eventData['remediation'] : [];

        return array_filter([
            'run_id' => $run['id'] ?? null,
            'operation' => $run['operation'] ?? null,
            'status' => $run['status'] ?? null,
            'driver' => $run['driver'] ?? null,
            'stanza' => $run['stanza'] ?? null,
            'repository' => $run['repository'] ?? null,
            'exit_code' => $eventData['exit_code'] ?? null,
            'queue' => $eventData['queue'] ?? null,
            'reason' => $eventData['reason'] ?? null,
            'threshold_hours' => $eventData['threshold_hours'] ?? null,
            'threshold_days' => $eventData['threshold_days'] ?? null,
            'window_days' => $eventData['window_days'] ?? null,
            'playbook_signature' => $remediation['signature'] ?? null,
            'playbook_severity' => $remediation['severity'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $eventData
     * @return list<string>
     */
    private function actions(string $eventKey, string $level, array $eventData): array
    {
        $base = [
            'php artisan checkpoint:status --limit=5 --format=json',
            'php artisan checkpoint:doctor --format=json',
        ];

        if (in_array($eventKey, ['backup.failed', 'backup.freshness_alarm', 'queue.lag_detected'], true) || $level === 'CRITICAL') {
            $base[] = 'php artisan checkpoint:report --limit=10 --format=json';
        }

        foreach ($this->remediationCommands($eventData) as $command) {
            if (! in_array($command, $base, true)) {
                $base[] = $command;
            }
        }

        return $base;
    }

    /**
     * @param  array<string,mixed>  $eventData
     * @return list<string>
     */
    private function remediationCommands(array $eventData): array
    {
        $remediation = is_array($eventData['remediation'] ?? null) ? $eventData['remediation'] : [];
        $commands = $remediation['recommended_commands'] ?? [];

        if (! is_array($commands)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $command): string => is_string($command) ? trim($command) : '',
            $commands,
        ), static fn (string $command): bool => $command !== ''));
    }

    /**
     * @param  array<string, mixed>  $canonical
     */
    private function slackText(array $canonical): string
    {
        $icon = $this->iconForLevel((string) $canonical['level']);
        $context = $this->inlineContext($canonical);
        $extra = isset($canonical['actions'][2]) ? sprintf("\n• `%s`", $canonical['actions'][2]) : '';

        $template = "%s *[%s]* `%s`\n*Summary:* {summary}\n*When:* %s\n*Context:* %s\n*Next actions:*\n• `%s`\n• `%s`%s";

        $result = sprintf(
            $template,
            $icon,
            strtoupper((string) $canonical['level']),
            (string) $canonical['event_key'],
            (string) $canonical['occurred_at'],
            $context,
            $canonical['actions'][0] ?? '',
            $canonical['actions'][1] ?? '',
            $extra,
        );

        return str_replace('{summary}', (string) $canonical['summary'], $result);
    }

    /**
     * @param  array<string, mixed>  $canonical
     */
    private function telegramText(array $canonical): string
    {
        $icon = $this->iconForLevel((string) $canonical['level']);
        $context = $this->inlineContext($canonical);
        $extra = isset($canonical['actions'][2]) ? sprintf("\n- %s", $canonical['actions'][2]) : '';

        $template = "%s [%s] %s\nSummary: {summary}\nWhen: %s\nContext: %s\nNext:\n- %s\n- %s%s";

        $result = sprintf(
            $template,
            $icon,
            strtoupper((string) $canonical['level']),
            (string) $canonical['event_key'],
            (string) $canonical['occurred_at'],
            $context,
            $canonical['actions'][0] ?? '',
            $canonical['actions'][1] ?? '',
            $extra,
        );

        return str_replace('{summary}', (string) $canonical['summary'], $result);
    }

    /**
     * @param  array<string, mixed>  $canonical
     */
    private function inlineContext(array $canonical): string
    {
        $context = is_array($canonical['context'] ?? null) ? $canonical['context'] : [];

        if ($context === []) {
            return 'n/a';
        }

        $pairs = [];

        foreach ($context as $key => $value) {
            $pairs[] = sprintf('%s=%s', $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode(' | ', $pairs);
    }

    private function iconForLevel(string $level): string
    {
        return match (strtolower($level)) {
            'critical' => ':rotating_light:',
            'warning' => ':warning:',
            default => ':information_source:',
        };
    }
}
