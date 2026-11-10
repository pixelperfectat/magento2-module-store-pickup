<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use Psr\Log\LoggerInterface;

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
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ReadyForPickupServiceInterface $readyForPickup,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ReadyEmailFailureDetectorInterface $emailFailureDetector,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Runs the ready-for-pickup step and returns to the order view, or to the grid for an unknown order.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');
        try {
            if ($orderId <= 0) {
                throw new NoSuchEntityException(__('The order could not be found.'));
            }
            $incrementId = (string) $this->orderRepository->get($orderId)->getIncrementId();
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('The order could not be found.'));
            $redirect->setPath('sales/order/index');
            return $redirect;
        }

        try {
            $this->readyForPickup->execute($orderId);
            $this->messageManager->addSuccessMessage(__('The order was marked as ready for pickup.'));
            if ($this->emailFailureDetector->hasFailed($this->orderRepository->get($orderId))) {
                $this->messageManager->addWarningMessage(
                    __('Order %1 was marked as ready for pickup, but the email could not be sent.', $incrementId)
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Order %s could not be marked as ready for pickup.', $incrementId),
                ['exception' => $e]
            );
            $this->messageManager->addErrorMessage(
                __('Order %1 could not be marked as ready for pickup. See the store pickup log.', $incrementId)
            );
        }

        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);

        return $redirect;
    }
}
