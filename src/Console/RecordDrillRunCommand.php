<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\DateFactory;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class RecordDrillRunCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:record-drill
        {--run-uuid= : Unique drill run UUID}
        {--marker-uuid= : Marker UUID}
        {--marker-email= : Marker email}
        {--marker-count= : Marker count}
        {--marker-result= : Marker result}
        {--rto-target-seconds= : RTO target in seconds}
        {--rto-actual-seconds= : RTO actual in seconds}
        {--rto-result= : RTO result}
        {--rpo-target-seconds= : RPO target in seconds}
        {--rpo-actual-seconds= : RPO actual in seconds}
        {--rpo-result= : RPO result}
        {--overall-result= : Overall result}
        {--executed-by= : Operator or system that executed the drill}
        {--executed-at= : ISO-8601 datetime for when the drill ran}';

    protected $description = 'Record a backup drill result.';

    protected $aliases = ['checkpoint:admin:record-drill'];

    public function __construct(
        private readonly Factory $validator,
        private readonly DateFactory $date,
        private readonly Dispatcher $events,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($this->enhancedInteractiveMode()) {
                intro('Record Backup Drill Run');
                note('What: persist externally executed drill outcomes into checkpoint history.');
                note('When: drills are executed outside queue automation.');
                note('Next: run checkpoint:check:report to verify drill evidence is reflected.');
            }

            $attributes = $this->validatedAttributes();
            $run = BackupDrillRun::query()->create($attributes);

            $this->events->dispatch(new BackupDrillCompleted($run));

            $message = $this->recordedMessage($run);

            if ($this->enhancedInteractiveMode()) {
                outro($message);
            } else {
                $this->promptInfo($message);
            }

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->promptError($exception->validator->errors()->first());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validatedAttributes(): array
    {
        $input = [
            'run_uuid' => $this->option('run-uuid'),
            'marker_uuid' => $this->option('marker-uuid'),
            'marker_email' => $this->option('marker-email'),
            'marker_count' => $this->option('marker-count'),
            'marker_result' => $this->option('marker-result'),
            'rto_target_seconds' => $this->option('rto-target-seconds'),
            'rto_actual_seconds' => $this->option('rto-actual-seconds'),
            'rto_result' => $this->option('rto-result'),
            'rpo_target_seconds' => $this->option('rpo-target-seconds'),
            'rpo_actual_seconds' => $this->option('rpo-actual-seconds'),
            'rpo_result' => $this->option('rpo-result'),
            'overall_result' => $this->option('overall-result'),
            'executed_by' => $this->option('executed-by'),
            'executed_at' => $this->option('executed-at'),
        ];

        $validated = $this->validator->make($input, [
            'run_uuid' => ['required', 'uuid'],
            'marker_uuid' => ['nullable', 'uuid'],
            'marker_email' => ['nullable', 'email'],
            'marker_count' => ['nullable', 'integer', 'min:0'],
            'marker_result' => ['nullable', 'in:pass,fail'],
            'rto_target_seconds' => ['nullable', 'integer', 'min:0'],
            'rto_actual_seconds' => ['nullable', 'integer', 'min:0'],
            'rto_result' => ['nullable', 'in:pass,fail'],
            'rpo_target_seconds' => ['nullable', 'integer', 'min:0'],
            'rpo_actual_seconds' => ['nullable', 'integer', 'min:0'],
            'rpo_result' => ['nullable', 'in:pass,fail'],
            'overall_result' => ['required', 'in:pass,fail'],
            'executed_by' => ['nullable', 'string'],
            'executed_at' => ['required', 'date'],
        ])->validate();

        $validated['executed_at'] = $this->date->parse((string) $validated['executed_at']);

        return $validated;
    }

    private function recordedMessage(BackupDrillRun $run): string
    {
        $result = strtoupper((string) $run->overall_result);
        $message = __('messages.cli.drill_recorded', [
            'uuid' => $run->run_uuid,
            'result' => $result,
        ]);

        if ($message === 'messages.cli.drill_recorded') {
            return sprintf('Recorded backup drill run %s (overall: %s).', $run->run_uuid, $result);
        }

        return (string) $message;
    }
}
