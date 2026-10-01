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
