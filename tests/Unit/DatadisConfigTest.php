<?php

declare(strict_types=1);

use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;

it('has sensible defaults', function () {
    $config = new DatadisConfig('A00000000', 'secret');

    expect($config->baseUrl)->toBe('https://datadis.es')
        ->and($config->timeout)->toBe(120.0)
        ->and($config->connectTimeout)->toBe(10.0)
        ->and($config->userAgent)->toContain('lenorix-datadis-client');
});

it('normalises the base url and the username', function () {
    $config = new DatadisConfig(' a00000000 ', 'secret', baseUrl: 'https://datadis.test/');

    expect($config->baseUrl)->toBe('https://datadis.test')
        ->and($config->username)->toBe('A00000000');
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
    'empty password' => [['username' => 'A00000000', 'password' => '']],
    'plain http' => [['username' => 'A00000000', 'password' => 'x', 'baseUrl' => 'http://datadis.es']],
    'not a url' => [['username' => 'A00000000', 'password' => 'x', 'baseUrl' => 'datadis.es']],
    'url with credentials' => [['username' => 'A00000000', 'password' => 'x', 'baseUrl' => 'https://user:pass@datadis.es']],
    'zero timeout' => [['username' => 'A00000000', 'password' => 'x', 'timeout' => 0.0]],
    'negative connect timeout' => [['username' => 'A00000000', 'password' => 'x', 'connectTimeout' => -1.0]],
    'empty user agent' => [['username' => 'A00000000', 'password' => 'x', 'userAgent' => '']],
    'user agent with newline' => [['username' => 'A00000000', 'password' => 'x', 'userAgent' => "a\r\nX-Evil: 1"]],
]);

it('never shows the password when dumped', function () {
    $config = new DatadisConfig('A00000000', 'super-secret-password');

    ob_start();
    var_dump($config);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('super-secret-password')
        ->and(print_r($config, true))->not->toContain('super-secret-password')
        ->and(var_export($config, true))->not->toContain('super-secret-password');
});

it('cannot be serialised with the password', function () {
    serialize(new DatadisConfig('A00000000', 'super-secret-password'));
})->throws(LogicException::class);

it('shows every setting but the password when debugged', function () {
    $config = new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test', timeout: 30.0, connectTimeout: 3.0, userAgent: 'agent');

    expect($config->__debugInfo())->toBe([
        'username' => 'A00000000',
        'baseUrl' => 'https://datadis.test',
        'password' => '[hidden]',
        'timeout' => 30.0,
        'connectTimeout' => 3.0,
        'userAgent' => 'agent',
    ]);
});
