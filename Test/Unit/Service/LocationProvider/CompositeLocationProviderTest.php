<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\LocationProvider;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use Psr\Log\LoggerInterface;

class CompositeLocationProviderTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function location(string $code, string $name, int $sortOrder = 0): PickupLocationInterface
    {
        return new PickupLocation($code, $name, ['1 Example Street'], '1010', 'Vienna', 'AT', sortOrder: $sortOrder);
    }

    /**
     * @param array<string, PickupLocationInterface> $locations
     * @return LocationProviderInterface&MockObject
     */
    private function provider(array $locations): LocationProviderInterface
    {
        $provider = $this->createMock(LocationProviderInterface::class);
        $provider->method('getLocations')->willReturn($locations);
        $provider->method('getLocation')->willReturnCallback(
            static fn (string $code): ?PickupLocationInterface => $locations[$code] ?? null
        );

        return $provider;
    }

    public function testDuplicateCodeFirstProviderWinsAndWarnsOncePerRequest(): void
    {
        $first = $this->location('main', 'First');
        $second = $this->location('main', 'Second');
        $composite = new CompositeLocationProvider($this->logger, [
            'config' => $this->provider(['main' => $first]),
            'extra' => $this->provider(['main' => $second]),
        ]);
        $this->logger->expects($this->once())->method('warning')->with(
            'Pickup location code "main" from provider "extra" is already taken; ignored.'
        );

        $this->assertSame(['main' => $first], $composite->getLocations(1));
        $this->assertSame(['main' => $first], $composite->getLocations(1));
    }

    public function testMergesAndSortsAcrossProviders(): void
    {
        $composite = new CompositeLocationProvider($this->logger, [
            'config' => $this->provider(['b' => $this->location('b', 'B', 20), 'a' => $this->location('a', 'A', 30)]),
            'extra' => $this->provider(['c' => $this->location('c', 'C', 10)]),
        ]);

        $this->assertSame(['c', 'b', 'a'], array_keys($composite->getLocations(1)));
    }

    public function testGetLocationReturnsTheFirstProvidersHit(): void
    {
        $first = $this->location('main', 'First');
        $composite = new CompositeLocationProvider($this->logger, [
            'config' => $this->provider(['main' => $first]),
            'extra' => $this->provider(['main' => $this->location('main', 'Second'), 'only' => $this->location('only', 'Only')]),
        ]);

        $this->assertSame($first, $composite->getLocation('main', 1));
        $this->assertSame('Only', $composite->getLocation('only', 1)?->getName());
        $this->assertNull($composite->getLocation('missing', 1));
    }

    public function testThrowingProviderIsSkippedAndLogged(): void
    {
        $broken = $this->createMock(LocationProviderInterface::class);
        $broken->method('getLocations')->willThrowException(new \RuntimeException('boom'));
        $broken->method('getLocation')->willThrowException(new \RuntimeException('boom'));
        $good = $this->location('main', 'Main');
        $composite = new CompositeLocationProvider($this->logger, [
            'broken' => $broken,
            'config' => $this->provider(['main' => $good]),
        ]);
        $this->logger->expects($this->exactly(2))->method('error')->with(
            'Pickup location provider "broken" failed and is skipped: boom',
            $this->callback(static fn (array $context): bool => $context['exception'] instanceof \RuntimeException)
        );

        $this->assertSame(['main' => $good], $composite->getLocations(1));
        $this->assertSame($good, $composite->getLocation('main', 1));
    }

    public function testNoProvidersYieldsNothing(): void
    {
        $composite = new CompositeLocationProvider($this->logger);

        $this->assertSame([], $composite->getLocations(1));
        $this->assertNull($composite->getLocation('main', 1));
    }

    public function testCachesPerStore(): void
    {
        $provider = $this->createMock(LocationProviderInterface::class);
        $provider->expects($this->exactly(2))->method('getLocations')->willReturn([]);
        $composite = new CompositeLocationProvider($this->logger, ['config' => $provider]);

        $composite->getLocations(1);
        $composite->getLocations(1);
        $composite->getLocations(2);
    }

    public function testDuplicateDoesNotStopTheRestOfTheProvider(): void
    {
        $main = $this->location('main', 'Main');
        $only = $this->location('only', 'Only');
        $composite = new CompositeLocationProvider($this->logger, [
            'config' => $this->provider(['main' => $main]),
            'extra' => $this->provider(['main' => $this->location('main', 'Dup'), 'only' => $only]),
        ]);

        $this->assertSame(['main' => $main, 'only' => $only], $composite->getLocations(1));
    }
}
