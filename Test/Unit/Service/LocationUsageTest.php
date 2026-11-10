<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\ResourceModel\LocationUsage as LocationUsageResource;
use PixelPerfect\StorePickup\Service\LocationUsage;

class LocationUsageTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public static function countProvider(): array
    {
        return [
            'nothing' => [0, 0, false],
            'quote only' => [2, 0, true],
            'order only' => [0, 1, true],
            'both' => [1, 1, true],
        ];
    }

    /**
     * @dataProvider countProvider
     */
    public function testInUseWhenAQuoteOrAnOrderUsesTheCode(int $quotes, int $orders, bool $expected): void
    {
        $resource = $this->createMock(LocationUsageResource::class);
        $resource->method('countOpenQuotes')->with('main')->willReturn($quotes);
        $resource->method('countOpenOrders')->with('main')->willReturn($orders);

        $this->assertSame($expected, (new LocationUsage($resource))->isInUse('main'));
    }
}
