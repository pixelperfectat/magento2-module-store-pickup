<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Integration\Config;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\LocationUsageInterface;
use PixelPerfect\StorePickup\Model\Config\Backend\Locations;

/**
 * A pickup location code cannot be removed or renamed while an open order uses it.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class LocationCodeInUseTest extends TestCase
{
    private const string LOCATIONS = '['
        . '{"code":"main","name":"Main Store","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","price":"","sort_order":"10","active":"1"},'
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
    private array $orderIds = [];

    protected function tearDown(): void
    {
        // fixture rollback deletes products and orders, which the frontend area forbids without this flag
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $orderRepository = Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class);
        foreach ($this->orderIds as $orderId) {
            $orderRepository->delete($orderRepository->get($orderId));
        }
        $this->orderIds = [];
    }

    private function placeOrder(): Order
    {
        $objectManager = Bootstrap::getObjectManager();
        $fixture = $objectManager->get(DataFixtureStorageManager::class)->getStorage()->get('cart');
        self::assertInstanceOf(DataObject::class, $fixture);
        $cartId = (int) $fixture->getId();
        $payment = $objectManager->get(PaymentMethodManagementInterface::class)->get($cartId);
        $orderId = (int) $objectManager->get(CartManagementInterface::class)->placeOrder($cartId, $payment);
        $this->orderIds[] = $orderId;
        $order = $objectManager->get(OrderRepositoryInterface::class)->get($orderId);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    /**
     * Backend model as the admin config save builds it, old value read from the saved default-scope config.
     *
     * @param array<string, array<string, string>> $rows
     */
    private function backend(array $rows): Locations
    {
        $backend = Bootstrap::getObjectManager()->create(Locations::class);
        $backend->setPath('carriers/pppickup/locations');
        $backend->setScope('default');
        $backend->setValue($rows);

        return $backend;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function row(string $code, string $active = '1'): array
    {
        return [
            'code' => $code,
            'name' => ucfirst($code) . ' Store',
            'street' => '1 Example Street',
            'postcode' => '1010',
            'city' => 'Vienna',
            'country_id' => 'AT',
            'active' => $active,
        ];
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testCodeOfAnOpenOrderCannotBeRemovedUntilTheOrderIsCanceled(): void
    {
        $order = $this->placeOrder();
        self::assertSame('main', $order->getData('pp_pickup_location_code'));
        $usage = Bootstrap::getObjectManager()->get(LocationUsageInterface::class);
        self::assertTrue($usage->isInUse('main'));
        self::assertFalse($usage->isInUse('second'));

        try {
            $this->backend(['_1' => $this->row('second')])->beforeSave();
            self::fail('Removing a code that an open order uses must be rejected.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString(
                'Pickup location "main" is used by open carts or orders.',
                $exception->getMessage()
            );
        }

        // deactivating keeps the code and is allowed
        $this->backend(['_1' => $this->row('main', '0'), '_2' => $this->row('second')])->beforeSave();

        $order->cancel();
        Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class)->save($order);
        self::assertSame(Order::STATE_CANCELED, $order->getState());
        self::assertFalse($usage->isInUse('main'));

        $this->backend(['_1' => $this->row('second')])->beforeSave();
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testStringValueFromTheCommandLineIsGuardedToo(): void
    {
        $this->placeOrder();

        // config:set hands the backend model the JSON text, not an array
        $json = '[{"code":"second","name":"Second Store","street":"2 Example Street","postcode":"8010",'
            . '"city":"Graz","country_id":"AT","price":"","sort_order":"20","active":"1"}]';
        $backend = Bootstrap::getObjectManager()->create(Locations::class);
        $backend->setPath('carriers/pppickup/locations');
        $backend->setScope('default');
        $backend->setValue($json);

        try {
            $backend->beforeSave();
            self::fail('A string value that drops a code an open order uses must be rejected.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString(
                'Pickup location "main" is used by open carts or orders.',
                $exception->getMessage()
            );
        }
    }

    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testCodeOfAnActiveCartIsInUse(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fixture = $objectManager->get(DataFixtureStorageManager::class)->getStorage()->get('cart');
        self::assertInstanceOf(DataObject::class, $fixture);
        $cartRepository = $objectManager->get(CartRepositoryInterface::class);
        $cart = $cartRepository->get((int) $fixture->getId());
        $cart->setData('pp_pickup_location_code', 'second');
        $cartRepository->save($cart);

        self::assertTrue($objectManager->get(LocationUsageInterface::class)->isInUse('second'));

        $cart->setIsActive(false);
        $cartRepository->save($cart);

        self::assertFalse($objectManager->get(LocationUsageInterface::class)->isInUse('second'));
    }

    /**
     * Website override that adds the code "extra" on top of the default locations.
     */
    private const string WEBSITE_LOCATIONS = '['
        . '{"code":"main","name":"Main Store","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","price":"","sort_order":"10","active":"1"},'
        . '{"code":"extra","name":"Extra Store","street":"3 Example Street","postcode":"4020","city":"Linz",'
        . '"country_id":"AT","price":"","sort_order":"30","active":"1"}]';

    private function websiteBackend(string $scope = 'websites'): Locations
    {
        $backend = Bootstrap::getObjectManager()->create(Locations::class);
        $backend->setPath('carriers/pppickup/locations');
        $backend->setScope($scope);
        $backend->setScopeCode('base');

        return $backend;
    }

    #[Config('carriers/pppickup/locations', self::LOCATIONS)]
    #[Config('carriers/pppickup/locations', self::WEBSITE_LOCATIONS, 'website', 'base')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testWebsiteOverrideCodeOfAnActiveCartCannotBeRemovedOrRevertedToDefault(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fixture = $objectManager->get(DataFixtureStorageManager::class)->getStorage()->get('cart');
        self::assertInstanceOf(DataObject::class, $fixture);
        $cartRepository = $objectManager->get(CartRepositoryInterface::class);
        $cart = $cartRepository->get((int) $fixture->getId());
        $cart->setData('pp_pickup_location_code', 'extra');
        $cartRepository->save($cart);

        // saving the website override without the code is refused
        $save = $this->websiteBackend();
        $save->setValue(['_1' => $this->row('main')]);
        try {
            $save->beforeSave();
            self::fail('Removing a website code that an active cart uses must be rejected.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('Pickup location "extra" is used by open carts', $exception->getMessage());
        }

        // "Use default" deletes the row: the default list has no "extra", so the delete is refused
        try {
            $this->websiteBackend()->beforeDelete();
            self::fail('Reverting a website override that drops an in-use code must be rejected.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('Pickup location "extra" is used by open carts', $exception->getMessage());
        }

        // once the cart is gone, the revert is allowed
        $cart->setIsActive(false);
        $cartRepository->save($cart);
        $this->websiteBackend()->beforeDelete();
    }
}
