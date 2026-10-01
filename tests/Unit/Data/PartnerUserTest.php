<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\PartnerUser;

it('skips a row without a name or a document and reads what it can of the rest', function () {
    $zone = new DateTimeZone('Europe/Madrid');

    expect(PartnerUser::fromRow(['email' => 'aaaa@aaaa.aa'], $zone))->toBeNull()
        ->and(PartnerUser::fromRow(['document' => 'A00000000', 'registrationDate' => 'soon', 'registerApp' => 'yes'], $zone))
        ->registrationDate->toBeNull()
        ->registerApp->toBeNull();
});

it('reads a user with only a name or only a document', function (array $row) {
    expect(PartnerUser::fromRow($row, new DateTimeZone('Europe/Madrid')))->not->toBeNull();
})->with([
    'only a name' => [['name' => 'EMPRESA A']],
    'only a document' => [['document' => 'A00000000']],
]);
