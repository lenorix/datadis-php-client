<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\DistributorError;

it('tells "no data in the period" from a failure, however the code is written', function (mixed $code, bool $noData) {
    expect(DistributorError::fromRow(['distributorCode' => '2', 'errorCode' => $code, 'errorDescription' => 'No existen datos en el periodo solicitado'])->isNoData())->toBe($noData);
})->with([
    'as captured' => ['8', true],
    'as a number' => [8, true],
    'padded' => [' 08', true],
    'another code' => ['15', false],
    'containing an 8' => ['18', false],
    'ending in a zero' => ['80', false],
    'none' => [null, false],
]);

it('takes code 8 as "no data" only with the description it was seen with, since codes are each distributor\'s', function (?string $description, bool $noData) {
    expect(DistributorError::fromRow(['distributorCode' => '2', 'errorCode' => '8', 'errorDescription' => $description])->isNoData())->toBe($noData);
})->with([
    'as captured' => ['No existen datos en el periodo solicitado', true],
    'in another case, with spaces' => ['  NO EXISTEN  DATOS en el periodo', true],
    'another meaning of the same code' => ['Error interno distribuidora', false],
    'a text that only mentions it' => ['Fallo: no existen datos de contrato', false],
    'no description' => [null, false],
]);
