<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the pickup payment lists on payment_method_is_active.
 *
 * Methods listed as pickup-only are hidden for orders that are not pickup orders; methods listed as hidden for
 * pickup are hidden for pickup orders. The observer only ever hides a method, it never makes one available.
 */
class RestrictPaymentForPickup implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param ConfigInterface $config
     * @param PickupMethodResolverInterface $resolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly PickupMethodResolverInterface $resolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Hide the payment method when the lists forbid it for this quote.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote instanceof Quote) {
            return;
        }

        try {
            $storeId = (int)$quote->getStoreId();
            $code = (string)$observer->getEvent()->getData('method_instance')->getCode();
            $isPickup = $this->resolver->isPickupMethod($quote->getShippingAddress()->getShippingMethod());

            $restricted = $isPickup
                ? in_array($code, $this->config->getHiddenForPickupPaymentMethods($storeId), true)
                : in_array($code, $this->config->getPickupOnlyPaymentMethods($storeId), true);
            if ($restricted) {
                $observer->getEvent()->getData('result')->setData('is_available', false);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pickup payment restriction could not be applied: ' . $e->getMessage());
        }
    }
}
