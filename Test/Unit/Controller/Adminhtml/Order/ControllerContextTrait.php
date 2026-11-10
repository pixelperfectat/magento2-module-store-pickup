<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds an admin action context that records the messages and the redirect path.
 */
trait ControllerContextTrait
{
    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var array{string, array<string, mixed>}|null */
    private ?array $redirectedTo = null;

    /** @var RequestInterface&MockObject */
    private $request;

    /** @var Redirect&MockObject */
    private $redirect;

    /**
     * @return Context&MockObject
     */
    private function context()
    {
        $this->request = $this->createMock(RequestInterface::class);
        $messageManager = $this->createMock(ManagerInterface::class);
        foreach (['Success', 'Error', 'Warning'] as $type) {
            $messageManager->method('add' . $type . 'Message')->willReturnCallback(
                function ($message) use ($type, $messageManager) {
                    $this->messages[] = [strtolower($type), (string) $message];
                    return $messageManager;
                }
            );
        }
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(
            function (string $path, array $params = []) {
                $this->redirectedTo = [$path, $params];
                return $this->redirect;
            }
        );
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        return $context;
    }

    /**
     * @param array<int, string> $incrementIds order id => increment id
     * @return OrderRepositoryInterface&MockObject
     */
    private function orderRepository(array $incrementIds)
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function ($id) use ($incrementIds) {
                $order = $this->createMock(OrderInterface::class);
                $order->method('getEntityId')->willReturn($id);
                $order->method('getIncrementId')->willReturn($incrementIds[$id]);
                return $order;
            }
        );

        return $repository;
    }
}
