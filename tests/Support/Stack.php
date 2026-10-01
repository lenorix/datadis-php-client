<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\Transport;
use Lenorix\DatadisClient\Support\InMemoryCache;
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
        $this->config = $config ?? new DatadisConfig('A00000000', self::PASSWORD, baseUrl: 'https://datadis.test');
        $factory = new HttpFactory;
        $this->requests = new RequestFactory($this->config->connection(), $factory, $factory);
        $this->transport = new Transport($this->http, $factory);
        $this->tokens = new TokenProvider($this->config, $this->requests, $this->transport, $this->cache, $this->clock);
        $this->caller = new ApiCaller($this->requests, $this->transport, $this->tokens);
    }

    public readonly DatadisConfig $config;

    public readonly RequestFactory $requests;

    public readonly Transport $transport;

    public readonly TokenProvider $tokens;

    public readonly ApiCaller $caller;

    /** A login answer carrying a token issued now that expires in $lifetime seconds (24 hours, like the real one). */
    public function loginOk(int $lifetime = 86400, string $subject = 'user'): ResponseInterface
    {
        $now = $this->clock->now()->getTimestamp();

        return Responses::text(Tokens::jwt(['sub' => $subject, 'iat' => $now, 'exp' => $now + $lifetime]));
    }
}
