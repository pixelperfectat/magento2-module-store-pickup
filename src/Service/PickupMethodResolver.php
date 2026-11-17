<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service;

use Magento\Framework\Exception\InvalidArgumentException;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;

class PickupMethodResolver implements PickupMethodResolverInterface
{
    /**
     * @inheritDoc
     */
    public function isPickupMethod(?string $shippingMethod): bool
    {
        return $this->getLocationCode($shippingMethod) !== null;
    }

    /**
     * @inheritDoc
     */
    public function getLocationCode(?string $shippingMethod): ?string
    {
        $prefix = self::CARRIER_CODE . '_';
        if ($shippingMethod === null || !str_starts_with($shippingMethod, $prefix)) {
            return null;
        }
        $code = substr($shippingMethod, strlen($prefix));

        return $this->isValidLocationCode($code) ? $code : null;
    }

    /**
     * @inheritDoc
     */
    public function toShippingMethod(string $locationCode): string
    {
        if (!$this->isValidLocationCode($locationCode)) {
            throw new InvalidArgumentException(__('Invalid pickup location code "%1".', $locationCode));
        }

        return self::CARRIER_CODE . '_' . $locationCode;
    }

    /**
     * @inheritDoc
     */
    public function isValidLocationCode(string $code): bool
    {
        // "$" also matches before a trailing line feed, which the pattern alone would let through.
        return preg_match(PickupLocationInterface::CODE_PATTERN, $code) === 1
            && !str_ends_with($code, "\n");
    }
}
