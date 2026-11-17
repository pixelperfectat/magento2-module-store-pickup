<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use Psr\Log\LoggerInterface;

class PickupAddressApplier implements PickupAddressApplierInterface
{
    /**
     * Shipping address fields kept in the snapshot and restored by revert().
     */
    private const array SNAPSHOT_FIELDS = [
        'customer_address_id',
        'firstname',
        'lastname',
        'company',
        'street',
        'postcode',
        'city',
        'region',
        'region_id',
        'country_id',
        'telephone',
        'save_in_address_book',
        'same_as_billing',
    ];

    /**
     * Fields that hold the location's address, cleared by revert() when there is no snapshot.
     */
    private const array LOCATION_FIELDS = ['street', 'postcode', 'city', 'region', 'region_id', 'company'];

    /**
     * Shipping address fields filled from billing (then the customer) when empty; billing key => customer key.
     */
    private const array CONTACT_FIELDS = [
        'firstname' => 'customer_firstname',
        'lastname' => 'customer_lastname',
        'telephone' => null,
    ];

    /**
     * Constructor.
     *
     * @param LocationProviderInterface $locationProvider
     * @param PickupMethodResolverInterface $methodResolver
     * @param SerializerInterface $serializer
     * @param CartRepositoryInterface $cartRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LocationProviderInterface $locationProvider,
        private readonly PickupMethodResolverInterface $methodResolver,
        private readonly SerializerInterface $serializer,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(CartInterface $quote): bool
    {
        if (!$quote instanceof Quote) {
            return false;
        }
        $location = $this->findSelectedLocation($quote);
        if ($location === null) {
            return false;
        }
        $address = $quote->getShippingAddress();
        if ($quote->getData(self::LOCATION_CODE_FIELD) === null) {
            $quote->setData(self::SNAPSHOT_FIELD, $this->serializer->serialize($this->takeSnapshot($address)));
        }
        $this->fillContactFields($quote, $address);
        $customerId = $quote->getCustomerId();
        $this->applyToAddress($address, $location, $customerId === null ? null : (int) $customerId);
        $quote->setData(self::LOCATION_CODE_FIELD, $location->getCode());

        return true;
    }

    /**
     * @inheritDoc
     */
    public function revert(CartInterface $quote): bool
    {
        if (!$quote instanceof Quote || $quote->getData(self::LOCATION_CODE_FIELD) === null) {
            return false;
        }
        $address = $quote->getShippingAddress();
        $snapshot = $this->readSnapshot($quote);
        if ($snapshot !== null) {
            foreach (self::SNAPSHOT_FIELDS as $field) {
                $address->setData($field, $snapshot[$field] ?? null);
            }
        } else {
            foreach (self::LOCATION_FIELDS as $field) {
                $address->setData($field, null);
            }
        }
        $quote->setData(self::SNAPSHOT_FIELD, null);
        $quote->setData(self::LOCATION_CODE_FIELD, null);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function sync(CartInterface $quote): void
    {
        try {
            if (!$quote instanceof Quote) {
                return;
            }
            $location = $this->findSelectedLocation($quote);
            $storedCode = $quote->getData(self::LOCATION_CODE_FIELD);
            $changed = false;
            if ($location !== null && $storedCode !== $location->getCode()) {
                $changed = $this->apply($quote);
            } elseif ($location === null && $storedCode !== null) {
                $changed = $this->revert($quote);
            }
            if ($changed) {
                $this->cartRepository->save($quote);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pickup address could not be synchronised with the shipping method: ' . $e->getMessage()
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function applyToAddress(
        AddressInterface $address,
        PickupLocationInterface $location,
        ?int $customerId
    ): void {
        $address->setStreet($location->getStreet());
        $address->setPostcode($location->getPostcode());
        $address->setCity($location->getCity());
        $address->setCountryId($location->getCountryId());
        $address->setRegionId($location->getRegionId());
        $address->setRegion($location->getRegion());
        $address->setCompany($location->getName());
        $address->setCustomerAddressId(null);
        $address->setSaveInAddressBook(0);
        $address->setSameAsBilling(0);
        $address->setCustomerId($customerId);
    }

    /**
     * The active pickup location selected as the quote's shipping method, or null.
     *
     * @param Quote $quote
     * @return PickupLocationInterface|null
     */
    private function findSelectedLocation(Quote $quote): ?PickupLocationInterface
    {
        if ($quote->isVirtual()) {
            return null;
        }
        $code = $this->methodResolver->getLocationCode($quote->getShippingAddress()->getShippingMethod());
        if ($code === null) {
            return null;
        }
        $location = $this->locationProvider->getLocation($code, (int) $quote->getStoreId());

        return $location !== null && $location->isActive() ? $location : null;
    }

    /**
     * The snapshot fields of the current shipping address.
     *
     * @param Address $address
     * @return array<string, mixed>
     */
    private function takeSnapshot(Address $address): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $address->getData($field);
        }

        return $snapshot;
    }

    /**
     * The stored snapshot, or null when there is none or it cannot be read.
     *
     * @param Quote $quote
     * @return array<string, mixed>|null
     */
    private function readSnapshot(Quote $quote): ?array
    {
        $serialized = $quote->getData(self::SNAPSHOT_FIELD);
        if (!is_string($serialized) || $serialized === '') {
            return null;
        }
        try {
            $snapshot = $this->serializer->unserialize($serialized);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning(
                'Pickup address snapshot of quote ' . $quote->getId() . ' is unreadable: ' . $e->getMessage()
            );
            return null;
        }

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Fill empty firstname, lastname and telephone from the billing address, then from the customer.
     *
     * @param Quote $quote
     * @param Address $address
     * @return void
     */
    private function fillContactFields(Quote $quote, Address $address): void
    {
        $billing = $quote->getBillingAddress();
        foreach (self::CONTACT_FIELDS as $field => $customerField) {
            if ((string) $address->getData($field) !== '') {
                continue;
            }
            $value = $billing->getData($field);
            if ((string) $value === '' && $customerField !== null) {
                $value = $quote->getData($customerField);
            }
            if ((string) $value !== '') {
                $address->setData($field, $value);
            }
        }
    }
}
