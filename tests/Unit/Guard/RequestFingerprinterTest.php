<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Tests\Support\Scenario;

const KEY = 'a-secret-key-of-at-least-32-bytes!!';

$query = [
    'cups' => 'ES0000000000000000AA0A',
    'distributorCode' => '2',
    'startDate' => '2026/01',
    'endDate' => '2026/01',
    'measurementType' => '0',
    'pointType' => 5,
    'authorizedNif' => null,
];

it('is a stable hex digest', function () use ($query) {
    $fingerprint = (new RequestFingerprinter(KEY))->fingerprint('A00000000', $query);

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and((new RequestFingerprinter(KEY))->fingerprint('A00000000', $query))->toBe($fingerprint);
});

it('does not depend on the order of the query keys or on keys it does not know', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('A00000000', array_reverse($query, true) + ['extra' => 'x']))
        ->toBe($fingerprinter->fingerprint('A00000000', $query));
});

it('treats a point type sent as int or string alike, as the wire does', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('A00000000', ['pointType' => '5'] + $query))->toBe($fingerprinter->fingerprint('A00000000', $query));
});

it('changes with every parameter, the account and the key', function (string $field, mixed $value) use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('A00000000', [$field => $value] + $query))->not->toBe($fingerprinter->fingerprint('A00000000', $query));
})->with([
    ['cups', Scenario::otherCups()],
    ['distributorCode', '3'],
    ['startDate', '2025/12'],
    ['endDate', '2026/02'],
    ['measurementType', '1'],
    ['pointType', 4],
    ['authorizedNif', '00000000T'],
]);

it('distinguishes accounts and keys', function () use ($query) {
    $fingerprint = (new RequestFingerprinter(KEY))->fingerprint('A00000000', $query);

    expect((new RequestFingerprinter(KEY))->fingerprint('00000000T', $query))->not->toBe($fingerprint)
        ->and((new RequestFingerprinter(KEY.'2'))->fingerprint('A00000000', $query))->not->toBe($fingerprint);
});

it('tells an omitted parameter from an empty one', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('A00000000', ['authorizedNif' => ''] + $query))->not->toBe($fingerprinter->fingerprint('A00000000', $query));
});

it('refuses a short key', function () {
    new RequestFingerprinter('short');
})->throws(ConfigurationException::class);

it('does not reveal the key when dumped', function () {
    expect(print_r(new RequestFingerprinter(KEY), true))->not->toContain(KEY);
});

it('fingerprints a list value by its items', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('A00000000', ['cups' => ['A', 'B']] + $query))
        ->not->toBe($fingerprinter->fingerprint('A00000000', ['cups' => ['A']] + $query))
        ->toBe($fingerprinter->fingerprint('A00000000', ['cups' => ['A', 'B']] + $query));
});

it('keeps the same fingerprint across versions, since fingerprints live in shared stores', function () use ($query) {
    expect((new RequestFingerprinter(KEY))->fingerprint('A00000000', $query))
        ->toBe(hash_hmac('sha256', '["datadis-query","v1","A00000000",["ES0000000000000000AA0A","2","2026/01","2026/01","0","5",null]]', KEY));
});

it('accepts a key of exactly the minimum length', function () {
    expect(new RequestFingerprinter(str_repeat('k', RequestFingerprinter::MIN_KEY_BYTES)))->toBeInstanceOf(RequestFingerprinter::class);
});

it('shows the key as hidden when debugged', function () {
    expect((new RequestFingerprinter(KEY))->__debugInfo())->toBe(['key' => '[hidden]']);
});
