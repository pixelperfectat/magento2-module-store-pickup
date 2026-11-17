<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Quote\Model\Quote\Address\RateRequest;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Decides whether a pickup location can be offered for a rate request.
 *
 * @api
 */
interface AvailabilityCheckerInterface
{
    /**
     * Whether the location is offered for this request.
     *
     * @param PickupLocationInterface $location
     * @param RateRequest $request
     * @return bool
     */
    public function isAvailable(PickupLocationInterface $location, RateRequest $request): bool;
}
