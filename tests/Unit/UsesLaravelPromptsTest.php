<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;

it('uses fallback console rendering when enhanced interactive mode is disabled', function (): void {
    $probe = new class
    {
        use UsesLaravelPrompts;

        public bool $interactive = false;

        /** @var list<string> */
        public array $calls = [];

        public function run(): void
        {
            $this->promptInfo('info');
            $this->promptWarning('warn');
            $this->promptError('error');
            $this->promptTable(['A'], [[1]]);
        }

        protected function enhancedInteractiveMode(): bool
        {
            return $this->interactive;
        }

        protected function info($string, $verbosity = null): void
        {
            $this->calls[] = 'fallback:info';
        }

        protected function warn($string, $verbosity = null): void
        {
            $this->calls[] = 'fallback:warn';
        }

        protected function error($string, $verbosity = null): void
        {
            $this->calls[] = 'fallback:error';
        }

        protected function table($headers, $rows, $tableStyle = 'default', array $columnStyles = []): void
        {
            $this->calls[] = 'fallback:table';
        }
    };

    $probe->run();

    expect($probe->calls)->toBe([
        'fallback:info',
        'fallback:warn',
        'fallback:error',
        'fallback:table',
    ]);
});
