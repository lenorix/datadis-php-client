<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

it('sends each CUPS once, in order, and refuses duplicates before sending', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::seq(Generators::choose(0, 5)))
        ->then(function (array $picks) {
            $pool = array_map(fn (int $i) => Cups::fromString(sprintf('ES00313000000000%02dJN', $i)), range(0, 5));
            $cups = array_map(fn (int $i) => $pool[$i], $picks);
            $s = Scenario::make();
            $s->http->queue(Responses::empty(200));

            try {
                $s->client->newAuthorization(Nif::fromString('87654321X'), null, null, ...$cups);
            } catch (InvalidRequestException) {
                expect(count(array_unique($picks)))->toBeLessThan(count($picks))
                    ->and($s->http->requests())->toBe([]);

                return;
            }

            $query = $s->http->requests()[1]->getUri()->getQuery();
            preg_match_all('/cups=([^&]+)/', $query, $m);

            expect($m[1])->toBe(array_map(fn (Cups $c) => $c->value(), $cups))
                ->and(count(array_unique($picks)))->toBe(count($picks));
        });
});

it('never authorizes the account itself, however it is written', function (string $written) {
    $s = Scenario::make();
    $nif = Nif::fromString($written);

    expect(fn () => $s->client->newAuthorization($nif))->toThrow(InvalidRequestException::class)
        ->and(fn () => $s->client->cancelAuthorization($nif))->toThrow(InvalidRequestException::class)
        ->and($s->http->requests())->toBe([]);
})->with(['12345678Z', '12345678z', ' 12345678Z ', "\t12345678z\t"]);
