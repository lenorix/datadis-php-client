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
    public const string CUPS = 'ES0000000000000000AA0A';

    /**
     * A second supply, for tests that need two that differ. The repository holds no CUPS but the
     * all-zero one, so this one is built when the test runs.
     */
    public static function otherCups(): string
    {
        return self::numberedCups(1);
    }

    /** The all-zero CUPS with $n in its last digits, built when the test runs: 0 is CUPS itself, without the suffix. */
    public static function numberedCups(int $n): string
    {
        return sprintf('ES%016dAA', $n);
    }

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
            new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'),
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
