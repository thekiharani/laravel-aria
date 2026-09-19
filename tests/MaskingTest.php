<?php

declare(strict_types=1);

use NoriaLabs\Aria\Middleware\MaskSensitiveData;

it('redacts an email address before the prompt leaves', function (): void {
    $patterns = config('aria.masking.patterns');

    expect(MaskSensitiveData::mask('write to joseph@noria.co.ke today', $patterns))
        ->toContain('[EMAIL REDACTED]')
        ->not->toContain('joseph@noria.co.ke');
});

it('redacts something shaped like a card number', function (): void {
    $patterns = config('aria.masking.patterns');

    expect(MaskSensitiveData::mask('card 4111 1111 1111 1111 please', $patterns))
        ->toContain('[CARD REDACTED]')
        ->not->toContain('4111');
});

it('leaves ordinary text alone', function (): void {
    $patterns = config('aria.masking.patterns');

    expect(MaskSensitiveData::mask('reconcile the month end', $patterns))
        ->toBe('reconcile the month end');
});

it('does nothing when the host configured no patterns', function (): void {
    expect(MaskSensitiveData::mask('joseph@noria.co.ke', []))->toBe('joseph@noria.co.ke');
});
