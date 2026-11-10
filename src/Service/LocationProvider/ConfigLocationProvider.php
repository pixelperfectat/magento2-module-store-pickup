<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\LocationProvider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocationFactory;
use Psr\Log\LoggerInterface;

/**
 * Pickup locations maintained in the admin configuration.
 */
class ConfigLocationProvider implements LocationProviderInterface
{
    public const XML_PATH_LOCATIONS = 'carriers/pppickup/locations';
    public const XML_PATH_LOCATION_TEXTS = 'carriers/pppickup/location_texts';

    /** @var array<int, array<string, PickupLocationInterface>> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param SerializerInterface $serializer
     * @param PickupMethodResolverInterface $resolver
     * @param PickupLocationFactory $locationFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SerializerInterface $serializer,
        private readonly PickupMethodResolverInterface $resolver,
        private readonly PickupLocationFactory $locationFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getLocations(int $storeId): array
    {
        return array_filter(
            $this->load($storeId),
            static fn (PickupLocationInterface $location): bool => $location->isActive()
        );
    }

    /**
     * @inheritDoc
     */
    public function getLocation(string $code, int $storeId): ?PickupLocationInterface
    {
        return $this->load($storeId)[$code] ?? null;
    }

    /**
     * All configured locations (active and inactive), sorted, keyed by code.
     *
     * @param int $storeId
     * @return array<string, PickupLocationInterface>
     */
    private function load(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }

        $texts = $this->readTexts($storeId);
        $locations = [];
        foreach ($this->readRows(self::XML_PATH_LOCATIONS, $storeId) as $row) {
            $code = $this->string($row, 'code');
            if (!$this->resolver->isValidLocationCode($code) || isset($locations[$code])) {
                $this->logger->warning(sprintf('Ignoring pickup location row with unusable code "%s".', $code));
                continue;
            }
            $locations[$code] = $this->createLocation($code, $row, $texts[$code] ?? []);
        }
        uasort($locations, static fn (PickupLocationInterface $a, PickupLocationInterface $b): int =>
            [$a->getSortOrder(), $a->getCode()] <=> [$b->getSortOrder(), $b->getCode()]);

        return $this->cache[$storeId] = $locations;
    }

    /**
     * Build a location from a stored row and its optional store-view text.
     *
     * @param string $code
     * @param array<string, mixed> $row
     * @param array<string, mixed> $text
     * @return PickupLocationInterface
     */
    private function createLocation(string $code, array $row, array $text): PickupLocationInterface
    {
        $price = $this->string($row, 'price');
        $regionId = (int) $this->string($row, 'region_id');

        return $this->locationFactory->create([
            'code' => $code,
            'name' => $this->override($text, $row, 'name') ?? '',
            'street' => $this->splitLines($this->string($row, 'street')),
            'postcode' => $this->string($row, 'postcode'),
            'city' => $this->string($row, 'city'),
            'countryId' => $this->string($row, 'country_id'),
            'regionId' => $regionId > 0 ? $regionId : null,
            'region' => $this->nullIfEmpty($this->string($row, 'region')),
            'telephone' => $this->nullIfEmpty($this->string($row, 'telephone')),
            'openingHours' => $this->override($text, $row, 'opening_hours'),
            'note' => $this->override($text, $row, 'note'),
            'price' => is_numeric($price) ? (float) $price : null,
            'sortOrder' => (int) $this->string($row, 'sort_order'),
            'active' => $this->string($row, 'active') === '1',
            'stockBinding' => null,
        ]);
    }

    /**
     * Text rows of the store view, keyed by code.
     *
     * @param int $storeId
     * @return array<string, array<string, mixed>>
     */
    private function readTexts(int $storeId): array
    {
        $texts = [];
        foreach ($this->readRows(self::XML_PATH_LOCATION_TEXTS, $storeId) as $row) {
            $texts[$this->string($row, 'code')] ??= $row;
        }

        return $texts;
    }

    /**
     * Stored rows of a serialized grid field; an unparsable value yields no rows and one logged error.
     *
     * @param string $path
     * @param int $storeId
     * @return array<array-key, array<string, mixed>>
     */
    private function readRows(string $path, int $storeId): array
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        try {
            $data = is_array($raw) ? $raw : $this->serializer->unserialize((string) $raw);
        } catch (\InvalidArgumentException $e) {
            $data = null;
        }
        if (!is_array($data)) {
            $this->logger->error(sprintf('Stored value of "%s" cannot be read; no pickup rows are used.', $path));

            return [];
        }

        return array_filter($data, 'is_array');
    }

    /**
     * Store-view text if it is not empty, otherwise the website row's value.
     *
     * @param array<string, mixed> $text
     * @param array<string, mixed> $row
     * @param string $key
     * @return string|null
     */
    private function override(array $text, array $row, string $key): ?string
    {
        $value = $this->nullIfEmpty($this->string($text, $key));

        return $value ?? $this->nullIfEmpty($this->string($row, $key));
    }

    /**
     * Trimmed string value of a row key.
     *
     * @param array<string, mixed> $row
     * @param string $key
     * @return string
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Empty string to null.
     *
     * @param string $value
     * @return string|null
     */
    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Split a multi-line cell into trimmed, non-empty lines.
     *
     * @param string $value
     * @return list<string>
     */
    private function splitLines(string $value): array
    {
        $lines = array_map('trim', preg_split('/\R/', $value) ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
