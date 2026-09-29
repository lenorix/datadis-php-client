<?php

declare(strict_types=1);

use Eris\Generators;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;

it('sends a request as often as the retry model says and never more', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::seq(Generators::elements(200, 400, 429, 500, 502, 503, 504, 'network')),
            Generators::elements(
                '/api-private/api/get-supplies-v2',
                '/api-private/api/get-contract-detail',
                '/api-public/api-search',
                '/api-private/api/get-consumption-data-v2',
                '/api-private/api/get-max-power',
                '/api-private/api/get-reactive-data-v2',
                '/api-private/api/new-authorization',
            ),
            Generators::choose(0, 4),
        )
        ->then(function (array $outcomes, string $path, int $maxRetries) {
            $outcomes[] = 200;
            $http = new FakeHttpClient;
            foreach ($outcomes as $outcome) {
                $http->queue($outcome === 'network' ? new ConnectException('x', new Request('GET', 'https://datadis.test')) : Responses::empty($outcome));
            }
            $sleeps = 0;
            $client = new RetryingClient($http, $maxRetries, sleep: function () use (&$sleeps) {
                $sleeps++;
            }, random: fn () => 0.5);

            $guarded = (bool) preg_match('/consumption|max-power|reactive|authorization/', $path);
            $expected = 0;
            foreach ($outcomes as $outcome) {
                $expected++;
                $transient = $outcome === 'network' || in_array($outcome, [502, 503, 504], true);
                if ($guarded || ! $transient || $expected > $maxRetries) {
                    break;
                }
            }

            try {
                $client->sendRequest(new Request('GET', 'https://datadis.test'.$path));
            } catch (ConnectException) {
            }

            expect($http->requests())->toHaveCount($expected)
                ->and($sleeps)->toBe($expected - 1)
                ->and(count($http->requests()))->toBeLessThanOrEqual($guarded ? 1 : $maxRetries + 1);
        });
});

it('keeps every wait within half the step and the maximum', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(1, 5000), Generators::choose(0, 60000), Generators::choose(0, 1000))
        ->then(function (int $base, int $extra, int $random) {
            $max = $base + $extra;
            $sleeps = [];
            $http = (new FakeHttpClient)->queue(...array_fill(0, 11, Responses::empty(503)));
            $client = new RetryingClient($http, 10, $base, $max, sleep: function (int $ms) use (&$sleeps) {
                $sleeps[] = $ms;
            }, random: fn () => $random / 1000);

            $client->sendRequest(new Request('GET', 'https://datadis.test/api-private/api/get-supplies-v2'));

            foreach ($sleeps as $attempt => $ms) {
                $step = min($max, $base * 2 ** $attempt);
                expect($ms)->toBeGreaterThanOrEqual((int) floor($step / 2))->toBeLessThanOrEqual($max);
            }
        });
});

it('plans ranges that cover exactly the servable months, in order, without overlaps', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::choose(2020, 2029), Generators::choose(1, 12),
            Generators::choose(0, 80),
            Generators::choose(1, 13),
            Generators::choose(0, 400),
        )
        ->then(function (int $year, int $month, int $length, int $perRequest, int $daysAfter) {
            $from = Month::of($year, $month);
            $to = $from->addMonths($length);
            $now = (new DateTimeImmutable('2025-01-01'))->modify("+{$daysAfter} days");

            $ranges = MonthPlanner::ranges($from, $to, $now, $perRequest);
            $covered = [];
            foreach ($ranges as [$start, $end]) {
                expect($end->diffInMonths($start))->toBeGreaterThanOrEqual(0)->toBeLessThan($perRequest);
                foreach (Month::sequence($start, $end) as $m) {
                    $covered[] = $m->format();
                }
            }

            $expected = array_values(array_map(
                fn (Month $m) => $m->format(),
                array_filter(Month::sequence($from, $to), fn (Month $m) => $m->isWithinHistory($now)),
            ));

            expect($covered)->toBe($expected);
        });
});
