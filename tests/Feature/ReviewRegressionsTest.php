<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\BillingPeriod;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

it('reads only the verified 404 "No supplies" as an empty list', function (string $call) {
    $s = Scenario::make();
    $s->http->queue(Responses::text('No supplies', 404, ['Content-Type' => 'application/json']));

    expect($s->client->{$call}()->records)->toBe([]);
})->with(['getSupplies', 'getDistributorsWithSupplies']);

it('raises any other 404 of the account lists, so a broken path or a changed API shows', function (string $call, string $body) {
    $s = Scenario::make();
    $s->http->queue(Responses::text($body, 404, ['Content-Type' => 'application/json']));

    expect(fn () => $s->client->{$call}())->toThrow(NoDataException::class);
})->with(['getSupplies', 'getDistributorsWithSupplies'])->with(['Unknown endpoint', '', 'No supplies for you', '{"status":404,"error":"Not Found"}']);

it('keeps the token the request carried out of a transport failure, whatever its shape', function (string $token) {
    $s = Scenario::make(login: false);
    $s->http->queue(Responses::text($token));
    $s->http->queue(new class($token) extends RuntimeException implements NetworkExceptionInterface
    {
        public function __construct(string $token)
        {
            parent::__construct("cURL error 28 sending GET with Authorization: Bearer {$token} and token={$token}");
        }

        public function getRequest(): RequestInterface
        {
            return new Request('GET', 'https://datadis.test');
        }
    });

    try {
        $s->client->getSupplies();
    } catch (TransportException $e) {
        expect((string) $e->detail)->not->toContain($token)->toContain('cURL error 28')
            ->and($e->getMessage())->not->toContain($token);

        return;
    }

    throw new LogicException('Expected a TransportException.');
})->with(['abc.def.ghi', 'opaque-token_0123/xyz=']);

it('does not keep a token without exp past its assumed lifetime, even in a store that ignores the TTL', function () {
    $s = Scenario::make(login: false, tokenCache: new QuirkyCache /* keeps values past their TTL */);
    $s->http->queue(Responses::text('opaque.token.one'), Responses::datadis('{"supplies":[],"distributorError":[]}'));
    $s->client->getSupplies();

    $s->clock->advance(TokenProvider::FALLBACK_TTL_SECONDS + 1);
    $s->http->queue(Responses::text('opaque.token.two'), Responses::datadis('{"supplies":[],"distributorError":[]}'));
    $s->client->getSupplies();

    expect($s->http->requests()[3]->getHeaderLine('Authorization'))->toBe('Bearer opaque.token.two');
});

it('reuses a token without exp until its assumed lifetime ends', function () {
    $s = Scenario::make(login: false, tokenCache: new QuirkyCache /* keeps values past their TTL */);
    $s->http->queue(Responses::text('opaque.token.one'), Responses::datadis('{"supplies":[],"distributorError":[]}'), Responses::datadis('{"supplies":[],"distributorError":[]}'));
    $s->client->getSupplies();
    $s->clock->advance(TokenProvider::FALLBACK_TTL_SECONDS - TokenProvider::SKEW_SECONDS - 1);
    $s->client->getSupplies();

    expect($s->http->requests())->toHaveCount(3)->and($s->http->requests()[2]->getHeaderLine('Authorization'))->toBe('Bearer opaque.token.one');
});

it('does not trust a bare token without exp found in the store, since its age is unknown', function () {
    $cache = new QuirkyCache /* keeps values past their TTL */;
    $s = Scenario::make(login: false, tokenCache: $cache);
    $s->http->queue(Responses::text(Tokens::datadis($s->clock->now()->getTimestamp())), Responses::datadis('{"supplies":[],"distributorError":[]}'));
    $s->client->getSupplies();
    $cache->items[array_key_first($cache->items)] = 'opaque.token.bare';
    $s->http->queue(Responses::text('opaque.token.new'), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $s->client->getSupplies();

    expect($s->http->requests()[3]->getHeaderLine('Authorization'))->toBe('Bearer opaque.token.new');
});

it('reads a row with no field at all as unusable, not as the blank row of a CUPS Datadis cannot see', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"timeCurve":[{}],"distributorError":[]}'));

    expect(fn () => $s->client->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 1), Month::of(2026, 1)))
        ->toThrow(UninterpretableResponseException::class, 'none of the 1 rows could be used');
});

it('plans up to the last month there is', function () {
    $ranges = MonthPlanner::ranges(Month::of(9999, 10), Month::of(9999, 12), new DateTimeImmutable('9999-12-15'), 2);

    expect(array_map(fn (array $r) => [(string) $r[0], (string) $r[1]], $ranges))->toBe([['9999/10', '9999/11'], ['9999/12', '9999/12']]);
});

it('does not take a period as covered by a last row with surplus but no consumption', function () {
    $s = Scenario::make();
    $row = fn (string $time, mixed $kWh) => ['cups' => Scenario::CUPS, 'date' => '2026/01/31', 'time' => $time, 'consumptionKWh' => $kWh, 'obtainMethod' => 'Real', 'surplusEnergyKWh' => 0.7];
    $s->http->queue(Responses::datadis(Payloads::envelope('timeCurve', [$row('23:00', 0.2), $row('24:00', null)])));
    $result = $s->client->getConsumptionData(Scenario::cups(), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
    $period = BillingPeriod::between(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

    expect($period->isCoveredBy($result->records))->toBeFalse()
        ->and($period->totalKWh($result->records))->toBe('0.200')
        ->and($result->skippedRows)->toBe(1);
});
