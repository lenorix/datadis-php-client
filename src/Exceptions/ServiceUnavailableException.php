<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Datadis or a distributor failed (5xx), or the login endpoint answered with something other than
 * a token. Only unguarded endpoints may be retried automatically.
 */
class ServiceUnavailableException extends DatadisException {}
