<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\TextNormalizer;

it('lowercases, removes accents and collapses whitespace', function (string $input, string $expected) {
    expect(TextNormalizer::normalize($input))->toBe($expected);
})->with([
    ['BAJA TENSIÓN  y   POTENCIA', 'baja tension y potencia'],
    ["Maxímetro\n\tÑandú", 'maximetro nandu'],
    ['  EDISTRIBUCIÓN REDES  ', 'edistribucion redes'],
    ['Ü ç À È Ì Ò Ù â ê î ô û', 'u c a e i o u a e i o u'],
    ['≤ 15 kW', '15 kw'],
    ['', ''],
]);

it('drops what has no ASCII form and survives broken UTF-8', function () {
    expect(TextNormalizer::normalize("a€b\xC3"))->toBe('ab');
});
