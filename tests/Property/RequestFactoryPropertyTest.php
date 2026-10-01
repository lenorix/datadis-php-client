<?php

declare(strict_types=1);

use Eris\Generators;
use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Http\RequestFactory;

it('round-trips any query value through the url', function () {
    $factory = new HttpFactory;
    $requests = new RequestFactory(new ConnectionSettings(baseUrl: 'https://datadis.test'), $factory, $factory);

    $this->limitTo(pbtIterations())
        ->forAll(Generators::string(), Generators::string())
        ->then(function (string $a, string $b) use ($requests) {
            $request = $requests->get('/api-private/api/get-supplies-v2', ['authorizedNif' => $a, 'cups' => $b], 'token');
            parse_str($request->getUri()->getQuery(), $parsed);

            expect($parsed['authorizedNif'] ?? null)->toBe($a)
                ->and($parsed['cups'] ?? null)->toBe($b)
                ->and($request->getUri()->getHost())->toBe('datadis.test');
        });
});

it('never puts the password in the login url or headers', function () {
    $factory = new HttpFactory;

    $this->limitTo(pbtIterations())
        ->forAll(Generators::suchThat(fn (string $s) => strlen($s) >= 6 && ! str_contains($s, "\0"), Generators::string()))
        ->then(function (string $password) use ($factory) {
            $request = (new RequestFactory(new ConnectionSettings(baseUrl: 'https://datadis.test'), $factory, $factory))->login(new DatadisConfig('A00000000', $password, baseUrl: 'https://datadis.test'));
            parse_str((string) $request->getBody(), $form);

            expect($form['password'] ?? null)->toBe($password);
            foreach ($request->getHeaders() as $values) {
                expect(implode(',', $values))->not->toContain($password);
            }
        });
});

it('repeats the key once per list item and keeps every item intact', function () {
    $factory = new HttpFactory;
    $requests = new RequestFactory(new ConnectionSettings(baseUrl: 'https://datadis.test'), $factory, $factory);

    $this->limitTo(pbtIterations())
        ->forAll(Generators::seq(Generators::string()))
        ->then(function (array $items) use ($requests) {
            $query = $requests->get('/x', ['cups' => array_values($items)], 'token')->getUri()->getQuery();
            $pairs = $query === '' ? [] : explode('&', $query);

            expect($pairs)->toHaveCount(count($items));
            foreach ($pairs as $i => $pair) {
                [$key, $value] = explode('=', $pair, 2);
                expect($key)->toBe('cups')->and(rawurldecode($value))->toBe($items[$i]);
            }
        });
});
