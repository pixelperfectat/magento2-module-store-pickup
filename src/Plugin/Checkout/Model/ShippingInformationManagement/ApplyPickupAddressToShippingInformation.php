<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Checkout\Model\ShippingInformationManagement;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts the pickup location address into the posted shipping information before it is validated and saved.
 */
class ApplyPickupAddressToShippingInformation
{
    /**
     * Constructor.
     *
     * @param CartRepositoryInterface $cartRepository
     * @param PickupMethodResolverInterface $methodResolver
     * @param LocationProviderInterface $locationProvider
     * @param PickupAddressApplierInterface $applier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PickupMethodResolverInterface $methodResolver,
        private readonly LocationProviderInterface $locationProvider,
        private readonly PickupAddressApplierInterface $applier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Swap the posted shipping address to the location for a pickup method; clear the code for any other method.
     *
     * The quote comes from `getActive()`, which returns the cached instance the subject saves afterwards.
     *
     * @param ShippingInformationManagement $subject
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return null
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        int $cartId,
        ShippingInformationInterface $addressInformation
    ): null {
        try {
            $quote = $this->cartRepository->getActive($cartId);
            if (!$quote instanceof Quote) {
                return null;
            }
            if ($addressInformation->getShippingCarrierCode() !== PickupMethodResolverInterface::CARRIER_CODE) {
                $quote->setData(PickupAddressApplierInterface::LOCATION_CODE_FIELD, null);
                return null;
            }
            $code = $this->methodResolver->getLocationCode(
                PickupMethodResolverInterface::CARRIER_CODE . '_' . $addressInformation->getShippingMethodCode()
            );
            $location = $code === null
                ? null
                : $this->locationProvider->getLocation($code, (int) $quote->getStoreId());
            if ($location === null || !$location->isActive()) {
                return null;
            }
            $customerId = $quote->getCustomerId();
            $this->applier->applyToAddress(
                $addressInformation->getShippingAddress(),
                $location,
                $customerId === null ? null : (int) $customerId
            );
            $quote->setData(PickupAddressApplierInterface::LOCATION_CODE_FIELD, $location->getCode());
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pickup address could not be applied to the shipping information: ' . $e->getMessage()
            );
        }

        return null;
    }
}
