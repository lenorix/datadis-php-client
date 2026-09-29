<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use GuzzleHttp\Client;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Psr\Http\Client\ClientInterface;

/**
 * The default transport. It is the only place that knows about Guzzle: everything else talks PSR-18,
 * so another client can be injected.
 *
 * PSR-18 has no per-request timeout, so one timeout covers both login and data calls.
 */
final class GuzzleClientFactory
{
    /** @param array<string, mixed> $options extra Guzzle options, they win over the defaults (a proxy, a custom handler) */
    public static function create(DatadisConfig|ConnectionSettings $config, array $options = []): ClientInterface
    {
        $config = $config instanceof DatadisConfig ? $config->connection() : $config;

        // @phpstan-ignore argument.type (the options are Guzzle's own, passed through as given)
        return new Client(array_replace([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            // Statuses are classified by ResponseClassifier, never thrown by Guzzle.
            'http_errors' => false,
            'allow_redirects' => false,
            // Datadis mislabels gzip. We ask for identity and inflate ourselves when needed.
            'decode_content' => false,
        ], $options));
    }
}
