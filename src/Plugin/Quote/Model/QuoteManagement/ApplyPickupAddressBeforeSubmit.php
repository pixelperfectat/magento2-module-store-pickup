<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Quote\Model\QuoteManagement;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Service\ExtensionAttribute\PickupLocationCodeMirror;
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
     * @param PickupMethodResolverInterface $methodResolver
     * @param PickupLocationCodeMirror $mirror
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PickupAddressApplierInterface $applier,
        private readonly PickupMethodResolverInterface $methodResolver,
        private readonly PickupLocationCodeMirror $mirror,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Apply the pickup location address and drop the snapshot, which an order no longer needs.
     *
     * The snapshot is kept when nothing was applied (for example a location disabled after it was selected): the
     * submit then fails validation and the next checkout load restores the earlier address from it.
     *
     * A location code left on a quote whose method was changed away from pickup without a checkout reload is
     * cleared, so it is not copied onto a non-pickup order. The address and the snapshot stay as they are. An
     * empty method is left alone: the submit fails validation then, and the code is what lets the next checkout
     * load restore the earlier address. The cart extension attribute is refreshed from the column, because the
     * quote-to-order copy also copies the extension attribute and a stale one would win on the order.
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
            } elseif ($this->isNonPickupQuote($quote)) {
                $quote->setData(PickupAddressApplierInterface::LOCATION_CODE_FIELD, null);
            }
            $this->mirror->mirror($quote, CartInterface::class);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pickup address could not be applied before the order was submitted: ' . $e->getMessage()
            );
        }

        return null;
    }

    /**
     * Whether the quote is virtual or has a shipping method that is set and is not a pickup method.
     *
     * @param Quote $quote
     * @return bool
     */
    private function isNonPickupQuote(Quote $quote): bool
    {
        if ($quote->isVirtual()) {
            return true;
        }
        $method = (string) $quote->getShippingAddress()->getShippingMethod();

        return $method !== '' && !$this->methodResolver->isPickupMethod($method);
    }
}
