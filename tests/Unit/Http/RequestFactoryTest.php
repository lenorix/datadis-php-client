<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Tests\Support\Scenario;

function account(): DatadisConfig
{
    return new DatadisConfig('A00000000', 'p&ss=w rd/é', baseUrl: 'https://datadis.test');
}

function requests(?DatadisConfig $config = null): RequestFactory
{
    $factory = new HttpFactory;

    return new RequestFactory(($config ?? account())->connection(), $factory, $factory);
}

it('builds the login request with credentials in a form body, never in the url', function () {
    $request = requests()->login(account());
    parse_str((string) $request->getBody(), $form);

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://datadis.test/nikola-auth/tokens/login')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and($form)->toBe(['username' => 'A00000000', 'password' => 'p&ss=w rd/é'])
        ->and((string) $request->getUri())->not->toContain('p&ss')->not->toContain('A00000000');
});

it('sends the headers Datadis needs on every data call', function () {
    $request = requests()->get('/api-private/api/get-supplies-v2', [], 'jwt-token');

    expect($request->getMethod())->toBe('GET')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept-Encoding'))->toBe('identity')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer jwt-token')
        ->and($request->getHeaderLine('User-Agent'))->toContain('lenorix-datadis-client');
});

it('sends the headers on the login request too', function () {
    $request = requests()->login(account());

    expect($request->getHeaderLine('Accept-Encoding'))->toBe('identity')
        ->and($request->getHeaderLine('User-Agent'))->toContain('lenorix-datadis-client')
        ->and($request->hasHeader('Authorization'))->toBeFalse();
});

it('drops null query values and encodes the rest', function () {
    $request = requests()->get('/api-private/api/get-max-power-v2', [
        'cups' => 'ES0000000000000000AA0A',
        'startDate' => '2025/03',
        'authorizedNif' => null,
        'pointType' => 5,
    ], 't');

    parse_str($request->getUri()->getQuery(), $query);

    expect($query)->toBe(['cups' => 'ES0000000000000000AA0A', 'startDate' => '2025/03', 'pointType' => '5'])
        ->and($request->getUri()->getQuery())->toContain('startDate=2025%2F03')
        ->and($request->getUri()->getQuery())->not->toContain('authorizedNif');
});

it('omits the question mark when there is no query', function () {
    expect((string) requests()->get('/api-private/api/get-supplies-v2', ['authorizedNif' => null], 't')->getUri())
        ->toBe('https://datadis.test/api-private/api/get-supplies-v2');
});

it('refuses a token that could inject headers', function (string $token) {
    requests()->get('/x', [], $token);
})->with(["a\r\nX-Evil: 1", 'has space', '', "tab\tsep"])->throws(InvalidArgumentException::class);

it('repeats the key for list values, which is how array parameters are bound', function () {
    $request = requests()->get('/api-private/api/new-authorization', [
        'authorizedNif' => '00000000T',
        'cups' => ['ES0000000000000000AA0A', Scenario::otherCups()],
    ], 't');

    expect($request->getUri()->getQuery())->toBe('authorizedNif=00000000T&cups=ES0000000000000000AA0A&cups='.Scenario::otherCups());
});

it('drops an empty list', function () {
    expect(requests()->get('/x', ['cups' => []], 't')->getUri()->getQuery())->toBe('');
});

it('refuses list values that are not strings', function () {
    requests()->get('/x', ['cups' => [1, null]], 't');
})->throws(InvalidArgumentException::class);

it('builds unauthenticated requests for the public API', function () {
    $factory = new HttpFactory;
    $requests = new RequestFactory(new ConnectionSettings(baseUrl: 'https://datadis.test'), $factory, $factory);

    $request = $requests->publicGet('/api-public/api-search', ['page' => 0, 'community' => '01,13']);

    expect($request->hasHeader('Authorization'))->toBeFalse()
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept-Encoding'))->toBe('identity')
        ->and((string) $request->getUri())->toBe('https://datadis.test/api-public/api-search?page=0&community=01%2C13');
});

it('refuses query values of other types and maps instead of lists', function (mixed $value) {
    requests()->get('/x', ['v' => $value], 't');
})->with([[1.5], [true], [['a' => 'b']]])->throws(InvalidArgumentException::class);
