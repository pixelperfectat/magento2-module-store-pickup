<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;

/**
 * Marks one order as ready for pickup from the admin order view.
 */
class ReadyForPickup extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PixelPerfect_StorePickup::ready_for_pickup';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param ReadyForPickupServiceInterface $readyForPickup
     * @param OrderRepositoryInterface $orderRepository
     * @param ReadyEmailFailureDetectorInterface $emailFailureDetector
     */
    public function __construct(
        Context $context,
        private readonly ReadyForPickupServiceInterface $readyForPickup,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ReadyEmailFailureDetectorInterface $emailFailureDetector
    ) {
        parent::__construct($context);
    }

    /**
     * Runs the ready-for-pickup step and returns to the order view.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        try {
            $this->readyForPickup->execute($orderId);
            $this->messageManager->addSuccessMessage(__('The order was marked as ready for pickup.'));
            $order = $this->orderRepository->get($orderId);
            if ($this->emailFailureDetector->hasFailed($order)) {
                $this->messageManager->addWarningMessage(
                    __(
                        'Order %1 was marked as ready for pickup, but the email could not be sent.',
                        $order->getIncrementId()
                    )
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);

        return $redirect;
    }
}
