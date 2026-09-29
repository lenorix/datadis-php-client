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
