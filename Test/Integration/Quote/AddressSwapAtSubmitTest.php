<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Integration\Quote;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Test\Fixture\Customer;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ShippingAddressManagementInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\CustomerCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Api\Service\PickupAddressApplierInterface;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use PixelPerfect\StorePickup\Service\LocationProvider\ConfigLocationProvider;

/**
 * A pickup order gets the location address as its shipping address, whichever way the checkout got there.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class AddressSwapAtSubmitTest extends TestCase
{
    private const string LOCATIONS = '['
        . '{"code":"main","name":"Main Store","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","price":"","sort_order":"10","active":"1"},'
        . '{"code":"second","name":"Second Store","street":"2 Example Street","postcode":"8010","city":"Graz",'
        . '"country_id":"AT","price":"","sort_order":"20","active":"1"}]';

    private const string LOCATIONS_SECOND_INACTIVE = '['
        . '{"code":"main","name":"Main Store","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","price":"","sort_order":"10","active":"1"},'
        . '{"code":"second","name":"Second Store","street":"2 Example Street","postcode":"8010","city":"Graz",'
        . '"country_id":"AT","price":"","sort_order":"20","active":"0"}]';

    private const array BILLING = [
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

    private const array SHIPPING = [
        'firstname' => 'Jane',
        'lastname' => 'Doe',
        'telephone' => '+43 1 2345678',
        'street' => ['5 Home Street'],
        'postcode' => '5020',
        'city' => 'Salzburg',
        'country_id' => 'AT',
        'region_id' => null,
        'company' => null,
    ];

    /** @var list<int> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        $this->resetLocationCaches();
    }

    protected function tearDown(): void
    {
        // fixture rollback deletes products, customers and orders, which the frontend area forbids without this flag
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $orderRepository = Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class);
        foreach ($this->orderIds as $orderId) {
            $orderRepository->delete($orderRepository->get($orderId));
        }
        $this->orderIds = [];
        $this->resetLocationCaches();
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

    private function fixture(string $name): DataObject
    {
        $fixture = Bootstrap::getObjectManager()->get(DataFixtureStorageManager::class)->getStorage()->get($name);
        self::assertInstanceOf(DataObject::class, $fixture);

        return $fixture;
    }

    private function cart(string $name = 'cart'): Quote
    {
        $cart = Bootstrap::getObjectManager()->get(CartRepositoryInterface::class)
            ->get((int) $this->fixture($name)->getId());
        self::assertInstanceOf(Quote::class, $cart);

        return $cart;
    }

    private function placeOrder(Quote $cart): OrderInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $cartId = (int) $cart->getId();
        $payment = $objectManager->get(PaymentMethodManagementInterface::class)->get($cartId);
        $orderId = (int) $objectManager->get(CartManagementInterface::class)->placeOrder($cartId, $payment);
        $this->orderIds[] = $orderId;

        return $objectManager->get(OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * Select a pickup rate the way a checkout companion does: save the shipping method, then apply() and save.
     */
    private function selectPickup(Quote $cart, string $code): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $cart->getShippingAddress()->setCollectShippingRates(true);
        $cart->setTotalsCollectedFlag(false);
        $objectManager->get(ShippingMethodManagementInterface::class)->set((int) $cart->getId(), 'pppickup', $code);
        self::assertSame('pppickup_' . $code, $cart->getShippingAddress()->getShippingMethod());
        self::assertTrue($objectManager->get(PickupAddressApplierInterface::class)->apply($cart));
        $objectManager->get(CartRepositoryInterface::class)->save($cart);
    }

    private function gridLocationCode(int $orderId): ?string
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $select = $connection->select()
            ->from($resource->getTableName('sales_order_grid'), ['pp_pickup_location_code'])
            ->where('entity_id = ?', $orderId);
        $code = $connection->fetchOne($select);

        return $code === false || $code === null ? null : (string) $code;
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::BILLING])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::SHIPPING])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'main']
    )]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testGuestPickupOrderGetsLocationAddress(): void
    {
        // a checkout that hides the address form for pickup leaves the shipping contact fields empty
        $cart = $this->cart();
        $cart->getShippingAddress()->setFirstname('')->setTelephone('');
        Bootstrap::getObjectManager()->get(CartRepositoryInterface::class)->save($cart);

        $order = $this->placeOrder($this->cart());

        $address = $order->getShippingAddress();
        self::assertNotNull($address);
        self::assertSame(['1 Example Street'], $address->getStreet());
        self::assertSame('Vienna', $address->getCity());
        self::assertSame('1010', $address->getPostcode());
        self::assertSame('Main Store', $address->getCompany());
        self::assertSame('Jane', $address->getFirstname());
        self::assertSame('+43 1 2345678', $address->getTelephone());
        self::assertSame('pppickup_main', $order->getShippingMethod());
        self::assertInstanceOf(DataObject::class, $order);
        self::assertSame('main', $order->getData('pp_pickup_location_code'));
        self::assertSame('main', $order->getExtensionAttributes()?->getPpPickupLocationCode());
        self::assertSame('main', $this->gridLocationCode((int) $order->getEntityId()));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(
        Customer::class,
        [
            'email' => 'jane.doe@example.com',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'addresses' => [[
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'telephone' => '+43 1 2345678',
                'street' => ['5 Home Street'],
                'postcode' => '5020',
                'city' => 'Salzburg',
                'country_id' => 'AT',
                'region' => null,
                'region_id' => null,
                'default_billing' => true,
                'default_shipping' => true,
            ]],
        ],
        as: 'customer'
    )]
    #[DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::BILLING])]
    public function testCustomerAddressBookIsUnchanged(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
        $customerId = (int) $this->fixture('customer')->getId();
        $savedAddressId = (int) $customerRepository->getById($customerId)->getDefaultShipping();
        self::assertGreaterThan(0, $savedAddressId);
        $cart = $this->cart();
        $cartId = (int) $cart->getId();

        // the customer picks the saved address first, then the pickup rate
        $shipping = $objectManager->create(AddressInterfaceFactory::class)->create();
        self::assertInstanceOf(AddressInterface::class, $shipping);
        $shipping->setCustomerAddressId($savedAddressId);
        $objectManager->get(ShippingAddressManagementInterface::class)->assign($cartId, $shipping);
        $information = $objectManager->create(ShippingInformationInterfaceFactory::class)->create();
        self::assertInstanceOf(ShippingInformationInterface::class, $information);
        $information->setShippingAddress($this->cart()->getShippingAddress())
            ->setShippingCarrierCode('pppickup')
            ->setShippingMethodCode('main');
        $objectManager->get(ShippingInformationManagementInterface::class)
            ->saveAddressInformation($cartId, $information);
        $objectManager->get(PaymentMethodManagementInterface::class)->set(
            $cartId,
            $objectManager->create(\Magento\Quote\Api\Data\PaymentInterface::class, ['data' => ['method' => 'checkmo']])
        );

        $order = $this->placeOrder($this->cart());

        $addresses = $customerRepository->getById($customerId)->getAddresses() ?? [];
        self::assertCount(1, $addresses);
        self::assertSame($savedAddressId, (int) $addresses[0]->getId());
        self::assertSame(['5 Home Street'], $addresses[0]->getStreet());
        $orderAddress = $order->getShippingAddress();
        self::assertNotNull($orderAddress);
        self::assertNull($orderAddress->getCustomerAddressId());
        self::assertSame(['1 Example Street'], $orderAddress->getStreet());
        self::assertSame('Main Store', $orderAddress->getCompany());
        self::assertSame($customerId, (int) $order->getCustomerId());
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::BILLING])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::SHIPPING])]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testOverwrittenAddressIsReappliedAtSubmit(): void
    {
        $cart = $this->cart();
        $this->selectPickup($cart, 'main');

        // a checkout form autosave writes the form values over the location address
        $cart->getShippingAddress()->setStreet(['9 Overwritten Lane']);
        Bootstrap::getObjectManager()->get(CartRepositoryInterface::class)->save($cart);

        $order = $this->placeOrder($this->cart());

        $address = $order->getShippingAddress();
        self::assertNotNull($address);
        self::assertSame(['1 Example Street'], $address->getStreet());
        self::assertSame('Main Store', $address->getCompany());
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::BILLING])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::SHIPPING])]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testSwitchingAwayFromPickupWithoutReloadLeavesNoCodeOnTheOrder(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $cart = $this->cart();
        $this->selectPickup($cart, 'main');
        self::assertSame('main', $cart->getData('pp_pickup_location_code'));

        // the method changes outside ShippingInformationManagement and no checkout reload runs sync()
        $cart->getShippingAddress()->setCollectShippingRates(true);
        $cart->setTotalsCollectedFlag(false);
        $objectManager->get(ShippingMethodManagementInterface::class)
            ->set((int) $cart->getId(), 'flatrate', 'flatrate');
        self::assertSame('main', $this->cart()->getData('pp_pickup_location_code'));

        $order = $this->placeOrder($this->cart());

        self::assertSame('flatrate_flatrate', $order->getShippingMethod());
        self::assertInstanceOf(DataObject::class, $order);
        self::assertNull($order->getData('pp_pickup_location_code'));
        self::assertNull($order->getExtensionAttributes()?->getPpPickupLocationCode());
        self::assertNull($this->gridLocationCode((int) $order->getEntityId()));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::BILLING])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::SHIPPING])]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testDisabledLocationFailsWithStandardError(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $cartRepository = $objectManager->get(CartRepositoryInterface::class);
        $cart = $this->cart();
        $cartId = (int) $cart->getId();
        $this->selectPickup($cart, 'second');
        self::assertSame(['2 Example Street'], $cart->getShippingAddress()->getStreet());

        // the location is disabled while the customer is still in the checkout; the next page load recollects rates
        $objectManager->get(MutableScopeConfigInterface::class)->setValue(
            'carriers/pppickup/locations',
            self::LOCATIONS_SECOND_INACTIVE,
            'store',
            'default'
        );
        $this->resetLocationCaches();
        $second = $objectManager->get(LocationProviderInterface::class)
            ->getLocation('second', (int) $cart->getStoreId());
        self::assertNotNull($second);
        self::assertFalse($second->isActive());
        $cart->getShippingAddress()->setCollectShippingRates(true);
        $cart->setTotalsCollectedFlag(false)->collectTotals();
        $cartRepository->save($cart);
        self::assertFalse($cart->getShippingAddress()->getShippingRateByCode('pppickup_second'));

        try {
            $this->placeOrder($cart);
            self::fail('The order was placed with a disabled pickup location.');
        } catch (LocalizedException $e) {
            self::assertSame(
                'The shipping method is missing. Select the shipping method and try again.',
                $e->getMessage()
            );
        }

        $objectManager->get(PickupAddressApplierInterface::class)->sync($cartRepository->getActive($cartId));

        $reloaded = $objectManager->create(QuoteFactory::class)->create()->load($cartId);
        self::assertSame(['5 Home Street'], $reloaded->getShippingAddress()->getStreet());
        self::assertSame('Salzburg', $reloaded->getShippingAddress()->getCity());
        self::assertNull($reloaded->getData('pp_pickup_location_code'));
        self::assertNull($reloaded->getData('pp_pickup_address_snapshot'));
    }
}
