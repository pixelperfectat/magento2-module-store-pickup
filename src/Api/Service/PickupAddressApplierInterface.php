<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;

/**
 * Swaps a quote's shipping address to a pickup location's address and back.
 *
 * None of the methods saves the quote, except `sync()`; callers save.
 *
 * @api
 */
interface PickupAddressApplierInterface
{
    /**
     * Quote and order column holding the selected pickup location code.
     */
    public const string LOCATION_CODE_FIELD = 'pp_pickup_location_code';

    /**
     * Quote column holding the shipping address the customer had before pickup was selected.
     */
    public const string SNAPSHOT_FIELD = 'pp_pickup_address_snapshot';

    /**
     * Put the pickup location's address into the shipping address of a pickup quote.
     *
     * Stores a snapshot of the current shipping address when no pickup location was selected before, fills empty
     * firstname, lastname and telephone from the billing address (then the customer) and sets the location code.
     *
     * @param CartInterface $quote
     * @return bool false, with nothing changed, when the quote is virtual, the shipping method is not a pickup
     *     method, or its location is missing or inactive
     */
    public function apply(CartInterface $quote): bool;

    /**
     * Restore the shipping address the customer had before pickup was selected.
     *
     * Without a snapshot, only the location parts of the address (street, postcode, city, region, company) are
     * cleared. The location code and the snapshot are cleared in both cases.
     *
     * @param CartInterface $quote
     * @return bool false when no pickup location code is stored on the quote
     */
    public function revert(CartInterface $quote): bool;

    /**
     * Bring the shipping address in line with the shipping method and save the quote when it changed.
     *
     * Applies the location when a pickup method with an active location is selected and its code is not stored
     * yet; reverts when a code is stored but the method is no longer pickup or its location is missing or
     * inactive. Never throws.
     *
     * @param CartInterface $quote
     * @return void
     */
    public function sync(CartInterface $quote): void;

    /**
     * Write a pickup location's address into a shipping address.
     *
     * Sets street, postcode, city, country, region, the location name as company, and detaches the address from
     * the customer's address book. Never touches firstname, lastname, telephone or email.
     *
     * @param AddressInterface $address
     * @param PickupLocationInterface $location
     * @param int|null $customerId
     * @return void
     */
    public function applyToAddress(
        AddressInterface $address,
        PickupLocationInterface $location,
        ?int $customerId
    ): void;
}
