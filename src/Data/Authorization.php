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
 * Verified against a real answer (October 2026): a bare list whose validity dates carry a time of
 * day (`2026-06-01 00:00:00.0` to `2028-06-01 23:59:59.0`); `status` is `VIGENTE` or `CANCELADA`.
 * A date without a time is read too.
 */
final readonly class Authorization
{
    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public ?string $id,
        public ?string $ownerDocument,
        public ?string $requesterDocument,
        public ?string $cups,
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
            Fields::nonEmptyText($row, 'cups'),
            Fields::nonEmptyText($row, 'status'),
            self::date(Fields::nonEmptyText($row, 'validityDateStart'), $zone),
            self::date(Fields::nonEmptyText($row, 'validityDateEnd'), $zone),
            Fields::nonEmptyText($row, 'distributorCodeFather'),
            $row,
        );
    }

    private static function date(?string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        return $value === null ? null : DatadisDate::tryParseDateTime($value, $zone) ?? DatadisDate::tryParse($value, $zone);
    }
}
