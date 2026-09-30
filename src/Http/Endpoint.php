<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Lenorix\DatadisClient\ApiVersion;

/**
 * The calls of the private API, and what may be done with each: which ones Datadis refuses to
 * repeat for 24 hours and which ones may be sent again after a network failure.
 *
 * @internal
 */
enum Endpoint: string
{
    private const string PREFIX = '/api-private/api/';

    case Supplies = 'get-supplies';
    case Distributors = 'get-distributors-with-supplies';
    case ContractDetail = 'get-contract-detail';
    case Consumption = 'get-consumption-data';
    case MaxPower = 'get-max-power';
    case Reactive = 'get-reactive-data';
    case Groups = 'get-groups';
    case NewAuthorization = 'new-authorization';
    case CancelAuthorization = 'cancel-authorization';
    case Authorizations = 'list-authorization';
    case PartnerUsers = 'partner-user-list';
    case PartnerDeleteUser = 'partner-delete-user';
    case PartnerAgreementDate = 'partner-agreement-date';

    /** The call a private API path names, in either version, whatever comes before the API prefix. */
    public static function fromPath(string $path): ?self
    {
        if (preg_match('#'.preg_quote(self::PREFIX, '#').'([a-z-]+?)(?:-v2)?$#D', $path, $m) !== 1) {
            return null;
        }

        return self::tryFrom($m[1]);
    }

    /** The name as it appears in the path and in exceptions: v1 and v2 have their own for the data calls. */
    public function name(ApiVersion $version): string
    {
        return match ($this) {
            self::Reactive, self::Groups => $this->value.ApiVersion::V2->suffix(),
            self::NewAuthorization, self::CancelAuthorization, self::Authorizations,
            self::PartnerUsers, self::PartnerDeleteUser, self::PartnerAgreementDate => $this->value,
            default => $this->value.$version->suffix(),
        };
    }

    public function path(ApiVersion $version): string
    {
        return self::PREFIX.$this->name($version);
    }

    /** Datadis refuses the identical query for 24 hours, and a rejected one counts. */
    public function isGuarded(): bool
    {
        return in_array($this, [self::Consumption, self::MaxPower, self::Reactive], true);
    }

    /** Sending it twice changes nothing and costs nothing, so a network failure may be retried. */
    public function isSafeToRepeat(): bool
    {
        return in_array($this, [self::Supplies, self::Distributors, self::ContractDetail, self::Groups, self::Authorizations, self::PartnerUsers, self::PartnerAgreementDate], true);
    }
}
