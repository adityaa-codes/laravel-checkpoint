<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Console\CheckpointCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function makeProbeCommand(): CheckpointCommand
{
    $input = new ArrayInput([], new InputDefinition);
    $input->setInteractive(false);

    return new class($input) extends CheckpointCommand
    {
        public function __construct(InputInterface $input)
        {
            parent::__construct();
            $this->input = $input;
        }

        /** @return never */
        public function handle(): int
        {
            exit(0);
        }

        public function probeResolveOutputMode(string $format, bool $agentMode): string
        {
            return $this->resolveOutputMode($format, $agentMode);
        }

        public function probePriorityLabel(string $status): string
        {
            return $this->priorityLabel($status);
        }

        /**
         * @param  list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>  $indicators
         */
        public function probeOverallSloStatus(array $indicators): string
        {
            return $this->overallSloStatus($indicators);
        }

        /**
         * @param  list<array<string,mixed>>  $checks
         * @return list<array<string,mixed>>
         */
        public function probeOrderedChecksForDisplay(array $checks): array
        {
            return $this->orderedChecksForDisplay($checks);
        }

        /**
         * @param  array<string,mixed>  $gateDecision
         * @return array{profile:string,profile_source:string,verdict:string,failed_gate:string,exit_code:int}
         */
        public function probeMachineGateDecision(array $gateDecision): array
        {
            return $this->machineGateDecision($gateDecision);
        }

        /**
         * @param  array<string,mixed>  $replace
         */
        public function probeTranslatedOr(string $key, string $default, array $replace = []): string
        {
            return $this->translatedOr($key, $default, $replace);
        }

        public function probeEnhancedInteractiveMode(): bool
        {
            return $this->enhancedInteractiveMode();
        }

        public function probeSetOutput(): void
        {
            $output = new BufferedOutput;
            $output->setDecorated(false);
            $this->setOutput(new SymfonyStyle($this->input, $output));
        }
    };
}

test('resolves output mode', function (): void {
    $command = makeProbeCommand();

    expect($command->probeResolveOutputMode('table', false))->toBe('table');
    expect($command->probeResolveOutputMode('json', false))->toBe('json');
    expect($command->probeResolveOutputMode('compact-json', false))->toBe('compact-json');
    expect($command->probeResolveOutputMode('invalid', false))->toBe('table');
    expect($command->probeResolveOutputMode('table', true))->toBe('agent');
});

test('provides priority labels', function (): void {
    $command = makeProbeCommand();

    expect($command->probePriorityLabel('fail'))->toBe('P0');
    expect($command->probePriorityLabel('warn'))->toBe('P1');
    expect($command->probePriorityLabel('pass'))->toBe('P3');
    expect($command->probePriorityLabel('unknown'))->toBe('P3');
});

test('computes overall SLO status from indicators', function (): void {
    $command = makeProbeCommand();

    $allPass = [
        ['name' => 'runs', 'target' => 0, 'current' => 0, 'status' => 'pass', 'unit' => 'runs'],
    ];
    expect($command->probeOverallSloStatus($allPass))->toBe('pass');

    $withWarn = [
        ['name' => 'runs', 'target' => 0, 'current' => 0, 'status' => 'pass', 'unit' => 'runs'],
        ['name' => 'drift', 'target' => 0, 'current' => 2, 'status' => 'warn', 'unit' => 'runs'],
    ];
    expect($command->probeOverallSloStatus($withWarn))->toBe('warn');

    $withFail = [
        ['name' => 'runs', 'target' => 0, 'current' => 1, 'status' => 'fail', 'unit' => 'runs'],
        ['name' => 'drift', 'target' => 0, 'current' => 2, 'status' => 'warn', 'unit' => 'runs'],
    ];
    expect($command->probeOverallSloStatus($withFail))->toBe('fail');
});

test('orders checks for display with fail first', function (): void {
    $command = makeProbeCommand();

    $checks = [
        ['code' => 'c', 'check' => 'C check', 'status' => 'pass'],
        ['code' => 'a', 'check' => 'A check', 'status' => 'fail'],
        ['code' => 'b', 'check' => 'B check', 'status' => 'warn'],
    ];

    $ordered = $command->probeOrderedChecksForDisplay($checks);

    expect($ordered[0]['status'])->toBe('fail');
    expect($ordered[1]['status'])->toBe('warn');
    expect($ordered[2]['status'])->toBe('pass');
});

test('normalizes machine gate decision', function (): void {
    $command = makeProbeCommand();

    $result = $command->probeMachineGateDecision([
        'profile' => 'ci',
        'profile_source' => 'override',
        'verdict' => 'pass',
        'failed_gate' => 'health',
        'exit_code' => 0,
    ]);

    expect($result)->toMatchArray([
        'profile' => 'ci',
        'profile_source' => 'override',
        'verdict' => 'pass',
        'failed_gate' => 'health',
        'exit_code' => 0,
    ]);
});

test('fills defaults in machine gate decision', function (): void {
    $command = makeProbeCommand();

    $result = $command->probeMachineGateDecision([]);

    expect($result)->toMatchArray([
        'profile' => 'unknown',
        'profile_source' => 'default',
        'verdict' => 'fail',
        'failed_gate' => 'policy',
        'exit_code' => 12,
    ]);
});

test('translates or returns default', function (): void {
    $command = makeProbeCommand();

    expect($command->probeTranslatedOr('nonexistent.key.test', 'default'))->toBe('default');
});

test('reports interactive mode disabled when input is not interactive', function (): void {
    $command = makeProbeCommand();

    expect($command->probeEnhancedInteractiveMode())->toBeFalse();
});
