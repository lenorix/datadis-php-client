<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

/**
 * A data call was refused because the account may not read that supply: a 403 (the authorizedNif
 * has no authorized supplies, or the stored distributorCode/pointType are stale), or the 400
 * "No se encuentra autorizado el cups introducido" (the CUPS is not the account's or its holder's,
 * or it was not sent exactly as the supplies list gives it). Permanent until consent or codes are fixed.
 */
final class AuthorizationException extends DatadisException {}
