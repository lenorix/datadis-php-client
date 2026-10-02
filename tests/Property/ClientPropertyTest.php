<?php

declare(strict_types=1);

use Eris\Generator;
use Eris\Generators;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Support\Decimal;
use Lenorix\DatadisClient\Tests\Support\Gen;
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

const ACCOUNT_FUZZ_KEYS = [
    'id', 'cups', 'status', 'ownerDocument', 'requesterDocument', 'validityDateStart', 'validityDateEnd',
    'distributorCodeFather', 'name', 'description', 'document', 'email', 'registrationDate', 'registerApp',
];

/**
 * A row with a random subset of the known keys, each holding a random value.
 *
 * @param  list<string>  $keys
 */
function junkRow(array $keys = FUZZ_KEYS): Generator
{
    return Generators::map(
        function (array $values) use ($keys): array {
            $row = [];
            foreach ($keys as $i => $key) {
                if ($values[$i] !== '__absent__') {
                    $row[$key] = $values[$i];
                }
            }

            return $row;
        },
        Generators::tuple(...array_map(fn () => Generators::oneOf(Generators::constant('__absent__'), junkValue()), $keys)),
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

$accountCalls = [
    'authorizations' => ['authorizations', fn ($c) => $c->listAuthorization()],
    'groups' => ['groups', fn ($c) => $c->getGroups()],
    'partner users' => ['users', fn ($c) => $c->partnerUserList()],
];

foreach ($accountCalls as $name => [$key, $call]) {
    it("only ever returns a result or a DatadisException for junk account rows ({$name})", function () use ($key, $call) {
        $this->limitTo(pbtIterations())
            ->forAll(Generators::seq(junkRow(ACCOUNT_FUZZ_KEYS)), Generators::bool())
            ->then(function (array $rows, bool $wrapped) use ($key, $call) {
                $s = Scenario::make(ApiVersion::V2);
                $s->http->queue(Responses::json((string) json_encode($wrapped ? [$key => $rows] : $rows, JSON_PARTIAL_OUTPUT_ON_ERROR)));

                try {
                    $result = $call($s->client);

                    expect($result->skippedRows + count($result->records))->toBe(count($rows));
                } catch (DatadisException $e) {
                    expect($e->requestSent)->toBeTrue();
                }
            });
    });
}

it('reads any partner agreement date answer as text, null or a DatadisException', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::oneOf(junkValue(), Generators::associative(['partnerAgreementDate' => junkValue()])))
        ->then(function (mixed $payload) {
            $s = Scenario::make(ApiVersion::V2);
            $s->http->queue(Responses::json((string) json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR)));

            try {
                $date = $s->client->partnerAgreementDate();

                expect($date === null || (is_string($date) && trim($date) !== ''))->toBeTrue();
            } catch (DatadisException $e) {
                expect($e->requestSent)->toBeTrue();
            }
        });
});

it('decodes valid account rows field by field, keeping each row as raw', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::seq(Generators::tuple(Gen::letters(6), Generators::choose(1577836800, 1893456000), Generators::choose(0, 999), Generators::bool())),
        )
        ->then(function (array $specs) {
            $zone = new DateTimeZone('Europe/Madrid');
            $authorizations = $users = $groups = [];

            foreach ($specs as $i => [$text, $seconds, $millis, $flag]) {
                $local = (new DateTimeImmutable('@'.$seconds))->setTimezone($zone);
                $authorizations[] = ['id' => "{$i}", 'ownerDocument' => 'A00000000', 'status' => $text, 'validityDateStart' => $local->format('Y-m-d H:i:s').'.0', 'validityDateEnd' => $local->format('Y/m/d')];
                $users[] = ['name' => $text, 'document' => 'A00000000', 'email' => null, 'registrationDate' => $seconds * 1000 + $millis, 'registerApp' => $flag];
                $groups[] = ['name' => "{$text}{$i}", 'description' => $flag ? $text : null];
            }

            $s = Scenario::make(ApiVersion::V2);
            $s->http->queue(
                Responses::json((string) json_encode($authorizations)),
                Responses::json((string) json_encode(['users' => $users])),
                Responses::json((string) json_encode(['groups' => $groups])),
            );
            $readAuthorizations = $s->client->listAuthorization()->records;
            $readUsers = $s->client->partnerUserList()->records;
            $readGroups = $s->client->getGroups()->records;

            expect(array_map(fn ($a) => $a->raw, $readAuthorizations))->toBe($authorizations)
                ->and(array_map(fn ($u) => $u->raw, $readUsers))->toBe($users)
                ->and(array_map(fn ($g) => $g->raw, $readGroups))->toBe($groups);

            foreach ($specs as $i => [$text, $seconds, , $flag]) {
                $local = (new DateTimeImmutable('@'.$seconds))->setTimezone($zone);

                expect($readAuthorizations[$i]->status)->toBe($text)
                    // The wall clock: without an offset, the hour repeated in October is ambiguous.
                    ->and($readAuthorizations[$i]->validityDateStart?->format('Y-m-d H:i:s'))->toBe($local->format('Y-m-d H:i:s'))
                    ->and($readAuthorizations[$i]->validityDateEnd?->format('Y-m-d H:i:s'))->toBe($local->format('Y-m-d').' 00:00:00')
                    ->and($readUsers[$i]->registrationDate?->getTimestamp())->toBe($seconds)
                    ->and($readUsers[$i]->registerApp)->toBe($flag)
                    ->and($readGroups[$i]->description)->toBe($flag ? $text : null);
            }
        });
});

/** A value in thousandths as Datadis may send it, and the text the client must give back with at least $scale decimals. */
function sentDecimal(int $thousandths, bool $asText, int $scale = 3): array
{
    $text = sprintf('%d.%03d', intdiv($thousandths, 1000), $thousandths % 1000);
    $expected = $scale === 2 && str_ends_with($text, '0') ? substr($text, 0, -1) : $text;

    return [$asText ? $text : $thousandths / 1000, $expected];
}

it('decodes valid supply, contract, consumption and maximum power rows exactly, keeping each row as raw', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::seq(Generators::tuple(
                Generators::choose(0, 99999999),
                Generators::bool(),
                Generators::choose(1, 28),
                Generators::choose(1, 24),
                Generators::choose(1, 5),
                Generators::elements('Real', 'Estimada'),
            )),
            Generators::elements(ApiVersion::V1, ApiVersion::V2),
        )
        ->then(function (array $specs, ApiVersion $version) {
            $zone = new DateTimeZone('Europe/Madrid');
            $supplies = $contracts = $readings = $peaks = $expected = [];

            foreach ($specs as $i => [$thousandths, $asText, $day, $hour, $pointType, $method]) {
                [$sent, $text] = sentDecimal($thousandths, $asText);
                [$power, $powerText] = sentDecimal($thousandths, $asText, 2);
                $date = sprintf('2025/11/%02d', $day);
                $supplies[] = ['cups' => Scenario::CUPS, 'validDateFrom' => $date, 'validDateTo' => $asText ? '' : '2026/01/01', 'pointType' => $pointType, 'distributorCode' => (string) $pointType];
                $contracts[] = ['cups' => Scenario::CUPS, 'contractedPowerkW' => array_fill(0, $pointType, $power), 'startDate' => $date, 'endDate' => ''];
                $readings[] = ['cups' => Scenario::CUPS, 'date' => $date, 'time' => sprintf('%02d:00', $hour), 'consumptionKWh' => $sent, 'obtainMethod' => $method, 'surplusEnergyKWh' => $asText ? null : $sent];
                $peaks[] = ['cups' => Scenario::CUPS, 'date' => $date, 'time' => sprintf('%02d:%02d', $hour - 1, ($i % 4) * 15), 'maxPower' => $sent, 'period' => (string) $pointType];
                $expected[] = [$text, $powerText, $date, $hour];
            }

            $body = fn (string $key, array $rows) => $version === ApiVersion::V1 ? (string) json_encode($rows) : Payloads::envelope($key, $rows);
            $s = Scenario::make($version);
            $s->http->queue(
                Responses::datadis($body('supplies', $supplies)),
                Responses::datadis($body('contract', $contracts)),
                Responses::datadis($body('timeCurve', $readings)),
                Responses::datadis($body('maxPower', $peaks)),
            );
            $cups = Cups::fromString(Scenario::CUPS);
            $readSupplies = $s->client->getSupplies()->records;
            $readContracts = $s->client->getContractDetail($cups, '2')->records;
            $readReadings = $s->client->getConsumptionData($cups, '2', 5, Month::of(2025, 11), Month::of(2025, 11))->records;
            $readPeaks = $s->client->getMaxPower($cups, '2', Month::of(2025, 11), Month::of(2025, 11))->records;

            // raw is the answer as received: a decimal JSON number arrives as its own text.
            $received = fn (array $rows) => array_map(fn (array $row) => array_map(fn ($v) => is_array($v) ? array_map(fn ($x) => is_float($x) ? Decimal::shortest($x) : $x, $v) : (is_float($v) ? Decimal::shortest($v) : $v), $row), $rows);

            expect(array_map(fn ($r) => $r->raw, $readSupplies))->toBe($received($supplies))
                ->and(array_map(fn ($r) => $r->raw, $readContracts))->toBe($received($contracts))
                ->and(array_map(fn ($r) => $r->raw, $readReadings))->toBe($received($readings))
                ->and(array_map(fn ($r) => $r->raw, $readPeaks))->toBe($received($peaks));

            $seen = [];

            foreach ($expected as $i => [$text, $powerText, $date, $hour]) {
                $midnight = new DateTimeImmutable(str_replace('/', '-', $date), $zone);
                [, $asText, , , $pointType, $method] = $specs[$i];
                // A label repeated on a day without a clock change has no interval of its own.
                $start = isset($seen["{$date} {$hour}"]) ? null : $midnight->modify('+'.($hour - 1).' hours')->getTimestamp();
                $seen["{$date} {$hour}"] = true;

                expect($readSupplies[$i]->pointType)->toBe($pointType)
                    ->and($readSupplies[$i]->validDateFrom?->format('Y/m/d'))->toBe($date)
                    ->and($readSupplies[$i]->validDateTo?->format('Y/m/d'))->toBe($asText ? null : '2026/01/01')
                    ->and($readContracts[$i]->contractedPowerkW)->toBe(array_fill(0, $pointType, $powerText))
                    ->and($readReadings[$i]->consumptionKWh)->toBe($text)
                    ->and($readReadings[$i]->surplusEnergyKWh)->toBe($asText ? null : $text)
                    ->and($readReadings[$i]->obtainMethod)->toBe($method)
                    ->and($readReadings[$i]->start?->getTimestamp())->toBe($start)
                    ->and($readReadings[$i]->hasValidTime() ? $readReadings[$i]->end->getTimestamp() - $start : null)->toBe($start === null ? null : 3600)
                    ->and($readPeaks[$i]->maxPower)->toBe($text)
                    ->and($readPeaks[$i]->instant?->format('Y/m/d H:i'))->toBe($date.' '.$peaks[$i]['time']);
            }
        });
});
