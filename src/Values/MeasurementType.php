<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Values;

/**
 * The `measurementType` query parameter of the consumption endpoint.
 *
 * Quarter-hourly data is only offered for some point types (1 and 2, and 3 for one distributor).
 */
enum MeasurementType: string
{
    case Hourly = '0';
    case QuarterHourly = '1';
}
