<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Controller\Adminhtml\Order;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Controller\Adminhtml\Order\ReadyForPickup;
use PixelPerfect\StorePickup\Exception\AlreadyNotifiedException;

class ReadyForPickupTest extends TestCase
{
    use ControllerContextTrait;

    /** @var ReadyForPickupServiceInterface&MockObject */
    private $service;

    /** @var ReadyEmailFailureDetectorInterface&MockObject */
    private $detector;

    private ReadyForPickup $controller;

    protected function setUp(): void
    {
        $context = $this->context();
        $this->request->method('getParam')->with('order_id')->willReturn('42');
        $this->service = $this->createMock(ReadyForPickupServiceInterface::class);
        $this->detector = $this->createMock(ReadyEmailFailureDetectorInterface::class);
        $this->controller = new ReadyForPickup(
            $context,
            $this->service,
            $this->orderRepository([42 => '000000042']),
            $this->detector
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
}
