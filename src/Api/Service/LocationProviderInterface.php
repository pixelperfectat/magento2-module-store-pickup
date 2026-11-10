<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Source of pickup locations.
 *
 * @api
 */
interface LocationProviderInterface
{
    /**
     * Active pickup locations of a store view, keyed by code, sorted by sort order and then by code.
     *
     * @param int $storeId
     * @return array<string, PickupLocationInterface>
     */
    public function getLocations(int $storeId): array;

    /**
     * One pickup location by code, whether it is active or not; null when the code is unknown.
     *
     * @param string $code
     * @param int $storeId
     * @return PickupLocationInterface|null
     */
    public function getLocation(string $code, int $storeId): ?PickupLocationInterface;
}
