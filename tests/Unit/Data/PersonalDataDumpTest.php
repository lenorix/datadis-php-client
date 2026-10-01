<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\Authorization;
use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Data\ContractDetail;
use Lenorix\DatadisClient\Data\DistributorError;
use Lenorix\DatadisClient\Data\Group;
use Lenorix\DatadisClient\Data\MaxPowerReading;
use Lenorix\DatadisClient\Data\PartnerUser;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\MeasurementType;

/** What var_dump, debug_zval_dump and print_r show of a value. */
function dumpsOf(mixed $value): string
{
    ob_start();
    var_dump($value);
    debug_zval_dump($value);

    return (string) ob_get_clean().print_r($value, true);
}

$madrid = new DateTimeZone('Europe/Madrid');

it('leaves the personal fields of every record out of var_dump and print_r, and keeps them readable', function (Closure $build, array $personal, Closure $read) use ($madrid) {
    $record = $build($madrid);
    $dumps = dumpsOf($record).dumpsOf(new ApiResult([$record], raw: ['echo' => $personal]));

    foreach ($personal as $value) {
        expect($dumps)->not->toContain($value);
    }

    expect($dumps)->toContain('[hidden]')->and($read($record))->toBe($personal[0]);
})->with([
    'supply' => [
        fn ($zone) => Supply::fromRow(['cups' => Scenario::CUPS, 'address' => 'CALLE FALSA 1', 'postalCode' => '00001', 'distributorCode' => '2', 'pointType' => 5], $zone),
        [Scenario::CUPS, 'CALLE FALSA 1', '00001'],
        fn (Supply $s) => $s->cups,
    ],
    'contract' => [
        fn ($zone) => ContractDetail::fromRow(['cups' => Scenario::CUPS, 'postalCode' => '00001', 'cau' => 'ES0000000000000000AA0A000'], $zone),
        [Scenario::CUPS, '00001', 'ES0000000000000000AA0A000'],
        fn (ContractDetail $c) => $c->cups,
    ],
    'consumption' => [
        fn ($zone) => ConsumptionReading::fromRow(['cups' => Scenario::CUPS, 'date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => 1, 'obtainMethod' => 'Real'], $zone, MeasurementType::Hourly),
        [Scenario::CUPS],
        fn (ConsumptionReading $r) => $r->cups,
    ],
    'maximum power' => [
        fn ($zone) => MaxPowerReading::fromRow(['cups' => Scenario::CUPS, 'date' => '2026/01/01', 'time' => '01:00', 'maxPower' => 1], $zone),
        [Scenario::CUPS],
        fn (MaxPowerReading $r) => $r->cups,
    ],
    'reactive energy' => [
        fn () => ReactiveEnergy::fromRow(['cups' => Scenario::CUPS, 'energy' => []]),
        [Scenario::CUPS],
        fn (ReactiveEnergy $r) => $r->cups,
    ],
    'authorization' => [
        fn ($zone) => Authorization::fromRow(['ownerDocument' => '00000000T', 'requesterDocument' => 'A00000000', 'cups' => Scenario::CUPS, 'status' => 'ACTIVE'], $zone),
        ['00000000T', 'A00000000', Scenario::CUPS],
        fn (Authorization $a) => $a->ownerDocument,
    ],
    'partner user' => [
        fn ($zone) => PartnerUser::fromRow(['name' => 'NOMBRE DE PRUEBA', 'document' => '00000000T', 'email' => 'aaaa@aaaa.aa'], $zone),
        ['NOMBRE DE PRUEBA', '00000000T', 'aaaa@aaaa.aa'],
        fn (PartnerUser $u) => $u->name,
    ],
    'group' => [
        fn () => Group::fromRow(['name' => 'GRUPO DE PRUEBA', 'description' => 'DESCRIPCION DE PRUEBA']),
        ['GRUPO DE PRUEBA', 'DESCRIPCION DE PRUEBA'],
        fn (Group $g) => $g->name,
    ],
    'distributor error' => [
        fn () => DistributorError::fromRow(['errorCode' => '50', 'errorDescription' => 'boom', 'echo' => Scenario::CUPS]),
        [Scenario::CUPS],
        fn (DistributorError $e) => $e->raw['echo'],
    ],
]);

it('still shows the fields that are not personal, and a missing personal field as null', function () use ($madrid) {
    $dumps = dumpsOf(Supply::fromRow(['cups' => Scenario::CUPS, 'province' => 'Madrid', 'distributorCode' => '2', 'pointType' => 5], $madrid));

    expect($dumps)->toContain('Madrid')->toContain('distributorCode')->toMatch('/\["address"\]=>\s+NULL/');
});
