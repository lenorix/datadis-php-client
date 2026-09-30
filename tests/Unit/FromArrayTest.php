<?php

declare(strict_types=1);

use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Tokens;

/*
 * Applications keep their settings in a configuration file or the environment (for example the
 * Laravel config/services.php filled from .env) and hand the array to the package.
 */

it('builds the configuration from the minimum an application needs', function () {
    $config = DatadisConfig::fromArray(['username' => '12345678z', 'password' => 'secret']);

    expect($config->username)->toBe('12345678Z')
        ->and($config->password())->toBe('secret')
        ->and($config->baseUrl)->toBe('https://datadis.es')
        ->and($config->timeout)->toBe(120.0)
        ->and($config->connectTimeout)->toBe(10.0)
        ->and($config->userAgent)->toBe(DatadisConfig::DEFAULT_USER_AGENT);
});

it('accepts the values as the environment gives them, as text', function () {
    $config = DatadisConfig::fromArray([
        'username' => ' 12345678Z ',
        'password' => 'secret',
        'base_url' => 'https://datadis.test/',
        'timeout' => '60',
        'connect_timeout' => '2.5',
        'user_agent' => 'my-app/1.0',
    ]);

    expect($config->baseUrl)->toBe('https://datadis.test')
        ->and($config->timeout)->toBe(60.0)
        ->and($config->connectTimeout)->toBe(2.5)
        ->and($config->userAgent)->toBe('my-app/1.0');
});

it('treats empty values from an unset environment variable as not given', function () {
    $config = DatadisConfig::fromArray(['username' => '12345678Z', 'password' => 'secret', 'base_url' => '', 'timeout' => null, 'user_agent' => '']);

    expect($config->baseUrl)->toBe('https://datadis.es')
        ->and($config->timeout)->toBe(120.0)
        ->and($config->userAgent)->toBe(DatadisConfig::DEFAULT_USER_AGENT);
});

it('ignores keys it does not know, so an application can keep its own settings alongside', function () {
    expect(DatadisConfig::fromArray(['username' => '12345678Z', 'password' => 'secret', 'retry' => ['enabled' => true], 'guard_store' => 'redis']))
        ->toBeInstanceOf(DatadisConfig::class);
});

it('reads the settings also when their names are spelt with dashes', function () {
    $config = DatadisConfig::fromArray(['username' => '12345678Z', 'password' => 'secret', 'base-url' => 'https://datadis.test', 'connect-timeout' => '3', 'user-agent' => 'app/1']);

    expect($config->baseUrl)->toBe('https://datadis.test')
        ->and($config->connectTimeout)->toBe(3.0)
        ->and($config->userAgent)->toBe('app/1');
});

it('names the setting that is wrong', function (array $settings, string $key) {
    try {
        DatadisConfig::fromArray($settings);
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain($key)->and($e->requestSent)->toBeFalse();

        return;
    }

    throw new LogicException('Expected a ConfigurationException.');
})->with([
    'no username' => [['password' => 'secret'], 'username'],
    'blank username' => [['username' => '  ', 'password' => 'secret'], 'username'],
    'no password' => [['username' => '12345678Z'], 'password'],
    'username not text' => [['username' => ['x'], 'password' => 'secret'], 'username'],
    'timeout not a number' => [['username' => '12345678Z', 'password' => 'secret', 'timeout' => 'soon'], 'timeout'],
    'connect timeout not a number' => [['username' => '12345678Z', 'password' => 'secret', 'connect_timeout' => [1]], 'connect_timeout'],
]);

it('keeps the password out of dumps also when built from an array', function () {
    $config = DatadisConfig::fromArray(['username' => '12345678Z', 'password' => 'never-dump-this']);

    expect(print_r($config, true).var_export($config, true))->not->toContain('never-dump-this');
});

it('builds a client from the same array, with the API version and time zone', function () {
    $http = (new FakeHttpClient)->queue(
        Responses::text(Tokens::jwt(['exp' => time() + 86400])),
        Responses::text(datadisFixture('v1/supplies-authorized.json'), 200, ['Content-Type' => 'text/plain']),
    );

    $client = DatadisClient::fromArray(
        ['username' => '12345678Z', 'password' => 'secret', 'base_url' => 'https://datadis.test', 'api-version' => ' V1 ', 'timezone' => 'Atlantic/Canary'],
        http: $http,
    );
    $supply = $client->supplies()->records[0];

    expect($http->lastRequest()->getUri()->getPath())->toBe('/api-private/api/get-supplies')
        ->and($supply->validFrom?->getTimezone()->getName())->toBe('Atlantic/Canary');
});

it('defaults to API v2 and the Madrid time zone', function () {
    $http = (new FakeHttpClient)->queue(Responses::text(Tokens::jwt(['exp' => time() + 86400])), Responses::json(datadisFixture('v2/supplies.json')));

    $supply = DatadisClient::fromArray(['username' => '12345678Z', 'password' => 'secret', 'base_url' => 'https://datadis.test', 'api_version' => ''], http: $http)->supplies()->records[0];

    expect($http->lastRequest()->getUri()->getPath())->toBe('/api-private/api/get-supplies-v2')
        ->and($supply->validFrom?->getTimezone()->getName())->toBe('Europe/Madrid');
});

it('names the client setting that is wrong', function (array $extra, string $key) {
    try {
        DatadisClient::fromArray(['username' => '12345678Z', 'password' => 'secret'] + $extra, http: new FakeHttpClient);
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain($key);

        return;
    }

    throw new LogicException('Expected a ConfigurationException.');
})->with([
    'unknown version' => [['api_version' => 'v3'], 'api_version'],
    'unknown time zone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'time zone not text' => [['timezone' => 1], 'timezone'],
]);
