<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Guard\LedgerEvent;
use Lenorix\DatadisClient\Guard\LedgerStore;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Values\Cups;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

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

    /** The secret of the fingerprints of every test ledger. */
    public const string SECRET = 'a-secret-key-of-at-least-32-bytes!!';

    /**
     * A client wired to a fake HTTP client, at a frozen "now" of 2026-09-15 10:00 in Madrid, whose
     * first answer is a valid login unless `login` is false.
     *
     * @param  (Closure(FrozenClock): RequestLedger)|null  $ledger  the ledger, built on the scenario's clock
     */
    public static function make(
        ApiVersion $version = ApiVersion::V2,
        ?DateTimeZone $zone = null,
        ?Closure $ledger = null,
        bool $login = true,
        ?CacheInterface $tokenCache = null,
    ): self {
        $http = new FakeHttpClient;
        $clock = self::clock();
        $client = self::client($http, $clock, $ledger === null ? null : $ledger($clock), $version, $zone, $tokenCache);

        if ($login) {
            $http->queue(self::login($clock));
        }

        return new self($client, $http, $clock);
    }

    /** The parts of make(), for tests that take them apart: [client, http, clock]. */
    public static function parts(ApiVersion $version = ApiVersion::V2, ?DateTimeZone $zone = null): array
    {
        $s = self::make($version, $zone);

        return [$s->client, $s->http, $s->clock];
    }

    /** The frozen "now" of every scenario: 2026-09-15 10:00 in Madrid. */
    public static function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    }

    public static function config(): DatadisConfig
    {
        return new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test');
    }

    /** A client of the scenario's account on any HTTP client and clock. */
    public static function client(
        ClientInterface $http,
        FrozenClock $clock,
        ?RequestLedger $ledger = null,
        ApiVersion $version = ApiVersion::V2,
        ?DateTimeZone $zone = null,
        ?CacheInterface $tokenCache = null,
    ): DatadisClient {
        return new DatadisClient(self::config(), http: $http, version: $version, tokenCache: $tokenCache, clock: $clock, timeZone: $zone, ledger: $ledger);
    }

    /**
     * A ledger on the given clock: in memory unless a store is given.
     *
     * @param  (Closure(LedgerEvent): void)|null  $onChange
     */
    public static function ledger(FrozenClock $clock, LedgerStore|CacheInterface|null $store = null, ?Closure $onChange = null, ?int $windowSeconds = null): RequestLedger
    {
        return new RequestLedger($store ?? new InMemoryCache($clock), new RequestFingerprinter(self::SECRET), $clock, $windowSeconds, $onChange);
    }

    /** A valid login answer: a token issued at the clock's now, lasting 24 hours unless told otherwise. */
    public static function login(FrozenClock $clock, int $lifetimeSeconds = 86400): ResponseInterface
    {
        $now = $clock->now()->getTimestamp();

        return Responses::text($lifetimeSeconds === 86400 ? Tokens::datadis($now) : Tokens::jwt(['sub' => 'account', 'iat' => $now, 'exp' => $now + $lifetimeSeconds]));
    }

    /** The answer Datadis gives to a token it does not accept (verified). */
    public static function refusedToken(): ResponseInterface
    {
        return Responses::datadisError((string) file_get_contents(__DIR__.'/../Fixtures/errors/401-spring.json'), 401);
    }

    public static function cups(): Cups
    {
        return Cups::fromString(self::CUPS);
    }

    /** @return array<string, string> the query of the n-th request (0 is the login) */
    public function query(int $index = 1): array
    {
        parse_str($this->http->requests()[$index]->getUri()->getQuery(), $query);

        return $query;
    }
}
