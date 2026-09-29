<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Datadis rejected the parameters (400 and other 4xx). Permanent: never resend the identical call,
 * because a rejected request still burns the 24 hour repetition window.
 */
final class RequestRejectedException extends DatadisException {}
