<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\ResourceModel\LocationUsage;

class LocationUsageTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: mixed}> $expectedWheres
     */
    private function usage(string $connectionName, string $table, array $expectedWheres, string $count): LocationUsage
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('from')->with('prefix_' . $table, ['COUNT(*)'])->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(
            function (string $condition, mixed $value) use (&$wheres, $select): Select {
                $wheres[] = [$condition, $value];

                return $select;
            }
        );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->with($select)->willReturnCallback(
            function () use (&$wheres, $expectedWheres, $count): string {
                $this->assertSame($expectedWheres, $wheres);

                return $count;
            }
        );
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->with($connectionName)->willReturn($connection);
        $resourceConnection->method('getTableName')->with($table, $connectionName)
            ->willReturn('prefix_' . $table);

        return new LocationUsage($resourceConnection);
    }

    public function testCountsActiveQuotesWithTheCode(): void
    {
        $usage = $this->usage(
            'checkout',
            'quote',
            [['pp_pickup_location_code = ?', 'main'], ['is_active = ?', 1]],
            '3'
        );

        $this->assertSame(3, $usage->countOpenQuotes('main'));
    }

    public function testCountsUnfinishedOrdersWithTheCode(): void
    {
        $usage = $this->usage(
            'sales',
            'sales_order',
            [['pp_pickup_location_code = ?', '7'], ['state NOT IN (?)', ['complete', 'closed', 'canceled']]],
            '0'
        );

        $this->assertSame(0, $usage->countOpenOrders('7'));
    }
}
