<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Controller\Adminhtml\Order;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Controller\Adminhtml\Order\MassReadyForPickup;
use Psr\Log\LoggerInterface;

class MassReadyForPickupTest extends TestCase
{
    use ControllerContextTrait;

    /** @var ReadyForPickupServiceInterface&MockObject */
    private $service;

    /** @var ReadyEmailFailureDetectorInterface&MockObject */
    private $detector;

    /** @var Filter&MockObject */
    private $filter;

    /** @var Collection&MockObject */
    private $collection;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private MassReadyForPickup $controller;

    protected function setUp(): void
    {
        $context = $this->context();
        $this->service = $this->createMock(ReadyForPickupServiceInterface::class);
        $this->detector = $this->createMock(ReadyEmailFailureDetectorInterface::class);
        $this->collection = $this->createMock(Collection::class);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);
        $this->filter = $this->createMock(Filter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new MassReadyForPickup(
            $context,
            $this->filter,
            $collectionFactory,
            $this->service,
            $this->orderRepository([7 => '000000007', 8 => '000000008', 9 => '000000009']),
            $this->detector,
            $this->logger
        );
    }

    /**
     * @param array<int, string> $orders order id => increment id
     * @return void
     */
    private function select(array $orders): void
    {
        $items = [];
        foreach ($orders as $id => $incrementId) {
            $order = $this->getMockBuilder(Order::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getEntityId', 'getIncrementId'])
                ->getMock();
            $order->method('getEntityId')->willReturn((string) $id);
            $order->method('getIncrementId')->willReturn($incrementId);
            $items[$id] = $order;
        }
        $this->collection->method('getItems')->willReturn($items);
        $this->filter->method('getCollection')->with($this->collection)->willReturn($this->collection);
    }

    public function testIsAPostActionGuardedByTheAcl(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller);
        $this->assertSame('PixelPerfect_StorePickup::ready_for_pickup', MassReadyForPickup::ADMIN_RESOURCE);
    }

    public function testOneGoodAndOneNonPickupOrder(): void
    {
        $this->select([7 => '000000007', 8 => '000000008']);
        $this->service->expects($this->exactly(2))->method('execute')->willReturnCallback(
            static function (int $orderId): void {
                if ($orderId === 8) {
                    throw new LocalizedException(__('Order %1 is not a store pickup order.', '000000008'));
                }
            }
        );
        $this->detector->method('hasFailed')->willReturn(false);

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame(
            [
                ['error', 'Order 000000008: Order 000000008 is not a store pickup order.'],
                ['success', '1 order(s) were marked as ready for pickup.'],
            ],
            $this->messages
        );
        $this->assertSame(['sales/order/index', []], $this->redirectedTo);
    }

    public function testFailedEmailAddsAWarningPerOrder(): void
    {
        $this->select([7 => '000000007', 8 => '000000008', 9 => '000000009']);
        $this->detector->method('hasFailed')->willReturnCallback(
            static fn ($order) => in_array($order->getEntityId(), [7, 9], true)
        );

        $this->controller->execute();

        $this->assertSame(
            [
                ['warning', 'Order 000000007 was marked as ready for pickup, but the email could not be sent.'],
                ['warning', 'Order 000000009 was marked as ready for pickup, but the email could not be sent.'],
                ['success', '3 order(s) were marked as ready for pickup.'],
            ],
            $this->messages
        );
    }

    public function testNoSuccessMessageWhenEveryOrderFails(): void
    {
        $this->select([8 => '000000008']);
        $this->service->method('execute')->willThrowException(new LocalizedException(__('Nope.')));
        $this->detector->expects($this->never())->method('hasFailed');

        $this->controller->execute();

        $this->assertSame([['error', 'Order 000000008: Nope.']], $this->messages);
    }

    public function testAFilterErrorBecomesAnErrorAndRedirects(): void
    {
        $this->filter->method('getCollection')->willThrowException(
            new LocalizedException(__('An item needs to be selected. Select and try again.'))
        );
        $this->service->expects($this->never())->method('execute');

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame([['error', 'An item needs to be selected. Select and try again.']], $this->messages);
        $this->assertSame(['sales/order/index', []], $this->redirectedTo);
    }

    public function testUnexpectedErrorIsLoggedAndTheBatchContinues(): void
    {
        $this->select([7 => '000000007', 8 => '000000008', 9 => '000000009']);
        $error = new \RuntimeException('Deadlock found');
        $processed = [];
        $this->service->expects($this->exactly(3))->method('execute')->willReturnCallback(
            static function (int $orderId) use (&$processed, $error): void {
                $processed[] = $orderId;
                if ($orderId === 8) {
                    throw $error;
                }
            }
        );
        $this->detector->method('hasFailed')->willReturnCallback(
            static fn ($order) => $order->getEntityId() === 9
        );
        $this->logger->expects($this->once())->method('error')->with(
            'Order 000000008 could not be marked as ready for pickup.',
            ['exception' => $error]
        );

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame([7, 8, 9], $processed);
        $this->assertSame(
            [
                ['error', 'Order 000000008 could not be marked as ready for pickup. See the store pickup log.'],
                ['warning', 'Order 000000009 was marked as ready for pickup, but the email could not be sent.'],
                ['success', '2 order(s) were marked as ready for pickup.'],
            ],
            $this->messages
        );
        $this->assertSame(['sales/order/index', []], $this->redirectedTo);
    }
}
