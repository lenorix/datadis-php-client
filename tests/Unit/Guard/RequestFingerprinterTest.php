<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;

const KEY = 'a-secret-key-of-at-least-32-bytes!!';

$query = [
    'cups' => 'ES0031300000000001JN0F',
    'distributorCode' => '2',
    'startDate' => '2026/01',
    'endDate' => '2026/01',
    'measurementType' => '0',
    'pointType' => 5,
    'authorizedNif' => null,
];

it('is a stable hex digest', function () use ($query) {
    $fingerprint = (new RequestFingerprinter(KEY))->fingerprint('12345678Z', $query);

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and((new RequestFingerprinter(KEY))->fingerprint('12345678Z', $query))->toBe($fingerprint);
});

it('does not depend on the order of the query keys or on keys it does not know', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('12345678Z', array_reverse($query, true) + ['extra' => 'x']))
        ->toBe($fingerprinter->fingerprint('12345678Z', $query));
});

it('treats a point type sent as int or string alike, as the wire does', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('12345678Z', ['pointType' => '5'] + $query))->toBe($fingerprinter->fingerprint('12345678Z', $query));
});

it('changes with every parameter, the account and the key', function (string $field, mixed $value) use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('12345678Z', [$field => $value] + $query))->not->toBe($fingerprinter->fingerprint('12345678Z', $query));
})->with([
    ['cups', 'ES0031300000000002JN'],
    ['distributorCode', '3'],
    ['startDate', '2025/12'],
    ['endDate', '2026/02'],
    ['measurementType', '1'],
    ['pointType', 4],
    ['authorizedNif', '87654321X'],
]);

it('distinguishes accounts and keys', function () use ($query) {
    $fingerprint = (new RequestFingerprinter(KEY))->fingerprint('12345678Z', $query);

    expect((new RequestFingerprinter(KEY))->fingerprint('87654321X', $query))->not->toBe($fingerprint)
        ->and((new RequestFingerprinter(KEY.'2'))->fingerprint('12345678Z', $query))->not->toBe($fingerprint);
});

it('tells an omitted parameter from an empty one', function () use ($query) {
    $fingerprinter = new RequestFingerprinter(KEY);

    expect($fingerprinter->fingerprint('12345678Z', ['authorizedNif' => ''] + $query))->not->toBe($fingerprinter->fingerprint('12345678Z', $query));
});

it('refuses a short key', function () {
    new RequestFingerprinter('short');
})->throws(ConfigurationException::class);

it('does not reveal the key when dumped', function () {
    expect(print_r(new RequestFingerprinter(KEY), true))->not->toContain(KEY);
});
