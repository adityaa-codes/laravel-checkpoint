<?php

declare(strict_types=1);

it('encryption is inactive when password env is not set', function (): void {
    config()->set('checkpoint.encryption.password', null);

    expect(config('checkpoint.encryption.password'))->toBeNull();
});

it('encryption is active when password env is set', function (): void {
    config()->set('checkpoint.encryption.password', 'hunter2');

    expect(config('checkpoint.encryption.password'))->toBe('hunter2');
});
