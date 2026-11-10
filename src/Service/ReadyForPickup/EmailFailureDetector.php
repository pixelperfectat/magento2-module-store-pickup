<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\ReadyForPickup;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;

/**
 * Reads the email result from the comment that the ready-for-pickup service wrote last.
 */
class EmailFailureDetector implements ReadyEmailFailureDetectorInterface
{
    /**
     * Constructor.
     *
     * @param ConfigInterface $config
     */
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hasFailed(OrderInterface $order): bool
    {
        if (!$this->config->isReadyEmailEnabled((int) $order->getStoreId())) {
            return false;
        }
        $newest = $this->newestComment($order);

        return $newest !== null && (int) $newest->getIsCustomerNotified() === 0;
    }

    /**
     * The comment with the highest id; the first one wins a tie.
     *
     * @param OrderInterface $order
     * @return OrderStatusHistoryInterface|null
     */
    private function newestComment(OrderInterface $order): ?OrderStatusHistoryInterface
    {
        $newest = null;
        foreach ($order->getStatusHistories() ?? [] as $history) {
            if ($newest === null || (int) $history->getEntityId() > (int) $newest->getEntityId()) {
                $newest = $history;
            }
        }

        return $newest;
    }
}
