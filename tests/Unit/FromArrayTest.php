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
    $config = DatadisConfig::fromArray(['username' => 'a00000000', 'password' => 'secret']);

    expect($config->username())->toBe('A00000000')
        ->and($config->password())->toBe('secret')
        ->and($config->baseUrl)->toBe('https://datadis.es')
        ->and($config->timeout)->toBe(120.0)
        ->and($config->connectTimeout)->toBe(10.0)
        ->and($config->userAgent)->toBe(DatadisConfig::DEFAULT_USER_AGENT);
});

it('accepts the values as the environment gives them, as text', function () {
    $config = DatadisConfig::fromArray([
        'username' => ' A00000000 ',
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
    $config = DatadisConfig::fromArray(['username' => 'A00000000', 'password' => 'secret', 'base_url' => '', 'timeout' => null, 'user_agent' => '']);

    expect($config->baseUrl)->toBe('https://datadis.es')
        ->and($config->timeout)->toBe(120.0)
        ->and($config->userAgent)->toBe(DatadisConfig::DEFAULT_USER_AGENT);
});

it('ignores keys it does not know, so an application can keep its own settings alongside', function () {
    $config = DatadisConfig::fromArray(['username' => 'A00000000', 'password' => 'secret', 'retry' => ['enabled' => true], 'guard_store' => 'redis', 'timeout' => '30']);

    expect($config->username())->toBe('A00000000')->and($config->timeout)->toBe(30.0);
});

it('reads the settings also when their names are spelt with dashes', function () {
    $config = DatadisConfig::fromArray(['username' => 'A00000000', 'password' => 'secret', 'base-url' => 'https://datadis.test', 'connect-timeout' => '3', 'user-agent' => 'app/1']);

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
    'no password' => [['username' => 'A00000000'], 'password'],
    'username not text' => [['username' => ['x'], 'password' => 'secret'], 'username'],
    'timeout not a number' => [['username' => 'A00000000', 'password' => 'secret', 'timeout' => 'soon'], 'timeout'],
    'connect timeout not a number' => [['username' => 'A00000000', 'password' => 'secret', 'connect_timeout' => [1]], 'connect_timeout'],
    'username not a NIF' => [['username' => 'aaaa@aaaa.aa', 'password' => 'secret'], 'username'],
    'control check not a boolean' => [['username' => 'A00000000', 'password' => 'secret', 'check_username_control' => 'maybe'], 'check_username_control'],
]);

it('takes a username without checking its control character when the settings say so', function (mixed $flag) {
    expect(DatadisConfig::fromArray(['username' => '00000000A', 'password' => 'secret', 'check-username-control' => $flag])->username())->toBe('00000000A');
})->with([false, 'false', '0', 0]);

it('checks the control character of the username unless told otherwise', function (mixed $flag) {
    DatadisConfig::fromArray(['username' => '00000000A', 'password' => 'secret', 'check_username_control' => $flag]);
})->with([true, 'true', '1', null, ''])->throws(ConfigurationException::class);

it('keeps the password out of dumps also when built from an array', function () {
    $config = DatadisConfig::fromArray(['username' => 'A00000000', 'password' => 'never-dump-this']);

    expect(print_r($config, true).var_export($config, true))->not->toContain('never-dump-this');
});

it('builds a client from the same array, with the API version and time zone', function () {
    $http = (new FakeHttpClient)->queue(
        Responses::text(Tokens::jwt(['exp' => time() + 86400])),
        Responses::text(datadisFixture('v1/supplies-authorized.json'), 200, ['Content-Type' => 'text/plain']),
    );

    $client = DatadisClient::fromArray(
        ['username' => 'A00000000', 'password' => 'secret', 'base_url' => 'https://datadis.test', 'api-version' => ' V1 ', 'timezone' => 'Atlantic/Canary'],
        http: $http,
    );
    $supply = $client->getSupplies()->records[0];

    expect($http->lastRequest()->getUri()->getPath())->toBe('/api-private/api/get-supplies')
        ->and($supply->validDateFrom?->getTimezone()->getName())->toBe('Atlantic/Canary');
});

it('defaults to API v2 and the Madrid time zone', function () {
    $http = (new FakeHttpClient)->queue(Responses::text(Tokens::jwt(['exp' => time() + 86400])), Responses::json(datadisFixture('v2/supplies.json')));

    $supply = DatadisClient::fromArray(['username' => 'A00000000', 'password' => 'secret', 'base_url' => 'https://datadis.test', 'api_version' => ''], http: $http)->getSupplies()->records[0];

    expect($http->lastRequest()->getUri()->getPath())->toBe('/api-private/api/get-supplies-v2')
        ->and($supply->validDateFrom?->getTimezone()->getName())->toBe('Europe/Madrid');
});

it('names the client setting that is wrong', function (array $extra, string $key) {
    try {
        DatadisClient::fromArray(['username' => 'A00000000', 'password' => 'secret'] + $extra, http: new FakeHttpClient);
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
