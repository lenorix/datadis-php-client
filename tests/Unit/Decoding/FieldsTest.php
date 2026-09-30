<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Decoding\Fields;

it('reads text from strings, integers and floats, and nothing else', function (mixed $value, ?string $expected) {
    expect(Fields::text(['k' => $value], 'k'))->toBe($expected);
})->with([
    ['abc', 'abc'], [7, '7'], [1.5, '1.5'], [2.0, '2'], [INF, null], [true, null], [['x'], null], [null, null],
]);

it('falls back to the next key', function () {
    expect(Fields::text(['b' => 'second'], 'a', 'b'))->toBe('second')
        ->and(Fields::text(['a' => 1.5, 'b' => 'second'], 'a', 'b'))->toBe('1.5');
});

it('treats blank text as absent', function () {
    expect(Fields::nonEmptyText(['k' => '   '], 'k'))->toBeNull()
        ->and(Fields::nonEmptyText(['k' => ' x '], 'k'))->toBe(' x ');
});

it('reads decimals and ignores empty or non numeric values', function (mixed $value, ?string $expected) {
    expect(Fields::decimal(['k' => $value], 2, 'k'))->toBe($expected);
})->with([['', null], ['abc', null], [null, null], ['1.5', '1.50'], [2, '2.00'], [true, null]]);

it('reads integers only when they are whole', function (mixed $value, ?int $expected) {
    expect(Fields::integer(['k' => $value], 'k'))->toBe($expected);
})->with([[5, 5], [5.0, 5], [5.5, null], [INF, null], [NAN, null], [' 7 ', 7], ['-3', -3], ['7.0', null], ['x', null], [null, null]]);

it('reads dates with surrounding spaces and ignores empty ones', function () {
    $zone = new DateTimeZone('Europe/Madrid');

    expect(Fields::date(['k' => ' 2026/01/02 '], $zone, 'k')?->format('Y-m-d'))->toBe('2026-01-02')
        ->and(Fields::date(['k' => ''], $zone, 'k'))->toBeNull();
});
