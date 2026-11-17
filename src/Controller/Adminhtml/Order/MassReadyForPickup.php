<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use PixelPerfect\StorePickup\Api\Service\ReadyEmailFailureDetectorInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Marks the orders selected in the admin order grid as ready for pickup, one by one.
 */
class MassReadyForPickup extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PixelPerfect_StorePickup::ready_for_pickup';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param ReadyForPickupServiceInterface $readyForPickup
     * @param OrderRepositoryInterface $orderRepository
     * @param ReadyEmailFailureDetectorInterface $emailFailureDetector
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ReadyForPickupServiceInterface $readyForPickup,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ReadyEmailFailureDetectorInterface $emailFailureDetector,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Runs the ready-for-pickup step per selected order; one failing order, for any reason, does not stop the others.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('sales/order/index');
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }

        $marked = 0;
        foreach ($collection->getItems() as $selected) {
            $orderId = (int) $selected->getEntityId();
            try {
                $this->readyForPickup->execute($orderId);
                $marked++;
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
                $this->messageManager->addErrorMessage(
                    __('Order %1: %2', $selected->getIncrementId(), $e->getMessage())
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Order %s could not be marked as ready for pickup.', $selected->getIncrementId()),
                    ['exception' => $e]
                );
                $this->messageManager->addErrorMessage(
                    __(
                        'Order %1 could not be marked as ready for pickup. See the store pickup log.',
                        $selected->getIncrementId()
                    )
                );
            }
        }
        if ($marked > 0) {
            $this->messageManager->addSuccessMessage(__('%1 order(s) were marked as ready for pickup.', $marked));
        }

        return $redirect;
    }
}
