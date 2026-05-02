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

        protected function interactiveInfo(string $message): void
        {
            $this->calls[] = 'interactive:info';
        }

        protected function interactiveWarning(string $message): void
        {
            $this->calls[] = 'interactive:warn';
        }

        protected function interactiveError(string $message): void
        {
            $this->calls[] = 'interactive:error';
        }

        protected function interactiveTable(array $headers, array $rows): void
        {
            $this->calls[] = 'interactive:table';
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

it('uses interactive prompt rendering when enhanced interactive mode is enabled', function (): void {
    $probe = new class
    {
        use UsesLaravelPrompts;

        public bool $interactive = true;

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

        protected function interactiveInfo(string $message): void
        {
            $this->calls[] = 'interactive:info';
        }

        protected function interactiveWarning(string $message): void
        {
            $this->calls[] = 'interactive:warn';
        }

        protected function interactiveError(string $message): void
        {
            $this->calls[] = 'interactive:error';
        }

        protected function interactiveTable(array $headers, array $rows): void
        {
            $this->calls[] = 'interactive:table';
        }
    };

    $probe->run();

    expect($probe->calls)->toBe([
        'interactive:info',
        'interactive:warn',
        'interactive:error',
        'interactive:table',
    ]);
});
