<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings the checkout quote's shipping address in line with its shipping method when the checkout page loads.
 *
 * Restores the customer's earlier address when the selected pickup location was disabled in the meantime.
 */
class SyncPickupAddressOnCheckout implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param Session $checkoutSession
     * @param PickupAddressApplierInterface $applier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Session $checkoutSession,
        private readonly PickupAddressApplierInterface $applier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sync the session quote, if there is one.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if (!$this->checkoutSession->getQuoteId()) {
                return;
            }
            $this->applier->sync($this->checkoutSession->getQuote());
        } catch (\Throwable $e) {
            $this->logger->error('Pickup address could not be synchronised on the checkout page: ' . $e->getMessage());
        }
    }
}
