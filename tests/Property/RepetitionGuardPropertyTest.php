<?php

declare(strict_types=1);

use Eris\Generators;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

it('sends a guarded query only when the model says the window is free', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::seq(Generators::tuple(
            Generators::choose(0, 3),          // which query
            Generators::choose(0, 4),          // how Datadis answers
            Generators::choose(0, 30 * 3600),  // seconds to wait before the call
        )))
        ->then(function (array $steps) {
            $clock = new FrozenClock(new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('Europe/Madrid')));
            $http = new FakeHttpClient;
            $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
            $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);
            $http->queue(Responses::text(Tokens::jwt(['exp' => $clock->now()->getTimestamp() + 365 * 86400])));

            $success = ['{"timeCurve":[],"distributorError":[]}', '{"maxPower":[],"distributorError":[]}', '{"reactiveEnergy":{},"distributorError":[]}', '{"maxPower":[],"distributorError":[]}'];
            $answers = [
                fn (int $call) => Responses::datadis($success[$call]),
                fn () => Responses::empty(500),
                fn () => Responses::datadisError('CUPS o distributor no válido ', 400),
                fn () => Responses::text('again', 429),
                fn () => new ConnectException('timeout', new Request('GET', 'https://datadis.test')),
            ];
            $calls = [
                fn () => $client->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1)),
                fn () => $client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1), Month::of(2026, 1)),
                // Same parameters as max power: the stricter reading treats it as the same query.
                fn () => $client->getReactiveData(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1), Month::of(2026, 1)),
                fn () => $client->getMaxPower(Cups::fromString(Scenario::otherCups()), '2', Month::of(2026, 2), Month::of(2026, 2)),
            ];
            $modelKey = [0 => 'consumption', 1 => 'power', 2 => 'power', 3 => 'other'];
            $model = [];
            $sent = 1;

            foreach ($steps as [$call, $answer, $wait]) {
                $clock->advance($wait);
                $now = $clock->now()->getTimestamp();
                $key = $modelKey[$call];
                $free = ! isset($model[$key]) || $now - $model[$key] >= RequestLedger::WINDOW_SECONDS;

                if ($free) {
                    $http->queue($answers[$answer]($call));
                }

                try {
                    $calls[$call]();
                    expect($free)->toBeTrue();
                } catch (RepetitionWindowException $e) {
                    expect($e->requestSent)->toBe($free);
                } catch (DatadisException $e) {
                    expect($free)->toBeTrue()->and($e->requestSent)->toBeTrue();
                }

                if ($free) {
                    $model[$key] = $now;
                    $sent++;
                }

                expect($http->requests())->toHaveCount($sent)->and($http->pending())->toBe(0);
            }
        });
});

it('gives a different fingerprint whenever any parameter differs, and the same one for the same values', function () {
    $fingerprinter = new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!');
    $count = count(RequestFingerprinter::PARAMETERS);
    $values = Generators::oneOf(Generators::constant(null), Generators::string(), Generators::choose(0, 9));

    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::vector($count, $values),
            Generators::choose(0, $count - 1),
            // How the second query differs from the first in that parameter.
            Generators::elements('same', 'retyped', 'omitted', 'changed'),
        )
        ->then(function (array $a, int $field, string $change) use ($fingerprinter) {
            $b = $a;
            $b[$field] = match ($change) {
                'same' => $a[$field],
                // The wire makes 5 and "5" the same parameter.
                'retyped' => is_int($a[$field]) ? (string) $a[$field] : $a[$field],
                'omitted' => null,
                'changed' => $a[$field] === null ? '' : $a[$field].'x',
            };
            $same = match ($change) {
                'same', 'retyped' => true,
                'omitted' => $a[$field] === null,
                'changed' => false,
            };

            $fingerprintA = $fingerprinter->fingerprint('A00000000', array_combine(RequestFingerprinter::PARAMETERS, $a));
            $fingerprintB = $fingerprinter->fingerprint('A00000000', array_combine(RequestFingerprinter::PARAMETERS, $b));

            expect($fingerprintA === $fingerprintB)->toBe($same);
        });
});

it('refuses exactly the query that was remembered, built the same way as the call', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::elements('consumption', 'maxPower', 'reactive'),
            Generators::elements('consumption', 'maxPower', 'reactive'),
            Generators::tuple(Generators::choose(0, 2), Generators::choose(0, 1), Generators::choose(0, 2), Generators::choose(0, 1)),
            Generators::tuple(Generators::choose(0, 2), Generators::choose(0, 1), Generators::choose(0, 2), Generators::choose(0, 1)),
        )
        ->then(function (string $rememberedKind, string $calledKind, array $remembered, array $called) {
            $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
            $http = new FakeHttpClient;
            $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
            $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);
            // [months back of the start, a second month, holder (none, the account, a third party), quarter-hourly]
            $args = function (array $q) {
                $start = Month::of(2026, 8)->addMonths(-$q[0]);

                return [$start, $q[1] === 1 ? Month::of(2026, 8) : null, [null, Nif::fromString('A00000000'), Nif::fromString('00000000T')][$q[2]], $q[3] === 1 ? MeasurementType::QuarterHourly : MeasurementType::Hourly];
            };
            $cups = Cups::fromString('ES0000000000000000AA0A');
            [$start, $end, $nif, $type] = $args($remembered);
            $sentAt = $clock->now()->modify('-1 hour');
            match ($rememberedKind) {
                'consumption' => $client->rememberConsumptionData($sentAt, $cups, '2', 5, $start, $end, $type, $nif),
                'maxPower' => $client->rememberMaxPower($sentAt, $cups, '2', $start, $end, $nif),
                'reactive' => $client->rememberReactiveData($sentAt, $cups, '2', $start, $end, $nif),
            };

            [$start2, $end2, $nif2, $type2] = $args($called);
            $http->queue(Responses::text(Tokens::datadis($clock->now()->getTimestamp())), Responses::datadis('{"timeCurve":[],"maxPower":[],"reactiveEnergy":{},"distributorError":[]}'));

            // The key Datadis uses: consumption counts the measurement type and the holder (the account's
            // own NIF is never sent); maximum power and reactive data share theirs, without the holder.
            $holder = fn (?Nif $n) => $n === null || $n->value() === 'A00000000' ? null : $n->value();
            $months = fn (Month $s, ?Month $e) => $s->format().'-'.($e ?? $s)->format();
            $key = fn (string $kind, Month $s, ?Month $e, ?Nif $n, MeasurementType $t) => $kind === 'consumption'
                ? ['c', $months($s, $e), $holder($n), $t->value]
                : ['p', $months($s, $e)];
            $same = $key($rememberedKind, $start, $end, $nif, $type) === $key($calledKind, $start2, $end2, $nif2, $type2);

            try {
                match ($calledKind) {
                    'consumption' => $client->getConsumptionData($cups, '2', 5, $start2, $end2, $type2, $nif2),
                    'maxPower' => $client->getMaxPower($cups, '2', $start2, $end2, $nif2),
                    'reactive' => $client->getReactiveData($cups, '2', $start2, $end2, $nif2),
                };
                $refused = false;
            } catch (RepetitionWindowException $e) {
                $refused = $e->httpStatus === null;
            }

            expect($refused)->toBe($same);
        });
});
