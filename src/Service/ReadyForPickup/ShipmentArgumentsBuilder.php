<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\ReadyForPickup;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterfaceFactory;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;

class ShipmentArgumentsBuilder implements ShipmentArgumentsBuilderInterface
{
    /**
     * Constructor.
     *
     * @param ShipmentCreationArgumentsInterfaceFactory $argumentsFactory
     */
    public function __construct(
        private readonly ShipmentCreationArgumentsInterfaceFactory $argumentsFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(OrderInterface $order, PickupLocationInterface $location): ShipmentCreationArgumentsInterface
    {
        return $this->argumentsFactory->create([]);
    }
}
