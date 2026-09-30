<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;

it('has the same defaults as the private client', function () {
    $settings = new ConnectionSettings;

    expect($settings->baseUrl)->toBe('https://datadis.es')
        ->and($settings->timeout)->toBe(120.0)
        ->and($settings->connectTimeout)->toBe(10.0)
        ->and($settings->userAgent)->toBe(DatadisConfig::DEFAULT_USER_AGENT);
});

it('validates like the private configuration', function (array $arguments) {
    new ConnectionSettings(...$arguments);
})->with([
    'plain http' => [['baseUrl' => 'http://datadis.es']],
    'credentials in url' => [['baseUrl' => 'https://a:b@datadis.es']],
    'user without password in url' => [['baseUrl' => 'https://someone@datadis.es']],
    'space in the host' => [['baseUrl' => 'https://datadis .es']],
    'unclosed IPv6 host' => [['baseUrl' => 'https://[::1']],
    'IPv6 host that is not an address' => [['baseUrl' => 'https://[::zz]']],
    'backslash in the host' => [['baseUrl' => 'https://datadis.es\\x']],
    'zero connect timeout' => [['connectTimeout' => 0.0]],
    'a timeout that is not a number' => [['timeout' => NAN]],
    'an infinite timeout' => [['timeout' => INF]],
    'a timeout Guzzle rounds to zero' => [['timeout' => 0.0001]],
    'query in url' => [['baseUrl' => 'https://datadis.es/?x=1']],
    'zero timeout' => [['timeout' => 0.0]],
    'bad user agent' => [['userAgent' => "x\ny"]],
])->throws(ConfigurationException::class);

it('is what the private configuration exposes', function () {
    $config = new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test/', timeout: 30.0, connectTimeout: 3.0, userAgent: 'agent');
    $settings = $config->connection();

    expect($settings->baseUrl)->toBe('https://datadis.test')
        ->and($settings->timeout)->toBe(30.0)
        ->and($settings->connectTimeout)->toBe(3.0)
        ->and($settings->userAgent)->toBe('agent');
});

it('accepts short timeouts and a base URL with spaces around it', function () {
    $settings = new ConnectionSettings(baseUrl: '  https://datadis.test/  ', timeout: 0.5, connectTimeout: 0.5);

    expect($settings->baseUrl)->toBe('https://datadis.test')->and($settings->timeout)->toBe(0.5);
});

it('refuses URLs without a host', function (string $url) {
    new ConnectionSettings(baseUrl: $url);
})->with(['https://', 'https:///path', '/relative', ''])->throws(ConfigurationException::class);

it('accepts a proxy on an IPv6 address', function () {
    expect((new ConnectionSettings(baseUrl: 'https://[::1]:8443/datadis'))->baseUrl)->toBe('https://[::1]:8443/datadis');
});
