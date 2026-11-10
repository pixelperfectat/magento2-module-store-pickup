<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Quote\Api\CartRepositoryInterface;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the pp_pickup_location_code column to the cart extension attribute and back.
 */
class PickupLocationCodeExtension
{
    public const KEY = 'pp_pickup_location_code';

    /**
     * Constructor.
     *
     * @param ExtensionAttributesFactory $extensionAttributesFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ExtensionAttributesFactory $extensionAttributesFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Expose the pickup location code on a loaded cart.
     *
     * @param CartRepositoryInterface $subject
     * @param CartInterface $result
     * @return CartInterface
     */
    public function afterGet(CartRepositoryInterface $subject, CartInterface $result): CartInterface
    {
        return $this->mirror($result);
    }

    /**
     * Expose the pickup location code on a loaded active cart.
     *
     * @param CartRepositoryInterface $subject
     * @param CartInterface $result
     * @return CartInterface
     */
    public function afterGetActive(CartRepositoryInterface $subject, CartInterface $result): CartInterface
    {
        return $this->mirror($result);
    }

    /**
     * Expose the pickup location code on a customer's cart.
     *
     * @param CartRepositoryInterface $subject
     * @param CartInterface $result
     * @return CartInterface
     */
    public function afterGetForCustomer(CartRepositoryInterface $subject, CartInterface $result): CartInterface
    {
        return $this->mirror($result);
    }

    /**
     * Expose the pickup location code on a customer's active cart.
     *
     * @param CartRepositoryInterface $subject
     * @param CartInterface $result
     * @return CartInterface
     */
    public function afterGetActiveForCustomer(CartRepositoryInterface $subject, CartInterface $result): CartInterface
    {
        return $this->mirror($result);
    }

    /**
     * Write the extension attribute back to the column data before the cart is saved.
     *
     * @param CartRepositoryInterface $subject
     * @param CartInterface $quote
     * @return array{0: CartInterface}
     */
    public function beforeSave(CartRepositoryInterface $subject, CartInterface $quote): array
    {
        try {
            $extension = $quote->getExtensionAttributes();
            // @phpstan-ignore-next-line the extension class is generated
            $code = $extension !== null ? $extension->getPpPickupLocationCode() : null;
            if ($code !== null && $quote instanceof DataObject) {
                $quote->setData(self::KEY, $code);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be written to the cart: ' . $e->getMessage());
        }

        return [$quote];
    }

    /**
     * Copy the column value onto the extension attributes.
     *
     * @param CartInterface $cart
     * @return CartInterface
     */
    private function mirror(CartInterface $cart): CartInterface
    {
        try {
            if (!$cart instanceof DataObject) {
                return $cart;
            }
            $extension = $cart->getExtensionAttributes()
                ?? $this->extensionAttributesFactory->create(CartInterface::class);
            // @phpstan-ignore-next-line the extension class is generated
            $extension->setPpPickupLocationCode($cart->getData(self::KEY));
            // @phpstan-ignore-next-line the extension class is generated
            $cart->setExtensionAttributes($extension);
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be read onto the cart: ' . $e->getMessage());
        }

        return $cart;
    }
}
