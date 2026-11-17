<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Builds the shipment creation arguments for the ready-for-pickup shipment.
 *
 * The default returns empty arguments; an inventory integration adds its source through a plugin.
 *
 * @api
 */
interface ShipmentArgumentsBuilderInterface
{
    /**
     * Arguments for the shipment of a pickup order at its location.
     *
     * @param OrderInterface $order
     * @param PickupLocationInterface $location
     * @return ShipmentCreationArgumentsInterface
     */
    public function build(OrderInterface $order, PickupLocationInterface $location): ShipmentCreationArgumentsInterface;
}
