<?php declare(strict_types=1);

namespace Magento\Quote\Api\Data {
    // The generated extension interfaces do not exist in a unit run; declare them for the stub below.
    if (!interface_exists(CartExtensionInterface::class)) {
        interface CartExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
        {
        }
    }
}

namespace Magento\Sales\Api\Data {
    if (!interface_exists(OrderExtensionInterface::class)) {
        interface OrderExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
        {
        }
    }
}

namespace PixelPerfect\StorePickup\Test\Stub {
    use Magento\Quote\Api\Data\CartExtensionInterface;
    use Magento\Sales\Api\Data\OrderExtensionInterface;

    /**
     * Stand-in for the generated CartExtension / OrderExtension classes.
     */
    class ExtensionAttributes implements CartExtensionInterface, OrderExtensionInterface
    {
        public ?string $code = null;

        public ?string $other = null;

        /**
         * @return string|null
         */
        public function getPpPickupLocationCode(): ?string
        {
            return $this->code;
        }

        /**
         * @param string|null $code
         * @return $this
         */
        public function setPpPickupLocationCode(?string $code): self
        {
            $this->code = $code;
            return $this;
        }
    }
}
