<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Lenorix\DatadisClient\Time\Month;

/**
 * The months asked for a supply fall outside its contract: before the month it started, or after the
 * month it ended. Datadis refuses such a query, and the refusal counts for 24 hours, so it is
 * refused here first. Nothing was sent.
 */
final class OutOfContractRangeException extends InvalidRequestException
{
    /**
     * @param  Month|null  $contractStart  the month the contract started, when the range starts before it
     * @param  Month|null  $contractEnd  the month the contract ended, when the range ends after it
     */
    public function __construct(
        string $message,
        public readonly ?Month $contractStart = null,
        public readonly ?Month $contractEnd = null,
    ) {
        parent::__construct($message);
    }
}
