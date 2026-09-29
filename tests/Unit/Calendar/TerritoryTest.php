<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Calendar\Territory;

it('derives the territory from the province in the postal code', function (?string $postalCode, ?Territory $expected) {
    expect(Territory::fromPostalCode($postalCode))->toBe($expected);
})->with([
    ['28001', Territory::Peninsula],
    ['01001', Territory::Peninsula],
    ['50001', Territory::Peninsula],
    ['07001', Territory::Baleares],
    ['35001', Territory::Canarias],
    ['38001', Territory::Canarias],
    ['51001', Territory::Ceuta],
    ['52001', Territory::Melilla],
    ['53001', null],
    ['00001', null],
    ['2800', null],
    ['280011', null],
    [' 28001', Territory::Peninsula],
    ['ABCDE', null],
    ['', null],
    [null, null],
]);

it('knows the civil time zone of each territory', function () {
    expect(Territory::Canarias->timeZone()->getName())->toBe('Atlantic/Canary')
        ->and(Territory::Peninsula->timeZone()->getName())->toBe('Europe/Madrid')
        ->and(Territory::Baleares->timeZone()->getName())->toBe('Europe/Madrid')
        ->and(Territory::Ceuta->timeZone()->getName())->toBe('Europe/Madrid')
        ->and(Territory::Melilla->timeZone()->getName())->toBe('Europe/Madrid');
});
