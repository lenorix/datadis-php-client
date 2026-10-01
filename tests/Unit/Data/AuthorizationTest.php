<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Authorization;

$zone = new DateTimeZone('Europe/Madrid');
$rows = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/v1/list-authorization.json'), true);

it('decodes a real authorization row', function () use ($zone, $rows) {
    $authorization = Authorization::fromRow($rows()[0], $zone);

    expect($authorization->id)->toBe('1')
        ->and($authorization->ownerDocument)->toBe('00000000T')
        ->and($authorization->requesterDocument)->toBe('A00000000')
        ->and($authorization->cups)->toBe('ES0000000000000000AA')
        ->and($authorization->status)->toBe('CANCELADA')
        ->and($authorization->validityDateStart?->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00')
        ->and($authorization->validityDateEnd?->format('Y-m-d H:i:s'))->toBe('2028-01-01 23:59:59')
        ->and($authorization->distributorCodeFather)->toBe('0021');
});

it('reads a validity date without a time of day, and none for an impossible time', function (string $value, ?string $expected) use ($zone) {
    expect(Authorization::fromRow(['id' => 1, 'validityDateEnd' => $value], $zone)->validityDateEnd?->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    ['2027-12-31', '2027-12-31 00:00:00'],
    ['2027/12/31', '2027-12-31 00:00:00'],
    ['2027-12-31 24:00:00.0', null],
    ['', null],
]);

it('rejects a row without any identifying field', function () use ($zone) {
    expect(Authorization::fromRow(['status' => 'x'], $zone))->toBeNull();
});

it('accepts a row identified by any one of its fields', function (array $row) use ($zone) {
    expect(Authorization::fromRow($row, $zone))->not->toBeNull();
})->with([[['id' => 1]], [['ownerDocument' => 'A00000000']], [['requesterDocument' => '00000000T']]]);

it('reads dates with surrounding spaces', function () use ($zone) {
    expect(Authorization::fromRow(['id' => 1, 'validityDateStart' => ' 2026/01/01 '], $zone)->validityDateStart?->format('Y-m-d'))->toBe('2026-01-01');
});
