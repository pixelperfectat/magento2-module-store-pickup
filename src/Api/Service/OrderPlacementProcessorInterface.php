<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Runs when a pickup order has been placed. Extension point for add-on packages (for example inventory sources).
 *
 * Register implementations in the `processors` argument of the composite in `di.xml`. Runs after the order is
 * saved: a processor must not rely on its exceptions reaching the customer, they are logged and swallowed.
 *
 * @api
 */
interface OrderPlacementProcessorInterface
{
    /**
     * Process a freshly placed pickup order.
     *
     * @param OrderInterface $order
     * @param PickupLocationInterface $location
     * @return void
     */
    public function process(OrderInterface $order, PickupLocationInterface $location): void;
}
