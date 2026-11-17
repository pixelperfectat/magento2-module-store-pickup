<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Tells whether an order that was just marked as ready for pickup misses the email it should have received.
 *
 * @api
 */
interface ReadyEmailFailureDetectorInterface
{
    /**
     * Whether the ready email is enabled for the order's store but the newest order comment notified no customer.
     *
     * @param OrderInterface $order The order as saved by ReadyForPickupServiceInterface::execute().
     * @return bool
     */
    public function hasFailed(OrderInterface $order): bool;
}
