<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\OrderPlacementProcessorInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Observer\RunPickupOrderPlacementProcessors;
use Psr\Log\LoggerInterface;

class RunPickupOrderPlacementProcessorsTest extends TestCase
{
    /** @var PickupMethodResolverInterface&MockObject */
    private $resolver;

    /** @var LocationProviderInterface&MockObject */
    private $locations;

    /** @var OrderPlacementProcessorInterface&MockObject */
    private $processor;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private RunPickupOrderPlacementProcessors $observer;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(PickupMethodResolverInterface::class);
        $this->locations = $this->createMock(LocationProviderInterface::class);
        $this->processor = $this->createMock(OrderPlacementProcessorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new RunPickupOrderPlacementProcessors(
            $this->resolver,
            $this->locations,
            $this->processor,
            $this->logger
        );
    }

    private function event(Order $order): Observer
    {
        return new Observer(['event' => new \Magento\Framework\Event(['order' => $order])]);
    }

    private function order(?string $method, int $storeId = 3): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getShippingMethod')->willReturn($method);
        $order->method('getStoreId')->willReturn((string)$storeId);

        return $order;
    }

    public function testPickupOrderRunsTheProcessorsWithTheLocation(): void
    {
        $order = $this->order('pp_store_pickup_main');
        $location = new PickupLocation('main', 'Main', ['1 Example Street'], '1010', 'Vienna', 'AT');
        $this->resolver->method('getLocationCode')->with('pp_store_pickup_main')->willReturn('main');
        $this->locations->expects($this->once())->method('getLocation')->with('main', 3)->willReturn($location);
        $this->processor->expects($this->once())->method('process')->with($order, $location);

        $this->observer->execute($this->event($order));
    }

    public function testNonPickupOrderSkips(): void
    {
        $this->resolver->method('getLocationCode')->with('flatrate_flatrate')->willReturn(null);
        $this->locations->expects($this->never())->method('getLocation');
        $this->processor->expects($this->never())->method('process');

        $this->observer->execute($this->event($this->order('flatrate_flatrate')));
    }

    public function testMissingLocationSkips(): void
    {
        $this->resolver->method('getLocationCode')->willReturn('gone');
        $this->locations->method('getLocation')->willReturn(null);
        $this->processor->expects($this->never())->method('process');

        $this->observer->execute($this->event($this->order('pp_store_pickup_gone')));
    }

    public function testMissingOrderSkips(): void
    {
        $this->processor->expects($this->never())->method('process');

        $this->observer->execute(new Observer(['event' => new \Magento\Framework\Event([])]));
    }

    public function testAnyThrowableIsLoggedAndSwallowed(): void
    {
        $this->resolver->method('getLocationCode')->willThrowException(new \TypeError('bad'));
        $this->logger->expects($this->once())->method('error');

        $this->observer->execute($this->event($this->order('pp_store_pickup_main')));
    }
}
