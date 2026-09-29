<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\Auth\InMemoryCache;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\Transport;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/** Wires the pieces against a fake HTTP client so tests read as scenarios. */
final class Stack
{
    public const string PASSWORD = 'never-leak-this-password';

    public function __construct(
        public readonly FakeHttpClient $http = new FakeHttpClient,
        public readonly FrozenClock $clock = new FrozenClock,
        public readonly CacheInterface $cache = new InMemoryCache(new FrozenClock),
        ?DatadisConfig $config = null,
    ) {
        $this->config = $config ?? new DatadisConfig('12345678Z', self::PASSWORD, baseUrl: 'https://datadis.test');
        $factory = new HttpFactory;
        $this->requests = new RequestFactory($this->config, $factory, $factory);
        $this->transport = new Transport($this->http);
        $this->tokens = new TokenProvider($this->config, $this->requests, $this->transport, $this->cache, $this->clock);
        $this->caller = new ApiCaller($this->requests, $this->transport, $this->tokens);
    }

    public readonly DatadisConfig $config;

    public readonly RequestFactory $requests;

    public readonly Transport $transport;

    public readonly TokenProvider $tokens;

    public readonly ApiCaller $caller;

    /** A login answer carrying a token that expires in $lifetime seconds from the frozen clock. */
    public function loginOk(int $lifetime = 3600, string $subject = 'user'): ResponseInterface
    {
        return Responses::text(Tokens::jwt(['sub' => $subject, 'exp' => $this->clock->now()->getTimestamp() + $lifetime]));
    }
}
