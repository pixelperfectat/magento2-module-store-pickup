<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\ReadyForPickup;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterfaceFactory;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\ReadyForPickup\ShipmentArgumentsBuilder;

require_once __DIR__ . '/../../../Stub/ReadyForPickupFactories.php';

class ShipmentArgumentsBuilderTest extends TestCase
{
    public function testReturnsEmptyArgumentsFromTheFactory(): void
    {
        $arguments = $this->createMock(ShipmentCreationArgumentsInterface::class);
        $factory = $this->createMock(ShipmentCreationArgumentsInterfaceFactory::class);
        $factory->expects($this->once())->method('create')->with([])->willReturn($arguments);

        $builder = new ShipmentArgumentsBuilder($factory);

        self::assertSame($arguments, $builder->build(
            $this->createMock(OrderInterface::class),
            new PickupLocation('main', 'Main Store', ['1 Example Street'], '1010', 'Vienna', 'AT')
        ));
    }
}
