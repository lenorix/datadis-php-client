<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;

/** A client wired to a fake HTTP client whose first answer is a valid login, at a frozen "now" of 2026-09-15. */
final class Scenario
{
    public const string CUPS = 'ES0031300000000001JN0F';

    public function __construct(
        public readonly DatadisClient $client,
        public readonly FakeHttpClient $http,
        public readonly FrozenClock $clock,
    ) {}

    public static function make(ApiVersion $version = ApiVersion::V2, ?DateTimeZone $zone = null): self
    {
        $http = new FakeHttpClient;
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
        $client = new DatadisClient(
            new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'),
            http: $http,
            version: $version,
            clock: $clock,
            timeZone: $zone,
        );
        $http->queue(Responses::text(Tokens::datadis($clock->now()->getTimestamp())));

        return new self($client, $http, $clock);
    }

    /** @return array<string, string> the query of the n-th request (0 is the login) */
    public function query(int $index = 1): array
    {
        parse_str($this->http->requests()[$index]->getUri()->getQuery(), $query);

        return $query;
    }
}
