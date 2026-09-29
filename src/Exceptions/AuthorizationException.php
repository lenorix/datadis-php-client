<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * A data call was refused with 403: the authorizedNif has no valid authorization for that CUPS,
 * or the stored distributorCode/pointType are stale. Permanent until consent or codes are fixed.
 */
final class AuthorizationException extends DatadisException {}
