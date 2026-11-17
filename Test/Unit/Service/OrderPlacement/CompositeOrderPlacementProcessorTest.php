<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\OrderPlacement;

use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\OrderPlacementProcessorInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\OrderPlacement\CompositeOrderPlacementProcessor;
use Psr\Log\LoggerInterface;

class CompositeOrderPlacementProcessorTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testNoProcessorsIsANoOp(): void
    {
        $this->logger->expects($this->never())->method('error');
        (new CompositeOrderPlacementProcessor($this->logger, []))->process(
            $this->createMock(OrderInterface::class),
            new PickupLocation('main', 'Main', ['1 Example Street'], '1010', 'Vienna', 'AT')
        );
    }

    public function testProcessorsRunInOrderWithOrderAndLocation(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $location = new PickupLocation('main', 'Main', ['1 Example Street'], '1010', 'Vienna', 'AT');
        $calls = [];
        $first = $this->createMock(OrderPlacementProcessorInterface::class);
        $first->expects($this->once())->method('process')->with($order, $location)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'first';
            });
        $second = $this->createMock(OrderPlacementProcessorInterface::class);
        $second->expects($this->once())->method('process')->with($order, $location)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'second';
            });

        (new CompositeOrderPlacementProcessor($this->logger, ['a' => $first, 'b' => $second]))
            ->process($order, $location);

        $this->assertSame(['first', 'second'], $calls);
    }

    public function testThrowingProcessorIsLoggedAndTheRestStillRun(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $location = new PickupLocation('main', 'Main', ['1 Example Street'], '1010', 'Vienna', 'AT');
        $first = $this->createMock(OrderPlacementProcessorInterface::class);
        $first->method('process')->willThrowException(new \RuntimeException('boom'));
        $second = $this->createMock(OrderPlacementProcessorInterface::class);
        $second->expects($this->once())->method('process')->with($order, $location);
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('boom'));

        (new CompositeOrderPlacementProcessor($this->logger, ['a' => $first, 'b' => $second]))
            ->process($order, $location);
    }

    public function testErrorIsAlsoCaught(): void
    {
        $first = $this->createMock(OrderPlacementProcessorInterface::class);
        $first->method('process')->willThrowException(new \TypeError('bad type'));
        $this->logger->expects($this->once())->method('error');

        (new CompositeOrderPlacementProcessor($this->logger, ['a' => $first]))->process(
            $this->createMock(OrderInterface::class),
            new PickupLocation('main', 'Main', ['1 Example Street'], '1010', 'Vienna', 'AT')
        );
    }
}
