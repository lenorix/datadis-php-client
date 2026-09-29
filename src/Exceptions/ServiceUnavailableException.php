<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Datadis or a distributor failed (5xx, also on login). Only unguarded endpoints may be retried
 * automatically. A login answer that is not a token is an UninterpretableResponseException.
 */
class ServiceUnavailableException extends DatadisException {}
