<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\DistributorError;

it('tells "no data in the period" from a failure, however the code is written', function (mixed $code, bool $noData) {
    expect(DistributorError::fromRow(['distributorCode' => '2', 'errorCode' => $code])->isNoData())->toBe($noData);
})->with([
    'as captured' => ['8', true],
    'as a number' => [8, true],
    'padded' => [' 08', true],
    'another code' => ['15', false],
    'containing an 8' => ['18', false],
    'ending in a zero' => ['80', false],
    'none' => [null, false],
]);
