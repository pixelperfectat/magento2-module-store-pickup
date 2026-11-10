<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Data;

/**
 * A store pickup location.
 *
 * @api
 */
interface PickupLocationInterface
{
    public const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    /**
     * Stable location code, matching CODE_PATTERN; it is the rate method code.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Display name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Street lines.
     *
     * @return list<string>
     */
    public function getStreet(): array;

    /**
     * Postcode.
     *
     * @return string
     */
    public function getPostcode(): string;

    /**
     * City.
     *
     * @return string
     */
    public function getCity(): string;

    /**
     * ISO country id.
     *
     * @return string
     */
    public function getCountryId(): string;

    /**
     * Directory region id, if any.
     *
     * @return int|null
     */
    public function getRegionId(): ?int;

    /**
     * Free-text region, if any.
     *
     * @return string|null
     */
    public function getRegion(): ?string;

    /**
     * Telephone number, if any.
     *
     * @return string|null
     */
    public function getTelephone(): ?string;

    /**
     * Opening hours text, if any.
     *
     * @return string|null
     */
    public function getOpeningHours(): ?string;

    /**
     * Customer-facing note, if any.
     *
     * @return string|null
     */
    public function getNote(): ?string;

    /**
     * Price override for this location; null means the carrier default.
     *
     * @return float|null
     */
    public function getPrice(): ?float;

    /**
     * Sort order.
     *
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * Whether the location is active.
     *
     * @return bool
     */
    public function isActive(): bool;

    /**
     * Identifier of the stock source bound to this location, if any.
     *
     * @return string|null
     */
    public function getStockBinding(): ?string;
}
