<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Values\MeasurementType;

it('uses the wire values 0 for hourly and 1 for quarter-hourly', function () {
    expect(MeasurementType::Hourly->value)->toBe('0')
        ->and(MeasurementType::QuarterHourly->value)->toBe('1')
        ->and(MeasurementType::from('1'))->toBe(MeasurementType::QuarterHourly);
});
