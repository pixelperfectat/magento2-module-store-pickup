<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\OrderPlacement;

use Magento\Sales\Api\Data\OrderInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\OrderPlacementProcessorInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs all registered processors in di.xml order. A throwing processor is logged and the rest still run.
 */
class CompositeOrderPlacementProcessor implements OrderPlacementProcessorInterface
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger
     * @param array<string, OrderPlacementProcessorInterface> $processors keyed by processor name
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $processors = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(OrderInterface $order, PickupLocationInterface $location): void
    {
        foreach ($this->processors as $name => $processor) {
            try {
                $processor->process($order, $location);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Order placement processor "%s" failed for order "%s" at pickup location "%s": %s',
                        $name,
                        (string)$order->getIncrementId(),
                        $location->getCode(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }
    }
}
