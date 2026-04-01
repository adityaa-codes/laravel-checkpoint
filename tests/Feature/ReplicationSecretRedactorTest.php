<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Services\ReplicationSecretRedactor;

it('redacts secrets from replication dsn and key value strings', function (): void {
    $redactor = new ReplicationSecretRedactor;

    expect($redactor->redact('pgsql://user:supersecret@db.internal/source'))
        ->toBe('pgsql://[REDACTED]@db.internal')
        ->and($redactor->redact('engine=pgsql,host=db.internal,password=secret,token=abc123'))
        ->toBe('engine=pgsql,host=db.internal,password=[REDACTED],token=[REDACTED]');
});
