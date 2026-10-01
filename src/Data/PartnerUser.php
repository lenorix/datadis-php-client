<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Decoding\Fields;
use SensitiveParameter;

/**
 * A user linked to the partner account (`partner-user-list`), with Datadis's keys. Verified against a
 * real answer (October 2026): `registrationDate` comes as milliseconds since the epoch.
 */
final readonly class PartnerUser
{
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['name', 'document', 'email', 'raw'];

    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public ?string $name,
        public ?string $document,
        public ?string $email,
        public ?DateTimeImmutable $registrationDate,
        public ?bool $registerApp,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @return self|null null when the row has neither a document nor a name
     */
    public static function fromRow(#[SensitiveParameter] array $row, DateTimeZone $zone): ?self
    {
        $document = Fields::nonEmptyText($row, 'document');
        $name = Fields::nonEmptyText($row, 'name');

        if ($document === null && $name === null) {
            return null;
        }

        $milliseconds = Fields::integer($row, 'registrationDate');
        $registerApp = $row['registerApp'] ?? null;

        return new self(
            $name,
            $document,
            Fields::nonEmptyText($row, 'email'),
            $milliseconds === null ? null : (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))->setTimezone($zone),
            is_bool($registerApp) ? $registerApp : null,
            $row,
        );
    }
}
