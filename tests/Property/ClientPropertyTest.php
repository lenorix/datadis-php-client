<?php

declare(strict_types=1);

use Eris\Generator;
use Eris\Generators;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

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
    'supplies' => fn ($c) => $c->supplies(),
    'contract' => fn ($c) => $c->contractDetail(Cups::fromString(Scenario::CUPS), '2'),
    'consumption' => fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 1)),
    'quarter-hourly' => fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 1, Month::of(2026, 1), Month::of(2026, 1), MeasurementType::QuarterHourly),
    'max power' => fn ($c) => $c->maxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), Month::of(2026, 1)),
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
                $s->http->queue(Responses::json($body));

                try {
                    $result = $call($s->client);

                    expect($result->skippedRows + count($result->records))->toBe(count($rows));
                } catch (DatadisException $e) {
                    expect($e->requestSent)->toBeTrue();
                }
            });
    });
}

it('decodes every valid hourly row, in order, whatever the day shape', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::elements(Payloads::normalDay(), Payloads::autumnDay(), Payloads::springDay()),
            Generators::choose(0, 20),
        )
        ->then(function (array $times, int $nullRows) {
            $rows = Payloads::hourlyRows('2025/10/26', $times);
            for ($i = 0; $i < $nullRows && $i < count($rows); $i++) {
                $rows[$i]['consumptionKWh'] = null;
            }

            $s = Scenario::make();
            $s->http->queue(Responses::json(Payloads::envelope('timeCurve', $rows)));
            $expectedUsable = max(0, count($rows) - min($nullRows, count($rows)));

            if ($expectedUsable === 0) {
                expect(fn () => $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2025, 10), Month::of(2025, 10)))
                    ->toThrow(DatadisException::class);

                return;
            }

            $result = $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2025, 10), Month::of(2025, 10));

            expect($result->records)->toHaveCount($expectedUsable)
                ->and($result->skippedRows)->toBe(count($rows) - $expectedUsable)
                ->and(array_map(fn ($r) => $r->time, $result->records))->toBe(array_slice($times, count($rows) - $expectedUsable));
        });
});

it('sends authorizedNif exactly when it differs from the account', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::elements('12345678Z', ' 12345678z', '12345678Z ', '87654321X', 'X1234567L', ' x1234567l '))
        ->then(function (string $nif) {
            $s = Scenario::make();
            $s->http->queue(Responses::json('{"supplies":[],"distributorError":[]}'));

            $s->client->supplies(Nif::fromString($nif));

            $sent = $s->query()['authorizedNif'] ?? null;
            $isOwn = strtoupper(trim($nif)) === '12345678Z';

            expect($sent)->toBe($isOwn ? null : strtoupper(trim($nif)));
        });
});

it('never sends a request when the month range is invalid', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(2020, 2028), Generators::choose(1, 12), Generators::choose(2020, 2028), Generators::choose(1, 12))
        ->then(function (int $y1, int $m1, int $y2, int $m2) {
            $from = Month::of($y1, $m1);
            $to = Month::of($y2, $m2);
            $now = new DateTimeImmutable('2026-09-15');
            $valid = ! $from->isAfter($to) && $from->isWithinHistory($now) && $to->isWithinHistory($now);

            $s = Scenario::make();
            $s->http->queue(Responses::json('{"maxPower":[],"distributorError":[]}'));

            try {
                $s->client->maxPower(Cups::fromString(Scenario::CUPS), '2', $from, $to);
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
            $s->http->queue(Responses::json((string) json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR)));

            try {
                $result = $reactive
                    ? $s->client->reactive(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), Month::of(2026, 1))
                    : $s->client->distributors();

                expect($result->records)->toBeArray();
            } catch (DatadisException $e) {
                expect($e->requestSent)->toBeTrue();
            }
        });
});
