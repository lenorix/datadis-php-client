<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\Cups;

it('accepts 20 and 22 character CUPS and normalises case and whitespace', function () {
    expect(Cups::fromString('ES0000000000000000AA')->value())->toBe('ES0000000000000000AA')
        ->and(Cups::fromString('  es0000000000000000aa0a ')->value())->toBe('ES0000000000000000AA0A')
        ->and('CUPS '.Cups::fromString(' es0000000000000000aa '))->toBe('CUPS ES0000000000000000AA');
});

it('rejects values that are not CUPS shaped', function (string $value) {
    expect(Cups::isValid($value))->toBeFalse();
    Cups::fromString($value);
})->with([
    'too short' => 'ES000000000000000',
    'digits missing' => 'ES000000000000000AA',
    'letters in digits' => 'ES00000000000000A0AA',
    'one trailing char' => 'ES0000000000000000AA0',
    'two trailing letters' => 'ES0000000000000000AAAB',
    'extra invoice chars' => 'ES0000000000000000AA0A123',
    'wrong country' => 'PT0031300000000001JN',
    'empty' => '',
])->throws(InvalidArgumentException::class);

it('exposes the 20 character base and matches on it', function () {
    $long = Cups::fromString('ES0000000000000000AA0A');
    $short = Cups::fromString('ES0000000000000000AA');

    expect($long->base())->toBe('ES0000000000000000AA')
        ->and($short->base())->toBe('ES0000000000000000AA')
        ->and($long->matches($short))->toBeTrue()
        ->and($long->matches(Cups::fromString(Scenario::otherCups())))->toBeFalse();
});

it('says a well formed CUPS is valid', function () {
    expect(Cups::isValid(' es0000000000000000aa0a '))->toBeTrue();
});

it('keeps its value out of var_dump, print_r, debug_zval_dump and var_export', function () {
    $cups = Cups::fromString(Scenario::CUPS);

    ob_start();
    var_dump($cups);
    debug_zval_dump($cups);

    expect((string) ob_get_clean().print_r($cups, true).var_export($cups, true))->not->toContain(Scenario::CUPS)->toContain('[hidden]');
});

it('keeps its value through serialize, which stores it on purpose', function () {
    $cups = unserialize(serialize(Cups::fromString(' es0000000000000000aa0a ')));

    expect($cups)->toBeInstanceOf(Cups::class)
        ->and($cups->value())->toBe(Scenario::CUPS)
        ->and($cups->matches(Cups::fromString(Scenario::CUPS)))->toBeTrue();
});

it('refuses to unserialize a value that is not a CUPS', function (string $tampered) {
    unserialize(str_replace('s:5:"value";s:22:"'.Scenario::CUPS.'";', $tampered, serialize(Cups::fromString(Scenario::CUPS))));
})->with([
    'not a CUPS' => ['s:5:"value";s:3:"abc";'],
    'not a string' => ['s:5:"value";i:1;'],
    'no value' => ['s:5:"other";s:3:"abc";'],
])->throws(InvalidArgumentException::class);
