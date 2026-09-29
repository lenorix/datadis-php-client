<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\PersonalDataRedactor;

it('redacts CUPS, NIF, NIE and CIF by shape', function (string $identifier) {
    $text = "Datadis rejected value {$identifier} for the request";

    expect(PersonalDataRedactor::redact($text))
        ->not->toContain($identifier)
        ->toContain('[redacted]');
})->with([
    'CUPS 20' => 'ES0031300000000001JN',
    'CUPS 22' => 'ES0031300000000001JN0F',
    'CUPS lower' => 'es0031300000000001jn',
    'NIF' => '12345678Z',
    'NIF lower' => '12345678z',
    'NIE' => 'X1234567L',
    'CIF' => 'A12345678',
]);

it('leaves text without identifiers untouched', function () {
    expect(PersonalDataRedactor::redact('Consulta ya realizada en las últimas 24 horas'))
        ->toBe('Consulta ya realizada en las últimas 24 horas');
});

it('is idempotent', function () {
    $once = PersonalDataRedactor::redact('a ES0031300000000001JN0F b 12345678Z');

    expect(PersonalDataRedactor::redact($once))->toBe($once);
});

it('collapses whitespace and caps the excerpt after redacting', function () {
    $body = "line one\n\n   ES0031300000000001JN   ".str_repeat('x', 400);
    $excerpt = PersonalDataRedactor::excerpt($body, 50);

    expect($excerpt)->not->toContain('ES0031300000000001JN')
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(50)
        ->and($excerpt)->toStartWith('line one [redacted] xxx');
});

it('handles empty and multibyte input', function () {
    expect(PersonalDataRedactor::excerpt('', 10))->toBe('')
        ->and(PersonalDataRedactor::excerpt('ñandú EDISTRIBUCIÓN', 8))->toBe('ñandú ED');
});

it('redacts a JWT that an error body might echo', function () {
    $token = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ1c2VyIn0.signature';

    expect(PersonalDataRedactor::redact("Invalid token {$token} supplied"))
        ->not->toContain($token)
        ->toBe('Invalid token [redacted] supplied');
});
