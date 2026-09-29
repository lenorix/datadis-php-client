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

it('redacts identifiers written with separators or glued to a label', function (string $text, string $identifierDigits) {
    expect(PersonalDataRedactor::redact($text))->not->toContain($identifierDigits);
})->with([
    ['NIF 12345678-Z rejected', '12345678'],
    ['NIF 12345678 Z rejected', '12345678'],
    ['NIE X-1234567-L rejected', '1234567'],
    ['NIF12345678Z', '12345678'],
    ['nie:Y1234567L,', '1234567'],
    ['CUPS=ES0031300000000001JN0Fend', '0031300000000001'],
]);

it('redacts everything when the pattern engine gives up, rather than leak', function () {
    $previous = ini_set('pcre.backtrack_limit', '1');
    $previousJit = ini_set('pcre.jit', '0');

    try {
        expect(PersonalDataRedactor::redact('value ES0031300000000001JN0F and 12345678Z'))->toBe(PersonalDataRedactor::PLACEHOLDER);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $previous);
        ini_set('pcre.jit', (string) $previousJit);
    }
});

it('keeps redacting until nothing identifier-shaped is left', function () {
    expect(PersonalDataRedactor::redact('12345678Z12345678Z'))->toBe('[redacted][redacted]');
});

it('writes excerpts of 300 characters by default, trimmed, and nothing for a negative length', function () {
    expect(mb_strlen(PersonalDataRedactor::excerpt(str_repeat('x', 400))))->toBe(300)
        ->and(PersonalDataRedactor::excerpt("  x  \n"))->toBe('x')
        ->and(PersonalDataRedactor::excerpt('abc', -5))->toBe('');
});
