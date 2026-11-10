<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Test\Unit\Plugin\Checkout\Model\ShippingInformationManagement;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocation;
use PixelPerfect\StorePickup\Plugin\Checkout\Model\ShippingInformationManagement\ApplyPickupAddressToShippingInformation;
use PixelPerfect\StorePickup\Service\PickupAddressApplier;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;
use Psr\Log\LoggerInterface;

class ApplyPickupAddressToShippingInformationTest extends TestCase
{
    /** @var CartRepositoryInterface&MockObject */
    private $cartRepository;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var array<string, PickupLocationInterface> */
    private array $locations;

    private ApplyPickupAddressToShippingInformation $plugin;

    /** @var ShippingInformationManagement&MockObject */
    private $subject;

    protected function setUp(): void
    {
        $this->locations = [
            'main' => new PickupLocation(
                code: 'main',
                name: 'Main Store',
                street: ['1 Example Street'],
                postcode: '1010',
                city: 'Vienna',
                countryId: 'AT'
            ),
            'closed' => new PickupLocation(
                code: 'closed',
                name: 'Closed Store',
                street: ['3 Example Street'],
                postcode: '4020',
                city: 'Linz',
                countryId: 'AT',
                active: false
            ),
        ];
        $provider = $this->createMock(LocationProviderInterface::class);
        $provider->method('getLocation')->willReturnCallback(
            fn (string $code, int $storeId): ?PickupLocationInterface => $storeId === 1
                ? ($this->locations[$code] ?? null)
                : null
        );
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $resolver = new PickupMethodResolver();
        $applier = new PickupAddressApplier(
            $provider,
            $resolver,
            new Json(),
            $this->createMock(CartRepositoryInterface::class),
            $this->logger
        );
        $this->plugin = new ApplyPickupAddressToShippingInformation(
            $this->cartRepository,
            $resolver,
            $provider,
            $applier,
            $this->logger
        );
        $this->subject = $this->createMock(ShippingInformationManagement::class);
    }

    /**
     * @param array<string, mixed> $data
     * @return Quote&MockObject
     */
    private function quote(array $data): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $quote->setData($data + ['store_id' => 1]);
        $this->cartRepository->method('getActive')->with(5)->willReturn($quote);

        return $quote;
    }

    /**
     * @return Address&MockObject
     */
    private function address(): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $address->setData([
            'firstname' => 'Jane',
            'street' => '5 Home Street',
            'city' => 'Innsbruck',
            'customer_address_id' => 7,
        ]);

        return $address;
    }

    /**
     * @param Address $address
     * @param string|null $carrier
     * @param string|null $method
     * @return ShippingInformationInterface&MockObject
     */
    private function information(Address $address, ?string $carrier, ?string $method): ShippingInformationInterface
    {
        $information = $this->createMock(ShippingInformationInterface::class);
        $information->method('getShippingAddress')->willReturn($address);
        $information->method('getShippingCarrierCode')->willReturn($carrier);
        $information->method('getShippingMethodCode')->willReturn($method);

        return $information;
    }

    public function testPickupCarrierGetsTheLocationAddressAndCode(): void
    {
        $quote = $this->quote(['customer_id' => '42']);
        $address = $this->address();

        $result = $this->plugin->beforeSaveAddressInformation(
            $this->subject,
            5,
            $this->information($address, 'pppickup', 'main')
        );

        $this->assertNull($result);
        $this->assertSame(['1 Example Street'], $address->getStreet());
        $this->assertSame('Vienna', $address->getCity());
        $this->assertSame('Main Store', $address->getCompany());
        $this->assertSame('Jane', $address->getFirstname());
        $this->assertNull($address->getCustomerAddressId());
        $this->assertSame(42, $address->getCustomerId());
        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testOtherCarrierClearsTheCodeAndLeavesTheAddress(): void
    {
        $quote = $this->quote(['pp_pickup_location_code' => 'main']);
        $address = $this->address();
        $before = $address->getData();

        $this->plugin->beforeSaveAddressInformation(
            $this->subject,
            5,
            $this->information($address, 'flatrate', 'flatrate')
        );

        $this->assertSame($before, $address->getData());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testOtherCarrierWithAMethodNamedLikeALocationLeavesTheAddress(): void
    {
        $quote = $this->quote([]);
        $address = $this->address();
        $before = $address->getData();

        $this->plugin->beforeSaveAddressInformation($this->subject, 5, $this->information($address, 'flatrate', 'main'));

        $this->assertSame($before, $address->getData());
        $this->assertNull($quote->getData('pp_pickup_location_code'));
    }

    public function testInactiveLocationLeavesAddressAndCode(): void
    {
        $quote = $this->quote(['pp_pickup_location_code' => 'main']);
        $address = $this->address();
        $before = $address->getData();

        $this->plugin->beforeSaveAddressInformation(
            $this->subject,
            5,
            $this->information($address, 'pppickup', 'closed')
        );

        $this->assertSame($before, $address->getData());
        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testUnknownLocationOrInvalidCodeLeavesAddress(): void
    {
        $this->quote([]);
        $address = $this->address();
        $before = $address->getData();

        $this->plugin->beforeSaveAddressInformation($this->subject, 5, $this->information($address, 'pppickup', 'gone'));
        $this->plugin->beforeSaveAddressInformation($this->subject, 5, $this->information($address, 'pppickup', 'B!'));

        $this->assertSame($before, $address->getData());
    }

    public function testLocationIsLookedUpInTheQuoteStore(): void
    {
        $this->quote(['store_id' => 2]);
        $address = $this->address();
        $before = $address->getData();

        $this->plugin->beforeSaveAddressInformation($this->subject, 5, $this->information($address, 'pppickup', 'main'));

        $this->assertSame($before, $address->getData());
    }

    public function testNonQuoteCartIsIgnored(): void
    {
        $this->cartRepository->method('getActive')->willReturn($this->createMock(CartInterface::class));
        $address = $this->address();
        $before = $address->getData();
        $this->logger->expects($this->never())->method('error');

        $this->plugin->beforeSaveAddressInformation($this->subject, 5, $this->information($address, 'pppickup', 'main'));
        $this->plugin->beforeSaveAddressInformation($this->subject, 5, $this->information($address, 'flatrate', 'x'));

        $this->assertSame($before, $address->getData());
    }

    public function testStringCartIdFromTheCoreCallerIsAccepted(): void
    {
        $quote = $this->quote([]);
        $address = $this->address();

        $this->plugin->beforeSaveAddressInformation(
            $this->subject,
            '5',
            $this->information($address, 'pppickup', 'main')
        );

        $this->assertSame('Vienna', $address->getCity());
        $this->assertSame('main', $quote->getData('pp_pickup_location_code'));
    }

    public function testMissingCartIdIsLoggedAndTheCallGoesAhead(): void
    {
        $this->cartRepository->method('getActive')->with(0)
            ->willThrowException(new \RuntimeException('no cart 0'));
        $this->logger->expects($this->once())->method('error')
            ->with('Pickup address could not be applied to the shipping information: no cart 0');

        $result = $this->plugin->beforeSaveAddressInformation(
            $this->subject,
            null,
            $this->information($this->address(), 'pppickup', 'main')
        );

        $this->assertNull($result);
    }

    public function testErrorsAreLoggedAndTheCallGoesAhead(): void
    {
        $this->cartRepository->method('getActive')->willThrowException(new \RuntimeException('no cart'));
        $this->logger->expects($this->once())->method('error')
            ->with('Pickup address could not be applied to the shipping information: no cart');

        $result = $this->plugin->beforeSaveAddressInformation(
            $this->subject,
            5,
            $this->information($this->address(), 'pppickup', 'main')
        );

        $this->assertNull($result);
    }
}
