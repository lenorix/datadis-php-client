<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\ExactJson;

it('keeps every digit of a decimal JSON number as text', function () {
    expect(ExactJson::decode('{"v":0.123456789012345678901,"w":[-12.5e-3,1E+2],"i":7,"big":123456789012345678901234}'))
        ->toBe(['v' => '0.123456789012345678901', 'w' => ['-12.5e-3', '1E+2'], 'i' => 7, 'big' => '123456789012345678901234']);
});

it('leaves the text of JSON strings alone, escaped quotes included', function () {
    expect(ExactJson::decode('["0.5", "a \\"1.5\\" b", "\\\\", 2.5]'))->toBe(['0.5', 'a "1.5" b', '\\', '2.5']);
});

it('keeps true, false, null and integers as they are', function () {
    expect(ExactJson::decode('{"a":true,"b":false,"c":null,"d":-0,"e":0}'))->toBe(['a' => true, 'b' => false, 'c' => null, 'd' => 0, 'e' => 0]);
});

it('leaves a number too long to be read as a decimal to the float it always was', function () {
    $long = '0.'.str_repeat('1', 80);

    expect(ExactJson::decode("[{$long}]"))->toBe([(float) $long]);
});

it('still reports text that is not JSON', function (string $json) {
    expect(ExactJson::decode($json))->toBeNull()->and(json_last_error())->not->toBe(JSON_ERROR_NONE);
})->with(['[1.5', '{"a":1.}', '01.5', '[1.5 2.5]']);
