<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Integration\Carrier;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Registry;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Api\ShipmentEstimationInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use PixelPerfect\StorePickup\Service\LocationProvider\ConfigLocationProvider;

/**
 * The pppickup carrier offers one rate per pickup location in the shipping estimation.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class PickupRatesTest extends TestCase
{
    private const string LOCATIONS = '['
        . '{"code":"main","name":"Main Shop","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","price":"","sort_order":"10","active":"1"},'
        . '{"code":"second","name":"Second Shop","street":"2 Example Street","postcode":"8010","city":"Graz",'
        . '"country_id":"AT","price":"2.50","sort_order":"20","active":"1"}]';

    private const string LOCATIONS_ONE_INACTIVE = '['
        . '{"code":"main","name":"Main Shop","street":"1 Example Street","postcode":"1010","city":"Vienna",'
        . '"country_id":"AT","price":"","sort_order":"10","active":"1"},'
        . '{"code":"second","name":"Second Shop","street":"2 Example Street","postcode":"8010","city":"Graz",'
        . '"country_id":"AT","price":"2.50","sort_order":"20","active":"0"}]';

    protected function setUp(): void
    {
        // the providers cache per store for the length of a request; every test is a request of its own
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(CompositeLocationProvider::class);
        $objectManager->removeSharedInstance(ConfigLocationProvider::class);
    }

    protected function tearDown(): void
    {
        // fixture rollback deletes products, which the frontend area forbids without this flag
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
    }

    /**
     * @return list<ShippingMethodInterface> only the pickup rates
     */
    private function pickupRates(Quote $cart): array
    {
        $objectManager = Bootstrap::getObjectManager();
        $address = $objectManager->get(AddressInterfaceFactory::class)->create();
        self::assertInstanceOf(AddressInterface::class, $address);
        $address->setCountryId('AT')->setPostcode('1010')->setCity('Vienna')->setStreet(['1 Example Street']);

        $rates = $objectManager->get(ShipmentEstimationInterface::class)
            ->estimateByExtendedAddress((int) $cart->getId(), $address);

        return array_values(array_filter(
            $rates,
            static fn (ShippingMethodInterface $rate): bool => $rate->getCarrierCode() === 'pppickup'
        ));
    }

    private function cart(string $name): Quote
    {
        $cart = Bootstrap::getObjectManager()->get(DataFixtureStorageManager::class)
            ->getStorage()->get($name);
        self::assertInstanceOf(Quote::class, $cart);

        return $cart;
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testEstimateReturnsOneRatePerLocation(): void
    {
        $rates = $this->pickupRates($this->cart('cart'));

        self::assertCount(2, $rates);
        self::assertSame('main', $rates[0]->getMethodCode());
        self::assertSame('Main Shop', $rates[0]->getMethodTitle());
        self::assertSame(0.0, (float) $rates[0]->getAmount());
        self::assertSame('second', $rates[1]->getMethodCode());
        self::assertSame(2.5, (float) $rates[1]->getAmount());
        self::assertSame('Store Pickup', $rates[1]->getCarrierTitle());
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS, 'store', 'default')]
    #[DataFixture(ProductFixture::class, ['type_id' => 'virtual'], as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testVirtualOnlyCartGetsNoPickupRate(): void
    {
        $cart = $this->cart('cart');
        self::assertTrue($cart->isVirtual());

        try {
            $rates = $this->pickupRates($cart);
        } catch (InputException) {
            // Magento refuses to estimate shipping for a virtual quote at all, which is just as good
            $rates = [];
        }

        self::assertSame([], $rates);
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[Config('carriers/pppickup/locations', self::LOCATIONS_ONE_INACTIVE, 'store', 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$'])]
    public function testInactiveLocationIsNotOffered(): void
    {
        $rates = $this->pickupRates($this->cart('cart'));

        self::assertCount(1, $rates);
        self::assertSame('main', $rates[0]->getMethodCode());
    }
}
