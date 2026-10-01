<?php

declare(strict_types=1);

use Eris\Generators;
use GuzzleHttp\Psr7\Response;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Http\ResponseClassifier;

it('either decodes JSON or throws a DatadisException, whatever the status and bytes', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(100, 599), Generators::string(), Generators::elements('', "\x1f\x8b", "\xEF\xBB\xBF", '{', '[', '"'))
        ->then(function (int $status, string $body, string $prefix) {
            try {
                $decoded = ResponseClassifier::decode(new Response($status, [], $prefix.$body), 'get-supplies-v2');

                expect($decoded)->toBeArray()
                    ->and($status)->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
            } catch (DatadisException $e) {
                expect($e->getMessage())->toStartWith('get-supplies-v2: ')
                    ->and($e->requestSent)->toBeTrue();
            }
        });
});

it('always throws for non-2xx statuses and never leaks an identifier', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(300, 599), Generators::string())
        ->then(function (int $status, string $noise) {
            $body = $noise.' ES0000000000000000AA0A '.$noise.' A00000000';

            try {
                ResponseClassifier::decode(new Response($status, [], $body), 'get-supplies-v2');
                throw new LogicException('Expected an exception.');
            } catch (DatadisException $e) {
                expect($e->getMessage())->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000')
                    ->and((string) $e->detail)->not->toContain('ES0000000000000000AA0A');
            }
        });
});
