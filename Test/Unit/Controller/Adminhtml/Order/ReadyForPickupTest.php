<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Controller\Adminhtml\Order;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Controller\Adminhtml\Order\ReadyForPickup;
use PixelPerfect\StorePickup\Exception\AlreadyNotifiedException;
use Psr\Log\LoggerInterface;

class ReadyForPickupTest extends TestCase
{
    use ControllerContextTrait;

    /** @var ReadyForPickupServiceInterface&MockObject */
    private $service;

    /** @var ReadyEmailFailureDetectorInterface&MockObject */
    private $detector;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private ReadyForPickup $controller;

    protected function setUp(): void
    {
        $this->controller = $this->build('42');
    }

    /**
     * @param mixed $orderIdParam
     * @param OrderRepositoryInterface|null $repository
     * @return ReadyForPickup
     */
    private function build($orderIdParam, ?OrderRepositoryInterface $repository = null): ReadyForPickup
    {
        $this->messages = [];
        $context = $this->context();
        $this->request->method('getParam')->with('order_id')->willReturn($orderIdParam);
        $this->service = $this->createMock(ReadyForPickupServiceInterface::class);
        $this->detector = $this->createMock(ReadyEmailFailureDetectorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        return new ReadyForPickup(
            $context,
            $this->service,
            $repository ?? $this->orderRepository([42 => '000000042']),
            $this->detector,
            $this->logger
        );
    }

    public function testIsAGetActionGuardedByTheAcl(): void
    {
        $this->assertInstanceOf(HttpGetActionInterface::class, $this->controller);
        $this->assertSame('PixelPerfect_StorePickup::ready_for_pickup', ReadyForPickup::ADMIN_RESOURCE);
    }

    public function testSuccessAddsTheMessageAndRedirectsToTheOrder(): void
    {
        $this->service->expects($this->once())->method('execute')->with(42);
        $this->detector->method('hasFailed')->willReturn(false);

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame([['success', 'The order was marked as ready for pickup.']], $this->messages);
        $this->assertSame(['sales/order/view', ['order_id' => 42]], $this->redirectedTo);
    }

    public function testFailedEmailAddsAWarningAfterTheSuccess(): void
    {
        $this->detector->expects($this->once())->method('hasFailed')
            ->with($this->callback(static fn (OrderInterface $order) => $order->getEntityId() === 42))
            ->willReturn(true);

        $this->controller->execute();

        $this->assertSame(
            [
                ['success', 'The order was marked as ready for pickup.'],
                ['warning', 'Order 000000042 was marked as ready for pickup, but the email could not be sent.'],
            ],
            $this->messages
        );
    }

    public function testLocalizedExceptionBecomesAnErrorAndStillRedirects(): void
    {
        $this->service->method('execute')->willThrowException(
            new AlreadyNotifiedException(__('Order %1 has already been marked as ready for pickup.', '000000042'))
        );
        $this->detector->expects($this->never())->method('hasFailed');

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame(
            [['error', 'Order 000000042 has already been marked as ready for pickup.']],
            $this->messages
        );
        $this->assertSame(['sales/order/view', ['order_id' => 42]], $this->redirectedTo);
    }

    public function testPlainLocalizedExceptionBecomesAnError(): void
    {
        $this->service->method('execute')->willThrowException(new LocalizedException(__('Nope.')));

        $this->controller->execute();

        $this->assertSame([['error', 'Nope.']], $this->messages);
    }

    public function testUnexpectedErrorIsLoggedAndShownAsAGenericError(): void
    {
        $error = new \RuntimeException('Deadlock found');
        $this->service->method('execute')->willThrowException($error);
        $this->detector->expects($this->never())->method('hasFailed');
        $this->logger->expects($this->once())->method('error')->with(
            'Order 000000042 could not be marked as ready for pickup.',
            ['exception' => $error]
        );

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame(
            [['error', 'Order 000000042 could not be marked as ready for pickup. See the store pickup log.']],
            $this->messages
        );
        $this->assertSame(['sales/order/view', ['order_id' => 42]], $this->redirectedTo);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function missingOrderIds(): array
    {
        return ['null' => [null], 'empty' => [''], 'zero' => ['0'], 'negative' => ['-3'], 'text' => ['abc']];
    }

    /**
     * @dataProvider missingOrderIds
     * @param mixed $param
     */
    public function testMissingOrderIdShowsNotFoundAndRedirectsToTheGrid($param): void
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects($this->never())->method('get');
        $controller = $this->build($param, $repository);
        $this->service->expects($this->never())->method('execute');

        $this->assertSame($this->redirect, $controller->execute());
        $this->assertSame([['error', 'The order could not be found.']], $this->messages);
        $this->assertSame(['sales/order/index', []], $this->redirectedTo);
    }

    public function testUnknownOrderIdShowsNotFoundAndRedirectsToTheGrid(): void
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $controller = $this->build('42', $repository);
        $this->service->expects($this->never())->method('execute');

        $controller->execute();

        $this->assertSame([['error', 'The order could not be found.']], $this->messages);
        $this->assertSame(['sales/order/index', []], $this->redirectedTo);
    }
}
