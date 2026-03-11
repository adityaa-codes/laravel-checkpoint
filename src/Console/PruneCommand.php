<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'db-ops:prune';

    protected $description = 'Prune old checkpoint command runs.';

    public function handle(): int
    {
        $count = (new CommandRun)->pruneAll();

        $this->info($this->prunedMessage($count));

        return self::SUCCESS;
    }

    private function prunedMessage(int $count): string
    {
        $message = __('messages.cli.pruned', [
            'count' => $count,
        ]);

        if ($message === 'messages.cli.pruned') {
            return sprintf('Pruned %d command run records.', $count);
        }

        return (string) $message;
    }
}
