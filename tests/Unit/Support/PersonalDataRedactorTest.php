<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\PersonalDataRedactor;

it('redacts CUPS, NIF, NIE and CIF by shape', function (string $identifier) {
    $text = "Datadis rejected value {$identifier} for the request";

    expect(PersonalDataRedactor::redact($text))
        ->not->toContain($identifier)
        ->toContain('[redacted]');
})->with([
    'CUPS 20' => 'ES0000000000000000AA',
    'CUPS 22' => 'ES0000000000000000AA0A',
    'CUPS lower' => 'es0000000000000000aa',
    'NIF' => 'A00000000',
    'NIF lower' => 'a00000000',
    'NIE' => 'X0000000A',
    'CIF' => 'A00000000',
    'NIF K' => 'K0000000T',
    'NIF L' => 'L0000000T',
    'NIF M' => 'M0000000T',
]);

it('leaves text without identifiers untouched', function () {
    expect(PersonalDataRedactor::redact('Consulta ya realizada en las últimas 24 horas'))
        ->toBe('Consulta ya realizada en las últimas 24 horas');
});

it('is idempotent', function () {
    $once = PersonalDataRedactor::redact('a ES0000000000000000AA0A b A00000000');

    expect(PersonalDataRedactor::redact($once))->toBe($once);
});

it('collapses whitespace and caps the excerpt after redacting', function () {
    $body = "line one\n\n   ES0000000000000000AA   ".str_repeat('x', 400);
    $excerpt = PersonalDataRedactor::excerpt($body, 50);

    expect($excerpt)->not->toContain('ES0000000000000000AA')
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
    ['NIF 00000000-A rejected', '00000000'],
    ['NIF 00000000 A rejected', '00000000'],
    ['NIE X-0000000-A rejected', '0000000'],
    ['NIF00000000A', '00000000'],
    ['nie:Y0000000A,', '0000000'],
    ['CUPS=ES0000000000000000AA0Aend', '0000000000000000'],
]);

it('redacts everything when the pattern engine gives up, rather than leak', function () {
    $previous = ini_set('pcre.backtrack_limit', '1');
    $previousJit = ini_set('pcre.jit', '0');

    try {
        expect(PersonalDataRedactor::redact('value ES0000000000000000AA0A and A00000000'))->toBe(PersonalDataRedactor::PLACEHOLDER);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $previous);
        ini_set('pcre.jit', (string) $previousJit);
    }
});

it('keeps redacting until nothing identifier-shaped is left', function () {
    expect(PersonalDataRedactor::redact('00000000A00000000A'))->toBe('[redacted][redacted]');
});

it('writes excerpts of 300 characters by default, trimmed, and nothing for a negative length', function () {
    expect(mb_strlen(PersonalDataRedactor::excerpt(str_repeat('x', 400))))->toBe(300)
        ->and(PersonalDataRedactor::excerpt("  x  \n"))->toBe('x')
        ->and(PersonalDataRedactor::excerpt('abc', -5))->toBe('');
});

it('redacts identifiers whatever the whitespace inside them, and an excerpt stays redacted', function (string $text) {
    $excerpt = PersonalDataRedactor::excerpt($text);

    expect(PersonalDataRedactor::redact($text))->not->toMatch('/\d{7}/')
        ->and($excerpt)->not->toMatch('/\d{7}/')
        ->and(PersonalDataRedactor::redact($excerpt))->toBe($excerpt);
})->with([
    'NIF with two spaces' => ['Titular 00000000  A sin permiso'],
    'NIF with a tab' => ["Titular 00000000\tA"],
    'NIE with spaces' => ['NIE X  0000000  A'],
    'CIF glued to its label' => ['Sin permiso para CIFA0000000A'],
]);
