<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Group;

it('reads a group', function () {
    $group = Group::fromRow(['name' => 'Oficinas', 'description' => 'Suministros de oficinas', 'extra' => 1]);

    expect($group->name)->toBe('Oficinas')
        ->and($group->description)->toBe('Suministros de oficinas')
        ->and($group->raw)->toHaveKey('extra');
});

it('needs a name', function (array $row) {
    expect(Group::fromRow($row))->toBeNull();
})->with([[[]], [['name' => '']], [['description' => 'x']]]);
