<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use Lenorix\DatadisClient\Decoding\Fields;
use SensitiveParameter;

/**
 * A group of supplies defined in the account (v2 `get-groups-v2`).
 *
 * The official documentation lists only `name` and `description`; the rest of the row is kept in `raw`.
 */
final readonly class Group
{
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['name', 'description', 'raw'];

    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public string $name,
        public ?string $description,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @return self|null null when the row has no name
     */
    public static function fromRow(#[SensitiveParameter] array $row): ?self
    {
        $name = Fields::nonEmptyText($row, 'name');

        return $name === null ? null : new self($name, Fields::text($row, 'description'), $row);
    }
}
