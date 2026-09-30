<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

$cups = fn () => Cups::fromString(Scenario::CUPS);
$jan = fn () => Month::of(2026, 1);

$calls = [
    'supplies' => [fn ($c, $nif) => $c->supplies($nif), '{"supplies":[]}'],
    'distributors' => [fn ($c, $nif) => $c->distributors($nif), '{"distExistenceUser":{"distributorCodes":[]}}'],
    'contract detail' => [fn ($c, $nif) => $c->contractDetail(Cups::fromString(Scenario::CUPS), '2', $nif), '{"contract":[]}'],
    'consumption' => [fn ($c, $nif) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 1), authorizedNif: $nif), '{"timeCurve":[]}'],
    'max power' => [fn ($c, $nif) => $c->maxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), Month::of(2026, 1), $nif), '{"maxPower":[]}'],
    'reactive' => [fn ($c, $nif) => $c->reactive(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 1), Month::of(2026, 1), $nif), '{"reactiveEnergy":{}}'],
];

foreach ($calls as $name => [$call, $body]) {
    it("sends authorizedNif for a third party and omits it for the account itself ({$name})", function () use ($call, $body) {
        $third = Scenario::make();
        $third->http->queue(Responses::datadis($body));
        $call($third->client, Nif::fromString('87654321x'));

        $own = Scenario::make();
        $own->http->queue(Responses::datadis($body));
        $call($own->client, Nif::fromString('12345678Z'));

        expect($third->query()['authorizedNif'] ?? null)->toBe('87654321X')
            ->and($own->query())->not->toHaveKey('authorizedNif');
    });
}

it('refuses malformed arguments of the calls that take them before sending', function (Closure $call) {
    $s = Scenario::make();

    expect(fn () => $call($s->client))->toThrow(InvalidRequestException::class)
        ->and($s->http->requests())->toBe([]);
})->with([
    'supplies distributor code' => [fn ($c) => $c->supplies(distributorCode: 'a b')],
    'consumption distributor code' => [fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '', 5, Month::of(2026, 1), Month::of(2026, 1))],
    'consumption range' => [fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 2), Month::of(2026, 1))],
    'consumption future' => [fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 10))],
    'reactive distributor code' => [fn ($c) => $c->reactive(Cups::fromString(Scenario::CUPS), '', Month::of(2026, 1), Month::of(2026, 1))],
    'reactive range' => [fn ($c) => $c->reactive(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 2), Month::of(2026, 1))],
    'max power distributor code' => [fn ($c) => $c->maxPower(Cups::fromString(Scenario::CUPS), 'x y', Month::of(2026, 1), Month::of(2026, 1))],
    'contract distributor code too long' => [fn ($c) => $c->contractDetail(Cups::fromString(Scenario::CUPS), '12345678901')],
]);

it('accepts a one day authorization and one with only a start or an end', function (?string $from, ?string $to, string $query) {
    $s = Scenario::make();
    $s->http->queue(Responses::empty(200));

    $s->client->newAuthorization(Nif::fromString('87654321X'), $from === null ? null : new DateTimeImmutable($from), $to === null ? null : new DateTimeImmutable($to));

    expect($s->http->requests()[1]->getUri()->getQuery())->toBe($query);
})->with([
    'one day' => ['2026-10-01', '2026-10-01', 'authorizedNif=87654321X&startDate=2026%2F10%2F01&endDate=2026%2F10%2F01'],
    'only a start' => ['2026-10-01', null, 'authorizedNif=87654321X&startDate=2026%2F10%2F01'],
    'only an end' => [null, '2026-10-01', 'authorizedNif=87654321X&endDate=2026%2F10%2F01'],
]);

it('counts repeated labels per day, not across days', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis(Payloads::envelope('timeCurve', [
        ...Payloads::hourlyRows('2026/01/01', Payloads::normalDay()),
        ...Payloads::hourlyRows('2026/01/02', Payloads::normalDay()),
    ])));

    $readings = $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 1))->records;

    expect($readings)->toHaveCount(48);
    foreach ($readings as $i => $reading) {
        expect($reading->hasValidTime())->toBeTrue();
        if ($i > 0) {
            expect($reading->start->getTimestamp())->toBe($readings[$i - 1]->end->getTimestamp());
        }
    }
});

it('builds requests with the PSR-17 factories it is given', function () {
    $guzzle = new HttpFactory;
    $requests = new class($guzzle) implements RequestFactoryInterface
    {
        public function __construct(private HttpFactory $inner) {}

        public function createRequest(string $method, $uri): RequestInterface
        {
            return $this->inner->createRequest($method, $uri)->withHeader('X-Built-By', 'app');
        }
    };
    $streams = new class($guzzle) implements StreamFactoryInterface
    {
        /** @var list<StreamInterface> */
        public array $created = [];

        public function __construct(private HttpFactory $inner) {}

        public function createStream(string $content = ''): StreamInterface
        {
            return $this->created[] = $this->inner->createStream($content);
        }

        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
        {
            return $this->inner->createStreamFromFile($filename, $mode);
        }

        public function createStreamFromResource($resource): StreamInterface
        {
            return $this->inner->createStreamFromResource($resource);
        }
    };
    $http = (new FakeHttpClient)->queue(Responses::text(Tokens::datadis(time())), Responses::datadis('{"supplies":[]}'));
    $client = new DatadisClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'), http: $http, requestFactory: $requests, streamFactory: $streams);

    $client->supplies();

    [$login, $supplies] = $http->requests();

    expect($login->getHeaderLine('X-Built-By'))->toBe('app')
        ->and($supplies->getHeaderLine('X-Built-By'))->toBe('app')
        ->and(in_array($login->getBody(), $streams->created, true))->toBeTrue();
});
