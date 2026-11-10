<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Quote\Model\QuoteManagement;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use Psr\Log\LoggerInterface;

/**
 * Re-applies the pickup location address right before a quote is submitted.
 *
 * `submit()` validates the quote (shipping address included) before it converts the addresses, so the swap must
 * happen here. It also undoes anything a checkout form autosave wrote over the location address. Covers
 * storefront, REST, GraphQL and admin-created orders.
 */
class ApplyPickupAddressBeforeSubmit
{
    /**
     * Constructor.
     *
     * @param PickupAddressApplierInterface $applier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PickupAddressApplierInterface $applier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Apply the pickup location address and drop the snapshot, which an order no longer needs.
     *
     * The snapshot is kept when nothing was applied (for example a location disabled after it was selected): the
     * submit then fails validation and the next checkout load restores the earlier address from it.
     *
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array<string, mixed> $orderData
     * @return null
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, array $orderData = []): null
    {
        try {
            if ($this->applier->apply($quote)) {
                $quote->setData(PickupAddressApplierInterface::SNAPSHOT_FIELD, null);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pickup address could not be applied before the order was submitted: ' . $e->getMessage()
            );
        }

        return null;
    }
}
