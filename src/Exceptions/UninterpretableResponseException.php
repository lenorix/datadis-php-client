<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * Datadis answered but the body cannot be used (not JSON, wrong shape, unusable rows, bad dates
 * or numbers). Not blindly retried.
 */
final class UninterpretableResponseException extends DatadisException {}
