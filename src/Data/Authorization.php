<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Time\DatadisDate;
use SensitiveParameter;

/**
 * An authorization between a supply owner and a third party (v1 `list-authorization`).
 *
 * UNVERIFIED: the field names come from the manual only; no real answer has been captured. Dates are
 * read with slashes or dashes because the format is not documented.
 */
final readonly class Authorization
{
    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public ?string $id,
        public ?string $ownerDocument,
        public ?string $requesterDocument,
        public ?string $status,
        public ?DateTimeImmutable $validityDateStart,
        public ?DateTimeImmutable $validityDateEnd,
        public ?string $distributorCodeFather,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @return self|null null when the row has neither an id nor a document
     */
    public static function fromRow(#[SensitiveParameter] array $row, DateTimeZone $zone): ?self
    {
        $id = Fields::nonEmptyText($row, 'id');
        $owner = Fields::nonEmptyText($row, 'ownerDocument');
        $requester = Fields::nonEmptyText($row, 'requesterDocument');

        if ($id === null && $owner === null && $requester === null) {
            return null;
        }

        return new self(
            $id,
            $owner,
            $requester,
            Fields::nonEmptyText($row, 'status'),
            self::date(Fields::nonEmptyText($row, 'validityDateStart'), $zone),
            self::date(Fields::nonEmptyText($row, 'validityDateEnd'), $zone),
            Fields::nonEmptyText($row, 'distributorCodeFather'),
            $row,
        );
    }

    private static function date(?string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return DatadisDate::tryParse(trim($value), $zone) ?? DatadisDate::tryParseDashed(trim($value), $zone);
    }
}
