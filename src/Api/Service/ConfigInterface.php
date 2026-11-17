<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

/**
 * Typed access to the store pickup configuration.
 *
 * All methods take an optional store id; null means the current store.
 *
 * @api
 */
interface ConfigInterface
{
    public const XML_PATH_ACTIVE = 'carriers/pppickup/active';
    public const XML_PATH_TITLE = 'carriers/pppickup/title';
    public const XML_PATH_PRICE = 'carriers/pppickup/price';
    public const XML_PATH_READY_EMAIL_ENABLED = 'pp_store_pickup/ready_email/enabled';
    public const XML_PATH_READY_EMAIL_IDENTITY = 'pp_store_pickup/ready_email/identity';
    public const XML_PATH_READY_EMAIL_TEMPLATE = 'pp_store_pickup/ready_email/template';
    public const XML_PATH_READY_EMAIL_GUEST_TEMPLATE = 'pp_store_pickup/ready_email/guest_template';
    public const XML_PATH_READY_EMAIL_COPY_TO = 'pp_store_pickup/ready_email/copy_to';
    public const XML_PATH_READY_EMAIL_COPY_METHOD = 'pp_store_pickup/ready_email/copy_method';
    public const XML_PATH_PICKUP_ONLY_METHODS = 'pp_store_pickup/payment/pickup_only_methods';
    public const XML_PATH_HIDDEN_FOR_PICKUP = 'pp_store_pickup/payment/hidden_for_pickup';

    /**
     * Master toggle of the pickup carrier.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool;

    /**
     * Carrier title shown at checkout.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string;

    /**
     * Price used for locations without a price of their own.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultPrice(?int $storeId = null): float;

    /**
     * Whether the ready-for-pickup email is sent.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isReadyEmailEnabled(?int $storeId = null): bool;

    /**
     * Sender identity code of the ready-for-pickup email.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getReadyEmailIdentity(?int $storeId = null): string;

    /**
     * Template id of the ready-for-pickup email.
     *
     * @param bool $guest
     * @param int|null $storeId
     * @return string
     */
    public function getReadyEmailTemplate(bool $guest, ?int $storeId = null): string;

    /**
     * Copy recipients of the ready-for-pickup email.
     *
     * @param int|null $storeId
     * @return list<string>
     */
    public function getReadyEmailCopyTo(?int $storeId = null): array;

    /**
     * Copy method of the ready-for-pickup email (bcc or separate email).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getReadyEmailCopyMethod(?int $storeId = null): string;

    /**
     * Payment method codes that are offered for pickup orders only.
     *
     * @param int|null $storeId
     * @return list<string>
     */
    public function getPickupOnlyPaymentMethods(?int $storeId = null): array;

    /**
     * Payment method codes that are hidden for pickup orders.
     *
     * @param int|null $storeId
     * @return list<string>
     */
    public function getHiddenForPickupPaymentMethods(?int $storeId = null): array;
}
