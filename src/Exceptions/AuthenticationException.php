<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Login failed, or a data call was refused with 401 even after one automatic re-login.
 * Retrying does not help until the credentials are fixed.
 */
final class AuthenticationException extends DatadisException {}
