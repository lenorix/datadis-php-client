<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Gen;
use Lenorix\DatadisClient\Tests\Support\Responses;

it('accepts every valid combination and sends exactly what it validated', function () {
    $community = Community::cases();

    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::choose(0, count($community) - 1),
            Generators::choose(0, count($community) - 1),
            Generators::subset(['01', '02', '03', '04', '05']),
            Generators::choose(0, 5000),
            Generators::choose(1, 2000),
            Generators::subset(['1', '2', '3', '4']),
            Generators::subset(['E0', 'E1', 'E2', 'E3', 'E4', 'E5', 'E6']),
            Generators::choose(0, 400),
        )
        ->then(function (int $c1, int $c2, array $types, int $page, int $size, array $sectors, array $tension, int $days) use ($community) {
            $picked = array_values(array_unique([$community[$c1], $community[$c2]], SORT_REGULAR));
            $from = new DateTimeImmutable('2025-01-01');
            $to = $from->modify("+{$days} days");

            $query = new PublicSearchQuery($from, $to, $picked, array_values($types), $page, $size, economicSector: array_values($sectors), tension: array_values($tension));
            $http = (new FakeHttpClient)->queue(Responses::json('[]'));
            (new PublicApiClient(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http))->apiSearch($query);
            parse_str($http->lastRequest()->getUri()->getQuery(), $sent);

            expect($sent['community'])->toBe(implode(',', array_map(fn ($c) => $c->value, $picked)))
                ->and(isset($sent['measurementType']) ? explode(',', $sent['measurementType']) : [])->toBe(array_values($types));
        });
});

it('never builds a query from a value with a comma, a space or an unknown code', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::oneOf(
            Generators::string(),
            Gen::digits(4),
            Gen::digits(5),
            Gen::digits(6),
            Generators::map(fn (array $p) => $p[0].$p[1].$p[2], Generators::tuple(Gen::digits(5), Generators::elements(',', ' ', ', '), Gen::digits(5))),
        ))
        ->then(function (string $value) {
            $valid = preg_match('/^\d{5}$/D', $value) === 1;

            try {
                new PublicSearchQuery(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-02'), [Community::Madrid], ['05'], postalCode: [$value]);
                expect($valid)->toBeTrue();
            } catch (InvalidRequestException) {
                expect($valid)->toBeFalse();
            }
        });
});

it('reads any JSON answer as records or a DatadisException', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::oneOf(
            Generators::seq(Generators::oneOf(Generators::constant(['sumEnergy' => 1]), Generators::int(), Generators::string(), Generators::constant(null), Generators::constant([]))),
            Generators::associative(['content' => Generators::oneOf(Generators::string(), Generators::seq(Generators::constant(['a' => 1])), Generators::constant(null))]),
            Generators::associative(['sumEnergy' => Generators::float(), 'mi1' => Generators::string()]),
        ))
        ->then(function (mixed $payload) {
            $http = (new FakeHttpClient)->queue(Responses::json((string) json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR)));
            $query = new PublicSearchQuery(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-02'), [Community::Madrid], ['05']);

            try {
                $result = (new PublicApiClient(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http))->apiSearch($query);

                foreach ($result->records as $record) {
                    // Whatever arrived, the energy is either unknown or an exact decimal, never a float.
                    expect($record->sumEnergy() ?? '0.000')->toMatch('/^-?\d+\.\d{3,}$/');
                }
            } catch (DatadisException $e) {
                expect($e->endpoint)->toBe('api-search');
            }
        });
});

it('walks pages until a short one and never asks for more than the limit', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::seq(Generators::choose(0, 3)), Generators::choose(1, 3), Generators::choose(1, 6))
        ->then(function (array $pageLengths, int $pageSize, int $maxPages) {
            $http = new FakeHttpClient;
            $served = 0;
            $http->queue(...array_fill(0, 10, function () use (&$served, $pageLengths, $pageSize) {
                $length = $pageLengths[$served] ?? 0;
                $served++;

                return Responses::json((string) json_encode(array_fill(0, min($length, $pageSize), ['a' => 1])));
            }));
            $query = new PublicSearchQuery(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-02'), [Community::Madrid], ['05'], pageSize: $pageSize);

            $records = iterator_to_array((new PublicApiClient(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http))->apiSearchAll($query, $maxPages), false);

            // Expected: read pages while they are full, stop after the first short one or at the limit.
            $expectedPages = 0;
            $expectedRecords = 0;
            while ($expectedPages < $maxPages) {
                $length = min($pageLengths[$expectedPages] ?? 0, $pageSize);
                $expectedPages++;
                $expectedRecords += $length;
                if ($length < $pageSize) {
                    break;
                }
            }

            expect($http->requests())->toHaveCount($expectedPages)->and($records)->toHaveCount($expectedRecords);
        });
});
