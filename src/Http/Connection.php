<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The wiring the private and the public API share: the PSR-17 factories, the transport and, with
 * credentials, the login. Guzzle is used for whatever the application does not provide.
 *
 * @internal
 */
final readonly class Connection
{
    public RequestFactory $requests;

    public Transport $transport;

    public function __construct(
        DatadisConfig|ConnectionSettings $settings,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $factory = new HttpFactory;
        $streamFactory ??= $factory;

        $this->requests = new RequestFactory($settings, $requestFactory ?? $factory, $streamFactory);
        $this->transport = new Transport($http ?? GuzzleClientFactory::create($settings), $streamFactory);
    }

    /** Authenticated calls: log in with the account and send its token. */
    public function caller(DatadisConfig $config, ?CacheInterface $tokenCache = null, ?ClockInterface $clock = null): ApiCaller
    {
        return new ApiCaller($this->requests, $this->transport, new TokenProvider($config, $this->requests, $this->transport, $tokenCache, $clock));
    }
}
