<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Lenorix\DatadisClient\Support\PersonalDataRedactor;
use RuntimeException;
use Throwable;

/**
 * Base of every failure raised by this package.
 *
 * `requestSent` says whether the request may have reached Datadis. It is false only for pre-flight
 * failures (configuration, parameter validation, a login that failed before the data request).
 * PSR-18 cannot tell "never sent" from "sent, no answer" (a read timeout is a network exception),
 * so anything that happens while a data request is in flight counts as possibly sent. That matters
 * because Datadis refuses an identical query for 24 hours.
 *
 * The message and the detail are redacted here, so a subclass that interpolates a CUPS or a NIF
 * still cannot leak it.
 */
abstract class DatadisException extends RuntimeException
{
    /** A redacted, single-line excerpt of what Datadis answered, when it answered. */
    public readonly ?string $detail;

    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?string $detail = null,
        public readonly ?string $endpoint = null,
        public readonly bool $requestSent = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct(PersonalDataRedactor::redact($message), 0, $previous);

        $this->detail = $detail === null ? null : PersonalDataRedactor::excerpt($detail);
    }
}
