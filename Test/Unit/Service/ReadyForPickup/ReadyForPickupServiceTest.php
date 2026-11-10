<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Service\ReadyForPickup;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupEmailSenderInterface;
use PixelPerfect\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;
use PixelPerfect\StorePickup\Exception\AlreadyNotifiedException;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Service\ReadyForPickup\ReadyForPickupService;

class ReadyForPickupServiceTest extends TestCase
{
    private const int ORDER_ID = 42;
    private const int STORE_ID = 3;

    /** @var OrderRepositoryInterface&MockObject */
    private $orderRepository;

    /** @var PickupMethodResolverInterface&MockObject */
    private $resolver;

    /** @var LocationProviderInterface&MockObject */
    private $locations;

    /** @var ShipOrderInterface&MockObject */
    private $shipOrder;

    /** @var ShipmentArgumentsBuilderInterface&MockObject */
    private $argumentsBuilder;

    /** @var ReadyForPickupEmailSenderInterface&MockObject */
    private $emailSender;

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var DateTime&MockObject */
    private $dateTime;

    private PickupLocation $location;

    private ReadyForPickupService $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->resolver = $this->createMock(PickupMethodResolverInterface::class);
        $this->resolver->method('getLocationCode')->willReturnCallback(
            static fn (?string $method): ?string => $method !== null && str_starts_with($method, 'pppickup_')
                ? substr($method, 9)
                : null
        );
        $this->resolver->method('isPickupMethod')->willReturnCallback(
            static fn (?string $method): bool => $method !== null && str_starts_with($method, 'pppickup_')
        );
        $this->locations = $this->createMock(LocationProviderInterface::class);
        $this->shipOrder = $this->createMock(ShipOrderInterface::class);
        $this->argumentsBuilder = $this->createMock(ShipmentArgumentsBuilderInterface::class);
        $this->emailSender = $this->createMock(ReadyForPickupEmailSenderInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-11-10 08:00:00');
        $this->location = new PickupLocation('main', 'Main Store', ['1 Example Street'], '1010', 'Vienna', 'AT');
        $this->service = new ReadyForPickupService(
            $this->orderRepository,
            $this->resolver,
            $this->locations,
            $this->shipOrder,
            $this->argumentsBuilder,
            $this->emailSender,
            $this->config,
            $this->dateTime
        );
    }

    /**
     * Order mock with a data store behind getData/setData and a status behind getStatus/setStatus.
     *
     * @return Order&MockObject
     */
    private function order(
        ?string $method = 'pppickup_main',
        string $state = Order::STATE_NEW,
        ?string $notifiedAt = null,
        bool $hasShipments = false,
        string $status = 'pending'
    ): Order {
        $data = ['pp_pickup_notified_at' => $notifiedAt, 'status' => $status, 'state' => $state];
        $order = $this->createMock(Order::class);
        $order->method('getShippingMethod')->willReturn($method);
        $order->method('getState')->willReturnCallback(static function () use (&$data) {
            return $data['state'];
        });
        $order->method('getStoreId')->willReturn((string) self::STORE_ID);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getEntityId')->willReturn((string) self::ORDER_ID);
        $order->method('hasShipments')->willReturn($hasShipments);
        $order->method('getData')->willReturnCallback(static function ($key = '') use (&$data) {
            return $key === '' ? $data : ($data[$key] ?? null);
        });
        $order->method('setData')->willReturnCallback(static function ($key, $value = null) use (&$data, &$order) {
            $data[$key] = $value;

            return $order;
        });
        $order->method('setState')->willReturnCallback(static function ($value) use (&$data, &$order) {
            $data['state'] = $value;

            return $order;
        });
        $order->method('getStatus')->willReturnCallback(static function () use (&$data) {
            return $data['status'];
        });
        $order->method('setStatus')->willReturnCallback(static function ($value) use (&$data, &$order) {
            $data['status'] = $value;

            return $order;
        });

        return $order;
    }

    /**
     * @return OrderStatusHistoryInterface&MockObject
     */
    private function expectComment(Order&MockObject $order, int $customerNotified): OrderStatusHistoryInterface
    {
        $history = $this->createMock(OrderStatusHistoryInterface::class);
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with(
                $this->callback(static fn ($comment): bool => (string) $comment
                    === 'Your order is ready for pickup at Main Store.'),
                false,
                true
            )
            ->willReturn($history);
        $history->expects($this->once())->method('setIsCustomerNotified')->with($customerNotified)
            ->willReturnSelf();

        return $history;
    }

    private function locationFound(): void
    {
        $this->locations->method('getLocation')->with('main', self::STORE_ID)->willReturn($this->location);
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: ?string, 3: class-string, 4: string}>
     */
    public static function rejectedOrders(): array
    {
        return [
            'not pickup wins over notified and state' => [
                'flatrate_flatrate',
                Order::STATE_CANCELED,
                '2026-11-01 10:00:00',
                LocalizedException::class,
                'Order 000000042 is not a store pickup order.',
            ],
            'no shipping method' => [
                null,
                Order::STATE_NEW,
                null,
                LocalizedException::class,
                'Order 000000042 is not a store pickup order.',
            ],
            'notified wins over state' => [
                'pppickup_main',
                Order::STATE_CANCELED,
                '2026-11-01 10:00:00',
                AlreadyNotifiedException::class,
                'Order 000000042 has already been marked as ready for pickup.',
            ],
            'canceled' => [
                'pppickup_main',
                Order::STATE_CANCELED,
                null,
                LocalizedException::class,
                'Order 000000042 cannot be marked as ready for pickup in state "canceled".',
            ],
            'holded' => [
                'pppickup_main',
                Order::STATE_HOLDED,
                null,
                LocalizedException::class,
                'Order 000000042 cannot be marked as ready for pickup in state "holded".',
            ],
        ];
    }

    /**
     * @dataProvider rejectedOrders
     * @param class-string<\Throwable> $exception
     */
    public function testRejectsIneligibleOrdersInOrderOfChecks(
        ?string $method,
        string $state,
        ?string $notifiedAt,
        string $exception,
        string $message
    ): void {
        $order = $this->order($method, $state, $notifiedAt);
        $this->orderRepository->method('get')->with(self::ORDER_ID)->willReturn($order);
        $this->locationFound();
        $this->shipOrder->expects($this->never())->method('execute');
        $this->emailSender->expects($this->never())->method('send');
        $this->orderRepository->expects($this->never())->method('save');

        try {
            $this->service->execute(self::ORDER_ID);
            self::fail('Expected ' . $exception);
        } catch (LocalizedException $e) {
            self::assertSame($exception, $e::class);
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testRejectsAnOrderThatIsNoOrderModel(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000042');
        $this->orderRepository->method('get')->willReturn($order);
        $this->shipOrder->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Order 000000042 is not a store pickup order.');

        $this->service->execute(self::ORDER_ID);
    }

    public function testRejectsAMissingLocation(): void
    {
        $order = $this->order();
        $this->orderRepository->method('get')->willReturn($order);
        $this->locations->method('getLocation')->with('main', self::STORE_ID)->willReturn(null);
        $this->shipOrder->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pickup location "main" no longer exists.');

        $this->service->execute(self::ORDER_ID);
    }

    public function testShipsSetsStatusSendsEmailAndRecordsTheStep(): void
    {
        $order = $this->order();
        $shipped = $this->order(state: Order::STATE_PROCESSING, status: 'processing');
        $this->orderRepository->expects($this->exactly(2))->method('get')->with(self::ORDER_ID)
            ->willReturnOnConsecutiveCalls($order, $shipped);
        $this->locationFound();
        $arguments = $this->createMock(ShipmentCreationArgumentsInterface::class);
        $this->argumentsBuilder->expects($this->once())->method('build')->with($order, $this->location)
            ->willReturn($arguments);
        $this->shipOrder->expects($this->once())->method('execute')
            ->with(self::ORDER_ID, [], false, false, null, [], [], $arguments)
            ->willReturn(7);
        $this->config->method('isReadyEmailEnabled')->with(self::STORE_ID)->willReturn(true);
        $this->emailSender->expects($this->once())->method('send')
            ->with($shipped, $this->location)
            ->willReturnCallback(static function () use ($shipped): bool {
                // the email shows the new status
                self::assertSame('ready_for_pickup', $shipped->getStatus());

                return true;
            });
        $this->expectComment($shipped, 1);
        $this->orderRepository->expects($this->once())->method('save')->with($shipped)
            ->willReturnCallback(static function (Order $saved): Order {
                self::assertSame('2026-11-10 08:00:00', $saved->getData('pp_pickup_notified_at'));

                return $saved;
            });

        $this->service->execute(self::ORDER_ID);

        self::assertSame('ready_for_pickup', $shipped->getStatus());
    }

    public function testKeepsAnExistingShipment(): void
    {
        $order = $this->order(state: Order::STATE_PROCESSING, hasShipments: true, status: 'processing');
        $this->orderRepository->method('get')->willReturn($order);
        $this->locationFound();
        $this->argumentsBuilder->expects($this->never())->method('build');
        $this->shipOrder->expects($this->never())->method('execute');
        $this->config->method('isReadyEmailEnabled')->willReturn(true);
        $this->emailSender->method('send')->willReturn(true);
        $this->expectComment($order, 1);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->service->execute(self::ORDER_ID);

        self::assertSame('ready_for_pickup', $order->getStatus());
    }

    public function testCompleteOrderKeepsItsStatus(): void
    {
        $order = $this->order(state: Order::STATE_NEW, status: 'pending');
        $complete = $this->order(state: Order::STATE_COMPLETE, status: 'complete');
        $this->orderRepository->method('get')->willReturnOnConsecutiveCalls($order, $complete);
        $this->locationFound();
        $this->shipOrder->expects($this->once())->method('execute');
        $this->config->method('isReadyEmailEnabled')->willReturn(true);
        $this->emailSender->method('send')->willReturn(true);
        $this->expectComment($complete, 1);
        $complete->expects($this->never())->method('setStatus');

        $this->service->execute(self::ORDER_ID);

        self::assertSame('complete', $complete->getStatus());
    }

    public function testShippedOrderInStateNewKeepsItsStatus(): void
    {
        $order = $this->order(state: Order::STATE_NEW, hasShipments: true, status: 'pending');
        $this->orderRepository->method('get')->willReturn($order);
        $this->locationFound();
        $this->config->method('isReadyEmailEnabled')->willReturn(true);
        $this->emailSender->method('send')->willReturn(true);
        $this->expectComment($order, 1);
        $order->expects($this->never())->method('setStatus');

        $this->service->execute(self::ORDER_ID);
    }

    public function testEmailDisabledDoesNotSendAndRecordsNoNotification(): void
    {
        $order = $this->order(state: Order::STATE_PROCESSING, hasShipments: true, status: 'processing');
        $this->orderRepository->method('get')->willReturn($order);
        $this->locationFound();
        $this->config->method('isReadyEmailEnabled')->with(self::STORE_ID)->willReturn(false);
        $this->emailSender->expects($this->never())->method('send');
        $this->expectComment($order, 0);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->service->execute(self::ORDER_ID);

        self::assertSame('ready_for_pickup', $order->getStatus());
        self::assertSame('2026-11-10 08:00:00', $order->getData('pp_pickup_notified_at'));
    }

    public function testFailedEmailRecordsNoNotification(): void
    {
        $order = $this->order(state: Order::STATE_PROCESSING, hasShipments: true, status: 'processing');
        $this->orderRepository->method('get')->willReturn($order);
        $this->locationFound();
        $this->config->method('isReadyEmailEnabled')->willReturn(true);
        $this->emailSender->expects($this->once())->method('send')->willReturn(false);
        $this->expectComment($order, 0);

        $this->service->execute(self::ORDER_ID);
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: ?string, 3: bool}>
     */
    public static function eligibility(): array
    {
        return [
            'new' => ['pppickup_main', Order::STATE_NEW, null, true],
            'processing' => ['pppickup_main', Order::STATE_PROCESSING, null, true],
            'complete' => ['pppickup_main', Order::STATE_COMPLETE, null, true],
            'pending payment' => ['pppickup_main', Order::STATE_PENDING_PAYMENT, null, false],
            'canceled' => ['pppickup_main', Order::STATE_CANCELED, null, false],
            'closed' => ['pppickup_main', Order::STATE_CLOSED, null, false],
            'holded' => ['pppickup_main', Order::STATE_HOLDED, null, false],
            'notified' => ['pppickup_main', Order::STATE_PROCESSING, '2026-11-01 10:00:00', false],
            'not pickup' => ['flatrate_flatrate', Order::STATE_PROCESSING, null, false],
            'no method' => [null, Order::STATE_PROCESSING, null, false],
        ];
    }

    /**
     * @dataProvider eligibility
     */
    public function testIsEligible(?string $method, string $state, ?string $notifiedAt, bool $expected): void
    {
        self::assertSame($expected, $this->service->isEligible($this->order($method, $state, $notifiedAt)));
    }

    public function testOrderThatIsNoOrderModelIsNotEligible(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);

        self::assertFalse($this->service->isEligible($order));
    }
}
