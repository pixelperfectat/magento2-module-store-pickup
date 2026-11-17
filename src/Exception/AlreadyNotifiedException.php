<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The order was already marked as ready for pickup.
 *
 * @api
 */
class AlreadyNotifiedException extends LocalizedException
{
}
