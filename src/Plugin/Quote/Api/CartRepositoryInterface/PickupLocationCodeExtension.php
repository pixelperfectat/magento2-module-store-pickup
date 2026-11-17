<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Quote\Api\CartRepositoryInterface;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the pp_pickup_location_code column to the cart extension attribute and back.
 */
class PickupLocationCodeExtension
{
    /**
     * Constructor.
     *
     * @param PickupLocationCodeMirror $mirror
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PickupLocationCodeMirror $mirror,
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
        return $this->expose($result);
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
        return $this->expose($result);
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
        return $this->expose($result);
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
        return $this->expose($result);
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
            $this->mirror->writeBack($quote);
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
    private function expose(CartInterface $cart): CartInterface
    {
        try {
            $this->mirror->mirror($cart, CartInterface::class);
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be read onto the cart: ' . $e->getMessage());
        }

        return $cart;
    }
}
