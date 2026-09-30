<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\SystemClock;

it('tells the current time', function () {
    $before = time();
    $now = (new SystemClock)->now()->getTimestamp();

    expect($now)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time());
});
