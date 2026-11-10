<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\ReadyForPickup;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Service\ReadyForPickup\EmailFailureDetector;

class EmailFailureDetectorTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    private EmailFailureDetector $detector;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->detector = new EmailFailureDetector($this->config);
    }

    /**
     * @param int|null $id
     * @param int|null $notified
     * @return OrderStatusHistoryInterface&MockObject
     */
    private function history(?int $id, ?int $notified)
    {
        $history = $this->createMock(OrderStatusHistoryInterface::class);
        $history->method('getEntityId')->willReturn($id);
        $history->method('getIsCustomerNotified')->willReturn($notified);

        return $history;
    }

    /**
     * @param list<OrderStatusHistoryInterface>|null $histories
     * @return OrderInterface&MockObject
     */
    private function order(?array $histories, string $storeId = '3')
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getStatusHistories')->willReturn($histories);

        return $order;
    }

    public function testFailedWhenEnabledAndTheNewestCommentWasNotSent(): void
    {
        $this->config->expects($this->once())->method('isReadyEmailEnabled')->with(3)->willReturn(true);
        $order = $this->order([$this->history(10, 1), $this->history(12, 0), $this->history(11, 1)]);

        $this->assertTrue($this->detector->hasFailed($order));
    }

    public function testNotFailedWhenTheNewestCommentWasSent(): void
    {
        $this->config->method('isReadyEmailEnabled')->willReturn(true);
        $order = $this->order([$this->history(12, 1), $this->history(10, 0), $this->history(11, 0)]);

        $this->assertFalse($this->detector->hasFailed($order));
    }

    public function testNotFailedWhenTheEmailIsDisabled(): void
    {
        $this->config->method('isReadyEmailEnabled')->with(3)->willReturn(false);
        $order = $this->order([$this->history(12, 0)]);

        $this->assertFalse($this->detector->hasFailed($order));
    }

    public function testNotFailedWithoutComments(): void
    {
        $this->config->method('isReadyEmailEnabled')->willReturn(true);

        $this->assertFalse($this->detector->hasFailed($this->order([])));
        $this->assertFalse($this->detector->hasFailed($this->order(null)));
    }

    public function testTheFirstOfTwoEqualIdsWins(): void
    {
        $this->config->method('isReadyEmailEnabled')->willReturn(true);

        $this->assertTrue($this->detector->hasFailed($this->order([$this->history(5, 0), $this->history(5, 1)])));
        $this->assertFalse($this->detector->hasFailed($this->order([$this->history(5, 1), $this->history(5, 0)])));
    }

    public function testAMissingNotifiedFlagCountsAsNotSent(): void
    {
        $this->config->method('isReadyEmailEnabled')->willReturn(true);

        $this->assertTrue($this->detector->hasFailed($this->order([$this->history(5, null)])));
    }
}
