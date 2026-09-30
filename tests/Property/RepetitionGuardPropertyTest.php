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
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

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
            $client = new DatadisClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);
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
                fn () => $client->consumption(Cups::fromString('ES0031300000000001JN0F'), '2', 5, Month::of(2026, 1), Month::of(2026, 1)),
                fn () => $client->maxPower(Cups::fromString('ES0031300000000001JN0F'), '2', Month::of(2026, 1), Month::of(2026, 1)),
                // Same parameters as max power: the stricter reading treats it as the same query.
                fn () => $client->reactive(Cups::fromString('ES0031300000000001JN0F'), '2', Month::of(2026, 1), Month::of(2026, 1)),
                fn () => $client->maxPower(Cups::fromString('ES0031300000000002JN'), '2', Month::of(2026, 2), Month::of(2026, 2)),
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

            $fingerprintA = $fingerprinter->fingerprint('12345678Z', array_combine(RequestFingerprinter::PARAMETERS, $a));
            $fingerprintB = $fingerprinter->fingerprint('12345678Z', array_combine(RequestFingerprinter::PARAMETERS, $b));

            expect($fingerprintA === $fingerprintB)->toBe($same);
        });
});
