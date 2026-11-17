<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Sales\Api\OrderRepositoryInterface;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the pp_pickup_location_code column to the order extension attribute and back.
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
     * Expose the pickup location code on a loaded order.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $result
     * @return OrderInterface
     */
    public function afterGet(OrderRepositoryInterface $subject, OrderInterface $result): OrderInterface
    {
        return $this->expose($result);
    }

    /**
     * Expose the pickup location code on every order of a list.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderSearchResultInterface $result
     * @return OrderSearchResultInterface
     */
    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $result
    ): OrderSearchResultInterface {
        try {
            foreach ($result->getItems() as $order) {
                $this->mirror->mirror($order, OrderInterface::class);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be read onto the orders: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Write the extension attribute back to the column data before the order is saved.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     * @return array{0: OrderInterface}
     */
    public function beforeSave(OrderRepositoryInterface $subject, OrderInterface $order): array
    {
        try {
            $this->mirror->writeBack($order);
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be written to the order: ' . $e->getMessage());
        }

        return [$order];
    }

    /**
     * Copy the column value onto the extension attributes.
     *
     * @param OrderInterface $order
     * @return OrderInterface
     */
    private function expose(OrderInterface $order): OrderInterface
    {
        try {
            $this->mirror->mirror($order, OrderInterface::class);
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be read onto the order: ' . $e->getMessage());
        }

        return $order;
    }
}
