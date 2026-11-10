<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\StorePickup\Exception\AlreadyNotifiedException;

/**
 * Marks a store pickup order as ready for pickup: ships it, emails the customer and sets the order status.
 *
 * Only the admin button, the mass action and modules that call this service trigger the step; a shipment created
 * anywhere else never does.
 *
 * @api
 */
interface ReadyForPickupServiceInterface
{
    public const string STATUS = 'ready_for_pickup';
    public const string NOTIFIED_AT = 'pp_pickup_notified_at';

    /**
     * Whether the order can be marked as ready for pickup now.
     *
     * True for a pickup order that was not marked yet and is in state new, processing or complete.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isEligible(OrderInterface $order): bool;

    /**
     * Ship the order unless it has a shipment, set the status, send the email and record the step.
     *
     * @param int $orderId
     * @return void
     * @throws AlreadyNotifiedException When the order was already marked as ready for pickup.
     * @throws LocalizedException When the order is no pickup order, is in another state, or its location is gone.
     */
    public function execute(int $orderId): void;
}
