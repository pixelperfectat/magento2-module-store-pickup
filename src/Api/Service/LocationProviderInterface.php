<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Source of pickup locations.
 *
 * When several providers are combined, the first provider in `di.xml` order that knows a code owns it, whether its
 * record is active or not. A later provider's record with the same code is never used.
 *
 * @api
 */
interface LocationProviderInterface
{
    /**
     * Active pickup locations of a store view, keyed by code, sorted by sort order and then by code.
     *
     * Only codes whose owner (the first provider that knows the code) has an active record are listed; a code whose
     * owner has it inactive is missing even when a later provider has it active.
     *
     * PHP turns a numeric code such as "7" into an int array key, so consumers must read the code from
     * `getCode()`, never from the key.
     *
     * @param int $storeId
     * @return array<string, PickupLocationInterface>
     */
    public function getLocations(int $storeId): array;

    /**
     * One pickup location by code, whether it is active or not; null when the code is unknown.
     *
     * Returns the owner's record: that of the first provider that knows the code, active or not.
     *
     * @param string $code
     * @param int $storeId
     * @return PickupLocationInterface|null
     */
    public function getLocation(string $code, int $storeId): ?PickupLocationInterface;
}
