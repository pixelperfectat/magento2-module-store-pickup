<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Sales\Api\OrderRepositoryInterface;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors the pp_pickup_location_code column to the order extension attribute and back.
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
     * Expose the pickup location code on a loaded order.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $result
     * @return OrderInterface
     */
    public function afterGet(OrderRepositoryInterface $subject, OrderInterface $result): OrderInterface
    {
        return $this->mirror($result);
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
        foreach ($result->getItems() as $order) {
            $this->mirror($order);
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
            $extension = $order->getExtensionAttributes();
            // @phpstan-ignore-next-line the extension class is generated
            $code = $extension !== null ? $extension->getPpPickupLocationCode() : null;
            if ($code !== null && $order instanceof DataObject) {
                $order->setData(self::KEY, $code);
            }
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
    private function mirror(OrderInterface $order): OrderInterface
    {
        try {
            if (!$order instanceof DataObject) {
                return $order;
            }
            $extension = $order->getExtensionAttributes()
                ?? $this->extensionAttributesFactory->create(OrderInterface::class);
            // @phpstan-ignore-next-line the extension class is generated
            $extension->setPpPickupLocationCode($order->getData(self::KEY));
            // @phpstan-ignore-next-line the extension class is generated
            $order->setExtensionAttributes($extension);
        } catch (\Throwable $e) {
            $this->logger->error('Pickup location code could not be read onto the order: ' . $e->getMessage());
        }

        return $order;
    }
}
