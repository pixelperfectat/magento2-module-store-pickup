<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service;

use PixelPerfect\StorePickup\Api\Service\LocationUsageInterface;
use PixelPerfect\StorePickup\Model\ResourceModel\LocationUsage as LocationUsageResource;

/**
 * Looks up the open carts and orders of a pickup location code.
 */
class LocationUsage implements LocationUsageInterface
{
    /**
     * Constructor.
     *
     * @param LocationUsageResource $resource
     */
    public function __construct(private readonly LocationUsageResource $resource)
    {
    }

    /**
     * @inheritDoc
     */
    public function isInUse(string $code): bool
    {
        return $this->resource->countOpenQuotes($code) > 0 || $this->resource->countOpenOrders($code) > 0;
    }
}
