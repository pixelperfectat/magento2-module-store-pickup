<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\AvailabilityCheckerInterface;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Model\Carrier\StorePickup;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../Stub/ShippingFactories.php';

class StorePickupTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var LocationProviderInterface&MockObject */
    private $locations;

    /** @var AvailabilityCheckerInterface&MockObject */
    private $checker;

    private StorePickup $carrier;

    /** @var LoggerInterface&MockObject */
    private $parentLogger;

    /** @var LoggerInterface&MockObject */
    private $pickupLogger;

    private float $defaultPrice = 0.0;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getTitle')->willReturn('Store Pickup');
        $this->config->method('getDefaultPrice')->willReturnCallback(fn (): float => $this->defaultPrice);
        $this->locations = $this->createMock(LocationProviderInterface::class);
        $this->checker = $this->createMock(AvailabilityCheckerInterface::class);
        $this->parentLogger = $this->createMock(LoggerInterface::class);
        $this->pickupLogger = $this->createMock(LoggerInterface::class);

        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            fn (): Result => new Result($this->createMock(StoreManagerInterface::class))
        );
        $methodFactory = $this->createMock(MethodFactory::class);
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnArgument(0);
        $methodFactory->method('create')->willReturnCallback(
            static fn (): Method => new Method($priceCurrency)
        );

        $this->carrier = new StorePickup(
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(ErrorFactory::class),
            $this->parentLogger,
            $resultFactory,
            $methodFactory,
            $this->locations,
            $this->checker,
            $this->config,
            $this->pickupLogger
        );
    }

    private function location(string $code, string $name, ?float $price = null): PickupLocationInterface
    {
        return new PickupLocation($code, $name, ['1 Example Street'], '1010', 'Vienna', 'AT', price: $price);
    }

    private function request(bool $freeShipping = false): RateRequest
    {
        $request = new RateRequest();
        $request->setStoreId(3);
        $request->setFreeShipping($freeShipping);

        return $request;
    }

    /**
     * @param list<PickupLocationInterface> $list
     */
    private function provide(array $list): void
    {
        $keyed = [];
        foreach ($list as $location) {
            $keyed[$location->getCode()] = $location;
        }
        $this->locations->method('getLocations')->with(3)->willReturn($keyed);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rates(Result|bool $result): array
    {
        $this->assertInstanceOf(Result::class, $result);

        return array_map(static fn (Method $m): array => $m->getData(), $result->getAllRates());
    }

    public function testInactiveReturnsFalse(): void
    {
        $this->config->method('isActive')->with(3)->willReturn(false);
        $this->locations->expects($this->never())->method('getLocations');

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testOneRatePerAvailableLocation(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->provide([$this->location('main', 'Main Shop'), $this->location('second', 'Second Shop', 2.5)]);
        $this->checker->method('isAvailable')->willReturn(true);

        $rates = $this->rates($this->carrier->collectRates($this->request()));

        $this->assertCount(2, $rates);
        $this->assertSame(
            ['carrier' => 'pppickup', 'carrier_title' => 'Store Pickup', 'method' => 'main',
                'method_title' => 'Main Shop', 'price' => 0.0, 'cost' => 0.0],
            $rates[0]
        );
        $this->assertSame('second', $rates[1]['method']);
        $this->assertSame('Second Shop', $rates[1]['method_title']);
        $this->assertSame(2.5, $rates[1]['price']);
        $this->assertSame(2.5, $rates[1]['cost']);
    }

    public function testUnavailableLocationIsSkipped(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $main = $this->location('main', 'Main Shop');
        $second = $this->location('second', 'Second Shop');
        $this->provide([$main, $second]);
        $this->checker->method('isAvailable')->willReturnCallback(
            static fn (PickupLocationInterface $l): bool => $l->getCode() !== 'second'
        );

        $rates = $this->rates($this->carrier->collectRates($this->request()));

        $this->assertSame(['main'], array_column($rates, 'method'));
    }

    public function testNoLocationsGivesEmptyResultWithoutError(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->provide([]);

        $result = $this->carrier->collectRates($this->request());

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame([], $result->getAllRates());
    }

    public function testFreeShippingZeroesAllPrices(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->provide([$this->location('main', 'Main Shop', 4.0), $this->location('second', 'Second Shop', 2.5)]);
        $this->checker->method('isAvailable')->willReturn(true);

        $rates = $this->rates($this->carrier->collectRates($this->request(true)));

        $this->assertSame([0.0, 0.0], array_column($rates, 'price'));
        $this->assertSame([0.0, 0.0], array_column($rates, 'cost'));
    }

    public function testDefaultPriceAppliesWhenLocationHasNone(): void
    {
        $this->defaultPrice = 1.9;
        $this->config->method('isActive')->willReturn(true);
        $this->checker->method('isAvailable')->willReturn(true);
        $this->provide([$this->location('main', 'Main Shop')]);

        $rates = $this->rates($this->carrier->collectRates($this->request()));

        $this->assertSame(1.9, $rates[0]['price']);
    }

    public function testAllowedMethodsAreCodeToName(): void
    {
        $this->provide([$this->location('main', 'Main Shop'), $this->location('second', 'Second Shop')]);
        $this->carrier->setStore(3);

        $this->assertSame(['main' => 'Main Shop', 'second' => 'Second Shop'], $this->carrier->getAllowedMethods());
    }

    public function testNumericCodeIsReadFromLocationNotKey(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->provide([$this->location('7', 'Seven')]);
        $this->checker->method('isAvailable')->willReturn(true);

        $rates = $this->rates($this->carrier->collectRates($this->request()));

        $this->assertSame('7', $rates[0]['method']);
    }

    public function testFailingProviderMakesCollectRatesReturnFalseAndLogOnce(): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->locations->method('getLocations')->willThrowException(new \RuntimeException('boom'));
        $this->pickupLogger->expects($this->once())->method('error')->with(
            $this->stringContains('boom'),
            $this->arrayHasKey('exception')
        );
        $this->parentLogger->expects($this->never())->method($this->anything());

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testFailingProviderMakesAllowedMethodsEmptyAndLogs(): void
    {
        $this->locations->method('getLocations')->willThrowException(new \RuntimeException('boom'));
        $this->pickupLogger->expects($this->once())->method('error')->with($this->stringContains('boom'));
        $this->parentLogger->expects($this->never())->method($this->anything());

        $this->assertSame([], $this->carrier->getAllowedMethods());
    }
}
