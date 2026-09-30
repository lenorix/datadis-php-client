<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Authorization;

$zone = new DateTimeZone('Europe/Madrid');
$rows = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/v1/list-authorization.json'), true);

it('decodes an authorization', function () use ($zone, $rows) {
    $authorization = Authorization::fromRow($rows()[0], $zone);

    expect($authorization->id)->toBe('1234')
        ->and($authorization->ownerDocument)->toBe('12345678Z')
        ->and($authorization->requesterDocument)->toBe('87654321X')
        ->and($authorization->status)->toBe('ACTIVE')
        ->and($authorization->validityDateStart?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($authorization->validityDateEnd?->format('Y-m-d'))->toBe('2027-12-31')
        ->and($authorization->distributorCodeFather)->toBe('2');
});

it('accepts dashed dates, string ids and open ends', function () use ($zone, $rows) {
    $authorization = Authorization::fromRow($rows()[1], $zone);

    expect($authorization->id)->toBe('1235')
        ->and($authorization->validityDateStart?->format('Y-m-d'))->toBe('2024-05-01')
        ->and($authorization->validityDateEnd)->toBeNull()
        ->and($authorization->distributorCodeFather)->toBeNull();
});

it('rejects a row without any identifying field', function () use ($zone) {
    expect(Authorization::fromRow(['status' => 'x'], $zone))->toBeNull();
});

it('accepts a row identified by any one of its fields', function (array $row) use ($zone) {
    expect(Authorization::fromRow($row, $zone))->not->toBeNull();
})->with([[['id' => 1]], [['ownerDocument' => '12345678Z']], [['requesterDocument' => '87654321X']]]);

it('reads dates with surrounding spaces', function () use ($zone) {
    expect(Authorization::fromRow(['id' => 1, 'validityDateStart' => ' 2026/01/01 '], $zone)->validityDateStart?->format('Y-m-d'))->toBe('2026-01-01');
});
