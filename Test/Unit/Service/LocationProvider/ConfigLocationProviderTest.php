<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\LocationProvider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\Data\PickupLocationFactory;
use PixelPerfect\StorePickup\Service\LocationProvider\ConfigLocationProvider;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../Stub/PickupLocationFactory.php';

class ConfigLocationProviderTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private Json $json;

    private ConfigLocationProvider $provider;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->json = new Json();
        $this->provider = new ConfigLocationProvider(
            $this->scopeConfig,
            $this->json,
            new PickupMethodResolver(),
            new PickupLocationFactory(),
            $this->logger
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $code, array $overrides = []): array
    {
        return $overrides + [
            'code' => $code,
            'name' => 'Main Store',
            'street' => "1 Example Street\nBuilding B",
            'postcode' => '1010',
            'city' => 'Vienna',
            'country_id' => 'AT',
            'region_id' => '',
            'region' => '',
            'telephone' => '+43 1 000000',
            'opening_hours' => 'Mon-Fri 9-18',
            'note' => 'Ring the bell',
            'price' => '',
            'sort_order' => '10',
            'active' => '1',
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|string|null $locations
     * @param array<int, array<string, mixed>>|string|null $texts
     */
    private function stubConfig($locations, $texts = null): void
    {
        $values = [
            ConfigLocationProvider::XML_PATH_LOCATIONS => is_array($locations)
                ? $this->json->serialize($locations) : $locations,
            ConfigLocationProvider::XML_PATH_LOCATION_TEXTS => is_array($texts)
                ? $this->json->serialize($texts) : $texts,
        ];
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $scopeCode) use ($values) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);
                $this->assertSame(self::STORE_ID, $scopeCode);

                return $values[$path] ?? null;
            }
        );
    }

    public function testMapsARow(): void
    {
        $this->stubConfig(['_1' => $this->row('main')]);

        $location = $this->provider->getLocations(self::STORE_ID)['main'];

        $this->assertSame('main', $location->getCode());
        $this->assertSame('Main Store', $location->getName());
        $this->assertSame(['1 Example Street', 'Building B'], $location->getStreet());
        $this->assertSame('1010', $location->getPostcode());
        $this->assertSame('Vienna', $location->getCity());
        $this->assertSame('AT', $location->getCountryId());
        $this->assertNull($location->getRegionId());
        $this->assertNull($location->getRegion());
        $this->assertSame('+43 1 000000', $location->getTelephone());
        $this->assertNull($location->getPrice());
        $this->assertSame(10, $location->getSortOrder());
        $this->assertTrue($location->isActive());
        $this->assertNull($location->getStockBinding());
    }

    public function testMapsRegionAndPrice(): void
    {
        $this->stubConfig(['_1' => $this->row('main', [
            'region_id' => '94', 'region' => 'Vienna', 'telephone' => '', 'price' => '2.50',
        ])]);

        $location = $this->provider->getLocations(self::STORE_ID)['main'];

        $this->assertSame(94, $location->getRegionId());
        $this->assertSame('Vienna', $location->getRegion());
        $this->assertNull($location->getTelephone());
        $this->assertSame(2.5, $location->getPrice());
    }

    public function testZeroPriceIsFreePickupNotDefault(): void
    {
        $this->stubConfig(['_1' => $this->row('main', ['price' => '0'])]);

        $this->assertSame(0.0, $this->provider->getLocations(self::STORE_ID)['main']->getPrice());
    }

    public function testStoreViewTextsOverrideNonEmptyValues(): void
    {
        $this->stubConfig(
            ['_1' => $this->row('main')],
            ['_9' => [
                'code' => 'main',
                'name' => 'Negozio principale',
                'opening_hours' => 'Lun–Ven 9–18',
                'note' => '',
            ]]
        );

        $location = $this->provider->getLocations(self::STORE_ID)['main'];

        $this->assertSame('Negozio principale', $location->getName());
        $this->assertSame('Lun–Ven 9–18', $location->getOpeningHours());
        $this->assertSame('Ring the bell', $location->getNote());
    }

    public function testWithoutTextRowAllWebsiteValuesApply(): void
    {
        $this->stubConfig(['_1' => $this->row('main')], ['_9' => ['code' => 'other', 'name' => 'Other']]);

        $location = $this->provider->getLocations(self::STORE_ID)['main'];

        $this->assertSame('Main Store', $location->getName());
        $this->assertSame('Mon-Fri 9-18', $location->getOpeningHours());
        $this->assertSame('Ring the bell', $location->getNote());
    }

    public function testInactiveRowIsHiddenFromListButReturnedByCode(): void
    {
        $this->stubConfig(['_1' => $this->row('main'), '_2' => $this->row('second', ['active' => '0'])]);

        $this->assertSame(['main'], array_keys($this->provider->getLocations(self::STORE_ID)));
        $second = $this->provider->getLocation('second', self::STORE_ID);
        $this->assertNotNull($second);
        $this->assertFalse($second->isActive());
        $this->assertNull($this->provider->getLocation('missing', self::STORE_ID));
    }

    public function testSortsBySortOrderThenCode(): void
    {
        $this->stubConfig([
            '_1' => $this->row('zeta', ['sort_order' => '5']),
            '_2' => $this->row('beta', ['sort_order' => '10']),
            '_3' => $this->row('alpha', ['sort_order' => '10']),
            '_4' => $this->row('omega', ['sort_order' => '1']),
        ]);

        $this->assertSame(
            ['omega', 'zeta', 'alpha', 'beta'],
            array_keys($this->provider->getLocations(self::STORE_ID))
        );
    }

    public function testRowWithInvalidOrRepeatedCodeIsSkippedAndLogged(): void
    {
        $this->stubConfig([
            '_1' => $this->row('main'),
            '_2' => $this->row('Bad Code'),
            '_3' => $this->row('main', ['name' => 'Duplicate']),
        ]);
        $this->logger->expects($this->exactly(2))->method('warning');

        $locations = $this->provider->getLocations(self::STORE_ID);

        $this->assertSame(['main'], array_keys($locations));
        $this->assertSame('Main Store', $locations['main']->getName());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyValueProvider(): array
    {
        return ['null' => [null], 'empty string' => [''], 'false' => [false], 'empty list' => ['[]']];
    }

    /**
     * @dataProvider emptyValueProvider
     * @param mixed $value
     */
    public function testNothingStoredYieldsNoLocationsAndNoError(mixed $value): void
    {
        $this->stubConfig($value);
        $this->logger->expects($this->never())->method('error');

        $this->assertSame([], $this->provider->getLocations(self::STORE_ID));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unparsableProvider(): array
    {
        return ['broken json' => ['{not json'], 'scalar json' => ['"text"']];
    }

    /**
     * @dataProvider unparsableProvider
     */
    public function testUnparsableValueYieldsNoLocationsAndOneError(string $value): void
    {
        $this->stubConfig($value);
        $this->logger->expects($this->once())->method('error');

        $this->assertSame([], $this->provider->getLocations(self::STORE_ID));
        $this->assertSame([], $this->provider->getLocations(self::STORE_ID));
        $this->assertNull($this->provider->getLocation('main', self::STORE_ID));
    }

    public function testNonRowEntriesAreIgnored(): void
    {
        $this->stubConfig(['__empty' => '', '_1' => $this->row('main')]);

        $this->assertSame(['main'], array_keys($this->provider->getLocations(self::STORE_ID)));
    }

    public function testFirstTextRowOfACodeWinsAndOtherCodesKeepTheirOwn(): void
    {
        $this->stubConfig(
            ['_1' => $this->row('main'), '_2' => $this->row('second')],
            [
                '_8' => ['code' => 'main', 'name' => 'First text'],
                '_9' => ['code' => 'main', 'name' => 'Second text'],
                '_10' => ['code' => 'second', 'name' => 'Other text'],
            ]
        );

        $locations = $this->provider->getLocations(self::STORE_ID);

        $this->assertSame('First text', $locations['main']->getName());
        $this->assertSame('Other text', $locations['second']->getName());
    }

    public function testStreetLinesAreTrimmedAndBlankLinesDropped(): void
    {
        $this->stubConfig(['_1' => $this->row('main', ['street' => "  1 Example Street  \r\n\n   \n  Building B "])]);

        $this->assertSame(
            ['1 Example Street', 'Building B'],
            $this->provider->getLocations(self::STORE_ID)['main']->getStreet()
        );
    }

    public function testCellValuesAreTrimmed(): void
    {
        $this->stubConfig(['_1' => $this->row('main', ['name' => '  Main Store ', 'city' => ' Vienna '])]);

        $location = $this->provider->getLocations(self::STORE_ID)['main'];

        $this->assertSame('Main Store', $location->getName());
        $this->assertSame('Vienna', $location->getCity());
    }

    public function testNumericCodeKeepsItsStringTypeAndTakesItsStoreViewText(): void
    {
        $this->stubConfig(
            ['_1' => $this->row('7')],
            ['_9' => ['code' => '7', 'name' => 'Negozio sette']]
        );

        $locations = $this->provider->getLocations(self::STORE_ID);

        $this->assertCount(1, $locations);
        $location = array_values($locations)[0];
        $this->assertSame('7', $location->getCode());
        $this->assertSame('Negozio sette', $location->getName());
        $this->assertSame('7', $this->provider->getLocation('7', self::STORE_ID)?->getCode());
    }

    public function testRepeatedNumericCodeIsSkipped(): void
    {
        $this->stubConfig(['_1' => $this->row('7'), '_2' => $this->row('7', ['name' => 'Again'])]);
        $this->logger->expects($this->once())->method('warning');

        $this->assertCount(1, $this->provider->getLocations(self::STORE_ID));
    }

    public function testCodesAtEqualSortOrderCompareAsStrings(): void
    {
        $this->stubConfig(['_1' => $this->row('9'), '_2' => $this->row('10')]);

        $codes = array_map(
            static fn ($location): string => $location->getCode(),
            array_values($this->provider->getLocations(self::STORE_ID))
        );

        $this->assertSame(['10', '9'], $codes);
    }
}
