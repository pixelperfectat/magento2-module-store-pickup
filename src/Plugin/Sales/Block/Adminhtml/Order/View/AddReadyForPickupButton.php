<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Plugin\Sales\Block\Adminhtml\Order\View;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;

/**
 * Adds the "Ready for Pickup" button to the admin order view of an eligible pickup order.
 */
class AddReadyForPickupButton
{
    public const string BUTTON_ID = 'ready_for_pickup';

    /**
     * Constructor.
     *
     * @param ReadyForPickupServiceInterface $readyForPickup
     * @param AuthorizationInterface $authorization
     * @param string $aclResource ACL resource the admin user needs to see the button.
     * @param string $route Admin route of the action the button calls.
     */
    public function __construct(
        private readonly ReadyForPickupServiceInterface $readyForPickup,
        private readonly AuthorizationInterface $authorization,
        private readonly string $aclResource = 'PixelPerfect_StorePickup::ready_for_pickup',
        private readonly string $route = 'pp_store_pickup/order/readyForPickup'
    ) {
    }

    /**
     * Adds the button before the block prepares its layout, as the core buttons are.
     *
     * @param View $subject
     * @param LayoutInterface $layout
     * @return null
     */
    public function beforeSetLayout(View $subject, LayoutInterface $layout)
    {
        /** @var OrderInterface|null $order the registry holds no order outside the order view */
        $order = $subject->getOrder();
        if (!$order instanceof OrderInterface
            || !$this->authorization->isAllowed($this->aclResource)
            || !$this->readyForPickup->isEligible($order)
        ) {
            return null;
        }

        $message = $subject->escapeJs(
            (string) __('Create the shipment if needed, set the status and email the customer that the order is ready for pickup?')
        );
        $url = $subject->escapeJs($subject->getUrl($this->route, ['order_id' => (int) $order->getEntityId()]));
        $subject->addButton(
            self::BUTTON_ID,
            [
                'label' => __('Ready for Pickup'),
                'class' => 'ready-for-pickup',
                'onclick' => "confirmSetLocation('{$message}', '{$url}')",
            ]
        );

        return null;
    }
}
