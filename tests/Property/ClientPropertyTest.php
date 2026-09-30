<?php

declare(strict_types=1);

use Eris\Generator;
use Eris\Generators;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;

const FUZZ_KEYS = [
    'cups', 'date', 'time', 'consumptionKWh', 'obtainMethod', 'surplusEnergyKWh', 'generationEnergyKWh',
    'selfConsumptionEnergyKWh', 'maxPower', 'period', 'distributorCode', 'pointType', 'validDateFrom',
    'validDateTo', 'contractedPowerkW', 'dateOwner', 'startDate', 'endDate', 'accessFare', 'installedCapacity',
    'partitionCoefficient', 'maxPowerInstall', 'energy', 'code', 'distributorName', 'errorCode',
];

/** Any JSON-ish value, including the awkward ones (empty strings, nested arrays, numbers as strings). */
function junkValue(): Generator
{
    return Generators::oneOf(
        Generators::int(),
        Generators::float(),
        Generators::string(),
        Generators::bool(),
        Generators::constant(null),
        Generators::constant(''),
        Generators::constant([]),
        Generators::constant(['a' => 1]),
        Generators::constant([1, 'x', null]),
        Generators::elements('2026/01/01', '2026/13/01', '01:00', '24:00', '00:00', '1', 'P3', '4.5', '1e5', '-0'),
    );
}

/** A row with a random subset of the known keys, each holding a random value. */
function junkRow(): Generator
{
    return Generators::map(
        function (array $values): array {
            $row = [];
            foreach (FUZZ_KEYS as $i => $key) {
                if ($values[$i] !== '__absent__') {
                    $row[$key] = $values[$i];
                }
            }

            return $row;
        },
        Generators::tuple(...array_map(fn () => Generators::oneOf(Generators::constant('__absent__'), junkValue()), FUZZ_KEYS)),
    );
}

$calls = [
    'supplies' => fn ($c) => $c->getSupplies(),
    'contract' => fn ($c) => $c->getContractDetail(Cups::fromString(Scenario::CUPS), '2'),
    'consumption' => fn ($c) => $c->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 1)),
    'quarter-hourly' => fn ($c) => $c->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 1, Month::of(2026, 1), Month::of(2026, 1), MeasurementType::QuarterHourly),
    'max power' => fn ($c) => $c->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), Month::of(2026, 1)),
];

$keys = ['supplies' => 'supplies', 'contract' => 'contract', 'consumption' => 'timeCurve', 'quarter-hourly' => 'timeCurve', 'max power' => 'maxPower'];

foreach ($calls as $name => $call) {
    it("only ever returns a result or a DatadisException for junk rows ({$name})", function () use ($call, $keys, $name) {
        $this->limitTo(pbtIterations())
            ->forAll(Generators::seq(junkRow()), Generators::elements(ApiVersion::V1, ApiVersion::V2))
            ->then(function (array $rows, ApiVersion $version) use ($call, $keys, $name) {
                $s = Scenario::make($version);
                $body = $version === ApiVersion::V1
                    ? json_encode($rows, JSON_PARTIAL_OUTPUT_ON_ERROR)
                    : json_encode([$keys[$name] => $rows, 'distributorError' => []], JSON_PARTIAL_OUTPUT_ON_ERROR);
                $s->http->queue(Responses::datadis($body));

                try {
                    $result = $call($s->client);

                    expect($result->skippedRows + count($result->records))->toBe(count($rows));
                } catch (DatadisException $e) {
                    expect($e->requestSent)->toBeTrue();
                }
            });
    });
}

it('decodes every row with a value, in order, and fails only when no row has one', function () {
    $days = [
        'normal' => ['2025/10/15', Payloads::normalDay()],
        'autumn change' => ['2025/10/26', Payloads::autumnDay()],
        'spring change' => ['2026/03/29', Payloads::springDay()],
    ];

    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::elements(...array_keys($days)),
            // Which rows come with a null value; all of them now and then, which random flags never give.
            Generators::oneOf(Generators::vector(25, Generators::bool()), Generators::constant(array_fill(0, 25, true))),
        )
        ->then(function (string $shape, array $isNull) use ($days) {
            [$date, $times] = $days[$shape];
            $rows = Payloads::hourlyRows($date, $times);
            $kept = [];

            foreach ($rows as $i => $row) {
                if ($isNull[$i]) {
                    $rows[$i]['consumptionKWh'] = null;
                } else {
                    $kept[] = $row['time'];
                }
            }

            [$year, $month] = array_map('intval', explode('/', $date));
            $s = Scenario::make();
            $s->http->queue(Responses::datadis(Payloads::envelope('timeCurve', $rows)));
            $call = fn () => $s->client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of($year, $month), Month::of($year, $month));

            if ($kept === []) {
                expect($call)->toThrow(DatadisException::class);

                return;
            }

            $result = $call();

            expect(array_map(fn ($r) => $r->time, $result->records))->toBe($kept)
                ->and($result->skippedRows)->toBe(count($rows) - count($kept));
        });
});

it('never sends a request for a month range Datadis would refuse', function () {
    // "Now" is 2026-09-15 in Madrid: Datadis serves 2024/10 to 2026/09.
    $oldest = 2024 * 12 + 10;
    $newest = 2026 * 12 + 9;

    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(2023, 2027), Generators::choose(1, 12), Generators::choose(2023, 2027), Generators::choose(1, 12))
        ->then(function (int $y1, int $m1, int $y2, int $m2) use ($oldest, $newest) {
            $first = $y1 * 12 + $m1;
            $last = $y2 * 12 + $m2;
            $valid = $first <= $last && $first >= $oldest && $last <= $newest;

            $s = Scenario::make();
            $s->http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));

            try {
                $s->client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of($y1, $m1), Month::of($y2, $m2));
                expect($valid)->toBeTrue()->and($s->http->requests())->toHaveCount(2);
            } catch (DatadisException $e) {
                expect($valid)->toBeFalse()->and($e->requestSent)->toBeFalse()->and($s->http->requests())->toHaveCount(0);
            }
        });
});

it('reads any reactive or distributors payload as a result or a DatadisException', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::oneOf(
                junkValue(),
                Generators::seq(junkValue()),
                Generators::associative(['reactiveEnergy' => Generators::oneOf(junkValue(), junkRow(), Generators::seq(junkRow())), 'distributorError' => junkValue()]),
                Generators::associative(['distExistenceUser' => Generators::oneOf(junkValue(), Generators::seq(junkValue())), 'distributorError' => junkValue()]),
                Generators::associative(['distributorCodes' => Generators::oneOf(junkValue(), Generators::seq(junkValue()))]),
                Generators::associative(['energy' => Generators::seq(junkRow())]),
            ),
            Generators::bool(),
        )
        ->then(function (mixed $payload, bool $reactive) {
            $s = Scenario::make();
            $s->http->queue(Responses::datadis((string) json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR)));

            try {
                if ($reactive) {
                    expect($s->client->getReactiveData(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), Month::of(2026, 1))->records)
                        ->each->toBeInstanceOf(ReactiveEnergy::class);
                } else {
                    expect($s->client->getDistributorsWithSupplies()->records)->each->toBeString();
                }
            } catch (DatadisException $e) {
                expect($e->requestSent)->toBeTrue();
            }
        });
});
