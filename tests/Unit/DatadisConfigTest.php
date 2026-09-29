<?php

declare(strict_types=1);

use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;

it('has sensible defaults', function () {
    $config = new DatadisConfig('12345678Z', 'secret');

    expect($config->baseUrl)->toBe('https://datadis.es')
        ->and($config->timeout)->toBe(120.0)
        ->and($config->connectTimeout)->toBe(10.0)
        ->and($config->userAgent)->toContain('lenorix-datadis-client/');
});

it('normalises the base url and the username', function () {
    $config = new DatadisConfig(' 12345678z ', 'secret', baseUrl: 'https://datadis.test/');

    expect($config->baseUrl)->toBe('https://datadis.test')
        ->and($config->username)->toBe('12345678Z');
});

it('refuses invalid configuration before anything is sent', function (array $arguments) {
    try {
        new DatadisConfig(...$arguments);
    } catch (ConfigurationException $e) {
        expect($e->requestSent)->toBeFalse();

        return;
    }

    throw new LogicException('Expected a ConfigurationException.');
})->with([
    'empty username' => [['username' => '', 'password' => 'x']],
    'blank username' => [['username' => '  ', 'password' => 'x']],
    'empty password' => [['username' => '12345678Z', 'password' => '']],
    'plain http' => [['username' => '12345678Z', 'password' => 'x', 'baseUrl' => 'http://datadis.es']],
    'not a url' => [['username' => '12345678Z', 'password' => 'x', 'baseUrl' => 'datadis.es']],
    'url with credentials' => [['username' => '12345678Z', 'password' => 'x', 'baseUrl' => 'https://user:pass@datadis.es']],
    'zero timeout' => [['username' => '12345678Z', 'password' => 'x', 'timeout' => 0.0]],
    'negative connect timeout' => [['username' => '12345678Z', 'password' => 'x', 'connectTimeout' => -1.0]],
    'empty user agent' => [['username' => '12345678Z', 'password' => 'x', 'userAgent' => '']],
    'user agent with newline' => [['username' => '12345678Z', 'password' => 'x', 'userAgent' => "a\r\nX-Evil: 1"]],
]);

it('never shows the password when dumped', function () {
    $config = new DatadisConfig('12345678Z', 'super-secret-password');

    ob_start();
    var_dump($config);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('super-secret-password')
        ->and(print_r($config, true))->not->toContain('super-secret-password')
        ->and(var_export($config, true))->not->toContain('super-secret-password');
});

it('cannot be serialised with the password', function () {
    serialize(new DatadisConfig('12345678Z', 'super-secret-password'));
})->throws(LogicException::class);

it('shows every setting but the password when debugged', function () {
    $config = new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test', timeout: 30.0, connectTimeout: 3.0, userAgent: 'agent');

    expect($config->__debugInfo())->toBe([
        'username' => '12345678Z',
        'baseUrl' => 'https://datadis.test',
        'password' => '[hidden]',
        'timeout' => 30.0,
        'connectTimeout' => 3.0,
        'userAgent' => 'agent',
    ]);
});
