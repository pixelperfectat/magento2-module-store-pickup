<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Sends the ready-for-pickup email to the customer of an order.
 *
 * @api
 */
interface ReadyForPickupEmailSenderInterface
{
    /**
     * Send the email; never throws, a failure is logged.
     *
     * Callers decide whether the email is enabled; this method sends whenever it is called.
     *
     * @param OrderInterface $order
     * @param PickupLocationInterface $location
     * @return bool Whether the email was sent.
     */
    public function send(OrderInterface $order, PickupLocationInterface $location): bool;
}
