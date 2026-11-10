<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\Availability;

use Magento\Quote\Model\Quote\Address\RateRequest;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\AvailabilityCheckerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks all registered checkers; every one must say yes. A throwing checker counts as no and is logged.
 */
class CompositeAvailabilityChecker implements AvailabilityCheckerInterface
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger
     * @param array<string, AvailabilityCheckerInterface> $checkers keyed by checker name
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $checkers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(PickupLocationInterface $location, RateRequest $request): bool
    {
        foreach ($this->checkers as $name => $checker) {
            try {
                if (!$checker->isAvailable($location, $request)) {
                    return false;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Availability checker "%s" failed for pickup location "%s"; treated as unavailable: %s',
                        $name,
                        $location->getCode(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );

                return false;
            }
        }

        return true;
    }
}
