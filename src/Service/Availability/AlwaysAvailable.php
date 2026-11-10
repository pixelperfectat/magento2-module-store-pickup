<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\Availability;

use Magento\Quote\Model\Quote\Address\RateRequest;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\AvailabilityCheckerInterface;

/**
 * Default checker: every location is available.
 */
class AlwaysAvailable implements AvailabilityCheckerInterface
{
    /**
     * @inheritDoc
     */
    public function isAvailable(PickupLocationInterface $location, RateRequest $request): bool
    {
        return true;
    }
}
