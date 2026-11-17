<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Api\Service;

/**
 * Tells whether open carts or orders still refer to a pickup location code.
 *
 * @api
 */
interface LocationUsageInterface
{
    /**
     * Whether an active quote or an unfinished order (state other than complete, closed or canceled) uses the code.
     *
     * @param string $code
     * @return bool
     */
    public function isInUse(string $code): bool;
}
