<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Setup\Patch\Data;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Setup\Patch\Data\AddReadyForPickupStatus;

require_once __DIR__ . '/../../../../Stub/StatusFactory.php';

class AddReadyForPickupStatusTest extends TestCase
{
    /** @var StatusResource&MockObject */
    private StatusResource $resource;

    /** @var Status&MockObject */
    private Status $status;

    private AddReadyForPickupStatus $patch;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(StatusResource::class);
        $this->status = $this->getMockBuilder(Status::class)
            ->disableOriginalConstructor()
            ->addMethods(['getStatus'])
            ->onlyMethods(['setData'])
            ->getMock();
        $factory = $this->createMock(StatusFactory::class);
        $factory->method('create')->willReturn($this->status);
        $this->patch = new AddReadyForPickupStatus($factory, $this->resource);
    }

    public function testCreatesTheStatusAndAssignsItToProcessing(): void
    {
        $this->resource->expects(self::once())->method('load')
            ->with($this->status, 'ready_for_pickup');
        $this->status->method('getStatus')->willReturn(null);
        $this->status->expects(self::once())->method('setData')
            ->with(['status' => 'ready_for_pickup', 'label' => 'Ready for Pickup']);
        $this->resource->expects(self::once())->method('save')->with($this->status);
        $this->resource->expects(self::once())->method('assignState')
            ->with('ready_for_pickup', Order::STATE_PROCESSING, false, true);

        self::assertSame($this->patch, $this->patch->apply());
    }

    public function testKeepsAnExistingStatusAndStillAssignsTheState(): void
    {
        $this->status->method('getStatus')->willReturn('ready_for_pickup');
        $this->status->expects(self::never())->method('setData');
        $this->resource->expects(self::never())->method('save');
        $this->resource->expects(self::once())->method('assignState')
            ->with('ready_for_pickup', Order::STATE_PROCESSING, false, true);

        $this->patch->apply();
    }

    public function testHasNoDependenciesOrAliases(): void
    {
        self::assertSame([], AddReadyForPickupStatus::getDependencies());
        self::assertSame([], $this->patch->getAliases());
    }
}
