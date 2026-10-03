<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Login failed, or a call was refused with 401: after one new attempt for a call that is safe to
 * repeat, at once for a guarded query or a change, which are never sent twice. A login refused
 * means the credentials must be fixed; a 401 on a data call may also be a token Datadis stopped
 * taking, which the next call replaces.
 */
final class AuthenticationException extends DatadisException {}
