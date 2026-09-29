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
        ->and($authorization->validFrom?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($authorization->validTo?->format('Y-m-d'))->toBe('2027-12-31')
        ->and($authorization->distributorCodeFather)->toBe('2');
});

it('accepts dashed dates, string ids and open ends', function () use ($zone, $rows) {
    $authorization = Authorization::fromRow($rows()[1], $zone);

    expect($authorization->id)->toBe('1235')
        ->and($authorization->validFrom?->format('Y-m-d'))->toBe('2024-05-01')
        ->and($authorization->validTo)->toBeNull()
        ->and($authorization->distributorCodeFather)->toBeNull();
});

it('rejects a row without any identifying field', function () use ($zone) {
    expect(Authorization::fromRow(['status' => 'x'], $zone))->toBeNull();
});
