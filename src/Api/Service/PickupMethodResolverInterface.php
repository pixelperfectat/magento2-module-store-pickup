<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

use Magento\Framework\Exception\InvalidArgumentException;

/**
 * Maps between full shipping method strings (pppickup_<code>) and location codes.
 *
 * @api
 */
interface PickupMethodResolverInterface
{
    public const CARRIER_CODE = 'pppickup';

    /**
     * Whether the shipping method belongs to the pickup carrier and carries a valid location code.
     *
     * @param string|null $shippingMethod
     * @return bool
     */
    public function isPickupMethod(?string $shippingMethod): bool;

    /**
     * Location code of a pickup shipping method, or null for any other method.
     *
     * @param string|null $shippingMethod
     * @return string|null
     */
    public function getLocationCode(?string $shippingMethod): ?string;

    /**
     * Full shipping method string for a location code.
     *
     * @param string $locationCode
     * @return string
     * @throws InvalidArgumentException If the code does not match the location code pattern.
     */
    public function toShippingMethod(string $locationCode): string;

    /**
     * Whether the code matches the location code pattern.
     *
     * @param string $code
     * @return bool
     */
    public function isValidLocationCode(string $code): bool;
}
