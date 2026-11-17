<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Integration\Fixture;

use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use PixelPerfect\StorePickup\Service\LocationProvider\ConfigLocationProvider;

/**
 * Places orders from cart data fixtures through CartManagementInterface and removes them again.
 *
 * The test sets the carts up with data fixtures (see the constants for the location config and an address) and
 * calls resetLocationCaches() in setUp() and cleanUpOrders() plus resetLocationCaches() in tearDown().
 */
trait PickupOrderTrait
{
    /**
     * Two active pickup locations; "main" has opening hours and a note.
     */
    private const string LOCATIONS = '['
        . '{"code":"main","name":"Main Store","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","telephone":"+43 1 0000000","opening_hours":"Mon-Fri 9:00-18:00",'
        . '"note":"Please use the side entrance.","price":"","sort_order":"10","active":"1"},'
        . '{"code":"second","name":"Second Store","street":"2 Example Street","postcode":"8010","city":"Graz",'
        . '"country_id":"AT","price":"","sort_order":"20","active":"1"}]';

    private const array ADDRESS = [
        'firstname' => 'Jane',
        'lastname' => 'Doe',
        'telephone' => '+43 1 2345678',
        'street' => ['8 Billing Road'],
        'postcode' => '6020',
        'city' => 'Innsbruck',
        'country_id' => 'AT',
        'region_id' => null,
        'company' => null,
    ];

    /** @var list<int> */
    private array $placedOrderIds = [];

    /**
     * A data fixture by its name.
     *
     * @param string $name
     * @return DataObject
     */
    private function fixture(string $name): DataObject
    {
        $fixture = Bootstrap::getObjectManager()->get(DataFixtureStorageManager::class)->getStorage()->get($name);
        self::assertInstanceOf(DataObject::class, $fixture);

        return $fixture;
    }

    /**
     * Places the order of a cart fixture and remembers it for cleanUpOrders().
     *
     * @param string $cart
     * @return int Order id.
     */
    private function placeOrder(string $cart = 'cart'): int
    {
        $objectManager = Bootstrap::getObjectManager();
        $cartId = (int) $this->fixture($cart)->getId();
        $payment = $objectManager->get(PaymentMethodManagementInterface::class)->get($cartId);
        $orderId = (int) $objectManager->get(CartManagementInterface::class)->placeOrder($cartId, $payment);
        self::assertGreaterThan(0, $orderId);
        $this->placedOrderIds[] = $orderId;

        return $orderId;
    }

    /**
     * The order as stored now, bypassing the order repository's per-request cache.
     *
     * @param int $orderId
     * @return Order
     */
    private function freshOrder(int $orderId): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class);
        $order->load($orderId);
        self::assertSame($orderId, (int) $order->getId());

        return $order;
    }

    /**
     * Deletes the orders placed by this test.
     *
     * Fixture rollback deletes products, customers and orders, which the frontend area forbids without isSecureArea.
     */
    private function cleanUpOrders(): void
    {
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $orderRepository = Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class);
        foreach ($this->placedOrderIds as $orderId) {
            $orderRepository->delete($orderRepository->get($orderId));
        }
        $this->placedOrderIds = [];
    }

    /**
     * The location providers cache per store for the length of a request; every test is a request of its own.
     */
    private function resetLocationCaches(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        foreach ([CompositeLocationProvider::class, ConfigLocationProvider::class] as $class) {
            $provider = $objectManager->get($class);
            (function (): void {
                $this->cache = [];
            })->call($provider);
        }
    }
}
