<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Service\ReadyForPickup;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use PixelPerfect\StorePickup\Api\Service\ConfigInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupEmailSenderInterface;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;
use PixelPerfect\StorePickup\Exception\AlreadyNotifiedException;

class ReadyForPickupService implements ReadyForPickupServiceInterface
{
    /**
     * Constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param PickupMethodResolverInterface $methodResolver
     * @param LocationProviderInterface $locationProvider
     * @param ShipOrderInterface $shipOrder
     * @param ShipmentArgumentsBuilderInterface $argumentsBuilder
     * @param ReadyForPickupEmailSenderInterface $emailSender
     * @param ConfigInterface $config
     * @param DateTime $dateTime
     * @param string $status Status set on an order that is in state processing after the shipment.
     * @param list<string> $eligibleStates Order states in which the step may run.
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PickupMethodResolverInterface $methodResolver,
        private readonly LocationProviderInterface $locationProvider,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentArgumentsBuilderInterface $argumentsBuilder,
        private readonly ReadyForPickupEmailSenderInterface $emailSender,
        private readonly ConfigInterface $config,
        private readonly DateTime $dateTime,
        private readonly string $status = self::STATUS,
        private readonly array $eligibleStates = [Order::STATE_NEW, Order::STATE_PROCESSING, Order::STATE_COMPLETE]
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEligible(OrderInterface $order): bool
    {
        return $this->isPickup($order)
            && !$this->isNotified($order)
            && in_array($order->getState(), $this->eligibleStates, true);
    }

    /**
     * @inheritDoc
     */
    public function execute(int $orderId): void
    {
        $order = $this->load($orderId);
        $this->assertEligible($order);
        $code = (string) $this->methodResolver->getLocationCode($order->getShippingMethod());
        $storeId = (int) $order->getStoreId();
        $location = $this->locationProvider->getLocation($code, $storeId);
        if ($location === null) {
            throw new LocalizedException(__('Pickup location "%1" no longer exists.', $code));
        }

        if (!$order->hasShipments()) {
            $this->shipOrder->execute(
                $orderId,
                [],
                false,
                false,
                null,
                [],
                [],
                $this->argumentsBuilder->build($order, $location)
            );
            $order = $this->load($orderId);
        }

        // an invoiced order that the shipment made complete keeps Magento's status
        if ($order->getState() === Order::STATE_PROCESSING) {
            $order->setStatus($this->status);
        }

        $sent = $this->config->isReadyEmailEnabled($storeId) && $this->emailSender->send($order, $location);

        $order->addCommentToStatusHistory(
            (string) __('Your order is ready for pickup at %1.', $location->getName()),
            false,
            true
        )->setIsCustomerNotified($sent ? 1 : 0);
        $order->setData(self::NOTIFIED_AT, $this->dateTime->gmtDate());
        $this->orderRepository->save($order);
    }

    /**
     * Loads the order model; any other order implementation is no pickup order for this service.
     *
     * @param int $orderId
     * @return Order
     * @throws LocalizedException
     */
    private function load(int $orderId): Order
    {
        $order = $this->orderRepository->get($orderId);
        if (!$order instanceof Order) {
            throw new LocalizedException(__('Order %1 is not a store pickup order.', $order->getIncrementId()));
        }

        return $order;
    }

    /**
     * Throws the first reason why the order cannot be marked as ready for pickup.
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException
     */
    private function assertEligible(Order $order): void
    {
        if (!$this->isPickup($order)) {
            throw new LocalizedException(
                __('Order %1 is not a store pickup order.', $order->getIncrementId())
            );
        }
        if ($this->isNotified($order)) {
            throw new AlreadyNotifiedException(
                __('Order %1 has already been marked as ready for pickup.', $order->getIncrementId())
            );
        }
        if (!in_array($order->getState(), $this->eligibleStates, true)) {
            throw new LocalizedException(
                __(
                    'Order %1 cannot be marked as ready for pickup in state "%2".',
                    $order->getIncrementId(),
                    $order->getState()
                )
            );
        }
    }

    /**
     * Whether the order uses the pickup carrier.
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function isPickup(OrderInterface $order): bool
    {
        return $order instanceof Order && $this->methodResolver->isPickupMethod($order->getShippingMethod());
    }

    /**
     * Whether the order was already marked as ready for pickup.
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function isNotified(OrderInterface $order): bool
    {
        return $order instanceof Order && !in_array($order->getData(self::NOTIFIED_AT), [null, ''], true);
    }
}
