<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\LocationProvider;

use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Merges the locations of all registered providers.
 *
 * The first provider in `di.xml` order that knows a code owns it, active or not. A later provider's location with
 * the same code is ignored, so a code gets a rate only when its owner's record is active.
 */
class CompositeLocationProvider implements LocationProviderInterface
{
    /** @var array<int, array<string, PickupLocationInterface>> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger
     * @param array<string, LocationProviderInterface> $providers keyed by provider name, in asking order
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $providers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getLocations(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }

        $merged = [];
        $seen = [];
        $checked = [];
        foreach ($this->providers as $name => $provider) {
            try {
                $locations = $provider->getLocations($storeId);
            } catch (\Throwable $e) {
                $this->logFailure((string) $name, $e);
                $locations = [];
            }
            foreach ($locations as $location) {
                $code = $location->getCode();
                if (in_array($code, $seen, true) || $this->isOwnedEarlier($code, $storeId, $checked)) {
                    $this->logger->warning(sprintf(
                        'Pickup location code "%s" from provider "%s" is already taken; ignored.',
                        $code,
                        $name
                    ));
                    continue;
                }
                $seen[] = $code;
                $merged[] = $location;
            }
            $checked[(string) $name] = $provider;
        }
        usort($merged, static fn (PickupLocationInterface $a, PickupLocationInterface $b): int =>
            $a->getSortOrder() <=> $b->getSortOrder() ?: strcmp($a->getCode(), $b->getCode()));
        $keyed = [];
        foreach ($merged as $location) {
            // The key is only a convenience; PHP turns a numeric code into an int key.
            $keyed[$location->getCode()] = $location;
        }

        return $this->cache[$storeId] = $keyed;
    }

    /**
     * @inheritDoc
     */
    public function getLocation(string $code, int $storeId): ?PickupLocationInterface
    {
        foreach ($this->providers as $name => $provider) {
            try {
                $location = $provider->getLocation($code, $storeId);
            } catch (\Throwable $e) {
                $this->logFailure((string) $name, $e);
                continue;
            }
            if ($location !== null) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Whether one of the providers asked before knows the code, active or not.
     *
     * @param string $code
     * @param int $storeId
     * @param array<string, LocationProviderInterface> $earlier
     * @return bool
     */
    private function isOwnedEarlier(string $code, int $storeId, array $earlier): bool
    {
        foreach ($earlier as $name => $provider) {
            try {
                if ($provider->getLocation($code, $storeId) !== null) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logFailure($name, $e);
            }
        }

        return false;
    }

    /**
     * Log a failing provider.
     *
     * @param string $name
     * @param \Throwable $e
     * @return void
     */
    private function logFailure(string $name, \Throwable $e): void
    {
        $this->logger->error(
            sprintf('Pickup location provider "%s" failed and is skipped: %s', $name, $e->getMessage()),
            ['exception' => $e]
        );
    }
}
