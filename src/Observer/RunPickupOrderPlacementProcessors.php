<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\OrderPlacementProcessorInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Hands a freshly placed pickup order to the order placement processors.
 *
 * Runs right after the order is saved, so it never lets anything escape: the order must not be affected.
 */
class RunPickupOrderPlacementProcessors implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param PickupMethodResolverInterface $resolver
     * @param LocationProviderInterface $locations
     * @param OrderPlacementProcessorInterface $processor
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PickupMethodResolverInterface $resolver,
        private readonly LocationProviderInterface $locations,
        private readonly OrderPlacementProcessorInterface $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run the processors for a pickup order; skip any other order.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order) {
                return;
            }
            $code = $this->resolver->getLocationCode($order->getShippingMethod());
            if ($code === null) {
                return;
            }
            $location = $this->locations->getLocation($code, (int)$order->getStoreId());
            if ($location === null) {
                return;
            }
            $this->processor->process($order, $location);
        } catch (\Throwable $e) {
            $this->logger->error('Order placement processors could not run: ' . $e->getMessage());
        }
    }
}
